<?php
declare(strict_types=1);

namespace App\Ai;

use App\App;
use App\Core\ConfigStore;
use App\Terminal\CommandRunner;

/**
 * AI 对话状态 + 流式执行（M5 R2/R3/R4/R6）。
 *
 * 与 GitModel / SearchModel 同构：状态归自己，面板只读；异步部分每轮由主循环 poll()。
 *
 * ## 执行底层：复用 M2/M4 的 CommandRunner
 * 不是「为了用协程而用协程」——实测 Swoole 协程 curl 这条路是坏的（`docs/swoole_study.md` §9）：
 * NATIVE_CURL hook 静默吞掉 WRITEFUNCTION；curl_multi 在该 hook 下段错误；
 * 关掉 hook 后 curl_exec 阻塞整个调度器（界面僵死）。子进程 + 非阻塞管道是本项目
 * 唯一同时满足「流式」和「不阻塞 UI」的已验证范式，M2/M4 已经用了两次。
 *
 * ## poll() 的返回值决定重绘
 * 与 SearchModel 同理：主循环靠它判断本帧要不要重画。流式打字期间每一块新 token
 * 都要返回 true，否则界面不动。这个值被吞掉就表现为「卡住不动直到最后一次性出现」。
 *
 * ## 半行保持原始字节
 * 同 M4：UTF-8 汉字占 3 字节，chunk 边界随时会从中间劈开，此时若先 sanitize 会把半个
 * 字符「修」成替换符。解析器内部攒原始字节，凑齐一行再解析（见 SseParser）。
 */
final class ChatModel
{
    /**
     * 快捷动作的 kind（与 `App::aiQuickAction()` 的四个动作一致）：它们天然携带任务类型，
     * 因此是自动选档的两个来源之一（另一个是输入框里的 `/前缀`）。
     */
    public const QUICK_ACTION_KINDS = ['explain', 'comment', 'refactor', 'unittest'];
    /** @var array<int,array{role:string,content:string}> 完整对话历史（多轮上下文就是这个） */
    private array $messages = [];

    private CommandRunner $runner;

    private OpenAiCompatProvider $provider;

    private ?SseParser $parser = null;

    /** 末条 assistant 的 finish_reason（'stop' / 'tool_calls' / …） */
    private ?string $finishReason = null;

    /**
     * 本轮流式累积中的工具调用（V2 Agent loop）。
     * key = delta 的 index；arguments 是多片拼起来的原始 JSON 串（流结束后统一 decode）。
     * @var array<int,array{id:?string,name:?string,arguments:string}>
     */
    private array $toolAcc = [];

    private bool $streaming = false;

    /** 累积的 stderr（curl 的网络错误等），非空说明请求没成功 */
    private string $stderr = '';

    private ?string $error = null;

    /** 当前 provider / model（空表示还没定，首次用时取默认） */
    private ?string $providerId = null;

    private ?string $model = null;

    // ── V2 Agent loop 状态 ─────────────────────────────

    /** 本轮 send 起已完成的工具执行步数（每轮工具调用 +1） */
    private int $steps = 0;

    /** 逐次确认模式下待用户裁决的 tool_calls；null=无（autoRun 模式恒为 null） */
    private ?array $pendingApproval = null;

    /** 工具自动执行（vicerc ai.toolAutoRun，默认 true；M7 命令可切） */
    private bool $toolAutoRun;

    /** 工具调用步数上限（vicerc ai.maxSteps，默认 8） */
    private int $maxSteps;

    /**
     * 当前生效的模型策略名（null = 没在用策略）。
     *
     * ⚠️ 手动切 provider/model 会把它清空：状态栏上挂着"策略=优质档"而实际跑的是用户手选的
     * 便宜模型，比不显示更糟 —— 可见性的前提是**不能说谎**。
     */
    private ?string $strategyName = null;

    /**
     * 是否被**人工钉住**（true = 按任务类型的自动选档暂停）。
     *
     * 语义（"人工优先"）：任何人工选档动作——`Ctrl+R`、菜单里的策略项、手动切 provider/model——
     * 都算钉住；只有**显式**切回「自动」才恢复自动路由。这样用户永远知道现在听谁的，
     * 不会出现"我明明选了优质档，发个 /plan 它又变回去了"。
     */
    private bool $pinned = false;

    /** 时钟（折扣时段判定用）；构造可注入，默认 `new DateTimeImmutable('now')` */
    private \Closure $now;

    /** 只读工具实现（list_files/read_file），root=项目根 */
    private AiTools $tools;

    // ── V2 上下文压缩状态 ─────────────────────────────

    /** 压缩摘要请求进行中（期间 finish() 走压缩分支） */
    private bool $compacting = false;

    /** 压缩前的完整历史（摘要失败降级恢复用） */
    private array $preCompact = [];

    /** send 路径压缩时摘出去的新 user 消息（摘要完成后回队续发真实请求）；null=手动 compactNow */
    private ?array $deferredUser = null;

    /** 构造函数里可注入 registry/tools，单测用假配置指向本地 mock 端点 */
    public function __construct(
        private App $shell,
        private ?ProviderRegistry $registry = null,
        ?AiTools $tools = null,
        ?\Closure $now = null,
    ) {
        $this->runner = new CommandRunner();
        $this->provider = new OpenAiCompatProvider();
        $this->registry ??= new ProviderRegistry();
        $this->tools = $tools ?? new AiTools();
        $this->toolAutoRun = ConfigStore::aiToolAutoRun();
        $this->maxSteps = ConfigStore::aiMaxSteps();
        // 时钟可注入：折扣时段判定必须能在单测里固定在任意时刻（否则只能 sleep 到那个钟点）。
        // ⚠️ 属性类型不能写 callable（PHP 属性不支持），所以存 \Closure。
        $this->now = $now ?? static fn(): \DateTimeImmutable => new \DateTimeImmutable('now');
    }

    // ── 状态读取 ──────────────────────────────────────

    /** @return array<int,array{role:string,content:string}> */
    public function messages(): array
    {
        return $this->messages;
    }

    public function isStreaming(): bool
    {
        return $this->streaming;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function providerId(): ?string
    {
        return $this->providerId;
    }

    public function registry(): ProviderRegistry
    {
        return $this->registry;
    }

    /** 当前生效的规格。没配任何 provider 时为 null。 */
    public function spec(): ?ProviderSpec
    {
        $id = $this->providerId ?? $this->registry->defaultId();
        if ($id === null) {
            return null;
        }
        return $this->registry->spec($id, $this->model);
    }

    // ── Provider / 模型切换（R4）────────────────────────

    /** 切换到下一个 provider（环形）。切换会保留已有对话。 */
    public function cycleProvider(): void
    {
        $ids = $this->registry->ids();
        if ($ids === []) {
            return;
        }
        $cur = $this->providerId ?? $ids[0];
        $i = array_search($cur, $ids, true);
        $next = $ids[(($i === false ? 0 : $i) + 1) % count($ids)];
        $this->useProvider($next);
    }

    /** 切到指定 provider；model 为 null 时用其默认模型。 */
    public function useProvider(string $id, ?string $model = null): void
    {
        if (!$this->registry->has($id)) {
            return;
        }
        $this->pendingApproval = null; // 换 provider 连带取消未裁决的确认态（上一家的调用不带到这一家）
        $this->providerId = $id;
        $this->model = $model; // 换 provider 时清掉 model，否则会拿上一家的模型名去打这一家
        $this->strategyName = null; // 手选了 provider → 不再属于任何策略（状态栏不许说谎）
        $this->pinned = true;       // 人工选档 = 钉住：自动选档暂停，别跟用户抢方向盘
        $spec = $this->spec();
        if ($spec !== null) {
            $this->shell->setMessage($this->shell->t('ai.switched', [
                'provider' => $spec->label,
                'model'    => $spec->model,
            ]));
        }
    }

    /**
     * 重载 provider 配置（内置 `config/providers.php` + 用户级 `~/.vicecode.providers.php`）。
     *
     * 用户级配置支持**应用内编辑 + 保存即生效**（见 EditorPanel 的保存钩子），所以这条路径
     * 会在运行中被调用。**保住当前选择**：当前 provider 在新配置里仍存在就留着（用户可能只是
     * 改了 base_url）；被删掉了才退回默认（`providerId = null` → `spec()` 取 `defaultId()`）。
     * 当前模型在新配置里没有时不必特判——`spec()` 本就会退回该 provider 的默认模型。
     *
     * @param ProviderRegistry|null $registry 注入用（单测）；null = 按当前文件重新读一份
     */
    public function reloadProviders(?ProviderRegistry $registry = null): void
    {
        $keepId = $this->providerId;
        $keepModel = $this->model;
        $this->registry = $registry ?? new ProviderRegistry();
        if ($keepId !== null && !$this->registry->has($keepId)) {
            $this->pendingApproval = null;   // 当前 provider 被移除了：确认态跟着作废
            $this->providerId = null;
            $this->model = null;
            return;
        }
        $this->providerId = $keepId;
        $this->model = $keepModel;
    }

    /** 用户级配置文件的问题（null = 正常）；供 UI 在重载后提示「已保留原配置」 */
    public function providersUserError(): ?string
    {
        return $this->registry->userError();
    }

    /** 用户级配置文件的路径（未启用时为 null） */
    public function providersUserFile(): ?string
    {
        return $this->registry->userFile();
    }

    /** 用户级配置里定义的模型策略（name => ModelStrategy，按声明顺序） */
    public function strategies(): array
    {
        return $this->registry->strategies();
    }

    /** 当前生效的策略名（null = 没在用策略，例如手动切的 provider/模型） */
    public function strategyName(): ?string
    {
        return $this->strategyName;
    }

    /** 当前策略的展示名（策略已被删掉时退回名字本身，供状态栏用） */
    public function strategyLabel(): ?string
    {
        if ($this->strategyName === null) {
            return null;
        }
        return $this->registry->strategies()[$this->strategyName]?->label ?? $this->strategyName;
    }

    /**
     * 应用一条模型策略：切换 provider/model 并记下策略名。
     *
     * **四类校验，一律拒绝 + 明确提示，绝不静默降级**（静默降级是这类功能最坏的失败方式：
     * 用户以为在跑"优质档"，实际跑的是别的模型，还找不出原因）：
     *  1. 策略不存在；
     *  2. 目标 provider 没配置；
     *  3. 目标 model 没在该 provider 里声明 —— ⚠️ `spec()` 在模型未登记时会**静默退回该
     *     provider 的默认模型**，所以必须自己比对（`$spec->model !== $want`）才算校验过；
     *  4. 策略声明的 `requires` 能力目标模型不具备（典型：`requires: ['tools']` 撞上没声明
     *     `tools` 的便宜模型 —— 那样 Agent 工具会**静默失效**，不发 tools 也不报错）。
     *
     * @param bool $pin true = 人工选档（**钉住**，按任务类型的自动选档暂停）；
     *                  false = 自动路由挑中的（不钉住，下一条按类型的请求可以再改）
     */
    public function applyStrategy(string $name, bool $pin = true): bool
    {
        $st = $this->registry->strategies()[$name] ?? null;
        $reject = $this->strategyRejectReason($name);
        if ($reject !== null) {
            $this->shell->setMessage($reject);
            return false;
        }
        // $reject === null 已保证 $st 与 $spec 非 null（见 strategyRejectReason）
        $spec = $this->registry->spec($st->providerId, $st->model);
        $this->pendingApproval = null;   // 与 useProvider 同规则：换模型连带取消未裁决的确认态
        $this->providerId = $st->providerId;
        $this->model = $spec->model;
        $this->strategyName = $st->name;
        $this->pinned = $pin;
        if ($pin) {
            // 人工钉住：副带说清"自动选档暂停了"，否则用户会以为按任务类型还在生效
            $this->shell->setMessage($this->shell->t('ai.strategy_applied_pinned', [
                'strategy' => $st->label,
                'provider' => $spec->label,
                'model'    => $spec->model,
            ]));
        } else {
            $this->shell->setMessage($this->shell->t('ai.strategy_applied', [
                'strategy' => $st->label,
                'provider' => $spec->label,
                'model'    => $spec->model,
            ]));
        }
        return true;
    }

    /**
     * 策略可用性**预判**（无副作用、不设提示）：null = 可用；否则返回该拒绝的提示文案。
     *
     * 抽出来是为了让"折扣时段"批量挑档时**先筛后切**——如果候选里最便宜那条是配错的，
     * 预判能让我们直接跳过它去试下一条，而不是把一堆拒绝提示刷到状态栏上（最后一条还会
     * 盖掉真正切档成功的那条）。四类拒绝的判定条件与文案与 `applyStrategy()` 完全一致，
     * `applyStrategy()` 现在就是"预判 + 提交"。
     */
    private function strategyRejectReason(string $name): ?string
    {
        $st = $this->registry->strategies()[$name] ?? null;
        if ($st === null) {
            return $this->shell->t('ai.strategy_unknown', ['strategy' => $name]);
        }
        if (!$this->registry->has($st->providerId)) {
            return $this->shell->t('ai.strategy_no_provider', [
                'strategy' => $st->label,
                'provider' => $st->providerId,
            ]);
        }
        $spec = $this->registry->spec($st->providerId, $st->model);
        if ($spec === null) {
            return $this->shell->t('ai.strategy_no_provider', [
                'strategy' => $st->label,
                'provider' => $st->providerId,
            ]);
        }
        if ($st->model !== null && $spec->model !== $st->model) {
            return $this->shell->t('ai.strategy_no_model', [
                'strategy' => $st->label,
                'model'    => $st->model,
                'provider' => $st->providerId,
            ]);
        }
        $missing = array_values(array_filter($st->requires, static fn(string $c): bool => !$spec->supports($c)));
        if ($missing !== []) {
            return $this->shell->t('ai.strategy_missing_caps', [
                'strategy' => $st->label,
                'caps'     => implode('/', $missing),
                'model'    => $spec->model,
            ]);
        }
        return null;
    }

    /** 是否被人工钉住（自动选档暂停）；供状态栏显示"现在听谁的" */
    public function isPinned(): bool
    {
        return $this->pinned;
    }

    /** 是否有任何策略可自动路由（状态栏据此决定要不要显示「自动」） */
    public function hasStrategies(): bool
    {
        return $this->registry->strategies() !== [];
    }

    /** 已知任务类型 = 4 个快捷动作 + 所有策略里声明的 kinds（去重排序；供前缀校验与提示） */
    public function knownKinds(): array
    {
        $kinds = self::QUICK_ACTION_KINDS;
        foreach ($this->registry->strategies() as $st) {
            foreach ($st->kinds as $k) {
                $kinds[] = $k;
            }
        }
        $kinds = array_values(array_unique($kinds));
        sort($kinds);
        return $kinds;
    }

    /**
     * 切回「自动」：解除钉住，之后按任务类型自动选档（快捷动作与 `/前缀`）。
     * 策略名保留（状态栏继续显示当前实际跑的那一档，只是不再算"钉住"）。
     */
    public function useAutoStrategy(): void
    {
        $this->pinned = false;
        $this->shell->setMessage($this->shell->t('ai.strategy_auto_on'));
    }

    /**
     * 按任务类型选档（**人工优先**：钉住时完全不介入，并由调用方给出提示）。
     *
     * 命中规则：按配置里的策略声明顺序，取第一条 `kinds` 含该 kind 的。
     * 不命中就不动（保持当前档），也不报错——"这条任务是普通的"本来就是常态。
     *
     * ⚠️ 返回值有一个**刻意的区分**：`''` = "命中了一条策略，但应用失败（已给出拒绝提示）"。
     * 调用方（`send()`）据此决定还要不要兜底跑折扣时段规则——命中却失败说明这条路是用户
     * **明确指定**的（如 `/plan`），此时再自动换到别的档位等于无视用户的显式意图。
     * 只有 null（钉住 / 没带 kind / 没命中）才是"任务类型这条规则没意见"。
     *
     * @return string|null 实际路由到的策略名；`''` = 命中但被拒绝；null = 没路由
     */
    public function routeByKind(?string $kind): ?string
    {
        if ($kind === null || $kind === '' || $this->pinned) {
            return null;
        }
        foreach ($this->registry->strategies() as $st) {
            if (in_array($kind, $st->kinds, true)) {
                return $this->applyStrategy($st->name, false) ? $st->name : '';
            }
        }
        return null;
    }

    /**
     * 按**折扣时段**选档：此刻有 provider 在打折，就切到"打折的档里最便宜的那个"。
     *
     * 这是 `routeByKind()` 的**兜底**——只在它没意见（返回 null）时才轮到这里，
     * 所以任务类型的显式意图永远赢。`pinned` 人工钉住时同样完全不介入。
     *
     * 为什么"降档"这件事要由**成本档**来决定，而不是配置顺序：用户配了 `cost` 就是在表达
     * "几号档更便宜"，按它升序取第一条才符合"闲时省钱"的直觉；没写 `cost` 的一律排在
     * 已声明者**之后**（未知不等于免费）。
     *
     * 打折组为空 → 什么都不做（保持当前档，不提示）。这是刻意的：平时大多数时刻都没有
     * 折扣，若每次发消息都提示一句"没有折扣"会把状态栏刷成噪音。
     *
     * @return string|null 实际路由到的策略名；null = 没换（钉住 / 无折扣 / 无可用候选）
     */
    public function routeByOffPeak(): ?string
    {
        if ($this->pinned) {
            return null;
        }
        $now = ($this->now)();
        $candidates = [];                       // [cost, 声明序, name, label, providerId]
        foreach ($this->registry->strategies() as $st) {
            if (!$st->autoOffpeak) {
                continue;                       // 该档明确不参与闲时降档（如"计划档"）
            }
            if ($this->strategyRejectReason($st->name) !== null) {
                continue;                       // 配错的候选直接跳过，不让它把拒绝提示刷上状态栏
            }
            $windows = $this->registry->offPeak($st->providerId);
            if ($windows === [] || !OffPeak::isActive($windows, $now)) {
                continue;
            }
            $cost = $this->registry->cost($st->providerId);
            $candidates[] = [$cost, $windows, $st];
        }
        if ($candidates === []) {
            return null;
        }
        // 已声明 cost 的按升序在前，未声明的排最后；同 cost 保持配置声明顺序（稳定排序）
        usort($candidates, static function (array $a, array $b): int {
            if ($a[0] === null && $b[0] === null) {
                return 0;
            }
            if ($a[0] === null) {
                return 1;
            }
            if ($b[0] === null) {
                return -1;
            }
            return $a[0] <=> $b[0];
        });
        foreach ($candidates as [, $windows, $st]) {
            if ($this->applyStrategy($st->name, false)) {
                $until = OffPeak::activeUntil($windows, $now);
                // 折扣提示要带上"为什么切"和"到几点"——否则用户只会看到档位莫名其妙变了
                $this->shell->setMessage($this->shell->t('ai.strategy_offpeak_applied', [
                    'strategy' => $st->label,
                    'provider' => $this->registry->label($st->providerId),
                    'model'    => (string) $this->model,
                    'until'    => $until ?? $this->shell->t('ai.strategy_offpeak_all_day'),
                ]));
                return $st->name;
            }
        }
        return null;
    }

    /**
     * 当前生效的档位是否正处在折扣时段（供状态栏打 `·折扣` 标记）。
     *
     * **实时计算**，不记忆"上次是不是打折"：折扣结束的那一刻标记必须自己消失，
     * 否则状态栏就在说谎（用户会以为现在还是便宜价）。
     */
    public function offPeakActive(): bool
    {
        return $this->strategyName !== null && $this->strategyOffPeakActive($this->strategyName);
    }

    /**
     * **任意一条**策略此刻是否打折（供菜单给每条策略打标；不要求它是当前档）。
     * 策略不存在 → false。
     */
    public function strategyOffPeakActive(string $name): bool
    {
        $st = $this->registry->strategies()[$name] ?? null;
        if ($st === null) {
            return false;
        }
        return OffPeak::isActive($this->registry->offPeak($st->providerId), ($this->now)());
    }


    /** 在已定义的策略（含「自动」档）之间循环切换（环形）。一条都没配时提示怎么配。 */
    public function cycleStrategy(): void
    {
        $names = array_keys($this->registry->strategies());
        if ($names === []) {
            $this->shell->setMessage($this->shell->t('ai.strategy_none'));
            return;
        }
        // 首站是「自动」：没钉住时 Ctrl+R 第一次就是切到自动（等于回到自动），语义连贯
        $stops = array_merge([ProviderRegistry::AUTO], $names);
        $cur = $this->pinned && $this->strategyName !== null ? $this->strategyName : ProviderRegistry::AUTO;
        $i = array_search($cur, $stops, true);
        $next = $stops[(($i === false ? -1 : $i) + 1) % count($stops)];
        if ($next === ProviderRegistry::AUTO) {
            $this->useAutoStrategy();
            return;
        }
        $this->applyStrategy($next);
    }

    /** 在当前 provider 内切到下一个模型（环形）。 */
    public function cycleModel(): void
    {
        $spec = $this->spec();
        if ($spec === null || $spec->models === []) {
            return;
        }
        $i = array_search($spec->model, $spec->models, true);
        $next = $spec->models[(($i === false ? 0 : $i) + 1) % count($spec->models)];
        $this->model = $next;
        $this->strategyName = null; // 手选了模型 → 不再属于任何策略
        $this->pinned = true;       // 人工选档 = 钉住
        $this->shell->setMessage($this->shell->t('ai.switched', [
            'provider' => $spec->label,
            'model'    => $next,
        ]));
    }

    // ── 发送与流式接收 ─────────────────────────────────

    /**
     * 发一条用户消息并起流式请求。
     *
     * 先把用户消息和一条**空的** assistant 消息入列，之后每个 token 都追加到那条
     * assistant 消息上——这样「流式」在数据层就是「最后一条消息在长」，
     * 渲染层不需要为「正在生成的消息」开特例。
     *
     * @param string|null $kind 任务类型（快捷动作的 kind，或输入框 `/前缀` 解析出来的）：
     *                          用于**按任务类型自动选档**；null = 普通消息（不路由，保持当前档）。
     *                          放在"生成中"检查**之后**处理——生成中绝不切模型。
     */
    public function send(string $text, ?string $kind = null): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }
        if ($this->streaming) {
            $this->shell->setMessage($this->shell->t('ai.busy'));
            return;
        }
        $routed = $this->routeByKind($kind);
        // 任务类型没意见（含"没带 kind"）时才轮到折扣时段兜底。
        // `''` = 命中了策略但被拒绝：那是用户**显式指定**的路，不许再自动改道。
        if ($routed === null) {
            $this->routeByOffPeak();
        }
        // 钉住时前缀不生效——必须说出来，否则用户以为 /plan 起作用了（静默失效最坏）。
        // 注：这条提示会被**随后出现的、更该看的**错误覆盖（如「缺少 API key」）——优先级如此是对的。
        if ($kind !== null && $this->pinned) {
            $this->shell->setMessage($this->shell->t('ai.kind_ignored_pinned', ['kind' => $kind]));
        }
        $this->pendingApproval = null; // 新提问覆盖未裁决的确认态
        $this->steps = 0;

        // 用户消息**先入列再校验**：打错字或没配 key 时，用户也该看到自己刚才打了什么
        // （否则输入框一清空，内容就像凭空消失了）。只有"生成中"这种没真发出去的情况才不入列。
        $this->error = null;
        $this->messages[] = ['role' => 'user', 'content' => $text];

        if ($this->shouldCompact()) {
            // 上下文压缩：先发摘要请求，摘要回来替换旧历史后再发真实请求（D4）
            $this->deferredUser = array_pop($this->messages); // 新 user 摘出来，摘要请求不带它
            $this->beginCompact();
            return;
        }

        if (!$this->startRequest()) {
            // startRequest 已设置 error 并提示；失败时末条 assistant 是空的，移除保持历史干净
            $this->finalizeContent();
        }
    }

    /** 手动触发压缩（菜单 ai.compact_now）：不续发真实请求，压缩完就停。 */
    public function compactNow(): void
    {
        if ($this->streaming || $this->compacting) {
            $this->shell->setMessage($this->shell->t('ai.busy'));
            return;
        }
        if (count($this->messages) < 2) {
            // 没什么可压的：如实报个不变
            $n = (string) count($this->messages);
            $this->shell->setMessage($this->shell->t('ai.compact_done', ['before' => $n, 'after' => $n]));
            return;
        }
        $this->beginCompact();
    }

    /** 估算上下文 token（CJK 偏保守按 3 字符/token） */
    private function estimateTokens(array $messages): int
    {
        $n = 0;
        foreach ($messages as $m) {
            if (is_array($m)) {
                $n += (int) ceil(mb_strlen((string) ($m['content'] ?? '')) / 3);
            }
        }
        return $n;
    }

    /** 是否该压缩：估算超阈值 且 消息数足够多（留够 keepRecent 的原料） */
    private function shouldCompact(): bool
    {
        return $this->estimateTokens($this->messages) > ConfigStore::aiCompactThreshold()
            && count($this->messages) > ConfigStore::aiCompactKeepRecent();
    }

    /**
     * 起摘要请求：当前历史换成「摘要指令」单条消息走同一条 curl 管线。
     * 原历史暂存 preCompact（失败降级恢复）；deferredUser 为 null 表示手动压缩。
     */
    private function beginCompact(): void
    {
        $this->preCompact = $this->messages;
        $this->messages = [];
        $this->compacting = true;
        $this->shell->setMessage($this->shell->t('ai.compact_started'));
        if (!$this->startRequest()) {
            // 起请求失败（缺 key 之类）：降级=不压缩，恢复原样
            $this->abortCompact();
        }
    }

    /** 压缩失败/中止：恢复 preCompact（send 路径还要把摘出去的 user 消息放回去） */
    private function abortCompact(): void
    {
        $this->messages = $this->preCompact;
        $this->preCompact = [];
        if ($this->deferredUser !== null) {
            $this->messages[] = $this->deferredUser;
            $this->deferredUser = null;
        }
        $this->compacting = false;
    }

    /**
     * 从旧历史里取「保留区」：最近 keepRecent 条；起点若落在 role:tool 消息上，
     * 回退到它的 assistant（带 tool_calls）——**唯一硬约束是保留区不能以 tool 开头**
     * （否则摘要区里留 assistant、保留区里留它的 tool 结果，拆散对会被端点 400）。
     * assistant 开头是合法的，不必强行回退到 user（那会把保留区越拖越大）。
     * @return array<int,array<string,mixed>>
     */
    private function keptRecent(array $old, int $keep): array
    {
        $start = max(0, count($old) - $keep);
        while ($start > 0 && ($old[$start]['role'] ?? '') === 'tool') {
            $start--;
        }
        return array_slice($old, $start);
    }

    /**
     * 起一次流式请求（send() 与 Agent loop 续跑共用）。
     * 校验 spec/key → 复位解析状态 → 组命令（Agent 模式带 tools）→ start runner。
     * 返回 false 时已设置 error 并提示（不抛）。
     *
     * ⚠️ 空的 assistant 气泡在这里入列（不在 send()）：Agent 续跑的每一轮都要有
     * 自己的 assistant 占位，否则流式 delta 会因为「末条不是 assistant」被丢弃。
     */
    private function startRequest(): bool
    {
        $spec = $this->spec();
        if ($spec === null) {
            $this->error = $this->shell->t('ai.no_provider');
            $this->shell->setMessage($this->error);
            return false;
        }
        if (!$spec->hasKey()) {
            $this->error = $this->shell->t('ai.no_key', ['env' => $spec->keyEnv ?? $spec->id]);
            $this->shell->setMessage($this->error);
            return false;
        }

        $this->error = null;
        $this->stderr = '';
        $this->parser = new SseParser();
        $this->toolAcc = [];
        $this->finishReason = null;
        $this->messages[] = ['role' => 'assistant', 'content' => ''];

        // 历史 + 本次，整段发出去就是多轮上下文。
        // 工具定义只在「当前模型声明了 tools 能力」时才带上（见 config/providers.php 的能力说明）：
        // 纯推理类模型拿到 tools 常被服务商整轮拒掉，宁可不发。
        $tools = $spec->supportsTools() ? AiTools::toolDefs() : null;
        $cmd = $this->provider->buildCommand($spec, $this->messages, OpenAiCompatProvider::DEFAULT_TIMEOUT, $tools);
        if (!$this->runner->start($cmd, null)) {
            $this->streaming = false;
            $this->error = $this->shell->t('ai.error', ['msg' => 'spawn failed']);
            $this->shell->setMessage($this->error);
            $this->provider->cleanup();
            return false;
        }
        $this->streaming = true;
        return true;
    }

    /**
     * 主循环每轮调一次：排空管道、喂解析器、把 token 追加到最后一条 assistant 消息。
     * 返回本轮是否产生了新内容（决定要不要重绘）。
     */
    public function poll(): bool
    {
        if (!$this->streaming) {
            return false;
        }

        $got = $this->runner->poll(function (string $bytes, bool $isErr): void {
            if ($isErr) {
                $this->stderr .= $bytes;
                return;
            }
            if ($this->parser === null) {
                return;
            }
            foreach ($this->parser->push($bytes) as $ev) {
                $this->handleEvent($ev);
            }
        });

        if (!$this->runner->isRunning()) {
            $this->finish();
            $got = true; // 「生成中 → 完成」的状态变化必须触发重绘
        }
        return $got;
    }

    /** R6 中断生成：杀掉 curl 子进程，已收到的内容保留（不回滚）。 */
    public function cancel(): void
    {
        if (!$this->streaming) {
            return;
        }
        $this->runner->cancel();
        // 不等 poll()：立刻把状态落到「已停止」，用户按键后界面马上有反馈
        $this->streaming = false;
        $this->pendingApproval = null; // 中断连带取消未裁决的确认态（不能让它卡死在等 y/n）
        if ($this->compacting) {
            $this->abortCompact(); // 压缩被打断 → 恢复原历史（send 场景含摘出去的 user 消息）
        }
        $this->finalizeContent();
        $this->shell->setMessage($this->shell->t('ai.cancelled'));
        $this->provider->cleanup();
    }

    /** 清空对话（保留当前 provider/model）。 */
    public function clear(): void
    {
        $this->cancel();
        $this->messages = [];
        $this->error = null;
        $this->pendingApproval = null;
        $this->steps = 0;
        $this->compacting = false;
        $this->preCompact = [];
        $this->deferredUser = null;
        ChatStore::clear(); // Ctrl+L 清空同步清档（用户预期：清空后重启不会有「幽灵对话」）
    }

    /** 应用退出时调用：请求还在跑就杀掉，并清掉临时文件。 */
    public function shutdown(): void
    {
        $this->runner->shutdown();
        $this->streaming = false;
        $this->pendingApproval = null;
        $this->finalizeContent(); // 流被打断时别把空气泡存进档
        $this->saveNow();
        $this->provider->cleanup();
    }

    // ── V2 对话持久化 ─────────────────────────────────

    /** 启动时恢复上次对话（ai.persist 开才生效；失败静默=不恢复）。App 构造后调用。 */
    public function restore(): void
    {
        if (!ConfigStore::aiPersist()) {
            return;
        }
        $snap = ChatStore::load();
        if ($snap === null || $snap['messages'] === []) {
            return;
        }
        $this->messages = $snap['messages'];
        if ($snap['provider'] !== null && $this->registry->has($snap['provider'])) {
            $this->providerId = $snap['provider'];
            $this->model = $snap['model']; // 换 provider 清 model 的规则在这里反着来：存档里 model 是配对存下来的
            // 策略名**只在仍然指向同一个目标时**才恢复（策略被删掉/改过、或用户手选过别的模型，
            // 就不该再挂着那个名字——状态栏不许说谎）
            $st = $snap['strategy'] !== null ? ($this->registry->strategies()[$snap['strategy']] ?? null) : null;
            if ($st !== null
                && $st->providerId === $snap['provider']
                && ($st->model === null || $st->model === $snap['model'])) {
                $this->strategyName = $st->name;
            }
        }
    }

    /** 落盘当前对话（ai.persist 开才生效；失败静默）。finish 收尾 / clear / shutdown 调用。 */
    public function saveNow(): void
    {
        if (!ConfigStore::aiPersist()) {
            return;
        }
        $spec = $this->spec();
        ChatStore::save($this->messages, [
            'provider' => $this->providerId,
            'model'    => $spec?->model,
            'strategy' => $this->strategyName,
        ]);
    }

    // ── 内部 ──────────────────────────────────────────

    /**
     * 处理一条 SseParser 结构化事件：正文增量追加到末条 assistant；
     * 工具增量按 index 累积到 toolAcc；finish 记录 finish_reason。
     * @param array<string,mixed> $ev
     */
    private function handleEvent(array $ev): void
    {
        switch ($ev['type'] ?? null) {
            case 'delta':
                if (is_string($ev['text'] ?? null)) {
                    $this->appendDelta($ev['text']);
                }
                return;
            case 'tool_delta':
                $idx = is_int($ev['index'] ?? null) ? $ev['index'] : 0;
                $slot = $this->toolAcc[$idx] ?? ['id' => null, 'name' => null, 'arguments' => ''];
                if (!$slot['id'] && is_string($ev['id'] ?? null)) {
                    $slot['id'] = $ev['id'];
                }
                if (!$slot['name'] && is_string($ev['name'] ?? null)) {
                    $slot['name'] = $ev['name'];
                }
                if (is_string($ev['args_delta'] ?? null)) {
                    $slot['arguments'] .= $ev['args_delta'];
                }
                $this->toolAcc[$idx] = $slot;
                return;
            case 'finish':
                $this->finishReason = is_string($ev['reason'] ?? null) ? $ev['reason'] : null;
                return;
        }
    }

    private function appendDelta(string $delta): void
    {
        $i = count($this->messages) - 1;
        if ($i < 0 || $this->messages[$i]['role'] !== 'assistant') {
            return;
        }
        $this->messages[$i]['content'] .= $delta;
    }

    /** 进程结束后的收尾：把残留半行解析掉、判定成功/失败、清理临时文件、Agent loop 续跑判定。 */
    private function finish(): void
    {
        $this->streaming = false;

        if ($this->parser !== null) {
            foreach ($this->parser->flush() as $ev) {
                $this->handleEvent($ev);
            }
        }

        // 被 SIGKILL（我们主动 cancel）时不走错误分支：那是用户自己停的
        $killed = $this->runner->termSig() !== 0;
        $status = $this->provider->httpStatus((string) ($this->provider->metaFile() ?? ''));
        $parseErr = $this->parser?->error();

        if (!$killed) {
            if ($status !== null && $status >= 400) {
                $this->error = $this->shell->t('ai.error', [
                    'msg' => $this->shell->t('ai.http_error', ['code' => (string) $status]),
                ]);
            } elseif ($parseErr !== null) {
                $this->error = $this->shell->t('ai.error', ['msg' => $parseErr]);
            } elseif (trim($this->stderr) !== '') {
                $this->error = $this->shell->t('ai.error', ['msg' => trim($this->stderr)]);
            } elseif ($this->runner->exitCode() !== 0) {
                $this->error = $this->shell->t('ai.error', ['msg' => 'exit ' . (string) $this->runner->exitCode()]);
            }
        }

        // V2：压缩摘要请求收尾（必须在工具分支之前——摘要请求期间不可能有 tool_calls）
        if ($this->compacting) {
            $this->finishCompact();
            return;
        }

        // V2：模型发起了工具调用 → 先落到末条 assistant 消息上（wire 必需，也防止被当空气泡移除）
        $hasToolCalls = $this->toolAcc !== [];
        if ($this->error === null && $hasToolCalls) {
            $this->attachToolCallsToLast();
        }

        if ($this->error !== null) {
            $this->shell->setMessage($this->error);
        } elseif ($this->lastAssistantText() === '' && !$hasToolCalls) {
            // 没报错但也没内容：别让界面上留一个空气泡，用户会以为卡了
            // （带 tool_calls 的 assistant content 为空是正常形态，不算空）
            $this->shell->setMessage($this->shell->t('ai.empty'));
        }

        $this->finalizeContent();
        $this->provider->cleanup();
        $this->parser = null;
        $this->saveNow(); // 每轮收尾落盘（含工具消息；续跑场景下轮收尾再覆盖）

        // ── Agent loop 续跑判定 ──
        if ($this->error !== null || !$hasToolCalls) {
            $this->toolAcc = [];
            return;
        }
        $calls = $this->collectedToolCalls();
        $this->toolAcc = [];
        if ($this->steps >= $this->maxSteps) {
            // 达上限：补上「未执行」的 tool 结果，保持 tool_call/tool 消息成对（否则下次请求会被端点 400）
            foreach ($calls as $c) {
                $this->messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $c['id'] ?? '',
                    'content' => '[未执行：已达工具调用步数上限]',
                ];
            }
            $this->shell->setMessage($this->shell->t('ai.max_steps', ['steps' => (string) $this->maxSteps]));
            return;
        }
        if ($this->toolAutoRun) {
            $this->runToolCalls($calls); // 只读本地 IO，微秒级，同步执行完立刻续跑
            return;
        }
        // 逐次确认：挂起等用户 y/n（M3 接 UI；期间流式已停，poll() 直接返回 false）
        $this->pendingApproval = $calls;
        $this->shell->setMessage($this->shell->t('ai.await_approval'));
    }

    // ── V2 Agent loop ─────────────────────────────────

    /** 压缩摘要请求收尾：成功 → 替换历史（+回队续发真实请求）；失败 → 降级不压缩。 */
    private function finishCompact(): void
    {
        $old = $this->preCompact;
        $deferred = $this->deferredUser;
        $this->deferredUser = null;
        $this->compacting = false;

        // 摘要 = 本次回复的 assistant 文本（messages 此刻 = [摘要指令, summary assistant]）
        $summary = $this->lastAssistantText();
        $failed = $this->error !== null || $summary === '';

        if ($failed) {
            // 降级：不压缩，原样恢复（历史一条不丢）；send 路径继续把真实请求发出去
            $this->error = null;
            $this->messages = $old;
            $this->preCompact = [];
            if ($deferred !== null) {
                $this->messages[] = $deferred;
                if (!$this->startRequest()) {
                    $this->finalizeContent();
                }
            } else {
                $this->shell->setMessage($this->shell->t('ai.compact_failed'));
            }
            return;
        }

        $summaryMsg = ['role' => 'user', 'content' => '[历史摘要] ' . $summary, 'meta' => ['kind' => 'summary']];
        $kept = $this->keptRecent($old, ConfigStore::aiCompactKeepRecent());
        $this->preCompact = [];

        if ($deferred !== null) {
            $this->messages = [$summaryMsg, ...$kept, $deferred];
            // 续发真实请求（此时估算远低于阈值，不会再触发压缩）
            if (!$this->startRequest()) {
                $this->finalizeContent();
            }
            return;
        }

        // 手动 compactNow：压缩完即停
        $this->messages = [$summaryMsg, ...$kept];
        $this->shell->setMessage($this->shell->t('ai.compact_done', [
            'before' => (string) count($old),
            'after'  => (string) count($this->messages),
        ]));
        $this->saveNow();
    }

    /** @return list<array{id:?string,name:?string,arguments:string}> 按 index 升序的已累积工具调用 */
    private function collectedToolCalls(): array
    {
        ksort($this->toolAcc);
        $out = [];
        foreach ($this->toolAcc as $slot) {
            $out[] = ['id' => $slot['id'], 'name' => $slot['name'], 'arguments' => $slot['arguments']];
        }
        return $out;
    }

    /** 把 toolAcc 转成 OpenAI wire 形状并落到末条 assistant 消息的 tool_calls 键上 */
    private function attachToolCallsToLast(): void
    {
        $i = count($this->messages) - 1;
        if ($i < 0 || ($this->messages[$i]['role'] ?? null) !== 'assistant') {
            return;
        }
        ksort($this->toolAcc);
        $wire = [];
        foreach ($this->toolAcc as $slot) {
            $wire[] = [
                'id' => $slot['id'] ?? ('call_' . $i . '_' . count($wire)),
                'type' => 'function',
                'function' => ['name' => (string) $slot['name'], 'arguments' => $slot['arguments']],
            ];
        }
        $this->messages[$i]['tool_calls'] = $wire;
    }

    /**
     * 执行一批工具调用并续跑下一轮请求（autoRun / approve() 共用）。
     * 工具结果是**本地只读 IO**，同步执行；结果以 role:tool 消息入列后立刻 startRequest()。
     * @param list<array{id:?string,name:?string,arguments:string}> $calls
     */
    private function runToolCalls(array $calls): void
    {
        $this->steps++;
        $this->pendingApproval = null;
        foreach ($calls as $c) {
            $result = $this->tools->execute((string) $c['name'], (string) $c['arguments']);
            $this->messages[] = [
                'role'         => 'tool',
                'tool_call_id' => $c['id'] ?? '',
                'content'      => $result['text'],
                // 私有键：渲染摘要（M3）/复制语义用，发送前由 Provider stripMeta 剥掉
                'meta'         => [
                    'kind'    => 'tool_result',
                    'display' => self::toolDisplay((string) $c['name'], (string) $c['arguments'], $result['ok']),
                ],
            ];
        }
        if (!$this->startRequest()) {
            $this->finalizeContent();
        }
    }

    /** 工具调用的单行摘要（渲染/复制用）：read_file(src/Foo.php) ✓/✗ */
    public static function toolDisplay(string $name, string $argumentsJson, bool $ok): string
    {
        $args = json_decode($argumentsJson, true);
        $path = is_array($args) && is_string($args['path'] ?? null) ? $args['path'] : '?';
        return $name . '(' . $path . ') ' . ($ok ? '✓' : '✗');
    }

    /** 逐次确认模式：放行当前挂起的工具调用并续跑。无挂起时是 no-op。 */
    public function approve(): void
    {
        if ($this->pendingApproval === null || $this->streaming) {
            return;
        }
        $calls = $this->pendingApproval;
        $this->runToolCalls($calls);
    }

    /** 逐次确认模式：拒绝当前挂起的工具调用——以「用户拒绝」结果入列并续跑（模型可改口）。 */
    public function deny(): void
    {
        if ($this->pendingApproval === null || $this->streaming) {
            return;
        }
        $calls = $this->pendingApproval;
        $this->pendingApproval = null;
        $this->steps++;
        foreach ($calls as $c) {
            $this->messages[] = [
                'role'         => 'tool',
                'tool_call_id' => $c['id'] ?? '',
                'content'      => '[用户拒绝执行该工具调用]',
                'meta'         => [
                    'kind'    => 'tool_result',
                    'display' => self::toolDisplay((string) $c['name'], (string) $c['arguments'], false),
                ],
            ];
        }
        if (!$this->startRequest()) {
            $this->finalizeContent();
        }
    }

    /** 逐次确认模式下是否有挂起待裁决的工具调用 */
    public function hasPendingApproval(): bool
    {
        return $this->pendingApproval !== null;
    }

    /** 取消确认态（Esc）：既不执行也不拒绝，Agent loop 就地停止（工具请求悬空不影响展示） */
    public function dismissApproval(): void
    {
        $this->pendingApproval = null;
        $this->shell->setMessage($this->shell->t('ai.cancelled'));
    }

    /** 工具自动执行开关（M7 命令切换；构造时取 vicerc 默认） */
    public function setToolAutoRun(bool $on): void
    {
        $this->toolAutoRun = $on;
    }

    public function toolAutoRun(): bool
    {
        return $this->toolAutoRun;
    }

    public function steps(): int
    {
        return $this->steps;
    }

    /** 测试注入工具根目录用（正常路径 root=构造时的 getcwd()） */
    public function setToolsRoot(string $root): void
    {
        $this->tools = new AiTools($root);
    }

    /**
     * 收尾时对最后一条 assistant 消息做清理：
     *  - 完全为空则从历史里移除（否则每轮失败都会留下一条空记录，还会被当成上下文发回去）
     */
    private function finalizeContent(): void
    {
        $i = count($this->messages) - 1;
        if ($i >= 0 && $this->messages[$i]['role'] === 'assistant'
            && $this->messages[$i]['content'] === ''
            && !isset($this->messages[$i]['tool_calls'])) {
            // ⚠️ 带 tool_calls 的 assistant 即使 content 为空也**不能移除**：
            // 后面的 role:tool 结果要靠它的 tool_calls 成对引用，移除会被端点 400
            array_splice($this->messages, $i, 1);
        }
    }

    private function lastAssistantText(): string
    {
        for ($i = count($this->messages) - 1; $i >= 0; $i--) {
            if ($this->messages[$i]['role'] === 'assistant') {
                return $this->messages[$i]['content'];
            }
        }
        return '';
    }

}

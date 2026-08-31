<?php
declare(strict_types=1);

namespace App\Ai;

use App\App;
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
    /** @var array<int,array{role:string,content:string}> 完整对话历史（多轮上下文就是这个） */
    private array $messages = [];

    private CommandRunner $runner;

    private OpenAiCompatProvider $provider;

    private ?SseParser $parser = null;

    private bool $streaming = false;

    /** 累积的 stderr（curl 的网络错误等），非空说明请求没成功 */
    private string $stderr = '';

    private ?string $error = null;

    /** 当前 provider / model（空表示还没定，首次用时取默认） */
    private ?string $providerId = null;

    private ?string $model = null;

    /** 构造函数里可注入 registry，单测用假配置指向本地 mock 端点 */
    public function __construct(
        private App $shell,
        private ?ProviderRegistry $registry = null,
    ) {
        $this->runner = new CommandRunner();
        $this->provider = new OpenAiCompatProvider();
        $this->registry ??= new ProviderRegistry();
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
        $this->providerId = $id;
        $this->model = $model; // 换 provider 时清掉 model，否则会拿上一家的模型名去打这一家
        $spec = $this->spec();
        if ($spec !== null) {
            $this->shell->setMessage($this->shell->t('ai.switched', [
                'provider' => $spec->label,
                'model'    => $spec->model,
            ]));
        }
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
     */
    public function send(string $text): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }
        if ($this->streaming) {
            $this->shell->setMessage($this->shell->t('ai.busy'));
            return;
        }

        // 用户消息**先入列再校验**：打错字或没配 key 时，用户也该看到自己刚才打了什么
        // （否则输入框一清空，内容就像凭空消失了）。只有"生成中"这种没真发出去的情况才不入列。
        $this->error = null;
        $this->messages[] = ['role' => 'user', 'content' => $text];

        $spec = $this->spec();
        if ($spec === null) {
            $this->error = $this->shell->t('ai.no_provider');
            $this->shell->setMessage($this->error);
            return;
        }
        if (!$spec->hasKey()) {
            $this->error = $this->shell->t('ai.no_key', ['env' => $spec->keyEnv ?? $spec->id]);
            $this->shell->setMessage($this->error);
            return;
        }

        $this->error = null;
        $this->stderr = '';
        $this->parser = new SseParser();
        $this->messages[] = ['role' => 'assistant', 'content' => ''];

        // 历史 + 本次（本次已入列），整段发出去就是多轮上下文
        $cmd = $this->provider->buildCommand($spec, $this->messages);
        if (!$this->runner->start($cmd, null)) {
            $this->streaming = false;
            $this->error = $this->shell->t('ai.error', ['msg' => 'spawn failed']);
            $this->shell->setMessage($this->error);
            $this->provider->cleanup();
            return;
        }
        $this->streaming = true;
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
            foreach ($this->parser->push($bytes) as $delta) {
                $this->appendDelta($delta);
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
    }

    /** 应用退出时调用：请求还在跑就杀掉，并清掉临时文件。 */
    public function shutdown(): void
    {
        $this->runner->shutdown();
        $this->streaming = false;
        $this->provider->cleanup();
    }

    // ── 内部 ──────────────────────────────────────────

    private function appendDelta(string $delta): void
    {
        $i = count($this->messages) - 1;
        if ($i < 0 || $this->messages[$i]['role'] !== 'assistant') {
            return;
        }
        $this->messages[$i]['content'] .= $delta;
    }

    /** 进程结束后的收尾：把残留半行解析掉、判定成功/失败、清理临时文件。 */
    private function finish(): void
    {
        $this->streaming = false;

        if ($this->parser !== null) {
            foreach ($this->parser->flush() as $delta) {
                $this->appendDelta($delta);
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

        if ($this->error !== null) {
            $this->shell->setMessage($this->error);
        } elseif ($this->lastAssistantText() === '') {
            // 没报错但也没内容：别让界面上留一个空气泡，用户会以为卡了
            $this->shell->setMessage($this->shell->t('ai.empty'));
        }

        $this->finalizeContent();
        $this->provider->cleanup();
        $this->parser = null;
    }

    /**
     * 收尾时对最后一条 assistant 消息做清理：
     *  - 完全为空则从历史里移除（否则每轮失败都会留下一条空记录，还会被当成上下文发回去）
     */
    private function finalizeContent(): void
    {
        $i = count($this->messages) - 1;
        if ($i >= 0 && $this->messages[$i]['role'] === 'assistant' && $this->messages[$i]['content'] === '') {
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

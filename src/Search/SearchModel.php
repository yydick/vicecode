<?php
declare(strict_types=1);

namespace App\Search;

use App\App;
use App\Terminal\CommandRunner;

/**
 * 搜索面板状态 + 异步执行。
 *
 * 状态归这个模型自己；SidebarPanel 的 Search tab 只读取它（与 GitModel 同构）。
 *
 * 为什么用 CommandRunner 而不是 GitModel 那套 `\go()` + 阻塞 exec：
 * GitModel 跑的 `git status` 是毫秒级，阻塞一下无所谓；搜索可能跑几秒，
 * 用阻塞 exec 会把整个事件循环卡死（协程也救不了——@exec 不是可让出的 I/O），
 * 界面会僵住，直接违背 M4/R2「搜索期间界面仍可操作」。CommandRunner 的
 * 「子进程 + 非阻塞管道 + 主循环每轮 poll」才是这个项目里唯一正确的长任务范式。
 *
 * 数据流：start() 起进程 → 主循环每轮 poll() 排空管道并增量解析 → 进程退出时 finalize()。
 * ⚠️ 重绘是事件驱动的：poll() 必须把「本轮有无新内容」如实返回，主循环靠它决定要不要重画，
 * 返回值被吞掉的话搜索跑完界面不会动（见 bin/vicecode.php 的重绘判据）。
 */
final class SearchModel
{
    public string $query = '';

    /** 是否有搜索在跑（含"进程已起但还没读到数据"的阶段） */
    public bool $running = false;

    /**
     * 焦点语义：true=在输入框（回车触发搜索），false=在结果区（回车打开/折叠）。
     * 一个 tab 里塞了输入框和列表两种交互，靠这个标记区分回车该干什么。
     */
    public bool $editingQuery = true;

    public ?string $error = null;

    /** 是否因触达 MAX_MATCHES 被截断（界面要提示，否则用户以为就这么点结果） */
    public bool $truncated = false;

    /** 已收集命中数（上限判断用，等于 totalMatches，但在跑的过程中就有值） */
    public int $matched = 0;

    public int $totalMatches = 0;

    public int $totalFiles = 0;

    /** 选中的「可见行」下标（不是命中下标——折叠后两者不等，见 SearchRow） */
    public int $selIdx = 0;

    /** @var array<string,bool> 折叠的文件路径集合（默认空 = 全展开） */
    public array $collapsed = [];

    /** @var array<string,SearchGroup> path => group，增量分组用（边读边聚，不等命令跑完） */
    private array $byPath = [];

    private ?CommandRunner $runner = null;

    /**
     * 跨 chunk 的未完成半行，**保持原始字节**。
     * 不能提前 sanitize：一个 UTF-8 汉字占 3 字节，chunk 边界随时会从中间劈开，
     * 此时 sanitize 里的 mb_convert_encoding 会把半个字符"修"成替换符，拼回去就乱码了。
     * 只在凑成完整行（遇到 \n）之后才交给 SearchClient::parseLine 去清洗。
     */
    private string $tail = '';

    /** 累积的 stderr（权限错误等），非空说明搜索不完整 */
    private string $stderr = '';

    /** 已因达上限发过 cancel（避免每行都重复 kill） */
    private bool $canceled = false;

    public function __construct(private App $shell)
    {
    }

    public function isRunning(): bool
    {
        return $this->runner !== null && $this->runner->isRunning();
    }

    /** 搜索根：固定工作区根（与 VSCode 一致，也与 GitModel::cwd() 同源） */
    private function cwd(): string
    {
        return getcwd() ?: '.';
    }

    /**
     * R1：触发一次搜索（回车调用）。空 query 只提示、不起进程。
     * 已有搜索在跑就先 kill 掉——用户改了词，旧结果没有意义了。
     */
    public function start(): void
    {
        if (trim($this->query) === '') {
            $this->shell->setMessage($this->shell->t('search.empty_query'));
            return;
        }

        $this->runner ??= new CommandRunner();
        if ($this->runner->isRunning()) {
            $this->runner->cancel();
            // 不等它死：CommandRunner::start 在 running 时会返回 false，所以下面得先收尾。
            // 但 SIGKILL 后内核不一定立刻回收，poll() 可能仍看到 running=true 而不 finish()，
            // 那样 start() 会返回 false、二次搜索静默失败。这里带界限地等它真正退出。
            $guard = 0;
            while ($this->runner->isRunning() && $guard++ < 50) {
                usleep(20000);
                $this->runner->poll(static function (string $b, bool $e): void {
                });
            }
        }

        // 复位全部结果状态（保留 query 与 collapsed 之外的一切）
        $this->byPath = [];
        $this->matched = 0;
        $this->totalMatches = 0;
        $this->totalFiles = 0;
        $this->tail = '';
        $this->stderr = '';
        $this->truncated = false;
        $this->error = null;
        $this->canceled = false;
        $this->collapsed = [];
        $this->selIdx = 0;
        $this->editingQuery = false; // 触发后焦点语义转到结果区，方向键即可导航

        $this->running = true;
        if (!$this->runner->start(SearchClient::buildCommand($this->query), $this->cwd())) {
            $this->running = false;
            $this->shell->setMessage($this->shell->t('term.spawn_failed'));
        }
    }

    /**
     * 主循环每轮调一次：排空管道 + 增量解析。返回本轮是否产生了新内容。
     *
     * 返回值直接决定要不要重绘，不能省（详见类注释）。
     */
    public function poll(): bool
    {
        if ($this->runner === null || !$this->runner->isRunning()) {
            return false;
        }

        $got = $this->runner->poll(function (string $bytes, bool $isErr): void {
            if ($isErr) {
                $this->stderr .= $bytes; // 权限错误等，不是命中，别混进结果
                return;
            }
            $chunk = $this->tail . $bytes;
            $parts = explode("\n", $chunk);
            $this->tail = (string) array_pop($parts); // 最后一段可能是半行，留到下一轮
            foreach ($parts as $line) {
                $this->ingestLine($line);
                if ($this->truncated && !$this->canceled) {
                    // 达上限：没必要让 grep 继续遍历整个仓库
                    $this->canceled = true;
                    $this->runner?->cancel();
                }
            }
        });

        if (!$this->runner->isRunning()) {
            if ($this->tail !== '') {
                $this->ingestLine($this->tail); // 最后一行可能没有换行符结尾
                $this->tail = '';
            }
            $this->running = false;
            $this->finalize();
            $got = true; // 状态从"搜索中"变成"已完成"，界面必须重画
        }
        return $got;
    }

    /** 摄入一行 grep 输出（纯状态操作，单测可直接调，不必起进程） */
    public function ingestLine(string $raw): void
    {
        if ($this->truncated) {
            return;
        }
        $hit = SearchClient::parseLine($raw);
        if ($hit === null) {
            return;
        }
        if ($this->matched >= SearchClient::MAX_MATCHES) {
            $this->truncated = true;
            return;
        }
        $this->matched++;
        $this->byPath[$hit->path] ??= new SearchGroup($hit->path, []);
        $this->byPath[$hit->path]->hits[] = $hit;
    }

    /**
     * 搜索结束后结算：定稿分组、给出状态提示、自动选中首条命中。
     *
     * ⚠️ grep 的退出码语义容易搞混：0=有匹配，1=**无匹配（正常，不是错误）**，2=真实错误。
     * 把 1 当错误的话，每次搜不到东西都会误报"搜索出错"。
     */
    private function finalize(): void
    {
        $this->totalFiles = count($this->byPath);
        $this->totalMatches = $this->matched;
        $code = $this->runner?->exitCode();

        if ($this->truncated) {
            $this->shell->setMessage($this->shell->t('search.truncated', [
                'n' => (string) SearchClient::MAX_MATCHES,
            ]));
            $this->selIdx = $this->firstHitRow();
            return;
        }
        if ($code === 2 || $this->stderr !== '') {
            // 部分文件读不了（权限）也走这里：结果可能不完整，得让用户知道
            $this->error = trim(SearchClient::firstErrorLine($this->stderr));
            $this->shell->setMessage($this->shell->t('search.status_error', [
                'msg' => $this->error !== '' ? $this->error : 'code ' . ((string) $code),
            ]));
            return;
        }
        if ($this->totalMatches === 0) {
            $this->shell->setMessage($this->shell->t('search.status_no_results'));
            return;
        }
        $this->shell->setMessage($this->shell->t('search.results', [
            'n' => (string) $this->totalMatches,
            'files' => (string) $this->totalFiles,
        ]));
        $this->selIdx = $this->firstHitRow(); // 搜完即选中首条，回车直接打开
    }

    /**
     * 构造「可见行」列表：每个分组一行 HEADER，未折叠则其下逐条 HIT。
     *
     * 渲染与点击命中**都必须调这一个**，谁都别自己缓存快照——
     * 折叠状态一变，缓存就和屏幕对不上，点击会打开错误的行（见 SearchRow 注释）。
     * 每帧现算的成本可以忽略（结果总数有 MAX_MATCHES 上限兜着）。
     *
     * ⚠️ 从「实时累加器」$byPath 现算（不缓存快照）：搜索进行中（流式摄入）界面也能
     * 看到中间结果；单测直接调 ingestLine() 也有输出。
     *
     * @return SearchRow[]
     */
    public function buildVisibleRows(): array
    {
        $rows = [];
        foreach (array_values($this->byPath) as $group) {
            $rows[] = new SearchRow(SearchRow::HEADER, $group->path, group: $group);
            if (isset($this->collapsed[$group->path])) {
                continue;
            }
            foreach ($group->hits as $hit) {
                $rows[] = new SearchRow(SearchRow::HIT, $group->path, hit: $hit);
            }
        }
        return $rows;
    }

    /** 首个 HIT 行的下标（搜完自动选它，让"回车就打开第一个结果"成立） */
    private function firstHitRow(): int
    {
        foreach ($this->buildVisibleRows() as $i => $row) {
            if ($row->kind === SearchRow::HIT) {
                return $i;
            }
        }
        return 0;
    }

    public function moveSelection(int $delta): void
    {
        $n = count($this->buildVisibleRows());
        if ($n === 0) {
            return;
        }
        $this->selIdx = max(0, min($n - 1, $this->selIdx + $delta));
        $this->editingQuery = false; // 一动方向键就说明用户在看结果，不是在打字
    }

    /** 折叠/展开某文件分组，并把选中行夹回有效范围（折叠会让可见行变少） */
    public function toggleGroup(string $path): void
    {
        if (isset($this->collapsed[$path])) {
            unset($this->collapsed[$path]);
        } else {
            $this->collapsed[$path] = true;
        }
        $n = count($this->buildVisibleRows());
        $this->selIdx = $n === 0 ? 0 : max(0, min($n - 1, $this->selIdx));
    }

    /**
     * R3：打开文件并定位到命中行。
     *
     * 不需要给编辑器加新 API：Buffer::$cursorRow 是 public，而 EditorPanel::content()
     * 每帧都会按 cursorRow 夹紧 scrollTop 保证光标行可见——设个字段，滚动是白送的。
     */
    public function openHit(string $path, int $line): void
    {
        $this->shell->openFile($path); // 内部已设 shell->buffer 并把焦点切到 editor
        $buf = $this->shell->buffer;
        if ($buf === null) {
            return;
        }
        $maxRow = max(0, count($buf->lines) - 1);
        $buf->cursorRow = min($maxRow, max(0, $line - 1)); // grep 行号 1-based → cursorRow 0-based
        $buf->cursorCol = 0;
    }

    /** 回车语义：输入框态=触发搜索；结果区态=HEADER 折叠 / HIT 打开 */
    public function triggerOrActivate(): void
    {
        if ($this->editingQuery) {
            $this->start();
            return;
        }
        $rows = $this->buildVisibleRows();
        $row = $rows[$this->selIdx] ?? null;
        if ($row === null) {
            // 结果区是空的（比如搜完没命中），回车退回"再搜一次"的语义，不至于按了没反应
            $this->start();
            return;
        }
        if ($row->kind === SearchRow::HEADER) {
            $this->toggleGroup($row->path);
            return;
        }
        if ($row->hit !== null) {
            $this->openHit($row->hit->path, $row->hit->line);
        }
    }

    /** 应用退出时调用：搜索还在跑就杀掉子进程，避免留孤儿 */
    public function shutdown(): void
    {
        $this->runner?->shutdown();
        $this->running = false;
    }
}

<?php
declare(strict_types=1);

namespace App\Panel;

use App\Core\ConfigStore;
use App\Core\KeyInput;
use App\App;
use App\Terminal\Cell;
use App\Terminal\CommandRunner;
use App\Terminal\PtyColor;
use App\Terminal\PtyProcess;
use App\Terminal\SessionStore;
use App\Terminal\TerminalBuffer;
use App\Terminal\Vt100Emulator;
use App\Text\DisplayWidth;
use App\Text\SpanClip;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\KeyModifiers;
use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Style\Modifier;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Widget\Margin;
use PhpTui\Tui\Widget\Widget;

/**
 * 终端面板：**默认走真实交互式 PTY**（bash --rcfile），shell 退出后回落到命令运行器。
 *
 * 两种模式共用同一块画布与同一个 TerminalBuffer 语义：
 *   - pty（默认）：真实 shell，用户的别名/函数/提示符都在（`ll` 直接能用）；
 *     聚焦后敲第一个可打印字符/回车自动进捕获（App::handleEvent），F2 切换捕获，
 *     Esc（捕获态）退出捕获。
 *   - runner（回落态）：shell 退出（`exit` / Ctrl+D）后接管，用 `sh -c` 跑单条命令。
 *     命令在独立子进程里跑，主循环每轮调 poll() 排空管道，故不阻塞渲染。
 *
 * 面板契约（本项目无 interface，靠签名约定保持一致）：
 *   content(Area, bool $focused): Widget —— 生成面板内容 Widget（外层 Block 由 App 加）；
 *   onChar() / onKey() / onClick() / onScroll() 返回 bool 表示是否已消费事件；
 *   状态归面板自己，App 只负责把事件分发给当前聚焦面板。
 *
 * ⚠️ content() 不是纯函数：它会把钳制后的滚动偏移回写到 $scroll
 * （视口贴住末尾时同步最新偏移，这样下次手动滚动才从正确的位置起算）；
 * pty 路径下它还会**起 shell**（聚焦后的首帧，用真实面板尺寸）。这是既有渲染管线约定，
 * 保持原样搬移，不要在重构时「提纯」。
 */
final class TerminalPanel
{
    /** 命令在独立子进程跑，主循环每轮 poll() 排空管道，故不阻塞渲染 */
    private CommandRunner $runner;

    private TerminalBuffer $buf;

    private string $cwd;

    public string $input = '';

    public int $pos = 0;

    public int $scroll = 0;

    /** 输出区横向滚动偏移（显示列），鼠标横向滚轮/触控板调整 */
    public int $hScroll = 0;

    /** 视口是否贴住输出末尾（有手动滚动时置 false） */
    public bool $follow = true;

    /** @var string[] */
    public array $hist = [];

    /** -1=正在编辑新行，否则为 hist 下标 */
    public int $histIdx = -1;

    private const MAX_HISTORY = 200;

    // ── 交互式 PTY 模式（默认，与命令运行器共存）──
    /**
     * 'pty' = 真实交互式 shell（**默认**）；'runner' = 命令运行器（shell 退出后的回落态）。
     *
     * 默认走 pty 的理由：runner 用 `sh -c` 起命令，**看不到用户的 shell 别名/函数**
     * （`ll` 这类来自 ~/.bashrc 的别名一律 command not found），且非交互、没有提示符。
     * 用户报障原文就是「终端里 ll 用不了」。
     */
    public string $mode = 'pty';

    /** 捕获态：所有按键转发 PTY（runner 模式恒为 false）。默认**不**捕获 —— 焦点落在终端后
     *  敲第一个可打印字符/回车才进捕获（App::handleEvent 的自动捕获分支），
     *  这样 Tab/Esc/方向键仍归应用导航，用户能 Tab 走。 */
    public bool $captured = false;

    private ?PtyProcess $pty = null;

    private ?Vt100Emulator $emu = null;

    /**
     * 文本选择矩形（归一化 [r0,c0,r1,c1]，视口绝对行列）；null=无选择。
     * 由 App 渲染前注入，ptyContent/runnerContent 据此反显高亮。
     */
    private ?array $sel = null;

    /** 最近一次 ptyContent 渲染的可见网格（list<list<Cell>>），供 getTextRect 取字 */
    private ?array $lastGrid = null;

    // ── 会话持久化恢复（App 构造末尾 maybeRestore() 灌入，首帧真实尺寸下 spawn）──
    /** 恢复目标 cwd（启动 cwd，非运行时 cd） */
    private ?string $restoreCwd = null;

    /** 恢复快照纯文本 */
    private ?string $restoreText = null;

    /** 恢复快照彩色网格（v2；优先于 restoreText） */
    private ?array $restoreCells = null;

    /** 待恢复标记：首帧 ptyContent() 在真实列宽下起 pty 并灌入 */
    private bool $restorePending = false;

    /** 交互 pty 待启动：默认 pty 模式下一构造就为真；推迟到**聚焦后的首帧** ptyContent()
     *  用真实面板尺寸起 pty。双重目的：
     *   1) 避免硬编码 80x24 与面板不符，导致全屏程序按错误 LINES 渲染、首末行错位；
     *   2) 未聚焦就不 spawn —— content() 与焦点无关、App::render() 每帧都构造它，
     *      若首帧无条件 spawn，几十个 headless 渲染用例会各起一个 bash（孤儿进程 + 秒级开销）。 */
    private bool $ptyStartPending = true;

    /** 回退滚动偏移（滚轮 / PageUp/Down 调整） */
    public int $scrollback = 0;

    /** 本次会话是否在 runner 模式下真正跑过命令（仅此才值得持久化 runner scrollback；
     *  单纯从 pty 退出回落 runner 不算——那样 buf 里只有自动退出横幅，不值得存盘） */
    private bool $ranInRunner = false;

    /** 上次同步给仿真器的尺寸（避免每帧 resize） */
    private int $lastCols = 0;

    private int $lastRows = 0;

    /** 是否已登记「进程退出时回收 pty」的兜底钩子 */
    private bool $reapRegistered = false;

    /**
     * shell 是否已「可接收输入」（提示符已画出）。
     *
     * ⚠️ 不能刚 spawn 就往里写：bash 在 readline 初始化之前**会丢掉**先到的输入 ——
     * 实测（写一个临时探针复现，6/6）：tty 回显看得见那行命令，
     * 但它既不执行、也没留在行里，随后直接出现一个新提示符。表现为「聚焦终端后立刻敲
     * `ll`，屏幕上明明有 `ll` 却什么都没发生」。启动慢的 .bashrc（nvm/pyenv/conda）
     * 会把窗口拉到几百毫秒到数秒，人手速足够快就能撞上。
     * 守护用例：`tests/term_default_unit.php` 第 9 段（去掉这里的缓冲就会红）。
     */
    private bool $shellReady = false;

    /** shell 就绪前攒下的按键字节（就绪后原样补发，见 $shellReady） */
    private string $pendingInput = '';

    /** 就绪判据的兜底时限：某些 shell / rc 组合不报 cwd 也不能把用户输入永远攒着 */
    private float $shellReadyDeadline = 0.0;

    public function __construct(private App $shell)
    {
        $this->runner = new CommandRunner();
        $this->buf = new TerminalBuffer();
        $this->cwd = getcwd() ?: '.';
    }

    // ── 状态读取（供 App 与测试断言）────────────────────

    public function buffer(): TerminalBuffer
    {
        return $this->buf;
    }

    /** 终端当前工作目录（启动 cwd，或在交互式 pty 里随 shell cd 实时更新） */
    public function cwd(): string
    {
        return $this->cwd;
    }

    public function isRunning(): bool
    {
        if ($this->runner->isRunning()) {
            return true;
        }
        if ($this->mode === 'pty' && $this->pty !== null && $this->pty->isRunning()) {
            return true;
        }
        return false;
    }

    // ── 主循环钩子 ────────────────────────────────────

    /** 主循环每轮调用：排空管道（命令运行器 或 交互式 PTY）。返回是否有新内容（供主循环决定是否重绘）。 */
    public function poll(): bool
    {
        if ($this->mode === 'runner') {
            return $this->pollRunner();
        }
        return $this->pollPty();
    }

    /** runner 模式排空命令管道 */
    private function pollRunner(): bool
    {
        if (!$this->runner->isRunning()) {
            return false;
        }
        $got = $this->runner->poll(function (string $bytes, bool $isErr): void {
            $this->buf->append($bytes, $isErr);
            // V1.1：给插件的是**原始字节**（可能含 ANSI 与 OSC 7 的 cwd 上报），且不节流
            $this->shell->emitPluginEvent('terminal.output', ['bytes' => $bytes]);
        });
        if (!$this->runner->isRunning()) {
            // 命令结束：结算未终止的半行，并把视口拉回底部看结果
            $this->buf->flushTail();
            $this->follow = true;
            $got = true;
        }
        return $got;
    }

    /** pty 模式：排空主端输出喂给仿真器；shell 退出则退回 runner。 */
    private function pollPty(): bool
    {
        if ($this->pty === null) {
            // ⚠️ 还不能把 mode 打回 runner：默认 pty 下 shell 要等**聚焦后的首帧**才起
            // （见 $ptyStartPending），而 poll() 每轮主循环都跑、通常早于那一帧。
            // 少了这个判据，默认 pty 会在第一轮就被 poll 回 runner，等于没改。
            if ($this->ptyStartPending || $this->restorePending) {
                return false;
            }
            $this->mode = 'runner';
            return false;
        }

        $got = false;
        // 就绪兜底：非 bash / 被用户 rc 改掉 PROMPT_COMMAND 时收不到那条 cwd OSC，不能因此把
        // 用户的按键永远攒着。⚠️ 必须放在**任何 return 之前**（含下面 pollExited 的那条与
        // 「本轮无输出」的那条）—— 早先它排在 `if ($bytes === '') return false;` 之后，
        // 于是「bash 还没打出提示符」这种正是要兜底的情形反而永远走不到，攒下的按键成了
        // 死锁（实测 pty_term 段 A 直接挂住、term_default_unit 第 8 段拿不到字符）。
        if (!$this->shellReady && microtime(true) >= $this->shellReadyDeadline) {
            $got = $this->markShellReady();   // 补发了按键 → 值得重绘
        }
        if ($this->pty->pollExited()) {
            $this->fallbackToRunner();
            return true;
        }
        $bytes = $this->pty->read();
        if ($bytes === '') {
            return $got;
        }
        $this->shell->emitPluginEvent('terminal.output', ['bytes' => $bytes]);
        $this->emu?->write($bytes);
        // 消费 shell 经 OSC 回显的工作目录（实时 cwd 捕获）
        $cwd = $this->emu?->consumeCwd();
        if ($cwd !== null) {
            $this->cwd = $cwd;
            // 这条 OSC 是 PROMPT_COMMAND 里发的 —— 收到它就说明提示符已经画出来了、
            // readline 已经进了读循环，此后写进去的按键不会再被丢掉（见 $shellReady）。
            $this->markShellReady();
        }
        return true;
    }

    /** 标记 shell 就绪，并把就绪期攒下的按键按原顺序补发。返回是否真的补发了内容。 */
    private function markShellReady(): bool
    {
        if ($this->shellReady) {
            return false;
        }
        $this->shellReady = true;
        if ($this->pendingInput === '') {
            return false;
        }
        $bytes = $this->pendingInput;
        $this->pendingInput = '';
        if ($this->pty !== null && $this->pty->isRunning()) {
            $this->pty->write($bytes);
        }
        return true;
    }

    /**
     * 是否已到「可以往里写输入」的时候：提示符已到（$shellReady），或兜底时限已过。
     *
     * ⚠️ 兜底时限必须在**每个写入点**都判一次，不能只放在主循环的 poll 里：
     * 调用方完全可能长时间不 poll（headless 测试就直接 sendToPty），那时按键会一直
     * 攒着不出去 —— 实测 `term_default_unit` 第 8 段就是这么挂住的。
     */
    private function canAcceptInput(): bool
    {
        if (!$this->shellReady && microtime(true) >= $this->shellReadyDeadline) {
            $this->markShellReady();
        }
        return $this->shellReady;
    }

    /** shell 退出后的统一回落：横幅 + 回 runner 模式 + 退捕获 + 回收 pty。 */
    private function fallbackToRunner(): void
    {
        $this->buf->append($this->shell->t('term.interactive_exit') . "\n", false);
        $this->mode = 'runner';
        $this->captured = false;
        // 就绪期攒的按键属于那个已经死掉的 shell，不能带给下一个（见 $shellReady）
        $this->pendingInput = '';
        $this->shellReady = false;
        $this->dropPty();
        $this->emu = null;
    }

    /**
     * 把「shell 是否已退出」在**按键/粘贴路径上**同步掉（主循环 poll 之外的第二处结算）。
     *
     * 为什么需要：主循环每轮只 poll 一次，而一次 stdin 读取里可能连着好几个按键
     * （脚本一次性灌入的长命令、粘贴、未来的批量输入）。若中途 shell 退出了却要等到下一轮
     * poll 才知道，同一批按键就会被劈成两半 —— 前半喂给**已经死掉的 pty**（sendToPty 静默丢弃）、
     * 后半落进 runner 输入行，用户看到的是命令被**截掉开头**。
     * 实测（`pty_scroll` 场景 A）：`exit\r` 之后紧接的前 8 个字节
     * （`printf '` 连同开引号）被丢掉，于是 runner 执行到的是
     * `R%03d\n' $(seq 1 60)` —— sh 报 `unexpected EOF while looking for matching '`。
     */
    public function syncShellState(): void
    {
        if ($this->mode === 'pty' && $this->pty !== null && $this->pty->pollExited()) {
            $this->fallbackToRunner();
        }
    }

    // ── 交互式 PTY 控制 ────────────────────────────────

    /**
     * 切换交互模式：
     *  - runner → 起 shell、进入捕获态（mode=pty, captured=true）
     *  - pty 且 captured → 退出捕获（仍可切回，shell 继续跑）
     *  - pty 且未捕获 → 重新进入捕获
     */
    public function toggleInteractive(): void
    {
        if ($this->mode === 'runner') {
            if ($this->runner->isRunning()) {
                return; // 命令运行中不切换
            }
            // 延迟到首帧 ptyContent() 用真实面板尺寸起 pty（见 ptyStartPending 说明），
            // 避免用 80x24 硬编码尺寸启动后全屏程序按错误 LINES 渲染、首末行错位。
            $this->mode = 'pty';
            $this->captured = true;
            $this->scrollback = 0;
            $this->ptyStartPending = true;
            return;
        }
        // pty 模式：翻转捕获态
        $this->captured = !$this->captured;
        if ($this->captured) {
            $this->scrollback = 0;
        }
    }

    /** 转发按键字节给 PTY（捕获态由 App 调用） */
    public function sendToPty(string $bytes): void
    {
        if ($this->pty === null || !$this->pty->isRunning()) {
            return;
        }
        if (!$this->canAcceptInput()) {
            // 刚起的 bash 还没进读循环：此刻写进去会被 readline 初始化丢掉（见 $shellReady）。
            // 攒着，等提示符出来（或兜底时限到）再按原顺序补发。
            $this->pendingInput .= $bytes;
            return;
        }
        $this->pty->write($bytes);
    }

    /** 退出捕获态（shell 仍在跑，焦点回到应用导航） */
    public function exitCapture(): void
    {
        $this->captured = false;
    }

    /**
     * 进入捕获态（**自动捕获**用：聚焦终端时敲第一个可打印字符/回车就接管键盘，
     * 用户不必先按 F2）。与 toggleInteractive() 的区别是**只进不出**，
     * 便于 App 在事件分发里无条件调用——不必先判断当前捕获态。
     */
    public function enterCapture(): void
    {
        if ($this->mode !== 'pty') {
            return;
        }
        $this->captured = true;
        $this->scrollback = 0;
    }

    /**
     * 确保交互 shell 已启动（自动捕获路径用）。正常路径是「聚焦后的首帧 ptyContent()」
     * 起 shell，但**触发捕获的那个按键就在这一帧的事件里**：若等渲染完再转发，
     * 按键已经丢了（实测：一次性灌入 `ll\r` 时第一帧只处理事件、不渲染，
     * 三个字节全丢）。故这里用当前面板区域提前起 shell，再转发。
     */
    public function ensurePtyStarted(Area $terminal): void
    {
        if ($this->mode !== 'pty' || $this->pty !== null) {
            return;
        }
        [, $W, $outH] = $this->viewport($terminal);
        if ($W <= 0 || $outH <= 0) {
            return; // 面板太小（或未布局）：等首帧渲染路径再来
        }
        if ($this->restorePending) {
            $this->restoreSession($W, $outH);
        } elseif ($this->ptyStartPending) {
            $this->startPty($W, $outH);
        }
    }

    /** 面板内容区尺寸：（内层 Area, 列数, 输出行数=总行−1 行提示行） */
    private function viewport(Area $terminal): array
    {
        $inner = $terminal->inner(new Margin(1, 1));
        $W = max(0, $inner->width);
        $H = max(0, $inner->height);
        return [$inner, $W, max(0, $H - 1)];
    }

    /** 是否处于捕获态（App 据此拦截按键） */
    public function isCaptured(): bool
    {
        return $this->mode === 'pty' && $this->captured;
    }

    /**
     * 用真实面板尺寸起交互 pty（F2 切换后、首帧渲染时调用）。
     * 尺寸必须 = 面板视口（W x outH），使 stty 与 LINES/COLUMNS 环境一致，
     * 全屏程序（vim/less/top）按正确行数渲染，首末行不再错位或滚出视口。
     */
    private function startPty(int $W, int $outH): void
    {
        $this->emu = new Vt100Emulator($W, $outH);
        $this->pty = new PtyProcess();
        $this->ensureReapOnExit();
        $env = $this->buildPtyEnv();
        if (!$this->pty->start(
            (string) (getenv('SHELL') ?: '/bin/bash'),
            $W,
            $outH,
            $this->cwd,
            $env
        )) {
            $this->emu = null;
            $this->dropPty();
            $this->mode = 'runner';
            $this->captured = false;
            $this->ptyStartPending = false;
            $this->buf->append($this->shell->t('term.spawn_failed') . "\n", true);
            return;
        }
        $this->lastCols = $W;
        $this->lastRows = $outH;
        $this->ptyStartPending = false;
        $this->resetShellReadiness();
    }

    /**
     * 新起了一个 shell：把「是否可接收输入」打回未就绪，并记下兜底时限。
     * 之后 `sendToPty()` 会把按键攒起来，等提示符（或时限）到了再补发 —— 见 $shellReady。
     *
     * 时限按 shell 区分：
     *  - **bash**：我们注入了 `PROMPT_COMMAND` 钩子，提示符一画出来就有 cwd OSC 报到，
     *    所以正常路径几乎立刻就绪；兜底给足 10s —— 它只是「万一收不到 OSC」的保命线，
     *    给短了反而危险：启动慢的 `.bashrc`（nvm/pyenv/conda，实测批跑负载下能超过 1.5s）
     *    会让补发落在那次「丢输入」的窗口里，等于把 bug 原样搬回来。
     *  - **其它 shell**：没有这条 OSC，只能靠时限。给 0.3s，避免用户敲了半天看不见字。
     */
    private function resetShellReadiness(): void
    {
        $this->shellReady = false;
        $this->pendingInput = '';
        $isBash = basename((string) (getenv('SHELL') ?: '/bin/bash')) === 'bash';
        $this->shellReadyDeadline = microtime(true) + ($isBash ? 10.0 : 0.3);
    }

    // ── 会话持久化 ────────────────────────────────────

    /**
     * 退出时存盘（在 shutdown() 最开头调用，此时 emu / buf 仍持有内容、早于杀进程）。
     *  - pty 模式：落盘主屏+滚动历史的彩色网格(v2) + 纯文本回退。
     *  - runner 模式：落盘输出缓冲（viewport 无关；仅在有内容时保存，避免空缓冲覆盖 pty 快照）。
     * 落盘失败静默（不致命）。
     */
    public function saveSession(): void
    {
        if (!ConfigStore::persistSession()) {
            return;
        }
        if ($this->mode === 'pty') {
            if ($this->emu === null) {
                return;
            }
            SessionStore::save([
                'mode' => 'pty',
                'cwd' => $this->cwd,
                'cells' => $this->emu->exportCells(),
                'text' => $this->emu->exportText(),
                'savedAt' => time(),
            ]);
            return;
        }
        // runner 模式：输出缓冲（先 flush 未结束半行），仅非空且本次会话真正跑过命令时保存
        // （避免 pty 退出回落后的自动横幅被误存，破坏「快照已消费」不变量）。
        if (!$this->ranInRunner || $this->buf->count() === 0) {
            return;
        }
        $this->buf->flushTail();
        SessionStore::save([
            'mode' => 'runner',
            'cwd' => $this->cwd,
            'lines' => $this->buf->exportLines(),
            'savedAt' => time(),
        ]);
    }

    /**
     * 启动恢复（App 构造末尾调用）。仅当开启持久化且存在有效快照时：
     * 灌入 cwd/text、置 pty 捕获态、标记待恢复——**此刻不起 pty**（屏宽未定会折行错位，
     * 且 shell 提示符会被快照冲掉）；真正的 spawn+灌入推迟到 ptyContent() 首帧真实尺寸。
     */
    public function maybeRestore(): void
    {
        if (!ConfigStore::persistSession()) {
            return;
        }
        $snap = SessionStore::load();
        if ($snap === null) {
            return;
        }
        // runner 模式快照：viewport 无关，构造时直接灌入缓冲，无需首帧延迟。
        if (($snap['mode'] ?? 'pty') === 'runner'
            && isset($snap['lines']) && is_array($snap['lines'])) {
            $this->buf->loadLines($snap['lines']);
            $this->cwd = $snap['cwd'];
            // ⚠️ 必须显式回落到 runner：默认模式已改成 pty，不写这两行的话
            // runner 快照恢复完仍停在 pty，接着还会去 spawn 一个 shell，快照等于白恢复。
            $this->mode = 'runner';
            $this->ptyStartPending = false;
            SessionStore::clear();
            return;
        }
        // pty 模式快照（既有逻辑）：首帧真实尺寸下才起 pty 灌入。
        if (isset($snap['cells']) && is_array($snap['cells'])) {
            $this->restoreCells = $snap['cells'];
        } else {
            $this->restoreText = $snap['text'] ?? '';
        }
        $this->restoreCwd = $snap['cwd'];
        $this->restorePending = true;
        $this->ptyStartPending = false;   // 走恢复路径（restoreSession），别再让默认起 shell 那条路也标着待启动
        $this->mode = 'pty';
        $this->captured = true;
        $this->scrollback = 0;
        // 清掉上次会话快照，避免下次启动又恢复同一份（会话已「消费」）
        SessionStore::clear();
    }

    /**
     * 首帧恢复：在真实列宽下起一个新 shell，把快照纯文本灌入仿真器，
     * 使新 shell 的提示符接在快照之后。pty start 失败则回退 runner（不致命）。
     */
    private function restoreSession(int $W, int $outH): void
    {
        $cwd = $this->restoreCwd ?? $this->cwd;
        $this->emu = new Vt100Emulator($W, $outH);
        $this->pty = new PtyProcess();
        $this->ensureReapOnExit();
        $env = $this->buildPtyEnv();
        if (!$this->pty->start(
            (string) (getenv('SHELL') ?: '/bin/bash'),
            $W,
            $outH,
            $cwd,
            $env
        )) {
            // 失败回退 runner（不致命）
            $this->emu = null;
            $this->dropPty();
            $this->mode = 'runner';
            $this->captured = false;
            $this->restorePending = false;
            $this->restoreCwd = null;
            $this->restoreText = null;
            $this->restoreCells = null;
            return;
        }
        // 彩色快照优先（v2）；否则回退纯文本（v1 旧快照）
        if ($this->restoreCells !== null) {
            $this->emu->importCells($this->restoreCells);
        } else {
            $this->emu->importText($this->restoreText ?? '');
        }
        $this->lastCols = $W;
        $this->lastRows = $outH;
        $this->cwd = $cwd; // 仅恢复启动 cwd（v1 局限：运行时 cd 不恢复）
        $this->scrollback = 0;
        $this->restorePending = false;
        $this->restoreCwd = null;
        $this->restoreText = null;
        $this->restoreCells = null;
        // 恢复出来的新 shell 同样要先等提示符（快照是灌进仿真器的，不代表 shell 已可接收输入）
        $this->resetShellReadiness();
    }

    /** 回退滚动（滚轮 / PageUp / PageDown） */
    public function scrollPty(int $delta): void
    {
        $max = $this->emu !== null ? $this->emu->scrollbackSize() : 0;
        // 约定：scrollback=0 表示贴住最新（底部），scrollback=max 表示翻到最旧（顶部）。
        // 故「向上 / 翻旧」（delta<0）需增大 scrollback，对 delta 取反后再钳制。
        $this->scrollback = max(0, min($max, $this->scrollback - $delta));
    }

    /** pty 回退缓冲总行数（无 pty 时 0）。测试与状态栏断言用。 */
    public function scrollbackSize(): int
    {
        return $this->emu !== null ? $this->emu->scrollbackSize() : 0;
    }

    /** 滚轮：pty 模式翻回退，否则内容滚动 */
    public function wheel(int $delta): void
    {
        if ($this->mode === 'pty') {
            $this->scrollPty($delta);
        } else {
            $this->scrollBy($delta);
        }
    }

    /** 构造注入 PTY 的环境变量（继承关键变量，强制 TERM） */
    private function buildPtyEnv(): array
    {
        $keys = [
            'PATH', 'HOME', 'USER', 'LOGNAME', 'LANG', 'LC_ALL', 'TERM', 'SHELL',
            'PWD', 'HOSTNAME', 'XDG_RUNTIME_DIR', 'DISPLAY',
        ];
        $env = [];
        foreach ($keys as $k) {
            $v = getenv($k);
            if ($v !== false) {
                $env[$k] = $v;
            }
        }
        return $env;
    }

    // ── 渲染 ──────────────────────────────────────────

    /** 面板内容：末行固定为命令输入行，其上是输出视口 */
    public function content(Area $terminal, bool $focused): Widget
    {
        if ($this->mode === 'pty') {
            return $this->ptyContent($terminal, $focused);
        }
        return $this->runnerContent($terminal, $focused);
    }

    /** 交互式 PTY 模式渲染：仿真器网格 + 底部提示行（无命令输入行） */
    private function ptyContent(Area $terminal, bool $focused): Widget
    {
        [$inner, $W, $outH] = $this->viewport($terminal);

        if ($outH <= 0 || $W <= 0) {
            return ParagraphWidget::fromLines(
                Line::fromSpans(Span::styled('', Style::default()))
            );
        }

        // 恢复快照 / 起默认 shell 都推迟到**聚焦后的首帧**（真实尺寸 + 不给未聚焦的渲染起进程）。
        // 未聚焦且 shell 还没起 → 只画一行「聚焦即启动」，不 spawn，也不建仿真器矩阵。
        if ($this->pty === null && ($this->restorePending || $this->ptyStartPending)) {
            if (!$focused) {
                return $this->pendingContent($W, $outH, $focused);
            }
            if ($this->restorePending) {
                $this->restoreSession($W, $outH);
            } else {
                $this->startPty($W, $outH);
            }
            if ($this->mode !== 'pty') {
                return $this->runnerContent($terminal, $focused);
            }
        }

        // 尺寸同步：emu 初始 80×24，首帧按实际面板尺寸重建；变化时才 resize（避免每帧写 stty）
        if ($this->emu === null) {
            $this->emu = new Vt100Emulator($W, $outH);
            $this->lastCols = $W;
            $this->lastRows = $outH;
        } elseif ($this->lastCols !== $W || $this->lastRows !== $outH) {
            $this->emu->resize($W, $outH);
            $this->lastCols = $W;
            $this->lastRows = $outH;
            if ($this->pty !== null && $this->pty->isRunning()) {
                $this->pty->resize($W, $outH);
            }
        }

        $grid = $this->emu->gridForRender($outH, $this->scrollback);
        $cursor = $grid['cursor'];
        $this->lastGrid = $grid['lines'];

        $lines = [];
        foreach ($grid['lines'] as $rIdx => $cells) {
            $spans = [];
            $curKey = null;
            $curText = '';
            $curStyle = Style::default();
            $flush = static function () use (&$spans, &$curText, &$curStyle): void {
                if ($curText !== '') {
                    $spans[] = Span::styled($curText, $curStyle);
                    $curText = '';
                }
            };
            foreach ($cells as $cIdx => $cell) {
                $isCursor = $cursor !== null
                    && $cursor['y'] === $rIdx
                    && $cursor['x'] === $cIdx;
                // 文本选择：本格落在选区矩形内则反显（与光标反显叠加）
                $inSel = $this->sel !== null
                    && ($inner->position->y + $rIdx) >= $this->sel[0]
                    && ($inner->position->y + $rIdx) <= $this->sel[2]
                    && ($inner->position->x + $cIdx) >= $this->sel[1]
                    && ($inner->position->x + $cIdx) <= $this->sel[3];
                [$st, $key] = $this->styleAndKey($cell, $isCursor || $inSel);
                if ($key !== $curKey) {
                    $flush();
                    $curKey = $key;
                    $curStyle = $st;
                }
                $ch = $cell->wide ? ' ' : ($cell->ch === '' ? ' ' : $cell->ch);
                $curText .= $ch;
            }
            $flush();
            $lines[] = Line::fromSpans(...$spans);
        }
        $lines[] = $this->ptyHintLine($W, $focused);

        return ParagraphWidget::fromLines(...$lines);
    }

    /** 渲染前注入文本选择矩形（归一化 [r0,c0,r1,c1]）；null 清掉高亮 */
    public function setSelection(?array $r): void
    {
        $this->sel = $r;
    }

    /**
     * 对一组内容 Span 反显 [c0,c1] 绝对显示列区间（落在文本区内的部分）。
     * 文本区显示列从 $textX0 起；跨入选区的 Span 切成「前/选中/后」三段，选中段加 REVERSED。
     * @param Span[] $spans
     * @return Span[]
     */
    private function invertSpans(array $spans, int $textX0, int $c0, int $c1): array
    {
        if ($c1 < $c0) {
            return $spans;
        }
        $out = [];
        $col = $textX0;
        foreach ($spans as $span) {
            $text = $span->content;
            $w = DisplayWidth::dispWidth($text);
            $s0 = $col;
            $s1 = $col + $w;
            $col = $s1;
            $a = max($s0, $c0);
            $b = min($s1, $c1 + 1); // c1 含
            if ($a >= $b) {
                $out[] = $span;
                continue;
            }
            if ($a > $s0) {
                $out[] = new Span(DisplayWidth::mbSubDisp($text, 0, $a - $s0), $span->style);
            }
            $selText = DisplayWidth::mbSubDisp($text, $a - $s0, $b - $a);
            $out[] = new Span($selText, $span->style->addModifier(Modifier::REVERSED));
            if ($b < $s1) {
                $out[] = new Span(DisplayWidth::mbSubDisp($text, $b - $s0, $s1 - $b), $span->style);
            }
        }
        return $out;
    }

    /**
     * 取终端文本选择矩形内的文字（runner / pty 两模式）。坐标均为视口绝对行列。
     * runner 模式从 TerminalBuffer 取去色文本（按 hScroll 偏移映射）；pty 模式从缓存的
     * 可见屏幕网格取单元格（跳过宽字符右占位）。
     */
    public function getTextRect(Area $terminal, int $r0, int $c0, int $r1, int $c1): string
    {
        $inner = $terminal->inner(new Margin(1, 1));
        $W = max(0, $inner->width);
        $H = max(0, $inner->height);

        if ($this->mode === 'pty') {
            return $this->ptyTextRect($inner, $r0, $c0, $r1, $c1);
        }

        // ── runner 模式 ──
        $outH = max(0, $H - 1);
        $rows = $this->buf->all();
        $status = $this->statusRow();
        if ($status !== null) {
            $rows[] = $status;
        }
        if ($rows === []) {
            return '';
        }
        $maxOff = max(0, count($rows) - $outH);
        $off = $this->follow ? $maxOff : min(max(0, $this->scroll), $maxOff);

        $out = [];
        for ($row = $r0; $row <= $r1; $row++) {
            $v = $row - $inner->position->y;
            if ($v < 0 || $v >= $outH) {
                $out[] = '';
                continue;
            }
            $src = $rows[$off + $v] ?? null;
            if ($src === null) {
                $out[] = '';
                continue;
            }
            $text = $src['text'] ?? '';
            // 显示文本 = mbSubDisp(text, hScroll, W)：屏幕上第 d 列显示的是**文本第 d+hScroll 列**。
            // 故选区绝对列 → 文本显示列 = (absCol - inner.x) + hScroll（**加**回偏移）。
            // 曾写成减 hScroll（符号反了）：未横滚时无差别，一横滚抓到的就是行首那几个字。
            $dispA = max(0, $c0 - $inner->position->x + $this->hScroll);
            $dispB = max(0, $c1 - $inner->position->x + $this->hScroll);
            $chA = DisplayWidth::mbDispToCharIndex($text, $dispA);
            $chB = DisplayWidth::mbDispToCharIndex($text, $dispB + 1);
            $out[] = mb_substr($text, $chA, $chB - $chA);
        }
        return implode("\n", $out);
    }

    /** pty 模式取字：从缓存可见网格按单元格拼字（跳过宽字符右占位） */
    private function ptyTextRect(Area $inner, int $r0, int $c0, int $r1, int $c1): string
    {
        if ($this->lastGrid === null) {
            return '';
        }
        $out = [];
        for ($row = $r0; $row <= $r1; $row++) {
            $v = $row - $inner->position->y;
            if ($v < 0 || $v >= count($this->lastGrid)) {
                $out[] = '';
                continue;
            }
            $cells = $this->lastGrid[$v];
            $line = '';
            for ($col = $c0; $col <= $c1; $col++) {
                $vc = $col - $inner->position->x;
                if ($vc < 0 || $vc >= count($cells)) {
                    break;
                }
                $cell = $cells[$vc];
                if ($cell->wide) {
                    continue; // 宽字符右占位：跳过，避免重复空格
                }
                $line .= $cell->ch === '' ? ' ' : $cell->ch;
            }
            $out[] = $line;
        }
        return implode("\n", $out);
    }

    /** 单格样式 + 合并判据键（同键合并成一个 Span，降低渲染开销） */
    private function styleAndKey(Cell $cell, bool $reverse): array
    {
        $st = Style::default();
        $fk = 'd';
        $bk = 'd';
        $mods = 0;
        if ($cell->fg >= 0) {
            $fg = PtyColor::fg($cell->fg);
            if ($fg !== null) {
                $st = $st->fg($fg);
            }
            $fk = (string) $cell->fg; // 用原始索引作合并键（Color 对象不可 string 化）
        }
        if ($cell->bg >= 0) {
            $bg = PtyColor::bg($cell->bg);
            if ($bg !== null) {
                $st = $st->bg($bg);
            }
            $bk = (string) $cell->bg;
        }
        if (($cell->flags & Vt100Emulator::FLAG_BOLD) !== 0) {
            $st = $st->addModifier(Modifier::BOLD);
            $mods |= 1;
        }
        if (($cell->flags & Vt100Emulator::FLAG_DIM) !== 0) {
            $st = $st->addModifier(Modifier::DIM);
            $mods |= 2;
        }
        if (($cell->flags & Vt100Emulator::FLAG_ITALIC) !== 0) {
            $st = $st->addModifier(Modifier::ITALIC);
            $mods |= 4;
        }
        if (($cell->flags & Vt100Emulator::FLAG_UNDERLINE) !== 0) {
            $st = $st->addModifier(Modifier::UNDERLINED);
            $mods |= 8;
        }
        if ($reverse || ($cell->flags & Vt100Emulator::FLAG_REVERSE) !== 0) {
            $st = $st->addModifier(Modifier::REVERSED);
            $mods |= 16;
        }
        return [$st, $fk . '|' . $bk . '|' . $mods];
    }

    /** shell 还没起（未聚焦）：主体只留一行「聚焦即启动」，末行仍是提示行 */
    private function pendingContent(int $W, int $outH, bool $focused): Widget
    {
        $lines = [Line::fromSpans(Span::styled(
            $this->shell->t('term.focus_to_start'),
            $this->shell->theme->style('termHint')
        ))];
        while (count($lines) < $outH) {
            $lines[] = Line::fromSpans(Span::styled('', Style::default()));
        }
        $lines[] = $this->ptyHintLine($W, $focused);
        return ParagraphWidget::fromLines(...$lines);
    }

    /** 底部提示行：捕获态提示按 F2 退出；非捕获态提示「输入即接管键盘」 */
    private function ptyHintLine(int $W, bool $focused): Line
    {
        $hint = $this->captured
            ? $this->shell->t('term.interactive_hint')
            : $this->shell->t('term.interactive_enter');
        $style = $focused
            ? $this->shell->theme->style('borderFocus')
            : $this->shell->theme->style('border');
        $w = DisplayWidth::dispWidth($hint);
        if ($w < $W) {
            $hint .= str_repeat(' ', $W - $w);
        } elseif ($w > $W) {
            $hint = DisplayWidth::mbSubDisp($hint, 0, $W);
        }
        return Line::fromSpans(Span::styled($hint, $style));
    }

    /** 命令运行器模式渲染（原 content 逻辑） */
    private function runnerContent(Area $terminal, bool $focused): Widget
    {
        $inner = $terminal->inner(new Margin(1, 1));
        $W = max(0, $inner->width);
        $H = max(0, $inner->height);
        $outH = max(0, $H - 1);     // 末行留给输入行

        $rows = $this->buf->all();
        $status = $this->statusRow();
        if ($status !== null) {
            $rows[] = $status;
        }
        if ($rows === []) {
            $rows[] = ['text' => $this->shell->t('term.empty'), 'err' => false, 'kind' => 'hint'];
        }

        // 视口：follow 时贴住末尾，否则用 scroll（并回写钳制后的值）
        $maxOff = max(0, count($rows) - $outH);
        $off = $this->follow ? $maxOff : min(max(0, $this->scroll), $maxOff);
        $this->scroll = $off;

        // 横向滚动上界：当前视口内最宽行的显示列（多则截断、少则归零）
        $maxW = 0;
        foreach (array_slice($rows, $off, $outH) as $r) {
            $w = DisplayWidth::dispWidth($r['text'] ?? '');
            if ($w > $maxW) {
                $maxW = $w;
            }
        }
        // 上界钳到「最宽行 - 视口宽」：滚到最右时窗口正好停在行尾（含尾段 token），
        // 若钳到 maxW-1 则只露出最后 1 列，长行尾部内容永远看不到。
        $this->hScroll = max(0, min($this->hScroll, max(0, $maxW - $W)));

        $lines = [];
        foreach (array_slice($rows, $off, $outH) as $v => $row) {
            $kind = $row['kind'] ?? ($row['err'] ? 'err' : 'out');
            $style = match ($kind) {
                'err' => $this->shell->theme->style('termErr'),
                'killed' => $this->shell->theme->style('termKilled'),
                'hint' => $this->shell->theme->style('termHint'),
                default => Style::default(),
            };
            $disp = DisplayWidth::mbSubDisp($row['text'], $this->hScroll, $W);
            $span = Span::styled($disp, $style);
            // 文本选择反显：本可见行落在选区行范围内时，反显文本区内 [c0,c1] 显示列
            $absRow = $inner->position->y + $v;
            if ($this->sel !== null
                && $absRow >= $this->sel[0] && $absRow <= $this->sel[2]) {
                $inv = $this->invertSpans([$span], $inner->position->x, $this->sel[1], $this->sel[3]);
                $span = $inv[0] ?? $span;
            }
            $lines[] = Line::fromSpans($span);
        }
        // 输出不足一屏时补空行，把输入行顶到面板底部
        while (count($lines) < $outH) {
            $lines[] = Line::fromSpans(Span::styled('', Style::default()));
        }
        $lines[] = $this->inputLine($W, $focused);

        return ParagraphWidget::fromLines(...$lines);
    }

    /** 命令结束后的状态行（退出码 / 被中断）；无已结束命令返回 null */
    private function statusRow(): ?array
    {
        if ($this->runner->isRunning()) {
            return null;
        }
        $code = $this->runner->exitCode();
        if ($code === null) {
            return null;
        }
        if ($this->runner->termSig() !== 0) {
            return ['text' => $this->shell->t('term.killed'), 'err' => false, 'kind' => 'killed'];
        }
        if ($code !== 0) {
            return ['text' => $this->shell->t('term.exit', ['code' => (string) $code]), 'err' => false, 'kind' => 'err'];
        }
        return null;
    }

    /** 输入行：提示符 + 输入文本 + 光标反显（水平滚动保证光标可见） */
    private function inputLine(int $W, bool $focused): Line
    {
        $running = $this->runner->isRunning();
        $prompt = $running ? '● ' : '$ ';
        $promptStyle = $running
            ? $this->shell->theme->style('termPromptIdle')
            : $this->shell->theme->style('termPromptBusy');
        $textW = max(0, $W - DisplayWidth::dispWidth($prompt));

        $spans = [];
        foreach (mb_str_split($this->input) as $g) {
            $spans[] = [$g, Style::default()];
        }
        // 把光标显示列滚进窗口（+1 是给光标本身留一格）
        $cursorDisp = DisplayWidth::dispWidth(mb_substr($this->input, 0, $this->pos));
        $scrollLeft = max(0, $cursorDisp - $textW + 1);

        return Line::fromSpans(
            Span::styled($prompt, $promptStyle),
            ...SpanClip::clip($spans, $focused ? [$this->pos] : [], $scrollLeft, $textW)
        );
    }

    // ── 事件 ────────────────────────────────────────

    public function onChar(CharKeyEvent $e): bool
    {
        // pty 模式：按键一律由 App 捕获分支转发（捕获态）或走应用导航（非捕获态），
        // 不进入 runner 的命令输入行。
        if ($this->mode === 'pty') {
            return false;
        }
        $ctrl = ($e->modifiers & KeyModifiers::CONTROL) !== 0;
        if ($ctrl && strtolower($e->char) === 'l') {
            $this->clear();
            return true;
        }
        if ($e->char === "\r" || $e->char === "\n") {
            $this->submit();
            return true;
        }
        if ($e->char === "\x7f" || $e->char === "\x08") {
            $this->deleteBackward();
            return true;
        }
        if (KeyInput::isPrintable($e->char) && !$ctrl) {
            $this->input = mb_substr($this->input, 0, $this->pos) . $e->char
                . mb_substr($this->input, $this->pos);
            $this->pos++;
            $this->histIdx = -1;
            return true;
        }
        return false;
    }

    /**
     * 导航/编辑键（终端焦点时由 App 分发过来）。Esc / Tab 不在此处理——
     * 它们是全局键（Esc 还担着退出/中断语义），交由 App 的全局逻辑。
     * 返回 true 表示本面板已消费该键。
     */
    public function onKey(CodedKeyEvent $e, array $areas): bool
    {
        // pty 模式：捕获态由 App 转发按键，不会走到这里；
        // 非捕获态（shell 在跑但焦点在应用导航）时，方向/PageUp/Down/Home/End 翻回退。
        if ($this->mode === 'pty' && !$this->captured) {
            switch ($e->code) {
                case KeyCode::PageUp:
                    $this->scrollPty(-max(1, ($areas['terminal']->height ?? 4) - 3));
                    return true;
                case KeyCode::PageDown:
                    $this->scrollPty(max(1, ($areas['terminal']->height ?? 4) - 3));
                    return true;
                case KeyCode::Up:
                    $this->scrollPty(-1);
                    return true;
                case KeyCode::Down:
                    $this->scrollPty(1);
                    return true;
                case KeyCode::Home:
                    $this->scrollback = $this->emu !== null ? $this->emu->scrollbackSize() : 0;
                    return true;
                case KeyCode::End:
                    $this->scrollback = 0;
                    return true;
                default:
                    return false;
            }
        }
        switch ($e->code) {
            case KeyCode::Enter:
                $this->submit();
                return true;
            case KeyCode::Up:
                $this->historyPrev();
                return true;
            case KeyCode::Down:
                $this->historyNext();
                return true;
            case KeyCode::Left:
                $this->moveCursor(-1);
                return true;
            case KeyCode::Right:
                $this->moveCursor(1);
                return true;
            case KeyCode::Home:
                $this->moveCursorHome();
                return true;
            case KeyCode::End:
                $this->moveCursorEnd();
                return true;
            case KeyCode::PageUp:
                $this->scrollBy(-max(1, $areas['terminal']->height - 3));
                return true;
            case KeyCode::PageDown:
                $this->scrollBy(max(1, $areas['terminal']->height - 3));
                return true;
            case KeyCode::Backspace:
                $this->deleteBackward();
                return true;
            case KeyCode::Delete:
                $this->deleteForward();
                return true;
            default:
                return false;
        }
    }

    public function submit(): void
    {
        $cmd = trim($this->input);
        $this->input = '';
        $this->pos = 0;
        if ($cmd === '') {
            return;
        }
        if (end($this->hist) !== $cmd) {
            $this->hist[] = $cmd;
            if (count($this->hist) > self::MAX_HISTORY) {
                array_shift($this->hist);
            }
        }
        $this->histIdx = -1;

        if ($this->runner->isRunning()) {
            // 必须带 \n：不带会被当成未终止的半行攒在 tail 里，渲染不出来
            $this->buf->append($this->shell->t('term.busy') . "\n", true);
            return;
        }
        $this->buf->append('$ ' . $cmd . "\n", false);   // 回显命令
        $this->ranInRunner = true;
        $this->follow = true;
        $this->scroll = 0;
        if (!$this->runner->start($cmd, $this->cwd)) {
            $this->buf->append($this->shell->t('term.spawn_failed') . "\n", true);
        }
    }

    /** R7：中断正在跑的命令 */
    public function cancel(): void
    {
        if (!$this->runner->isRunning()) {
            return;
        }
        $this->runner->cancel();
        $this->follow = true;
        $this->shell->setMessage($this->shell->t('term.killed'));
    }

    /** 滚屏（滚轮 / PageUp / PageDown）。 */
    public function scrollBy(int $delta): void
    {
        $this->follow = false;
        $this->scroll = max(0, $this->scroll + $delta);
    }

    /** 横向滚动（鼠标横向滚轮 / 触控板）：调整输出区 hScroll，上界由 content() 按最宽行钳制 */
    public function onScrollH(int $delta): void
    {
        $this->hScroll = max(0, $this->hScroll + $delta);
    }

    /** Ctrl+L：清空输出 */
    public function clear(): void
    {
        $this->buf->clear();
        $this->scroll = 0;
        $this->follow = true;
    }

    public function historyPrev(): void
    {
        if ($this->hist === []) {
            return;
        }
        $this->histIdx = $this->histIdx === -1
            ? count($this->hist) - 1
            : max(0, $this->histIdx - 1);
        $this->input = $this->hist[$this->histIdx];
        $this->pos = mb_strlen($this->input);
    }

    public function historyNext(): void
    {
        if ($this->histIdx === -1) {
            return;
        }
        if ($this->histIdx >= count($this->hist) - 1) {
            $this->histIdx = -1;
            $this->input = '';
            $this->pos = 0;
            return;
        }
        $this->histIdx++;
        $this->input = $this->hist[$this->histIdx];
        $this->pos = mb_strlen($this->input);
    }

    /** 左右方向键：移动输入光标（delta 为 -1 / +1） */
    public function moveCursor(int $delta): void
    {
        $target = $this->pos + $delta;
        if ($target < 0 || $target > mb_strlen($this->input)) {
            return;
        }
        $this->pos = $target;
    }

    public function moveCursorHome(): void
    {
        $this->pos = 0;
    }

    public function moveCursorEnd(): void
    {
        $this->pos = mb_strlen($this->input);
    }

    /** 退格：删光标前字符 */
    public function deleteBackward(): void
    {
        if ($this->pos <= 0) {
            return;
        }
        $this->input = mb_substr($this->input, 0, $this->pos - 1)
            . mb_substr($this->input, $this->pos);
        $this->pos--;
        $this->histIdx = -1;
    }

    /** Delete：删光标处字符 */
    public function deleteForward(): void
    {
        if ($this->pos >= mb_strlen($this->input)) {
            return;
        }
        $this->input = mb_substr($this->input, 0, $this->pos)
            . mb_substr($this->input, $this->pos + 1);
    }

    public function clearInput(): void
    {
        $this->input = '';
        $this->pos = 0;
    }

    /**
     * 粘贴文本到终端（按当前模式分派）：
     *  - runner 模式：插到命令输入行光标处；输入行是单行的，粘贴文本里的换行统一规整为空格，
     *    避免把多行塞进单行输入行（也不自动提交命令，避免误执行）。
     *  - pty 捕获态：把文本字节转发给 PTY（\n→\r 适配 Enter 语义），由真实 shell 解释（真粘贴）。
     *  - pty 非捕获态：先进捕获再转发（粘贴即打字）。
     */
    public function pasteText(string $text): void
    {
        if ($text === '') {
            return;
        }
        $this->syncShellState();   // shell 可能刚退出（见 syncShellState）：别把粘贴喂给死 pty
        if ($this->mode === 'pty') {
            // 非捕获态也接受粘贴：粘贴就是打字，与「聚焦后敲第一个字符自动进捕获」同一语义。
            // （改动前非捕获态直接 return，默认 pty 下用户按 Ctrl+V 会毫无反应。）
            // shell 由调用方 App::applyPaste() 先经 ensurePtyStarted() 起好；
            // 若面板还没布局过（pty 仍为 null）则 sendToPty 静默丢弃，不致命。
            $this->enterCapture();
            $bytes = str_replace("\n", "\r", str_replace("\r\n", "\r", $text));
            $this->sendToPty($bytes);
            return;
        }
        // runner 模式：单行输入行，换行规整为空格
        $line = str_replace(["\r\n", "\r", "\n"], ' ', $text);
        $this->input = mb_substr($this->input, 0, $this->pos)
            . $line
            . mb_substr($this->input, $this->pos);
        $this->pos += mb_strlen($line);
        $this->histIdx = -1;
    }

    /**
     * 退出时收尾：停掉还在跑的子进程，避免留下孤儿进程
     */
    public function shutdown(): void
    {
        // 先存盘（此时 emu 仍持有内容、pty 还没杀），再回收 pty 进程
        $this->saveSession();
        $this->runner->shutdown();
        $this->dropPty();
    }

    /**
     * 丢弃当前 pty 实例：**必须先 shutdown() 再置空**。
     *
     * PtyProcess 持有 bash `--rcfile` 用的临时文件（`/tmp/vicetui_rc_*`），只有
     * `PtyProcess::shutdown()` 会删它；直接 `$this->pty = null` 会把文件永久留在 /tmp
     * （实测：shell 每次自行退出——Ctrl+D / `exit`——就漏一个，一次会话一个，
     * 应用退出时也不会被回收）。shutdown() 自身幂等，对已退出的进程调用是安全的。
     */
    private function dropPty(): void
    {
        if ($this->pty === null) {
            return;
        }
        $this->pty->shutdown();
        $this->pty = null;
    }

    /**
     * pty 子进程的**退出兜底回收**：登记一次 PHP 进程退出时的回收钩子。
     *
     * 产品内的正常/异常退出都已覆盖（Lifecycle 的关闭闭包 → `App::shutdownResources()`、
     * `bin/vicecode.php` 的 finally、信号 handler），但**直接 `new App()` 的调用方**没有任何
     * 东西保证会调 shutdown() —— headless 测试就是这种调用方：只要有一个用例把焦点放在终端并
     * 渲染一帧，就会真起一个 shell，进程结束后它被 reparent 到 init 一直活着
     * （实测 `command_palette_unit` 漏一个 bash + 一个 rc 文件，且交互式 bash **扛得住
     *  SIGTERM**，只有 SIGKILL 才收得掉）。`register_shutdown_function` 在 PHP 正常结束与
     * 致命错误时都会跑，正好补上这一段。
     *
     * ⚠️ 只回收进程，**不落盘会话快照**（那要 persistSession 开关 + 用户明确退出才算数，
     * 顺手写文件会给调用方留下意外副作用）。
     */
    private function ensureReapOnExit(): void
    {
        if ($this->reapRegistered) {
            return;
        }
        $this->reapRegistered = true;
        register_shutdown_function(function (): void {
            $this->dropPty();
        });
    }
}

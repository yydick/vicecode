<?php
declare(strict_types=1);

namespace App\Terminal;

use App\App;
use App\Panel\TerminalPanel;
use App\Core\ConfigStore;
use App\Core\KeyInput;
use App\Text\DisplayWidth;
use App\Text\SpanClip;
use App\Terminal\Cell;
use App\Terminal\CommandRunner;
use App\Terminal\PtyColor;
use App\Terminal\PtyProcess;
use App\Terminal\SessionStore;
use App\Terminal\TerminalBuffer;
use App\Terminal\Vt100Emulator;
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
 * 终端的一个「实例」：每个标签是一个真正独立的 shell（或命令运行器），
 * 拥有自己的 pty/emu/runner/buf 全套状态。多标签由 TerminalPanel 容器持有本类的数组。
 *
 * 两种模式共用同一块画布与同一个 TerminalBuffer 语义：
 *   - pty（默认）：真实 shell，用户的别名/函数/提示符都在（`ll` 直接能用）；
 *     聚焦后敲第一个可打印字符/回车自动进捕获（App::handleEvent），F2 切换捕获，
 *     Esc（捕获态）退出捕获。
 *   - runner（回落态）：shell 退出（`exit` / Ctrl+D）后接管，用 `sh -c` 跑单条命令。
 */
final class TerminalInstance
{
    /** 命令在独立子进程跑，主循环每轮 poll() 排空管道，故不阻塞渲染 */
    private CommandRunner $runner;

    private TerminalBuffer $buf;

    /** 当前工作目录（状态栏「目录=」段显示用；public 与 mode/captured 等状态字段口径一致，测试反射注入） */
    public string $cwd;

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
    public string $mode = 'pty';

    /** 捕获态：所有按键转发 PTY（runner 模式恒为 false）。默认**不**捕获。 */
    public bool $captured = false;

    private ?PtyProcess $pty = null;

    private ?Vt100Emulator $emu = null;

    /**
     * 文本选择矩形（归一化 [r0,c0,r1,c1]，视口绝对行列）；null=无选择。
     */
    private ?array $sel = null;

    /** 最近一次 ptyContent 渲染的可见网格（list<list<Cell>>），供 getTextRect 取字 */
    private ?array $lastGrid = null;

    /** 恢复目标 cwd（启动 cwd，非运行时 cd） */
    private ?string $restoreCwd = null;

    /** 恢复快照纯文本 */
    private ?string $restoreText = null;

    /** 恢复快照彩色网格（优先于 restoreText） */
    private ?array $restoreCells = null;

    /** 待恢复标记：首帧 ptyContent() 在真实列宽下起 pty 并灌入 */
    private bool $restorePending = false;

    /** 交互 pty 待启动：默认 pty 模式下一构造就为真；推迟到**聚焦后的首帧** ptyContent() */
    private bool $ptyStartPending = true;

    /** 回退滚动偏移（滚轮 / PageUp/Down 调整） */
    public int $scrollback = 0;

    /** 本次会话是否在 runner 模式下真正跑过命令 */
    private bool $ranInRunner = false;

    /** 上次同步给仿真器的尺寸（避免每帧 resize） */
    private int $lastCols = 0;

    private int $lastRows = 0;

    /** 是否已登记「进程退出时回收 pty」的兜底钩子 */
    private bool $reapRegistered = false;

    private bool $shellReady = false;

    /** shell 就绪前攒下的按键字节（就绪后原样补发） */
    private string $pendingInput = '';

    /** 就绪判据的兜底时限 */
    private float $shellReadyDeadline = 0.0;

    public function __construct(private TerminalPanel $panel)
    {
        $this->runner = new CommandRunner();
        $this->buf = new TerminalBuffer();
        $this->cwd = getcwd() ?: '.';
    }

    /** 反向访问宿主 App（用于 t() / theme / terminalTakeover 等） */
    private function app(): App
    {
        return $this->panel->shell;
    }

    // ── 状态读取（供容器与测试断言）────────────────────

    public function buffer(): TerminalBuffer
    {
        return $this->buf;
    }

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

    public function poll(): bool
    {
        if ($this->mode === 'runner') {
            return $this->pollRunner();
        }
        return $this->pollPty();
    }

    private function pollRunner(): bool
    {
        if (!$this->runner->isRunning()) {
            return false;
        }
        $got = $this->runner->poll(function (string $bytes, bool $isErr): void {
            $this->buf->append($bytes, $isErr);
            $this->app()->emitPluginEvent('terminal.output', ['bytes' => $bytes]);
        });
        if (!$this->runner->isRunning()) {
            $this->buf->flushTail();
            $this->follow = true;
            $got = true;
        }
        return $got;
    }

    private function pollPty(): bool
    {
        if ($this->pty === null) {
            if ($this->ptyStartPending || $this->restorePending) {
                return false;
            }
            $this->mode = 'runner';
            return false;
        }

        $got = false;
        if (!$this->shellReady && microtime(true) >= $this->shellReadyDeadline) {
            $got = $this->markShellReady();
        }
        if ($this->pty->pollExited()) {
            $this->fallbackToRunner();
            return true;
        }
        $bytes = $this->pty->read();
        if ($bytes === '') {
            return $got;
        }
        $this->app()->emitPluginEvent('terminal.output', ['bytes' => $bytes]);
        $this->emu?->write($bytes);
        $cwd = $this->emu?->consumeCwd();
        if ($cwd !== null) {
            $this->cwd = $cwd;
            $this->markShellReady();
        }
        return true;
    }

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

    private function canAcceptInput(): bool
    {
        if (!$this->shellReady && microtime(true) >= $this->shellReadyDeadline) {
            $this->markShellReady();
        }
        return $this->shellReady;
    }

    private function fallbackToRunner(): void
    {
        $this->buf->append($this->app()->t('term.interactive_exit') . "\n", false);
        $this->mode = 'runner';
        $this->captured = false;
        $this->pendingInput = '';
        $this->shellReady = false;
        $this->dropPty();
        $this->emu = null;
    }

    public function syncShellState(): void
    {
        if ($this->mode === 'pty' && $this->pty !== null && $this->pty->pollExited()) {
            $this->fallbackToRunner();
        }
    }

    /** 接管期：PTY 是否仍存活（bin/vicecode.php 据此决定是否自动退出独占） */
    public function ptyAlive(): bool
    {
        return $this->mode === 'pty' && $this->pty !== null && $this->pty->isRunning();
    }

    /**
     * 接管（Takeover）：把仿真器与 PTY 都拉到整帧尺寸（绕过面板子矩形）
     */
    public function resizeToViewport(int $w, int $h): void
    {
        $w = max(1, $w);
        $h = max(1, $h);
        if ($this->emu === null) {
            $this->emu = new Vt100Emulator($w, $h);
        } else {
            $this->emu->resize($w, $h);
        }
        $this->lastCols = $w;
        $this->lastRows = $h;
        if ($this->pty !== null && $this->pty->isRunning()) {
            $this->pty->resize($w, $h);
        }
    }

    /** 退出接管：PTY/仿真器恢复成面板子矩形尺寸（含右缘 gutter） */
    public function resizeToPanel(Area $terminal): void
    {
        [, $outH, , $cw] = $this->panel->ptyViewportGeometry($terminal);
        $this->resizeToViewport($cw, $outH);
    }

    /**
     * 接管期主循环钩子：把 PTY 输出原样透传到真实终端，同时抄一份给仿真器。PTY 死亡则退回 runner。
     */
    public function takeoverPump(): void
    {
        if ($this->pty === null || !$this->pty->isRunning()) {
            if ($this->pty !== null && $this->pty->pollExited()) {
                $this->fallbackToRunner();
            }
            $this->app()->terminalTakeover = false;
            return;
        }
        $bytes = $this->pty->read();
        if ($bytes !== '') {
            $this->emu?->write($bytes);
            fwrite(STDOUT, $bytes);
            fflush(STDOUT);
        }
    }

    // ── 交互式 PTY 控制 ────────────────────────────────

    public function toggleInteractive(): void
    {
        if ($this->mode === 'runner') {
            if ($this->runner->isRunning()) {
                return;
            }
            $this->mode = 'pty';
            $this->captured = true;
            $this->scrollback = 0;
            $this->ptyStartPending = true;
            return;
        }
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

    /** 进入捕获态（自动捕获用：只进不出） */
    public function enterCapture(): void
    {
        if ($this->mode !== 'pty') {
            return;
        }
        $this->captured = true;
        $this->scrollback = 0;
    }

    public function ensurePtyStarted(Area $terminal): void
    {
        if ($this->mode !== 'pty' || $this->pty !== null) {
            return;
        }
        [, $outH, , $cw] = $this->panel->ptyViewportGeometry($terminal);
        if ($cw <= 0 || $outH <= 0) {
            return;
        }
        if ($this->restorePending) {
            $this->restoreSession($cw, $outH);
        } elseif ($this->ptyStartPending) {
            $this->startPty($cw, $outH);
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
            $this->buf->append($this->app()->t('term.spawn_failed') . "\n", true);
            return;
        }
        $this->lastCols = $W;
        $this->lastRows = $outH;
        $this->ptyStartPending = false;
        $this->resetShellReadiness();
    }

    private function resetShellReadiness(): void
    {
        $this->shellReady = false;
        $this->pendingInput = '';
        $isBash = basename((string) (getenv('SHELL') ?: '/bin/bash')) === 'bash';
        $this->shellReadyDeadline = microtime(true) + ($isBash ? 10.0 : 0.3);
    }

    // ── 会话持久化 ────────────────────────────────────

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
     * 启动恢复（由 TerminalPanel 在构造末尾对默认实例调用）。
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
        if (($snap['mode'] ?? 'pty') === 'runner'
            && isset($snap['lines']) && is_array($snap['lines'])) {
            $this->buf->loadLines($snap['lines']);
            $this->cwd = $snap['cwd'];
            $this->mode = 'runner';
            $this->ptyStartPending = false;
            SessionStore::clear();
            return;
        }
        if (isset($snap['cells']) && is_array($snap['cells'])) {
            $this->restoreCells = $snap['cells'];
        } else {
            $this->restoreText = $snap['text'] ?? '';
        }
        $this->restoreCwd = $snap['cwd'];
        $this->restorePending = true;
        $this->ptyStartPending = false;
        $this->mode = 'pty';
        $this->captured = true;
        $this->scrollback = 0;
        SessionStore::clear();
    }

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
        if ($this->restoreCells !== null) {
            $this->emu->importCells($this->restoreCells);
        } else {
            $this->emu->importText($this->restoreText ?? '');
        }
        $this->lastCols = $W;
        $this->lastRows = $outH;
        $this->cwd = $cwd;
        $this->scrollback = 0;
        $this->restorePending = false;
        $this->restoreCwd = null;
        $this->restoreText = null;
        $this->restoreCells = null;
        $this->resetShellReadiness();
    }

    /** 回退滚动（滚轮 / PageUp / PageDown） */
    public function scrollPty(int $delta): void
    {
        $max = $this->emu !== null ? $this->emu->scrollbackSize() : 0;
        $this->scrollback = max(0, min($max, $this->scrollback - $delta));
    }

    /** pty 回退缓冲总行数（无 pty 时 0） */
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

    /** 构造注入 PTY 的环境变量 */
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

    /** 交互式 PTY 模式渲染 */
    private function ptyContent(Area $terminal, bool $focused): Widget
    {
        [$inner, $W, $outH] = $this->viewport($terminal);
        [, , $gutter, $cw] = $this->panel->ptyViewportGeometry($terminal);

        if ($outH <= 0 || $W <= 0) {
            return ParagraphWidget::fromLines(
                Line::fromSpans(Span::styled('', Style::default()))
            );
        }

        if ($this->pty === null && ($this->restorePending || $this->ptyStartPending)) {
            if (!$focused) {
                return $this->pendingContent($W, $outH, $focused);
            }
            if ($this->restorePending) {
                $this->restoreSession($cw, $outH);
            } else {
                $this->startPty($cw, $outH);
            }
            if ($this->mode !== 'pty') {
                return $this->runnerContent($terminal, $focused);
            }
        }

        if ($this->emu === null) {
            $this->emu = new Vt100Emulator($cw, $outH);
            $this->lastCols = $cw;
            $this->lastRows = $outH;
        } elseif ($this->lastCols !== $cw || $this->lastRows !== $outH) {
            $this->emu->resize($cw, $outH);
            $this->lastCols = $cw;
            $this->lastRows = $outH;
            if ($this->pty !== null && $this->pty->isRunning()) {
                $this->pty->resize($cw, $outH);
            }
        }

        $grid = $this->emu->gridForRender($outH, $this->scrollback);
        $cursor = $grid['cursor'];
        $this->lastGrid = $grid['lines'];

        $sbTotal = $this->emu->scrollbackSize();
        $total = $sbTotal + $outH;
        $thumbTop = -1;
        $thumbBottom = -1;
        if ($gutter && $total > $outH) {
            $viewStart = max(0, $total - $outH - max(0, $this->scrollback));
            $thumbTop = (int) round($viewStart / $total * $outH);
            $thumbBottom = (int) round(($viewStart + $outH) / $total * $outH) - 1;
            if ($thumbBottom < $thumbTop) {
                $thumbBottom = $thumbTop;
            }
            $thumbTop = max(0, min($outH - 1, $thumbTop));
            $thumbBottom = max(0, min($outH - 1, $thumbBottom));
        }
        $trackStyle = $this->app()->theme->style('border');
        $thumbStyle = $this->app()->theme->style('borderFocus');

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
                // ⚠️ sel 是屏幕绝对行列，比对基准必须是**渲染实际原点**：
                // content() 在 build 期以「面板外框」求值（splitInner 不做 inner），而渲染期
                // Block/Grid 会把内容重排进内区——viewport() 的 inner（margin 后）才是
                // ParagraphWidget 实际落笔的原点。用它，高亮才与文本同行同列。
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
            if ($gutter) {
                $inThumb = $rIdx >= $thumbTop && $rIdx <= $thumbBottom;
                $spans[] = Span::styled($inThumb ? '█' : ' ', $inThumb ? $thumbStyle : $trackStyle);
            }
            $lines[] = Line::fromSpans(...$spans);
        }
        $lines[] = $this->ptyHintLine($cw, $focused, $gutter, $trackStyle);

        return ParagraphWidget::fromLines(...$lines);
    }

    /** 渲染前注入文本选择矩形（归一化 [r0,c0,r1,c1]）；null 清掉高亮 */
    public function setSelection(?array $r): void
    {
        $this->sel = $r;
    }

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
            $b = min($s1, $c1 + 1);
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

    public function getTextRect(Area $terminal, int $r0, int $c0, int $r1, int $c1): string
    {
        // ⚠️ $terminal = midArea（面板切段后的实例内容区）。渲染时 ParagraphWidget 把
        // 内容**画满 midArea 全域** —— inner(Margin) 只用于推导 W/outH 尺寸、不产生位移，
        // 故行列基准必须是 midArea **原点**；用 margin 后的坐标会整体偏 1 行 1 列
        //（显示看着选中了、复制出来是别处 —— BUGFIXES E5）。
        $inner = $terminal->inner(new Margin(1, 1));   // 仅尺寸口径（与渲染一致）
        $W = max(0, $inner->width);
        $H = max(0, $inner->height);
        $fx = $terminal->position->x;
        $fy = $terminal->position->y;

        if ($this->mode === 'pty') {
            return $this->ptyTextRect($fy, $fx, $r0, $c0, $r1, $c1);
        }

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
            $v = $row - $fy;
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
            $dispA = max(0, $c0 - $fx + $this->hScroll);
            $dispB = max(0, $c1 - $fx + $this->hScroll);
            $chA = DisplayWidth::mbDispToCharIndex($text, $dispA);
            $chB = DisplayWidth::mbDispToCharIndex($text, $dispB + 1);
            $out[] = mb_substr($text, $chA, $chB - $chA);
        }
        return implode("\n", $out);
    }

    private function ptyTextRect(int $fy, int $fx, int $r0, int $c0, int $r1, int $c1): string
    {
        if ($this->lastGrid === null) {
            return '';
        }
        $out = [];
        for ($row = $r0; $row <= $r1; $row++) {
            $v = $row - $fy;
            if ($v < 0 || $v >= count($this->lastGrid)) {
                $out[] = '';
                continue;
            }
            $cells = $this->lastGrid[$v];
            $line = '';
            for ($col = $c0; $col <= $c1; $col++) {
                $vc = $col - $fx;
                if ($vc < 0) {
                    continue;   // 还没进网格（选区含面板边框列）：跳过，别把整行 break 成空
                }
                if ($vc >= count($cells)) {
                    break;
                }
                $cell = $cells[$vc];
                if ($cell->wide) {
                    continue;
                }
                $line .= $cell->ch === '' ? ' ' : $cell->ch;
            }
            $out[] = $line;
        }
        return implode("\n", $out);
    }

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
            $fk = (string) $cell->fg;
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

    private function pendingContent(int $W, int $outH, bool $focused): Widget
    {
        $gutter = $W >= 3;
        $cw = $gutter ? $W - 1 : $W;
        $trackStyle = $this->app()->theme->style('border');
        $lines = [Line::fromSpans(Span::styled(
            $this->app()->t('term.focus_to_start'),
            $this->app()->theme->style('termHint')
        ))];
        while (count($lines) < $outH) {
            $lines[] = Line::fromSpans(Span::styled('', Style::default()));
        }
        $lines[] = $this->ptyHintLine($cw, $focused, $gutter, $trackStyle);
        return ParagraphWidget::fromLines(...$lines);
    }

    private function ptyHintLine(int $cw, bool $focused, bool $gutter, Style $trackStyle): Line
    {
        $hint = $this->captured
            ? $this->app()->t('term.interactive_hint') . ' · F5'
            : $this->app()->t('term.interactive_enter');
        $style = $focused
            ? $this->app()->theme->style('borderFocus')
            : $this->app()->theme->style('border');
        $w = DisplayWidth::dispWidth($hint);
        if ($w < $cw) {
            $hint .= str_repeat(' ', $cw - $w);
        } elseif ($w > $cw) {
            $hint = DisplayWidth::mbSubDisp($hint, 0, $cw);
        }
        $spans = [Span::styled($hint, $style)];
        if ($gutter) {
            $spans[] = Span::styled(' ', $trackStyle);
        }
        return Line::fromSpans(...$spans);
    }

    private function runnerContent(Area $terminal, bool $focused): Widget
    {
        $inner = $terminal->inner(new Margin(1, 1));
        $W = max(0, $inner->width);
        $H = max(0, $inner->height);
        $outH = max(0, $H - 1);

        $rows = $this->buf->all();
        $status = $this->statusRow();
        if ($status !== null) {
            $rows[] = $status;
        }
        if ($rows === []) {
            $rows[] = ['text' => $this->app()->t('term.empty'), 'err' => false, 'kind' => 'hint'];
        }

        $maxOff = max(0, count($rows) - $outH);
        $off = $this->follow ? $maxOff : min(max(0, $this->scroll), $maxOff);
        $this->scroll = $off;

        $maxW = 0;
        foreach (array_slice($rows, $off, $outH) as $r) {
            $w = DisplayWidth::dispWidth($r['text'] ?? '');
            if ($w > $maxW) {
                $maxW = $w;
            }
        }
        $this->hScroll = max(0, min($this->hScroll, max(0, $maxW - $W)));

        $lines = [];
        foreach (array_slice($rows, $off, $outH) as $v => $row) {
            $kind = $row['kind'] ?? ($row['err'] ? 'err' : 'out');
            $style = match ($kind) {
                'err' => $this->app()->theme->style('termErr'),
                'killed' => $this->app()->theme->style('termKilled'),
                'hint' => $this->app()->theme->style('termHint'),
                default => Style::default(),
            };
            $disp = DisplayWidth::mbSubDisp($row['text'], $this->hScroll, $W);
            $span = Span::styled($disp, $style);
            // ⚠️ sel 基准与 ptyContent 同口径：viewport() 的 inner 原点 = 渲染实际落笔位置
            $absRow = $inner->position->y + $v;
            if ($this->sel !== null
                && $absRow >= $this->sel[0] && $absRow <= $this->sel[2]) {
                $inv = $this->invertSpans([$span], $inner->position->x, $this->sel[1], $this->sel[3]);
                $span = $inv[0] ?? $span;
            }
            $lines[] = Line::fromSpans($span);
        }
        while (count($lines) < $outH) {
            $lines[] = Line::fromSpans(Span::styled('', Style::default()));
        }
        $lines[] = $this->inputLine($W, $focused);

        return ParagraphWidget::fromLines(...$lines);
    }

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
            return ['text' => $this->app()->t('term.killed'), 'err' => false, 'kind' => 'killed'];
        }
        if ($code !== 0) {
            return ['text' => $this->app()->t('term.exit', ['code' => (string) $code]), 'err' => false, 'kind' => 'err'];
        }
        return null;
    }

    private function inputLine(int $W, bool $focused): Line
    {
        $running = $this->runner->isRunning();
        $prompt = $running ? '● ' : '$ ';
        $promptStyle = $running
            ? $this->app()->theme->style('termPromptIdle')
            : $this->app()->theme->style('termPromptBusy');
        $textW = max(0, $W - DisplayWidth::dispWidth($prompt));

        $spans = [];
        foreach (mb_str_split($this->input) as $g) {
            $spans[] = [$g, Style::default()];
        }
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

    public function onKey(CodedKeyEvent $e, array $areas): bool
    {
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
            $this->buf->append($this->app()->t('term.busy') . "\n", true);
            return;
        }
        $this->buf->append('$ ' . $cmd . "\n", false);
        $this->ranInRunner = true;
        $this->follow = true;
        $this->scroll = 0;
        if (!$this->runner->start($cmd, $this->cwd)) {
            $this->buf->append($this->app()->t('term.spawn_failed') . "\n", true);
        }
    }

    public function cancel(): void
    {
        if (!$this->runner->isRunning()) {
            return;
        }
        $this->runner->cancel();
        $this->follow = true;
        $this->app()->setMessage($this->app()->t('term.killed'));
    }

    public function scrollBy(int $delta): void
    {
        $this->follow = false;
        $this->scroll = max(0, $this->scroll + $delta);
    }

    public function onScrollH(int $delta): void
    {
        $this->hScroll = max(0, $this->hScroll + $delta);
    }

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

    public function pasteText(string $text): void
    {
        if ($text === '') {
            return;
        }
        $this->syncShellState();
        if ($this->mode === 'pty') {
            $this->enterCapture();
            $bytes = str_replace("\n", "\r", str_replace("\r\n", "\r", $text));
            $this->sendToPty($bytes);
            return;
        }
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
        $this->saveSession();
        $this->runner->shutdown();
        $this->dropPty();
    }

    /** 容器关闭/回收非活动实例：只回收进程与临时文件，不覆盖会话快照 */
    public function disposeWithoutSave(): void
    {
        $this->runner->shutdown();
        $this->dropPty();
    }

    private function dropPty(): void
    {
        if ($this->pty === null) {
            return;
        }
        $this->pty->shutdown();
        $this->pty = null;
    }

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

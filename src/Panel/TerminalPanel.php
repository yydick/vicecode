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
 * 终端面板（M2 命令运行器）：输出视口 + 命令输入行 + 命令历史。
 *
 * 命令在独立子进程里跑，主循环每轮调 poll() 排空管道，故不阻塞渲染。
 *
 * 面板契约（本项目无 interface，靠签名约定保持一致）：
 *   content(Area, bool $focused): Widget —— 生成面板内容 Widget（外层 Block 由 App 加）；
 *   onChar() / onKey() / onClick() / onScroll() 返回 bool 表示是否已消费事件；
 *   状态归面板自己，App 只负责把事件分发给当前聚焦面板。
 *
 * ⚠️ content() 不是纯函数：它会把钳制后的滚动偏移回写到 $scroll
 * （视口贴住末尾时同步最新偏移，这样下次手动滚动才从正确的位置起算）。
 * 这是既有渲染管线约定，保持原样搬移，不要在重构时「提纯」。
 *
 * M7 若要做真正的交互式 PTY 终端，替换 CommandRunner 即可，这里的视口/输入行逻辑仍可复用。
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

    // ── 交互式 PTY 模式（F2 进入/退出，与命令运行器共存）──
    /** 'runner' = 命令运行器；'pty' = 真实交互式 shell */
    public string $mode = 'runner';

    /** 捕获态：所有按键转发 PTY（runner 模式恒为 false） */
    public bool $captured = false;

    private ?PtyProcess $pty = null;

    private ?Vt100Emulator $emu = null;

    // ── 会话持久化恢复（App 构造末尾 maybeRestore() 灌入，首帧真实尺寸下 spawn）──
    /** 恢复目标 cwd（启动 cwd，非运行时 cd） */
    private ?string $restoreCwd = null;

    /** 恢复快照纯文本 */
    private ?string $restoreText = null;

    /** 待恢复标记：首帧 ptyContent() 在真实列宽下起 pty 并灌入 */
    private bool $restorePending = false;

    /** 回退滚动偏移（滚轮 / PageUp/Down 调整） */
    public int $scrollback = 0;

    /** 上次同步给仿真器的尺寸（避免每帧 resize） */
    private int $lastCols = 0;

    private int $lastRows = 0;

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
            $this->mode = 'runner';
            return false;
        }
        if ($this->pty->pollExited()) {
            $this->buf->append($this->shell->t('term.interactive_exit') . "\n", false);
            $this->mode = 'runner';
            $this->captured = false;
            $this->pty = null;
            $this->emu = null;
            return true;
        }
        $bytes = $this->pty->read();
        if ($bytes === '') {
            return false;
        }
        $this->emu?->write($bytes);
        return true;
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
            $this->emu = new Vt100Emulator(80, 24);
            $this->pty = new PtyProcess();
            $env = $this->buildPtyEnv();
            if (!$this->pty->start(
                (string) (getenv('SHELL') ?: '/bin/bash'),
                80,
                24,
                $this->cwd,
                $env
            )) {
                $this->emu = null;
                $this->pty = null;
                $this->buf->append($this->shell->t('term.spawn_failed') . "\n", true);
                return;
            }
            $this->mode = 'pty';
            $this->captured = true;
            $this->scrollback = 0;
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
        if ($this->pty !== null && $this->pty->isRunning()) {
            $this->pty->write($bytes);
        }
    }

    /** 退出捕获态（shell 仍在跑，焦点回到应用导航） */
    public function exitCapture(): void
    {
        $this->captured = false;
    }

    /** 是否处于捕获态（App 据此拦截按键） */
    public function isCaptured(): bool
    {
        return $this->mode === 'pty' && $this->captured;
    }

    // ── 会话持久化 ────────────────────────────────────

    /**
     * 退出时存盘（在 shutdown() 最开头调用，此时 emu 仍持有内容、早于杀进程）。
     * 仅当开启持久化且处于 pty 模式且仿真器就绪时落盘主屏+滚动历史纯文本。
     * 落盘失败静默（不致命）。
     */
    public function saveSession(): void
    {
        if (!ConfigStore::persistSession()) {
            return;
        }
        if ($this->mode !== 'pty' || $this->emu === null) {
            return;
        }
        SessionStore::save([
            'cwd' => $this->cwd,
            'text' => $this->emu->exportText(),
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
        $this->restoreCwd = $snap['cwd'];
        $this->restoreText = $snap['text'];
        $this->restorePending = true;
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
        $text = $this->restoreText ?? '';
        $this->emu = new Vt100Emulator($W, $outH);
        $this->pty = new PtyProcess();
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
            $this->pty = null;
            $this->mode = 'runner';
            $this->captured = false;
            $this->restorePending = false;
            $this->restoreCwd = null;
            $this->restoreText = null;
            return;
        }
        $this->emu->importText($text);
        $this->lastCols = $W;
        $this->lastRows = $outH;
        $this->cwd = $cwd; // 仅恢复启动 cwd（v1 局限：运行时 cd 不恢复）
        $this->scrollback = 0;
        $this->restorePending = false;
        $this->restoreCwd = null;
        $this->restoreText = null;
    }

    /** 回退滚动（滚轮 / PageUp / PageDown） */
    public function scrollPty(int $delta): void
    {
        $max = $this->emu !== null ? $this->emu->scrollbackSize() : 0;
        $this->scrollback = max(0, min($max, $this->scrollback + $delta));
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
        $inner = $terminal->inner(new Margin(1, 1));
        $W = max(0, $inner->width);
        $H = max(0, $inner->height);
        $outH = max(0, $H - 1);   // 末行留给提示行

        if ($outH <= 0 || $W <= 0) {
            return ParagraphWidget::fromLines(
                Line::fromSpans(Span::styled('', Style::default()))
            );
        }

        // 首帧恢复：在真实列宽下起 pty 并灌入快照（规避屏宽未定导致的折行错位）。
        // 恢复失败已回退 runner，本帧改走 runner 渲染。
        if ($this->restorePending && $this->pty === null) {
            $this->restoreSession($W, $outH);
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
                [$st, $key] = $this->styleAndKey($cell, $isCursor);
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

    /** 底部提示行：捕获态提示按 F2 退出；非捕获态提示按 F2 进入交互 */
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
        foreach (array_slice($rows, $off, $outH) as $row) {
            $kind = $row['kind'] ?? ($row['err'] ? 'err' : 'out');
            $style = match ($kind) {
                'err' => $this->shell->theme->style('termErr'),
                'killed' => $this->shell->theme->style('termKilled'),
                'hint' => $this->shell->theme->style('termHint'),
                default => Style::default(),
            };
            $lines[] = Line::fromSpans(Span::styled(DisplayWidth::mbSubDisp($row['text'], $this->hScroll, $W), $style));
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
            ...SpanClip::clip($spans, $focused, $this->pos, $scrollLeft, $textW)
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
                    $this->scrollback = 0;
                    return true;
                case KeyCode::End:
                    $this->scrollback = $this->emu !== null ? $this->emu->scrollbackSize() : 0;
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

    /** 退出时收尾：停掉还在跑的子进程，避免留下孤儿进程 */
    public function shutdown(): void
    {
        // 先存盘（此时 emu 仍持有内容、pty 还没杀），再回收 pty 进程
        $this->saveSession();
        $this->runner->shutdown();
        if ($this->pty !== null) {
            $this->pty->shutdown();
            $this->pty = null;
        }
    }
}

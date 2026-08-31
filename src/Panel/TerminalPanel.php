<?php
declare(strict_types=1);

namespace App\Panel;

use App\Core\KeyInput;
use App\App;
use App\Terminal\CommandRunner;
use App\Terminal\TerminalBuffer;
use App\Text\DisplayWidth;
use App\Text\SpanClip;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\KeyModifiers;
use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
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
        return $this->runner->isRunning();
    }

    // ── 主循环钩子 ────────────────────────────────────

    /** 主循环每轮调用：排空命令管道。返回是否有新内容（供主循环决定是否重绘）。 */
    public function poll(): bool
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

    // ── 渲染 ──────────────────────────────────────────

    /** 面板内容：末行固定为命令输入行，其上是输出视口 */
    public function content(Area $terminal, bool $focused): Widget
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
                'err' => Style::default()->fg(AnsiColor::Red),
                'killed' => Style::default()->fg(AnsiColor::Yellow),
                'hint' => Style::default()->fg(AnsiColor::DarkGray),
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
            ? Style::default()->fg(AnsiColor::Green)
            : Style::default()->fg(AnsiColor::Cyan);
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
        $this->runner->shutdown();
    }
}

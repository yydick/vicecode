<?php
declare(strict_types=1);

namespace App\Panel;

use App\App;
use App\Editor\Buffer;
use App\Editor\Highlighter;
use App\Text\DisplayWidth;
use App\Text\SpanClip;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\KeyModifiers;
use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Position\Position;
use PhpTui\Tui\Style\Modifier;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Widget\Margin;
use PhpTui\Tui\Widget\Widget;

/**
 * 编辑器面板：行号 + 语法高亮 + 光标 + 多 Buffer 标签栏。
 *
 * 多 Buffer 的 $buffers 归本面板持有；当前 Buffer（$shell->buffer）留在 App 是因为
 * 状态栏与未保存确认都要读它，且测试会直接赋值。
 *
 * 面板契约（本项目无 interface，靠签名约定保持一致）：
 *   content(Area, bool $focused): Widget —— 生成面板内容 Widget（外层 Block 由 App 加）；
 *   onClick() / onChar() 返回 bool 表示是否已消费事件。
 *
 * ⚠️ content() 不是纯函数，会修改状态——这是既有渲染管线约定，原样搬移，未「提纯」：
 *   1. 修正 buffer 的 scrollTop / scrollLeft（保证光标可见）；
 *   2. 按修订号刷新高亮缓存 hlLines / hlRev（整文件高亮，scrivo 需完整上下文）；
 *   3. 重建标签栏命中矩形 $tabRects，供 onClick() 使用。
 */
final class EditorPanel
{
    /** @var array<string,Buffer> */
    private array $buffers = [];

    /** 标签栏命中矩形：x0 => [x0, x1, path]，渲染时重建 */
    private array $tabRects = [];

    public function __construct(private App $shell)
    {
    }

    // ── 状态访问（供 App / Lifecycle 读）────────────────

    /** @return array<string,Buffer> */
    public function buffers(): array
    {
        return $this->buffers;
    }

    public function removeBuffer(string $path): void
    {
        unset($this->buffers[$path]);
    }

    public function hasTabs(): bool
    {
        return count($this->buffers) > 1;
    }

    /** 指定路径的文件是否仍被打开（用于未保存确认等断言，避免外部直接访问 $buffers）。 */
    public function hasBuffer(string $path): bool
    {
        return isset($this->buffers[$path]);
    }

    // ── 渲染 ──────────────────────────────────────────

    public function content(Area $editor, bool $focused): Widget
    {
        // 命中矩形每帧重建，故先无条件清空。原先只在 hasTabs() 时清，
        // buffers 降到 1 个后旧矩形会残留——虽然 onClick() 同样判 hasTabs() 而不会误命中，
        // 但那属于脏状态，也与本类 docblock 声明的「每帧重建」不符。
        $this->tabRects = [];

        $inner = $editor->inner(new Margin(1, 1));
        $W = max(0, $inner->width);
        $H = max(0, $inner->height);
        $buf = $this->shell->buffer;

        if ($buf === null) {
            return ParagraphWidget::fromString($this->shell->t('editor.no_file'));
        }
        if ($buf->noticeKey !== null) {
            return ParagraphWidget::fromString($this->shell->t($buf->noticeKey, $buf->noticeParams));
        }

        $gutterW = min($W, $buf->maxLineNoWidth + 1);
        $textW = max(0, $W - $gutterW);
        $hasTabs = $this->hasTabs();
        $tabH = $hasTabs ? 1 : 0;
        $visibleRows = max(0, $H - $tabH);

        // 垂直滚动：保证光标行可见
        if ($buf->cursorRow < $buf->scrollTop) {
            $buf->scrollTop = $buf->cursorRow;
        }
        if ($buf->cursorRow >= $buf->scrollTop + $visibleRows) {
            $buf->scrollTop = $buf->cursorRow - $visibleRows + 1;
        }
        if ($buf->scrollTop < 0) {
            $buf->scrollTop = 0;
        }
        // 水平滚动：保证光标列可见
        if ($buf->cursorCol < $buf->scrollLeft) {
            $buf->scrollLeft = $buf->cursorCol;
        }
        if ($buf->cursorCol >= $buf->scrollLeft + $textW) {
            $buf->scrollLeft = $buf->cursorCol - $textW + 1;
        }
        if ($buf->scrollLeft < 0) {
            $buf->scrollLeft = 0;
        }

        // 高亮缓存（按 Buffer 修订号；整文件高亮一次，scrivo 需完整上下文）
        $lang = Highlighter::langFor((string) $buf->path);
        if ($buf->hlRev !== $buf->rev) {
            $buf->hlLines = Highlighter::highlightLines($buf->lines, $lang);
            $buf->hlRev = $buf->rev;
        }
        $hl = $buf->hlLines;

        $lines = [];
        if ($hasTabs) {
            $lines[] = $this->tabLine($editor, $W);
        }
        $total = count($buf->lines);
        for ($i = 0; $i < $visibleRows; $i++) {
            $li = $buf->scrollTop + $i;
            $lineNo = (string) ($li + 1);
            $gutter = DisplayWidth::mbPad($lineNo, $gutterW - 1) . ' ';
            $gutterStyle = $li === $buf->cursorRow
                ? Style::default()->fg(AnsiColor::Yellow)
                : Style::default()->fg(AnsiColor::DarkGray);

            if ($li >= $total) {
                // 缓冲区之后：暗色 ~ 占位
                $lines[] = Line::fromSpans(
                    Span::styled($gutter, $gutterStyle),
                    Span::styled('~', Style::default()->fg(AnsiColor::DarkGray)),
                );
                continue;
            }

            if ($hl !== null && isset($hl[$li]) && $hl[$li] !== null) {
                $lineSpans = $hl[$li];
            } else {
                $lineSpans = [[$buf->lines[$li], Style::default()]];
            }
            $contentSpans = SpanClip::clip(
                $lineSpans,
                $li === $buf->cursorRow && $focused,
                $buf->cursorCol,
                $buf->scrollLeft,
                $textW
            );
            $lines[] = Line::fromSpans(
                Span::styled($gutter, $gutterStyle),
                ...$contentSpans
            );
        }
        return ParagraphWidget::fromLines(...$lines);
    }

    /** 顶部标签栏（一行）：已开文件 + dirty 标记，当前 REVERSED；同时记录命中矩形供点击。 */
    private function tabLine(Area $editor, int $W): Line
    {
        $absX0 = $editor->position->x + 1;
        $maxX = $absX0 + $W;
        $this->tabRects = [];
        $spans = [];
        $cx = $absX0;
        foreach ($this->buffers as $path => $b) {
            $name = basename($path);
            $mark = $b->dirty ? $this->shell->t('status.dirty') : '';
            $seg = ' ' . $name . $mark . ' ';
            $w = DisplayWidth::dispWidth($seg);
            if ($cx + $w > $maxX) {
                break;
            }
            $st = ($b === $this->shell->buffer)
                ? Style::default()->addModifier(Modifier::REVERSED)
                : Style::default()->fg(AnsiColor::Gray);
            $spans[] = Span::styled($seg, $st);
            $this->tabRects[$cx] = [$cx, $cx + $w - 1, $path];
            $cx += $w;
        }
        return Line::fromSpans(...$spans);
    }

    // ── 事件 ────────────────────────────────────────

    /** 点击编辑器：先判标签栏命中，否则按坐标定位光标（R6）。 */
    public function onClick(Position $pos, array $areas): bool
    {
        $editor = $areas['editor'];

        // 点 tab 栏（编辑器内第 1 行）→ 切换 buffer
        if ($this->hasTabs() && $pos->y === $editor->position->y + 1) {
            foreach ($this->tabRects as [$x0, $x1, $path]) {
                if ($pos->x >= $x0 && $pos->x <= $x1) {
                    $this->switchBuffer($path);
                    $this->shell->focus('editor');
                    return true;
                }
            }
            $this->shell->focus('editor');
            return true;
        }

        $this->positionCursorAtClick($pos, $editor);
        $this->shell->focus('editor');
        return true;
    }

    /**
     * 编辑器焦点下的功能键：光标移动 / 翻页 / 删除，以及 Ctrl+Tab 切 buffer。
     * 返回 false 表示本面板不处理该键，调用方（App）继续走全局键
     * ——例如普通 Tab（切焦点）与 Esc（退出）都不归编辑器管。
     *
     * @param array<string,Area> $areas
     */
    public function onKey(CodedKeyEvent $e, array $areas): bool
    {
        // Ctrl+Tab 切 buffer 不需要 buffer 已打开，故放在 null 判断之前
        if ($e->code === KeyCode::Tab && ($e->modifiers & KeyModifiers::CONTROL)) {
            $this->cycleBuffer();
            return true;
        }

        $buf = $this->shell->buffer;
        if ($buf === null) {
            return false;
        }
        $page = max(1, $areas['editor']->height - 4);

        switch ($e->code) {
            case KeyCode::Up:
                $buf->moveUp();
                return true;
            case KeyCode::Down:
                $buf->moveDown();
                return true;
            case KeyCode::Left:
                $buf->moveLeft();
                return true;
            case KeyCode::Right:
                $buf->moveRight();
                return true;
            case KeyCode::Home:
                $buf->moveHome();
                return true;
            case KeyCode::End:
                $buf->moveEnd();
                return true;
            case KeyCode::PageUp:
                $buf->pageUp($page);
                return true;
            case KeyCode::PageDown:
                $buf->pageDown($page);
                return true;
            case KeyCode::Backspace:
                $buf->backspace();
                return true;
            case KeyCode::Delete:
                $buf->delete();
                return true;
            default:
                return false;
        }
    }

    public function onChar(CharKeyEvent $e): bool
    {
        $buf = $this->shell->buffer;
        if ($buf === null) {
            return false;
        }
        // Ctrl+W 关闭当前 buffer（dirty 时弹确认）
        if (($e->modifiers & KeyModifiers::CONTROL) && strtolower($e->char) === 'w') {
            $this->shell->requestClose((string) $buf->path);
            return true;
        }
        // Ctrl+S 保存
        if (($e->modifiers & KeyModifiers::CONTROL) && (strtolower($e->char) === 's' || $e->char === "\x13")) {
            $this->save();
            return true;
        }
        if ($e->char === "\r" || $e->char === "\n") {
            $buf->insertNewline();
            return true;
        }
        if ($e->char === "\x7f" || $e->char === "\x08") {
            $buf->backspace();
            return true;
        }
        if (strlen($e->char) === 1 && ord($e->char) >= 32 && !($e->modifiers & KeyModifiers::CONTROL)) {
            $buf->insertChar($e->char);
            return true;
        }
        return false;
    }

    // ── Buffer 操作 ──────────────────────────────────

    public function openFile(string $path): void
    {
        if (is_dir($path)) {
            return;
        }
        if (!isset($this->buffers[$path])) {
            $this->buffers[$path] = Buffer::fromFile($path);
        }
        $this->shell->buffer = $this->buffers[$path];
        $this->shell->focus('editor');
    }

    public function switchBuffer(string $path): void
    {
        if (isset($this->buffers[$path])) {
            $this->shell->buffer = $this->buffers[$path];
        }
    }

    /** 打开虚拟文档（如 git diff），以字符串内容建只读 Buffer 并切焦点到编辑器 */
    public function openVirtual(string $path, string $content, bool $readOnly = true): void
    {
        if (!isset($this->buffers[$path])) {
            $this->buffers[$path] = Buffer::fromString($path, $content, $readOnly);
        } else {
            // 已有则更新内容（diff 可能变化），保持只读
            $b = $this->buffers[$path];
            $c = str_replace("\r\n", "\n", $content);
            $c = rtrim($c, "\n");
            $b->lines = $c === '' ? [''] : explode("\n", $c);
            $b->readOnly = $readOnly;
            $b->recompute();
        }
        $this->shell->buffer = $this->buffers[$path];
        $this->shell->focus('editor');
    }

    /** Ctrl+Tab：在已开文件间环形切换 */
    public function cycleBuffer(): void
    {
        $paths = array_keys($this->buffers);
        if (count($paths) < 2 || $this->shell->buffer === null) {
            return;
        }
        $idx = array_search($this->shell->buffer->path, $paths, true);
        if ($idx === false) {
            return;
        }
        $next = $paths[($idx + 1) % count($paths)];
        $this->shell->buffer = $this->buffers[$next];
    }

    /** Ctrl+S 保存，结果写进状态栏消息 */
    public function save(): void
    {
        $buf = $this->shell->buffer;
        if ($buf === null) {
            return;
        }
        if ($buf->readOnly) {
            $this->shell->setMessage($this->shell->t('editor.readonly'));
            return;
        }
        $path = $buf->path;
        if ($path === null || (!is_writable($path) && !is_writable(dirname($path)))) {
            $this->shell->setMessage($this->shell->t('editor.save_failed', ['msg' => '权限不足或路径不可写']));
            return;
        }
        $ok = $buf->save();
        $this->shell->setMessage($ok
            ? $this->shell->t('editor.saved')
            : $this->shell->t('editor.save_failed', ['msg' => (error_get_last()['message'] ?? 'unknown')]));
    }

    /** 点击编辑器内某格 → 映射回 Buffer 的 (row,col) 并定位光标（R6 鼠标精细交互） */
    private function positionCursorAtClick(Position $pos, Area $editor): void
    {
        $buf = $this->shell->buffer;
        if ($buf === null || $buf->readOnly) {
            return;
        }
        $inner = $editor->inner(new Margin(1, 1));
        $W = max(0, $inner->width);
        $gutterW = min($W, $buf->maxLineNoWidth + 1);

        $row = ($pos->y - $inner->position->y - ($this->hasTabs() ? 1 : 0)) + $buf->scrollTop;
        $total = count($buf->lines);
        if ($row < 0 || $row >= $total) {
            // 点在可视行之外：夹到最近的有效行
            $row = max(0, min($total - 1, $row));
        }
        $buf->cursorRow = $row;

        $col = ($pos->x - $inner->position->x - $gutterW) + $buf->scrollLeft;
        if ($col < 0) {
            $col = 0;
        }
        $lineLen = mb_strlen($buf->lines[$row]);
        if ($col > $lineLen) {
            $col = $lineLen;
        }
        $buf->cursorCol = $col;
    }
}

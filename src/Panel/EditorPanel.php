<?php
declare(strict_types=1);

namespace App\Panel;

use App\Core\KeyInput;
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

    /** 最近一次渲染的编辑器文本宽度（显示列），供 onScrollH/onChar 做范围钳制 */
    private int $lastTextW = 0;

    /**
     * 横滚是否被「滚轮/触控板」钉住：true 时 content() 只做范围钳制、不把 scrollLeft
     * 拉回光标（否则滚轮右滚会被每帧的「保证光标可见」立刻清零，横滚看不到效果）。
     * 一旦发生光标移动/点击/输入/打开文件，复位为 false 恢复「光标跟随」。
     */
    private bool $scrollPinned = false;

    /**
     * 横向滚动边界指示：左侧（scrollLeft>0）是否还有隐藏内容、右侧是否还有。
     * 每帧 content() 重新计算，供 App 在编辑器标题栏渲染 ‹/› 提示。
     */
    public bool $hLeft = false;
    public bool $hRight = false;

    /**
     * 文本选择矩形（归一化 [r0,c0,r1,c1]，视口绝对行列）；null=无选择。
     * 由 App 在渲染前注入，content() 据此反显高亮。坐标含边框/行号列——
     * 反显时只作用于文本区（排除行号栏），取字时按同款数学映射回 Buffer 行/字符。
     */
    private ?array $sel = null;

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
        $this->hLeft = false;
        $this->hRight = false;

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
        // 水平滚动：
        //  - 滚轮横滚（scrollPinned=true）时只做范围钳制，不把 scrollLeft 拉回光标（横滚才看得到效果）；
        //  - 否则跟随光标（显示列单位，双向），保证直接设置/移动的光标始终可见。
        // 两者都不再把字符索引 cursorCol 与显示列 scrollLeft/textW 混用（CJK 下比例不同）。
        $this->lastTextW = $textW;
        $lineCur = $buf->lines[$buf->cursorRow] ?? '';
        if ($this->scrollPinned) {
            $buf->scrollLeft = max(0, min($buf->scrollLeft, max(0, DisplayWidth::dispWidth($lineCur) - $textW)));
            if ($buf->scrollLeft < 0) {
                $buf->scrollLeft = 0;
            }
        } else {
            $this->followBoth($buf, $textW);
        }

        // 高亮缓存（按 Buffer 修订号；整文件高亮一次，scrivo 需完整上下文）
        $lang = Highlighter::langFor((string) $buf->path);
        if ($buf->hlRev !== $buf->rev) {
            $buf->hlLines = Highlighter::highlightLines($buf->lines, $lang, $this->shell->theme);
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
                ? $this->shell->theme->style('editorLineNoActive')
                : $this->shell->theme->style('editorLineNo');

            if ($li >= $total) {
                // 缓冲区之后：暗色 ~ 占位
                $lines[] = Line::fromSpans(
                    Span::styled($gutter, $gutterStyle),
                    Span::styled('~', $this->shell->theme->style('editorTilde')),
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
            // 文本选择反显：本可见行落在选区行范围内时，反显文本区内 [c0,c1] 显示列
            $absRow = $inner->position->y + $tabH + $i;
            if ($this->sel !== null
                && $absRow >= $this->sel[0] && $absRow <= $this->sel[2]) {
                $contentSpans = $this->invertSpans(
                    $contentSpans,
                    $inner->position->x + $gutterW,
                    $this->sel[1],
                    $this->sel[3]
                );
            }
            $lines[] = Line::fromSpans(
                Span::styled($gutter, $gutterStyle),
                ...$contentSpans
            );
        }

        // 横向滚动边界指示：取可见行的最大显示宽，判断左右是否还有隐藏内容。
        // 用可见视口内最宽行（而非整文档），既便宜又能正确反映「当前屏」的滚动余量。
        $maxVisW = 0;
        for ($i = 0; $i < $visibleRows; $i++) {
            $li = $buf->scrollTop + $i;
            if ($li >= $total) {
                continue;
            }
            $w = DisplayWidth::dispWidth($buf->lines[$li] ?? '');
            if ($w > $maxVisW) {
                $maxVisW = $w;
            }
        }
        $this->hLeft = $buf->scrollLeft > 0;
        $this->hRight = $buf->scrollLeft + $textW < $maxVisW;

        return ParagraphWidget::fromLines(...$lines);
    }

    /**
     * 光标跟随（向右）：仅当光标落在可见区右侧之外时，把 scrollLeft 右移使光标进入视口。
     * 不处理「光标在左侧之外」——那样会把自由横滚（滚轮）强行拉回，故由 followLeft 单独负责。
     * 全部使用显示列单位。
     */
    private function followRight(Buffer $buf, int $textW): void
    {
        $line = $buf->lines[$buf->cursorRow] ?? '';
        $cursorDisp = DisplayWidth::dispWidth(mb_substr($line, 0, $buf->cursorCol));
        $g = mb_substr($line, $buf->cursorCol, 1);
        $gw = $g === '' ? 0 : DisplayWidth::dispWidth($g);
        if ($cursorDisp + $gw > $buf->scrollLeft + $textW) {
            $buf->scrollLeft = max(0, $cursorDisp + $gw - $textW);
        }
        $this->clampScrollRange($buf, $line, $textW);
    }

    /** 光标跟随（向左）：仅当光标落在可见区左侧之外时，把 scrollLeft 左移使光标进入视口。 */
    private function followLeft(Buffer $buf): void
    {
        $line = $buf->lines[$buf->cursorRow] ?? '';
        $cursorDisp = DisplayWidth::dispWidth(mb_substr($line, 0, $buf->cursorCol));
        if ($cursorDisp < $buf->scrollLeft) {
            $buf->scrollLeft = $cursorDisp;
        }
        if ($buf->scrollLeft < 0) {
            $buf->scrollLeft = 0;
        }
    }

    /** 把 scrollLeft 钳到合法范围 [0, max(0, 行显示宽 - 视口宽)]，避免越界或滚出空白 */
    private function clampScrollRange(Buffer $buf, string $line, int $textW): void
    {
        $maxW = DisplayWidth::dispWidth($line);
        $max = max(0, $maxW - $textW);
        if ($buf->scrollLeft < 0) {
            $buf->scrollLeft = 0;
        }
        if ($buf->scrollLeft > $max) {
            $buf->scrollLeft = $max;
        }
    }

    /** 光标跟随（双向）：输入类操作使用——既防光标跑出右边缘，也会在光标落在左边缘外时拉回视口 */
    private function followBoth(Buffer $buf, int $textW): void
    {
        $this->followRight($buf, $textW);
        $this->followLeft($buf);
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
                : $this->shell->theme->style('editorTab');
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
        $this->scrollPinned = false; // 点击定位后恢复「光标跟随」
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

        // 文本宽度（显示列），供光标跟随钳制
        $ea = $areas['editor'];
        $einner = $ea->inner(new Margin(1, 1));
        $eW = max(0, $einner->width);
        $eGutter = min($eW, $buf->maxLineNoWidth + 1);
        $textW = max(0, $eW - $eGutter);

        switch ($e->code) {
            case KeyCode::Up:
                $buf->moveUp();
                return true;
            case KeyCode::Down:
                $buf->moveDown();
                return true;
            case KeyCode::Left:
                $this->scrollPinned = false;
                $buf->moveLeft();
                $this->followLeft($buf);
                return true;
            case KeyCode::Right:
                $this->scrollPinned = false;
                $buf->moveRight();
                $this->followRight($buf, $textW);
                return true;
            case KeyCode::Home:
                $this->scrollPinned = false;
                $buf->moveHome();
                $this->followLeft($buf);
                return true;
            case KeyCode::End:
                $this->scrollPinned = false;
                $buf->moveEnd();
                $this->followRight($buf, $textW);
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
        $this->scrollPinned = false; // 输入即光标移动，恢复「光标跟随」
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
            $this->followBoth($buf, $this->lastTextW);
            return true;
        }
        if ($e->char === "\x7f" || $e->char === "\x08") {
            $buf->backspace();
            $this->followBoth($buf, $this->lastTextW);
            return true;
        }
        if (KeyInput::isPrintable($e->char) && !($e->modifiers & KeyModifiers::CONTROL)) {
            $buf->insertChar($e->char);
            $this->followBoth($buf, $this->lastTextW);
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
        // 打不开（不存在/无权限/过大/二进制）时除了编辑器里的提示行，状态栏也带一句：
        // 用户可能正看着别处，只靠编辑器内提示容易以为没反应。
        $b = $this->shell->buffer;
        if ($b->noticeKey !== null) {
            $this->shell->setMessage($this->shell->t($b->noticeKey, $b->noticeParams));
        }
        $this->scrollPinned = false; // 打开新文件：光标跟随（从列 0 显示）
        $this->shell->focus('editor');
    }

    public function switchBuffer(string $path): void
    {
        if (isset($this->buffers[$path])) {
            $this->shell->buffer = $this->buffers[$path];
            $this->scrollPinned = false;
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
        $this->scrollPinned = false;
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
            $this->shell->setMessage($this->shell->t('editor.save_no_permission'));
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

        // 鼠标列是「显示列」：先换算成绝对显示列，再转成字符索引（CJK 占 2 列，
        // 不能直接当字符索引，否则点汉字列会错位）。
        $absDisp = ($pos->x - $inner->position->x - $gutterW) + $buf->scrollLeft;
        if ($absDisp < 0) {
            $absDisp = 0;
        }
        $buf->cursorCol = DisplayWidth::mbDispToCharIndex($buf->lines[$row] ?? '', $absDisp);
    }

    /**
     * 横向滚动（鼠标横向滚轮 / 触控板两指横滑）：调整 scrollLeft 视口偏移。
     * content() 已有「保证光标列可见」的钳制——光标在视口内时不会回弹，
     * 所以手动横向滚动在光标可见范围内持久生效；光标移出时才会被钳回。
     */
    public function onScrollH(int $delta): void
    {
        $buf = $this->shell->buffer;
        if ($buf === null) {
            return;
        }
        $this->scrollPinned = true; // 自由横滚：本帧 content() 不再把 scrollLeft 拉回光标
        $maxW = 0;
        foreach ($buf->lines as $line) {
            $w = DisplayWidth::dispWidth($line);
            if ($w > $maxW) {
                $maxW = $w;
            }
        }
        $buf->scrollLeft = max(0, min($buf->scrollLeft + $delta, max(0, $maxW - 1)));
    }

    /** 点击是否落在编辑器内容区（可起文本选择）：在面板内、且在内容起始行之下（排除 tab 栏与边框） */
    public function isSelectableAt(Position $pos, Area $editor): bool
    {
        if (!$editor->containsPosition($pos)) {
            return false;
        }
        $inner = $editor->inner(new Margin(1, 1));
        $tabH = $this->hasTabs() ? 1 : 0;
        return $pos->y >= $inner->position->y + $tabH;
    }

    /** 渲染前注入文本选择矩形（归一化 [r0,c0,r1,c1]）；null 清掉高亮 */
    public function setSelection(?array $r): void
    {
        $this->sel = $r;
    }

    /**
     * 对一组内容 Span 反显 [c0,c1] 绝对显示列区间（落在文本区内的部分）。
     * 文本区显示列从 $textX0 起；对跨入选区的 Span 切成「前/选中/后」三段，
     * 选中段加 REVERSED modifier。不改原 Span（新建避免副作用）。
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
     * 取编辑器文本选择矩形内的文字（无软换行 → 精确）。
     * 坐标均为视口绝对行列，用 positionCursorAtClick 同款数学反推 Buffer 行/字符区间。
     */
    public function getTextRect(Area $editor, int $r0, int $c0, int $r1, int $c1): string
    {
        $buf = $this->shell->buffer;
        if ($buf === null) {
            return '';
        }
        $inner = $editor->inner(new Margin(1, 1));
        $W = max(0, $inner->width);
        $gutterW = min($W, $buf->maxLineNoWidth + 1);
        $hasTabs = $this->hasTabs();
        $tabH = $hasTabs ? 1 : 0;
        $textX0 = $inner->position->x + $gutterW;
        $contentTop = $inner->position->y + $tabH;

        $out = [];
        for ($row = $r0; $row <= $r1; $row++) {
            if ($row < $contentTop) {
                $out[] = ''; // 边框/tab 栏行不是内容
                continue;
            }
            $li = ($row - $contentTop) + $buf->scrollTop;
            if ($li < 0 || $li >= count($buf->lines)) {
                $out[] = '';
                continue;
            }
            $line = $buf->lines[$li];
            $dispA = max(0, $c0 - $textX0);
            $dispB = max(0, $c1 - $textX0);
            $chA = DisplayWidth::mbDispToCharIndex($line, $dispA);
            $chB = DisplayWidth::mbDispToCharIndex($line, $dispB + 1);
            $out[] = mb_substr($line, $chA, $chB - $chA);
        }
        return implode("\n", $out);
    }
}

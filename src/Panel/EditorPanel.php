<?php
declare(strict_types=1);

namespace App\Panel;

use App\Core\CompletionState;
use App\Core\ConfigStore;
use App\Core\KeyInput;
use App\App;
use App\Editor\AutoPair;
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

    /** 自动配对设置（懒加载缓存；保存 .vicerc 时失效） */
    private ?AutoPair $autoPairConf = null;

    /** 最近一次渲染用的编辑器矩形（选中包裹要把屏幕选区折回 buffer 坐标，只有渲染期才知道内边距） */
    private ?Area $lastEditorArea = null;

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
        $this->shell->emitPluginEvent('file.closed', ['path' => $path]);
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
        $this->lastEditorArea = $editor;   // 供选中包裹把屏幕选区折回 buffer 坐标

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
        // 横滚上界取「可见视口内最宽行」（与下方 hRight 指示符同源）：
        // 早先按**光标所在行**算，光标停在短行时整屏都滚不动（与其他面板"按最宽行"也不一致）。
        $maxVisW = $this->visibleMaxWidth($buf, $buf->scrollTop, $visibleRows);
        $lineCur = $buf->lines[$buf->cursorRow] ?? '';
        if ($this->scrollPinned) {
            $buf->scrollLeft = max(0, min($buf->scrollLeft, max(0, $maxVisW - $textW)));
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
                $focused ? $buf->cursorColsOnLine($li) : [],
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

        $this->hLeft = $buf->scrollLeft > 0;
        $this->hRight = $buf->scrollLeft + $textW < $maxVisW;

        return ParagraphWidget::fromLines(...$lines);
    }

    /**
     * 可见视口内最宽行的显示列宽（横滚上界与 › 指示符共用，避免两个口径打架）。
     */
    private function visibleMaxWidth(Buffer $buf, int $scrollTop, int $rows): int
    {
        $max = 0;
        for ($i = 0; $i < $rows; $i++) {
            $li = $scrollTop + $i;
            if ($li >= count($buf->lines)) {
                continue;
            }
            $w = DisplayWidth::dispWidth($buf->lines[$li] ?? '');
            if ($w > $max) {
                $max = $w;
            }
        }
        return $max;
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
            case KeyCode::Down:
                // Alt+↑/↓ = 在上面/下面**加一个编辑光标**（多行同时编辑）。
                // ⚠️ **一律消费**（哪怕越界加不上）：越界时"什么都不做"比"顺手把主光标挪走"可预期得多
                // —— 后者会让主光标跑到已有光标的行上，出现"一行两个光标"的歧义态。
                if (($e->modifiers & KeyModifiers::ALT) !== 0) {
                    $buf->addCursorRelative($e->code === KeyCode::Up ? -1 : 1);
                    return true;
                }
                $e->code === KeyCode::Up ? $buf->moveUp() : $buf->moveDown();
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
                $this->backspaceEdit();
                return true;
            case KeyCode::Delete:
                if ($buf->hasMultipleCursors()) {
                    $buf->editsAtEachCursor(static fn () => $buf->delete());
                    $this->followBoth($buf, $textW);
                } else {
                    $buf->delete();
                }
                return true;
            case KeyCode::Enter:
                // ⚠️ 真实终端的回车是 **CodedKeyEvent(Enter)**，不是 CharKeyEvent("\r")。
                // onChar 里那条 "\r" 分支只在「直接喂字符事件」的调用方下才会走到，
                // 真实终端**永远不会**。少了这条，编辑器里按回车毫无反应（`BUGFIXES` A2 同款坑，
                // 那次修的是 AI 输入框；本处用 EditorPanel 自己的 `$textW` 而不是 onChar 依赖的
                // `$this->lastTextW` —— 后者只有渲染过才有值，键盘先于首帧到达时会是 0）。
                $this->scrollPinned = false;
                $buf->editsAtEachCursor(static fn () => $buf->insertNewline());
                $this->followBoth($buf, $textW);
                return true;
            case KeyCode::Esc:
                // 多光标时 Esc **只取消多光标**（不退出程序）——否则想收掉多余光标就只能退出应用。
                // 单光标时不消费，维持原语义（交给 App 走全局退出流程）。
                if ($buf->hasMultipleCursors()) {
                    $buf->collapseCursors();
                    return true;
                }
                return false;
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
        // Esc 取消多光标走**两条路径**：本项目对"单独按 Esc"一直是双路处理的
        // （`BUGFIXES` A3 修"退不出捕获态"时用的就是 `CharKeyEvent("\x1b")` + `CodedKeyEvent(Esc)` 双路，
        //  App 的 PTY 捕获分支与全局分支也都这么兜）。
        // 实测记录：本机真实 pty 里孤立 ESC 走的是 **Coded** 那条（去掉本分支后
        // `tests/probe_alt_arrows.php` 依然通过），所以这条是给"不认孤立 ESC、把它当普通字符"的终端兜底。
        if ($e->char === "\x1b") {
            if ($buf->hasMultipleCursors()) {
                $buf->collapseCursors();
                return true;
            }
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
            $buf->editsAtEachCursor(static fn () => $buf->insertNewline());
            $this->followBoth($buf, $this->lastTextW);
            return true;
        }
        if ($e->char === "\x7f" || $e->char === "\x08") {
            $this->backspaceEdit();
            return true;
        }
        if (KeyInput::isPrintable($e->char) && !($e->modifiers & KeyModifiers::CONTROL)) {
            if ($this->handleAutoPair($e->char)) {
                $this->followBoth($buf, $this->lastTextW);
                return true;
            }
            $buf->editsAtEachCursor(static fn () => $buf->insertChar($e->char));
            $this->followBoth($buf, $this->lastTextW);
            return true;
        }
        return false;
    }

    // ── 自动配对（可配置，见 .vicerc 的 editor.autoPairs）────────

    /**
     * 自动配对设置（懒加载 + 缓存）。
     *
     * 缓存理由：这段逻辑在**每次按键**上，而 `ConfigStore::load()` 每次都要读一遍配置文件。
     * 配置改了想立刻生效：在应用内保存 `.vicerc`（`save()` 里会失效本缓存）。
     */
    private function autoPair(): AutoPair
    {
        return $this->autoPairConf ??= new AutoPair(ConfigStore::editorAutoPairs());
    }

    /** 失效自动配对缓存（`save()` 存的是 `.vicerc` 时调用） */
    public function reloadEditorConfig(): void
    {
        $this->autoPairConf = null;
    }

    /**
     * 字符输入时的自动配对处置。返回 true = 这次输入已被处理（调用方不要再普通插入）。
     *
     * 三种结局都由 {@see AutoPair::plan()} 决定：跳过已有右符号 / 打左补右 / 选中包裹；
     * 不适用则返回 false，交给普通插入。
     */
    private function handleAutoPair(string $ch): bool
    {
        $buf = $this->shell->buffer;
        if ($buf === null || $buf->readOnly) {
            return false;
        }
        // 多光标下**不做**自动配对：不同位置"该不该配对"可能不同（引号词后规则、右侧是否已有右符号），
        // 一次输入在不同光标处产生不同结果会让用户完全无法预期。这一条写进了 README 与 CHANGELOG。
        if ($buf->hasMultipleCursors()) {
            return false;
        }
        $ap = $this->autoPair();
        if ($ap->isEmpty()) {
            return false;
        }
        $line = $buf->currentLine();
        $col = $buf->cursorCol;
        $before = $col > 0 ? mb_substr($line, $col - 1, 1) : '';
        $after = mb_substr($line, $col, 1);
        $plan = $ap->plan($ch, $before, $after);

        // 选中包裹：有选区且这次是"打左补右"的左符号 → 把选中内容包起来
        $pair = $ap->openPair($ch);
        if ($plan === AutoPair::PAIR && $pair !== null && $this->wrapSelectionWith($pair)) {
            return true;
        }
        if ($plan === AutoPair::SKIP) {
            // 右边就是同一个右符号：光标跳过它，不重复插（`()` 中间再打 `)` 不会变成 `())`）
            $buf->moveRight();
            return true;
        }
        if ($plan === AutoPair::PAIR && $pair !== null) {
            $buf->insertPair($ch, mb_substr($pair, 1, 1));
            return true;
        }
        return false;
    }

    /**
     * 退格：优先「成对删」（光标正好夹在一个空对中间时一次删两个），否则普通退格。
     * 两条退格路径（`CharKeyEvent "\x7f"` 与 `CodedKeyEvent(Backspace)`）共用这里，避免只改一条。
     */
    private function backspaceEdit(): void
    {
        $buf = $this->shell->buffer;
        if ($buf === null) {
            return;
        }
        // 多光标：每个光标各删一格（跨行合并由 Buffer::editsAtEachCursor 的行号补偿兜住）。
        // 自动配对的"成对删"不参与 —— 与 handleAutoPair 同一条理由。
        if ($buf->hasMultipleCursors()) {
            $buf->editsAtEachCursor(static fn () => $buf->backspace());
            $this->followBoth($buf, $this->lastTextW);
            return;
        }
        $ap = $this->autoPair();
        $line = $buf->currentLine();
        $col = $buf->cursorCol;
        $before = $col > 0 ? mb_substr($line, $col - 1, 1) : '';
        $after = mb_substr($line, $col, 1);
        if (!$ap->isEmpty() && $ap->emptyPairAt($before, $after)) {
            $buf->backspace();
            $buf->delete();     // 光标此时停在右符号上，delete 收掉它
        } else {
            $buf->backspace();
        }
        $this->followBoth($buf, $this->lastTextW);
    }

    /**
     * 用 `$pair` 包裹当前选中的内容。返回是否处理了。
     *
     * 选区是 `setSelection()` 注入的**屏幕坐标**矩形，这里按与 `getTextRect()` **同一套**
     * 映射折成 buffer 的行/字符下标 —— 两处映射必须一致，否则包出来的范围会和"复制到的"
     * 不一样（复制走 getTextRect）。
     */
    private function wrapSelectionWith(string $pair): bool
    {
        $buf = $this->shell->buffer;
        $area = $this->lastEditorArea;
        if ($buf === null || $area === null || $this->sel === null) {
            return false;
        }
        [$li0, $ch0, $li1, $ch1] = $this->selToCharRange($area, $buf, $this->sel);
        if ($li0 === $li1 && $ch0 === $ch1) {
            return false;   // 空选区：不包裹，退回普通插入
        }
        $inner = $this->rangeText($buf, $li0, $ch0, $li1, $ch1);
        $buf->replaceLineRange($li0, $ch0, $li1, $ch1, mb_substr($pair, 0, 1) . $inner . mb_substr($pair, 1, 1));
        $this->sel = null;   // 包完清掉高亮，免得它继续框住已经移位的内容
        return true;
    }

    /** 屏幕矩形 → buffer 的 [行0, 字符0, 行1, 字符1]（左下/右上角折算，夹进 buffer 范围） */
    private function selToCharRange(Area $editor, Buffer $buf, array $sel): array
    {
        [$r0, $c0, $r1, $c1] = $sel;
        $inner = $editor->inner(new Margin(1, 1));
        $contentTop = $inner->position->y + ($this->hasTabs() ? 1 : 0);
        $gutterW = min(max(0, $inner->width), $buf->maxLineNoWidth + 1);
        $textX0 = $inner->position->x + $gutterW;
        $last = max(0, count($buf->lines) - 1);

        $lineAt = static fn (int $row): int => max(0, min(($row - $contentTop) + $buf->scrollTop, $last));
        $charAt = static fn (int $li, int $col): int
            => DisplayWidth::mbDispToCharIndex($buf->lines[$li] ?? '', max(0, $col - $textX0));

        $li0 = $lineAt($r0);
        $li1 = $lineAt($r1);
        return [$li0, $charAt($li0, $c0), $li1, $charAt($li1, $c1 + 1)];
    }

    /** 取 buffer 坐标下 `[li0:ch0, li1:ch1]` 的文本（跨行用 \n 连接） */
    private function rangeText(Buffer $buf, int $li0, int $ch0, int $li1, int $ch1): string
    {
        if ($li0 === $li1) {
            return mb_substr($buf->lines[$li0] ?? '', $ch0, max(0, $ch1 - $ch0));
        }
        $out = [mb_substr($buf->lines[$li0] ?? '', $ch0)];
        for ($li = $li0 + 1; $li < $li1; $li++) {
            $out[] = $buf->lines[$li] ?? '';
        }
        $out[] = mb_substr($buf->lines[$li1] ?? '', 0, $ch1);
        return implode("\n", $out);
    }

    /**
     * 光标所在那一行的屏幕矩形（Tab 补全浮层的锚点）。
     *
     * 与 `render()` 共用同一套内边距与 tab 栏偏移 —— 浮层要贴在**光标行**上，
     * 错一行就会让人以为候选属于上一行。光标行不在可视区内（或没有 buffer）返回 null。
     */
    public function cursorRowArea(Area $editor): ?Area
    {
        $buf = $this->shell->buffer;
        if ($buf === null) {
            return null;
        }
        $inner = $editor->inner(new Margin(1, 1));
        $tabH = $this->hasTabs() ? 1 : 0;
        $rows = max(0, $inner->height - $tabH);
        $rel = $buf->cursorRow - $buf->scrollTop;
        if ($rel < 0 || $rel >= $rows) {
            return null;
        }
        return Area::fromScalars($inner->position->x, $inner->position->y + $tabH + $rel, max(1, $inner->width), 1);
    }

    // ── Buffer 操作 ──────────────────────────────────

    /**
     * Tab / Shift+Tab 的缩进与反向缩进（由 App 按焦点分发进来）。
     *
     * 为什么不直接在 App 里改 Buffer：光标跟随（`followBoth`）与 `scrollPinned` 是
     * 编辑器自己的渲染状态，绕过去会导致缩进后视口不跟手（与 onChar 里插字符是同一件事）。
     *
     * @param bool $outdent true = 反向缩进（Shift+Tab）
     * @return bool 是否真的改了内容（没改则不必重绘，也交回给上层继续处理该键）
     */
    public function indent(bool $outdent): bool
    {
        $buf = $this->shell->buffer;
        if ($buf === null || $buf->readOnly) {
            return false;   // 只读 buffer（如 git diff 视图）不参与缩进
        }
        $this->scrollPinned = false;
        if ($outdent) {
            $changed = false;
            $buf->editsAtEachCursor(static function () use ($buf, &$changed): void {
                if ($buf->outdentLine(CompletionState::INDENT_SPACES)) {
                    $changed = true;   // 只要有一个光标真的缩进了就算改动过
                }
            });
            if (!$changed) {
                return false;   // 所有光标的行首都没空白 → 交回上层（与单光标语义一致）
            }
        } else {
            $buf->editsAtEachCursor(static fn () => $buf->insertText(str_repeat(' ', CompletionState::INDENT_SPACES)));
        }
        $this->followBoth($buf, $this->lastTextW);
        return true;
    }

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
        $this->shell->emitPluginEvent('file.opened', ['path' => $path, 'virtual' => false]);
    }

    public function switchBuffer(string $path): void
    {
        if (isset($this->buffers[$path])) {
            $this->shell->buffer = $this->buffers[$path];
            $this->scrollPinned = false;
            $this->shell->emitPluginEvent('buffer.switched', ['path' => $path]);
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
        $this->shell->emitPluginEvent('file.opened', ['path' => $path, 'virtual' => true]);
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
        $this->shell->emitPluginEvent('buffer.switched', ['path' => $next]);
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
        $this->shell->emitPluginEvent('file.saved', ['path' => $path, 'ok' => $ok]);
        // 保存的若是插件专用配置文件，则重新注入插件配置（在 ViceCode 内改完即生效，无需重启）。
        // 键盘 Ctrl+S 也走这里，所以热加载判断放此处而非 App::menuAction，才能覆盖全部保存路径。
        if ($ok && $path === ConfigStore::pluginsPath()) {
            $this->shell->reloadPluginConfig();
            $this->shell->setMessage($this->shell->t('plugins.reloaded'));
            return;
        }
        // 同上：保存的是用户级模型（provider）配置文件 → 重读并重建注册表（提示由 reloadProviders 给）
        if ($ok && $path === ConfigStore::providersPath()) {
            $this->shell->reloadProviders();
            return;
        }
        // 保存的是主配置文件（.vicerc）→ 让启动期读入、之后被缓存的编辑器设置（自动配对）立刻生效
        if ($ok && $path === ConfigStore::path()) {
            $this->reloadEditorConfig();
        }
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
        [$row, $col] = $this->clickToBufferPos($pos, $editor, $buf);
        $buf->cursorRow = $row;
        $buf->cursorCol = $col;
    }

    /**
     * Alt+点击：在点击处**加一个编辑光标**（多行同时编辑）。
     *
     * 不动主光标、也**不产生选区** —— 选区那一步由 App 在记锚点之前拦掉（否则松手会当成拖选去复制）。
     * 换算复用 {@see self::clickToBufferPos()}，与普通点击同一套映射，避免两个落点差一格。
     *
     * @return bool 是否真的加了（落在主光标行或已有光标的行 → false，不新增）
     */
    public function addCursorAtClick(Position $pos, Area $editor): bool
    {
        $buf = $this->shell->buffer;
        if ($buf === null || $buf->readOnly) {
            return false;
        }
        [$row, $col] = $this->clickToBufferPos($pos, $editor, $buf);
        return $buf->addCursorAt($row, $col);
    }

    /**
     * 鼠标点击位置 → buffer 的 [行, 字符列]。
     *
     * 行：面板内行号 - tab 栏高度 + `scrollTop`（点在可视区外夹到最近有效行）；
     * 列：鼠标列是**显示列**，要减去行号槽宽、加上 `scrollLeft` 再换算成字符下标
     * （CJK 占 2 列，直接当字符索引会错位）。
     *
     * @return array{0:int,1:int}
     */
    private function clickToBufferPos(Position $pos, Area $editor, \App\Editor\Buffer $buf): array
    {
        $inner = $editor->inner(new Margin(1, 1));
        $gutterW = min(max(0, $inner->width), $buf->maxLineNoWidth + 1);

        $row = ($pos->y - $inner->position->y - ($this->hasTabs() ? 1 : 0)) + $buf->scrollTop;
        $total = count($buf->lines);
        $row = max(0, min($total - 1, $row));

        $absDisp = max(0, ($pos->x - $inner->position->x - $gutterW) + $buf->scrollLeft);
        $col = DisplayWidth::mbDispToCharIndex($buf->lines[$row] ?? '', $absDisp);
        return [$row, $col];
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

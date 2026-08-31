<?php
declare(strict_types=1);

namespace App\Panel;

use App\App;
use App\Core\KeyBindings;
use App\Text\DisplayWidth;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Widget\BorderType;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Widget\Widget;

/**
 * 帮助页覆盖层（M6 R2）：`?` 唤出，列出全部快捷键。
 *
 * ## 叠加怎么实现的
 * php-tui 的 `CompositeWidget` 能把多个 widget 渲染到**同一个 area**（注释原话：
 * "useful for showing dialogues"）。故 App::render() 在帮助页打开时返回
 * `CompositeWidget::fromWidgets($baseGrid, $this->widget(...))`——底层 UI 先画，
 * 覆盖层再画在上面。
 *
 * ## 为什么键位要居中而不是占满整屏
 * 用户要的是"浮在界面上的一页"，底下还看得见自己在哪个面板；占满整屏就丢失了
 * 这个上下文，且要自己处理清屏，反而更容易留残影。
 *
 * ## 打开期间键盘只归帮助页
 * 由 App::handle 在最前面拦截：只要 `isOpen()` 为真，事件全部交给这里，
 * 不往下层面板分发——否则在帮助页里按 `q` 会顺带把应用退了。
 */
final class HelpPanel
{
    private bool $open = false;

    private int $scroll = 0;

    /** 面板内容宽度（列），受视口约束 */
    private int $panelW = 60;

    private int $panelH = 20;

    public function __construct(private App $shell)
    {
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    public function scroll(): int
    {
        return $this->scroll;
    }

    public function toggle(): void
    {
        $this->open = !$this->open;
        $this->scroll = 0;
    }

    public function close(): void
    {
        $this->open = false;
        $this->scroll = 0;
    }

    public function open(): void
    {
        $this->open = true;
        $this->scroll = 0;
    }

    /**
     * 帮助页打开时消费按键。返回 true 表示已消费。
     *
     * @param int $viewH 面板可视高度（用于翻页步长与滚动上界）
     */
    public function onKey(CodedKeyEvent $e, int $viewH): bool
    {
        switch ($e->code) {
            case KeyCode::Esc:
                $this->close();
                return true;
            case KeyCode::Up:
                $this->scrollBy(-1);
                return true;
            case KeyCode::Down:
                $this->scrollBy(1);
                return true;
            case KeyCode::PageUp:
                $this->scrollBy(-max(1, $viewH - 1));
                return true;
            case KeyCode::PageDown:
                $this->scrollBy(max(1, $viewH - 1));
                return true;
            case KeyCode::Home:
                $this->scroll = 0;
                return true;
            case KeyCode::End:
                $this->scroll = max(0, $this->contentLineCount() - 1);
                return true;
        }
        return false;
    }

    /** 字符键：`?` 与 `q` 关闭（与面板标题提示一致） */
    public function onChar(CharKeyEvent $e): bool
    {
        if ($e->char === KeyBindings::HELP_KEY || strtolower($e->char) === 'q') {
            $this->close();
            return true;
        }
        return false;
    }

    /** 面板内容区高度（行）。键位处理要在渲染前知道它，故独立成方法，不依赖 widget() 先跑。 */
    public function viewHeightFor(int $vpW, int $vpH): int
    {
        $panelH = max(5, min($vpH - 2, $this->contentLineCount() + 2));
        return max(1, $panelH - 2);
    }

    public function scrollBy(int $delta): void
    {
        $this->scroll = max(0, min(max(0, $this->contentLineCount() - 1), $this->scroll + $delta));
    }

    // ── 内容 ────────────────────────────────────────

    /** @return string[] 帮助页的全部内容行（未裁剪、未滚动） */
    public function contentLines(): array
    {
        $t = fn(string $k): string => $this->shell->t($k);
        $lines = [];
        $lines[] = $t('help.title');
        $lines[] = '';
        foreach (KeyBindings::all() as $g) {
            $lines[] = $t($g['group']);
            foreach ($g['items'] as $it) {
                $lines[] = $this->formatItem($it['keys'], $t($it['desc']));
            }
            $lines[] = '';
        }
        $lines[] = $t('help.scroll_hint');
        $lines[] = $t('help.close');
        return $lines;
    }

    public function contentLineCount(): int
    {
        return count($this->contentLines());
    }

    /**
     * 一行条目的排版：键位列定宽，说明列跟在后面。
     * 定宽用「所有条目里最长的键位」算，而不是硬编码——新增 Ctrl+Shift+X 这类长键时
     * 不会突然错位。
     */
    private function formatItem(string $keys, string $desc): string
    {
        // 必须用 mbPadDisp（按显示列宽）而不是 mbPad（按字符数）：
        // 键位列里混着 ASCII('Ctrl+S') 和 CJK('横向滚轮'，4 字 8 列)，
        // 按字符数补齐会让 CJK 行短一大截，右列的说明参差不齐。
        return '  ' . DisplayWidth::mbPadDisp($keys, $this->keyColWidth()) . '  ' . $desc;
    }

    private function keyColWidth(): int
    {
        $max = 0;
        foreach (KeyBindings::all() as $g) {
            foreach ($g['items'] as $it) {
                $max = max($max, DisplayWidth::dispWidth($it['keys']));
            }
        }
        return $max;
    }

    // ── 渲染 ────────────────────────────────────────

    /**
     * 居中覆盖层。外层用 Grid 做「上下留白 + 左右留白」，内层是带边框的内容块。
     *
     * ⚠️ 每个内容行都要按面板内宽裁剪：ParagraphWidget 默认走 LineTruncator，
     * 超宽是**折行**不是截断，会把后续行整片挤下去（M1 幽灵行）。
     */
    public function widget(int $vpW, int $vpH): Widget
    {
        $W = $vpW;
        $H = $vpH;

        // 太小就别画了：留白格会被挤成 0 宽/0 高的 area，php-tui 往里写会抛
        // OutOfBoundsException（20x6 实测崩过）。此时帮助页浮层没有意义，只保留底层 UI。
        if ($W < 8 || $H < 5) {
            return $this->spacer();
        }

        // 面板尺寸：宽度取「最长内容行 + 边框 + 余量」，高度取内容行数。
        // ⚠️ 必须用 min($W - 2, …) 而不是给宽度设下限：一旦面板宽度追平视口，
        //   两侧留白格就被分到 0 宽，Grid 会产出 0xN 的 area 并让 Paragraph 抛越界。
        //   下限只兜到 3（够画一圈边框 + 1 格内容），再小就由上面的守卫拦掉。
        $longest = 0;
        foreach ($this->contentLines() as $l) {
            $longest = max($longest, DisplayWidth::dispWidth($l));
        }
        $this->panelW = max(3, min($W - 2, $longest + 4));
        $this->panelH = max(3, min($H - 2, $this->contentLineCount() + 2));

        $panel = $this->buildPanel();

        // 纵向：上留白 / 面板 / 下留白
        $top = max(0, intdiv($H - $this->panelH, 2));
        $bottom = max(0, $H - $this->panelH - $top);
        $rows = GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::length($top), Constraint::length($this->panelH), Constraint::length($bottom))
            ->widgets($this->spacer(), $this->centerRow($W, $panel), $this->spacer());

        return $rows;
    }

    /** 横向：左留白 / 面板 / 右留白 */
    private function centerRow(int $W, Widget $panel): Widget
    {
        $left = max(0, intdiv($W - $this->panelW, 2));
        $right = max(0, $W - $this->panelW - $left);
        return GridWidget::default()
            ->direction(Direction::Horizontal)
            ->constraints(Constraint::length($left), Constraint::length($this->panelW), Constraint::length($right))
            ->widgets($this->spacer(), $panel, $this->spacer());
    }

    private function buildPanel(): Widget
    {
        $innerW = max(0, $this->panelW - 2);
        $innerH = max(0, $this->panelH - 2);

        $this->scroll = max(0, min($this->scroll, max(0, $this->contentLineCount() - $innerH)));
        $slice = array_slice($this->contentLines(), $this->scroll, $innerH);

        $lines = [];
        foreach ($slice as $i => $text) {
            $isGroupHeader = $this->isGroupHeaderLine($this->scroll + $i);
            $style = $isGroupHeader
                ? $this->shell->theme->style('helpGroup')
                : Style::default();
            $lines[] = Line::fromSpans(Span::styled(
                DisplayWidth::mbCutDisp($text, $innerW),
                $style
            ));
        }
        while (count($lines) < $innerH) {
            $lines[] = Line::fromSpans(Span::styled('', Style::default()));
        }

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->titles(Title::fromString(' ' . $this->shell->t('help.title') . ' '))
            ->widget(ParagraphWidget::fromLines(...$lines));
    }

    /** 判断第 $idx 内容行是否为分组标题（用于着色） */
    private function isGroupHeaderLine(int $idx): bool
    {
        // 内容结构：标题 / 空行 / 每组(组名 + N 条目 + 空行) / 提示 / 关闭提示
        if ($idx < 2) {
            return false;
        }
        $i = $idx - 2;
        foreach (KeyBindings::all() as $g) {
            if ($i === 0) {
                return true;
            }
            $i -= 1 + count($g['items']) + 1;
            if ($i < 0) {
                return false;
            }
        }
        return false;
    }

    /** 留白块：不画任何东西（Composite 下不会盖掉底层已画的内容） */
    private function spacer(): Widget
    {
        return ParagraphWidget::fromString('');
    }
}

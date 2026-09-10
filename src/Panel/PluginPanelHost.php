<?php
declare(strict_types=1);

namespace App\Panel;

use App\App;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\KeyModifiers;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Style\Modifier;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Widget\BorderType;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Widget\Widget;

/**
 * 自定义面板宿主浮层（V1.1）。所有插件通过 PluginInterface::panels() 声明的面板，
 * 都收进这一个浮层，内部用 tab 切换。
 *
 * ## 为什么是浮层而非布局面板
 * docs/plugins.md §9 曾把「自定义面板」列为 V1.1 未支持，理由是新增布局面板要改
 * 焦点枚举 / Tab 循环 / 鼠标命中 / build 分支 / LayoutFactory，成本很高。本实现改为
 * 「可开合浮层」，复用 HelpPanel / PluginsPanel / CommandPalettePanel 的
 * CompositeWidget 叠加范式——底层六面板布局完全不动，浮层浮在其上并独占键盘。
 * 因此 App::PANELS、LayoutFactory::split、build、拖拽分隔条、Tab 焦点循环一律无需改动。
 *
 * ## 复用既有范式
 * 居中数学照搬 PluginsPanel::widget()（top/bottom/left/right + Constraint::length）；
 * 打开期间独占键盘（模态）与 PluginsPanel 同款。
 */
final class PluginPanelHost
{
    private bool $open = false;

    /** 当前高亮的 tab 下标 */
    private int $sel = 0;

    public function __construct(private App $shell)
    {
    }

    // ── 状态 ────────────────────────────────────────

    public function isOpen(): bool
    {
        return $this->open;
    }

    public function open(): void
    {
        $this->open = true;
        $this->sel = 0;
    }

    public function close(): void
    {
        $this->open = false;
        $this->sel = 0;
    }

    public function toggle(): void
    {
        $this->open ? $this->close() : $this->open();
    }

    /** 测试辅助：当前高亮 tab 下标 */
    public function selectedIndex(): int
    {
        return $this->sel;
    }

    // ── 键盘 ────────────────────────────────────────

    /**
     * 浮层打开时消费字符键。返回 true 表示已消费。
     * 仅当当前 tab 的面板提供了 onChar 回调时才交给它，否则浮层不处理字符键。
     */
    public function onChar(CharKeyEvent $e): bool
    {
        if (!$this->open) {
            return false;
        }
        $tabs = $this->shell->pluginPanels();
        if ($tabs === []) {
            return false;
        }
        $panel = $tabs[$this->sel]['panel'];
        if ($panel->onChar !== null) {
            return ($panel->onChar)($e);
        }
        return false;
    }

    /**
     * 浮层打开时消费编码键。返回 true 表示已消费。
     * Tab / → 切到下一 tab；Shift+Tab / ← 切到上一 tab；Esc 关闭；
     * 其余键若当前 tab 的面板提供了 onKey 回调则交给它，否则浮层不消费（由上层吞掉）。
     */
    public function onKey(CodedKeyEvent $e): bool
    {
        if (!$this->open) {
            return false;
        }
        $tabs = $this->shell->pluginPanels();
        $n = count($tabs);
        switch ($e->code) {
            case KeyCode::Esc:
                $this->close();
                return true;
            case KeyCode::Tab:
                if ($n > 0) {
                    $this->sel = (($e->modifiers & KeyModifiers::SHIFT) !== 0)
                        ? ($this->sel - 1 + $n) % $n
                        : ($this->sel + 1) % $n;
                }
                return true;
            case KeyCode::Right:
                if ($n > 0) {
                    $this->sel = ($this->sel + 1) % $n;
                }
                return true;
            case KeyCode::Left:
                if ($n > 0) {
                    $this->sel = ($this->sel - 1 + $n) % $n;
                }
                return true;
            default:
                if ($n > 0) {
                    $panel = $tabs[$this->sel]['panel'];
                    if ($panel->onKey !== null) {
                        return ($panel->onKey)($e);
                    }
                }
                return false;
        }
    }

    // ── 渲染 ────────────────────────────────────────

    public function widget(int $vpW, int $vpH): Widget
    {
        if ($vpW < 12 || $vpH < 6) {
            return ParagraphWidget::fromString('');
        }
        $tabs = $this->shell->pluginPanels();
        $n = count($tabs);
        $this->sel = $n === 0 ? 0 : max(0, min($n - 1, $this->sel));

        $panelW = max(30, min($vpW - 2, 70));
        $panelH = max(8, min($vpH - 2, 28));
        $innerW = max(0, $panelW - 2);
        $innerH = max(0, $panelH - 2);
        $tabH = 1;
        $contentH = max(0, $innerH - $tabH);

        // tab 栏：一行内各面板标题拼接，选中项反显高亮
        $tabSpans = [];
        foreach ($tabs as $i => $t) {
            $style = Style::default()->fg($this->shell->theme->color('menuDrop'));
            if ($i === $this->sel) {
                $style = $style->fg($this->shell->theme->color('menuSel'))
                    ->addModifier(Modifier::REVERSED);
            }
            $tabSpans[] = Span::styled(' ' . $t['panel']->title . ' ', $style);
        }
        $tabWidget = ParagraphWidget::fromLines(Line::fromSpans(...$tabSpans));

        // 内容区：无面板时给提示，否则交给当前面板的 render 闭包
        if ($n === 0) {
            $contentWidget = ParagraphWidget::fromString($this->shell->t('panel.empty'));
        } else {
            $panel = $tabs[$this->sel]['panel'];
            $contentWidget = ($panel->render)($this->shell, $innerW, $contentH);
        }

        $inner = GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::length($tabH), Constraint::min(1))
            ->widgets($tabWidget, $contentWidget);

        $panel = BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->titles(Title::fromString(' ' . $this->shell->t('panel.title') . ' '))
            ->widget($inner);

        $top = max(0, intdiv($vpH - $panelH, 2));
        $bottom = max(0, $vpH - $panelH - $top);
        $left = max(0, intdiv($vpW - $panelW, 2));
        $right = max(0, $vpW - $panelW - $left);

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::length($top), Constraint::length($panelH), Constraint::length($bottom))
            ->widgets(
                ParagraphWidget::fromString(''),
                GridWidget::default()
                    ->direction(Direction::Horizontal)
                    ->constraints(Constraint::length($left), Constraint::length($panelW), Constraint::length($right))
                    ->widgets(ParagraphWidget::fromString(''), $panel, ParagraphWidget::fromString('')),
                ParagraphWidget::fromString('')
            );
    }
}

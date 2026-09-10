<?php
declare(strict_types=1);

namespace App\Panel;

use App\App;
use App\Text\DisplayWidth;
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
 * 命令面板（F1 唤出）：汇聚系统命令（菜单项）与 V1.1 插件命令，提供模糊子串过滤 +
 * 键盘选择，选中后走既有的 App::menuAction() 分发，不另立分发逻辑。
 *
 * ## 唤起键为什么是 F1 而不是 Ctrl+Shift+P
 * 用户原始诉求是 VS Code 的 Ctrl+Shift+P，但项目实测结论（src/App.php:421-422、memory feedback.md）
 * 确认：真实 pty 下 Shift/Alt 修饰不可区分，Ctrl+Shift+P 会退化成裸 Ctrl+P（0x10 控制字节），
 * 与 AI 面板的 Ctrl+P 切 Provider 冲突。F1 在 pty 下是独立 FunctionKeyEvent（同 F10/F2），
 * 完全可识别且当前空闲，故用 F1。
 *
 * ## 复用既有范式
 * 居中浮层 + 打开期间独占键盘：与 HelpPanel / PluginsPanel 同款（CompositeWidget 叠加，
 * 底层 UI 先画、浮层再浮其上）。widget() 的居中数学照搬 PluginsPanel::widget()。
 * 命令清单直接扁平化 MenuBarPanel::definitions()（含插件命令组），和菜单同源、天然对齐。
 */
final class CommandPalettePanel
{
    private bool $open = false;

    /** 过滤输入串（输入框内容） */
    private string $filter = '';

    /** 当前高亮项在 $filtered 中的下标 */
    private int $sel = 0;

    /** 打开时从 App 取的全量命令（扁平化菜单定义） */
    private array $entries = [];

    /** 按 $filter 过滤后的命令（选中项下标基于此） */
    private array $filtered = [];

    /** 命令列表的滚动偏移（仅列表区滚动，过滤输入框固定） */
    private int $scroll = 0;

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
        $this->filter = '';
        $this->sel = 0;
        $this->scroll = 0;
        $this->entries = $this->shell->commandPaletteEntries();
        $this->rebuildFiltered();
    }

    public function close(): void
    {
        $this->open = false;
        $this->filter = '';
        $this->sel = 0;
        $this->filtered = [];
    }

    public function toggle(): void
    {
        $this->open ? $this->close() : $this->open();
    }

    // 测试辅助：读当前输入/匹配数/选中 id
    public function filterText(): string
    {
        return $this->filter;
    }

    public function matchCount(): int
    {
        return count($this->filtered);
    }

    public function totalCount(): int
    {
        return count($this->entries);
    }

    public function selectedId(): ?string
    {
        return $this->filtered[$this->sel]['id'] ?? null;
    }

    // ── 过滤 ────────────────────────────────────────

    /** 按输入串重算 $filtered（空串=全列），并把选择夹回合法范围 */
    private function rebuildFiltered(): void
    {
        if ($this->filter === '') {
            $this->filtered = $this->entries;
        } else {
            $q = mb_strtolower($this->filter);
            $out = [];
            foreach ($this->entries as $e) {
                if (mb_stripos($e['title'], $q) !== false
                    || mb_stripos($e['id'], $q) !== false) {
                    $out[] = $e;
                }
            }
            $this->filtered = $out;
        }
        $this->sel = 0;
        $this->scroll = 0;
    }

    // ── 键盘 ────────────────────────────────────────

    /**
     * 面板打开时消费字符键（过滤输入）。返回 true 表示已消费。
     * 只接受可打印字符：拒绝空串、ESC 与 ASCII 控制符（含 DEL 0x7f）；
     * 多字节（CJK 等）strlen>1 直接放过。
     */
    public function onChar(CharKeyEvent $e): bool
    {
        if (!$this->open) {
            return false;
        }
        $c = $e->char;
        if ($c === '' || $c === "\x1b") {
            return false;
        }
        if (strlen($c) === 1 && (ord($c) < 32 || ord($c) === 127)) {
            return false;   // ASCII 控制符不入过滤框
        }
        // 带 CONTROL/ALT 修饰的字符（如 Ctrl+字母）不当作输入，交给编码层处理
        if (($e->modifiers & (KeyModifiers::CONTROL | KeyModifiers::ALT)) !== 0) {
            return false;
        }
        $this->filter .= $c;
        $this->rebuildFiltered();
        return true;
    }

    /**
     * 面板打开时消费编码键。返回 true 表示已消费。
     */
    public function onKey(CodedKeyEvent $e): bool
    {
        if (!$this->open) {
            return false;
        }
        $n = count($this->filtered);
        switch ($e->code) {
            case KeyCode::Up:
                $this->sel = $n === 0 ? 0 : max(0, $this->sel - 1);
                return true;
            case KeyCode::Down:
                $this->sel = $n === 0 ? 0 : min($n - 1, $this->sel + 1);
                return true;
            case KeyCode::Enter:
                $this->executeSelected();
                return true;
            case KeyCode::Backspace:
                $this->filter = mb_substr($this->filter, 0, -1);
                $this->rebuildFiltered();
                return true;
            case KeyCode::Esc:
                $this->close();
                return true;
        }
        return false;
    }

    /** 执行当前高亮命令（走既有 menuAction 分发）后关闭面板 */
    private function executeSelected(): void
    {
        $id = $this->filtered[$this->sel]['id'] ?? null;
        if ($id !== null) {
            $this->shell->menuAction($id);
        }
        $this->close();
    }

    // ── 渲染 ────────────────────────────────────────

    public function widget(int $vpW, int $vpH): Widget
    {
        if ($vpW < 10 || $vpH < 6) {
            return ParagraphWidget::fromString('');
        }
        $filtered = $this->filtered;
        $count = count($filtered);

        // 内容宽度：过滤行 / 各命令行 / 标题 三者取最大（每条命令行 = 前导空格 + 标题 + 间隔 + 快捷键 + 尾随空格）
        $contentW = DisplayWidth::dispWidth($this->shell->t('palette.title')) + 4;
        $filterW = 2 + DisplayWidth::dispWidth($this->filter) + 1; // "> " + 输入 + 光标
        $contentW = max($contentW, $filterW);
        foreach ($filtered as $e) {
            $rowW = 1 + DisplayWidth::dispWidth($e['title'])
                + 2 + DisplayWidth::dispWidth($e['shortcut']) + 1;
            $contentW = max($contentW, $rowW);
        }
        $innerW = max(8, min($vpW - 4, $contentW + 2));

        // 高度：2 边框 + 1 过滤行 + 列表（列表随视口截断）
        $listH = min($count, max(0, $vpH - 4)); // 2 边框 + 1 过滤行 + 至少 1 行列表
        $panelH = min($vpH - 2, 2 + 1 + $listH);
        $innerH = max(0, $panelH - 2);
        $listH = max(0, $innerH - 1);

        // 自动滚动让选中项可见（保持选中项大致居中）
        $scroll = $count === 0 ? 0
            : max(0, min($this->sel - intdiv($listH, 2), max(0, $count - $listH)));
        $this->scroll = $scroll;
        $slice = $listH > 0 ? array_slice($filtered, $scroll, $listH) : [];

        $lines = [];
        // 过滤输入框行
        if ($this->filter === '') {
            $ph = $this->shell->t('palette.placeholder');
            $lines[] = Line::fromSpans(
                Span::styled('> ', Style::default()->fg($this->shell->theme->color('menuSel'))),
                Span::styled($ph . '█', Style::default()
                    ->fg($this->shell->theme->color('menuDrop'))
                    ->addModifier(Modifier::DIM)),
            );
        } else {
            $lines[] = Line::fromSpans(
                Span::styled('> ', Style::default()->fg($this->shell->theme->color('menuSel'))),
                Span::styled($this->filter . '█', Style::default()
                    ->fg($this->shell->theme->color('menuDrop'))),
            );
        }
        // 命令列表行
        foreach ($slice as $i => $e) {
            $globalIdx = $scroll + $i;
            $hit = $globalIdx === $this->sel;
            $row = ' ' . $e['title']
                . str_repeat(' ', max(1, 2 + DisplayWidth::dispWidth($e['shortcut'])))
                . $e['shortcut'] . ' ';
            $style = Style::default()->fg($this->shell->theme->color('menuDrop'));
            if ($hit) {
                $style = $style->fg($this->shell->theme->color('menuSel'))
                    ->addModifier(Modifier::REVERSED);
            }
            $lines[] = Line::fromSpans(
                Span::styled(DisplayWidth::mbCutDisp($row, $innerW), $style)
            );
        }
        while (count($lines) < $innerH) {
            $lines[] = Line::fromSpans(Span::styled('', Style::default()));
        }

        $panel = BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->titles(Title::fromString(' ' . $this->shell->t('palette.title') . ' '))
            ->widget(ParagraphWidget::fromLines(...$lines));

        $panelW = min($vpW - 2, $innerW + 2);

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

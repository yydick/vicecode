<?php
declare(strict_types=1);

namespace App\Panel;

use App\App;
use App\Core\ConfigStore;
use App\Plugin\PluginInterface;
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
 * 插件管理浮层（V1.1 入口）：菜单「文件 → 已安装插件」唤出，列出已加载插件及其当前配置，
 * 按 Enter 直接用 ViceCode 自带编辑器打开插件专用配置文件 ~/.vicecode.plugins.json
 * （VSCode「打开设置(JSON)」的同款体验，保存即热重载，不甩给用户外部编辑器）。
 *
 * 复用 HelpPanel 的 CompositeWidget 居中浮层 + 打开期间独占键盘的范式（见 HelpPanel 注释）。
 */
final class PluginsPanel
{
    private bool $open = false;

    private int $scroll = 0;

    /** 选中的插件下标（↑/↓ 移动；Space 切换其启用状态） */
    private int $sel = 0;

    /**
     * contentLines() 每次重建时记录的「每个插件条目占据的行区间」。
     * 条目高度可变（有无配置/命令/冲突提示），无法用固定行高换算，故渲染时顺手记账，
     * 供 widget() 把选中项滚进可视区。
     * @var list<array{start:int,end:int}> start/end 均为 0-based 闭区间
     */
    private array $entryRanges = [];

    private int $panelW = 70;

    private int $panelH = 20;

    public function __construct(private App $shell)
    {
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    public function open(): void
    {
        $this->open = true;
        $this->scroll = 0;
        $this->sel = 0;
    }

    public function close(): void
    {
        $this->open = false;
        $this->scroll = 0;
    }

    /**
     * 打开期间消费按键：Esc/q 关闭，↑/↓ 移动选中插件，PgUp/PgDn/Home/End 滚动，
     * Space 切换选中插件的启用/禁用，Enter 在 ViceCode 编辑器内打开配置。
     * 返回 true 表示已消费。
     */
    public function onKey(CodedKeyEvent $e, int $viewH): bool
    {
        switch ($e->code) {
            case KeyCode::Esc:
                $this->close();
                return true;
            case KeyCode::Enter:
                // 在 ViceCode 自己的编辑器里打开插件专用配置文件编辑（而非外部编辑器）
                $this->shell->openPluginConfig();
                $this->close();
                return true;
            case KeyCode::Up:
                $this->moveSelection(-1);
                return true;
            case KeyCode::Down:
                $this->moveSelection(1);
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

    public function onChar(CharKeyEvent $e): bool
    {
        if (strtolower($e->char) === 'q') {
            $this->close();
            return true;
        }
        if ($e->char === ' ') {
            // 切换选中插件的启用状态（立即热生效）
            $this->toggleSelected();
            return true;
        }
        if ($e->char === "\r" || $e->char === "\n") {
            // Enter 的字符形态：在 ViceCode 编辑器内打开插件专用配置文件编辑
            $this->shell->openPluginConfig();
            $this->close();
            return true;
        }
        return false;
    }

    /** 浮层当前选中的插件 id（列表为空返回 null） */
    public function selectedPluginId(): ?string
    {
        $p = $this->shell->allPlugins[$this->sel] ?? null;
        return $p?->id();
    }

    /** 选中的插件是否已启用（无选中返回 false，仅作展示用） */
    public function selectedPluginEnabled(): bool
    {
        $id = $this->selectedPluginId();
        return $id !== null && $this->shell->pluginIsEnabled($id);
    }

    /** ↑/↓：在插件条目间移动选中（并保证其滚入可视区；不能越界） */
    public function moveSelection(int $delta): void
    {
        $n = count($this->shell->allPlugins);
        if ($n === 0) {
            $this->sel = 0;
            return;
        }
        $this->sel = max(0, min($n - 1, $this->sel + $delta));
        $this->scrollSelectionIntoView();
    }

    /** Space：翻转选中插件的启用状态 */
    public function toggleSelected(): void
    {
        $id = $this->selectedPluginId();
        if ($id !== null) {
            $this->shell->togglePlugin($id);
        }
    }

    /**
     * 把选中条目滚进可视区。依赖 contentLines() 记账的 entryRanges（未构建时什么都不做）。
     * $innerH/$total 缺省按「最近一次渲染的内高」估算——已经足够，因为下一次 widget()
     * 会用精确值再夹一次，不会出现漂移累积。
     */
    private function scrollSelectionIntoView(?int $innerH = null, ?int $total = null): void
    {
        $range = $this->entryRanges[$this->sel] ?? null;
        if ($range === null) {
            return;
        }
        $innerH ??= max(1, $this->panelH - 2);
        $total ??= count($this->contentLines());
        if ($range['start'] < $this->scroll) {
            $this->scroll = $range['start'];
        } elseif ($range['end'] >= $this->scroll + $innerH) {
            $this->scroll = $range['end'] - $innerH + 1;
        }
        $this->scroll = max(0, min($this->scroll, max(0, $total - $innerH)));
    }

    public function viewHeightFor(int $vpW, int $vpH): int
    {
        $panelH = max(5, min($vpH - 2, $this->contentLineCount() + 2));
        return max(1, $panelH - 2);
    }

    public function scrollBy(int $delta): void
    {
        $this->scroll = max(0, min(max(0, $this->contentLineCount() - 1), $this->scroll + $delta));
    }

    /** 每个已加载插件「默认 ∩ 用户覆盖」后的有效配置（委托 App 统一合并逻辑）。 */
    private function effectiveConfig(PluginInterface $p): array
    {
        return $this->shell->pluginEffectiveConfig($p);
    }

    /** @return string[] 浮层全部内容行（未裁剪、未滚动）；同时记录每个插件条目的行区间 */
    public function contentLines(): array
    {
        $t = fn(string $k): string => $this->shell->t($k);
        $lines = [];
        $this->entryRanges = [];
        $lines[] = $t('plugins.title');
        $lines[] = '';
        // 列出**全部**已加载插件（含被禁用者）：禁用后仍需能从这里再打开
        $plugins = $this->shell->allPlugins;
        if ($plugins === []) {
            $lines[] = $t('plugins.no_plugins');
            $lines[] = '';
        }
        $this->sel = count($plugins) === 0 ? 0 : max(0, min($this->sel, count($plugins) - 1));
        foreach ($plugins as $i => $p) {
            $start = count($lines);
            $id = $p->id();
            $on = $this->shell->pluginIsEnabled($id);
            $config = $this->effectiveConfig($p);
            $cfgStr = $config === []
                ? $t('plugins.no_config')
                : implode('  ', array_map(static fn($k, $v): string => $k . '=' . $v, array_keys($config), $config));
            $tick = method_exists($p, 'tickInterval') ? $p->tickInterval() : null;
            $tickStr = $tick !== null ? "tick={$tick}s" : 'tick=none';
            $marker = $i === $this->sel ? '» ' : '  ';
            $status = $on ? $t('plugins.enabled') : $t('plugins.disabled');
            $lines[] = $marker . '• ' . $id . '  [' . $status . ' · ' . $tickStr . ']';
            $lines[] = '     ' . $cfgStr;
            // V1.1：命令清单（快捷键只列绑定成功的，避免菜单/页面承诺一个按了没反应的组合）
            $cmds = $this->shell->pluginCommandsOf($id);
            if ($cmds !== []) {
                $lines[] = '     ' . $t('plugins.commands') . ' (' . count($cmds) . '):';
                foreach ($cmds as $local => $c) {
                    $sc = $this->shell->pluginShortcutOf($id . '.' . $local);
                    $lines[] = '       ⌘ ' . $c->title . ($sc !== null ? '  (' . $sc . ')' : '');
                }
            }
            // 冲突必须可见：静默忽略一个快捷键，用户会以为插件坏了
            foreach ($this->shell->pluginConflictsOf($id) as $info) {
                $lines[] = '       ⚠ ' . $this->conflictText($info);
            }
            $this->entryRanges[$i] = ['start' => $start, 'end' => count($lines) - 1];
            $lines[] = '';
        }
        $lines[] = $t('plugins.config_path') . ': ' . ConfigStore::pluginsPath();
        $lines[] = $t('plugins.hint');
        $lines[] = $t('plugins.close');
        return $lines;
    }

    /**
     * 冲突原因 → 可见文案（都用 i18n，避免硬编码中文）。
     * @param array{reason:string,owner:string} $info
     */
    private function conflictText(array $info): string
    {
        $t = $this->shell->t(...);           // 带占位符的翻译（闭包 $t 只收一个参数，不能复用）
        $reason = $info['reason'] ?? '';
        $owner = $info['owner'] ?? '';
        switch ($reason) {
            case 'reserved':
                return $t('plugins.shortcut_reserved', ['key' => $owner]);
            case 'taken':
                return $t('plugins.shortcut_taken', ['key' => $owner, 'owner' => $owner]);
            case 'unsupported':
                return $t('plugins.shortcut_unsupported', ['key' => $owner]);
            case 'no_executor':
                return $t('plugins.no_executor');
            default:
                return $t('plugins.command_unavailable', ['reason' => $reason]);
        }
    }

    public function contentLineCount(): int
    {
        return count($this->contentLines());
    }

    public function widget(int $vpW, int $vpH): Widget
    {
        if ($vpW < 8 || $vpH < 5) {
            return ParagraphWidget::fromString('');
        }
        $all = $this->contentLines();          // 一次构建：内部记好 entryRanges，供选中项滚动定位
        $total = count($all);
        $longest = 0;
        foreach ($all as $l) {
            $longest = max($longest, DisplayWidth::dispWidth($l));
        }
        $this->panelW = max(3, min($vpW - 2, $longest + 4));
        $this->panelH = max(3, min($vpH - 2, $total + 2));

        $innerW = max(0, $this->panelW - 2);
        $innerH = max(0, $this->panelH - 2);
        $this->scroll = max(0, min($this->scroll, max(0, $total - $innerH)));
        // 让选中条目保持在可视区内（↑/↓ 移动后自动跟随；面板变矮时也拉回来）
        $this->scrollSelectionIntoView($innerH, $total);
        $slice = array_slice($all, $this->scroll, $innerH);

        $lines = [];
        foreach ($slice as $text) {
            $lines[] = Line::fromSpans(Span::styled(DisplayWidth::mbCutDisp($text, $innerW), Style::default()));
        }
        while (count($lines) < $innerH) {
            $lines[] = Line::fromSpans(Span::styled('', Style::default()));
        }
        $panel = BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->titles(Title::fromString(' ' . $this->shell->t('plugins.title') . ' '))
            ->widget(ParagraphWidget::fromLines(...$lines));

        $top = max(0, intdiv($vpH - $this->panelH, 2));
        $bottom = max(0, $vpH - $this->panelH - $top);
        $left = max(0, intdiv($vpW - $this->panelW, 2));
        $right = max(0, $vpW - $this->panelW - $left);
        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::length($top), Constraint::length($this->panelH), Constraint::length($bottom))
            ->widgets(
                ParagraphWidget::fromString(''),
                GridWidget::default()
                    ->direction(Direction::Horizontal)
                    ->constraints(Constraint::length($left), Constraint::length($this->panelW), Constraint::length($right))
                    ->widgets(ParagraphWidget::fromString(''), $panel, ParagraphWidget::fromString('')),
                ParagraphWidget::fromString('')
            );
    }
}

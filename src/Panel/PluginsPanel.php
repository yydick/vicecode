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
    }

    public function close(): void
    {
        $this->open = false;
        $this->scroll = 0;
    }

    /** 打开期间消费按键（Esc/q 关闭，上下/翻页滚动，Enter 在 ViceCode 编辑器内打开配置）。返回 true 表示已消费。 */
    public function onKey(CodedKeyEvent $e, int $viewH): bool
    {
        switch ($e->code) {
            case KeyCode::Esc:
                $this->close();
                return true;
            case KeyCode::Enter:
                // 在 ViceCode 自己的编辑器里打开 ~/.vicerc 编辑（而非外部编辑器）
                $this->shell->openPluginConfig();
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

    public function onChar(CharKeyEvent $e): bool
    {
        if (strtolower($e->char) === 'q') {
            $this->close();
            return true;
        }
        if ($e->char === "\r" || $e->char === "\n") {
            // Enter 的字符形态：在 ViceCode 编辑器内打开 ~/.vicerc 编辑
            $this->shell->openPluginConfig();
            $this->close();
            return true;
        }
        return false;
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

    /** @return string[] 浮层全部内容行（未裁剪、未滚动） */
    public function contentLines(): array
    {
        $t = fn(string $k): string => $this->shell->t($k);
        $lines = [];
        $lines[] = $t('plugins.title');
        $lines[] = '';
        $plugins = $this->shell->plugins;
        if ($plugins === []) {
            $lines[] = '(none)';
            $lines[] = '';
        }
        foreach ($plugins as $p) {
            $id = $p->id();
            $config = $this->effectiveConfig($p);
            $cfgStr = $config === []
                ? $t('plugins.no_config')
                : implode('  ', array_map(static fn($k, $v): string => $k . '=' . $v, array_keys($config), $config));
            $tick = method_exists($p, 'tickInterval') ? $p->tickInterval() : null;
            $tickStr = $tick !== null ? "tick={$tick}s" : 'tick=none';
            $lines[] = '• ' . $id . '  [' . $t('plugins.enabled') . ' · ' . $tickStr . ']';
            $lines[] = '   ' . $cfgStr;
            // V1.1：命令清单（快捷键只列绑定成功的，避免菜单/页面承诺一个按了没反应的组合）
            $cmds = $this->shell->pluginCommandsOf($id);
            if ($cmds !== []) {
                $lines[] = '   ' . $t('plugins.commands') . ' (' . count($cmds) . '):';
                foreach ($cmds as $local => $c) {
                    $sc = $this->shell->pluginShortcutOf($id . '.' . $local);
                    $lines[] = '     ⌘ ' . $c->title . ($sc !== null ? '  (' . $sc . ')' : '');
                }
            }
            // 冲突必须可见：静默忽略一个快捷键，用户会以为插件坏了
            foreach ($this->shell->pluginConflictsOf($id) as $info) {
                $lines[] = '     ⚠ ' . $this->conflictText($info);
            }
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
        $longest = 0;
        foreach ($this->contentLines() as $l) {
            $longest = max($longest, DisplayWidth::dispWidth($l));
        }
        $this->panelW = max(3, min($vpW - 2, $longest + 4));
        $this->panelH = max(3, min($vpH - 2, $this->contentLineCount() + 2));

        $innerW = max(0, $this->panelW - 2);
        $innerH = max(0, $this->panelH - 2);
        $this->scroll = max(0, min($this->scroll, max(0, $this->contentLineCount() - $innerH)));
        $slice = array_slice($this->contentLines(), $this->scroll, $innerH);

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

<?php
declare(strict_types=1);

namespace App\Panel;

use App\App;
use App\Core\LayoutFactory;
use App\Text\DisplayWidth;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Style\Modifier;
use PhpTui\Tui\Widget\Widget;

/**
 * 顶部菜单栏（用户从 Backlog 提出，2026-08-31 接入）。
 *
 * ## 激活方式（先验证过、不臆测）
 * 真实 pty 探针（tests/pty_keyprobe.php）确认：
 *  · **Alt+字母不可用**——`ESC f` 被解析成 `CharKeyEvent('f')` 且 modifiers=0，
 *    Alt 修饰位丢失，与直接按 f 无法区分。故不采用 `Alt+F` 这种传统菜单热键。
 *  · **F10 / F9 / F1 可用**（FunctionKeyEvent，代码名 F10/F9/F1）。
 *  · 鼠标点击菜单标签可激活（M2 起点击已贯穿全应用）。
 * 故激活入口是 **F10 + 点击**，与「编辑器/终端/AI 输入框」是互相不冲突的全局键。
 *
 * ## 不进 PANELS 焦点循环
 * 菜单栏是全局控件，不是焦点面板：Tab 在 sidebar/editor/terminal/ai_stream/ai_input
 * 间循环，不会停到菜单栏（否则 Tab 行为变怪）。App::focusPanel() 的枚举不含 'menu'。
 * 激活靠 F10 或点击，关闭后焦点回到原面板。
 *
 * ## 下拉是覆盖层
 * 与 HelpPanel 同机制：App::render() 在菜单打开时把下拉做成 CompositeWidget 浮到
 * 主区之上（底层 UI 仍可见）。下拉只画在菜单栏正下方、当前菜单对齐的左边界。
 *
 * ## 只挂真实存在的命令
 * 每个菜单项的 action 都对应 App 里一个真实方法（见 App::menuAction），没有空壳项。
 */
final class MenuBarPanel
{
    private bool $open = false;

    /** 当前激活的菜单下标（打开时高亮、下拉显示它） */
    private int $active = 0;

    /** 当前菜单内高亮的条目下标 */
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
        $this->active = $this->active; // 保留上次激活的菜单
        $this->sel = 0;
        $this->clampSelection();       // 插件组数量可变，打开时先把下标夹回合法范围
    }

    public function close(): void
    {
        $this->open = false;
        $this->sel = 0;
    }

    /** F10：激活菜单栏（若已开则先关，符合"再按一次收起"直觉） */
    public function toggle(): void
    {
        $this->open ? $this->close() : $this->open();
    }

    // ── 菜单定义（单一真源；i18n key 在语言包里）──

    /**
     * @return array<int,array{label:string,items:array<int,array{label:string,action:string,shortcut:string}>}>
     */
    public function definitions(): array
    {
        $t = fn(string $k): string => $this->shell->t($k);
        $defs = [
            [
                'label' => $t('menu.file'),
                'items' => [
                    ['label' => $t('menu.file_open'),   'action' => 'file.open',   'shortcut' => ''],
                    ['label' => $t('menu.file_save'),   'action' => 'file.save',   'shortcut' => 'Ctrl+S'],
                    ['label' => $t('menu.file_close'),  'action' => 'file.close',  'shortcut' => 'Ctrl+W'],
                    ['label' => $t('menu.file_plugins'),'action' => 'plugins.open', 'shortcut' => ''],
                    ['label' => $t('menu.file_quit'),   'action' => 'file.quit',   'shortcut' => 'Ctrl+Q'],
                ],
            ],
            [
                'label' => $t('menu.view'),
                'items' => [
                    ['label' => $t('menu.view_theme'),    'action' => 'view.theme',    'shortcut' => 'Ctrl+T'],
                    ['label' => $t('menu.view_focus_editor'),  'action' => 'view.focus.editor',  'shortcut' => 'Tab'],
                    ['label' => $t('menu.view_focus_terminal'),'action' => 'view.focus.terminal','shortcut' => 'Tab'],
                    ['label' => $t('menu.view_focus_explorer'),'action' => 'view.focus.explorer','shortcut' => 'Tab'],
                    ['label' => $t('menu.view_focus_ai'),     'action' => 'view.focus.ai',     'shortcut' => 'Tab'],
                    ['label' => $t('menu.command_palette'),    'action' => 'palette.open',      'shortcut' => 'F1'],
                    ['label' => $t('menu.view_lang'),     'action' => 'view.lang',     'shortcut' => ''],
                ],
            ],
            [
                'label' => $t('menu.terminal'),
                'items' => [
                    ['label' => $t('menu.term_cancel'), 'action' => 'term.cancel', 'shortcut' => 'Ctrl+C'],
                    ['label' => $t('menu.term_clear'),  'action' => 'term.clear',  'shortcut' => ''],
                ],
            ],
            [
                'label' => $t('menu.help'),
                'items' => [
                    ['label' => $t('menu.help_shortcuts'), 'action' => 'help.shortcuts', 'shortcut' => '?'],
                    ['label' => $t('menu.help_about'),     'action' => 'help.about',     'shortcut' => ''],
                ],
            ],
        ];
        // V1.1：插件命令组**只追加在末尾** —— 系统 4 组的下标与项序一概不变，
        // 下面那些按 $this->active/$this->sel 取数组的地方才不会错位。
        $pluginItems = $this->shell->pluginMenuItems();
        if ($pluginItems !== []) {
            $defs[] = ['label' => $t('menu.plugins'), 'items' => $pluginItems];
        }
        return $defs;
    }

    /** 当前激活菜单的条目列表（带兜底，避免越界取到 undefined index） */
    private function activeItems(): array
    {
        $defs = $this->definitions();
        return $defs[$this->active]['items'] ?? [];
    }

    /**
     * 把 active / sel 夹回合法范围。
     * 插件组是运行时追加的（数量可变），菜单已打开时若条目数变化，旧下标可能越界。
     */
    private function clampSelection(): void
    {
        $defs = $this->definitions();
        $n = count($defs);
        $this->active = $n === 0 ? 0 : max(0, min($n - 1, $this->active));
        $items = $this->activeItems();
        $this->sel = $items === [] ? 0 : max(0, min(count($items) - 1, $this->sel));
    }

    /** 第 $i 个菜单在菜单栏里的起始 x 列（用于下拉对齐与鼠标命中） */
    public function menuStartX(int $i): int
    {
        $x = 0;
        $defs = $this->definitions();
        for ($j = 0; $j < $i; $j++) {
            $x += DisplayWidth::dispWidth(' ' . $defs[$j]['label'] . ' ');
        }
        return $x;
    }

    /** 第 $i 个菜单占用的宽度（列） */
    public function menuWidth(int $i): int
    {
        $defs = $this->definitions();
        return DisplayWidth::dispWidth(' ' . $defs[$i]['label'] . ' ');
    }

    // ── 键盘 ────────────────────────────────────────

    /**
     * 菜单打开时消费按键。返回 true 表示已消费（App::handle 据此不再往下分发）。
     */
    public function onKey(CodedKeyEvent $e): bool
    {
        if (!$this->open) {
            return false;
        }
        $this->clampSelection();
        $defs = $this->definitions();
        $nMenus = count($defs);
        switch ($e->code) {
            case \PhpTui\Term\KeyCode::Esc:
                $this->close();
                return true;
            case \PhpTui\Term\KeyCode::Left:
                $this->active = ($this->active - 1 + $nMenus) % $nMenus;
                $this->sel = 0;
                return true;
            case \PhpTui\Term\KeyCode::Right:
                $this->active = ($this->active + 1) % $nMenus;
                $this->sel = 0;
                return true;
            case \PhpTui\Term\KeyCode::Up:
                $items = $this->activeItems();
                if (count($items) > 0) {
                    $this->sel = ($this->sel - 1 + count($items)) % count($items);
                }
                return true;
            case \PhpTui\Term\KeyCode::Down:
                $items = $this->activeItems();
                if (count($items) > 0) {
                    $this->sel = ($this->sel + 1) % count($items);
                }
                return true;
            case \PhpTui\Term\KeyCode::Enter:
                $this->runActive();
                return true;
        }
        return false;
    }

    /** 执行当前高亮的菜单项 */
    public function runActive(): void
    {
        $defs = $this->definitions();
        $item = $this->activeItems()[$this->sel] ?? null;
        if ($item !== null) {
            $this->shell->menuAction($item['action']);
        }
        $this->close();
    }

    // ── 鼠标 ────────────────────────────────────────

    /**
     * 点击菜单栏区域：命中某菜单标签则激活它并重置选择；已开且点同一标签则关闭。
     * 返回 true 表示已消费。
     */
    public function clickBar(int $x): bool
    {
        $defs = $this->definitions();
        foreach ($defs as $i => $_) {
            $start = $this->menuStartX($i);
            $w = $this->menuWidth($i);
            if ($x >= $start && $x < $start + $w) {
                if ($this->open && $this->active === $i) {
                    $this->close();
                } else {
                    $this->open = true;
                    $this->active = $i;
                    $this->sel = 0;
                }
                return true;
            }
        }
        return false;
    }

    /**
     * 命中测试：给定视口坐标，返回对应的菜单项下标；未命中（点面板外 / 顶边框空白行）返回 null。
     * 下拉面板顶边框在绝对行 $top（菜单栏下方一行），条目从 $top+1 行起（跳过顶边框），
     * 故命中行须与绘制一致地 +1，否则会出现「点第一项却命中第二项」的 off-by-one。
     * @param int $x 相对视口左边界的列；@param int $y 相对视口顶端的行
     */
    public function dropdownHit(int $x, int $y): ?int
    {
        if (!$this->open) {
            return null;
        }
        $startX = $this->menuStartX($this->active);
        $defs = $this->definitions();
        $items = $this->activeItems();
        $top = 1;                 // 菜单栏 row0 下方：面板顶边框所在绝对行
        $itemsTop = $top + 1;    // 跳过面板顶边框，条目从第 2 行起
        $itemW = $this->dropdownWidth();
        if ($x >= $startX && $x < $startX + $itemW
            && $y >= $itemsTop && $y < $itemsTop + count($items)) {
            return $y - $itemsTop;
        }
        return null;
    }

    /**
     * 下拉打开时点击：命中某条目则执行；点下拉空白（含顶边框空行）则关闭。返回 true 表示已消费。
     * @param int $x 相对视口左边界的列；@param int $y 相对视口顶端的行
     */
    public function clickDropdown(int $x, int $y): bool
    {
        if (!$this->open) {
            return false;
        }
        $hit = $this->dropdownHit($x, $y);
        if ($hit !== null) {
            $this->sel = $hit;
            $this->runActive();
            return true;
        }
        // 点下拉之外（含面板顶边框空白行）：关闭（点其它菜单标签由 clickBar 处理，这里只关）
        $this->close();
        return true;
    }

    // ── 下拉尺寸 ────────────────────────────────────

    private function dropdownWidth(): int
    {
        $defs = $this->definitions();
        $items = $this->activeItems();
        $w = 0;
        foreach ($items as $it) {
            $line = ' ' . $it['label'] . '  ' . $it['shortcut'] . ' ';
            $w = max($w, DisplayWidth::dispWidth($line));
        }
        return max(3, $w);
    }

    // ── 渲染 ────────────────────────────────────────

    /** 菜单栏那一行的渲染（交给 App::build 的 root Grid 第一个 widget） */
    public function content(Area $area): Widget
    {
        $innerW = max(0, $area->width);
        $defs = $this->definitions();
        $spans = [];
        foreach ($defs as $i => $m) {
            $text = ' ' . $m['label'] . ' ';
            // 高亮项用 REVERSED（反显）而非改前景色——这是菜单高亮的通行做法，
            // 也不依赖再配一套背景色。menuActive/menuSel 只作兜底前景。
            $style = \PhpTui\Tui\Style\Style::default()
                ->fg($this->shell->theme->color('menuInactive'));
            if ($this->open && $this->active === $i) {
                $style = \PhpTui\Tui\Style\Style::default()
                    ->fg($this->shell->theme->color('menuActive'))
                    ->addModifier(Modifier::REVERSED);
            }
            $spans[] = \PhpTui\Tui\Text\Span::styled(
                DisplayWidth::mbCutDisp($text, $innerW),
                $style
            );
            $innerW -= DisplayWidth::dispWidth($text);
            if ($innerW <= 0) {
                break;
            }
        }
        $line = \PhpTui\Tui\Text\Line::fromSpans(...$spans);
        return \PhpTui\Tui\Extension\Core\Widget\ParagraphWidget::fromLines($line);
    }

    /**
     * 下拉覆盖层（菜单打开时由 App::render 用 CompositeWidget 浮到主区之上）。
     *
     * 透明叠加：只把面板画到 (startX, top) 起的子区域，其余缓冲不动，底层 UI 自然透出。
     * 早期实现用「全视口 Grid + 空 spacer 定位」，但 php-tui 的 GridWidget 渲染时先建
     * 空白子缓冲再 putBuffer 拷回，会把底层 UI 整块抹成空白（即「遮罩全屏」的 bug）。
     * 故改由 DropdownOverlay 仅画面板子区域、不清屏。
     * 极小视口（高度放不下边框）不画，避免 0 高 area。
     */
    public function dropdownWidget(int $vpW, int $vpH): Widget
    {
        if ($vpH < 2) {
            return \PhpTui\Tui\Extension\Core\Widget\ParagraphWidget::fromString('');
        }
        $startX = $this->menuStartX($this->active);
        $defs = $this->definitions();
        $items = $this->activeItems();
        $contentW = $this->dropdownWidth();   // 下拉内容（含内边距）的宽度
        $contentH = count($items);            // 条目数
        // ⚠️ BlockWidget 的边框要占 2 列 + 2 行，故面板尺寸必须算上边框，
        // 否则边框会把内容区挤成 0 高/0 宽（实测只画出空边框 ┌──┐，条目文字全没了）。
        $panelW = $contentW + 2;
        $panelH = $contentH + 2;

        $top = 1; // 第 0 行是菜单栏，不盖
        // 视口太矮放不下下拉（含边框）就不画，避免约束溢出崩界面
        if ($vpH < $top + $panelH) {
            return \PhpTui\Tui\Extension\Core\Widget\ParagraphWidget::fromString('');
        }
        // 水平越界则左移夹紧，保证面板完整可见（不画到视口外）
        $startX = max(0, min($startX, max(0, $vpW - $panelW)));

        $panel = $this->buildDropdown($items);

        return new \App\Widget\DropdownOverlay($panel, $startX, $top, $panelW, $panelH);
    }

    private function buildDropdown(array $items): Widget
    {
        $innerW = max(0, $this->dropdownWidth());   // 内容宽度（BlockWidget 已单独算边框）
        $lines = [];
        foreach ($items as $i => $it) {
            $hit = $this->open && $this->sel === $i;
            $text = ' ' . $it['label'] . '  ' . $it['shortcut'] . ' ';
            $style = \PhpTui\Tui\Style\Style::default()
                ->fg($this->shell->theme->color('menuDrop'));
            if ($hit) {
                $style = $style->fg($this->shell->theme->color('menuSel'))
                    ->addModifier(Modifier::REVERSED);
            }
            $lines[] = \PhpTui\Tui\Text\Line::fromSpans(
                \PhpTui\Tui\Text\Span::styled(DisplayWidth::mbCutDisp($text, $innerW), $style)
            );
        }
        while (count($lines) < 1) {
            $lines[] = \PhpTui\Tui\Text\Line::fromSpans(\PhpTui\Tui\Text\Span::styled('', \PhpTui\Tui\Style\Style::default()));
        }
        return \PhpTui\Tui\Extension\Core\Widget\BlockWidget::default()
            ->borders(\PhpTui\Tui\Widget\Borders::ALL)
            ->borderType(\PhpTui\Tui\Widget\BorderType::Plain)
            ->widget(\PhpTui\Tui\Extension\Core\Widget\ParagraphWidget::fromLines(...$lines));
    }
}

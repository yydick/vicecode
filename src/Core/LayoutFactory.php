<?php
declare(strict_types=1);

namespace App\Core;

use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Layout\Layout;
use PhpTui\Tui\Widget\Direction;

use App\Core\LayoutConfig;

/**
 * 布局的唯一真相：既负责切出各面板的矩形，也提供组装 Widget 用的同批约束。
 *
 * 拆自 App 时顺手修掉一个已知问题：原先 `areas()` 切矩形、 `build()` 组装 GridWidget
 * 各写了一份 constraints，改布局必须两处同步（plan/MILESTONES.md 需求池已记录）。
 * 现在两边都取这里的约束方法，只可能有一份。
 *
 * 约束用方法而非类常量返回：PHP 的类常量不允许包含 `new`（Constraint 是对象），
 * 即使 PHP 8.1 起 new 已可用于参数默认值，类常量仍要求纯常量表达式。
 *
 * M6 规划的「可拖拽分隔条调整面板宽度」(R5) 已落地：约束取值来自 LayoutConfig，
 * 拖拽分隔条时改 Config，这里切出的矩形与 build() 组装的 Grid 自动同步。
 */
final class LayoutFactory
{
    /**
     * 外层：菜单行 1 + 主区 + 状态栏 1。 @return Constraint[]
     *
     * ⚠️ 视口太矮时不画菜单行：三段里主区是 min(1)，高度 < 5 时会被挤成 0 高，
     * php-tui 往 0 高 area 里写会抛 OutOfBoundsException。此时宁可不要菜单栏，
     * 也不能让整个界面崩掉。
     */
    public static function rootConstraints(int $viewportHeight = 1000): array
    {
        if ($viewportHeight < self::MIN_HEIGHT_WITH_MENU) {
            return [Constraint::min(1), Constraint::length(1)];
        }
        return [Constraint::length(1), Constraint::min(1), Constraint::length(1)];
    }

    /** 低于这个高度就不显示菜单栏（见 rootConstraints 的说明） */
    public const MIN_HEIGHT_WITH_MENU = 5;

    public static function hasMenuBar(int $viewportHeight): bool
    {
        return $viewportHeight >= self::MIN_HEIGHT_WITH_MENU;
    }

    /**
     * 主区**可见段**的 key 序列：`['sidebar'?, 'center', 'ai'?]`（center 恒在，至少一段）。
     *
     * ⚠️ 这是「键与约束按下标对偶」的那一半：`mainConstraints()` 与 `split()`、`build()`
     * 全部按它遍历，因此隐藏一块面板时**不会再出现索引移位** —— 旧实现用定位索引
     * （`$main->get(1)` 当中间列、`$main->get(2)` 当 AI 列），一隐藏侧栏就把 AI 的矩形
     * 当成中间列，整个界面错位（`BUGFIXES` B17 的根因）。
     * @return list<string>
     */
    public static function mainKeys(LayoutConfig $c): array
    {
        $keys = [];
        if ($c->sidebarVisible) {
            $keys[] = 'sidebar';
        }
        $keys[] = 'center';
        if ($c->aiVisible) {
            $keys[] = 'ai';
        }
        return $keys;
    }

    /** 主区约束，长度与 mainKeys() 一一对应。 @return Constraint[] */
    public static function mainConstraints(LayoutConfig $c): array
    {
        $out = [];
        foreach (self::mainKeys($c) as $k) {
            $out[] = match ($k) {
                'sidebar' => Constraint::length($c->sidebarWidth),
                'ai' => Constraint::length($c->aiWidth),
                // 中间列是自适应段：侧栏 + AI 再宽也不能把它挤成 0
                default => Constraint::min(LayoutConfig::MIN_CENTER),
            };
        }
        return $out;
    }

    /**
     * 中列**可见段**的 key 序列：
     *  - 终端最大化 → `['terminal']`（编辑器让位，但左右两列保留）；
     *  - 终端被隐藏 → `['editor']`；
     *  - 否则 `['editor', 'terminal']`（按 editorRatio 上下分）。
     *
     * 中列至少保留一段：最大化隐含「终端可见」，隐藏终端时编辑器顶上来。
     * @return list<string>
     */
    public static function centerKeys(LayoutConfig $c): array
    {
        if ($c->terminalMaximized && $c->terminalVisible) {
            return ['terminal'];
        }
        return $c->terminalVisible ? ['editor', 'terminal'] : ['editor'];
    }

    /** 中列约束，长度与 centerKeys() 一一对应。 @return Constraint[] */
    public static function centerConstraints(LayoutConfig $c): array
    {
        if (count(self::centerKeys($c)) === 1) {
            return [Constraint::min(1)];   // 单段吃满中列
        }
        $editor = (int) round($c->editorRatio * 100);
        return [Constraint::percentage($editor), Constraint::percentage(100 - $editor)];
    }

    /** AI 列：消息流吃剩余 / 输入框固定高度（来自可变 LayoutConfig）。 @return Constraint[] */
    public static function aiConstraints(LayoutConfig $c): array
    {
        return [Constraint::min(LayoutConfig::MIN_AI_STREAM), Constraint::length($c->aiInputHeight)];
    }

    /**
     * 切出**当前可见**面板的绝对坐标矩形（命中测试与渲染共用）。
     * 约束取 LayoutConfig，与 build() 共用同一份可变配置——这就是 R5 拖拽只改 Config、
     * 渲染与命中即同步生效的关键。
     *
     * 返回数组里**没有**被隐藏的面板键；`build()` 用同一对 keys 组装，段数天然一致。
     * @return array<string,Area>
     */
    public static function split(Area $vp, LayoutConfig $c): array
    {
        $root = Layout::default()
            ->constraints(self::rootConstraints($vp->height))
            ->direction(Direction::Vertical)
            ->split($vp);

        // 有菜单栏时 root 是 3 段（menu/main/status），否则是 2 段（main/status）
        $withMenu = self::hasMenuBar($vp->height);

        // 主区：按 mainKeys 逐段配对，而不是拿定位索引去取
        $main = Layout::default()
            ->constraints(self::mainConstraints($c))
            ->direction(Direction::Horizontal)
            ->split($root->get($withMenu ? 1 : 0));
        $mainRects = [];
        foreach (self::mainKeys($c) as $i => $k) {
            $mainRects[$k] = $main->get($i);
        }

        $areas = [];
        // 顺序与加显隐之前保持一致（sidebar / editor / terminal / ai_* / status / menu），
        // 只是隐藏的键不再出现 —— 依赖键集的测试（areas()['editor'] 等）不受影响。
        if (isset($mainRects['sidebar'])) {
            $areas['sidebar'] = $mainRects['sidebar'];
        }

        $centerKeys = self::centerKeys($c);
        $center = Layout::default()
            ->constraints(self::centerConstraints($c))
            ->direction(Direction::Vertical)
            ->split($mainRects['center']);
        foreach ($centerKeys as $i => $k) {
            $areas[$k] = $center->get($i);
        }

        if (isset($mainRects['ai'])) {
            $ai = Layout::default()
                ->constraints(self::aiConstraints($c))
                ->direction(Direction::Vertical)
                ->split($mainRects['ai']);
            $areas['ai_stream'] = $ai->get(0);
            $areas['ai_input'] = $ai->get(1);
        }

        $areas['status'] = $root->get($withMenu ? 2 : 1);
        if ($withMenu) {
            $areas['menu'] = $root->get(0);
        }
        return $areas;
    }
}

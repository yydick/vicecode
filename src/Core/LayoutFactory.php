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

    /** 主区：侧栏 / 中间自适应 / AI，宽度均来自可变 LayoutConfig。 @return Constraint[] */
    public static function mainConstraints(LayoutConfig $c): array
    {
        return [Constraint::length($c->sidebarWidth), Constraint::min(LayoutConfig::MIN_CENTER), Constraint::length($c->aiWidth)];
    }

    /** 中列：编辑器按比例 / 终端吃剩余。 @return Constraint[] */
    public static function centerConstraints(LayoutConfig $c): array
    {
        $editor = (int) round($c->editorRatio * 100);
        return [Constraint::percentage($editor), Constraint::percentage(100 - $editor)];
    }

    /** AI 列：消息流吃剩余 / 输入框固定高度（来自可变 LayoutConfig）。 @return Constraint[] */
    public static function aiConstraints(LayoutConfig $c): array
    {
        return [Constraint::min(LayoutConfig::MIN_AI_STREAM), Constraint::length($c->aiInputHeight)];
    }

    /**
     * 切出六个面板的绝对坐标矩形（命中测试与渲染共用）。
     * 约束取 LayoutConfig，与 build() 共用同一份可变配置——这就是 R5 拖拽只改 Config、
     * 渲染与命中即同步生效的关键。
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
        $menu = $withMenu ? $root->get(0) : null;
        $status = $root->get($withMenu ? 2 : 1);

        $main = Layout::default()
            ->constraints(self::mainConstraints($c))
            ->direction(Direction::Horizontal)
            ->split($root->get($withMenu ? 1 : 0));
        $sidebar = $main->get(0);

        $center = Layout::default()
            ->constraints(self::centerConstraints($c))
            ->direction(Direction::Vertical)
            ->split($main->get(1));
        $editor = $center->get(0);
        $terminal = $center->get(1);

        $ai = Layout::default()
            ->constraints(self::aiConstraints($c))
            ->direction(Direction::Vertical)
            ->split($main->get(2));
        $aiStream = $ai->get(0);
        $aiInput = $ai->get(1);

        $areas = [
            'sidebar' => $sidebar,
            'editor' => $editor,
            'terminal' => $terminal,
            'ai_stream' => $aiStream,
            'ai_input' => $aiInput,
            'status' => $status,
        ];
        if ($menu !== null) {
            $areas['menu'] = $menu;
        }
        return $areas;
    }
}

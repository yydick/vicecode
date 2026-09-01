<?php
declare(strict_types=1);

namespace App\Core;

use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Layout\Layout;
use PhpTui\Tui\Widget\Direction;

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
 * M6 打算做「可拖拽分隔条调整面板宽度」，届时只需改这里的约束取值。
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

    /** 主区：侧栏 30 列 / 中间自适应 / AI 45 列。 @return Constraint[] */
    public static function mainConstraints(): array
    {
        return [Constraint::length(30), Constraint::min(10), Constraint::length(45)];
    }

    /** 中列：编辑器 60% / 终端 40%。 @return Constraint[] */
    public static function centerConstraints(): array
    {
        return [Constraint::percentage(60), Constraint::percentage(40)];
    }

    /** AI 列：消息流 75% / 输入框 3 行。 @return Constraint[] */
    public static function aiConstraints(): array
    {
        return [Constraint::percentage(75), Constraint::length(3)];
    }

    /**
     * 切出六个面板的绝对坐标矩形（命中测试与渲染共用）。
     * @return array<string,Area>
     */
    public static function split(Area $vp): array
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
            ->constraints(self::mainConstraints())
            ->direction(Direction::Horizontal)
            ->split($root->get($withMenu ? 1 : 0));
        $sidebar = $main->get(0);

        $center = Layout::default()
            ->constraints(self::centerConstraints())
            ->direction(Direction::Vertical)
            ->split($main->get(1));
        $editor = $center->get(0);
        $terminal = $center->get(1);

        $ai = Layout::default()
            ->constraints(self::aiConstraints())
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

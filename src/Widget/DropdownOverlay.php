<?php
declare(strict_types=1);

namespace App\Widget;

use Closure;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Display\Buffer;
use PhpTui\Tui\Extension\Core\Widget\ClosureRenderer;
use PhpTui\Tui\Widget\Widget;
use PhpTui\Tui\Widget\WidgetRenderer;

/**
 * 透明下拉覆盖层（菜单栏子菜单用）。
 *
 * php-tui 的 GridWidget 渲染每个子区域时会先用 Buffer::empty 建空白子缓冲再 putBuffer
 * 拷回，因此「全视口 Grid + 空 spacer 定位」会把底层 UI 整块抹成空白（即「遮罩全屏」）。
 * 为避免清屏，本 Widget 只把面板画到 (startX, top) 起的子区域，其余缓冲保持原样
 * （底层 UI 自然透出）。渲染逻辑见 self::renderer()。
 */
final class DropdownOverlay implements Widget
{
    public function __construct(
        /** 下拉面板（通常是带边框的 BlockWidget） */
        public Widget $panel,
        /** 相对覆盖层左上角的起始列（=菜单栏内该菜单的 menuStartX） */
        public int $startX,
        /** 相对覆盖层左上角的起始行（菜单栏下方，通常为 1） */
        public int $top,
        /** 面板宽（含边框） */
        public int $panelW,
        /** 面板高（含边框） */
        public int $panelH,
    ) {
    }

    /**
     * 配套渲染器：仅把面板画进子区域，不触碰其余缓冲（透明叠加）。
     * 通过 DisplayBuilder::addWidgetRenderer() 注册一次即可；非本类型 Widget 直接跳过，
     * 不影响其它 widget 的既有渲染。
     */
    public static function renderer(): WidgetRenderer
    {
        /** @var Closure(WidgetRenderer, Widget, Buffer, Area): void $fn */
        $fn = static function (WidgetRenderer $renderer, Widget $widget, Buffer $buffer, Area $area): void {
            if (!$widget instanceof self) {
                return;
            }
            // 覆盖层被 CompositeWidget 以「与底层相同的整块区域」渲染，故 area 即整视口。
            $sub = Area::fromScalars(
                $area->left() + $widget->startX,
                $area->top() + $widget->top,
                $widget->panelW,
                $widget->panelH
            );
            $renderer->render($renderer, $widget->panel, $buffer, $sub);
        };
        return new ClosureRenderer($fn);
    }
}

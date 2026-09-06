<?php
declare(strict_types=1);

/**
 * 菜单下拉覆盖层回归测试（headless）。
 *
 * 复盖：
 *  - 下拉面板只画在 (menuStartX, 1) 起的子区域，底层 UI（菜单栏 row0 + 侧栏/编辑器 rows 9+）
 *    仍可见 —— 防止早期「全视口 Grid + 空 spacer 定位」把底层整块抹成空白的「遮罩全屏」bug 回归。
 *  - 下拉左边界对齐到对应菜单的 menuStartX（active=0/1/3 三种）。
 *
 * 运行：php tests/menu_dropdown.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\App;
use App\Widget\DropdownOverlay;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Display\Backend\DummyBackend;
use PhpTui\Tui\DisplayBuilder;
use PhpTui\Tui\Widget\Widget;

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

/** 渲染 App（菜单打开、指定 active）成去 ANSI 的文本网格。 */
function renderMenu(int $active): array
{
    $app = new App();
    $app->menuBar->open();
    $ref = new ReflectionProperty($app->menuBar, 'active');
    $ref->setAccessible(true);
    $ref->setValue($app->menuBar, $active);

    $vp = Area::fromDimensions(120, 40);
    $backend = new DummyBackend(120, 40);
    $disp = DisplayBuilder::default($backend)
        ->fullscreen()
        ->addWidgetRenderer(DropdownOverlay::renderer())
        ->build();
    $disp->draw($app->render($vp));
    $clean = preg_replace('/\x1B\[[0-9;?]*[ -\/]*[@-~]/', '', $backend->toString());
    return explode("\n", rtrim($clean, "\n"));
}

echo "== 菜单下拉覆盖层 ==\n";

foreach ([0, 1, 3] as $active) {
    $lines = renderMenu($active);
    $sx = (new App())->menuBar->menuStartX($active);

    // 1) 下拉左边界（┌）对齐到 menuStartX（row 1）
    $row1 = $lines[1] ?? '';
    check(
        mb_substr($row1, $sx, 1) === '┌',
        "active=$active 下拉框左边界对齐 menuStartX($sx) (row1[col$sx]=┌)"
    );

    // 2) 菜单栏 row0 仍可见（未被遮罩）—— 含菜单标签首字「文」（菜单栏逐字间隔渲染，
    //    故「文件」非连续子串，用单字「文」判定即可）
    $row0 = $lines[0] ?? '';
    check(
        mb_strpos($row0, '文') !== false,
        "active=$active 菜单栏 row0 仍可见（含菜单标签，未被遮罩）"
    );

    // 3) 底层 UI（侧栏/编辑器）在 rows 9+ 仍可见（回归：早期会被整块抹空）
    $baseVisible = false;
    for ($i = 9; $i < 22; $i++) {
        if (trim(mb_substr($lines[$i] ?? '', 0, 120)) !== '') {
            $baseVisible = true;
            break;
        }
    }
    check($baseVisible, "active=$active 底层 UI 在 rows 9+ 仍可见（未被遮罩全屏）");
}

/** 构造打开且 active 设为 $active 的菜单栏（供点击命中测试）。 */
function openMenuBar(int $active): \App\Panel\MenuBarPanel
{
    $app = new App();
    $app->menuBar->open();
    $ref = new ReflectionProperty($app->menuBar, 'active');
    $ref->setAccessible(true);
    $ref->setValue($app->menuBar, $active);
    return $app->menuBar;
}

echo "== 下拉点击命中映射（off-by-one 回归）==\n";
foreach ([0, 1, 3] as $active) {
    $mb = openMenuBar($active);
    $sx = $mb->menuStartX($active);
    $n = count($mb->definitions()[$active]['items']);
    $itemsTop = 2; // 菜单栏 row0 + 面板顶边框 row1 → 条目从第 2 行起
    // 每个条目行应命中对应下标（绘制与点击坐标一致）
    for ($i = 0; $i < $n; $i++) {
        $hit = $mb->dropdownHit($sx + 2, $itemsTop + $i);
        check($hit === $i, "active=$active 点第 $i 项（row " . ($itemsTop + $i) . "）命中下标 $i");
    }
    // 面板顶边框空行（row1）不应命中任何项（此前 off-by-one 会误选中第 0 项）
    check($mb->dropdownHit($sx + 2, 1) === null, "active=$active 点面板顶边框空行（row1）不命中任何项");
    // 末项之下越界行不命中
    check($mb->dropdownHit($sx + 2, $itemsTop + $n) === null, "active=$active 点末项之下（row " . ($itemsTop + $n) . "）不命中");
    // 面板左边界之外不命中
    check($mb->dropdownHit($sx - 1, $itemsTop) === null, "active=$active 点面板左边界外不命中");
}

echo $failed ? "\nmenu_dropdown FAIL\n" : "\nmenu_dropdown PASS\n";
exit($failed ? 1 : 0);

<?php
declare(strict_types=1);

/**
 * 探索性探针（不参与 tools/run_tests.sh 验收）：侧栏树「横滚后点三角」命中。
 *
 * 行文本渲染用 mbSubDisp($text, hScroll, innerW) —— 屏幕列 = 文本列 - hScroll；
 * 而 hitArrow() 用 sb.x+3+2*depth，**没减 hScroll**。故 hScroll>0 时点三角会错位。
 *
 * 运行：php tests/probe_tree_hscroll.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\App;
use PhpTui\Term\Event\MouseEvent;
use PhpTui\Term\MouseButton;
use PhpTui\Term\MouseEventKind;
use PhpTui\Tui\Display\Area;

$vp = Area::fromDimensions(120, 40);

// 造测试根目录：dir_long_名字超长/ 下再放一个子目录，保证 maxHScroll 远大于 innerW
$root = sys_get_temp_dir() . '/vc_probe_tree_' . getmypid();
$longName = 'd_' . str_repeat('abcdefghij', 12); // 122 字符
mkdir($root . '/' . $longName . '/inner', 0777, true);
file_put_contents($root . '/' . $longName . '/inner/x.txt', "x\n");

$old = getcwd();
chdir($root);

/**
 * 建一个 App：展开到指定 depth 的目录，渲染一帧把 maxHScroll 算出来，再设 hScroll。
 */
$mk = static function (int $hScroll) use ($vp): array {
    $app = new App();
    $tree = $app->sidebar->tree();
    $sb = $app->areas($vp)['sidebar'];
    $target = null;
    $idx = null;
    // 展开 longName（depth 0），露出 inner（depth 1）
    foreach ($tree->roots as $n) {
        if ($n->name === $GLOBALS['longName'] && $n->isDir) {
            $n->ensureChildren();
            $n->expanded = true;
        }
    }
    foreach ($tree->visible() as $i => $n) {
        if ($n->name === 'inner' && $n->isDir) {
            $target = $n;
            $idx = $i;
        }
    }
    $app->sidebar->content($sb, true);   // 先渲染一帧，算出 maxHScroll
    $app->sidebar->hScroll = $hScroll;
    $app->sidebar->content($sb, true);   // 再渲染，钳制生效
    return [$app, $tree, $target, $idx, $sb];
};

$GLOBALS['longName'] = $longName;

/** 点侧栏第 $colOff 列、第 $idx 行，返回 inner 是否被展开 */
$click = static function (int $hScroll, int $colOff, ?int $idx = null) use ($mk, $vp): ?bool {
    [$app, $tree, $target, $foundIdx, $sb] = $mk($hScroll);
    if ($target === null) {
        return null;
    }
    $row = $sb->position->y + 3 + ($idx ?? $foundIdx);
    $app->handle(MouseEvent::new(MouseEventKind::Down, MouseButton::Left, $sb->position->x + $colOff, $row, 0), $vp);
    return $target->expanded;
};

echo "== 侧栏树横滚后点三角 ==\n";

// 基线：hScroll=0，depth=1 的三角在 sb.x+5
$r = $click(0, 5);
echo "  hScroll=0  点 +5  → inner expanded=" . var_export($r, true) . "（期望 true，回归基线）\n";
$r = $click(0, 7);
echo "  hScroll=0  点 +7  → inner expanded=" . var_export($r, true) . "（期望 false，名称区不 toggle）\n";

// 横滚 4 列：三角文本列 4 → 屏幕列 sb.x+1+4-4 = sb.x+1
[$app, $tree, $target, $idx, $sb] = $mk(4);
printf("  ---- hScroll=%d（钳制后），innerW=%d ----\n", $app->sidebar->hScroll, $sb->width - 2);

$r = $click(4, 1);
echo "  hScroll=4  点 +1  → inner expanded=" . var_export($r, true) . "（期望 true：三角实际在 +1）\n";
$r = $click(4, 2);
echo "  hScroll=4  点 +2  → inner expanded=" . var_export($r, true) . "（期望 true：命中区含其后 1 列）\n";
$r = $click(4, 5);
echo "  hScroll=4  点 +5  → inner expanded=" . var_export($r, true) . "（期望 false：那里已是名称区）\n";

chdir($old ?: '/');
exec('rm -rf ' . escapeshellarg($root));

// ── 追加：横滚后侧栏行文本是否丢内容（渲染层双重截断？）──
echo "\n== 横滚后侧栏行文本 ==\n";
$root2 = sys_get_temp_dir() . '/vc_probe_tree2_' . getmypid();
$long2 = 'd_' . str_repeat('0123456789', 8); // 82 字符
mkdir($root2 . '/' . $long2, 0777, true);
file_put_contents($root2 . '/' . $long2 . '/leaf.txt', "x\n");
chdir($root2);

use PhpTui\Tui\Display\Buffer as TuiBuffer;
use PhpTui\Tui\Extension\Core\CoreExtension;
use PhpTui\Tui\Widget\WidgetRenderer\AggregateWidgetRenderer;

$ext = new CoreExtension();
$renderers = [];
foreach ($ext->widgetRenderers() as $rr) {
    $renderers[] = $rr;
}
$renderer = new AggregateWidgetRenderer($renderers);

foreach ([0, 4, 10, 30, 50] as $hs) {
    $app2 = new App();
    $sb2 = $app2->areas($vp)['sidebar'];
    $app2->sidebar->content($sb2, true);
    $app2->sidebar->hScroll = $hs;
    $buf = TuiBuffer::empty($sb2);
    $renderer->render($renderer, $app2->sidebar->content($sb2, true), $buf, $buf->area());
    $lines = $buf->toLines();
    // 树行：tab 行 + 分隔线之后起，打印前几行便于定位
    $shown = array_map(static fn(string $l): string => trim($l), array_slice($lines, 0, 6));
    printf(
        "  设hScroll=%-3d 实际=%-3d maxH=%-3d innerW=%d 行=[%s]\n",
        $hs,
        $app2->sidebar->hScroll,
        $app2->sidebar->maxHScroll,
        $sb2->width - 2,
        implode(' | ', $shown)
    );
}

chdir($old ?: '/');
exec('rm -rf ' . escapeshellarg($root2));
echo "\nprobe 结束\n";

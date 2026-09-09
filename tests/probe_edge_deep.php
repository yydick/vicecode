<?php
declare(strict_types=1);

/**
 * 探索性探针（不参与 tools/run_tests.sh 验收，文件名含 probe 会被跳过）。
 *
 * A. 极长 cwd 挤状态栏：cwd 长度 × 视口宽度的组合扫描，看 assemble() 取舍。
 * B. 深层目录树（>20 层）：全展开后渲染，看缩进/名称/hScroll/命中列。
 *
 * 运行：php tests/probe_edge_deep.php
 */

require __DIR__ . '/../vendor/autoload.php';

putenv('APP_LOCALE=zh_CN');

use App\App;
use App\Text\DisplayWidth;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Display\Buffer as TuiBuffer;
use PhpTui\Tui\Extension\Core\CoreExtension;
use PhpTui\Tui\Widget\WidgetRenderer\AggregateWidgetRenderer;

function setCwd(App $app, string $cwd): void
{
    $p = new ReflectionProperty($app->terminal, 'cwd');
    $p->setAccessible(true);
    $p->setValue($app->terminal, $cwd);
}

// ── A. 极长 cwd ────────────────────────────────────────
echo "== A. 极长 cwd 挤状态栏 ==\n";
$app = new App();
$app->focus('terminal');

$cwds = [
    'short'      => '/home/yydick/works/tui',
    'len60'      => '/' . str_repeat('abcdefghij', 6),
    'len120'     => '/' . str_repeat('abcdefghij', 12),
    'len300'     => '/' . str_repeat('abcdefghij', 30),
    'len1000'    => '/' . str_repeat('abcdefghij', 100),
    'cjk60'      => '/' . str_repeat('目录', 30),
    'deep_path'  => '/' . implode('/', array_fill(0, 20, 'very_long_directory_name')),
];

foreach ($cwds as $label => $cwd) {
    setCwd($app, $cwd);
    foreach ([40, 80, 120, 200] as $w) {
        $r = $app->statusBar->assemble($w);
        $tw = DisplayWidth::dispWidth($r['text']);
        $seg = str_contains(implode(',', $r['dropped']), 'cwd') ? 'cwd被丢' : 'cwd保留';
        printf(
            "  %-10s cwd列宽=%-4d w=%-4d 文本列宽=%-4d %s dropped=[%s]\n",
            $label,
            DisplayWidth::dispWidth($cwd),
            $w,
            $tw,
            $tw > $w ? '⚠超宽!' : 'ok',
            $seg . ' ' . implode(',', $r['dropped'])
        );
    }
}

// 更细：只变 cwd 长度，固定 w=120，看「cwd 从保留到被丢」的临界点
echo "\n-- 固定 w=120，cwd 长度扫描（找临界点）--\n";
for ($len = 10; $len <= 130; $len += 10) {
    setCwd($app, '/' . str_repeat('a', $len - 1));
    $r = $app->statusBar->assemble(120);
    $hasCwd = !str_contains($r['text'], '…') ? '' : '';
    $keptCwd = !in_array('cwd', $r['dropped'], true);
    printf(
        "  cwd=%-4d kept=%-3s dropped=[%s] text=%s\n",
        $len,
        $keptCwd ? 'Y' : 'N',
        implode(',', $r['dropped']),
        $r['text']
    );
    unset($hasCwd);
}

// 极端：cwd 里带控制字符 / 换行
echo "\n-- cwd 含异常字符 --\n";
foreach (["/tmp/a\tb", "/tmp/a\nb", "/tmp/ab", "/tmp/\e[31mred"] as $i => $weird) {
    setCwd($app, $weird);
    $r = $app->statusBar->assemble(120);
    printf("  #%d text=%s (列宽=%d)\n", $i, json_encode($r['text'], JSON_UNESCAPED_SLASHES), DisplayWidth::dispWidth($r['text']));
}

// ── B. 深层目录树 ──────────────────────────────────────
echo "\n== B. 深层目录树 ==\n";

$root = sys_get_temp_dir() . '/vc_probe_deep_' . getmypid();
$deep = $root;
$levels = 30;
for ($i = 1; $i <= $levels; $i++) {
    $deep .= '/l' . $i;
}
if (!is_dir($deep)) {
    mkdir($deep, 0777, true);
}
file_put_contents($deep . '/leaf.txt', "leaf\n");
// 每层放一个兄弟文件，制造"目录+文件"混合
$cur = $root;
for ($i = 1; $i <= $levels; $i++) {
    $cur .= '/l' . $i;
    file_put_contents($cur . '/f' . $i . '.txt', "x\n");
}

$oldCwd = getcwd();
chdir($root);
$appT = new App();
$tree = $appT->sidebar->tree();

// 全展开
$expandAll = function (array $nodes) use (&$expandAll): void {
    foreach ($nodes as $n) {
        if ($n->isDir) {
            $n->ensureChildren();
            $n->expanded = true;
            $expandAll($n->children ?? []);
        }
    }
};
$expandAll($tree->roots);

$vis = $tree->visible();
printf("  可见节点数=%d，最深 depth=%d\n", count($vis), max(array_map(static fn($n) => $n->depth, $vis)));

$vp = Area::fromDimensions(120, 40);
$sb = $appT->areas($vp)['sidebar'];
printf("  侧栏区域 x=%d y=%d w=%d h=%d\n", $sb->position->x, $sb->position->y, $sb->width, $sb->height);

// 直接调用 content() 看行文本（不经过 buffer，方便看原始串）
$widget = $appT->sidebar->content($sb, true);
$ref = new ReflectionClass($widget);
printf("  content 返回 %s\n", $ref->getShortName());

// 走真实渲染拿屏幕文本
$ext = new CoreExtension();
$renderers = [];
foreach ($ext->widgetRenderers() as $r) {
    $renderers[] = $r;
}
$renderer = new AggregateWidgetRenderer($renderers);
$buffer = TuiBuffer::empty($vp);
$renderer->render($renderer, $appT->render($vp), $buffer, $buffer->area());
$lines = $buffer->toLines();
// 侧栏所在列范围
$sbX = $sb->position->x;
$sbW = $sb->width;
foreach (array_slice($lines, $sb->position->y + 3, 12) as $i => $line) {
    $sub = DisplayWidth::mbSubDisp($line, $sbX, $sbW);
    printf("  行%2d |%s|\n", $i, $sub);
}

printf("  hScroll=%d maxHScroll=%d innerW=%d\n", $appT->sidebar->hScroll, $appT->sidebar->maxHScroll, $sbW - 2);

// 命中列：depth=d 的三角在 sb.x+3+2*d
foreach ([1, 5, 10, 15, 20, 25, 30] as $d) {
    $x = $sbX + 3 + 2 * $d;
    printf("  depth=%-3d 三角列 x=%-4d %s\n", $d, $x, $x < $sbX + $sbW - 1 ? '' : '⚠ 已在侧栏右边界外，点不到');
}
printf("  侧栏右边界 x=%d（inner 右界 x=%d）\n", $sbX + $sbW - 1, $sbX + $sbW - 2);

chdir($oldCwd ?: '/');
// 清理
exec('rm -rf ' . escapeshellarg($root));

echo "\nprobe 结束\n";

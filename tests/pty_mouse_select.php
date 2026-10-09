<?php
declare(strict_types=1);

/**
 * 终端鼠标选择 —— 真实 pty 端到端（防回归 E5「选区高亮窜行」）：
 *
 * 用户真机报障：点上一行，选中的是下一行（高亮与文本错一行）。
 * 根因：content() 在 build 期被求值时收到的是**面板外框**（splitInner 按外框切段），
 * 而渲染期 Block/Grid 把内容重排进内区 —— 选区高亮比对若用构造期 midArea 原点，
 * 就与渲染实际落笔原点差 1 行 1 列（BUGFIXES E5 后续）。修复：高亮比对基准取
 * 构造期 viewport() 的 inner 原点（= 渲染实际落笔原点）。
 *
 * 本测试用真实 pty（bash 输出带标记的三行），拖选中间行后**渲染并逐格扫 REVERSED**：
 *   1) 反白单元格必须出现在**拖选那一行**（不多不少）；
 *   2) 剪贴板取字与拖选行一致（取字链同口径）。
 * 反白位置是本测试的核心断言 —— 纯文本/剪贴板断言抓不住高亮窜行。
 *
 * 运行：php tests/pty_mouse_select.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/isolation.php';
vc_isolate_config('vc_mouse_select');
putenv('APP_LOCALE=zh_CN');

use App\App;
use PhpTui\Term\Event\MouseEvent;
use PhpTui\Term\MouseEventKind;
use PhpTui\Term\MouseButton;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Display\Buffer;
use PhpTui\Tui\Extension\Core\CoreExtension;
use PhpTui\Tui\Position\Position;
use PhpTui\Tui\Style\Modifier;
use PhpTui\Tui\Widget\WidgetRenderer\AggregateWidgetRenderer;

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

$rr = new AggregateWidgetRenderer(iterator_to_array((new CoreExtension())->widgetRenderers()));
$vp = Area::fromDimensions(120, 40);
$app = new App();
$app->focus('terminal');

// 聚焦首帧起 pty；等 shell 握手
$app->terminal->content($app->areas($vp)['terminal'], true);
usleep(400000);
$app->terminal->poll();

// bash 输出三行唯一标记
$app->terminal->sendToPty("echo ROW_A_MARK; echo ROW_B_MARK; echo ROW_C_MARK\n");
$deadline = microtime(true) + 5;
while (microtime(true) < $deadline) {
    $app->terminal->poll();
    $app->terminal->content($app->areas($vp)['terminal'], true);
    usleep(50000);
    $j = '';
    foreach ($app->terminal->buffer()->all() as $r) {
        $j .= ($r['text'] ?? '') . "\n";
    }
    if (str_contains($j, 'ROW_C_MARK')) {
        break;
    }
}
$app->terminal->poll();

// 渲染完整帧（Block 链），从渲染结果**反推**三个标记的屏幕行列（E4 教训：不复述公式）
$b = Buffer::empty($vp);
$rr->render($rr, $app->render($vp), $b, $b->area());
$marks = [];
foreach ($b->toLines() as $y => $l) {
    foreach (['ROW_A_MARK', 'ROW_B_MARK', 'ROW_C_MARK'] as $mk) {
        $p = mb_strpos($l, $mk);
        if ($p !== false) {
            $marks[$mk] = ['row' => $y, 'col' => $p];
        }
    }
}
check(count($marks) === 3, '三行标记全部渲染可见（实际 ' . count($marks) . ' 个）');
if (count($marks) !== 3) {
    echo "pty_mouse_select FAIL（标记不可见，环境异常）\n";
    exit(1);
}

// 拖选 ROW_B_MARK（整词 + 1 尾空格，与取字粒度一致）
$m = $marks['ROW_B_MARK'];
$ev = static fn(\PhpTui\Term\MouseEventKind $k, int $c, int $r): MouseEvent => MouseEvent::new($k, MouseButton::Left, $c, $r, 0);
$app->handle($ev(MouseEventKind::Down, $m['col'], $m['row']), $vp);
$app->handle($ev(MouseEventKind::Drag, $m['col'] + 10, $m['row']), $vp);
$app->handle($ev(MouseEventKind::Up, $m['col'] + 10, $m['row']), $vp);
$clip = (string) $app->clipboardPeek();
check(str_contains($clip, 'ROW_B_MARK'), '拖选复制取字正确（实际 ' . json_encode($clip) . '）');

// 再渲染一帧（选区高亮保留），逐格统计 REVERSED 出现的行
$b2 = Buffer::empty($vp);
$rr->render($rr, $app->render($vp), $b2, $b2->area());
$a2 = $b2->area();
$revRows = [];
for ($y = $a2->position->y; $y < $a2->position->y + $a2->height; $y++) {
    $cnt = 0;
    for ($x = $a2->position->x; $x < $a2->position->x + $a2->width; $x++) {
        if (($b2->get(new Position($x, $y))->modifiers & Modifier::REVERSED) !== 0) {
            $cnt++;
        }
    }
    if ($cnt > 0) {
        $revRows[$y] = $cnt;
    }
}
// 期望：拖选行恰有 11 格反白（ROW_B_MARK 10 列 + 1 尾空格）。
// 注意 UI 其它地方也有 REVERSED（选中的实例标签条、菜单等），这里按「拖选行必须有」+ 邻行必须无判定。
$target = $m['row'];
$above = $target - 1;
$below = $target + 1;
check(($revRows[$target] ?? 0) >= 11, "反白出现在拖选行 {$target}（" . ($revRows[$target] ?? 0) . " 格）");
check(($revRows[$above] ?? 0) === 0, "上一行 {$above} 无反白（窜行症状：高亮跑到别的行）");
check(($revRows[$below] ?? 0) === 0, "下一行 {$below} 无反白（窜行症状：高亮跑到别的行）");

$app->terminal->shutdown();
echo $failed ? "\n终端鼠标选择端到端 FAIL\n" : "\n终端鼠标选择端到端 PASS\n";
exit($failed ? 1 : 0);

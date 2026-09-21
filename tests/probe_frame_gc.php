<?php
declare(strict_types=1);

/**
 * 探针（不进跑批）：判断「每帧内存增长」到底是**泄漏**还是**可回收的循环垃圾**。
 *
 * 为什么需要它：用户报「跑完大目录后终端乱掉、怀疑 OOM」时，我一度用
 * `memory_get_usage()` 的累计差值得出「每帧 +3232 字节」并当成泄漏 —— **那是错的**。
 * 两个独立事实把它证伪：
 *   1. 纯 php-tui widget（不经过 App::render）**0 字节/帧** → 增长确实来自我们的代码；
 *   2. 但 `gc_collect_cycles()` 一次回收 1200 个循环、内存**回到起点以下**，
 *      且 4000 帧长跑下自动 GC 正常触发（runs 0→4）、**峰值稳定在 7.6 MB**。
 * 结论：那是**可回收的循环垃圾**，不是泄漏。**判内存问题必须看自动 GC 下的长跑曲线，
 * 不能只看累计差值。**（同一轮里 OOM 也被独立证伪：dmesg 无 OOM-kill、
 * 该目录峰值 RSS 仅 43.8 MB / 上限 2048M —— 见 docs/BUGFIXES.md D10。）
 *
 * 运行：php tests/probe_frame_gc.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/isolation.php';
putenv('APP_LOCALE=zh_CN');

use App\App;
use PhpTui\Tui\Display\Area as TuiArea;
use PhpTui\Tui\Display\Buffer as TuiBuffer;
use PhpTui\Tui\Extension\Core\CoreExtension;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Widget\WidgetRenderer\AggregateWidgetRenderer;

vc_isolate_config('vc_probe_frame_gc');

$ext = new CoreExtension();
$rs = [];
foreach ($ext->widgetRenderers() as $r) {
    $rs[] = $r;
}
$renderer = new AggregateWidgetRenderer($rs);
$vp = TuiArea::fromDimensions(120, 40);

$app = new App();
$frame = static function (bool $trivial) use ($renderer, $app, $vp): void {
    $buf = TuiBuffer::empty($vp);
    $widget = $trivial ? ParagraphWidget::fromString('x') : $app->render($vp);
    $renderer->render($renderer, $widget, $buf, $buf->area());
    $buf->toLines();
};

// ── 1) 定位：增长来自 php-tui 底座还是我们的渲染 ──
foreach ([true => '纯 php-tui widget', false => 'App::render'] as $trivial => $label) {
    for ($i = 0; $i < 100; $i++) {
        $frame((bool) $trivial);   // 预热
    }
    $a = memory_get_usage();
    for ($i = 0; $i < 200; $i++) {
        $frame((bool) $trivial);
    }
    $b = memory_get_usage();
    printf("%-16s 100→300 帧：%+.1f 字节/帧\n", $label, ($b - $a) / 200);
}

// ── 2) 判定：这堆增长能不能被回收（能回收 = 循环垃圾，不是泄漏）──
for ($i = 0; $i < 100; $i++) {
    $frame(false);
}
$a = memory_get_usage();
for ($i = 0; $i < 200; $i++) {
    $frame(false);
}
$b = memory_get_usage();
$collected = gc_collect_cycles();
$c = memory_get_usage();
printf("\n不回收：%.2f → %.2f MB（%+.1f 字节/帧）\n", $a / 1048576, $b / 1048576, ($b - $a) / 200);
printf("gc_collect_cycles()：回收 %d 个循环，内存 %.2f MB（低于起点 = 可回收垃圾，不是泄漏）\n",
    $collected, $c / 1048576);

// ── 3) 长跑：自动 GC 是否真的在跑（峰值应该平稳，不该一路涨）──
$peak = 0;
for ($i = 1; $i <= 2000; $i++) {
    $frame(false);
    $peak = max($peak, memory_get_usage());
}
$st = gc_status();
printf("2000 帧长跑：自动 GC runs=%d collected=%d，内存峰值 %.2f MB\n",
    $st['runs'], $st['collected'], $peak / 1048576);

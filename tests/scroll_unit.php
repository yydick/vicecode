<?php
declare(strict_types=1);

/**
 * headless 单测：回退滚动（滚轮 wheel + PageUp/Down）底层逻辑，两种模式分别钉死。
 *
 *  - Vt100Emulator：gridForRender(rows, scrollbackOffset) 随 offset 增大翻出旧行（scrollPty 驱动它）。
 *  - runner 模式：buffer 灌 60 行 → onKey(PageUp) 把 scroll 向顶夹、onKey(PageDown) 回底；
 *    wheel(-3)/wheel(3) 经 scrollBy 同向；content() 渲染证明最旧/最新行可见。
 *  - pty 模式：真实 proc_open pty 跑 60 行 → scrollPty 调 gridForRender 的 offset，
 *    wheel(-3) 翻回退、wheel(3) 回底；断言 scrollback 偏移与最旧/最新行可见。
 *
 * 运行：php tests/scroll_unit.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\App;
use App\Terminal\Vt100Emulator;
use PhpTui\Tui\Display\Area;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyCode;

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

// ── Vt100Emulator 滚动回退渲染 ───────────────────────
echo "== Vt100Emulator 回退渲染 ==\n";
$emu = new Vt100Emulator(20, 5);
$in = '';
for ($n = 1; $n <= 60; $n++) {
    $in .= "line$n\r\n";
}
$emu->write($in);
check($emu->scrollbackSize() === 56, '60 行写入 5 行屏 → 回退 56 行');
$gBottom = $emu->gridForRender(5, 0);
$bLast = '';
foreach ($gBottom['lines'][3] as $c) {
    $bLast .= $c->ch;
}
check(str_starts_with(trim($bLast), 'line60'), 'offset=0 时最新行 line60 可见');
$gTop = $emu->gridForRender(5, 56); // offset=scrollbackSize → 顶
$tFirst = '';
foreach ($gTop['lines'][0] as $c) {
    $tFirst .= $c->ch;
}
check(str_starts_with(trim($tFirst), 'line1'), 'offset=scrollbackSize 时最旧行 line1 可见（回退翻到顶）');

// ── runner 模式：onKey PageUp/Down + wheel ───────────
echo "\n== runner 模式滚动 ==\n";
$app = new App();
$app->focus('terminal');
$term = $app->terminal;
check($term->mode === 'runner', '初始 runner 模式');
$area = Area::fromDimensions(120, 12); // 模拟终端面板区域（非满屏）
for ($i = 1; $i <= 60; $i++) {
    $term->buffer()->append('R' . sprintf('%03d', $i) . "\n", false);
}
// 先渲染一帧把 scroll 同步到最底
$term->content($area, true);
$scrollBottom = $term->scroll;
check($scrollBottom > 0, "底部时 scroll 已同步到最底 (scroll=$scrollBottom)");
$renderedBottom = normalizeText($term->content($area, true));
check(str_contains($renderedBottom, 'r060'), '底部渲染含最新行 R060');
check(!str_contains($renderedBottom, 'r001'), '底部渲染不含最旧行 R001（已被滚出顶部）');

// PageUp ×20 → 夹紧到顶
for ($k = 0; $k < 20; $k++) {
    $term->onKey(CodedKeyEvent::new(KeyCode::PageUp), ['terminal' => $area]);
}
check($term->scroll === 0, 'PageUp ×20 后 scroll 夹紧到顶 (0)');
$renderedTop = normalizeText($term->content($area, true));
check(str_contains($renderedTop, 'r001'), 'PageUp 翻到顶后最旧行 R001 出现');
check(!str_contains($renderedTop, 'r060'), '顶部渲染已滚出最新行 R060（证明翻离了底部）');

// PageDown ×20 → 回到底（scrollBy 不夹紧上界，内部 scroll 可能 > maxOff，
// 但渲染时 off 会夹紧到 maxOff，故用「>= 底部」+ 渲染可见性断言，而非严格相等）
for ($k = 0; $k < 20; $k++) {
    $term->onKey(CodedKeyEvent::new(KeyCode::PageDown), ['terminal' => $area]);
}
check($term->scroll >= $scrollBottom, 'PageDown ×20 后 scroll 回到（或越过）底部');
$renderedBack = normalizeText($term->content($area, true));
check(str_contains($renderedBack, 'r060'), 'PageDown 回底后最新行 R060 可见');

// wheel 方向：wheel(-3) 翻上、wheel(3) 翻下（与鼠标 ScrollUp/ScrollDown 同源）
$term->wheel(-3);
check($term->scroll < $scrollBottom, 'wheel(-3) 向顶滚动（scroll 减小）');
$term->wheel(3);
check($term->scroll > 0, 'wheel(3) 向底滚动（scroll 增大）');

// ── pty 模式：真实 pty + scrollPty/wheel ────────────
echo "\n== pty 模式滚动（真实 pty）==\n";
$app2 = new App();
$app2->focus('terminal');
$t2 = $app2->terminal;
$t2->toggleInteractive();           // 进 pty 捕获态（pty 真正起在自己的首帧渲染）
// 触发首帧渲染消费 ptyStartPending：F2 切 pty 后 pty 推迟到首帧 ptyContent()
// 用真实面板尺寸起（避免按错误 LINES 渲染全屏程序），故需先渲染一帧才能 isRunning()。
$t2->content($area, true);
check($t2->mode === 'pty' && $t2->isRunning(), '已进入 pty 且 shell 在跑');
// 预热：先排空约 1 秒，等 shell 提示符就绪（bash --rcfile 在 pty 下会先打
// "Inappropriate ioctl for device"，此时命令字节会被吃掉的，必须等就绪再喂）。
for ($i = 0; $i < 20; $i++) {
    $t2->poll();
    usleep(50000);
}
$t2->sendToPty("printf 'P%03d\\n' $(seq 1 60)\n");
// 排空直到见到 P060 或超时
$deadline = microtime(true) + 4;
$seen = false;
while (microtime(true) < $deadline) {
    $t2->poll();
    if ($t2->scrollbackSize() >= 40) {
        $seen = true;
    }
    if ($seen) {
        break;
    }
    usleep(50000);
}
// 多排空确保稳定
for ($i = 0; $i < 10; $i++) {
    $t2->poll();
    usleep(30000);
}
$sbSize = $t2->scrollbackSize();
check($sbSize > 0, "pty 已产生回退 (scrollbackSize=$sbSize)");
$boxBottom = $t2->content($area, true);
$bottomTxt = renderToString($boxBottom);
check(str_contains($bottomTxt, 'p060'), 'pty 底部最新行 P060 可见');

// wheel(-3) 多次翻回退 → 最旧行 P001 出现
for ($k = 0; $k < 20; $k++) {
    $t2->wheel(-3);
}
$topTxt = renderToString($t2->content($area, true));
check(str_contains($topTxt, 'p001'), 'wheel(-3) 翻回退后最旧行 P001 出现');
// wheel(3) 回到底
for ($k = 0; $k < 20; $k++) {
    $t2->wheel(3);
}
$bottomTxt2 = renderToString($t2->content($area, true));
check(str_contains($bottomTxt2, 'p060'), 'wheel(3) 回底后最新行 P060 可见');

// scrollPty 直接调用：约定 scrollback=0 贴底（最新）、=scrollbackSize 翻到顶（最旧），
// 向上（delta<0）增大 scrollback，故巨大负 delta 夹紧到顶、巨大正 delta 夹紧到底。
$t2->scrollPty(-99999);
check($t2->scrollback === $sbSize, 'scrollPty(-99999) 夹紧到顶 (scrollback=scrollbackSize)');
$t2->scrollPty(99999);
check($t2->scrollback === 0, 'scrollPty(99999) 夹紧到底 (scrollback=0)');
$t2->shutdown();

// ── 辅助：把渲染 Widget 转纯文本（去 ANSI / 小写 / 只留 alnum+CJK）──
function normalizeText(\PhpTui\Tui\Widget\Widget $w): string
{
    return renderToString($w);
}
function renderToString(\PhpTui\Tui\Widget\Widget $w): string
{
    // 用 DummyBackend + DisplayBuilder 把 widget 渲染成纯文本网格，再归一化。
    // 注意：DummyBackend 命名空间是 PhpTui\Tui\Display\Backend，且 draw 的视口由
    // DisplayBuilder::fullscreen() 决定（无需手动传 Area）；flush()/toString() 取网格文本。
    $backend = new \PhpTui\Tui\Display\Backend\DummyBackend(120, 40);
    $disp = \PhpTui\Tui\DisplayBuilder::default($backend)->fullscreen()->build();
    $disp->draw($w);
    $txt = $backend->toString();
    $s = (string) preg_replace('/\x1B\[[0-9;?]*[ -\/]*[@-~]/', '', $txt);
    return strtolower((string) preg_replace('/[^a-zA-Z0-9\x{4e00}-\x{9fff}]/u', '', $s));
}

echo $failed ? "\nscroll_unit FAIL\n" : "\nscroll_unit PASS\n";
exit($failed ? 1 : 0);

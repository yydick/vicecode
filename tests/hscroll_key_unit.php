<?php
declare(strict_types=1);

/**
 * 键盘横向滚动（Shift+←/→，步进 ±4）单测（不依赖 tty）：
 *  1) 编辑器焦点：Shift+Right 增 scrollLeft、Shift+Left 减（钳 0）；
 *  2) 终端/侧栏/AI 焦点：Shift+←/→ 分别改各自 hScroll；
 *  3) 普通 ←/→（无 Shift）不触发横滚（留给光标移动），scrollLeft/hScroll 不变；
 *  4) 菜单打开期间 Shift+←/→ 被模态吞掉，不触发横滚。
 *
 * 运行：php tests/hscroll_key_unit.php
 */

require __DIR__ . '/../vendor/autoload.php';
putenv('APP_LOCALE=zh_CN');

use App\App;
use App\Panel\AiPanel;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\KeyModifiers;
use PhpTui\Tui\Display\Area;

$aiClass = AiPanel::class;

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

// Shift+方向键事件构造助手
function shiftKey(string $code): CodedKeyEvent
{
    $kc = $code === 'Left' ? KeyCode::Left : KeyCode::Right;
    return CodedKeyEvent::new($kc, KeyModifiers::SHIFT);
}
function plainKey(string $code): CodedKeyEvent
{
    $kc = $code === 'Left' ? KeyCode::Left : KeyCode::Right;
    return CodedKeyEvent::new($kc, 0);
}

$vp = Area::fromDimensions(160, 50);

// ─────────────── 1) 编辑器焦点 ───────────────
echo "== 编辑器焦点 Shift+←/→ ==\n";
$line0 = str_repeat('x', 200);
$tf = tempnam(sys_get_temp_dir(), 'vc_kh');
file_put_contents($tf, $line0);
$app = new App();
$app->openFile($tf);
$app->focus('editor');
check($app->buffer->scrollLeft === 0, '编辑器初始 scrollLeft=0');

$app->handle(shiftKey('Right'), $vp);
check($app->buffer->scrollLeft === 4, 'Shift+Right：scrollLeft=4');
$app->handle(shiftKey('Right'), $vp);
check($app->buffer->scrollLeft === 8, 'Shift+Right 再按：scrollLeft=8');
$app->handle(shiftKey('Left'), $vp);
check($app->buffer->scrollLeft === 4, 'Shift+Left：scrollLeft=4');
$app->handle(shiftKey('Left'), $vp); // 4→0
$app->handle(shiftKey('Left'), $vp); // 钳到 0
check($app->buffer->scrollLeft === 0, 'Shift+Left 下界钳到 0（不出现负数）');
unlink($tf);

// ─────────────── 2) 终端焦点 ───────────────
echo "== 终端焦点 Shift+←/→ ==\n";
$app = new App();
$app->focus('terminal');
$app->terminal->buffer()->append(str_repeat('y', 150) . 'TERMMARK' . "\n", false);
$app->handle(shiftKey('Right'), $vp);
check($app->terminal->hScroll === 4, '终端 Shift+Right：hScroll=4');
$app->handle(shiftKey('Left'), $vp);
check($app->terminal->hScroll === 0, '终端 Shift+Left：hScroll=0（钳下界）');

// ─────────────── 3) 侧栏焦点（SEARCH tab 长路径）───────────────
echo "== 侧栏焦点 Shift+←/→ ==\n";
$app = new App();
$app->focus('sidebar');
$app->sidebar->tabIndex = 2; // SEARCH
$long = str_repeat('a', 120);
foreach (['src/' . $long . ':1:foo', 'src/' . $long . ':2:bar'] as $ln) {
    $app->search->ingestLine($ln);
}
$app->handle(shiftKey('Right'), $vp);
check($app->sidebar->hScroll === 4, '侧栏 Shift+Right：hScroll=4');
$app->handle(shiftKey('Left'), $vp);
check($app->sidebar->hScroll === 0, '侧栏 Shift+Left：hScroll=0（钳下界）');

// ─────────────── 4) AI 焦点 ───────────────
echo "== AI 焦点 Shift+←/→ ==\n";
// AiPanel::$hScroll 为 private，用反射读取（不污染生产代码加 getter）
$aiHScroll = function (App $app) use ($aiClass): int {
    $r = new ReflectionProperty($aiClass, 'hScroll');
    $r->setAccessible(true);
    return (int) $r->getValue($app->ai);
};
$app = new App();
$app->focus('ai_input');
$app->handle(shiftKey('Right'), $vp);
check($aiHScroll($app) === 4, 'AI Shift+Right：hScroll=4');
$app->handle(shiftKey('Left'), $vp);
check($aiHScroll($app) === 0, 'AI Shift+Left：hScroll=0（钳下界）');

// ─────────────── 5) 普通 ←/→ 不触发横滚 ───────────────
echo "== 普通 ←/→ 不触发横滚 ==\n";
$tf = tempnam(sys_get_temp_dir(), 'vc_kp');
file_put_contents($tf, str_repeat('z', 200));
$app = new App();
$app->openFile($tf);
$app->focus('editor');
$app->handle(plainKey('Right'), $vp); // 普通方向键 → 光标右移，不应横滚
check($app->buffer->scrollLeft === 0, '普通 Right：scrollLeft 仍为 0（留给光标移动）');
unlink($tf);

// ─────────────── 6) 菜单打开期间被模态吞掉 ───────────────
echo "== 菜单打开期间 Shift+←/→ 被吞 ==\n";
$tf = tempnam(sys_get_temp_dir(), 'vc_km');
file_put_contents($tf, str_repeat('w', 200));
$app = new App();
$app->openFile($tf);
$app->focus('editor');
$app->menuBar->toggle(); // 打开菜单（独占键盘）
check($app->menuBar->isOpen(), '菜单已打开');
$app->handle(shiftKey('Right'), $vp);
check($app->buffer->scrollLeft === 0, '菜单打开时 Shift+Right 不触发横滚（被模态吞掉）');
$app->menuBar->toggle(); // 关闭菜单
$app->handle(shiftKey('Right'), $vp);
check($app->buffer->scrollLeft === 4, '菜单关闭后 Shift+Right 正常横滚（scrollLeft=4）');
unlink($tf);

// ─────────────── 结果 ───────────────
echo "\n";
if ($failed) {
    echo "键盘横向滚动单测：存在失败 ✗\n";
    exit(1);
}
echo "键盘横向滚动单测：全部通过 ✓\n";

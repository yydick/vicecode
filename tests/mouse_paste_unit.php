<?php
declare(strict_types=1);

/**
 * 右键粘贴 + OSC 52 字节流旁路 —— 无终端单测：
 *  1) Osc52StreamScanner：完整/跨块/ST 结尾/夹杂按键；
 *  2) 右键在终端内 → 粘贴（headless 走内存剪贴板路径）；
 *  3) 右键在其他面板 → 什么都不发生（右键语义预留给将来的上下文菜单）；
 *  4) pasteTick 超时降级：OSC 52 无响应 → 贴应用内剪贴板并提示。
 *
 * 运行：php tests/mouse_paste_unit.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/isolation.php';
vc_isolate_config('vc_mouse_paste');
putenv('APP_LOCALE=zh_CN');

use App\App;
use App\Core\Osc52StreamScanner;
use PhpTui\Term\Event\MouseEvent;
use PhpTui\Term\MouseEventKind;
use PhpTui\Term\MouseButton;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Widget\Margin;

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

// ─────────────── 1) Osc52StreamScanner ───────────────
echo "== Osc52StreamScanner ==\n";
$b64 = base64_encode('hello paste');
$full = "\x1b]52;c;" . $b64 . "\x07";

$got = null;
$scan = new Osc52StreamScanner(static function (string $t) use (&$got): void { $got = $t; });

// 完整单块
$got = null;
$out = $scan->feed("abc" . $full . "def");
check($got === 'hello paste', '完整单块解码回调');
check($out === 'abcdef', '序列剔除、前后按键放行（实际 ' . json_encode($out) . '）');

// 跨块（劈三刀）
$got = null;
$o1 = $scan->feed(substr($full, 0, 6));
check($o1 === '' && $got === null && $scan->collecting(), '起始块进入收集态（不喂 parser）');
$o2 = $scan->feed(substr($full, 6, 10));
check($got === null && $scan->collecting(), '中段继续收集');
$o3 = $scan->feed(substr($full, 16));
check($got === 'hello paste', '结尾块解码回调');
check($o1 . $o2 . $o3 === '', '跨块无字节漏进 parser');

// ST 结尾（\e\\）
$got = null;
$scanSt = new Osc52StreamScanner(static function (string $t) use (&$got): void { $got = $t; });
$scanSt->feed("\x1b]52;c;" . $b64 . "\x1b\\");
check($got === 'hello paste', 'ST 结尾（\e\\）同样解码');

// 剪贴板内容里含 BEL 的二进制被拒后不炸
$got = null;
$scan->feed("\x1b]52;c;" . base64_encode("a\x07b") . "\x07");
check($got === "a\x07b" || $got === null, '含 BEL 的 base64 主体不崩');

// ─────────────── 2) 右键在终端内 → 粘贴 ───────────────
echo "\n== 右键粘贴 ==\n";
$vp = Area::fromDimensions(120, 40);
$app = new App();
$app->focus('editor');
$app->terminal->mode = 'runner';           // 钉回 runner：粘贴落到输入行，headless 可断言
// clip 是私有，经反射注入内存剪贴板（App 未暴露公开 copy 入口，拖选路径内部使用）
$rc = new ReflectionProperty($app, 'clip');
$rc->setAccessible(true);
$rc->getValue($app)->copy('PASTE_ME');
$t = $app->areas($vp)['terminal'];
// 点终端面板中部（明确避开顶行标签条 / 底行切换条）
$tx = $t->position->x + intdiv($t->width, 2);
$ty = $t->position->y + intdiv($t->height, 2);
$app->handle(MouseEvent::new(MouseEventKind::Down, MouseButton::Right, $tx, $ty, 0), $vp);
check($app->focusPanel() === 'terminal', '右键终端 → 聚焦终端');
check(str_contains($app->terminal->input, 'PASTE_ME'), '右键终端 → 应用内剪贴板内容进输入行（实际 ' . json_encode($app->terminal->input) . '）');

// ─────────────── 3) 右键在其他面板 → 不消费 ───────────────
$app2 = new App();
$app2->focus('editor');
$tmpFile = vc_tmp_file('vc_mp');
file_put_contents($tmpFile, "hello\n");
$app2->openFile($tmpFile);
$ed = $app2->areas($vp)['editor'];
$ex = $ed->position->x + intdiv($ed->width, 2);
$ey = $ed->position->y + intdiv($ed->height, 2);
$app2->handle(MouseEvent::new(MouseEventKind::Down, MouseButton::Right, $ex, $ey, 0), $vp);
$bufText = $app2->buffer?->lines[0] ?? '';
check($bufText === 'hello', '右键编辑器 → 不插入任何内容（右键语义预留给上下文菜单）');
check($app2->focusPanel() === 'editor', '右键编辑器 → 焦点不被右键改变（仍 editor）');

// ─────────────── 4) pasteTick 超时降级 ───────────────
echo "\n== pasteTick 超时降级 ==\n";
$app3 = new App();
$app3->openFile(vc_tmp_file('vc_mp2'));
$app3->focus('editor');
$rc3 = new ReflectionProperty($app3, 'clip');
$rc3->setAccessible(true);
$rc3->getValue($app3)->copy('FALLBACK_TEXT');
// 模拟「请求已发出且超时」：tty 分支才会走 OSC52，headless 直接注入内部状态
$rp = new ReflectionProperty($app3, 'pasteTarget');
$rp->setAccessible(true);
$rp->setValue($app3, 'editor');
$rt = new ReflectionProperty($app3, 'pasteSentAt');
$rt->setAccessible(true);
$rt->setValue($app3, microtime(true) - 2.0);
$app3->pasteTick();
check(str_contains($app3->buffer?->lines[0] ?? '', 'FALLBACK_TEXT'), '超时 → 降级贴应用内剪贴板');
check(str_contains($app3->message, '应用内剪贴板'), '状态栏提示降级来源（实际：' . $app3->message . '）');
check($rp->getValue($app3) === null, 'pasteTarget 清空（不重复粘贴）');
$nrp = new ReflectionProperty($app3, 'needsRedraw');
$nrp->setAccessible(true);
check($nrp->getValue($app3) === true, '降级发生在无事件轮 → needsRedraw 置位（主循环才会上屏提示）');
// 未超时：不动
$rp->setValue($app3, 'editor');
$rt->setValue($app3, microtime(true) - 0.1);
$app3->buffer?->lines[0] !== null && ($app3->buffer->lines[0] = 'x');
$app3->pasteTick();
check($rp->getValue($app3) === 'editor', '未超时不触发降级');

// 会话级记忆：首次超时后 osc52ReadOk=false，第二次 requestPaste 直接贴内存（零等待、无 pasteSentAt）
$app4 = new App();
$app4->terminal->mode = 'runner';
$app4->focus('terminal');
$rc4 = new ReflectionProperty($app4, 'clip');
$rc4->setAccessible(true);
$rc4->getValue($app4)->copy('FAST_PASTE');
$ok4 = new ReflectionProperty($app4, 'osc52ReadOk');
$ok4->setAccessible(true);
$ok4->setValue($app4, false);   // 模拟首次超时已发生
$t4 = $app4->areas($vp)['terminal'];
$app4->handle(MouseEvent::new(MouseEventKind::Down, MouseButton::Right,
    $t4->position->x + intdiv($t4->width, 2), $t4->position->y + intdiv($t4->height, 2), 0), $vp);
check(str_contains($app4->terminal->input, 'FAST_PASTE'), '第二次右键直接贴内存（零等待）');
$ps = new ReflectionProperty($app4, 'pasteSentAt');
$ps->setAccessible(true);
check($ps->getValue($app4) === null, '零等待路径不设 OSC 52 请求计时（不进入等待）');
// （tty 分支的 osc52ReadOk=true 探测路径无法在 headless 下触发——stream_isatty 恒 false，
//  行为已由代码路径评审覆盖；headless 下第二次右键同样即时贴内存 ✓）

echo $failed ? "\n右键粘贴/OSC52 单测 FAIL\n" : "\n右键粘贴/OSC52 单测全部 PASS\n";
exit($failed ? 1 : 0);

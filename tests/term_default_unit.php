<?php
declare(strict_types=1);

/**
 * 终端默认交互式 pty（B15）—— App 层的**按键路由**守护（headless，进跑批）。
 *
 * 默认模式从 runner 换成 pty 之后，「这个键该归 shell 还是该归应用」全压在一处判据上
 * （`App::isAutoCaptureKey`）。它错一格，用户就掉进两类坑：
 *   1) **吞得太多**：Tab / Esc / 方向键 / `?` 被当成「开始打字」→ 焦点一进终端就再也出不来、
 *      帮助页永远打不开（而终端恰好是默认聚焦流程里最常经过的面板）；
 *   2) **吞得太少**：明明在打字却没进捕获 → 字符被静默丢弃，用户以为终端坏了。
 *
 * 本用例把两侧边界都钉住；真实 pty 下的端到端由 `pty_term`（自动捕获执行命令）与
 * `pty_term_alias`（别名可用）覆盖。
 *
 * 运行：php tests/term_default_unit.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/isolation.php';
vc_isolate_config('vc_term_default');   // 否则 new App() 会读开发机真实 ~/.vicerc

// ⚠️ 再把 HOME 换成一个只有一行的自造目录：本用例会真的起交互式 shell，而 shell 会 source
// `$HOME/.bashrc` —— 开发机那份要加载 nvm + conda（`conda shell.bash hook` 真的起 Python），
// 启动要数秒且随负载抖动，会让下面「等提示符/等退出」的窗口偶发不够用。
// 我们验的是按键路由，不该被用户 rc 的启动速度牵着走。
$home = vc_tmp_dir('vc_term_home');
file_put_contents($home . '/.bashrc', "PS1='ready$ '\n");
putenv('HOME=' . $home);

use App\App;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\KeyModifiers;
use PhpTui\Tui\Display\Area;

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

$vp = Area::fromDimensions(120, 40);
$area = Area::fromDimensions(120, 40);

// ── 1) 自动捕获：可打印字符进捕获并转发给 shell（不落 runner 输入行）──
echo "== 自动捕获：可打印字符 ==\n";
$app = new App();
check($app->terminal->mode === 'pty', '前提：默认模式就是 pty');
check(!$app->terminal->isCaptured(), '前提：默认未捕获');
$app->focus('terminal');
$app->handle(CharKeyEvent::new('l', 0), $vp);
check($app->terminal->isCaptured(), '聚焦终端后敲可打印字符 → 自动进捕获');
check($app->terminal->input === '', '该字符**没有**落进 runner 输入行（走的是 shell）');
$app->handle(CharKeyEvent::new('s', 0), $vp);
check($app->terminal->input === '', '捕获后继续打字也不进 runner 输入行');

// ── 2) Tab 不被吞：未捕获时仍能切走焦点 ──
echo "\n== 自动捕获：Tab 必须留给焦点切换 ==\n";
$app->terminal->exitCapture();   // 回到「shell 活着但未捕获」这个默认导航态
$app->handle(CodedKeyEvent::new(KeyCode::Tab, 0), $vp);
check($app->focusPanel() !== 'terminal', '未捕获时 Tab 仍能切走焦点（实际 ' . $app->focusPanel() . '）');
check(!$app->terminal->isCaptured(), 'Tab 没被当成「开始打字」');
$app->focus('terminal');

// ── 3) `?` 仍能打开帮助页 ──
echo "\n== 自动捕获：`?` 必须留给帮助页 ==\n";
$app->handle(CharKeyEvent::new('?', 0), $vp);
check($app->help->isOpen(), '未捕获时 `?` 打开帮助页');
check(!$app->terminal->isCaptured(), '`?` 没被当成「开始打字」');
$app->help->close();

// ── 4) 带修饰键 / 导航键都不触发捕获 ──
echo "\n== 自动捕获：不该捕获的键 ==\n";
$notCapture = [
    'Ctrl+L'  => CharKeyEvent::new('l', KeyModifiers::CONTROL),
    'Alt+L'   => CharKeyEvent::new('l', KeyModifiers::ALT),
    'Up'      => CodedKeyEvent::new(KeyCode::Up, 0),
    'PageUp'  => CodedKeyEvent::new(KeyCode::PageUp, 0),
    'Left'    => CodedKeyEvent::new(KeyCode::Left, 0),
];
foreach ($notCapture as $name => $ev) {
    $app->handle($ev, $vp);
    check(!$app->terminal->isCaptured(), "{$name}：不触发自动捕获（留给应用 / 回退滚动）");
}

// ── 5) 回车也触发捕获（真实 pty 里 Enter 是 CodedKeyEvent）──
echo "\n== 自动捕获：回车 ==\n";
$app->handle(CodedKeyEvent::new(KeyCode::Enter, 0), $vp);
check($app->terminal->isCaptured(), 'CodedKeyEvent(Enter) 也触发自动捕获');
$app->terminal->shutdown();   // 上面几条已经真的起了 shell，收尾

// ── 6) Esc：非捕获 pty 下必须退出应用，不能被 isRunning() 空吞 ──
echo "\n== Esc：非捕获 pty 下不能空吞 ==\n";
$app2 = new App();
$app2->focus('terminal');
$app2->terminal->content($area, true);   // 聚焦首帧 → 起 shell；isRunning() 这才为真
check($app2->terminal->isRunning(), '前提：shell 在跑（旧写法 isRunning() 恒真才会吞掉 Esc）');
check(!$app2->terminal->isCaptured(), '前提：未捕获');
$app2->handle(CodedKeyEvent::new(KeyCode::Esc, 0), $vp);
check($app2->quit === true, '未捕获 pty 下 Esc 退出应用（旧写法会被 cancel() 空吞、什么都不发生）');
$app2->terminal->shutdown();

// ── 7) runner 回落态没被破坏：打字仍进命令输入行 ──
echo "\n== runner 回落态 ==\n";
$app3 = new App();
$app3->terminal->mode = 'runner';
$app3->focus('terminal');
$app3->handle(CharKeyEvent::new('e', 0), $vp);
check($app3->terminal->input === 'e', 'runner 模式：打字仍进命令输入行');
check(!$app3->terminal->isCaptured(), 'runner 模式不产生捕获态');

// ── 8) shell 退出后的按键不能被「已经死掉的 pty」吞掉 ──
// 主循环每轮才 poll 一次；若只靠它，shell 退出后到达的那几个键会先被喂给死 pty（静默丢弃）
// 再落进 runner 输入行 —— 表现为命令被截掉开头（pty_scroll 场景 A 实测过：`printf '…` 的
// 前 8 个字节连同开引号一起没了，sh 报 unexpected EOF）。这里让 shell 自己退出，
// 然后**走真实按键路径**敲一个字符，断言它最终落到 runner 输入行。
echo "\n== shell 退出后的按键 ==\n";
$app4 = new App();
$app4->focus('terminal');
$app4->terminal->content($area, true);   // 起 shell
check($app4->terminal->isRunning(), '前提：shell 在跑');
// 先等 shell **可接收输入**（提示符到了才会把攒下的按键补发，见 TerminalPanel::$shellReady）：
// 这里必须 poll（真实主循环一直在 poll），否则就绪判定不推进，exit 会一直攒在缓冲里。
$app4->terminal->sendToPty("exit\r");
$quitDeadline = microtime(true) + 8;
while (microtime(true) < $quitDeadline && $app4->terminal->mode === 'pty') {
    $app4->terminal->poll();
    usleep(20000);
}
check($app4->terminal->mode === 'runner', '前提：exit 最终生效、shell 已退出并回落 runner');
$arrived = false;
$deadline = microtime(true) + 5;
while (microtime(true) < $deadline) {
    $app4->handle(CharKeyEvent::new('x', 0), $vp);   // App 按键路径内部会先结算 shell 状态
    if ($app4->terminal->input === 'x') {
        $arrived = true;
        break;
    }
    usleep(20000);
}
check($arrived, 'shell 退出后敲的字符落进 runner 输入行（没被死 pty 吞掉）');
$app4->terminal->shutdown();

// ── 9) 刚起 shell 就打字：命令必须真的执行 ──
// shell 是**聚焦后首帧**才起的，而用户的第一个键可能几乎同时到达。刚 spawn 的 bash 在
// readline 初始化前会**丢掉**先到的输入（tty 回显看得见、既不执行也不留在行里，随后出现
// 一个新提示符）—— 所以 TerminalPanel 会把按键先攒起来、等提示符（或兜底时限）再补发。
// 这条断言就是那个「攒起来」的守护：实测去掉缓冲后这里稳定拿不到 42。
echo "\n== 刚起 shell 就打字 ==\n";
$app5 = new App();
$app5->focus('terminal');
$app5->terminal->content($area, true);          // 起 shell
foreach (str_split('echo $((6*7))') as $ch) {   // 紧接着（不等待）逐字符敲
    $app5->handle(CharKeyEvent::new($ch, 0), $vp);
}
$app5->handle(CodedKeyEvent::new(KeyCode::Enter, 0), $vp);
$inner = $area->inner(new \PhpTui\Tui\Widget\Margin(1, 1));
$got42 = false;
$deadline = microtime(true) + 6;
while (microtime(true) < $deadline) {
    $app5->terminal->poll();
    $app5->terminal->content($area, true);      // 渲染一帧（填充可见网格）
    $text = $app5->terminal->getTextRect(
        $area,
        $inner->position->y,
        $inner->position->x,
        $inner->position->y + max(0, $inner->height - 1),
        $inner->position->x + max(0, $inner->width - 1)
    );
    if (str_contains($text, '42')) {            // 42 只可能来自 ($((6*7))) 求值
        $got42 = true;
        break;
    }
    usleep(20000);
}
check($got42, '刚起 shell 就敲的命令真的执行了（按键没被 readline 初始化丢掉）');
$app5->terminal->shutdown();

echo $failed ? "\nterm_default_unit FAIL\n" : "\nterm_default_unit PASS\n";
exit($failed ? 1 : 0);

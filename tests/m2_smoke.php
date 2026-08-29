<?php
declare(strict_types=1);

/**
 * M2 无终端冒烟测试（不依赖 tty/STDIN）：
 *  1) TerminalBuffer：半行拼接、跨流断行、行上限、脏字节清洗；
 *  2) CommandRunner：stdout/stderr 分流、退出码、中断、shutdown 清理；
 *  3) App 集成（R1–R7）：输入行、提交执行、流式输出渲染、历史召回、
 *     退出码/stderr 着色、滚轮翻页、Ctrl+C 与 Esc 的判定顺序。
 *
 * 运行：php tests/m2_smoke.php
 */

require __DIR__ . '/../vendor/autoload.php';

// 固定界面语言为 zh_CN，使渲染断言可确定
putenv('APP_LOCALE=zh_CN');

use App\App;
use App\Terminal\CommandRunner;
use App\Terminal\TerminalBuffer;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event\MouseEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\KeyModifiers;
use PhpTui\Term\MouseButton;
use PhpTui\Term\MouseEventKind;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Display\Buffer as TuiBuffer;
use PhpTui\Tui\Extension\Core\CoreExtension;
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

/** 渲染 App 到文本（headless，不创建 Terminal/Display）。 */
function renderApp(App $app, Area $vp): string
{
    $ext = new CoreExtension();
    $renderers = [];
    foreach ($ext->widgetRenderers() as $r) {
        $renderers[] = $r;
    }
    $renderer = new AggregateWidgetRenderer($renderers);
    $buffer = TuiBuffer::empty($vp);
    $renderer->render($renderer, $app->render($vp), $buffer, $buffer->area());
    return implode("\n", $buffer->toLines());
}

/** 模拟在终端面板逐字符键入。 */
function typeTerm(App $app, Area $vp, string $s): void
{
    $n = strlen($s);
    for ($i = 0; $i < $n; $i++) {
        $app->handle(CharKeyEvent::new($s[$i], 0), $vp);
    }
}

/** 模拟主循环：反复 pollTerminal() 直到命令结束。 */
function pump(App $app, float $maxSec = 10.0): void
{
    $t0 = microtime(true);
    while ($app->termRunning() && microtime(true) - $t0 < $maxSec) {
        $app->pollTerminal();
        usleep(2000);
    }
}

// ─────────────────── 1) TerminalBuffer ───────────────────
echo "== TerminalBuffer ==\n";
$b = new TerminalBuffer();
$b->append("line1\nline2\n", false);
$b->append('tail', false);
check($b->count() === 2, '半行不落地（count=2）');
$b->flushTail();
check($b->count() === 3 && $b->all()[2] === ['text' => 'tail', 'err' => false], 'flushTail 落地半行');

$b2 = new TerminalBuffer();
$b2->append('out-half', false);
$b2->append("err-half\n", true);          // 半行未完就换流：旧半行先按原流结算
check(
    $b2->count() === 2
    && $b2->all()[0] === ['text' => 'out-half', 'err' => false]
    && $b2->all()[1] === ['text' => 'err-half', 'err' => true],
    '跨流断行不串色'
);

$b3 = new TerminalBuffer();
$b3->append("a\tb\033[31mred\033[0m\r\n\x07bell", false);
$b3->flushTail();
check(
    $b3->count() === 2 && $b3->all()[0]['text'] === 'a    bred' && $b3->all()[1]['text'] === 'bell',
    'ANSl/CRLF/TAB/BEL 清洗（' . json_encode(array_column($b3->all(), 'text')) . '）'
);

$b4 = new TerminalBuffer();
for ($i = 0; $i < 2100; $i++) {
    $b4->append("l$i\n", false);
}
check($b4->count() === TerminalBuffer::MAX_LINES && $b4->all()[0]['text'] === 'l100', '行上限 2000，丢弃最旧');

// ─────────────────── 2) CommandRunner ───────────────────
echo "== CommandRunner ==\n";
$r = new CommandRunner();
check($r->start('echo hi; echo bad >&2; exit 5'), 'start() 起进程');
$out = '';
$err = '';
$t0 = microtime(true);
while ($r->isRunning() && microtime(true) - $t0 < 5) {
    $r->poll(function (string $bytes, bool $isErr) use (&$out, &$err): void {
        if ($isErr) {
            $err .= $bytes;
        } else {
            $out .= $bytes;
        }
    });
    usleep(2000);
}
check(trim($out) === 'hi' && trim($err) === 'bad', "stdout/stderr 分流（out=" . trim($out) . " err=" . trim($err) . '）');
check($r->exitCode() === 5, '退出码=5');
check($r->termSig() === 0, '正常退出 termsig=0');

$r2 = new CommandRunner();
$r2->start('sleep 30');
usleep(100000);
$r2->cancel();
$t0 = microtime(true);
while ($r2->isRunning() && microtime(true) - $t0 < 5) {
    $r2->poll(static function (): void {
    });
    usleep(2000);
}
check(!$r2->isRunning() && $r2->termSig() === 9, 'cancel() → termsig=9');

$r3 = new CommandRunner();
$r3->start('sleep 30');
usleep(50000);
$r3->shutdown();
check(!$r3->isRunning(), 'shutdown() 杀掉在跑的命令');

// ─────────────────── 3) App 集成（R1–R7） ───────────────────
echo "== App 集成 ==\n";
$vp = Area::fromDimensions(120, 40);
$app = new App();
$app->focusIndex = array_search('terminal', App::PANELS, true);
check($app->focusPanel() === 'terminal', '焦点切到 terminal');
$text = renderApp($app, $vp);
check(str_contains($text, '终端'), '面板标题渲染');
check(str_contains($text, '$'), '输入行提示符渲染');

// R1 输入行
typeTerm($app, $vp, 'echo hello-m2');
check($app->terminal->input === 'echo hello-m2', 'R1 可打印字符进输入行');
check($app->terminal->pos === 13, 'R1 光标跟随输入');
$app->handle(CharKeyEvent::new('q', 0), $vp);
check(!$app->quit && $app->terminal->input === 'echo hello-m2q', 'R1 terminal 聚焦时 q 进输入行而非退出');
$app->handle(CharKeyEvent::new("\x7f", 0), $vp);
check($app->terminal->input === 'echo hello-m2', 'R1 Backspace 删除光标前字符');
$app->handle(CodedKeyEvent::new(KeyCode::Left, 0), $vp);
check($app->terminal->pos === 12, 'R1 Left 移动光标');
$app->handle(CodedKeyEvent::new(KeyCode::End, 0), $vp);
check($app->terminal->pos === 13, 'R1 End 回到行尾');

// R2/R3 提交 → 执行 → 输出渲染
$app->handle(CharKeyEvent::new("\r", 0), $vp);
check($app->termRunning(), 'R2 回车提交后命令在跑');
check($app->terminal->input === '', 'R1 提交后输入行清空');
pump($app);
check(!$app->termRunning(), 'R2 命令结束');
$text = renderApp($app, $vp);
check(str_contains($text, 'hello-m2'), 'R3 命令输出渲染到面板');
check(str_contains($text, '$ echo hello-m2'), 'R3 命令本身回显');

// R5 退出码 + stderr 着色
typeTerm($app, $vp, 'echo boom >&2; exit 3');
$app->handle(CharKeyEvent::new("\r", 0), $vp);
pump($app);
$text = renderApp($app, $vp);
check(str_contains($text, 'boom'), 'R3 stderr 内容进入输出');
check(str_contains($text, '退出码 3'), 'R5 非零退出码提示');
$rows = $app->terminal->buffer()->all();
$errRows = array_values(array_filter($rows, static fn(array $r): bool => $r['err']));
check($errRows !== [] && $errRows[0]['text'] === 'boom', 'R5 stderr 行带 err 标记（渲染层据此着红）');

// R4 历史召回
$app->handle(CodedKeyEvent::new(KeyCode::Up, 0), $vp);
check($app->terminal->input === 'echo boom >&2; exit 3', 'R4 ↑ 召回上一条');
$app->handle(CodedKeyEvent::new(KeyCode::Up, 0), $vp);
check($app->terminal->input === 'echo hello-m2', 'R4 再 ↑ 召回更早一条');
$app->handle(CodedKeyEvent::new(KeyCode::Down, 0), $vp);
check($app->terminal->input === 'echo boom >&2; exit 3', 'R4 ↓ 返回较新一条');
$app->handle(CodedKeyEvent::new(KeyCode::Down, 0), $vp);
check($app->terminal->input === '' && $app->terminal->histIdx === -1, 'R4 ↓ 到底回到空输入');

// R6 滚轮翻页
typeTerm($app, $vp, 'seq 1 100');
$app->handle(CharKeyEvent::new("\r", 0), $vp);
pump($app);
check($app->terminal->buffer()->count() >= 100, 'R6 命令产出足量输出行');
$app->handle(MouseEvent::new(MouseEventKind::ScrollUp, MouseButton::Left, 0, 0, 0), $vp);
check(!$app->terminal->follow, 'R6 滚轮上滚退出 follow 模式');
$app->handle(MouseEvent::new(MouseEventKind::ScrollDown, MouseButton::Left, 0, 0, 0), $vp);
check($app->terminal->scroll >= 0, 'R6 滚轮下滚不越界');
$app->handle(CodedKeyEvent::new(KeyCode::PageUp, 0), $vp);
check($app->terminal->scroll === 0, 'R6 PageUp 翻到顶部（钳制 ≥0）');

// Ctrl+L 清屏
$app->handle(CharKeyEvent::new('l', KeyModifiers::CONTROL), $vp);
check($app->terminal->buffer()->count() === 0, 'Ctrl+L 清空输出');

// 真实终端里回车是 CodedKeyEvent(Enter) 而不是 CharKeyEvent("\r")。
// 只在 CharKeyEvent 上挂提交逻辑的话，headless 全绿但 pty 下提交不了命令——
// 这里显式覆盖 CodedKeyEvent 路径，防回归。
typeTerm($app, $vp, 'echo coded-enter');
$app->handle(CodedKeyEvent::new(KeyCode::Enter, 0), $vp);
check($app->termRunning(), 'R2 CodedKeyEvent(Enter) 也能提交（pty 真实路径）');
pump($app);
$text = renderApp($app, $vp);
check(str_contains($text, 'coded-enter'), 'R3 Enter 路径提交的命令输出渲染');

// R7 判定顺序：运行中 Ctrl+C = 中断子进程，不是退出应用
typeTerm($app, $vp, 'sleep 20');
$app->handle(CharKeyEvent::new("\r", 0), $vp);
usleep(100000);
check($app->termRunning(), 'R7 长命令在跑');
$app->handle(CharKeyEvent::new('c', KeyModifiers::CONTROL), $vp);
check(!$app->quit, 'R7 运行中 Ctrl+C 不退出应用');
pump($app);
check(!$app->termRunning(), 'R7 命令被中断');
$text = renderApp($app, $vp);
check(str_contains($text, '已中断'), 'R7 显示中断提示');

// 运行中再提交 → busy 提示
typeTerm($app, $vp, 'sleep 20');
$app->handle(CharKeyEvent::new("\r", 0), $vp);
usleep(50000);
typeTerm($app, $vp, 'echo second');
$app->handle(CharKeyEvent::new("\r", 0), $vp);
$text = renderApp($app, $vp);
check(str_contains($text, '有命令正在运行'), '运行中提交给出 busy 提示');
$app->handle(CharKeyEvent::new('c', KeyModifiers::CONTROL), $vp);
pump($app);
check(!$app->termRunning(), 'busy 场景收尾：命令已中断');

// Esc 三级：运行中→中断；有输入→清空；空输入→退出
typeTerm($app, $vp, 'sleep 20');
$app->handle(CharKeyEvent::new("\r", 0), $vp);
usleep(50000);
$app->handle(CodedKeyEvent::new(KeyCode::Esc, 0), $vp);
check(!$app->quit, 'Esc 在命令运行时不退出（改为中断）');
pump($app);

typeTerm($app, $vp, 'abc');
$app->handle(CodedKeyEvent::new(KeyCode::Esc, 0), $vp);
check(!$app->quit && $app->terminal->input === '', 'Esc 在有输入时清空输入');
$app->handle(CodedKeyEvent::new(KeyCode::Esc, 0), $vp);
check($app->quit, 'Esc 在空输入时退出（保持原有手感）');

// 非运行状态下 Ctrl+Q 退出（Ctrl+C 已让位给「复制」，不再兼任退出热键）
$app2 = new App();
$app2->focusIndex = array_search('terminal', App::PANELS, true);
$app2->handle(CharKeyEvent::new('q', KeyModifiers::CONTROL), $vp);
check($app2->quit, '非运行时 Ctrl+Q 退出应用');

// 退出时清理：命令在跑时按 Ctrl+Q 直接退出，也要干净收尾（不留孤儿进程）
$app3 = new App();
$app3->focusIndex = array_search('terminal', App::PANELS, true);
typeTerm($app3, $vp, 'sleep 20');
$app3->handle(CharKeyEvent::new("\r", 0), $vp);
usleep(50000);
check($app3->termRunning(), '退出前命令确实在跑');
$app3->handle(CharKeyEvent::new('q', KeyModifiers::CONTROL), $vp);
check($app3->quit && !$app3->termRunning(), '运行中 Ctrl+Q 直接退出且命令已收尾');

echo $failed ? "RESULT: FAIL\n" : "RESULT: PASS\n";
exit($failed ? 1 : 0);

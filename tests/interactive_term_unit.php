<?php
declare(strict_types=1);

/**
 * 交互式 PTY 终端 —— headless 单测（纯逻辑 + 真实 pty 管道）。
 *
 * 复盖：
 *  - Vt100Emulator：CSI 光标定位 / SGR 着色 / EL·ED 擦除 / 交替屏 swap /
 *    滚动回退 / 保存·恢复光标 / 宽字符占位 / UTF-8 跨块拼接。
 *  - PtyProcess：headless 下 proc_open pty 跑 echo，读回含预期串（验证管道通）。
 *  - KeyToPty：Enter/Backspace/Delete/方向/Ctrl+C/Tab/F1 映射正确字节。
 *  - TerminalPanel 模式切换：App 聚焦终端 → toggleInteractive 进入 pty+captured；
 *    exitCapture 退捕获；再次 F2 重新捕获；Ctrl+D 后 pty 退出自动回 runner。
 *
 * 运行：php tests/interactive_term_unit.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\App;
use App\Terminal\KeyToPty;
use App\Terminal\PtyProcess;
use App\Terminal\Vt100Emulator;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event\FunctionKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\KeyModifiers;

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

// ── Vt100Emulator ────────────────────────────────────
echo "== Vt100Emulator ==\n";

$emu = new Vt100Emulator(80, 5);
$emu->write("\x1b[3;5HX"); // CUP 1-based (row3,col5) → 0-based (cy=2,cx=4)，写 X 后光标前进到 (cx=5)
$g = $emu->gridForRender(5, 0);
check($g['cursor'] === ['x' => 5, 'y' => 2], '写 X 后光标前进到 (cx=5, cy=2)');
check($g['lines'][2][4]->ch === 'X', 'CUP 后字符写在正确格 (row2,col4)');

// SGR 红字 + bold
$emu = new Vt100Emulator(80, 1);
$emu->write("\x1b[1;31mR");
$g = $emu->gridForRender(1, 0);
check($g['lines'][0][0]->fg === 1, 'SGR 31 → 前景色索引 1 (Red)');
check(($g['lines'][0][0]->flags & Vt100Emulator::FLAG_BOLD) !== 0, 'SGR 1 → bold 置位');

// EL 擦除到行尾
$emu = new Vt100Emulator(80, 1);
$emu->write("ABCDE\x1b[1;1H\x1b[0K"); // 回首格后整行擦除
$g = $emu->gridForRender(1, 0);
$blank = true;
foreach ($g['lines'][0] as $c) {
    if ($c->ch !== ' ') {
        $blank = false;
        break;
    }
}
check($blank, 'EL(0) 从光标到行尾擦空整行');

// ED 全屏擦除
$emu = new Vt100Emulator(10, 3);
$emu->write("ZZ\r\nZZ\r\nZZ\x1b[2J");
$g = $emu->gridForRender(3, 0);
$allBlank = true;
foreach ($g['lines'] as $row) {
    foreach ($row as $c) {
        if ($c->ch !== ' ') {
            $allBlank = false;
        }
    }
}
check($allBlank, 'ED(2) 全屏擦空');

// 交替屏 swap
$emu = new Vt100Emulator(10, 2);
$emu->write("MAIN");
$emu->write("\x1b[?1049h");
$emu->write("ALTSCREEN");
$gAlt = $emu->gridForRender(2, 0);
$altHead = $gAlt['lines'][0][0]->ch . $gAlt['lines'][0][1]->ch . $gAlt['lines'][0][2]->ch . $gAlt['lines'][0][3]->ch;
check(str_starts_with($altHead, 'ALTS'), '进入 1049h 后显示交替屏内容');
$emu->write("\x1b[?1049l");
$gBack = $emu->gridForRender(2, 0);
$backHead = $gBack['lines'][0][0]->ch . $gBack['lines'][0][1]->ch;
check(str_starts_with($backHead, 'MA'), '退出 1049l 后回到主屏 (MAIN)');

// 滚动回退：写 30 行到 5 行屏（每行后带 \r\n；末行下方留一空行，故可见 4 行）
$emu = new Vt100Emulator(20, 5);
$in = '';
for ($n = 1; $n <= 30; $n++) {
    $in .= "line$n\r\n";
}
$emu->write($in);
check($emu->scrollbackSize() === 26, '30 行写入 5 行屏 → 回退 26 行 (1..26)');
$g = $emu->gridForRender(5, 0);
$lastRow = '';
foreach ($g['lines'][3] as $c) {
    $lastRow .= $c->ch;
}
check(str_starts_with(trim($lastRow), 'line30'), '屏上最新一行 line30 可见 (row3)');

// 保存/恢复光标
$emu = new Vt100Emulator(20, 5);
$emu->write("\x1b[3;3H"); // (cy=2,cx=2)
$emu->write("\x1b7");      // 保存
$emu->write("\x1b[1;1HX"); // 移到 (0,0) 写 X
$emu->write("\x1b8");      // 恢复
$emu->write("Y");          // 应在 (2,2)
$g = $emu->gridForRender(5, 0);
check($g['lines'][2][2]->ch === 'Y', 'DECSC/DECRC 恢复光标后字符写到原位置 (2,2)');

// 宽字符占位（CJK 占 2 列）
$emu = new Vt100Emulator(20, 1);
$emu->write("\x1b[1;1H你"); // 全角「你」占两列
$g = $emu->gridForRender(1, 0);
check($g['lines'][0][0]->ch === '你', '宽字符写在左格');
check($g['lines'][0][1]->wide === true, '宽字符右格标记为 continuation');

// UTF-8 跨块拼接：分两半写入「好」(E5 A5 BD)
$emu = new Vt100Emulator(20, 1);
$emu->write("\xe5\xa5");      // 「好」前两字节
$emu->write("\xbd");          // 第三字节
$g = $emu->gridForRender(1, 0);
check($g['lines'][0][0]->ch === '好', '被劈开的多字节 UTF-8 在块边界拼接后正确');

// ── PtyProcess ───────────────────────────────────────
echo "== PtyProcess ==\n";
$pty = new PtyProcess();
$started = $pty->start((string) (getenv('SHELL') ?: '/bin/bash'), 80, 24, getcwd() ?: '.', []);
check($started, 'proc_open pty 成功启动交互式 shell');
if ($started) {
    $pty->write("echo hi_pty_42\n");
    $out = '';
    $deadline = microtime(true) + 3;
    while (microtime(true) < $deadline) {
        $out .= $pty->read();
        if (str_contains($out, 'hi_pty_42')) {
            break;
        }
        usleep(50000);
    }
    check(str_contains($out, 'hi_pty_42'), 'pty 读回 echo 输出 (管道双向通)');
    // resize 不应崩
    $pty->resize(100, 30);
    $pty->write("echo resized_ok\n");
    $r2 = '';
    $deadline = microtime(true) + 3;
    while (microtime(true) < $deadline) {
        $r2 .= $pty->read();
        if (str_contains($r2, 'resized_ok')) {
            break;
        }
        usleep(50000);
    }
    check(str_contains($r2, 'resized_ok'), 'resize 后 shell 仍正常响应');
    $pty->shutdown();
    check(!$pty->isRunning(), 'shutdown 后 pty 不在运行');
}

// ── KeyToPty ─────────────────────────────────────────
echo "== KeyToPty ==\n";
check(KeyToPty::encode(CharKeyEvent::new('a')) === 'a', '普通字符 a → "a"');
check(KeyToPty::encode(CodedKeyEvent::new(KeyCode::Enter)) === "\r", 'Enter → CR');
check(KeyToPty::encode(CodedKeyEvent::new(KeyCode::Backspace)) === "\x7f", 'Backspace → DEL(0x7f)');
check(KeyToPty::encode(CodedKeyEvent::new(KeyCode::Delete)) === "\x1b[3~", 'Delete → CSI 3~');
check(KeyToPty::encode(CodedKeyEvent::new(KeyCode::Up)) === "\x1b[A", 'Up → CSI A');
check(KeyToPty::encode(CodedKeyEvent::new(KeyCode::Down)) === "\x1b[B", 'Down → CSI B');
check(KeyToPty::encode(CodedKeyEvent::new(KeyCode::Tab)) === "\t", 'Tab → TAB');
check(KeyToPty::encode(FunctionKeyEvent::new(1)) === "\x1bOP", 'F1 → SS3 P');
$ctrlC = KeyToPty::encode(CharKeyEvent::new('c', KeyModifiers::CONTROL));
check($ctrlC === "\x03", 'Ctrl+C → 0x03');
check(KeyToPty::encode(CodedKeyEvent::new(KeyCode::Esc)) === null, 'Esc → null (由 App 退出捕获)');
check(KeyToPty::encode(FunctionKeyEvent::new(2)) === null, 'F2 → null (由 App 退出捕获)');
// Ctrl+方向
$ctrlUp = KeyToPty::encode(CodedKeyEvent::new(KeyCode::Up, KeyModifiers::CONTROL));
check($ctrlUp === "\x1b[1;5A", 'Ctrl+Up → CSI 1;5A');

// ── TerminalPanel 模式切换（经 App）────────────────────
echo "== TerminalPanel 模式切换 ==\n";
$app = new App();
$app->focus('terminal');
$term = $app->terminal;
check($term->mode === 'runner', '初始为 runner 模式');
$term->toggleInteractive();
check($term->mode === 'pty', 'F2 进入 pty 模式');
check($term->isCaptured(), '进入后处于捕获态');
// 触发首帧渲染消费 ptyStartPending：F2 切 pty 后 pty 推迟到首帧 ptyContent()
// 用真实面板尺寸起（避免按错误 LINES 渲染全屏程序），故需先渲染一帧才能 isRunning()。
$term->content(\PhpTui\Tui\Display\Area::fromDimensions(120, 40), true);
check($term->isRunning(), 'pty shell 在运行');
$term->exitCapture();
check(!$term->isCaptured(), 'exitCapture 退出捕获（shell 仍在跑）');
$term->toggleInteractive(); // pty && !captured → 重新捕获
check($term->isCaptured(), '再次 F2 重新进入捕获');
// 渲染 pty 内容不崩
$area = \PhpTui\Tui\Display\Area::fromDimensions(120, 40);
try {
    $term->content($area, true);
    $ok = true;
} catch (\Throwable $e) {
    echo '    render error: ' . $e->getMessage() . "\n";
    $ok = false;
}
check($ok, 'pty 模式 content() 渲染不抛异常');
// 着色渲染：向 pty 发送带 SGR 颜色的字节，让仿真器产生真实颜色索引格，
// 再渲染 content()（真实 pty 曾因 styleAndKey 里 (string)Color 对象崩溃）。
$term->sendToPty("\x1b[38;5;196mCOLOR_OK\x1b[0m\r");
for ($i = 0; $i < 40; $i++) {
    $term->poll();
    usleep(20000);
}
try {
    $term->content($area, true);
    $okColor = true;
} catch (\Throwable $e) {
    echo '    color render error: ' . $e->getMessage() . "\n";
    $okColor = false;
}
check($okColor, '着色单元格 content() 渲染不抛异常（真实 pty 曾崩于 Color 对象强转）');
// Ctrl+D 退出 shell → 自动回 runner
// 先排空启动期输出：固定等待跨过 bash 初始化，再静候直至「已见过输出且连续静默」
// （= bash 阻塞在 stdin 读），此时 Ctrl+D(EOF) 才会被读成空行 EOF 退出；否则字节早于读点被丢弃。
$seen = false;
$quiet = 0;
$minWait = microtime(true) + 1.5; // 固定最小等待，跨过启动期
$deadline = microtime(true) + 6;
while (microtime(true) < $deadline) {
    $got = $term->poll();
    if ($got) {
        $seen = true;
        $quiet = 0;
    } else {
        $quiet++;
    }
    if (microtime(true) >= $minWait && $seen && $quiet >= 8) { // 见输出后约 400ms 静默
        break;
    }
    usleep(50000);
}
$term->sendToPty("\x04");
$deadline = microtime(true) + 3;
while (microtime(true) < $deadline) {
    $term->poll();
    if ($term->mode === 'runner') {
        break;
    }
    usleep(50000);
}
check($term->mode === 'runner', 'Ctrl+D 退出 shell 后自动退回 runner 模式');
$term->shutdown();

echo $failed ? "\ninteractive_term_unit FAIL\n" : "\ninteractive_term_unit PASS\n";
exit($failed ? 1 : 0);

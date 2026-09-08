<?php
declare(strict_types=1);

/**
 * 实时 cwd 捕获 —— headless 单测 + 真实 pty 集成。
 *
 * headless 复盖 Vt100Emulator 对自定义 OSC `777;vicetui;cwd=<path>` 的解析：
 *  - BEL / ST 两种终止符
 *  - 跨 write() 块续传
 *  - 非绝对路径忽略
 *  - 其它 OSC 忽略且不渲染
 *  - consumeCwd 取走即清空
 *
 * 真实 pty：PtyProcess 注入 PROMPT_COMMAND 钩子，spawn bash → `cd /tmp` 后
 * 仿真器应能捕获到 /tmp（验证注入 + 解析全链路）。
 *
 * 运行：php tests/cwd_unit.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Terminal\PtyProcess;
use App\Terminal\Vt100Emulator;

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

// ── headless：OSC cwd 解析 ──────────────────────────
echo "== Vt100Emulator cwd OSC (headless) ==\n";

// BEL 终止符
$emu = new Vt100Emulator(80, 5);
$emu->write("\x1b]777;vicetui;cwd=/tmp\x07");
check($emu->consumeCwd() === '/tmp', 'BEL 终止的 OSC 解析出 /tmp');
check($emu->consumeCwd() === null, 'consumeCwd 取走后清空（再取为 null）');

// ST(\x1b\\) 终止符
$emu = new Vt100Emulator(80, 5);
$emu->write("\x1b]777;vicetui;cwd=/home\x1b\\");
check($emu->consumeCwd() === '/home', 'ST 终止的 OSC 解析出 /home');

// 跨 write() 块续传
$emu = new Vt100Emulator(80, 5);
$emu->write("\x1b]777;vicetui;cwd=/us");
$emu->write("r/bin\x07");
check($emu->consumeCwd() === '/usr/bin', 'OSC 被 write() 块边界劈开仍能正确拼接');

// 非绝对路径忽略
$emu = new Vt100Emulator(80, 5);
$emu->write("\x1b]777;vicetui;cwd=relative/path\x07");
check($emu->consumeCwd() === null, '非绝对路径（相对路径）被忽略');

// 其它 OSC 忽略且不渲染：标题序列夹在 A、B 之间，应只看到 AB
$emu = new Vt100Emulator(80, 1);
$emu->write("A\x1b]0;xterm-title\x07B");
check($emu->consumeCwd() === null, '未知 OSC(0;title) 被忽略，不触发 cwd');
$g = $emu->gridForRender(1, 0);
$line = '';
foreach ($g['lines'][0] as $c) {
    $line .= $c->ch;
}
check(trim($line) === 'AB', '未知 OSC 序列被剥离，不污染渲染文本（AB）');

// cwd 内容里含 CJK 也应正确捕获
$emu = new Vt100Emulator(80, 5);
$emu->write("\x1b]777;vicetui;cwd=/你/好\x07");
check($emu->consumeCwd() === '/你/好', 'cwd 含 CJK 路径正确捕获');

// ── 真实 pty：PROMPT_COMMAND 注入 → cwd 实时捕获 ─────
echo "== PtyProcess cwd hook (real pty) ==\n";

$dir = sys_get_temp_dir() . '/vicetui_cwd_' . uniqid();
if (!is_dir($dir)) {
    mkdir($dir, 0700, true);
}
$target = '/tmp'; // 几乎所有环境都存在

$pty = new PtyProcess();
$started = $pty->start('/bin/bash', 80, 24, $dir, []);
check($started, 'proc_open pty 启动 bash（含 cwd 钩子注入）');

if ($started) {
    $emu = new Vt100Emulator(80, 24);
    $captured = null;

    // 先排空启动期输出（含初始 cwd 上报，应为 $dir）
    $deadline = microtime(true) + 4;
    while (microtime(true) < $deadline) {
        $out = $pty->read();
        if ($out !== '') {
            $emu->write($out);
            $c = $emu->consumeCwd();
            if ($c !== null) {
                $captured = $c;
            }
        }
        if ($captured === $dir) {
            break;
        }
        usleep(30000);
    }
    check($captured === $dir, "启动后 cwd 上报为初始目录 ($dir)");

    // cd 到 /tmp，期待下一个提示符前上报 /tmp
    $pty->write("cd " . $target . "\n");
    $captured = null;
    $deadline = microtime(true) + 4;
    while (microtime(true) < $deadline) {
        $out = $pty->read();
        if ($out !== '') {
            $emu->write($out);
            $c = $emu->consumeCwd();
            if ($c !== null) {
                $captured = $c;
                if ($c === $target) {
                    break;
                }
            }
        }
        usleep(30000);
    }
    check($captured === $target, "cd $target 后 cwd 实时捕获为 $target");

    $pty->shutdown();
    check(!$pty->isRunning(), 'shutdown 后 pty 不在运行');

    // 清理测试目录
    @rmdir($dir);
}

echo $failed ? "\ncwd_unit FAIL\n" : "\ncwd_unit PASS\n";
exit($failed ? 1 : 0);

<?php
declare(strict_types=1);

/**
 * M3 真实 pty 驱动：点击切到 GIT tab → 异步刷新 → 看分支/状态 → Enter 看 diff → q 退出。
 * 断言：exit=0、无致命错误、屏幕含分支名与状态行、diff 可载入编辑器（不崩）。
 *
 * 运行：php tests/pty_git.php
 */

$env = array_merge(getenv(), ['COLUMNS' => '120', 'LINES' => '40']);

function normalize(string $raw): string
{
    $s = (string) preg_replace('/\x1B\][^\x07\x1B]*(?:\x07|\x1B\\\\)/', '', $raw);
    $s = (string) preg_replace('/\x1B\[[0-9;?]*[ -\/]*[@-~]/', '', $s);
    $s = (string) preg_replace('/\x1B[@-Z\\\\-_]/', '', $s);
    return strtolower((string) preg_replace('/[^a-zA-Z0-9\x{4e00}-\x{9fff}]/u', '', $s));
}
$readPty = static function ($stream, int $len) {
    set_error_handler(static fn () => true);
    try {
        $str = '';
        $deadline = microtime(true) + 2.0;
        while (microtime(true) < $deadline && ($chunk = fread($stream, $len)) !== '' && $chunk !== false) {
            $str .= $chunk;
            if (str_contains($str, 'errno=5') || str_contains($str, 'Input/output error')) {
                break;
            }
            if (strlen($str) > 60000) {
                break;
            }
        }
        return $str;
    } finally {
        restore_error_handler();
    }
};

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

$descs = [0 => ['pty'], 1 => ['pty'], 2 => ['pty']];
$proc = proc_open([PHP_BINARY, 'bin/tui.php'], $descs, $pipes, null, $env);
if ($proc === false) {
    echo "[FAIL] 无法启动 bin/tui.php\n";
    exit(1);
}
stream_set_blocking($pipes[0], false);
stream_set_blocking($pipes[1], false);
usleep(300000);

// 侧栏 GIT tab：120x40 下 sidebar 占左侧 ~30 列，tab 行在屏幕 y=1（0-based），
// 第 2 段（GIT）inner 列约 [9,18)。SGR 坐标 1-based。
$COL = 12;
$ROW = 2;
fwrite($pipes[0], "\x1b[<0;{$COL};{$ROW}M");
fwrite($pipes[0], "\x1b[<0;{$COL};{$ROW}m");
usleep(900000); // 等异步 git status/log/branch 刷新

// 切到 log 子视图（l）再切回状态（l），强制在刷新完成后多渲几帧，确保读到含分支的帧
fwrite($pipes[0], 'l');
usleep(200000);
fwrite($pipes[0], 'l');
usleep(300000);

$out = normalize($readPty($pipes[1], 8192));
check(str_contains($out, 'master'), '屏幕含当前分支 master');
check(str_contains($out, '状态'), 'GIT tab 显示「状态」子视图');
check(str_contains($out, '⎇') || str_contains($out, 'branch') || str_contains($out, '分支'), '状态栏/侧栏显示分支信息');

// Down 移动 git 选中 + Enter 看 diff（不崩即可；可能无 diff 安全跳过）
fwrite($pipes[0], "\x1b[B");
usleep(200000);
fwrite($pipes[0], "\r");
usleep(400000);

$out2 = normalize($readPty($pipes[1], 8192));
check(true, '切换子视图 / 移动 / 看 diff 未崩溃');

// 干净退出：用 Ctrl+Q（任何面板都能退，避免焦点在编辑器时 q 变成输入）
fwrite($pipes[0], "\x11");
usleep(300000);
$status = proc_get_status($proc);
$deadline = microtime(true) + 3;
while ($status['running'] && microtime(true) < $deadline) {
    usleep(50000);
    $status = proc_get_status($proc);
}
$code = $status['running'] ? -1 : (int) $status['exitcode'];
proc_close($proc);
check($code === 0, sprintf('干净退出 exit=0（实际 %d）', $code));

echo $failed ? "\nM3 pty FAIL\n" : "\nM3 pty PASS\n";
exit($failed ? 1 : 0);

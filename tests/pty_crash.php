<?php
declare(strict_types=1);

/**
 * R3 真实 pty：未捕获异常也必须还原终端。
 *
 * 为什么单独开一个文件：终端被留在 raw mode + alternate screen 时，用户的 shell
 * 直接废掉（无回显、无光标），是所有故障里最严重的一种。而这条路径在正常退出时
 * 测不出来 —— 必须在**真的崩了**的情况下验证。
 *
 * 怎么造崩溃而不污染产品代码：运行时把 bin/vicecode.php 复制一份、在启动后注入
 * 一行 `throw`，跑这个副本。产品代码里不留任何测试开关。
 *
 * 断言什么：进程非 0 退出，但**崩溃前仍发出了还原终端的转义序列**
 * （`ESC[?1049l` 退出 alternate screen、`ESC[?25h` 显示光标）。
 * 光看退出码不够 —— 崩溃时退出码必然非 0，看不出终端有没有被还原。
 *
 * 运行：php tests/pty_crash.php
 */

chdir(__DIR__ . '/..');
$root = getcwd();

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

/** 生成一份「会崩」的入口副本，返回路径 */
function makeCrashingEntry(string $root): string
{
    $src = (string) file_get_contents($root . '/bin/vicecode.php');
    $marker = '$display = DisplayBuilder::default($backend)->fullscreen()->build();';
    if (!str_contains($src, $marker)) {
        throw new RuntimeException('找不到注入点，bin/vicecode.php 结构变了');
    }
    $out = str_replace(
        $marker,
        $marker . "\n    throw new \\RuntimeException('crash injected by tests/pty_crash.php');",
        $src
    );
    // ⚠️ 必须放在**项目根下面**：入口用 __DIR__.'/../vendor/autoload.php' 定位自动加载，
    // 放到 /tmp 会解析成 /vendor/autoload.php 而直接 Fatal（那样测的是加载失败，不是崩溃还原）。
    // 用点开头的目录，避免污染 bin/ —— pty_mouse 会按固定坐标点 bin/ 里的条目。
    $dir = $root . '/.vicecode_crash_' . getmypid();
    @mkdir($dir);
    $path = $dir . '/vicecode.php';
    file_put_contents($path, $out);
    return $path;
}

/**
 * @param bool $sendQuit 是否在启动后发 Ctrl+Q（对照组需要，崩溃组不需要——它自己会死）
 */
function runInPty(string $script, array $envExtra, bool $sendQuit = false): array
{
    $descs = [0 => ['pty'], 1 => ['pty'], 2 => ['pty']];
    $env = array_merge(getenv(), ['COLUMNS' => '120', 'LINES' => '40'], $envExtra);
    $p = proc_open([PHP_BINARY, $script], $descs, $pipes, $env['PWD'] ?? null, $env);
    if ($p === false) {
        return [-1, '', ''];
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    if ($sendQuit) {
        usleep(500000);
        fwrite($pipes[0], "\x11"); // Ctrl+Q
    }

    $out = '';
    $err = '';
    $deadline = microtime(true) + 8;
    while (true) {
        $c = @fread($pipes[1], 8192);
        if (is_string($c) && $c !== '') {
            $out .= $c;
        }
        $c2 = @fread($pipes[2], 8192);
        if (is_string($c2) && $c2 !== '') {
            $err .= $c2;
        }
        $st = proc_get_status($p);
        if (!$st['running']) {
            break;
        }
        if (microtime(true) > $deadline) {
            proc_terminate($p, SIGKILL);
            break;
        }
        usleep(30000);
    }
    // 收尾再读一轮
    $c = @fread($pipes[1], 8192);
    if (is_string($c)) {
        $out .= $c;
    }
    $c2 = @fread($pipes[2], 8192);
    if (is_string($c2)) {
        $err .= $c2;
    }
    $st = proc_get_status($p);
    $code = $st['running'] ? -1 : (int) $st['exitcode'];
    proc_close($p);

    // ⚠️ proc_open 的三个 ['pty'] 描述符**共用同一个 pty**（实测：子进程同时写
    // STDOUT/STDERR 时，$pipes[1] 一次就读到 "OUTERR"，$pipes[2] 读到 false）。
    // 所以两个管道是在抢同一份数据，先读的拿走。断言必须看两者的并集，
    // 只查 $out 会随机漏掉尾部（表现为"还原序列时有时无"）。
    return [$code, $out . $err, ''];
}

$crash = makeCrashingEntry($root);
register_shutdown_function(static function () use ($crash): void {
    @unlink($crash);
    @rmdir(dirname($crash));
});

echo "== 崩溃路径（注入 throw）==\n";
[$code, $out, $err] = runInPty($crash, []);
check($code !== 0, '崩溃时进程非 0 退出（实际 ' . $code . '）');
check(str_contains($err, 'crash injected') || str_contains($out, 'Uncaught'),
    '异常被顶层捕获并报错（不是静默死掉）');
// 核心断言：还原序列必须在崩溃后仍然发出
check(str_contains($out, "\x1b[?1049l"), '崩溃后仍发出 ESC[?1049l（退出 alternate screen）');
check(str_contains($out, "\x1b[?25h"), '崩溃后仍发出 ESC[?25h（显示光标）');
check(str_contains($out, "\x1b[?1000l"), '崩溃后仍发出 ESC[?1000l（关闭鼠标捕获）');

echo "== 对照组：正常退出也要还原（不能因为改了结构就丢）==\n";
[$code2, $out2, $err2] = runInPty($root . '/bin/vicecode.php', [], true);
check($code2 === 0, '正常路径 exit=0（实际 ' . $code2 . '）');
check(str_contains($out2, "\x1b[?1049l"), '正常路径也发出 ESC[?1049l');
check(str_contains($out2, "\x1b[?25h"), '正常路径也发出 ESC[?25h');
// 还原序列只应出现一次：多来一遍说明还原逻辑被挂了两条（defer + finally 重复）
$restoreCount = preg_match_all('/\x1b\[\?1049l/', $out2);
check($restoreCount === 1, '还原序列只发一次（实际 ' . $restoreCount . ' 次）—— 重复说明还原被挂了多处');

@unlink($crash);
@rmdir(dirname($crash));
echo $failed ? "\nR3 崩溃还原 FAIL\n" : "\nR3 崩溃还原 PASS\n";
exit($failed ? 1 : 0);

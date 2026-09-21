<?php
declare(strict_types=1);

/**
 * 真实 pty：**被信号终止**时终端必须还原、且必须留下日志（D11）。
 *
 * ## 为什么需要它
 * `bin/vicecode.php` 原来只有两条收尾路径：`start()` 的 finally（正常退出 + 可捕获异常）
 * 与顶层 `register_shutdown_function`（E_ERROR / E_USER_ERROR / 内存耗尽）。
 * **信号两者都覆盖不到**：不装 handler 时默认动作是「进程立即终止」，
 * PHP 既不执行 shutdown function、也不走 finally；而 `error_get_last()` 是 null
 * （信号不是 error）→ 终端留在 raw + 备用屏 + 鼠标上报，**日志也不写**。
 *
 * 这正是用户报上来的现象本身（`-bash: 35: command not found`：鼠标上报灌进 shell），
 * 而且它是唯一「查不到原因」的死法 —— 所以在修好之前先把它钉住。
 *
 * ## 覆盖
 *   ① 三个信号（SIGTERM / SIGHUP / SIGINT）各自：还原三连 + 日志记到信号名
 *      + 退出码 = 128+信号 + **存活标记残留**（异常结束，下次启动要提示）；
 *   ② **正常退出**（Ctrl+Q）反过来：存活标记必须被清掉，且不产生致命日志 ——
 *      没有这条反面对照，①里「标记残留」的断言可能只是恒真。
 *
 * 运行：php tests/pty_signals.php（要求真实 pty 环境）
 */

chdir(__DIR__ . '/..');
$root = getcwd();
require __DIR__ . '/lib/isolation.php';

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

/** fread pty 时对端关闭会抛 EIO，静音掉（与其它 pty 用例一致） */
$readPty = static function ($stream, int $len) {
    set_error_handler(static fn(int $no, string $str): bool
        => str_contains($str, 'errno=5') || str_contains($str, 'Input/output error'));
    try {
        return fread($stream, $len);
    } finally {
        restore_error_handler();
    }
};

/** 把 pty 里已有的输出排空到 $out，直到 $until 出现或超时 */
$drain = static function ($pipes, string &$out, float $seconds, ?callable $until = null) use ($readPty): bool {
    $end = microtime(true) + $seconds;
    while (microtime(true) < $end) {
        $r = [$pipes[1]];
        $w = $e = [];
        if (stream_select($r, $w, $e, 0, 150000) > 0) {
            $chunk = $readPty($pipes[1], 65536);
            if ($chunk === '' || $chunk === false) {
                break;   // 对端已关
            }
            $out .= $chunk;
            if ($until !== null && $until($out)) {
                return true;
            }
        }
    }
    return $until === null ? true : $until($out);
};

/**
 * 起一个隔离配置下的应用，等到它真的进入备用屏（raw + 鼠标捕获都已生效）。
 *
 * @return array{0:resource,1:array,2:string} [proc, pipes, 已捕获输出]
 */
$startApp = static function (string $tag, array $extraEnv = [], ?string $cfg = null) use ($root, $drain): array {
    $cfg ??= vc_isolate_config($tag);
    $descs = [0 => ['pty'], 1 => ['pty'], 2 => ['pty']];
    $proc = proc_open([PHP_BINARY, $root . '/bin/vicecode.php'], $descs, $pipes, $root,
        ['VICECODE_CONFIG' => $cfg] + $extraEnv);
    $out = '';
    $drain($pipes, $out, 10.0, static fn(string $s): bool => str_contains($s, "\e[?1049h"));
    // ⚠️ `?1049h` 是 flush 出来的，此后应用还要建 Display / 起读键协程，期间若有
    // termios 变更会把 pty 的待读输入冲掉 —— 实测这段窗口里发按键会**丢键**
    // （Ctrl+Q 无反应、必须 SIGKILL 收场）。等它静下来再发。
    usleep(900_000);
    return [$proc, $pipes, $out, $cfg];
};

/**
 * 收摊：**轮询等它真的退出**再取状态。
 *
 * ⚠️ 不能看到还原序列就立刻取状态：`?1049l` 是在 handler 里发完还原序列之后、
 * 重发信号之前打出来的，那一刻进程还活着 —— 立刻读会得到 running=true，
 * 于是被我们 SIGKILL 掉，`termsig` 永远是 9。
 *
 * ⚠️ 也**不能**在它退出后再调一次 `proc_get_status()`：PHP 的语义是「首次报告
 * 未运行的那次调用带正确 exitcode，之后再调得到 -1」，所以只在循环里取一次。
 *
 * @return array proc_get_status() 的原始状态（running=true 表示是我们强杀的）
 */
$reap = static function ($proc, float $wait = 5.0): array {
    $end = microtime(true) + $wait;
    while (true) {
        $st = proc_get_status($proc);
        if (!$st['running'] || microtime(true) >= $end) {
            break;
        }
        usleep(100_000);
    }
    if ($st['running']) {
        proc_terminate($proc, SIGKILL);
        proc_close($proc);
        return ['running' => true];
    }
    proc_close($proc);
    return $st;
};

$cases = [
    ['SIGTERM', SIGTERM],
    ['SIGHUP',  SIGHUP],
    ['SIGINT',  SIGINT],
];

echo "== 被信号终止：还原终端 + 留日志 + 标记残留 ==\n";
foreach ($cases as [$name, $signo]) {
    [$proc, $pipes, $out, $cfg] = $startApp('vc_sig_' . strtolower($name));
    // 正向锚点：应用没起来的话，下面「有还原序列」之类的断言毫无意义
    check(str_contains($out, "\e[?1000h"), "{$name} 前置：应用已捕获鼠标（进入可被破坏的状态）");

    proc_terminate($proc, $signo);
    $drain($pipes, $out, 6.0);
    $st = $reap($proc);

    check(str_contains($out, "\e[?1000l"), "{$name}：还原了鼠标捕获 ?1000l");
    check(str_contains($out, "\e[?1049l"), "{$name}：退出了 alternate screen ?1049l");
    check(str_contains($out, "\e[?25h"), "{$name}：恢复了光标 ?25h");

    $logPath = dirname($cfg) . '/.vicecode_fatal.log';
    $logTxt = is_file($logPath) ? (string) file_get_contents($logPath) : '';
    check(str_contains($logTxt, $name), "{$name}：日志里记下了死因 —— 内容 "
        . var_export(trim($logTxt), true));
    // 收尾后重发信号 → 进程应「真的被该信号终止」（termsig），而不是靠 exit(128+n) 假装
    check(($st['signaled'] ?? false) === true && ($st['termsig'] ?? 0) === $signo,
        "{$name}：进程确实被该信号终止（signaled=" . var_export($st['signaled'] ?? null, true)
        . " termsig=" . var_export($st['termsig'] ?? null, true) . '）');
    // 异常结束 → 存活标记必须留着，下次启动才能提示「上次没正常退出」
    check(is_file(dirname($cfg) . '/.vicecode_alive'), "{$name}：存活标记残留（下次启动会提示）");

    @unlink($logPath);
}

echo "\n== 非 Swoole 底座（TUI_USE_SWOOLE=0）==\n";
// 两种底座的退出路径不同（协程调度 vs 阻塞读循环），历史上也各自出过不一样的问题，
// 所以信号这一项单独覆盖一例 —— 尤其「协程内 exit() 被转成 Swoole\ExitException」
// 那个坑只存在于 Swoole 侧，非协程侧走的是另一条分支。
[$proc, $pipes, $out, $cfg] = $startApp('vc_sig_noswoole', ['TUI_USE_SWOOLE' => '0']);
check(str_contains($out, "\e[?1000h"), '非 Swoole 前置：应用已捕获鼠标');
proc_terminate($proc, SIGTERM);
$drain($pipes, $out, 6.0);
$st = $reap($proc);
check(str_contains($out, "\e[?1000l") && str_contains($out, "\e[?1049l") && str_contains($out, "\e[?25h"),
    '非 Swoole：终端已还原（?1000l / ?1049l / ?25h）');
check(($st['signaled'] ?? false) === true && ($st['termsig'] ?? 0) === SIGTERM,
    '非 Swoole：进程被 SIGTERM 终止（signaled=' . var_export($st['signaled'] ?? null, true)
    . ' termsig=' . var_export($st['termsig'] ?? null, true) . '）');
@unlink(dirname($cfg) . '/.vicecode_fatal.log');

echo "\n== 残留标记 → 下次启动必须提示 ==\n";
// 存活标记的**用途**就在这一步：光「留下标记」没人看得见，得下次启动时说出来。
// 用一个肯定不存在的 pid，免得 staleAlive() 的 pid 存活检查把它当成"另一个在跑的实例"。
$dir = vc_tmp_dir('vc_sig_stale');
$cfg = $dir . '/.vicerc';
file_put_contents(
    $dir . '/.vicecode_alive',
    json_encode(['since' => '2026-01-01T00:00:00+00:00', 'pid' => 4194304], JSON_UNESCAPED_SLASHES) . "\n"
);
[$proc, $pipes, $out, ] = $startApp('vc_sig_stale', [], $cfg);
check(str_contains($out, "\e[?1000h"), '前置：应用已起来（同一份配置目录）');

$logPath = $dir . '/.vicecode_fatal.log';
$logTxt = is_file($logPath) ? (string) file_get_contents($logPath) : '';
check(str_contains($logTxt, '上次会话未正常结束'),
    '上次异常结束 → 本次启动往日志写了 WARN —— 内容 ' . var_export(trim($logTxt), true));
$marker = json_decode((string) @file_get_contents($dir . '/.vicecode_alive'), true);
check(is_array($marker) && ($marker['pid'] ?? 0) !== 4194304,
    '本次启动把自己写进标记（覆盖掉旧的）—— 实际 ' . json_encode($marker));

fwrite($pipes[0], "\x11");   // Ctrl+Q 收尾（保持用例干净）
$drain($pipes, $out, 8.0, static fn(string $s): bool => str_contains($s, "\e[?1049l"));
$reap($proc);

echo "\n== 反面对照：正常退出必须清掉存活标记 ==\n";
[$proc, $pipes, $out, $cfg] = $startApp('vc_sig_clean');
check(str_contains($out, "\e[?1000h"), '前置：应用已起来');
$alivePath = dirname($cfg) . '/.vicecode_alive';
check(is_file($alivePath), '前置：启动时确实写了存活标记');

fwrite($pipes[0], "\x11");   // Ctrl+Q：无未保存改动时直接退出
$drain($pipes, $out, 8.0, static fn(string $s): bool => str_contains($s, "\e[?1049l"));
$st = $reap($proc);

check(($st['exitcode'] ?? null) === 0, '正常退出退出码为 0（实际 '
    . var_export($st['exitcode'] ?? null, true) . '）');
check(!is_file($alivePath), '正常退出**清掉**了存活标记（否则下次启动会误报）');
check(!is_file(dirname($cfg) . '/.vicecode_fatal.log'), '正常退出不产生致命日志');

echo $failed ? "\n信号还原 FAIL\n" : "\n信号还原 PASS\n";
exit($failed ? 1 : 0);

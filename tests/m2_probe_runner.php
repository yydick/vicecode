<?php
declare(strict_types=1);

/**
 * M2 探针（最小样本先行）：验证「proc_open 子进程 + 非阻塞管道 + 主循环轮询」范式。
 *
 * 分两组对照跑，因为 proc_close() 的退出码语义会被 Swoole HOOK 改写（见 C2）：
 *   A 组：无 HOOK
 *   B 组：SWOOLE_HOOK_ALL & ~SWOOLE_HOOK_STDIO（bin/tui.php 实际使用的 flags）
 *
 * 运行：php tests/m2_probe_runner.php
 */

$failed = false;

function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

/**
 * 模拟主循环：跑一条命令，每轮非阻塞排空两个管道，返回收集结果。
 *
 * @return array{out:string,err:string,exit:?int,termsig:int,closeRaw:int,loops:int,sec:float}
 */
function runCommand(string $shellCmd, float $maxSec = 20.0): array
{
    $desc = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $p = proc_open(['/bin/sh', '-c', $shellCmd], $desc, $pipes);
    if (!is_resource($p)) {
        throw new RuntimeException('proc_open failed');
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $buf = [1 => '', 2 => ''];
    $loops = 0;
    $exit = null;
    $sig = 0;
    $t0 = microtime(true);

    while (true) {
        $loops++;
        foreach ([1, 2] as $i) {
            while (true) {
                $c = fread($pipes[$i], 32768);
                if ($c === false || $c === '') {
                    break;
                }
                $buf[$i] .= $c;
            }
        }
        $st = proc_get_status($p);
        if ($st['running'] === false) {
            // 进程已退出：再排空一次，拿走管道里的残留数据
            foreach ([1, 2] as $i) {
                while (true) {
                    $c = fread($pipes[$i], 32768);
                    if ($c === false || $c === '') {
                        break;
                    }
                    $buf[$i] .= $c;
                }
            }
            $exit = $st['exitcode'];
            $sig = $st['termsig'];
            break;
        }
        usleep(2000);
        if (microtime(true) - $t0 > $maxSec) {
            break;
        }
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    $closeRaw = proc_close($p);

    return [
        'out' => $buf[1],
        'err' => $buf[2],
        'exit' => $exit,
        'termsig' => $sig,
        'closeRaw' => is_int($closeRaw) ? $closeRaw : -1,
        'loops' => $loops,
        'sec' => round(microtime(true) - $t0, 2),
    ];
}

function runAllChecks(): void
{
    // C1 大输出：5 万 stdout + 5 万 stderr，轮询排空不死锁、不丢行
    $r = runCommand('seq 1 50000 | while read i; do echo "o$i"; echo "e$i" >&2; done; exit 3');
    $o = substr_count($r['out'], "\n");
    $e = substr_count($r['err'], "\n");
    check($o === 50000 && $e === 50000, "C1 10万行不丢（stdout=$o stderr=$e loops={$r['loops']} {$r['sec']}s）");

    // C2 退出码语义：一律用 proc_get_status()['exitcode']
    foreach ([['exit 0', 0], ['false', 1], ['exit 42', 42]] as [$cmd, $want]) {
        $r = runCommand($cmd);
        check(
            $r['exit'] === $want,
            "C2 exitcode(`$cmd`)={$r['exit']} 期望={$want}（proc_close 原始返回={$r['closeRaw']}）"
        );
    }

    // C3 尾部残留：命令最后一段不带换行，退出前的补排空必须拿到
    $r = runCommand('seq 1 2000; printf "TAIL-NO-NEWLINE"');
    check(
        str_ends_with($r['out'], 'TAIL-NO-NEWLINE') && substr_count($r['out'], "\n") === 2000,
        'C3 尾部无换行残留不丢（末尾=' . var_export(substr($r['out'], -16), true) . '）'
    );

    // C4 中断：proc_terminate(SIGKILL)
    $desc = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $p = proc_open(['/bin/sh', '-c', 'sleep 50'], $desc, $pp);
    stream_set_blocking($pp[1], false);
    usleep(200000);
    $before = proc_get_status($p);
    proc_terminate($p, SIGKILL);
    usleep(200000);
    $after = proc_get_status($p);
    fclose($pp[1]);
    fclose($pp[2]);
    proc_close($p);
    check(
        $before['running'] === true && $after['running'] === false && $after['termsig'] === 9,
        "C4 中断（before running=" . var_export($before['running'], true)
            . " after running=" . var_export($after['running'], true) . " termsig={$after['termsig']}）"
    );

    // C5 背压：主循环 stall 1.5s 不轮询（管道缓冲被打满），恢复后仍要收全
    $desc = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $p = proc_open(['/bin/sh', '-c', 'seq 1 20000; sleep 0.3'], $desc, $pp);
    stream_set_blocking($pp[1], false);
    usleep(1500000);                     // 故意 stall：模拟主循环被别的事占住
    $got = '';
    while (true) {
        while (true) {
            $c = fread($pp[1], 32768);
            if ($c === false || $c === '') {
                break;
            }
            $got .= $c;
        }
        if (proc_get_status($p)['running'] === false) {
            while (true) {
                $c = fread($pp[1], 32768);
                if ($c === false || $c === '') {
                    break;
                }
                $got .= $c;
            }
            break;
        }
        usleep(2000);
    }
    fclose($pp[1]);
    fclose($pp[2]);
    proc_close($p);
    check(substr_count($got, "\n") === 20000, 'C5 stall 1.5s 背压后收全（行数=' . substr_count($got, "\n") . '）');

    // C6 原始字节：NUL / 非 UTF-8 / ANSI 序列原样到达，不被截断
    $r = runCommand('printf "a\\000b\\377\\376c\\033[31mred\\033[0m"');
    $want = "a\000b\xff\xfec\x1b[31mred\x1b[0m";
    check($r['out'] === $want, 'C6 原始字节不损（长度=' . strlen($r['out']) . ' 期望=' . strlen($want) . '）');
}

/**
 * M2 选定的 hook flags。
 *
 * 关掉 HOOK_FILE 的原因：Swoole 会接管 proc_open 的管道 fd 并注册进 reactor，
 * proc_close 后延迟释放再次 close → 往 stdout 吐
 * `WARNING network::socket_free_defer(): close(N) failed`，在 TUI 里直接打进
 * alternate screen 造成花屏。关掉即消失（实测噪音 2 行 → 0）。
 *
 * 关掉 HOOK_PROC 的原因：① 接管后 proc_close() 返回值被改写（exit 42 → 0），
 * 退出码必须改走 proc_get_status()['exitcode']；② 接管后 proc_open 只能在协程内
 * 调用（"API must be called in the coroutine"），headless 测试无法直接测 runner。
 * 关掉后 proc_open/proc_close 恢复原生语义，C2 的 closeRaw 也变回正确值。
 */
const M2_HOOK_FLAGS = SWOOLE_HOOK_ALL & ~SWOOLE_HOOK_STDIO & ~SWOOLE_HOOK_FILE & ~SWOOLE_HOOK_PROC;

/**
 * 起一个子进程跑本脚本的某一组，捕获 stdout/stderr（用于检测 Swoole 噪音）。
 * 注意：Swoole 的 socket_free_defer 警告走 **stdout**，两个流都要查。
 *
 * @return array{out:string,err:string,code:int}
 */
function runChild(string ...$args): array
{
    $p = proc_open(
        array_merge([PHP_BINARY, __FILE__], $args),
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['out' => $out, 'err' => $err, 'code' => proc_close($p)];
}

/** 统计一段输出里 Swoole 噪音行数。 */
function noiseCount(array $child): int
{
    return substr_count($child['out'], 'WARNING') + substr_count($child['err'], 'WARNING');
}

$withHook = in_array('--hook', $argv ?? [], true);
$flagIdx = array_search('--flags', $argv ?? [], true);
$flagVal = $flagIdx !== false ? (int) ($argv[$flagIdx + 1] ?? 0) : null;

if (!$withHook && $flagVal === null) {
    echo "=== A组：无 Swoole HOOK（headless 环境）===\n";
    runAllChecks();

    if (extension_loaded('swoole')) {
        // B 组是对照组：证明「为什么必须关 HOOK_FILE」，噪音只作信息记录，不作断言
        echo "=== B组（对照）：HOOK_ALL & ~STDIO，M1.5 时期的 flags ===\n";
        $b = runChild('--hook');
        echo $b['out'];
        check($b['code'] === 0, 'B组用例全通过（但见下方噪音信息）');
        echo '  [INFO] B组 Swoole 噪音行数=' . noiseCount($b)
            . "（HOOK_FILE 接管管道 fd 所致；TUI 里会打进 alternate screen 花屏，故 M2 关掉它）\n";

        echo "=== C组：M2 选定 flags（& ~FILE & ~PROC）===\n";
        $c = runChild('--flags', (string) M2_HOOK_FLAGS);
        echo $c['out'];
        check($c['code'] === 0, 'C组用例全通过');
        check(noiseCount($c) === 0, 'C组无任何 Swoole 噪音（实测=' . noiseCount($c) . '）');
        check(
            str_contains($c['out'], '`exit 42`)=42') && str_contains($c['out'], '原始返回=42'),
            'C组 proc_close 退出码语义正确（不受 HOOK 改写；B组该项为 0）'
        );
    }

    echo $failed ? "RESULT: FAIL\n" : "RESULT: PASS\n";
    exit($failed ? 1 : 0);
}

// 子进程分支：bin/tui.php 的主循环本就跑在 Coroutine\run 里，故照包一层
$flags = $flagVal ?? (SWOOLE_HOOK_ALL & ~SWOOLE_HOOK_STDIO);
Swoole\Runtime::enableCoroutine($flags);
Swoole\Coroutine\run(static function (): void {
    runAllChecks();
});
echo $failed ? "RESULT: FAIL\n" : "RESULT: PASS\n";
exit($failed ? 1 : 0);

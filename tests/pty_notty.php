<?php
declare(strict_types=1);

/**
 * D8 真实进程：**非 tty 的 stdin**（重定向 / 管道 / `</dev/null`）必须快速、干净、人话地失败，
 * 而不是「PHP Fatal error + 堆栈 + exit 255」。
 *
 * 为什么单开一个文件：这条路径**只能在非 tty 下复现**，而项目里所有跑 bin/vicecode.php 的
 * 测试都用 `['pty']` 描述符（stdin 是 tty）—— 于是它从来没被覆盖过。
 *
 * 背景（实测）：php-tui 的 raw mode 靠 `stty -g` 探测，非 tty 时失败 →
 * vendor `SttyRawMode::enable()` 抛 `RuntimeException('Could not get stty settings')`；
 * 而 `Swoole\Coroutine\run()` 内部的异常**不会传播给调用者**，所以当初挂在 run() 外面的
 * try/catch 接不住 → PHP Fatal、exit 255，连终端还原都无从谈起。
 *
 * 断言：
 *   1. 退出码**恰为 1**（PHP Fatal 会是 255，挂住会是 -1）；
 *   2. 给出「ViceCode 异常退出」+「需要交互式终端」的人话
 *      —— 这是**正向锚点**：证明守卫真的跑到了，否则下面的阴性断言会在「进程压根没起来」时假通过；
 *   3. 不喷 `Fatal error` / `Uncaught` / `Stack trace`；
 *   4. **没有进入 alternate screen**（`ESC[?1049h`）—— 守卫必须在任何终端操作之前，
 *      否则等于留下半个初始化过的终端。⚠️ 这条看的是**原始字节流**：转义序列不进字符网格，
 *      不能用重建帧（feedback §1.2）。
 *
 * 两个底座分支都测（Swoole 协程 / 纯 php-tui 回退），两者的入口路径不同。
 *
 * 两条修复各自被谁锁住（**注入实测**，别误以为摘掉守卫会全红）：
 *   - 「非 tty 守卫」只锁第 2 条（说清是交互式终端）：摘掉它后其余断言仍全绿 ——
 *     因为「协程内 catch」会把 SttyRawMode 的异常同样变为人话（只是提示不针对非 tty 场景）；
 *   - 「协程内 catch」锁第 1/3 条（恰为 1、不喷 Fatal）：把它换回挂在 run() 外面的 catch，
 *     Swoole 分支立刻退回 PHP Fatal + exit 255（实测）。
 *
 * 运行：php tests/pty_notty.php
 */

chdir(__DIR__ . '/..');
require __DIR__ . '/lib/isolation.php';
$cfg = vc_isolate_config('vc_notty');

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
 * 用**非 tty 的 stdin** 跑一次 bin/vicecode.php。
 *
 * @return array{0:int,1:string} [退出码, stdout+stderr 的并集]
 */
function runNoTty(string $envName, string $envValue): array
{
    // 0 => ['file', '/dev/null', 'r']：不是 tty —— 这正是本用例要覆盖的输入形态。
    $descs = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $env = array_merge(getenv(), [
        'COLUMNS' => '120',
        'LINES' => '40',
        'VICECODE_CONFIG' => $GLOBALS['cfg'],
        $envName => $envValue,
    ]);
    $p = proc_open([PHP_BINARY, 'bin/vicecode.php'], $descs, $pipes, getcwd(), $env);
    if ($p === false) {
        return [-1, ''];
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $out = '';
    $code = -1;
    $deadline = microtime(true) + 10;
    while (true) {
        foreach ([1, 2] as $fd) {
            $c = @fread($pipes[$fd], 8192);
            if (is_string($c) && $c !== '') {
                $out .= $c;
            }
        }
        $st = proc_get_status($p);
        if (!$st['running']) {
            $code = (int) $st['exitcode'];
            break;
        }
        if (microtime(true) > $deadline) {
            proc_terminate($p, SIGKILL);
            break;
        }
        usleep(30000);
    }
    foreach ([1, 2] as $fd) {
        $c = @fread($pipes[$fd], 8192);
        if (is_string($c) && $c !== '') {
            $out .= $c;
        }
    }
    proc_close($p);
    return [$code, $out];
}

$branches = [
    ['TUI_USE_SWOOLE', '1', 'Swoole 协程底座'],
    ['TUI_USE_SWOOLE', '0', '纯 php-tui 回退'],
];
foreach ($branches as [$envName, $envValue, $label]) {
    echo "== 非 tty stdin（{$label}）==\n";
    [$code, $out] = runNoTty($envName, $envValue);
    check($code === 1, "{$label}：退出码恰为 1（Fatal 会是 255 / 挂住会是 -1）—— 实际 $code");
    check(str_contains($out, 'ViceCode 异常退出'), "{$label}：给出「ViceCode 异常退出」（正向锚点）");
    check(str_contains($out, '交互式终端') || str_contains($out, 'TTY'),
        "{$label}：说清是「需要交互式终端 / stdin 不是 TTY」");
    check(!str_contains($out, 'Fatal error') && !str_contains($out, 'Uncaught'),
        "{$label}：不喷 PHP Fatal / Uncaught");
    check(!str_contains($out, 'Stack trace'), "{$label}：不喷 PHP 堆栈");
    check(!str_contains($out, "\x1b[?1049h"), "{$label}：没有进入 alternate screen（守卫在终端操作之前）");
}

echo $failed ? "\nD8 非 tty 启动 FAIL\n" : "\nD8 非 tty 启动 PASS\n";
exit($failed ? 1 : 0);

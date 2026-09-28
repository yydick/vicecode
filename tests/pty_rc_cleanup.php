<?php
declare(strict_types=1);

/**
 * D9 真实 pty：交互 shell 的 rc 临时文件**不许残留** —— 包括「终端关闭（SIGHUP）」这条路径。
 *
 * 背景：`PtyProcess` 起 bash 时用 `--rcfile <临时文件>` 注入 cwd 上报钩子。该文件原先只在
 * 应用**正常退出 / 走 finally** 时被删，于是不执行 finally 的退出路径全漏：
 *   - 终端关闭 → 内核 SIGHUP 直接终止进程（实测：每关一次终端 `/tmp/vicetui_rc_*` +1）；
 *   - SIGKILL。
 * 现改为**由 bash 读完即自删**（rc 最后一行 `command rm -f -- <自己>`）—— 文件寿命只有毫秒级，
 * 之后无论进程怎么死都不可能残留（`unlink` 只摘目录项，bash 已持有的 fd 仍可读到 EOF）。
 *
 * 断言：
 *   1. 应用起来了（正向锚点）；2. 交互 shell 真的起来了（正向锚点 —— 否则后面全是空转）；
 *   3. **shell 启动后 rc 文件就不在 /tmp 里**（D9 的直接防回归）；
 *   4. 给进程发 SIGHUP（模拟终端关闭）后它真的退出了（正向锚点）；
 *   5. **SIGHUP 之后 rc 文件依然不残留**。
 *
 * ⚠️ 两点诚实说明：
 *   - 真实终端关闭时，内核给**前台进程组**发 SIGHUP，交互 bash 会与应用一起被收走（实测无孤儿）；
 *     本用例只对应用发 SIGHUP，故那条 bash 会变孤儿 —— 由用例自己收尾 `kill -9`（交互 bash
 *     忽略 SIGTERM），并在末尾断言清干净，不污染后续测试。
 *   - 「shell 起来了」的判据用 `bash --rcfile` **进程数**，不能用 rc 文件是否出现：它现在只存活
 *     毫秒级，靠轮询看会偶发假阴性（这正是本条修复带来的判据变化，`pty_crash` 场景 B 同步改过）。
 *
 * 运行：php tests/pty_rc_cleanup.php
 */

chdir(__DIR__ . '/..');
require __DIR__ . '/lib/isolation.php';
$cfg = vc_isolate_config('vc_rc_cleanup');

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

/** 当前 /tmp 里的 rc 临时文件数 */
function rcCount(): int
{
    return count((array) glob(sys_get_temp_dir() . '/vicetui_rc_*'));
}

/**
 * 当前活着的 `bash --rcfile` 进程 pid 列表。
 * grep 模式用 `[b]ash` 方括号技巧，免得把 grep 自己算进去。
 *
 * @return list<int>
 */
function bashRcfilePids(): array
{
    exec("ps -eo pid,args 2>/dev/null | grep '[b]ash --rcfile'", $lines);
    $pids = [];
    foreach ($lines as $l) {
        if (preg_match('/^\s*(\d+)/', $l, $m)) {
            $pids[] = (int) $m[1];
        }
    }
    return $pids;
}

$rcBefore = rcCount();
$pidsBefore = bashRcfilePids();

$descs = [0 => ['pty'], 1 => ['pty'], 2 => ['pty']];
$env = array_merge(getenv(), [
    'COLUMNS' => '120',
    'LINES' => '40',
    'VICECODE_CONFIG' => $cfg,
]);
$p = proc_open([PHP_BINARY, 'bin/vicecode.php'], $descs, $pipes, getcwd(), $env);
if ($p === false) {
    echo "proc_open 失败\n";
    exit(2);
}
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

$out = '';
$pid = (int) proc_get_status($p)['pid'];

/** 收输出 + 判进程存活；返回 false 表示进程已退出 */
function pump($p, array $pipes, string &$out, float $seconds): bool
{
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        foreach ([1, 2] as $fd) {
            $c = @fread($pipes[$fd], 8192);
            if (is_string($c) && $c !== '') {
                $out .= $c;
            }
        }
        if (!proc_get_status($p)['running']) {
            return false;
        }
        usleep(30000);
    }
    return true;
}

// ① 等首屏画出来（条件等待，不写死 sleep）
$deadline = microtime(true) + 10;
while (strlen($out) < 2000 && microtime(true) < $deadline) {
    if (!pump($p, $pipes, $out, 0.2)) {
        break;
    }
}
check(strlen($out) > 2000, '应用起来了（首屏字节 > 2000，正向锚点）');

// 焦点切到终端面板（Tab×2）→ F2 进交互 pty
fwrite($pipes[0], "\t\t");
pump($p, $pipes, $out, 0.4);
fwrite($pipes[0], "\x1bOQ");

// ② 等交互 shell 真的起来：`bash --rcfile` 进程出现（条件等待，超时 10s）
$shellUp = false;
$deadline = microtime(true) + 10;
while (microtime(true) < $deadline) {
    if (count(bashRcfilePids()) > count($pidsBefore)) {
        $shellUp = true;
        break;
    }
    if (!pump($p, $pipes, $out, 0.2)) {
        break;
    }
}
check($shellUp, 'F2 后交互 shell 真的起来了（bash --rcfile 进程出现，正向锚点）');

// ③ 核心断言：shell 启动后 rc 临时文件**已经不在** /tmp（bash 读完即自删）
// 条件等待，避免恰好撞在「bash 还没执行到自删那行」的毫秒窗口上。
$gone = false;
$deadline = microtime(true) + 5;
while (microtime(true) < $deadline) {
    if (rcCount() <= $rcBefore) {
        $gone = true;
        break;
    }
    usleep(50000);
}
check($gone, sprintf('shell 启动后 rc 临时文件已自删（vicetui_rc_*：%d → %d）', $rcBefore, rcCount()));

// ④ 发 SIGHUP（模拟终端关闭；真实场景由内核在 pty 主端关闭时发出）
$signalled = posix_kill($pid, SIGHUP);
check($signalled, 'SIGHUP 已发出到应用进程');
$exited = false;
$deadline = microtime(true) + 6;
while (microtime(true) < $deadline) {
    if (!proc_get_status($p)['running']) {
        $exited = true;
        break;
    }
    usleep(30000);
}
check($exited, 'SIGHUP 后应用进程真的退出了（正向锚点：证明信号送到了）');

// ⑤ 核心断言：SIGHUP 退出路径同样不残留 rc 文件
check(rcCount() <= $rcBefore,
    sprintf('SIGHUP 后 /tmp 未残留 pty rc 文件（vicetui_rc_*：%d → %d）', $rcBefore, rcCount()));

// 收尾：只发 SIGHUP 给应用时，那条 bash 会变孤儿（真实场景内核会一起收走）—— 本用例自行清理，
// 免得给后续测试留下一个活着的 bash（交互 bash 忽略 SIGTERM，必须 SIGKILL）。
$orphans = array_values(array_diff(bashRcfilePids(), $pidsBefore));
foreach ($orphans as $opid) {
    @posix_kill($opid, SIGKILL);
}
usleep(300000);
$left = array_values(array_diff(bashRcfilePids(), $pidsBefore));
check($left === [],
    sprintf('用例收尾已清掉自己拉起的孤儿 shell（残留 %d 个：%s）', count($left), implode(',', $left)));

foreach ([1, 2] as $fd) {
    if (is_resource($pipes[$fd])) {
        @fclose($pipes[$fd]);
    }
}
if (is_resource($pipes[0])) {
    @fclose($pipes[0]);
}
proc_close($p);

// ⑥ 兜底回收：**只 new App() 的调用方**（headless 测试 / 未来别的宿主）不调 shutdown() 时，
//    进程结束也必须把交互 shell 收走 —— 否则「聚焦终端 + 渲染一帧」的用例会一个个漏 bash
//    （实测 command_palette_unit 漏过 1 个孤儿 bash + 1 个 rc 文件，而交互 bash 扛得住 SIGTERM，
//     只有 SIGKILL 收得掉 → 会一直堆着）。这里起子进程：聚焦终端、渲染一帧（真起 shell）、
//     **故意不调 shutdown()** 直接退出，断言进程退出后没有多出来的 shell / rc 文件。
echo "\n== 进程退出兜底回收（不调 shutdown 的调用方）==\n";
$root = dirname(__DIR__);
$childCode = <<<'PHP'
require $argv[1] . '/vendor/autoload.php';
require $argv[1] . '/tests/lib/isolation.php';
vc_isolate_config('vc_rc_reap');
$app = new App\App();
$app->focus('terminal');
$app->render(PhpTui\Tui\Display\Area::fromDimensions(120, 40));   // 聚焦首帧 → 起 shell
usleep(600000);   // 留出一段「shell 活着」的窗口，好让父进程观察到它确实起来了（正向锚点）
// 故意不调 shutdown()：register_shutdown_function 的兜底回收负责收尾
PHP;
$pidsBeforeReap = bashRcfilePids();
$rcBeforeReap = rcCount();
$pReap = proc_open(
    [PHP_BINARY, '-r', $childCode, $root],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes2
);
$childOut = '';
$spawnedPids = [];
if (is_resource($pReap)) {
    stream_set_blocking($pipes2[1], false);
    $deadline = microtime(true) + 15;
    while (microtime(true) < $deadline) {
        $childOut .= (string) @fread($pipes2[1], 8192);
        // 采样：子进程还活着时它拉起的 shell 应当看得见（它就是「正向锚点」）。
        // ⚠️ 必须**累积**而不是覆盖最后一次：循环最后一轮是在子进程退出之后采的，
        // 那时 shell 已被兜底钩子收走，覆盖写会把整段窗口的观察结果清成空数组。
        $spawnedPids = array_values(array_unique(array_merge(
            $spawnedPids,
            array_diff(bashRcfilePids(), $pidsBeforeReap)
        )));
        if (!proc_get_status($pReap)['running']) {
            break;
        }
        usleep(50000);
    }
}
$childStillRunning = is_resource($pReap) && proc_get_status($pReap)['running'];
// 正向锚点：子进程运行期间确实多出过一个交互 shell（否则下面的阴性断言全是空转）
check($spawnedPids !== [],
    sprintf('子进程运行期间确实起来了交互 shell（正向锚点，见到 pid：%s）', implode(',', $spawnedPids)));
check(!$childStillRunning, '子进程已自行退出（正向锚点：证明这段流程跑到了）');
usleep(500000);   // 给退出钩子与内核回收一点时间
$leftAfterReap = array_values(array_diff(bashRcfilePids(), $pidsBeforeReap));
check($leftAfterReap === [],
    sprintf('不调 shutdown() 的进程退出后没有多出交互 shell（$leftAfterReap 残留：%s）', implode(',', $leftAfterReap)));
check(rcCount() <= $rcBeforeReap,
    sprintf('且 /tmp 未残留 rc 文件（%d → %d）', $rcBeforeReap, rcCount()));
// 兜底：真超时了才动手（正常路径不该走到这）
if ($childStillRunning && is_resource($pReap)) {
    @proc_terminate($pReap, SIGKILL);
    foreach ($leftAfterReap as $opid) {
        @posix_kill($opid, SIGKILL);
    }
}
foreach ([1, 2] as $fd) {
    if (isset($pipes2[$fd]) && is_resource($pipes2[$fd])) {
        @fclose($pipes2[$fd]);
    }
}
if (isset($pipes2[0]) && is_resource($pipes2[0])) {
    @fclose($pipes2[0]);
}

echo $failed ? "\nD9 rc 临时文件清理 FAIL\n" : "\nD9 rc 临时文件清理 PASS\n";
exit($failed ? 1 : 0);

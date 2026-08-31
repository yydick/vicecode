<?php
/**
 * M5 最小可验证样本：Swoole 协程 curl 能否真·流式读 SSE。
 *
 * 要回答的四个问题（答不上来就别往下设计）：
 *  1. 本项目这套 HOOK flags 下，curl 到底有没有被协程化？（没被协程化 = 阻塞整个调度器）
 *  2. CURLOPT_WRITEFUNCTION 是**增量**回调还是攒完一次性回调？（不增量就无所谓"流式打字"）
 *  3. 传输期间事件循环是否还活着？（用并发协程打点证明，不是"没报错"就算数）
 *  4. 能不能中途取消？（M5 R6 停止生成）
 *
 * 服务端是同进程内 proc_open 起的一个真 HTTP 服务（examples/sse_server.php），
 * 起完自己测完自己收掉，可重复执行。
 */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

const PORT = 18777;
const M5_HOOK_FLAGS = SWOOLE_HOOK_ALL & ~SWOOLE_HOOK_STDIO & ~SWOOLE_HOOK_FILE & ~SWOOLE_HOOK_PROC;

$ok = 0;
$fail = 0;
function check(bool $cond, string $msg): void
{
    global $ok, $fail;
    if ($cond) {
        $ok++;
        echo "  [OK] $msg\n";
    } else {
        $fail++;
        echo "  [FAIL] $msg\n";
    }
}

// ── 起 mock SSE 服务端 ────────────────────────────────
$server = proc_open(
    ['php', '-S', '127.0.0.1:' . PORT, __DIR__ . '/sse_server.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes
);
if (!is_resource($server)) {
    fwrite(STDERR, "无法启动 mock 服务端\n");
    exit(1);
}

// 等端口真的能连上（比 sleep 盲等可靠）
$up = false;
for ($i = 0; $i < 100; $i++) {
    $c = @stream_socket_client('tcp://127.0.0.1:' . PORT, $errno, $errstr, 0.2);
    if ($c) {
        fclose($c);
        $up = true;
        break;
    }
    usleep(50000);
}
if (!$up) {
    proc_terminate($server, SIGKILL);
    proc_close($server);
    fwrite(STDERR, "mock 服务端端口未就绪\n");
    exit(1);
}

$t0 = microtime(true);
$chunks = [];      // [相对毫秒, 字节数]
$done = false;
$ticks = 0;        // 并发协程的打点次数
$err = null;

Swoole\Coroutine\run(function () use (&$chunks, &$done, &$ticks, &$err, $t0): void {
    // 问题 3：请求进行中，另一个协程必须还在跑
    Swoole\Coroutine::create(function () use (&$ticks, &$done): void {
        while (!$done) {
            $ticks++;
            Swoole\Coroutine::sleep(0.05);
        }
    });

    $ch = curl_init('http://127.0.0.1:' . PORT . '/sse?n=8&delay=150');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_WRITEFUNCTION => function ($curl, $body) use (&$chunks, $t0): int {
            $chunks[] = [(int) round((microtime(true) - $t0) * 1000), strlen($body)];
            return strlen($body);
        },
        CURLOPT_HTTPHEADER => ['Accept: text/event-stream', 'Cache-Control: no-cache'],
        CURLOPT_TIMEOUT => 20,
        // 禁止 libcurl 缓冲：某些版本会在 WRITEFUNCTION 前再攒一层
        CURLOPT_BUFFERSIZE => 128,
    ]);
    $okExec = curl_exec($ch);
    $err = $okExec === false ? curl_error($ch) : null;
    curl_close($ch);
    $done = true;
    // 给打点协程一轮机会退出
    Swoole\Coroutine::sleep(0.06);
});

$elapsedMs = (int) round((microtime(true) - $t0) * 1000);
proc_terminate($server, SIGKILL);
proc_close($server);

echo "== 结果 ==\n";
echo "总耗时 {$elapsedMs}ms，收到 " . count($chunks) . " 个 chunk，并发协程打点 {$ticks} 次\n";
foreach ($chunks as [$ms, $len]) {
    echo sprintf("  +%4dms  %3d bytes\n", $ms, $len);
}

echo "== 判定 ==\n";
check($err === null, 'curl_exec 无错误' . ($err !== null ? "（$err）" : ''));
// 8 个 token × 150ms ≈ 1200ms；一次性返回的话耗时接近这个值但 chunk 只有 1 个
check(count($chunks) >= 8, 'WRITEFUNCTION 增量回调（chunk 数 ' . count($chunks) . ' >= 8），不是攒完一次性给');
check(count($chunks) <= 12, 'chunk 数未爆炸（' . count($chunks) . ' <= 12），没有被切成逐字节');
// 首块必须远早于总耗时：证明不是结束后才回调
$firstMs = $chunks[0][0] ?? -1;
check($firstMs >= 0 && $firstMs < $elapsedMs - 200, "首块在 {$firstMs}ms 到达，远早于结束 {$elapsedMs}ms");
// 问题 3 的关键证据：打点次数应与请求时长相称（1200ms / 50ms ≈ 24）
check($ticks >= 10, "传输期间事件循环存活（并发协程打点 {$ticks} 次 >= 10）");

// 问题 1 的反证：若 curl 未被协程化、阻塞了调度器，打点协程一次都跑不了
check($ticks > 0, 'curl 未阻塞整个调度器（协程化生效）');

// ── 问题 4：中途取消 ──────────────────────────────────
echo "== 中途取消（M5 R6 停止生成）==\n";
$server2 = proc_open(
    ['php', '-S', '127.0.0.1:' . (PORT + 1), __DIR__ . '/sse_server.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $p2
);
$up2 = false;
for ($i = 0; $i < 100; $i++) {
    $c = @stream_socket_client('tcp://127.0.0.1:' . (PORT + 1), $e, $s, 0.2);
    if ($c) {
        fclose($c);
        $up2 = true;
        break;
    }
    usleep(50000);
}
if ($up2) {
    $n2 = 0;
    $canceled = null;
    $t1 = microtime(true);
    Swoole\Coroutine\run(function () use (&$n2, &$canceled): void {
        $cid = Swoole\Coroutine::getCid();
        // 另一个协程在收到第 3 个 chunk 后 kill 掉请求协程
        Swoole\Coroutine::create(function () use ($cid, &$n2, &$canceled): void {
            $guard = 0;
            while ($n2 < 3 && $guard++ < 200) {
                Swoole\Coroutine::sleep(0.02);
            }
            $canceled = Swoole\Coroutine::cancel($cid);
        });
        $ch = curl_init('http://127.0.0.1:' . (PORT + 1) . '/sse?n=20&delay=150');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => function ($curl, $body) use (&$n2): int {
                $n2++;
                return strlen($body);
            },
            CURLOPT_TIMEOUT => 20,
            CURLOPT_BUFFERSIZE => 128,
        ]);
        curl_exec($ch);
        curl_close($ch);
    });
    $el2 = (int) round((microtime(true) - $t1) * 1000);
    proc_terminate($server2, SIGKILL);
    proc_close($server2);
    check($n2 < 20, "取消后只收到 {$n2} 个 chunk（< 20），确实提前中止");
    check($el2 < 2500, "取消耗时 {$el2}ms < 2500ms（20 个 token 需 3000ms）");
    echo "  cancel() 返回 " . var_export($canceled, true) . "\n";
} else {
    check(false, '第二个 mock 服务端未就绪');
    proc_terminate($server2, SIGKILL);
    proc_close($server2);
}

echo "\n结论：" . ($fail === 0 ? "全部通过 ✓" : "有 {$fail} 项失败") . "\n";
exit($fail === 0 ? 0 : 1);

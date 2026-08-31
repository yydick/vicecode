<?php
/**
 * M5 传输层探针：找出「在协程里流式读 SSE」到底哪条路走得通。
 *
 * 背景：examples/07-sse-stream.php 用当前 HOOK flags + libcurl 的 WRITEFUNCTION，
 * 结果是请求耗时正常（说明数据在传）但回调一次都没被调用。这里做对照实验，
 * 把「HOOK 组合 × 读法」逐格试出来，别靠猜。
 *
 * 统一用一个 mock SSE 服务端（真 HTTP、分块 flush），所有方案请求同一个 URL，
 * 只比三件事：拿到几个 chunk、首块延迟、期间并发协程能否打点。
 */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

const PORT = 18801;
const URL = 'http://127.0.0.1:' . PORT . '/sse?n=6&delay=120';

function startServer(): array
{
    $p = proc_open(
        ['php', '-S', '127.0.0.1:' . PORT, __DIR__ . '/../examples/sse_server.php'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes
    );
    for ($i = 0; $i < 100; $i++) {
        $c = @stream_socket_client('tcp://127.0.0.1:' . PORT, $e, $s, 0.2);
        if ($c) {
            fclose($c);
            return [$p, true];
        }
        usleep(50000);
    }
    return [$p, false];
}

[$srv, $up] = startServer();
if (!$up) {
    fwrite(STDERR, "mock 服务端未就绪\n");
    exit(1);
}

/**
 * 跑一个方案。
 * @param int|null $flags null = 完全不开 HOOK
 * @param callable $do  (callable $onChunk) => void，跑在协程里
 * @return array{n:int, firstMs:int, ms:int, ticks:int, err:?string}
 */
function runCase(?int $flags, callable $do): array
{
    $n = 0;
    $firstMs = -1;
    $ticks = 0;
    $err = null;
    $done = false;
    $t0 = microtime(true);

    $wrap = function () use (&$n, &$firstMs, &$ticks, &$err, &$done, $do, $t0): void {
        Swoole\Coroutine::create(function () use (&$ticks, &$done): void {
            while (!$done) {
                $ticks++;
                Swoole\Coroutine::sleep(0.05);
            }
        });
        $onChunk = function (string $b) use (&$n, &$firstMs, $t0): void {
            if ($n === 0) {
                $firstMs = (int) round((microtime(true) - $t0) * 1000);
            }
            $n++;
        };
        try {
            $do($onChunk);
        } catch (Throwable $e) {
            $err = get_class($e) . ': ' . $e->getMessage();
        }
        $done = true;
        Swoole\Coroutine::sleep(0.06);
    };

    if ($flags === null) {
        // 完全不开 HOOK：在 Coroutine\run 里跑原生阻塞 curl（对照组，预期卡死调度器）
        Swoole\Coroutine\run($wrap);
    } else {
        Swoole\Coroutine::set(['hook_flags' => $flags]);
        Swoole\Coroutine\run($wrap);
    }

    return [
        'n' => $n,
        'firstMs' => $firstMs,
        'ms' => (int) round((microtime(true) - $t0) * 1000),
        'ticks' => $ticks,
        'err' => $err,
    ];
}

$cur = SWOOLE_HOOK_ALL & ~SWOOLE_HOOK_STDIO & ~SWOOLE_HOOK_FILE & ~SWOOLE_HOOK_PROC;
$noCurl = $cur & ~SWOOLE_HOOK_NATIVE_CURL & ~SWOOLE_HOOK_CURL;

// ── 方案 A：libcurl + WRITEFUNCTION（当前 flags）──────
$curlWriteFn = static function (callable $onChunk): void {
    $ch = curl_init(URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_WRITEFUNCTION => static function ($c, $body) use ($onChunk): int {
            $onChunk($body);
            return strlen($body);
        },
        CURLOPT_TIMEOUT => 20,
        CURLOPT_BUFFERSIZE => 128,
    ]);
    curl_exec($ch);
    curl_close($ch);
};

// ── 方案 B：Swoole\Coroutine\Http\Client + 手写 recv 循环 ──
$httpClient = static function (callable $onChunk): void {
    $cli = new Swoole\Coroutine\Http\Client('127.0.0.1', PORT);
    $cli->set([
        'timeout' => 20,
        // 关键：不让 Swoole 自己攒完整个响应体
        'write_func' => null,
    ]);
    $cli->setMethod('GET');
    $cli->setHeaders(['Accept' => 'text/event-stream']);
    $okc = $cli->execute('/sse?n=6&delay=120');
    if (!$okc) {
        throw new RuntimeException('execute failed: ' . $cli->errMsg);
    }
    // 边收边回调
    while (true) {
        $data = $cli->recv(20);
        if ($data === false || $data === '') {
            break;
        }
        $onChunk((string) $data);
    }
    $cli->close();
};

// ── 方案 C：原生阻塞 curl（无 HOOK 对照）──────────────
$plainCurl = static function (callable $onChunk): void {
    $ch = curl_init(URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_WRITEFUNCTION => static function ($c, $body) use ($onChunk): int {
            $onChunk($body);
            return strlen($body);
        },
        CURLOPT_TIMEOUT => 20,
        CURLOPT_BUFFERSIZE => 128,
    ]);
    curl_exec($ch);
    curl_close($ch);
};

$cases = [
    'A1 libcurl+WRITEFUNCTION / 当前flags(含NATIVE_CURL)' => [$cur, $curlWriteFn],
    'A2 libcurl+WRITEFUNCTION / 关掉CURL系HOOK'          => [$noCurl, $curlWriteFn],
    'A3 libcurl+WRITEFUNCTION / 完全不开HOOK(对照)'        => [null, $plainCurl],
    'B1 Coroutine\Http\Client + recv 循环'                => [$cur, $httpClient],
];

echo "== M5 传输层对照实验（6 个 token × 120ms ≈ 720ms）==\n\n";
$rows = [];
foreach ($cases as $name => [$flags, $fn]) {
    $r = runCase($flags, $fn);
    $rows[] = [$name, $r];
    printf(
        "%s\n  chunk=%-3d 首块=%-5dms 总耗时=%-5dms 并发打点=%-3d %s\n\n",
        $name,
        $r['n'],
        $r['firstMs'],
        $r['ms'],
        $r['ticks'],
        $r['err'] !== null ? 'err=' . $r['err'] : ''
    );
}

echo "== 判据 ==\n";
echo "  · 流式成立：chunk >= 6 且 首块 明显早于 总耗时\n";
echo "  · 不阻塞  ：并发打点 >= 8（720ms / 50ms ≈ 14）\n";

proc_terminate($srv, SIGKILL);
proc_close($srv);

<?php
/**
 * 第三轮：找「既不阻塞调度器、又能增量流式」的传输方案。
 *
 * 前两轮的结论（别再重走）：
 *  - libcurl + WRITEFUNCTION：NATIVE_CURL hook 下回调被静默吞掉（0 chunk）；
 *    关掉 hook 后能流式但阻塞整个调度器（打点=1）→ UI 会僵死。两条都不行。
 *  - Coroutine\Http\Client：execute() 是「等完整响应」，recv() 是 WebSocket 方法，
 *    HTTP 上恒返回 false → 没有 HTTP 增量读的口子。
 *
 * 所以只剩三条路，这里逐个验证：
 *  C1 curl_multi + 手动轮询（有/无 NATIVE_CURL hook 各测一次）
 *  C2 原始协程 socket + 手写 HTTP（无依赖，但要自己处理 chunked/HTTPS）
 *  C3 curl 子进程 + 非阻塞管道（M2/M4 的 CommandRunner 范式）
 */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

const P3 = 18803;
const TOKENS = 12;
const DELAY = 120;

$p = proc_open(
    ['php', '-S', '127.0.0.1:' . P3, __DIR__ . '/../examples/sse_server.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes
);
$up = false;
for ($i = 0; $i < 100; $i++) {
    $c = @stream_socket_client('tcp://127.0.0.1:' . P3, $e, $s, 0.2);
    if ($c) {
        fclose($c);
        $up = true;
        break;
    }
    usleep(50000);
}
if (!$up) {
    fwrite(STDERR, "mock 服务端未就绪\n");
    exit(1);
}

$path = '/sse?n=' . TOKENS . '&delay=' . DELAY;
$url = 'http://127.0.0.1:' . P3 . $path;
$expectMs = TOKENS * DELAY;

function measure(string $name, int $flags, callable $do): array
{
    $n = 0;
    $first = -1;
    $ticks = 0;
    $done = false;
    $err = null;
    $bytes = 0;
    $t0 = microtime(true);

    Swoole\Coroutine::set(['hook_flags' => $flags]);
    Swoole\Coroutine\run(function () use (&$n, &$first, &$ticks, &$done, &$err, &$bytes, $do, $t0): void {
        Swoole\Coroutine::create(function () use (&$ticks, &$done): void {
            while (!$done) {
                $ticks++;
                Swoole\Coroutine::sleep(0.05);
            }
        });
        try {
            $do(function (string $b) use (&$n, &$first, &$bytes, $t0): void {
                if ($n === 0) {
                    $first = (int) round((microtime(true) - $t0) * 1000);
                }
                $n++;
                $bytes += strlen($b);
            });
        } catch (Throwable $e) {
            $err = get_class($e) . ': ' . $e->getMessage();
        }
        $done = true;
        Swoole\Coroutine::sleep(0.06);
    });

    $r = [
        'n' => $n,
        'first' => $first,
        'ms' => (int) round((microtime(true) - $t0) * 1000),
        'ticks' => $ticks,
        'bytes' => $bytes,
        'err' => $err,
    ];
    printf(
        "%-44s chunk=%-3d 首块=%-5d 耗时=%-5d 打点=%-3d bytes=%-5d %s\n",
        $name, $r['n'], $r['first'], $r['ms'], $r['ticks'], $r['bytes'], $err !== null ? "err=$err" : ''
    );
    return $r;
}

$cur = SWOOLE_HOOK_ALL & ~SWOOLE_HOOK_STDIO & ~SWOOLE_HOOK_FILE & ~SWOOLE_HOOK_PROC;
$noCurl = $cur & ~SWOOLE_HOOK_NATIVE_CURL & ~SWOOLE_HOOK_CURL;

// ── C1: curl_multi 手动轮询 ───────────────────────────
$multi = static function (callable $on) use ($url): void {
    $mh = curl_multi_init();
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_WRITEFUNCTION => static function ($c, $body) use ($on): int {
            $on($body);
            return strlen($body);
        },
        CURLOPT_TIMEOUT => 20,
        CURLOPT_BUFFERSIZE => 128,
        CURLOPT_HTTPHEADER => ['Accept: text/event-stream'],
    ]);
    curl_multi_add_handle($mh, $ch);
    $running = 1;
    $guard = 0;
    do {
        curl_multi_exec($mh, $running);
        if (!$running || $guard++ > 2000) {
            break;
        }
        // 让出调度器：不用 curl_multi_select（PHP 函数，不会被 Swoole 协程化，会阻塞整个进程），
        // 改用极短的协程 sleep 主动 yield。
        Swoole\Coroutine::sleep(0.005);
    } while (true);
    curl_multi_remove_handle($mh, $ch);
    curl_multi_close($mh);
};

// ── C2: 原始协程 socket + 手写 HTTP ───────────────────
$rawSock = static function (callable $on) use ($path): void {
    $sock = new Swoole\Coroutine\Socket(AF_INET, SOCK_STREAM, 0);
    if (!$sock->connect('127.0.0.1', P3, 5)) {
        throw new RuntimeException('connect failed: ' . $sock->errMsg);
    }
    $req = "GET {$path} HTTP/1.1\r\n"
        . "Host: 127.0.0.1:" . P3 . "\r\n"
        . "Accept: text/event-stream\r\n"
        . "Connection: close\r\n\r\n";
    if ($sock->send($req) === false) {
        throw new RuntimeException('send failed: ' . $sock->errMsg);
    }
    while (true) {
        $buf = $sock->recv(4096, 5);
        if ($buf === false || $buf === '') {
            break;
        }
        $on($buf);
    }
    $sock->close();
};

// ── C3: curl 子进程 + 非阻塞管道（M2/M4 范式）──────────
$sub = static function (callable $on) use ($url): void {
    $proc = proc_open(
        ['curl', '-N', '-sS', '--max-time', '20', '-H', 'Accept: text/event-stream', $url],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($proc)) {
        throw new RuntimeException('proc_open failed');
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $guard = 0;
    while (true) {
        $st = proc_get_status($proc);
        $data = fread($pipes[1], 8192);
        if ($data !== false && $data !== '') {
            $on($data);
        }
        fread($pipes[2], 8192);
        if (!$st['running']) {
            // 再排一次，把退出前的残余读干净
            $data = fread($pipes[1], 8192);
            if ($data !== false && $data !== '') {
                $on($data);
            }
            break;
        }
        if ($guard++ > 5000) {
            break;
        }
        Swoole\Coroutine::sleep(0.005);
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
};
/**
 * ⚠️ 每个方案跑在**独立进程**里：C1a（curl_multi + NATIVE_CURL hook）实测会段错误，
 * 同进程内跑会把后面所有用例一起带走。用 `php sse_probe3.php <case>` 单跑一个。
 * 不带参数时跑三个安全用例。
 */
$cases = [
    'C1b' => ['C1b curl_multi / 关掉CURL系hook', $noCurl, $multi],
    'C2'  => ['C2 Coroutine\Socket 手写 HTTP', $cur, $rawSock],
    'C3'  => ['C3 curl 子进程 + 非阻塞管道', $cur, $sub],
    // 段错误用例，只在显式指定时才跑
    'C1a' => ['C1a curl_multi / 当前flags(含NATIVE_CURL)', $cur, $multi],
];

$want = $argv[1] ?? null;
$res = [];
foreach ($cases as $key => [$label, $flags, $fn]) {
    if ($want === null && $key === 'C1a') {
        continue; // 默认跳过已知段错误的用例
    }
    if ($want !== null && $want !== $key) {
        continue;
    }
    $res[$label] = measure($label, $flags, $fn);
}

if ($want === null) {
    echo "\n（C1a 已知段错误，默认跳过；要跑：php sse_probe3.php C1a）\n";
    proc_terminate($p, SIGKILL);
    proc_close($p);
    exit(0);
}

echo "\n== 判据（期望耗时≈{$expectMs}ms，打点≈" . (int) ($expectMs / 50) . "）==\n";
echo "  · 流式  ：chunk 明显 > 1 且 首块 远早于 耗时\n";
echo "  · 不阻塞：打点 >= " . (int) ($expectMs / 50 / 2) . "（至少达到理论值一半）\n\n";

$pass = 0;
foreach ($res as $name => $r) {
    $streaming = $r['n'] > 1 && $r['first'] >= 0 && $r['first'] < $r['ms'] - 200;
    $nonblock = $r['ticks'] >= (int) ($expectMs / 50 / 2);
    $good = $streaming && $nonblock && $r['err'] === null && $r['bytes'] > 0;
    if ($good) {
        $pass++;
    }
    printf(
        "  %s %-40s 流式=%s 不阻塞=%s\n",
        $good ? '✅' : '❌',
        $name,
        $streaming ? 'Y' : 'N',
        $nonblock ? 'Y' : 'N'
    );
}
echo "\n满足「流式 + 不阻塞」的方案数：{$pass}\n";

proc_terminate($p, SIGKILL);
proc_close($p);

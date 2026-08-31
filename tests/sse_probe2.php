<?php
/**
 * 第二轮：只盯 Swoole\Coroutine\Http\Client，把「真协程 + 真流式」的组合找出来。
 *
 * 第一轮结论：
 *  - libcurl + WRITEFUNCTION 在 NATIVE_CURL hook 下被静默吞掉（0 chunk、立刻返回）；
 *  - 关掉 CURL hook 后 libcurl 能流式，但是阻塞调用，会把整个调度器卡住（打点=1）。
 *    对 TUI 来说这是致命的：请求期间界面会完全僵死。
 * 所以只剩 Coroutine\Http\Client 这条路值得救。第一轮它 0 chunk/62ms 就返回了，
 * 这里把可疑变量逐个拆开：write_func、recv、set 参数、execute 返回时机。
 */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

const PORT2 = 18802;
const PATH2 = '/sse?n=6&delay=120';

$p = proc_open(
    ['php', '-S', '127.0.0.1:' . PORT2, __DIR__ . '/../examples/sse_server.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes
);
$up = false;
for ($i = 0; $i < 100; $i++) {
    $c = @stream_socket_client('tcp://127.0.0.1:' . PORT2, $e, $s, 0.2);
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

$flags = SWOOLE_HOOK_ALL & ~SWOOLE_HOOK_STDIO & ~SWOOLE_HOOK_FILE & ~SWOOLE_HOOK_PROC;

function measure(string $name, callable $do): void
{
    global $flags;
    $n = 0;
    $first = -1;
    $ticks = 0;
    $done = false;
    $err = null;
    $t0 = microtime(true);

    Swoole\Coroutine::set(['hook_flags' => $flags]);
    Swoole\Coroutine\run(function () use (&$n, &$first, &$ticks, &$done, &$err, $do, $t0): void {
        Swoole\Coroutine::create(function () use (&$ticks, &$done): void {
            while (!$done) {
                $ticks++;
                Swoole\Coroutine::sleep(0.05);
            }
        });
        try {
            $do(function (string $b) use (&$n, &$first, $t0): void {
                if ($n === 0) {
                    $first = (int) round((microtime(true) - $t0) * 1000);
                }
                $n++;
            });
        } catch (Throwable $e) {
            $err = get_class($e) . ': ' . $e->getMessage();
        }
        $done = true;
        Swoole\Coroutine::sleep(0.06);
    });

    printf(
        "%-46s chunk=%-3d 首块=%-5d 耗时=%-5d 打点=%-3d %s\n",
        $name,
        $n,
        $first,
        (int) round((microtime(true) - $t0) * 1000),
        $ticks,
        $err !== null ? "err=$err" : ''
    );
}

// B1: 默认 set + execute 后 recv 循环
measure('B1 execute→recv，默认 set', static function (callable $on): void {
    $cli = new Swoole\Coroutine\Http\Client('127.0.0.1', PORT2);
    $cli->setMethod('GET');
    $cli->setHeaders(['Accept' => 'text/event-stream']);
    if (!$cli->execute(PATH2)) {
        throw new RuntimeException("execute failed: {$cli->errMsg}");
    }
    while (($d = $cli->recv(20)) !== false && $d !== '') {
        $on((string) $d);
    }
    $cli->close();
});

// B2: 显式 timeout，不传 write_func
measure('B2 execute→recv，显式 timeout=20', static function (callable $on): void {
    $cli = new Swoole\Coroutine\Http\Client('127.0.0.1', PORT2);
    $cli->set(['timeout' => 20]);
    $cli->setMethod('GET');
    $cli->setHeaders(['Accept' => 'text/event-stream']);
    if (!$cli->execute(PATH2)) {
        throw new RuntimeException("execute failed: {$cli->errMsg}");
    }
    while (($d = $cli->recv(20)) !== false && $d !== '') {
        $on((string) $d);
    }
    $cli->close();
});

// B3: 用 Swoole 的 write_func 回调（不等 recv）
measure('B3 set(write_func) 回调式', static function (callable $on): void {
    $cli = new Swoole\Coroutine\Http\Client('127.0.0.1', PORT2);
    $cli->set([
        'timeout' => 20,
        'write_func' => static function (Swoole\Coroutine\Http\Client $c, string $data) use ($on): void {
            $on($data);
        },
    ]);
    $cli->setMethod('GET');
    $cli->setHeaders(['Accept' => 'text/event-stream']);
    if (!$cli->execute(PATH2)) {
        throw new RuntimeException("execute failed: {$cli->errMsg}");
    }
    $cli->recv(20);
    $cli->close();
});

// B4: onReceive 事件回调
measure('B4 on("receive") 事件回调', static function (callable $on): void {
    $cli = new Swoole\Coroutine\Http\Client('127.0.0.1', PORT2);
    $cli->set(['timeout' => 20]);
    $cli->on('receive', static function (Swoole\Coroutine\Http\Client $c, string $data) use ($on): void {
        $on($data);
    });
    $cli->setMethod('GET');
    $cli->setHeaders(['Accept' => 'text/event-stream']);
    if (!$cli->execute(PATH2)) {
        throw new RuntimeException("execute failed: {$cli->errMsg}");
    }
    $cli->recv(20);
    $cli->close();
});

// B5: 诊断用——把 execute 后的状态全打出来，看是不是压根没连上
measure('B5 诊断：打印 statusCode/body 长度', static function (callable $on): void {
    $cli = new Swoole\Coroutine\Http\Client('127.0.0.1', PORT2);
    $cli->set(['timeout' => 20]);
    $cli->setMethod('GET');
    if (!$cli->execute(PATH2)) {
        echo "\n      [诊断] execute=false statusCode={$cli->statusCode} errCode={$cli->errCode} errMsg={$cli->errMsg}\n";
        return;
    }
    echo "\n      [诊断] execute=true statusCode={$cli->statusCode} headers=" . json_encode($cli->headers ?? []) . "\n";
    $i = 0;
    while ($i++ < 30) {
        $d = $cli->recv(20);
        echo '      [诊断] recv#' . $i . ' = ' . var_export(is_string($d) ? substr($d, 0, 60) : $d, true) . "\n";
        if ($d === false || $d === '') {
            break;
        }
        $on($d);
    }
    $cli->close();
});

proc_terminate($p, SIGKILL);
proc_close($p);

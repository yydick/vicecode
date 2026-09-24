<?php
declare(strict_types=1);

/**
 * probe：扫描不同终端宽度下「小视口 + 纯终端」是否卡死/崩溃。
 * 假设：LayoutFactory mainConstraints = sidebar(30)+center(min10)+ai(45) 共需 85 列，
 *       任何 < 85 列视口会把 center 挤成 0/负宽 → 布局冲突/卡死。
 * 本探针验证阈值（特别是常见 80 列终端）。
 * 不参与跑批。
 */

require __DIR__ . '/lib/isolation.php';

$log = static function (string $s): void {
    fwrite(STDERR, $s . "\n");
    fflush(STDERR);
};

$cfg = vc_isolate_config('vc_probe_w');
file_put_contents($cfg, (string) json_encode(['persistSession' => false]));
$home = vc_tmp_dir('vc_probe_w');
file_put_contents($home . '/.bashrc', "PS1='ready$ '\n");
$bin = __DIR__ . '/../bin/vicecode.php';

function normalize(string $raw): string
{
    $s = (string) preg_replace('/\x1B\][^\x07\x1B]*(?:\x07|\x1B\\\\)/', '', $raw);
    $s = (string) preg_replace('/\x1B\[[0-9;?]*[ -\/]*[@-~]/', '', $s);
    $s = (string) preg_replace('/\x1B[@-Z\\\\-_]/', '', $s);
    return strtolower((string) preg_replace('/[^a-zA-Z0-9\x{4e00}-\x{9fff}]/u', '', $s));
}

function runApp(string $bin, array $args, array $env, array $descs, array $seq): array
{
    $proc = proc_open(array_merge([PHP_BINARY, $bin], $args), $descs, $pipes, null, $env);
    if ($proc === false) {
        return ['out' => '', 'code' => -1];
    }
    stream_set_blocking($pipes[0], false);
    stream_set_blocking($pipes[1], false);
    $readPty = static function ($stream, int $len) {
        set_error_handler(static function (int $no, string $str): bool {
            return str_contains($str, 'errno=5') || str_contains($str, 'Input/output error');
        });
        try {
            return fread($stream, $len);
        } finally {
            restore_error_handler();
        }
    };
    $out = '';
    $feed = static function (string $bytes) use ($pipes): void {
        if ($bytes !== '') {
            fwrite($pipes[0], $bytes);
        }
    };
    $drain = static function () use ($pipes, $readPty, &$out): void {
        while (true) {
            $r = [$pipes[1]];
            $w = $e = [];
            if (stream_select($r, $w, $e, 0, 30000) <= 0) {
                return;
            }
            $chunk = $readPty($pipes[1], 8192);
            if ($chunk === '' || $chunk === false) {
                return;
            }
            $out .= $chunk;
        }
    };
    foreach ($seq as $step) {
        [$bytes, $us] = $step;
        $anchor = $step[2] ?? null;
        if (!proc_get_status($proc)['running']) {
            break;
        }
        $feed($bytes);
        $deadline = microtime(true) + $us / 1000000;
        do {
            usleep(20000);
            $drain();
            if ($anchor === null || str_contains(normalize($out), normalize($anchor))) {
                break;
            }
        } while (microtime(true) < $deadline);
    }
    // 退出 guard：Esc 退捕获 + Ctrl+Q 退出
    $guard = 0;
    $end = time() + 5;
    while (proc_get_status($proc)['running'] && time() < $end) {
        $feed($guard % 2 === 0 ? "\x1b" : "\x11");
        usleep(200000);
        $drain();
        $guard++;
    }
    // 不阻塞 proc_close：直接 SIGKILL + 返回。-9 标记卡死被强杀。
    $st = proc_get_status($proc);
    if ($st['running']) {
        @proc_terminate($proc, 9);
        $code = -9;
    } else {
        $code = $st['exitcode'];
    }
    return ['out' => $out, 'code' => $code];
}

$descs = [0 => ['pty'], 1 => ['pty'], 2 => ['pty']];

$env = array_merge(getenv(), [
    'APP_LOCALE' => 'zh_CN',
    'VICECODE_CONFIG' => $cfg,
    'HOME' => $home,
    'SHELL' => '/bin/bash',
]);

function termSeq(string $cmd, string $ok): array
{
    return [
        ['', 4000000, 'ctrlq'],
        ["\t", 50000],
        ["\t", 50000],
        [$cmd, 50000],
        ["\r", 6000000, $ok],
        ["\x1b", 300000],
        ["\x11", 800000],
    ];
}

// 扫描宽度：重点 85 边界及其上下，含常见 80 列
$widths = [120, 100, 90, 86, 85, 84, 80, 60, 40];
$anyBad = false;
foreach ($widths as $w) {
    $log("WIDTH $w start");
    $e = array_merge($env, ['COLUMNS' => (string) $w, 'LINES' => '24']);
    $r = runApp($bin, [], $e, $descs, termSeq('echo $((6*7))', '42'));
    $n = normalize($r['out']);
    $fatal = str_contains($r['out'], 'Fatal error') || str_contains($r['out'], 'Uncaught')
        || str_contains($r['out'], 'OutOfBounds') || str_contains($r['out'], 'Position');
    $okExit = $r['code'] === 0;
    $okOut = str_contains($n, '42');
    $status = ($okExit && $okOut && !$fatal) ? 'OK' : 'BAD';
    if ($status === 'BAD') {
        $anyBad = true;
    }
    printf("W=%3d  exit=%-3s  output42=%-5s  fatal=%-5s  => %s\n",
        $w, var_export($r['code'], true), var_export($okOut, true), var_export($fatal, true), $status);
    fflush(STDOUT);
    // 清理可能残留的 pty 孤儿
    @exec('pkill -f "[b]in/vicecode.php" 2>/dev/null');
    $log("WIDTH $w done");
}

echo $anyBad ? "RESULT: 发现窄视口问题\n" : "RESULT: 所有宽度均正常\n";
fflush(STDOUT);
exit($anyBad ? 1 : 0);

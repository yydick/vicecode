<?php
declare(strict_types=1);

/**
 * probe：验证「小视口 + 打开超长/大文件」是否渲染卡死（第一版 dump 显示停在编辑器帧无限重绘）。
 * 环境先清残留，runApp 非阻塞退出兜底。不参与跑批。
 */

require __DIR__ . '/lib/isolation.php';

$log = static function (string $s): void {
    fwrite(STDERR, $s . "\n");
    fflush(STDERR);
};

// 先清残留 pty 进程，避免上次被强杀的孤儿干扰（memory §7）
@exec('pkill -f "[b]in/vicecode.php" 2>/dev/null');
usleep(500000);

$cfg = vc_isolate_config('vc_probe_file');
file_put_contents($cfg, (string) json_encode(['persistSession' => false]));
$home = vc_tmp_dir('vc_probe_file');
file_put_contents($home . '/.bashrc', "PS1='ready$ '\n");
$bin = __DIR__ . '/../bin/vicecode.php';

// fixture：超长单行 + 数百行 + CJK
$fix = tempnam(sys_get_temp_dir(), 'vcfix') . '.txt';
$lines = [str_repeat('x', 500)];
for ($i = 0; $i < 300; $i++) {
    $lines[] = "行{$i} 中文内容 " . str_repeat('y', 30);
}
file_put_contents($fix, implode("\n", $lines));

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
    $guard = 0;
    $end = time() + 5;
    while (proc_get_status($proc)['running'] && time() < $end) {
        $feed("\x11"); // 直接 Ctrl+Q 退出
        usleep(200000);
        $drain();
        $guard++;
    }
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

// 场景：40×10 打开大文件，滚动几行，直接 Ctrl+Q 退出（验证渲染不卡死）
$log('FILE+SMALL start');
$e = array_merge($env, ['COLUMNS' => '40', 'LINES' => '10']);
$seq = [
    ['', 4000000, 'ctrlq'],
    ["\e[B", 40000],   // 下滚
    ["\e[B", 40000],
    ["\e[B", 40000],
    ["\e[B", 40000],
    ["\x11", 1000000], // Ctrl+Q 退出
];
$r = runApp($bin, [$fix], $e, $descs, $seq);
$n = normalize($r['out']);
$fatal = str_contains($r['out'], 'Fatal error') || str_contains($r['out'], 'Uncaught')
    || str_contains($r['out'], 'OutOfBounds') || str_contains($r['out'], 'Position');
$ok = $r['code'] === 0 && !$fatal;
printf("打开大文件+40x10：exit=%s fatal=%s => %s\n", var_export($r['code'], true), var_export($fatal, true), $ok ? 'OK（未卡死）' : 'BAD（卡死/崩溃）');
fflush(STDOUT);
file_put_contents(__DIR__ . '/probe_file_dump.log', $r['out']);
@exec('pkill -f "[b]in/vicecode.php" 2>/dev/null');
echo $ok ? "RESULT: PASS（证伪：大文件小视口不卡死）\n" : "RESULT: FAIL（复现卡死/崩溃）\n";
fflush(STDOUT);
exit($ok ? 0 : 1);

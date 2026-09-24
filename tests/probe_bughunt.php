<?php
declare(strict_types=1);

/**
 * probe：区分「小视口纯终端」「小视口+大文件」「极小视口」「隐藏AI」四类边界。
 * 不参与跑批。每个 pty 实例退出带硬超时 + proc_terminate 兜底。
 */

require __DIR__ . '/lib/isolation.php';

$log = static function (string $s): void {
    fwrite(STDERR, $s . "\n");
    fflush(STDERR);
};

$cfg = vc_isolate_config('vc_probe_bg');
file_put_contents($cfg, (string) json_encode(['persistSession' => false]));
$home = vc_tmp_dir('vc_probe_home');
file_put_contents($home . '/.bashrc', "PS1='ready$ '\n");

// ── fixture：超长单行 + 数百行 + CJK ──
$fix = tempnam(sys_get_temp_dir(), 'vcfix') . '.txt';
$lines = [str_repeat('x', 500)];
for ($i = 0; $i < 300; $i++) {
    $lines[] = "行{$i} 中文内容 " . str_repeat('y', 30);
}
file_put_contents($fix, implode("\n", $lines));

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
    $guard = 0;
    $end = time() + 6;
    while (proc_get_status($proc)['running'] && time() < $end) {
        $feed($guard % 2 === 0 ? "\x1b" : "\x11");
        usleep(200000);
        $drain();
        $guard++;
    }
    if (proc_get_status($proc)['running']) {
        @proc_terminate($proc, 9);
    }
    $code = proc_close($proc);
    return ['out' => $out, 'code' => $code];
}

$descs = [0 => ['pty'], 1 => ['pty'], 2 => ['pty']];

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    fflush(STDOUT);
    if (!$cond) {
        $failed = true;
    }
}

$baseEnv = array_merge(getenv(), [
    'APP_LOCALE' => 'zh_CN',
    'VICECODE_CONFIG' => $cfg,
    'HOME' => $home,
    'SHELL' => '/bin/bash',
]);

function termSeq(string $cmd, string $ok): array
{
    return [
        ['', 10000000, 'ctrlq'],
        ["\t", 60000],
        ["\t", 60000],
        [$cmd, 60000],
        ["\r", 8000000, $ok],
        ["\x1b", 400000],
        ["\x11", 1000000],
    ];
}

// ── A：40×10 纯终端（对照：小视口终端是否本身可用）──
$log('SCENARIO A start');
$env = array_merge($baseEnv, ['COLUMNS' => '40', 'LINES' => '10']);
$rA = runApp($bin, [], $env, $descs, termSeq('echo $((6*7))', '42'));
$nA = normalize($rA['out']);
$fatalA = str_contains($rA['out'], 'Fatal error') || str_contains($rA['out'], 'Uncaught') || str_contains($rA['out'], 'OutOfBounds');
echo "== A：40x10 纯终端（对照）==\n";
check($rA['code'] === 0, "干净退出 exit={$rA['code']}");
check(!$fatalA, '无 Fatal/Uncaught/OutOfBounds');
check(str_contains($nA, '42'), '终端命令执行并渲染（输出 42）');
$log('SCENARIO A done code=' . $rA['code']);

// ── B：40×10 + 打开大文件（复现 exit=9 卡死）──
$log('SCENARIO B start');
$rB = runApp($bin, [$fix], $env, $descs, termSeq('echo $((6*7))', '42'));
$nB = normalize($rB['out']);
$fatalB = str_contains($rB['out'], 'Fatal error') || str_contains($rB['out'], 'Uncaught') || str_contains($rB['out'], 'OutOfBounds');
echo "\n== B：40x10 + 打开大文件（复现卡死）==\n";
check($rB['code'] === 0, "干净退出 exit={$rB['code']}（非0=卡死被强杀）");
check(!$fatalB, '无 Fatal/Uncaught/OutOfBounds');
check(str_contains($nB, '42'), '终端命令执行并渲染（输出 42）—— 若无则焦点被打开文件偏移/卡死');
file_put_contents(__DIR__ . '/probe_bughunt_b_dump.log', $rB['out']);
$log('SCENARIO B done code=' . $rB['code']);

// ── C：24×6 极小视口纯终端 ──
$log('SCENARIO C start');
$envC = array_merge($baseEnv, ['COLUMNS' => '24', 'LINES' => '6']);
$rC = runApp($bin, [], $envC, $descs, termSeq('echo hi', 'hi'));
$nC = normalize($rC['out']);
$fatalC = str_contains($rC['out'], 'Fatal error') || str_contains($rC['out'], 'Uncaught') || str_contains($rC['out'], 'OutOfBounds');
echo "\n== C：24x6 极小视口纯终端 ==\n";
check($rC['code'] === 0, "干净退出 exit={$rC['code']}");
check(!$fatalC, '无 Fatal/Uncaught/OutOfBounds');
check(str_contains($nC, 'hi'), '终端命令渲染（输出 hi）');
$log('SCENARIO C done code=' . $rC['code']);

// ── D：隐藏 AI 面板起手（B16 边界）──
$log('SCENARIO D start');
$cfg2 = vc_isolate_config('vc_probe_noai');
file_put_contents($cfg2, (string) json_encode(['persistSession' => false, 'layout' => ['aiVisible' => false]]));
$envD = array_merge($baseEnv, ['COLUMNS' => '80', 'LINES' => '24', 'VICECODE_CONFIG' => $cfg2]);
$rD = runApp($bin, [], $envD, $descs, termSeq('echo ok', 'ok'));
$fatalD = str_contains($rD['out'], 'Fatal error') || str_contains($rD['out'], 'Uncaught') || str_contains($rD['out'], 'OutOfBounds');
echo "\n== D：隐藏 AI 面板起手（B16）==\n";
check($rD['code'] === 0, "干净退出 exit={$rD['code']}");
check(!$fatalD, '无 Fatal/Uncaught/OutOfBounds');
$log('SCENARIO D done code=' . $rD['code']);

echo $failed ? "RESULT: FAIL\n" : "RESULT: PASS\n";
fflush(STDOUT);
exit($failed ? 1 : 0);

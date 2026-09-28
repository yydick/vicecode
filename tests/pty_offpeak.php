<?php
declare(strict_types=1);

/**
 * 折扣时段自动降档 —— 真实 pty 端到端验收。
 *
 * 为什么必须走 pty + mock：headless（`tests/route_offpeak_unit.php`）已覆盖"选哪一档"的逻辑，
 * 但本功能的**核心承诺**是"折扣时段里请求真的打到便宜模型"。状态栏显示对了不算数——
 * 必须看**发出去的请求体**。所以用 `MOCK_ECHO_MODEL=1`（服务端回显请求体里的 model），
 * 并用 `MOCK_LOG_FILE` 断言**调用序列**（旁路记录比在差分画面上找字符串硬得多）。
 *
 * ⚠️ 折扣窗口**动态生成**：用当前时刻算出"正在打折"和"还早"的两段，避免写死钟点后
 * 这条测试只在某个时间段能跑过（而且窗口不写 tz 时按本机时区判定——测试进程与应用同机，
 * 这里显式写成系统当前偏移，彻底不受 TZ 影响）。
 *
 * 断言都建在**重建后的最终帧**上（`vc_rebuild_screen`；差分渲染只重发变化格）。
 *
 * 运行：timeout 150 php tests/pty_offpeak.php
 */

chdir(__DIR__ . '/..');
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/isolation.php';
require __DIR__ . '/lib/pty_screen.php';

use App\Core\ConfigStore;

const W = 200;          // 状态栏段多（策略段 + AI 段要同时在屏上），给足宽度
const H = 50;
const PORT = 18996;     // 与 pty_strategy 的 18995 错开，避免同机并行跑批时抢端口

$cfgFile = vc_isolate_config('vc_offpeak_pty');
$provPath = ConfigStore::providersPath();

// ── 动态窗口 ──────────────────────────────────────────────────────────
// 基准：本机当前时刻。tz 用系统当前偏移（'P' = ±HH:MM），于是"窗口钟点"与"判定钟点"同源，
// 换机器/换时区都不影响结论。days 省略 = 任意星期（本用例刻意不测星期，那是 offpeak_unit 的活）。
$tz = date('P');
$fmt = static fn(int $offsetHours): string => date('H:i', time() + $offsetHours * 3600);
$activeWindow   = ['from' => $fmt(-1), 'to' => $fmt(+1)];    // 覆盖"现在"
$inactiveWindow = ['from' => $fmt(+2), 'to' => $fmt(+3)];    // 离现在还有 2 小时

// 用户级配置：两个 provider 指向**同一个 mock 服务端**的不同模型；
// 只有便宜的 `zzcheap` 配了正在生效的折扣窗口，贵的 `zzrich` 的窗口在 2 小时后。
$tpl = <<<'PHP'
<?php
return [
    '@strategies' => [
        // 声明顺序决定 Ctrl+R 的站点顺序：自动 → smart → grind
        'smart' => ['label' => 'ZZSMART', 'provider' => 'zzrich',  'model' => 'zz-model-rich'],
        'grind' => ['label' => 'ZZGRIND', 'provider' => 'zzcheap', 'model' => 'zz-model-cheap'],
    ],
    'zzrich' => [
        'label'        => 'ZZRICH',
        'key_env'      => 'ZZ_KEY',
        'base_url'     => 'http://127.0.0.1:__PORT__/v1',
        'models'       => ['zz-model-rich' => ['tools']],
        'model'        => 'zz-model-rich',
        'cost'         => 9,
        'off_peak'     => [__INACTIVE__],
    ],
    'zzcheap' => [
        'label'        => 'ZZCHEAP',
        'key_env'      => 'ZZ_KEY',
        'base_url'     => 'http://127.0.0.1:__PORT__/v1',
        'models'       => ['zz-model-cheap' => ['tools']],
        'model'        => 'zz-model-cheap',
        'cost'         => 1,
        'off_peak'     => [__ACTIVE__],
    ],
];
PHP;
$inline = static fn(array $w): string => var_export($w, true);
file_put_contents(
    $provPath,
    str_replace(
        ['__PORT__', '__ACTIVE__', '__INACTIVE__'],
        [(string) PORT, $inline($activeWindow), $inline($inactiveWindow)],
        $tpl
    )
);

$logFile = dirname($cfgFile) . '/model-calls.log';
@unlink($logFile);

$srv = proc_open(
    ['env', 'MOCK_ECHO_MODEL=1', 'MOCK_LOG_FILE=' . $logFile, 'php', '-S', '127.0.0.1:' . PORT, __DIR__ . '/../examples/sse_server.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $srvPipes
);
$ready = false;
for ($i = 0; $i < 200; $i++) {
    $c = @stream_socket_client('tcp://127.0.0.1:' . PORT, $e, $s, 0.2);
    if ($c) {
        fclose($c);
        $ready = true;
        break;
    }
    usleep(50000);
}
if (!$ready) {
    echo "[FAIL] mock 服务端未起来\n";
    exit(1);
}

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

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

// 探针：AI 输入框**中心**（+1 是 0-based → SGR 1-based；取中心避免点到工具栏图标）
$probe = new App\App();
$ai = $probe->areas(PhpTui\Tui\Display\Area::fromDimensions(W, H))['ai_input'];
$clickX = $ai->position->x + intdiv($ai->width, 2) + 1;
$clickY = $ai->position->y + intdiv($ai->height, 2) + 1;

$descs = [0 => ['pty'], 1 => ['pty'], 2 => ['pty']];
$env = array_merge(getenv(), [
    'COLUMNS' => (string) W,
    'LINES' => (string) H,
    'APP_LOCALE' => 'zh_CN',
    'TERM' => 'xterm-256color',
    'VICECODE_CONFIG' => $cfgFile,
    'VICECODE_PROVIDERS_CONFIG' => $provPath,
    'ZZ_KEY' => 'test-key-not-used',
]);
$proc = proc_open([PHP_BINARY, 'bin/vicecode.php'], $descs, $pipes, getcwd(), $env);
if ($proc === false) {
    echo "[FAIL] 无法启动 bin/vicecode.php\n";
    exit(1);
}
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

$out = '';
$waitForFrame = static function (callable $pred, float $seconds) use ($pipes, $readPty, &$out): bool {
    $end = microtime(true) + $seconds;
    while (microtime(true) < $end) {
        $quiet = 0;
        while ($quiet < 2 && microtime(true) < $end) {
            $chunk = $readPty($pipes[1], 65536);
            if ($chunk === '') {
                $quiet++;
                usleep(60000);
            } else {
                $out .= $chunk;
                $quiet = 0;
            }
        }
        if ($pred(vc_rebuild_screen($out, W, H))) {
            return true;
        }
    }
    return false;
};
// ⚠️ 必须 `use (&$out)`：箭头函数按值捕获，会让重建永远基于最初那个空串
$frame = static function () use (&$out): string {
    return vc_rebuild_screen($out, W, H);
};
$send = static function (string $bytes) use ($pipes): void {
    fwrite($pipes[0], $bytes);
};
$calls = static function () use ($logFile): array {
    return array_values(array_filter(explode("\n", trim((string) @file_get_contents($logFile)))));
};

// ── 1) 首帧 + 聚焦 ──
echo "== 真实 pty：折扣时段自动降档 ==\n";
$firstFrame = $waitForFrame(static fn(string $f): bool => str_contains($f, 'vicecode'), 10.0);
check($firstFrame, '应用首帧已渲染（锚点：app 标题）');

$send("\x1b[<0;{$clickX};{$clickY}M");
$send("\x1b[<0;{$clickX};{$clickY}m");
$focused = $waitForFrame(static fn(string $f): bool => str_contains($f, 'aiinput'), 8.0);
check($focused, '点击 AI 输入框落焦（焦点段 = AI_INPUT）');

check(str_contains($frame(), '策略自动'), '未切换前状态栏显示「策略=自动」（配了策略即开启自动选档）');
check(!str_contains($frame(), '折扣'),
    '无策略生效时状态栏没有折扣标记（折扣标记只跟着当前档位）');

// ── 2) 无 kind 的普通消息 → 时段兜底，请求打到便宜模型 ──
$send('随便聊聊');
usleep(200000);
$send("\r");
$ok1 = $waitForFrame(static fn(string $f): bool => str_contains($f, 'modelzzmodelcheap'), 20.0);
check($ok1, '普通消息 → 请求打到**便宜模型** zz-model-cheap（zzcheap 正在折扣时段，cost 1 < zzrich 的 9）');
check(str_contains($frame(), 'zzgrind'), '状态栏出现该档位 ZZGRIND');
// 锚点用**归一化后相邻的形式** `zzgrind折扣`，而不是单独的「折扣」二字：`ai.strategy_offpeak_applied`
// 的提示文案里也有"折扣"（但写成"折扣时段：…"），用裸词会让这条断言在"状态栏根本没打标"时也通过。
check(str_contains($frame(), 'zzgrind自动折扣'),
    '状态栏给当前档打上「·折扣」标记；且因为是自动路由所以「·自动」也在（zzcheap 此刻在折扣中）');
check(str_contains($frame(), 'zzgrind自动'),
    '时段路由**不钉住** → 状态栏带「·自动」后缀（说明仍归自动规则管，不是人工钉住）');

// ── 3) 人工钉住：Ctrl+R 一次从「自动」→ smart（贵档），时段规则不再抢 ──
$send("\x12");
$gotSmart = $waitForFrame(static fn(string $f): bool => str_contains($f, 'zzsmart'), 8.0);
check($gotSmart, 'Ctrl+R ×1 → 状态栏出现 ZZSMART（自动 → 声明顺序第一条策略）');
// ⚠️ 必须拿"档位名 + 折扣"组合锚点：`ai.strategy_offpeak_applied` 的**提示**里也有"折扣时段"，
// 所以上一条消息残留时单查「折扣」会假通过（这条断言原本就是这么写的，注入验证时抓到）。
check(!str_contains($frame(), 'zzsmart折扣'),
    '钉住的贵档不在折扣时段 → 状态栏**没有**折扣标记（标记跟的是当前档位，不是"今天有没有折扣"）');

$send('钉住时发一条');
usleep(200000);
$send("\r");
$ok2 = $waitForFrame(static fn(string $f): bool => str_contains($f, 'modelzzmodelrich'), 20.0);
check($ok2, '钉住后即使便宜档在打折也不抢方向盘 → 请求仍打到 zz-model-rich');

// ── 4) Ctrl+R 再切一档 → grind（便宜档、正在折扣）→ 状态栏重新带标记 ──
$send("\x12");
$gotGrind = $waitForFrame(static fn(string $f): bool => str_contains($f, 'zzgrind'), 8.0);
check($gotGrind, 'Ctrl+R ×1 → ZZGRIND（人工选中打折的档位）');
check(!str_contains($frame(), 'zzgrind自动'),
    '人工选档 = 钉住，没有「·自动」后缀（说明现在听用户的）');

$send('再发一条');
usleep(200000);
$send("\r");
$ok3 = $waitForFrame(static fn(string $f): bool => str_contains($f, 'modelzzmodelcheap'), 20.0);
check($ok3, '人工钉住便宜档后 → 请求打到 zz-model-cheap');

// ── 5) 服务端日志断言调用序列（比画面硬得多）──
$want = ['zz-model-cheap', 'zz-model-rich', 'zz-model-cheap'];
$got = $calls();
check($got === $want, '调用序列 = ' . implode(' → ', $want) . '（实际：' . implode(' → ', $got) . '）');

// ── 6) 干净退出 ──
$send("\x11");
$end = microtime(true) + 8;
$quiet = 0;
while ($quiet < 3 && microtime(true) < $end) {
    $chunk = $readPty($pipes[1], 65536);
    if ($chunk === '') {
        $quiet++;
        usleep(60000);
    } else {
        $out .= $chunk;
        $quiet = 0;
    }
}
$st = proc_get_status($proc);
$code = $st['running'] ? -1 : (int) $st['exitcode'];
if ($st['running']) {
    proc_terminate($proc, SIGKILL);
}
proc_close($proc);
proc_terminate($srv, SIGKILL);
proc_close($srv);

check($code === 0, "干净退出 exit=$code");
check(!str_contains($out, 'Fatal error') && !str_contains($out, 'Uncaught'), '无 Fatal / Uncaught');
check(str_contains($out, "\x1b[?1049l"), '退出仍还原终端');

@unlink($provPath);
@unlink($logFile);
echo $failed ? "\nRESULT: FAIL\n" : "\nRESULT: PASS\n";
exit($failed ? 1 : 0);

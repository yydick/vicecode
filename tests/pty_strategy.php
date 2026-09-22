<?php
declare(strict_types=1);

/**
 * 模型策略（`@strategies`）—— 真实 pty 端到端验收。
 *
 * 为什么必须走 pty + mock 服务端：headless 已覆盖解析/校验/持久化（`tests/strategy_unit.php`），
 * 但本功能的**核心承诺**是"切了档，请求真的打到那个模型"。状态栏显示对了不算数——必须看
 * **发出去的请求体**。所以这里用 `MOCK_ECHO_MODEL=1`（服务端把请求体里的 model 原样回显），
 * 在真实终端里按 Ctrl+R 切档、发消息，断言**回显的 model 确实换了**。
 *
 * 断言全部建在**重建后的最终帧**上（`vc_rebuild_screen`）：差分渲染只重发变化格、同一行还会被
 * 拆成多次「定位+写入」，累积流里找整词会踩坑（见 tests/lib/pty_screen.php）。
 *
 * 运行：timeout 150 php tests/pty_strategy.php
 */

chdir(__DIR__ . '/..');
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/isolation.php';
require __DIR__ . '/lib/pty_screen.php';

use App\Core\ConfigStore;

// 200×50：状态栏段多（app/file/mode/AI/策略/tab/quit…），120 列下按优先级会丢掉一些段——
// 本用例要同时看到「策略段」与「AI 段的模型」，所以用宽一点的视口（与 pty_ai_v2 同款）。
const W = 200;
const H = 50;
const PORT = 18995;

$cfgFile = vc_isolate_config('vc_strategy_pty');   // 隔离 .vicerc / 插件配置 / provider 用户配置
$provPath = ConfigStore::providersPath();

// 用户级配置：两条策略指向**同一个 mock provider 的两个模型**。
// label 用 ASCII（状态栏断言不依赖中文宽度）；模型名也是 ASCII 便于归一化匹配。
// ⚠️ 端口用占位符替换注入：nowdoc 不插值，直接把 PORT 写进去会变成用户配置里一个未定义常量。
$tpl = <<<'PHP'
<?php
return [
    '@strategies' => [
        // fast 声明了 kinds=plan：于是「自动选档」会把 /plan 路由到 fast，与下面钉住的 smart 形成区分
        'fast'  => ['label' => 'ZZFAST',  'provider' => 'mock', 'model' => 'zz-model-fast', 'kinds' => ['plan']],
        'smart' => ['label' => 'ZZSMART', 'provider' => 'mock', 'model' => 'zz-model-smart'],
    ],
    'mock' => [
        'label'    => 'Mock',
        'key_env'  => 'ZZ_KEY',
        'base_url' => 'http://127.0.0.1:__PORT__/v1',
        'models'   => ['zz-model-fast' => ['tools'], 'zz-model-smart' => ['tools']],
        'model'    => 'zz-model-fast',
    ],
];
PHP;
file_put_contents($provPath, str_replace('__PORT__', (string) PORT, $tpl));

// 请求日志：mock 每收到一次请求记一行 model。用于断言**调用序列**——比在 pty 画面上找回显硬得多
// （消息流会滚动、差分渲染只重发变化格；服务端日志是纯粹的调用记录）。
$logFile = dirname($cfgFile) . '/model-calls.log';
@unlink($logFile);

// mock 服务端：把请求体里的 model 回显成 `[model=xxx]`
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

// 探针：AI 输入框中心（坐标动态算，别写死）。+1 是 0-based → SGR 1-based。
// ⚠️ 取**中心**而不是左上角：输入框顶层边框右端是工具栏图标，点偏了会变成点工具栏。
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
/** 累积原始字节直到**重建后的最终帧**满足 $pred，或超时 */
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
// ⚠️ 必须用 `use (&$out)` 的普通闭包：箭头函数（fn()）捕获是**按值**的，`$frame()` 会永远
// 重建最初那个空 $out（本轮真踩了——所有对 $frame() 的断言都恒假，阴性断言还恒真）。
$frame = static function () use (&$out): string {
    return vc_rebuild_screen($out, W, H);
};
$send = static function (string $bytes) use ($pipes): void {
    fwrite($pipes[0], $bytes);
};

// ── 1) 首帧 → 聚焦 AI 输入框（点它的区域中心，跟真实用户一样）──
echo "== 真实 pty：模型策略端到端 ==\n";
// 正向锚点：先确认画面真的画出来了，否则下面"未切换前不显示策略"会在空帧上假通过
// 锚点用 app 标题（ASCII，且语言无关）；状态栏里的段名是本地化的（zh 下是「焦点=」而不是 focus），
// 拿英文段名当 needle 在中文界面里永远不中——那是我上一轮刚在 pty_search 修过的僵尸断言。
$firstFrame = $waitForFrame(static fn(string $f): bool => str_contains($f, 'vicecode'), 10.0);
check($firstFrame, '应用首帧已渲染（状态栏出现 app 标题）');

$send("\x1b[<0;{$clickX};{$clickY}M");
$send("\x1b[<0;{$clickX};{$clickY}m");
// `AI_INPUT` 是标识符（不随语言变），归一化后是 'aiinput'
$focused = $waitForFrame(static fn(string $f): bool => str_contains($f, 'aiinput'), 8.0);
check($focused, '点击 AI 输入框落焦（状态栏焦点段 = AI_INPUT）');

// 策略已定义、还没切过 → 状态栏显示「策略=自动」（自动选档已就绪），但不该显示某个档位
check(str_contains($frame(), '策略自动'), '未切换前状态栏显示「策略=自动」（配了策略即开启自动选档）');
check(!str_contains($frame(), '策略zz'), '未切换前不显示任何具体档位');

// ── 2) 钉住 smart 档（Ctrl+R ×2：自动 → fast → smart），发消息 ──
$send("\x12");
$send("\x12");
$gotSmart = $waitForFrame(static fn(string $f): bool => str_contains($f, 'zzsmart'), 8.0);
check($gotSmart, 'Ctrl+R ×2 → 状态栏出现 ZZSMART（手动选档）');
check(str_contains($frame(), 'zzmodelsmart'), '状态栏 AI 段显示该档指向的模型（zz-model-smart）');
check(!str_contains($frame(), 'zzsmart自动'), '手动钉住时状态栏**不带**「·自动」后缀（说明现在听用户的）');

$send('第一条');
usleep(200000);
$send("\r");
$ok1 = $waitForFrame(static fn(string $f): bool => str_contains($f, 'modelzzmodelsmart'), 20.0);
check($ok1, '发消息 → 请求打到 zz-model-smart（钉住的档真的作用到了请求体）');

// ── 3) 钉住状态下打 /plan 前缀：**不抢档**，而且必须明说 ──
$send('/plan 规划一下');
usleep(200000);
$send("\r");
$ok2 = $waitForFrame(static fn(string $f): bool => str_contains($f, '自动选档未生效'), 20.0);
check($ok2, '钉住时 /plan 被忽略 → 状态栏明说「自动选档未生效」（不许静默失效）');

// ── 4) Ctrl+R 一次切到「自动」→ /plan 前缀真的按类型路由 ──
$send("\x12");                                   // 从钉住的 smart → 「自动」
$gotAuto = $waitForFrame(static fn(string $f): bool => str_contains($f, '自动选档'), 8.0);
check($gotAuto, 'Ctrl+R → 切回「自动」档');
// ⚠️ 这里也必须**等**：策略段与 AI 段不是同一帧更新的（AI 段要等自动选档解析出模型），
// 原先用 `str_contains($frame(), …)` 立刻断言，负载高时会读到「策略段已切、AI 段还没跟上」
// 的中间帧 → 偶发假红（批跑里红过两次，单独跑却全绿）。
$gotAutoSeg = $waitForFrame(static fn(string $f): bool => str_contains($f, 'zzsmart自动'), 8.0);
check($gotAutoSeg, '状态栏标出「ZZSMART·自动」（说明现在听自动的）');

$send('/plan 规划二');
usleep(200000);
$send("\r");
$ok3 = $waitForFrame(static fn(string $f): bool => str_contains($f, 'modelzzmodelfast'), 20.0);
check($ok3, '自动档下 /plan → 请求打到 zz-model-fast（fast 声明了 kinds=plan：按类型自动选档生效）');

// 无 kind 的普通消息不该来回跳档（保持当前档）
$send('普通消息');
usleep(200000);
$send("\r");
usleep(1500000);
check((string) file_get_contents($logFile) !== '', 'mock 记录了请求（日志非空）');

// ── 5) 用**服务端日志**断言调用序列：钉住 smart 两条 → 自动路由 fast 两条 ──
$calls = array_values(array_filter(explode("\n", trim((string) @file_get_contents($logFile)))));
$want = ['zz-model-smart', 'zz-model-smart', 'zz-model-fast', 'zz-model-fast'];
check($calls === $want,
    '请求序列 = ' . implode(' → ', $want) . '（实际：' . implode(' → ', $calls) . '）');

// ── 4) 干净退出 ──
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
// ⚠️ 收尾再排空一次：应用退出前写的那批字节（含「还原终端」的 `?1049l` / `?25h`）可能还压在
// pty 缓冲里 —— 上面那轮排空是「连续 3 次空读」就收手的，正好会停在它写出之前，
// 于是 `str_contains($out, "\x1b[?1049l")` 偶发假红（实测批跑红过、单独跑全绿）。
usleep(100000);
$out .= (string) $readPty($pipes[1], 65536);
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

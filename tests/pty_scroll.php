<?php
declare(strict_types=1);

/**
 * 真实 pty 验收：回退滚动（PageUp/Down）在 runner 与 pty 两种模式下确实能翻出旧行。
 *
 * 关键设计点（来自 App::handle 路由）：
 *   - pty 捕获态：PageUp/Down 字节被转发给 shell（readline），不归我们的滚动处理；
 *     故「用 PageUp 翻 pty 回退」必须在「非捕获态」测（F2 进 pty → Esc 退捕获仍在跑 → PageUp）。
 *   - runner 模式：PageUp/Down 由 TerminalPanel::onKey → scrollBy 直接处理。
 *
 * 差分渲染陷阱：php-tui 只把相对上一帧「变了」的格重发到 pty，滚动走的旧行不会重发，
 *   所以裸 grep 累积字节找某行不可靠（见项目 facts）。本测试在翻到顶/翻回底后各触发一次
 *   **整屏重绘**（打开/关闭帮助层 ? / Esc，覆盖层改写所有格 → 底层终端面板整帧重画），
 *   此时 R001/P001 与 R060/P060 才会真正进入字节流，断言才可靠。
 *
 * 不变量：输出多于一屏时，翻到顶后最旧行（R001/P001）可见、翻回底后最新行（R060/P060）可见；
 *   滚动方向反了或没生效，对应行不会出现 → 断言失败。
 *
 * 运行：timeout 120 php tests/pty_scroll.php
 */

$cfgFile = tempnam(sys_get_temp_dir(), 'vc_scrcfg');
file_put_contents($cfgFile, (string) json_encode(['persistSession' => false]));
$sessionFile = dirname($cfgFile) . '/.vicecode_session';
@unlink($sessionFile); // 确保从干净状态开始

$env = array_merge(getenv(), [
    'COLUMNS' => '120',
    'LINES' => '40',
    'APP_LOCALE' => 'zh_CN',
    'VICECODE_CONFIG' => $cfgFile,
]);
$descs = [0 => ['pty'], 1 => ['pty'], 2 => ['pty']];

function normalize(string $raw): string
{
    $s = (string) preg_replace('/\x1B\][^\x07\x1B]*(?:\x07|\x1B\\\\)/', '', $raw);
    $s = (string) preg_replace('/\x1B\[[0-9;?]*[ -\/]*[@-~]/', '', $s);
    $s = (string) preg_replace('/\x1B[@-Z\\\\-_]/', '', $s);
    return strtolower((string) preg_replace('/[^a-zA-Z0-9\x{4e00}-\x{9fff}]/u', '', $s));
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

/**
 * 跑一次 bin/vicecode.php，按序列喂键、排空输出，返回原始输出与退出码。
 * @param array<array{0:string,1:int}> $seq
 */
function runApp(string $bin, array $env, array $descs, array $seq): array
{
    global $readPty;
    $proc = proc_open([PHP_BINARY, $bin], $descs, $pipes, null, $env);
    if ($proc === false) {
        return ['out' => '', 'code' => -1, 'proc' => null];
    }
    stream_set_blocking($pipes[0], false);
    stream_set_blocking($pipes[1], false);
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
    foreach ($seq as [$bytes, $us]) {
        if (!proc_get_status($proc)['running']) {
            break;
        }
        $feed($bytes);
        usleep($us);
        $drain();
    }
    // 兜底退出
    $guard = 0;
    while (proc_get_status($proc)['running'] && $guard < 8) {
        $feed($guard % 2 === 0 ? "\x1b" : "\x11");
        usleep(200000);
        $drain();
        $guard++;
    }
    $code = proc_close($proc);
    return ['out' => $out, 'code' => $code, 'proc' => $proc];
}

$bin = __DIR__ . '/../bin/vicecode.php';

// 终端面板只是六面板之一，高度远小于整屏；每按一次 PageUp 只翻一屏高（约 9 行），
// 故要多按几次才能翻到顶（scrollBy/scrollPty 会夹紧在上下界，多按无害）。
$pages = static function (string $key, int $n, int $us): array {
    $s = [];
    for ($i = 0; $i < $n; $i++) {
        $s[] = [$key, $us];
    }
    return $s;
};

// runner 模式：60 行输出 → PageUp 翻到顶现 R001、PageDown 翻回底现 R060。
// 关键点：php-tui 用差分渲染，滚动走的旧行不会重发到 pty 字节流（grep 累积字节不可靠，
// 见项目 facts）。要可靠断言最旧行可见，滚动到目标位置后**触发一次整屏重绘**——
// 打开/关闭帮助层（? / Esc）会让覆盖层改写所有格，底层终端面板整帧重画，R001/R060 才进流。
$fullRedraw = static function (): array {
    return [
        ["?", 500000],                 // 打开帮助层 → 整屏重绘，底层终端面板整帧发出
        ["", 500000],
        ["\x1b", 500000],              // Esc 关闭帮助层 → 再次整屏重绘
        ["", 500000],
    ];
};
$seqRunner = array_merge(
    [
        ["\t", 700000],                 // sidebar -> editor
        ["\t", 700000],                 // editor -> terminal
        ["printf 'R%03d\\n' $(seq 1 60)\r", 1500000],
        ["", 1500000],                  // 等命令跑完、输出灌满缓冲（此时底部 R048-R060 已发出）
    ],
    $pages("\x1b[5~", 20, 120000),      // PageUp ×20 → 夹紧到顶（内容 R001-R013）
    $fullRedraw(),                      // 整屏重绘 → R001 进入字节流
    $pages("\x1b[6~", 20, 120000),      // PageDown ×20 → 回到底（内容 R048-R060）
    $fullRedraw(),                      // 整屏重绘 → R060 在底部进入字节流
    [
        ["\x1b", 600000],               // Esc 退出应用
        ["\x11", 1800000],              // Ctrl+Q 保底退出
    ],
);

// pty 非捕获态：F2 进 pty → 60 行 → Esc 退捕获 → PageUp 翻回退现 P001、PageDown 回底现 P060
$seqPty = array_merge(
    [
        ["\t", 700000],
        ["\t", 700000],
        ["\x1bOQ", 1800000],            // F2：进入交互式 pty（捕获态）
        ["printf 'P%03d\\n' $(seq 1 60)\r", 1500000],
        ["", 1500000],                  // 等 shell 输出 60 行（底部 P052-P060 已发出）
        ["\x1b", 600000],               // Esc：退捕获（shell 仍在跑，进入非捕获态）
    ],
    $pages("\x1b[5~", 20, 120000),      // PageUp ×20 → 翻 pty 回退到顶（非捕获态才归我们的处理）
    $fullRedraw(),                      // 整屏重绘 → P001 进入字节流
    $pages("\x1b[6~", 20, 120000),      // PageDown ×20 → 回到底
    $fullRedraw(),                      // 整屏重绘 → P060 在底部进入字节流
    [
        ["\x04", 1500000],              // Ctrl+D：shell 退出 → 退回 runner
        ["\x1b", 600000],               // Esc 退出应用
        ["\x11", 1800000],              // Ctrl+Q 保底退出
    ],
);

$rr = runApp($bin, $env, $descs, $seqRunner);
$rp = runApp($bin, $env, $descs, $seqPty);

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

$nr = normalize($rr['out']);
$np = normalize($rp['out']);
$fatalR = str_contains($rr['out'], 'Fatal error') || str_contains($rr['out'], 'Uncaught');
$fatalP = str_contains($rp['out'], 'Fatal error') || str_contains($rp['out'], 'Uncaught');

echo "== runner 模式：PageUp/Down 回退滚动 ==\n";
check($rr['code'] === 0, "干净退出 exit=$rr[code]");
check(!$fatalR, '无 Fatal / Uncaught');
check(str_contains($nr, 'r060'), '底部时最新行 R060 可见（命令输出已渲染）');
check(str_contains($nr, 'r001'), 'PageUp 翻到顶后最旧行 R001 出现（证明回退滚动生效）');
check(str_contains($nr, 'r060'), 'PageDown 翻回底后最新行 R060 仍在（证明可滚回底部）');

echo "\n== pty 非捕获态：PageUp/Down 回退滚动 ==\n";
check($rp['code'] === 0, "干净退出 exit=$rp[code]");
check(!$fatalP, '无 Fatal / Uncaught');
check(str_contains($np, 'p060'), '底部时最新行 P060 可见');
check(str_contains($np, 'p001'), 'PageUp 翻回退后最旧行 P001 出现（证明 pty 回退滚动生效）');
check(str_contains($np, 'p060'), 'PageDown 翻回底后最新行 P060 仍在（证明可滚回底部）');

@unlink($cfgFile);
@unlink($sessionFile);

echo $failed ? "\nRESULT: FAIL\n" : "\nRESULT: PASS\n";
exit($failed ? 1 : 0);

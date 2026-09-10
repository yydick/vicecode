<?php
declare(strict_types=1);

/**
 * 插件系统 V1.1 —— 真实 pty 复验：命令钩子 / 快捷键 / 状态栏点击。
 *
 * 为什么必须走 pty：headless 直接构造事件会绕过 SGR 解析、真实终端的按键字节
 * （Ctrl+K = \x0b、F3 = \x1b[13~）与差分渲染，只有 pty 能证明"用户真的按得到"。
 *
 * 手法：测试插件把**最后收到的事件/命令**写进状态栏段（`M:EV:app.ready` / `M:CMD:k`），
 * pty 侧只认屏幕文本。差分渲染只重发变化字节，故一律用 rebuildScreen 重建最终帧
 * （项目里已踩过三次"文本消失/拆词"的坑）。
 *
 * 运行：timeout 120 php tests/pty_plugin_v11.php
 */

chdir(__DIR__ . '/..');

require __DIR__ . '/../vendor/autoload.php';

// ── 测试插件：把最后收到的事件/命令写进状态栏段 ──────────────
$base = sys_get_temp_dir() . '/vc_pv11_' . getmypid();
@mkdir($base . '/plugins/v11', 0777, true);
file_put_contents($base . '/plugins/v11/plugin.json', json_encode([
    'id' => 'v11', 'name' => 'V11', 'class' => 'V11Plugin', 'entry' => 'V11Plugin.php',
]));
file_put_contents($base . '/plugins/v11/V11Plugin.php', <<<'PHP'
<?php
final class V11Plugin implements \App\Plugin\PluginInterface
{
    public static string $last = 'none';
    public function id(): string { return 'v11'; }
    public function tickInterval(): ?int { return null; }
    public function statusSegments(\App\App $app): array
    {
        // 高优先级保证一定显示；commandId='k' 让这一段可点击
        return [new \App\Plugin\StatusSegment('last', 'M:' . self::$last, 95, 200, 'k')];
    }
    public function commands(): array
    {
        return [new \App\Plugin\PluginCommand('k', 'K cmd', 'Ctrl+K', 10)];
    }
    public function executeCommand(string $i, \App\App $a): void { self::$last = 'CMD:' . $i; }
    public function onEvent(\App\Plugin\PluginEvent $e): void { self::$last = 'EV:' . $e->name; }
}
PHP);

const W = 120;
const H = 40;

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

/** @return string[] 保留空格的屏幕行（供定位列用） */
function rebuildScreenLines(string $raw, int $w, int $h): array
{
    $grid = array_fill(0, $h, array_fill(0, $w, ' '));
    $r = 0;
    $c = 0;
    $len = strlen($raw);
    $i = 0;
    while ($i < $len) {
        $ch = $raw[$i];
        if ($ch === "\x1b") {
            if (isset($raw[$i + 1]) && $raw[$i + 1] === '[') {
                $j = $i + 2;
                $params = '';
                while ($j < $len && !ctype_alpha($raw[$j]) && $raw[$j] !== '~') {
                    $params .= $raw[$j];
                    $j++;
                }
                $cmd = ($j < $len) ? $raw[$j] : '';
                $j++;
                $nums = array_map('intval', explode(';', $params === '' ? '1' : $params));
                switch ($cmd) {
                    case 'H':
                    case 'f':
                        $r = max(0, ($nums[0] ?? 1) - 1);
                        $c = max(0, ($nums[1] ?? 1) - 1);
                        break;
                    case 'A': $r = max(0, $r - ($nums[0] ?? 1)); break;
                    case 'B': $r = min($h - 1, $r + ($nums[0] ?? 1)); break;
                    case 'C': $c = min($w - 1, $c + ($nums[0] ?? 1)); break;
                    case 'D': $c = max(0, $c - ($nums[0] ?? 1)); break;
                    case 'J':
                        if (($nums[0] ?? 0) === 2 || ($nums[0] ?? 0) === 3) {
                            $grid = array_fill(0, $h, array_fill(0, $w, ' '));
                        }
                        break;
                    case 'K':
                        if (($nums[0] ?? 0) === 0) {
                            for ($k = $c; $k < $w; $k++) { $grid[$r][$k] = ' '; }
                        } elseif (($nums[0] ?? 0) === 1) {
                            for ($k = 0; $k <= $c; $k++) { $grid[$r][$k] = ' '; }
                        } elseif (($nums[0] ?? 0) === 2) {
                            for ($k = 0; $k < $w; $k++) { $grid[$r][$k] = ' '; }
                        }
                        break;
                }
                $i = $j;
                continue;
            }
            $i++;
            while ($i < $len && !ctype_alpha($raw[$i]) && $raw[$i] !== "\x1b") {
                $i++;
            }
            if ($i < $len) {
                $i++;
            }
            continue;
        }
        if ($ch === "\r") { $c = 0; $i++; continue; }
        if ($ch === "\n") { $r = min($h - 1, $r + 1); $c = 0; $i++; continue; }
        if ($ch === "\x00" || $ch === "\x08") { $i++; continue; }
        if ($r >= 0 && $r < $h && $c >= 0 && $c < $w) {
            $grid[$r][$c] = $ch;
        }
        $c++;
        if ($c >= $w) { $c = 0; $r = min($h - 1, $r + 1); }
        $i++;
    }
    return array_map(static fn (array $row): string => implode('', $row), $grid);
}

function normalizeLines(array $lines): string
{
    $t = implode("\n", $lines);
    $r = preg_replace('/[^a-zA-Z0-9\x{4e00}-\x{9fff}]/u', '', $t);
    return $r === null ? (string) preg_replace('/[^a-zA-Z0-9]/', '', $t) : $r;
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
 * 跑一轮：喂完字节序列，返回 [归一化最终帧, 原始输出, 退出码]。
 * 等待用条件轮询（读到连续空为止），不赌固定 sleep。
 */
function runOnce(array $env, array $seq, callable $readPty): array
{
    $descs = [0 => ['pty'], 1 => ['pty'], 2 => ['pty']];
    $proc = proc_open([PHP_BINARY, 'bin/vicecode.php'], $descs, $pipes, null, $env);
    if ($proc === false) {
        return ['', '', 1];
    }
    stream_set_blocking($pipes[0], false);
    stream_set_blocking($pipes[1], false);

    $drain = static function () use ($pipes, $readPty): string {
        $acc = '';
        $empty = 0;
        while ($empty < 3) {
            $b = $readPty($pipes[1], 65536);
            if ($b === '' || $b === false) {
                $empty++;
            } else {
                $acc .= $b;
                $empty = 0;
            }
            usleep(20000);
        }
        return $acc;
    };

    $raw = $drain();                       // 首帧
    foreach ($seq as $bytes) {
        fwrite($pipes[0], $bytes);
        $raw .= $drain();
    }
    fwrite($pipes[0], "\x11");             // Ctrl+Q 干净退出
    // 守卫：启动早期 pty 会吞掉首包 Ctrl+Q（原始模式建立前的字节会丢失），
    // 若进程仍存活则周期性重发，避免 proc_close() 永久阻塞把测试挂死。
    $guard = 0;
    while (proc_get_status($proc)['running'] && $guard < 25) {
        usleep(200000);
        fwrite($pipes[0], "\x11");
        $guard++;
    }
    $raw .= $drain();

    $frames = normalizeLines(rebuildScreenLines($raw, W, H));
    foreach ($pipes as $p) {
        if (is_resource($p)) {
            fclose($p);
        }
    }
    $code = proc_close($proc);
    return [$frames, $raw, $code];
}

$env = array_merge(getenv(), [
    'COLUMNS' => (string) W,
    'LINES' => (string) H,
    'TERM' => 'xterm-256color',
    'VICECODE_PLUGINS_DIR' => $base,
    'VICECODE_CONFIG' => tempnam(sys_get_temp_dir(), 'vc_v11cfg'),
]);

echo "== 真实 pty：事件与快捷键 ==\n";
[$frame, $raw, $code] = runOnce($env, [], $readPty);
if (getenv('VC_DEBUG') !== false) {
    $dbg = rebuildScreenLines($raw, W, H);
    echo "    debug 状态栏行=[" . trim($dbg[H - 1] ?? '') . "]\n";
    echo "    debug 归一化帧前 200=[" . substr($frame, 0, 200) . "]\n";
}
check(str_contains($frame, 'MEVappready'), '首帧状态栏段显示 M:EV:app.ready（事件在真实终端里也发出）');
check(!str_contains($raw, 'Fatal') && !str_contains($raw, 'Uncaught'), '启动无 Fatal / Uncaught');

[$frame2] = runOnce($env, ["\x0b"], $readPty);   // Ctrl+K
check(str_contains($frame2, 'MCMDk'), 'Ctrl+K → 段变为 M:CMD:k（快捷键在真实终端生效）');

echo "== 真实 pty：状态栏段点击 ==\n";
// 先拿到段所在列：重建首帧（保留空格）后找 'M:' 在状态栏行的位置
[$initFrame, $initRaw] = runOnce($env, [], $readPty);
$lines = rebuildScreenLines($initRaw, W, H);
$statusRow = $lines[H - 1] ?? '';
$mcol = strpos($statusRow, 'M:');
if ($mcol === false) {
    check(false, '定位不到状态栏插件段（状态栏行：' . trim($statusRow) . '）');
} else {
    // SGR 按下/抬起（1-based 列与行；状态栏在最后一行 → row = H）
    $click = "\x1b[<0;" . ($mcol + 1) . ";" . H . "M" . "\x1b[<0;" . ($mcol + 1) . ";" . H . "m";
    [$frame3] = runOnce($env, [$click], $readPty);
    check(str_contains($frame3, 'MCMDk'), '点状态栏插件段（列 ' . ($mcol + 1) . '）→ M:CMD:k');

    // 反例：点状态栏最左的空白列（列 1）不应触发
    $clickBlank = "\x1b[<0;1;" . H . "M" . "\x1b[<0;1;" . H . "m";
    [$frame4] = runOnce($env, ["\x0b", $clickBlank], $readPty);
    check(str_contains($frame4, 'MCMDk'), '点状态栏空白列后仍是上一次的 M:CMD:k（点错不触发新命令）');
}

echo "== 真实 pty：菜单链路（F10 → 走到插件组 → Enter）==\n";
[$frame5, $raw5, $code5] = runOnce($env, ["\x1b[21~", "\x1b[C", "\x1b[C", "\x1b[C", "\x1b[C", "\r"], $readPty);
check(str_contains($frame5, 'MCMDk'), 'F10 + 右×4 + Enter → 执行插件组首项（菜单链路通）');
check($code5 === 0, 'Ctrl+Q 退出码为 0（实际 ' . $code5 . '）');
check(!str_contains($raw5, 'Fatal') && !str_contains($raw5, 'Warning'), '整轮输出无 Fatal / PHP Warning');

exec('rm -rf ' . escapeshellarg($base));
echo $failed ? "\npty 插件 V1.1 验收 FAIL\n" : "\npty 插件 V1.1 验收全部 PASS\n";
exit($failed ? 1 : 0);

<?php
declare(strict_types=1);

/**
 * 自定义面板（插件浮层宿主）—— 真实 pty 复验。
 *
 * 为什么必须走 pty：headless 直接构造事件会绕过 SGR 解析、真实按键字节与差分渲染，
 * 只有 pty 能证明「用户真的按得到」。手法复刻 tests/pty_plugin_v11.php 的
 * proc_open + 退出守卫 + rebuildScreenLines + normalizeLines。
 *
 * 驱动链路：F1（命令面板）→ 输入 "host"（ASCII 子串匹配 action id panel.host.open，
 * 规避 CJK 进 pty 的帧脆弱性）→ Enter 打开浮层 → 断言面板内容可见 → Tab 切到第二面板
 * → Esc 关闭 → 断言内容消失。
 *
 * 运行：timeout 120 php tests/pty_plugin_panel.php
 */

chdir(__DIR__ . '/..');

require __DIR__ . '/../vendor/autoload.php';

// ── 测试插件：声明 2 个面板，内容渲染产出 ASCII 标记 ──────
$base = sys_get_temp_dir() . '/vc_ppanel_' . getmypid();
@mkdir($base . '/plugins/pp', 0777, true);
file_put_contents($base . '/plugins/pp/plugin.json', json_encode([
    'id' => 'pp', 'name' => 'PP', 'class' => 'PanelPlugin', 'entry' => 'PanelPlugin.php',
]));
file_put_contents($base . '/plugins/pp/PanelPlugin.php', <<<'PHP'
<?php
final class PanelPlugin implements \App\Plugin\PluginInterface
{
    public function id(): string { return 'pp'; }
    public function tickInterval(): ?int { return null; }
    public function statusSegments(\App\App $app): array { return []; }
    public function panels(): array
    {
        return [
            new \App\Plugin\PluginPanel('a', 'Panel A',
                fn($app, $w, $h) => \PhpTui\Tui\Extension\Core\Widget\ParagraphWidget::fromString('ZZPNL_A')),
            new \App\Plugin\PluginPanel('b', 'Panel B',
                fn($app, $w, $h) => \PhpTui\Tui\Extension\Core\Widget\ParagraphWidget::fromString('ZZPNL_B')),
        ];
    }
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

/**
 * 把 pty 原始字节流重建成「屏幕行」数组（保留空格，供列定位）。
 *
 * 关键修正：终端里多字节字符（制表框线 1 列、CJK 2 列）占 1~2 个显示列，
 * 但每个 UTF-8 码点是 1~4 字节。旧实现按「每字节 1 列」步进，使 CUP 之后的
 * 所有列定位整体错位，导致 Tab 帧把内容 "ZZPNL_B" 的 'B' 偏到错误列、'A' 残留。
 * 这里用 mb_strwidth 按显示宽度步进，使坐标与真实终端一致。
 *
 * CSI 终止符范围是 0x40–0x7E（含多参 SGR），仅 H/f 改光标，其余（m/J/K/…）忽略。
 */
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
                while ($j < $len && !($raw[$j] >= '@' && $raw[$j] <= '~')) {
                    $j++;
                }
                if ($j >= $len) {
                    break;
                }
                $seq = substr($raw, $i + 2, $j - ($i + 2));
                $cmd = $raw[$j];
                $nums = array_map('intval', explode(';', $seq === '' ? '1' : $seq));
                if ($cmd === 'H' || $cmd === 'f') {
                    $r = max(0, ($nums[0] ?? 1) - 1);
                    $c = max(0, ($nums[1] ?? 1) - 1);
                } elseif ($cmd === 'A') {
                    $r = max(0, $r - ($nums[0] ?? 1));
                } elseif ($cmd === 'B') {
                    $r = min($h - 1, $r + ($nums[0] ?? 1));
                } elseif ($cmd === 'C') {
                    $c = min($w - 1, $c + ($nums[0] ?? 1));
                } elseif ($cmd === 'D') {
                    $c = max(0, $c - ($nums[0] ?? 1));
                } elseif ($cmd === 'J') {
                    if (($nums[0] ?? 0) === 2 || ($nums[0] ?? 0) === 3) {
                        $grid = array_fill(0, $h, array_fill(0, $w, ' '));
                    }
                } elseif ($cmd === 'K') {
                    if (($nums[0] ?? 0) === 0) {
                        for ($k = $c; $k < $w; $k++) { $grid[$r][$k] = ' '; }
                    } elseif (($nums[0] ?? 0) === 1) {
                        for ($k = 0; $k <= $c; $k++) { $grid[$r][$k] = ' '; }
                    } elseif (($nums[0] ?? 0) === 2) {
                        for ($k = 0; $k < $w; $k++) { $grid[$r][$k] = ' '; }
                    }
                }
                $i = $j + 1;
                continue;
            }
            // 其它 ESC 序列（字符集等）：跳到字母或下一个 ESC
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
        if (ord($ch) < 32 && $ch !== "\t") { $i++; continue; }

        // UTF-8 多字节：按显示宽度写入并保持光标同步
        $o = ord($ch);
        $bytes = 1;
        if ($o >= 0xF0) {
            $bytes = 4;
        } elseif ($o >= 0xE0) {
            $bytes = 3;
        } elseif ($o >= 0xC0) {
            $bytes = 2;
        }
        $cp = substr($raw, $i, $bytes);
        $width = mb_strwidth($cp, 'UTF-8');
        if ($width < 1) {
            $width = 1;
        }
        if ($r >= 0 && $r < $h && $c >= 0 && $c < $w) {
            $grid[$r][$c] = $cp;
        }
        $c += $width;
        if ($c >= $w) { $c = 0; $r = min($h - 1, $r + 1); }
        $i += $bytes;
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
    // 若序列以「孤立 Esc」收尾，需等待读键协程空闲超时（waitEvent 0.2s）触发解析器冲刷，
    // 把暂存的裸 \x1b 定稿为 Esc 键，浮层才会真正关闭。先静默空等再 drain。
    usleep(400000);
    $raw .= $drain();
    // ⚠️ 在发送 Ctrl+Q 之前先抓「最后一个按键后的帧」：应用退出时可能清屏（如退出交替屏），
    // 若等到退出后再重建帧，浮层内容会被清屏冲掉，断言会误判「Tab 没切 / Esc 没关」。
    $frameAfterSeq = normalizeLines(rebuildScreenLines($raw, W, H));
    fwrite($pipes[0], "\x11");             // Ctrl+Q 干净退出
    $guard = 0;
    while (proc_get_status($proc)['running'] && $guard < 25) {
        usleep(200000);
        fwrite($pipes[0], "\x11");
        $guard++;
    }
    $raw .= $drain();

    foreach ($pipes as $p) {
        if (is_resource($p)) {
            fclose($p);
        }
    }
    $code = proc_close($proc);
    return [$frameAfterSeq, $raw, $code];
}

$env = array_merge(getenv(), [
    'COLUMNS' => (string) W,
    'LINES' => (string) H,
    'TERM' => 'xterm-256color',
    'VICECODE_PLUGINS_DIR' => $base,
    'VICECODE_CONFIG' => tempnam(sys_get_temp_dir(), 'vc_ppcfg'),
]);

echo "== 真实 pty：命令面板(F1) → host → 打开插件面板浮层 ==\n";
// F1 开命令面板，输入 host（匹配 action id panel.host.open），Enter 执行
[$frame, $raw, $code] = runOnce($env, ["\x1bOP", "host", "\r"], $readPty);
check(str_contains($frame, 'ZZPNLA'), 'F1 + host + Enter → 浮层显示 Panel A（ZZPNL_A 可见）');
check(!str_contains($raw, 'Fatal') && !str_contains($raw, 'Uncaught'), '启动无 Fatal / Uncaught');
check($code === 0, 'Ctrl+Q 退出码为 0（实际 ' . $code . '）');

echo "== 真实 pty：Tab 切到第二面板 ==\n";
// 重新走一遍并多按一次 Tab
[$frame2, $raw2] = runOnce($env, ["\x1bOP", "host", "\r", "\x09"], $readPty);
check(str_contains($frame2, 'ZZPNLB'), '打开后 Tab → 浮层显示 Panel B（ZZPNL_B 可见）');
check(!str_contains($raw2, 'Fatal') && !str_contains($raw2, 'Uncaught'), 'frame2 无 Fatal / Uncaught');

echo "== 真实 pty：Esc 关闭浮层 ==\n";
[$frame3] = runOnce($env, ["\x1bOP", "host", "\r", "\x1b"], $readPty);
check(!str_contains($frame3, 'ZZPNLA'), 'Esc → 浮层关闭，Panel A 内容消失');
check(!str_contains($frame3, 'Fatal') && !str_contains($frame3, 'Warning'), '整轮输出无 Fatal / PHP Warning');

exec('rm -rf ' . escapeshellarg($base));
echo $failed ? "\npty 自定义面板验收 FAIL\n" : "\npty 自定义面板验收全部 PASS\n";
exit($failed ? 1 : 0);

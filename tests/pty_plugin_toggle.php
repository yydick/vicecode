<?php
declare(strict_types=1);

/**
 * 插件启用/禁用开关 —— 真实 pty 复验。
 *
 * 为什么必须走 pty：Space 在真实终端里是一个普通字节（0x20），要经 EventParser →
 * App::handle 的字符分支 → 侧栏「扩展」tab 才落到切换逻辑；headless 直接构造事件
 * 绕过了这一段。而且"切换后到底有没有生效"必须看真实终端上：
 *   ① 本次会话内：侧栏行由「已启用」变「已禁用」、状态栏给出回执；
 *   ② 下一次启动：被禁用的插件**根本没被加载**（状态栏段消失），配置文件确实落了盘；
 *   ③ 再按一次：段重新出现（热启用真的把段注入回来了）。
 * 差分渲染只重发变化字节，故一律用 rebuildScreen 重建最终帧，不做整词 grep。
 *
 * 运行：timeout 120 php tests/pty_plugin_toggle.php
 */

chdir(__DIR__ . '/..');

require __DIR__ . '/../vendor/autoload.php';

use App\App;
use PhpTui\Tui\Display\Area;

// ── 测试插件：状态栏段文本固定为 DM0（全 UI 唯一），不含配置/命令，避免额外干扰 ──
$base = sys_get_temp_dir() . '/vc_ptoggle_' . getmypid();
@mkdir($base . '/plugins/demo', 0777, true);
file_put_contents($base . '/plugins/demo/plugin.json', json_encode([
    'id' => 'demo', 'name' => 'Demo', 'class' => 'VCTogglePlugin', 'entry' => 'VCTogglePlugin.php',
]));
file_put_contents($base . '/plugins/demo/VCTogglePlugin.php', <<<'PHP'
<?php
final class VCTogglePlugin implements \App\Plugin\PluginInterface
{
    public function id(): string { return 'demo'; }
    public function name(): string { return 'Demo'; }
    public function tickInterval(): ?int { return null; }
    public function statusSegments(\App\App $app): array
    {
        return [new \App\Plugin\StatusSegment('demo', 'DM0', 90, 3)];
    }
}
PHP);

const W = 120;
const H = 40;

$cfgFile = $base . '/cfg.json';
file_put_contents($cfgFile, json_encode([]));

// 隔离：测试配置指向独立目录，避免读到真实家目录残留（主题/语言/会话）
putenv('APP_LOCALE=zh_CN');
putenv('VICECODE_PLUGINS_DIR=' . $base);
putenv('VICECODE_PLUGINS_CONFIG=' . $cfgFile);
putenv('VICECODE_CONFIG=' . $base . '/vicerc.json');

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
        // 多字节字符必须整体落进一个格子、并按**显示宽度**推进列号：
        // 逐字节写会把 UTF-8 拆碎（归一化 /u 直接失败、汉字全丢），
        // 而汉字在终端里占 2 列，只推进 1 列会让整行后面全部错位。
        $len1 = 1;
        $b = ord($ch);
        if ($b >= 0xF0) { $len1 = 4; } elseif ($b >= 0xE0) { $len1 = 3; } elseif ($b >= 0xC0) { $len1 = 2; }
        $seq = substr($raw, $i, $len1);
        $cw = \App\Text\DisplayWidth::dispWidth($seq);
        if ($cw < 1) { $cw = 1; }
        if ($r >= 0 && $r < $h && $c >= 0 && $c < $w) {
            $grid[$r][$c] = $seq;
        }
        $c += $cw;
        if ($c >= $w) { $c = 0; $r = min($h - 1, $r + 1); }
        $i += $len1;
    }
    return array_map(static fn (array $row): string => implode('', $row), $grid);
}

/** 归一化：去掉空白与标点，只留字母数字与汉字（差分渲染会把同行文本拆成多段写入） */
function norm(array $lines): string
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
 * 跑一轮：按序喂入每步字节，返回 [每步之后重建的屏幕行, 原始输出, 退出码]。
 * 第 0 项是首帧（未喂任何字节）。等待用条件轮询（连续 3 次空），不赌固定 sleep。
 * @return array{0:list<string[]>,1:string,2:int}
 */
function runPty(array $env, array $steps, int $w, int $h, callable $readPty): array
{
    $descs = [0 => ['pty'], 1 => ['pty'], 2 => ['pty']];
    $proc = proc_open([PHP_BINARY, 'bin/vicecode.php'], $descs, $pipes, null, $env);
    if ($proc === false) {
        return [[], '', 1];
    }
    stream_set_blocking($pipes[0], false);
    stream_set_blocking($pipes[1], false);

    // 条件等待：读到「连续 4 次空」为止；$waitContent=true 时还要先拿到至少一个字节
    // （应用启动/重绘会晚于按键，若不等内容就退出会读到空帧——本测试踩过）。
    $drain = static function (bool $waitContent = true, float $timeoutMs = 3000.0) use ($pipes, $readPty): string {
        $acc = '';
        $empty = 0;
        $t0 = microtime(true);
        while (true) {
            $b = $readPty($pipes[1], 65536);
            if ($b === '' || $b === false) {
                $empty++;
            } else {
                $acc .= $b;
                $empty = 0;
            }
            if ($empty >= 4 && (!$waitContent || $acc !== '')) {
                break;
            }
            if ((microtime(true) - $t0) * 1000 > $timeoutMs) {
                break;
            }
            usleep(20000);
        }
        return $acc;
    };

    $raw = $drain();
    $frames = [rebuildScreenLines($raw, $w, $h)];
    foreach ($steps as $bytes) {
        if ($bytes !== '') {
            fwrite($pipes[0], $bytes);
        }
        $raw .= $drain();
        $frames[] = rebuildScreenLines($raw, $w, $h);
    }
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
    return [$frames, $raw, $code];
}

/** 侧栏「扩展」tab 的点击字节（动态探针取几何：树/面板坐标随布局变化，绝不写死） */
function tabClickBytes(int $w, int $h, App $probe): string
{
    $sb = $probe->areas(Area::fromDimensions($w, $h))['sidebar'];
    $seg = max(1, intdiv(max(1, $sb->width - 2), 4));      // 侧栏共 4 个 tab
    $inner = 3 * $seg + intdiv($seg, 2);                   // 第 4 个 tab（扩展）中央
    $x = $sb->position->x + 1 + $inner + 1;                // SGR 1-based
    $y = $sb->position->y + 1 + 1;                         // tab 行是 inner 第 0 行
    return "\x1b[<0;{$x};{$y}M\x1b[<0;{$x};{$y}m";
}

$probe = new App();
$tabWide = tabClickBytes(W, H, $probe);
$tabTiny = tabClickBytes(40, 10, $probe);

$env = array_merge(getenv(), [
    'COLUMNS' => (string) W,
    'LINES' => (string) H,
    'TERM' => 'xterm-256color',
    'APP_LOCALE' => 'zh_CN',
    'VICECODE_PLUGINS_DIR' => $base,
    'VICECODE_PLUGINS_CONFIG' => $cfgFile,
    'VICECODE_CONFIG' => $base . '/vicerc.json',
]);

echo "== 真实 pty：会话内 Space 切换 ==\n";
[$frames, $rawA, $codeA] = runPty($env, [$tabWide, ' '], W, H, $readPty);
$f0 = norm($frames[0] ?? []);
$f1 = norm($frames[1] ?? []);
$f2 = norm($frames[2] ?? []);
if (getenv('VC_DEBUG') !== false) {
    foreach ([0 => $f0, 1 => $f1, 2 => $f2] as $k => $v) {
        echo "    debug frame{$k} 状态行=[" . trim(($frames[$k] ?? [])[H - 1] ?? '') . "]\n";
        echo "    debug frame{$k} 侧栏行=[" . trim(($frames[$k] ?? [])[6] ?? '') . "] len=" . strlen($v) . "\n";
        echo "    debug frame{$k} 含demo=" . (str_contains($v, 'demo') ? 'Y' : 'N')
            . " 含已启用=" . (str_contains($v, '已启用') ? 'Y' : 'N')
            . " 含已禁用=" . (str_contains($v, '已禁用') ? 'Y' : 'N')
            . " 含插件=" . (str_contains($v, '插件') ? 'Y' : 'N') . "\n";
    }
}
check(str_contains($f0, 'DM0'), '首帧状态栏出现插件段 DM0（插件已启用并注入段）');
check(str_contains($f1, 'demo') && str_contains($f1, '已启用'), '点侧栏扩展 tab 后列出 demo 且标注「已启用」');
check(str_contains($f2, 'demo') && str_contains($f2, '已禁用'), '按 Space 后侧栏该行变为「已禁用」');
check(!str_contains($f2, '已启用'), '同一帧里「已启用」已被改写掉（不是只多弹了一行提示）');
check($codeA === 0, 'Ctrl+Q 退出码为 0（实际 ' . $codeA . '）');

echo "== 真实 pty：落盘后重启，插件不再加载 ==\n";
$saved = json_decode((string) file_get_contents($cfgFile), true);
check(is_array($saved) && ($saved['demo']['enabled'] ?? null) === false, 'Space 已把 enabled=false 落盘到插件专用配置文件');
[$frames2, $rawB, $codeB] = runPty($env, [$tabWide], W, H, $readPty);
$g0 = norm($frames2[0] ?? []);
$g1 = norm($frames2[1] ?? []);
check(!str_contains($g0, 'DM0'), '重启后首帧无 DM0：被禁用的插件根本没被加载（不注入状态栏段）');
check(str_contains($g1, 'demo') && str_contains($g1, '已禁用'), '重启后侧栏仍列出 demo 并标注「已禁用」（可再打开）');

echo "== 真实 pty：再按一次 Space 热启用，段立刻回来 ==\n";
[$frames3, $rawC, $codeC] = runPty($env, [$tabWide, ' '], W, H, $readPty);
$h2 = norm($frames3[2] ?? []);
check(str_contains($h2, '已启用') && !str_contains($h2, '已禁用'), '按 Space 后侧栏该行改回「已启用」');
check(str_contains($h2, 'DM0'), '热启用后状态栏段 DM0 立刻重新出现（无需重启）');

echo "== 真实 pty：极小视口下按 Space 不崩 ==\n";
$envTiny = array_merge($env, ['COLUMNS' => '40', 'LINES' => '10']);
[$frames4, $rawD, $codeD] = runPty($envTiny, [$tabTiny, ' '], 40, 10, $readPty);
$t2 = norm($frames4[2] ?? []);
check(str_contains($t2, 'demo'), '40x10 视口下扩展 tab 仍列出插件（渲染不越界）');
check($codeD === 0, '40x10 下 Space 切换后退出码仍为 0（实际 ' . $codeD . '）');

$allRaw = $rawA . $rawB . $rawC . $rawD;
check(!str_contains($allRaw, 'Fatal') && !str_contains($allRaw, 'Uncaught'), '整轮输出无 Fatal / Uncaught');
check(!preg_match('/PHP (Warning|Notice)/', $allRaw), '整轮输出无 PHP Warning / Notice');

exec('rm -rf ' . escapeshellarg($base));
putenv('VICECODE_PLUGINS_CONFIG');
putenv('VICECODE_PLUGINS_DIR');

echo $failed ? "\npty 插件开关验收 FAIL\n" : "\npty 插件开关验收全部 PASS\n";
exit($failed ? 1 : 0);

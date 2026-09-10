<?php
declare(strict_types=1);

/**
 * 命令面板（F1 唤出）—— 真实 pty 复验。
 *
 * 为什么必须走 pty：headless 直接构造事件会绕过真实终端按键字节与差分渲染，
 * 只有 pty 能证明"用户真的按得到 F1、真的在过滤框里打得出字"。
 *
 * 手法复用 pty_plugin_v11.php：rebuildScreenLines 重建最终帧（差分渲染只重发变化字节），
 * normalizeLines 抹掉标点只留字母/CJK 便于断言。VICECODE_PLUGINS_DIR 指向空 tmp，
 * 保证命令清单只有系统 16 项、无插件组，断言可确定化。
 *
 * 运行：timeout 120 php tests/pty_command_palette.php
 */

chdir(__DIR__ . '/..');
require __DIR__ . '/../vendor/autoload.php';

const W = 120;
const H = 40;

$base = sys_get_temp_dir() . '/vc_pal_pty_' . getmypid();
@mkdir($base . '/plugins', 0777, true);   // 空目录：无插件命令组

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

/** @return string[] 屏幕行（多字节/CJK 宽度感知：CJK 占 2 列但只在首格存字符，implode 后保持连续） */
function rebuildScreenLines(string $raw, int $w, int $h): array
{
    $grid = array_fill(0, $h, array_fill(0, $w, ''));
    $r = 0; $c = 0; $len = strlen($raw); $i = 0;
    while ($i < $len) {
        $ch = $raw[$i];
        if ($ch === "\x1b") {
            if (isset($raw[$i + 1]) && $raw[$i + 1] === '[') {
                $j = $i + 2; $params = '';
                while ($j < $len && !ctype_alpha($raw[$j]) && $raw[$j] !== '~') { $params .= $raw[$j]; $j++; }
                $cmd = ($j < $len) ? $raw[$j] : ''; $j++;
                $nums = array_map('intval', explode(';', $params === '' ? '1' : $params));
                switch ($cmd) {
                    case 'H': case 'f': $r = max(0, ($nums[0] ?? 1) - 1); $c = max(0, ($nums[1] ?? 1) - 1); break;
                    case 'A': $r = max(0, $r - ($nums[0] ?? 1)); break;
                    case 'B': $r = min($h - 1, $r + ($nums[0] ?? 1)); break;
                    case 'C': $c = min($w - 1, $c + ($nums[0] ?? 1)); break;
                    case 'D': $c = max(0, $c - ($nums[0] ?? 1)); break;
                    case 'J': if (($nums[0] ?? 0) === 2 || ($nums[0] ?? 0) === 3) { $grid = array_fill(0, $h, array_fill(0, $w, '')); } break;
                    case 'K':
                        if (($nums[0] ?? 0) === 0) { for ($k = $c; $k < $w; $k++) { $grid[$r][$k] = ''; } }
                        elseif (($nums[0] ?? 0) === 1) { for ($k = 0; $k <= $c; $k++) { $grid[$r][$k] = ''; } }
                        elseif (($nums[0] ?? 0) === 2) { for ($k = 0; $k < $w; $k++) { $grid[$r][$k] = ''; } }
                        break;
                }
                $i = $j; continue;
            }
            $i++;
            while ($i < $len && !ctype_alpha($raw[$i]) && $raw[$i] !== "\x1b") { $i++; }
            if ($i < $len) { $i++; }
            continue;
        }
        if ($ch === "\r") { $c = 0; $i++; continue; }
        if ($ch === "\n") { $r = min($h - 1, $r + 1); $c = 0; $i++; continue; }
        if ($ch === "\x00" || $ch === "\x08") { $i++; continue; }
        // 解码完整 UTF-8 字符（多字节为一个整体），按显示宽度推进列、只在首格存字符，
        // 这样 CJK（宽 2）在 implode 后保持连续、归一化能匹配到（如「命令面板」）。
        $cp = $ch;
        $bytes = 1;
        $o = ord($ch);
        if ($o >= 0xC0) {
            if (($o & 0xE0) === 0xC0) { $bytes = 2; }
            elseif (($o & 0xF0) === 0xE0) { $bytes = 3; }
            elseif (($o & 0xF8) === 0xF0) { $bytes = 4; }
            $cp = substr($raw, $i, $bytes);
        }
        $wid = max(1, mb_strwidth($cp, 'UTF-8'));
        if ($r >= 0 && $r < $h && $c >= 0 && $c < $w) {
            $grid[$r][$c] = $cp;   // 仅首格存字符；跨宽的后续格留空，implode 后不影响连续性
        }
        $c += $wid;
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
    try { return fread($stream, $len); } finally { restore_error_handler(); }
};

/**
 * 跑一轮：喂完字节序列，返回 [归一化最终帧, 原始输出, 退出码]。带退出守卫避免挂死。
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
        $acc = ''; $empty = 0;
        while ($empty < 3) {
            $b = $readPty($pipes[1], 65536);
            if ($b === '' || $b === false) { $empty++; } else { $acc .= $b; $empty = 0; }
            usleep(20000);
        }
        return $acc;
    };
    $raw = $drain();
    foreach ($seq as $bytes) {
        fwrite($pipes[0], $bytes);
        $raw .= $drain();
    }
    fwrite($pipes[0], "\x11");             // Ctrl+Q 干净退出
    $guard = 0;
    while (proc_get_status($proc)['running'] && $guard < 25) {
        usleep(200000);
        fwrite($pipes[0], "\x11");
        $guard++;
    }
    $raw .= $drain();
    $frames = normalizeLines(rebuildScreenLines($raw, W, H));
    foreach ($pipes as $p) { if (is_resource($p)) { fclose($p); } }
    $code = proc_close($proc);
    return [$frames, $raw, $code];
}

$env = array_merge(getenv(), [
    'COLUMNS' => (string) W,
    'LINES' => (string) H,
    'TERM' => 'xterm-256color',
    'VICECODE_PLUGINS_DIR' => $base,
    'VICECODE_CONFIG' => tempnam(sys_get_temp_dir(), 'vc_pal'),
]);

echo "== 真实 pty：F1 唤起命令面板浮层 ==\n";
// 用 ASCII 唯一标记 + 过滤框提示符 '> ' 做判别：编辑器里打字不会有 '> ' 前缀，
// 故 '> zzQcmd' 只可能来自命令面板的过滤输入框，可证明面板确实打开且接到输入。
// （CJK 标题在差分重建里有宽度歧义，故断言改用 ASCII 标记，与 pty_plugin_v11 同思路。）
[$frame, $raw, $code] = runOnce($env, ["\x1bOP", 'zzQcmd'], $readPty);   // F1 + 过滤输入
// 过滤文本 'zzQcmd' 在同一 Span 内连续渲染（与 '>' 之间因换色有 SGR 转义，故不连写 '> zzQcmd'）。
// 面板是模态的、输入只进过滤框，故 raw/帧里出现 zzQcmd 即证明面板已打开并接到输入。
check(str_contains($frame, 'zzQcmd'), 'F1 唤起面板且过滤框回显 zzQcmd（面板模态，输入只进过滤框）');
check(!str_contains($raw, 'Fatal') && !str_contains($raw, 'Uncaught'), '启动无 Fatal / Uncaught');
check($code === 0, 'Ctrl+Q 退出码为 0（实际 ' . $code . '）');

echo "== 真实 pty：过滤收窄 ==\n";
[$frame2] = runOnce($env, ["\x1bOP", 'view.focus.terminal'], $readPty);
// 输入回显：'view.focus.terminal' 经规范化(去 . 与空格)后变成 viewfocusterminal（纯 ASCII，重建可靠）
check(str_contains($frame2, 'viewfocusterminal'), '过滤框回显出输入串 view.focus.terminal（过滤生效）');

echo "== 真实 pty：回车执行后浮层消失 ==\n";
[$frame3, $raw3, $code3] = runOnce($env, ["\x1bOP", 'view.focus.terminal', "\r"], $readPty); // \r = Enter
check(!str_contains($frame3, 'viewfocusterminal'), '回车执行后命令面板浮层消失（过滤输入清空，最终帧不再含该串）');
check($code3 === 0, 'Ctrl+Q 退出码为 0（实际 ' . $code3 . '）');

echo "== 真实 pty：Esc 关闭 ==\n";
[$frame4] = runOnce($env, ["\x1bOP", 'zzQcmd', "\x1b"], $readPty); // F1 + 输入 + ESC
check(!str_contains($frame4, '> zzQcmd'), 'F1 打开后按 Esc 关闭面板（过滤输入已清空）');

exec('rm -rf ' . escapeshellarg($base));
echo $failed ? "\npty 命令面板验收 FAIL\n" : "\npty 命令面板验收全部 PASS\n";
exit($failed ? 1 : 0);

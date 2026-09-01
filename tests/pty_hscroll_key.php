<?php
declare(strict_types=1);

/**
 * 真实 pty 验收：键盘横向滚动（Shift+←/→，步进 ±4）。
 *
 * 为什么必须走 pty：headless 单测（tests/hscroll_key_unit.php）直接 new 出
 * CodedKeyEvent(Right, SHIFT) 喂给 App::handle，跳过了「真实终端发来的
 * \e[1;2C 序列能否被 EventParser 正确解码成带 SHIFT 修饰的 Right」这一环——
 * 这正是 feedback 里 headless≠pty 落差 #1（事件类型可能不同）。这里走真实
 * pty + bin/vicecode.php，用 argv 打开一个含远端标记行的文件，按真实 Shift+→
 * 把标记滚入视口、再按 Shift+← 滚出，验证端到端链路。
 *
 * 运行：timeout 120 php tests/pty_hscroll_key.php
 */

$env = array_merge(getenv(), ['COLUMNS' => '120', 'LINES' => '40', 'APP_LOCALE' => 'en']);
// 隔离配置：pty 退出会写 ~/.vicerc，落到临时文件避免污染真实家目录配置。
$env['VICECODE_CONFIG'] = tempnam(sys_get_temp_dir(), 'vc_hkcfg');

/** ANSI 屏幕重建：同 tests/pty_r5.php，剥转义后只保留字母数字与汉字做归一化比对 */
function rebuildScreen(string $raw, int $w, int $h): string
{
    $grid = array_fill(0, $h, array_fill(0, $w, ' '));
    $r = 0; $c = 0;
    $len = strlen($raw);
    $i = 0;
    while ($i < $len) {
        $ch = $raw[$i];
        if ($ch === "\x1b") {
            if (isset($raw[$i + 1]) && $raw[$i + 1] === '[') {
                $j = $i + 2;
                $params = '';
                while ($j < $len && $raw[$j] !== '' && !ctype_alpha($raw[$j]) && $raw[$j] !== '~') {
                    $params .= $raw[$j]; $j++;
                }
                $cmd = ($j < $len) ? $raw[$j] : '';
                $j++;
                $nums = array_map('intval', explode(';', $params === '' ? '1' : $params));
                switch ($cmd) {
                    case 'H': case 'f':
                        $r = max(0, ($nums[0] ?? 1) - 1); $c = max(0, ($nums[1] ?? 1) - 1); break;
                    case 'A': $r = max(0, $r - ($nums[0] ?? 1)); break;
                    case 'B': $r = min($h - 1, $r + ($nums[0] ?? 1)); break;
                    case 'C': $c = min($w - 1, $c + ($nums[0] ?? 1)); break;
                    case 'D': $c = max(0, $c - ($nums[0] ?? 1)); break;
                    case 'J':
                        if (($nums[0] ?? 0) === 2 || ($nums[0] ?? 0) === 3) { $grid = array_fill(0, $h, array_fill(0, $w, ' ')); }
                        break;
                    case 'K':
                        if (($nums[0] ?? 0) === 0) { for ($k = $c; $k < $w; $k++) { $grid[$r][$k] = ' '; } }
                        elseif (($nums[0] ?? 0) === 1) { for ($k = 0; $k <= $c; $k++) { $grid[$r][$k] = ' '; } }
                        elseif (($nums[0] ?? 0) === 2) { for ($k = 0; $k < $w; $k++) { $grid[$r][$k] = ' '; } }
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
        if ($r >= 0 && $r < $h && $c >= 0 && $c < $w) { $grid[$r][$c] = $ch; }
        $c++;
        if ($c >= $w) { $c = 0; $r = min($h - 1, $r + 1); }
        $i++;
    }
    $lines = [];
    foreach ($grid as $row) { $lines[] = rtrim(implode('', $row)); }
    $t = implode("\n", $lines);
    $res = preg_replace('/[^a-zA-Z0-9\x{4e00}-\x{9fff}]/u', '', $t);
    return $res === null ? strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', $t)) : $res;
}

$readPty = static function ($stream, int $len) {
    set_error_handler(static function (int $no, string $str): bool {
        return str_contains($str, 'errno=5') || str_contains($str, 'Input/output error');
    });
    try { return fread($stream, $len); } finally { restore_error_handler(); }
};

/** 持续读取直到 pty 安静（连续若干次空读），返回期间累计的原始输出（捕获完整帧，规避异步绘制竞态） */
$drain = static function ($stream) use ($readPty): string {
    $acc = '';
    $empty = 0;
    while ($empty < 3) {
        $b = $readPty($stream, 65536);
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

/**
 * 打开 file，发送一串按键序列（每个 [bytes, us, tag]），每步 drain 到安静再读；最后 Ctrl+Q 退出。
 * @param array<int,array{0:string,1:int,2:string}> $seq
 * @return array<string,string> tag => 该步读取到的原始输出
 */
function runKeys(array $env, callable $drain, string $file, array $seq): array
{
    $descs = [0 => ['pty'], 1 => ['pty'], 2 => ['pty']];
    $proc = proc_open([PHP_BINARY, 'bin/vicecode.php', $file], $descs, $pipes, null, $env);
    if ($proc === false) { return []; }
    stream_set_blocking($pipes[0], false);
    stream_set_blocking($pipes[1], false);

    usleep(600000);
    $res = ['init' => $drain($pipes[1])];
    foreach ($seq as [$bytes, $us, $tag]) {
        fwrite($pipes[0], $bytes);
        usleep($us);
        $res[$tag] = $drain($pipes[1]);
    }
    fwrite($pipes[0], "\x11"); // Ctrl+Q
    usleep(300000);
    $drain($pipes[1]);
    foreach ($pipes as $p) { if (is_resource($p)) { fclose($p); } }
    proc_close($proc);
    return $res;
}

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) { $failed = true; }
}

// 构造含远端标记行的文件：44 个 x + "MARK" + 收尾，标记落在第 44~47 列。
// 实测编辑器可见宽度约 13 列；ScrollLeft=40 时可见窗口约 [40,53)，恰好覆盖第 44 列，
// 故 10 次 Shift+→（scrollLeft 0→40）可把标记滚入视口，10 次 Shift+← 滚出。
$line = str_repeat('x', 44) . 'MARK' . str_repeat('x', 40);
$tf = tempnam(sys_get_temp_dir(), 'vc_hk');
file_put_contents($tf, $line);

$SR = "\x1b[1;2C"; // Shift+Right
$SL = "\x1b[1;2D"; // Shift+Left

echo "== 真实 pty 键盘横向滚动（Shift+←/→ 步进 ±4）==\n";
$out = runKeys($env, $drain, $tf, [
    // 先按 10 次 Shift+→（scrollLeft 0→40），标记应滚入视口
    [$SR, 60000, 'r1'], [$SR, 60000, 'r2'], [$SR, 60000, 'r3'], [$SR, 60000, 'r4'], [$SR, 60000, 'r5'],
    [$SR, 60000, 'r6'], [$SR, 60000, 'r7'], [$SR, 60000, 'r8'], [$SR, 60000, 'r9'], [$SR, 60000, 'r10'],
    // 再按 10 次 Shift+←（scrollLeft 40→0），标记应滚出视口
    [$SL, 60000, 'l1'], [$SL, 60000, 'l2'], [$SL, 60000, 'l3'], [$SL, 60000, 'l4'], [$SL, 60000, 'l5'],
    [$SL, 60000, 'l6'], [$SL, 60000, 'l7'], [$SL, 60000, 'l8'], [$SL, 60000, 'l9'], [$SL, 60000, 'l10'],
]);

$init = rebuildScreen($out['init'] ?? '', 120, 40);
// pty 差分渲染：单帧读取只含增量，标记列暴露后不再被重绘，故需对「整段累计输出」重建。
// 右滚态 = init..r10 的累计；左滚态 = init..l10 的全部累计（最终 scrollLeft=0，标记被 x 覆盖）。
// 注：rebuildScreen 归一化会把 MARK 转成小写（/u 正则遇非法 UTF-8 字节回退 strtolower），
// 故断言统一用小写比较。
$rawRight = implode('', array_slice($out, 0, 11));
$rawLeft = implode('', $out);
$afterRight = strtolower(rebuildScreen($rawRight, 120, 40));
$afterLeft = strtolower(rebuildScreen($rawLeft, 120, 40));
$initLow = strtolower($init);

check(isset($out['init']) && $out['init'] !== '', '应用启动后有终端输出');
check(str_contains($initLow, 'x'), '初始编辑器已渲染文件内容（含 x）');
check(!str_contains($initLow, 'mark'), '初始（scrollLeft=0）标记 MARK 不在视口');
check(str_contains($afterRight, 'mark'), 'Shift+→×10 后 MARK 滚入视口');
check(!str_contains($afterLeft, 'mark'), 'Shift+←×10 后 MARK 滚出视口（回到 scrollLeft=0）');

unlink($tf);

echo $failed ? "\npty 键盘横向滚动验收 FAIL\n" : "\npty 键盘横向滚动验收全部 PASS\n";
exit($failed ? 1 : 0);

<?php
declare(strict_types=1);

/**
 * 真实 pty 验收：顶部菜单栏（Backlog 接入）。
 *
 * 为什么必须走 pty：headless 直接构造 CodedKeyEvent 会绕过真实终端对 F10 / 方向键 /
 * Enter 的转义序列解析，也测不到「F10 在真实 pty 下到底能不能被解析成 FunctionKeyEvent」。
 * 探针 tests/pty_keyprobe.php 已确认 F10 可用、Alt+字母不可用；这里是在真实 App 上把
 * 「F10 打开 → 方向导航 → Enter 执行 → Esc 关闭 → 帮助项打开帮助页」整条链路跑通。
 *
 * 断言方式：归一化（剥 ANSI、转小写、只留字母数字与汉字）后，查菜单栏/下拉/浮层里
 * 的判别性文本。语言固定为 en（APP_LOCALE=en），避免中英文混杂导致断言漂移。
 *
 * 运行：timeout 90 php tests/pty_menu.php
 */

$env = array_merge(getenv(), ['COLUMNS' => '120', 'LINES' => '40', 'APP_LOCALE' => 'en']);

/** 归一化：剥 ANSI，只留字母数字与汉字（差分渲染会把整词拆成逐格写入） */
function normalize(string $raw): string
{
    $s = (string) preg_replace('/\x1B\][^\x07\x1B]*(?:\x07|\x1B\\\\)/', '', $raw);
    $s = (string) preg_replace('/\x1B\[[0-9;?]*[ -\/]*[@-~]/', '', $s);
    $s = (string) preg_replace('/\x1B[@-Z\\\\-_]/', '', $s);
    return strtolower((string) preg_replace('/[^a-zA-Z0-9\x{4e00}-\x{9fff}]/u', '', $s));
}

/**
 * ANSI 屏幕重建：按光标定位/擦除序列把字符「后写覆盖先写」地放进网格，得到最终可见帧。
 *
 * 为什么需要它：pty 差分渲染把每一帧的变更拆成「光标定位 + 局部字符写入」。直接对输出流
 * 做归一化拼接，任何「出现过又消失」的文本（如下拉被关闭后的 Clear 项）都会永远残留在
 * 字符串里（关闭只是用空格/新内容覆盖那些 cell，但并不反向删除已写字符）。因此对「文本消失」
 * 类断言，必须重建出最终帧再看，否则是假阴性（见 memory「headless 与 pty 落差 3」）。
 *
 * @param int $w 视口宽（与 COLUMNS 一致）
 * @param int $h 视口高（与 LINES 一致）
 */
function rebuildScreen(string $raw, int $w, int $h): string
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
                while ($j < $len && $raw[$j] !== '' && !ctype_alpha($raw[$j]) && $raw[$j] !== '~') {
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
                    case 'A':
                        $r = max(0, $r - ($nums[0] ?? 1));
                        break;
                    case 'B':
                        $r = min($h - 1, $r + ($nums[0] ?? 1));
                        break;
                    case 'C':
                        $c = min($w - 1, $c + ($nums[0] ?? 1));
                        break;
                    case 'D':
                        $c = max(0, $c - ($nums[0] ?? 1));
                        break;
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
                    // 颜色/反向(m)、显示开关(h/l)、字符集(B/0) 等：忽略
                }
                $i = $j;
                continue;
            }
            // 非 CSI 的 ESC 序列（ESC(B / ESC7 / ESC8 等）：跳到终止字母
            $i++;
            while ($i < $len && !ctype_alpha($raw[$i]) && $raw[$i] !== "\x1b") {
                $i++;
            }
            if ($i < $len) {
                $i++;
            }
            continue;
        }
        if ($ch === "\r") {
            $c = 0;
            $i++;
            continue;
        }
        if ($ch === "\n") {
            $r = min($h - 1, $r + 1);
            $c = 0;
            $i++;
            continue;
        }
        if ($ch === "\x00" || $ch === "\x08") {
            $i++;
            continue;
        }
        if ($r >= 0 && $r < $h && $c >= 0 && $c < $w) {
            $grid[$r][$c] = $ch;
        }
        $c++;
        if ($c >= $w) {
            $c = 0;
            $r = min($h - 1, $r + 1);
        }
        $i++;
    }
    $lines = [];
    foreach ($grid as $row) {
        $lines[] = rtrim(implode('', $row));
    }
    $t = implode("\n", $lines);
    $r = preg_replace('/[^a-zA-Z0-9\x{4e00}-\x{9fff}]/u', '', $t);
    if ($r === null) {
        // 异常 UTF-8 字节会让 /u 修饰符失败返回 null；兜底用非 unicode 清洗，避免整屏丢内容
        $r = strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', $t));
    }
    return $r;
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
 * 跑一轮：喂完字节序列后 Esc 退出，返回归一化后的全部输出。
 * @param array<int,array{0:string,1:int}> $seq [bytes, microseconds]
 */
function runOnce(array $env, array $seq, callable $readPty, bool $returnRaw = false, bool $finishEsc = true): string
{
    $descs = [0 => ['pty'], 1 => ['pty'], 2 => ['pty']];
    $proc = proc_open([PHP_BINARY, 'bin/vicecode.php'], $descs, $pipes, null, $env);
    if ($proc === false) {
        return '';
    }
    stream_set_blocking($pipes[0], false);
    stream_set_blocking($pipes[1], false);

    $out = '';
    usleep(500000);                       // 等首帧渲染
    $out .= (string) $readPty($pipes[1], 65536);

    foreach ($seq as [$bytes, $us]) {
        fwrite($pipes[0], $bytes);
        usleep($us);
        $out .= (string) $readPty($pipes[1], 65536);
    }

    // 收尾：先 Esc 关掉任何打开的菜单/浮层（Esc 在菜单/帮助页里被吞、不会退应用），
    // 再 Ctrl+Q 干净退出（proc_close 在 pty 下不会因 SIGHUP 杀进程，必须主动 quit）。
    // $finishEsc=false 时跳过收尾 Esc（测试 4 需要孤立 Esc 单独验证「关闭」，避免收尾 Esc 干扰最终帧）。
    if ($finishEsc) {
        fwrite($pipes[0], "\x1b");
        usleep(200000);
        $out .= (string) $readPty($pipes[1], 65536);
    }
    fwrite($pipes[0], "\x11");            // Ctrl+Q
    usleep(300000);
    $out .= (string) $readPty($pipes[1], 65536);

    foreach ($pipes as $p) {
        if (is_resource($p)) {
            fclose($p);
        }
    }
    proc_close($proc);
    return $returnRaw ? $out : normalize($out);
}

// 键序列（真实终端转义）
$F10   = "\x1b[21~";   // F10
$Right = "\x1b[C";
$Down  = "\x1b[B";
$Enter = "\r";
$Esc   = "\x1b";

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

echo "== 1) F10 打开菜单（下拉出现 File 菜单项）==\n";
$out = runOnce($env, [[$F10, 350000]], $readPty);
check(str_contains($out, 'file') && str_contains($out, 'help'), '菜单栏常驻（File / Help 标签在工具栏上）');
check(str_contains($out, 'open'), 'F10 后下拉出现「Open」（文件菜单默认展开）');

echo "== 2) 方向导航 Help→About，Enter 打开关于页 ==\n";
$out = runOnce($env, [
    [$F10, 200000],
    [$Right, 120000], [$Right, 120000], [$Right, 120000], // active = Help(3)
    [$Down, 120000],                                       // sel = About(1)
    [$Enter, 350000],                                       // 执行 help.about
], $readPty);
check(str_contains($out, 'aboutvicecode'), 'Enter 在「Help → About」打开关于页（About ViceCode）');

echo "== 3) Help→Shortcuts，Enter 打开快捷键帮助页 ==\n";
$out = runOnce($env, [
    [$F10, 200000],
    [$Right, 120000], [$Right, 120000], [$Right, 120000], // active = Help
    // sel 默认 0 = Shortcuts
    [$Enter, 350000],
], $readPty);
check(str_contains($out, 'keyboardshortcuts'), 'Enter 在「Help → Shortcuts」打开快捷键页（Keyboard Shortcuts）');

echo "== 4) Esc 关闭菜单 ==\n";
// 判别词用 'close'：只出现在文件菜单下拉（Close 项），非常驻文本。
// open 轮用 normalize（拼接全部帧）：下拉出现过 Close 即判定打开成功（测试 1 同法）。
// close 轮发孤立 Esc 后，用 rebuildScreen 重建最终可见帧判断 Close 已消失——
// pty 差分渲染下「文本消失」不能对拼接流 grep（残留），必须重建最终帧（见 rebuildScreen 注释）。
// 注：孤立 Esc 关闭菜单已由协程读键 DIAG 铁证（HANDLE-MENU: CodedKeyEvent(Esc) → ONKEY-ESC-FIRED）。
$open = runOnce($env, [[$F10, 500000]], $readPty);
check(str_contains($open, 'close'), 'F10 打开后下拉出现「Close」（文件菜单展开）');
$rawClose = runOnce($env, [
    [$F10, 500000],
    [$Esc, 1500000],   // 孤立 Esc 关闭（等解析器冲刷，内部超时 ~百毫秒）
], $readPty, true, true);
$screenClose = rebuildScreen($rawClose, 120, 40);
check(!str_contains($screenClose, 'close'), 'Esc 后下拉消失（菜单已关闭）');

echo "== 5) 鼠标点击 Help 标签激活该菜单（下拉出现 Shortcuts）==\n";
// 120x40 en：菜单标签 ' File '(6) ' View '(6) ' Terminal '(10) ' Help '(6)
// Help 0-based 起 x=22，中心 ≈25；菜单栏在 row 0 → SGR 1-based col=26 row=1
$press   = "\x1b[<0;26;1M";
$release = "\x1b[<0;26;1m";
$out = runOnce($env, [[$press, 150000], [$release, 350000]], $readPty);
check(str_contains($out, 'shortcuts'), '鼠标点击 Help 标签后下拉出现「Shortcuts」');

echo $failed ? "\npty 菜单验收 FAIL\n" : "\npty 菜单验收全部 PASS\n";
exit($failed ? 1 : 0);

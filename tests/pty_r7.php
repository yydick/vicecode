<?php
declare(strict_types=1);

/**
 * 真实 pty 验收：R7 配置文件持久化。
 *
 * 验证「真实运行 + 退出」整条链路：设 VICECODE_CONFIG 指向临时文件，启动 vicecode，
 * 用 SGR 拖拽把侧栏拉到 50，Ctrl+Q 退出（触发 start() 的 finally → saveConfig），
 * 然后检查该文件确实被写入且含 layout.sidebarWidth=50。
 *
 * 为什么必须走 pty：headless 已覆盖 ConfigStore/App 的读写逻辑（tests/r7_unit.php），
 * 但「bin/vicecode.php 退出 finally 调用 saveConfig 把偏好落盘」这条真实运行路径
 * 只有真实 pty 才跑得到——不能构造 App 直接调 saveConfig 了事。
 *
 * 运行：timeout 120 php tests/pty_r7.php
 */

$base = is_array(getenv()) ? getenv() : [];
$env = array_merge($base, ['COLUMNS' => '120', 'LINES' => '40', 'APP_LOCALE' => 'en']);

$cfgFile = sys_get_temp_dir() . '/vicecode_r7_pty_' . uniqid('', true) . '.json';
@unlink($cfgFile);
$env['VICECODE_CONFIG'] = $cfgFile;

$readPty = static function ($stream, int $len) {
    set_error_handler(static function (int $no, string $str): bool {
        return str_contains($str, 'errno=5') || str_contains($str, 'Input/output error');
    });
    try { return fread($stream, $len); } finally { restore_error_handler(); }
};

function runDragProc(array $env, callable $readPty, array $seq): void
{
    $descs = [0 => ['pty'], 1 => ['pty'], 2 => ['pty']];
    $proc = proc_open([PHP_BINARY, 'bin/vicecode.php'], $descs, $pipes, null, $env);
    if ($proc === false) { return; }
    stream_set_blocking($pipes[0], false);
    stream_set_blocking($pipes[1], false);
    usleep(500000);
    $readPty($pipes[1], 65536); // 丢弃首帧
    foreach ($seq as [$bytes, $us]) {
        fwrite($pipes[0], $bytes);
        usleep($us);
        $readPty($pipes[1], 65536);
    }
    fwrite($pipes[0], "\x11"); // Ctrl+Q 退出 → finally → saveConfig
    usleep(400000);
    $readPty($pipes[1], 65536);
    foreach ($pipes as $p) { if (is_resource($p)) { fclose($p); } }
    proc_close($proc);
}

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) { $failed = true; }
}

$sideDown = "\x1b[<0;31;11M";   // 侧栏右边界按下
$sideDrag = "\x1b[<32;51;11M";  // 拖到 col51(0based50) → 侧栏宽 50
$sideUp   = "\x1b[<0;51;11m";

echo "== 真实 pty 运行 + 退出落盘（R7）==\n";
runDragProc($env, $readPty, [
    [$sideDown, 200000],
    [$sideDrag, 350000],
    [$sideUp,   200000],
]);

check(is_file($cfgFile), '退出后 ~/.vicerc（临时路径）被生成');
if (is_file($cfgFile)) {
    $data = json_decode((string) file_get_contents($cfgFile), true);
    check(($data['layout']['sidebarWidth'] ?? 0) === 50, "落盘侧栏宽 = 50（实际 " . ($data['layout']['sidebarWidth'] ?? '缺失') . "）");
    check(($data['theme'] ?? '') === 'dark', '落盘主题 = dark（默认，未切换）');
    check(($data['locale'] ?? '') === 'en', '落盘语言 = en（APP_LOCALE）');
}
@unlink($cfgFile);

echo $failed ? "\npty R7 验收 FAIL\n" : "\npty R7 验收全部 PASS\n";
exit($failed ? 1 : 0);

<?php
declare(strict_types=1);

/**
 * M4 真实 pty 驱动（R1 输入 + R2 异步结果）：
 *   切到 SEARCH tab → 输入关键词 → 回车触发 grep → 结果在真实终端里出现。
 *
 * 关键验证点：结果出现，证明 bin/vicecode.php 主循环里 pollSearch() 的返回值
 * 确实被并入了重绘判据（否则 grep 跑完界面不会动，屏幕上看不到任何命中）。
 * R3（回车打开并定位）由 tests/search_unit.php §6 用 App::handle 精确覆盖（含 cursorRow）。
 *
 * 用临时哨兵文件制造一个确定命中；搜索根 = getcwd()（项目根），与 SearchClient 一致。
 *
 * 运行：php tests/pty_search.php
 */

// 固定工作目录到项目根，使子进程的 getcwd() 与我们创建的哨兵文件一致
chdir(__DIR__ . '/..');
$root = getcwd();
$sentinel = $root . '/search_sentinel_zzz.php';
@unlink($sentinel);
file_put_contents($sentinel, "<?php\n// ZZUNIQUEMARKER_LINE\n");

require __DIR__ . '/../vendor/autoload.php';
use App\App;
use PhpTui\Tui\Display\Area;

function normalize(string $raw): string
{
    $s = (string) preg_replace('/\x1B\][^\x07\x1B]*(?:\x07|\x1B\\\\)/', '', $raw);
    $s = (string) preg_replace('/\x1B\[[0-9;?]*[ -\/]*[@-~]/', '', $s);
    $s = (string) preg_replace('/\x1B[@-Z\\\\-_]/', '', $s);
    return strtolower((string) preg_replace('/[^a-zA-Z0-9\x{4e00}-\x{9fff}]/u', '', $s));
}
$readPty = static function ($stream, int $len) {
    set_error_handler(static fn () => true);
    try {
        $str = '';
        $deadline = microtime(true) + 2.0;
        while (microtime(true) < $deadline && ($chunk = fread($stream, $len)) !== '' && $chunk !== false) {
            $str .= $chunk;
            if (str_contains($str, 'errno=5') || str_contains($str, 'Input/output error')) {
                break;
            }
            if (strlen($str) > 60000) {
                break;
            }
        }
        return $str;
    } finally {
        restore_error_handler();
    }
};

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

// 探针：同尺寸布局算 SEARCH tab 点击坐标（0-based → SGR 1-based）
$probe = new App();
$sb = $probe->areas(Area::fromDimensions(120, 40))['sidebar'];
$sbX = $sb->position->x;
$sbY = $sb->position->y;
$innerW = $sb->width - 2;
$seg = max(1, intdiv($innerW, 3));
$tabCol = $sbX + 1 + 2 * $seg + 1;   // 第 3 段（SEARCH tab）中段
$tabRow = $sbY + 1;                  // tab 行（0-based y=1，含上边框偏移）
$tc = $tabCol + 1; $tr = $tabRow + 1; // SGR 1-based

$env = array_merge(getenv(), ['COLUMNS' => '120', 'LINES' => '40']);
$descs = [0 => ['pty'], 1 => ['pty'], 2 => ['pty']];
$proc = proc_open([PHP_BINARY, 'bin/vicecode.php'], $descs, $pipes, null, $env);
if ($proc === false) {
    echo "[FAIL] 无法启动 bin/vicecode.php\n";
    @unlink($sentinel);
    exit(1);
}
stream_set_blocking($pipes[0], false);
stream_set_blocking($pipes[1], false);
usleep(300000);

// 点 SEARCH tab
fwrite($pipes[0], "\x1b[<0;{$tc};{$tr}M");
fwrite($pipes[0], "\x1b[<0;{$tc};{$tr}m");
usleep(500000);
$outTab = normalize($readPty($pipes[1], 8192));
check(str_contains($outTab, 'searchfilecontents') || str_contains($outTab, '搜索文件内容'),
    'SEARCH tab 显示输入占位提示（搜索文件内容）');

// 键入关键词（focus=sidebar 且 SEARCH tab 时字符进 query）
$query = 'ZZUNIQUEMARKER';
fwrite($pipes[0], $query);
usleep(300000);
$outTyped = '';
for ($i = 0; $i < 4; $i++) {
    $outTyped .= normalize($readPty($pipes[1], 16384));
    usleep(150000);
}
check(str_contains($outTyped, 'marker'), '键入进搜索框（捕获关键词 MARKER）');

// 回车触发搜索（R1：Enter 触发）
fwrite($pipes[0], "\r");

// 等 grep 跑完（非阻塞管道 + 主循环 pollSearch 排空）；repo 不大、排除 vendor 后应很快
usleep(1500000);
$outRes = '';
for ($i = 0; $i < 4; $i++) {
    $outRes .= normalize($readPty($pipes[1], 16384));
    usleep(200000);
}
check(str_contains($outRes, 'zzuniquemarker'), 'R2：搜索结果在真实终端中出现（pollSearch 并入重绘生效）');
check(!str_contains($outRes, 'nomatches') && !str_contains($outRes, 'noresults'),
    'R2：有命中时不显示「无匹配结果」');

// 干净退出
fwrite($pipes[0], "\x11");
usleep(300000);
$status = proc_get_status($proc);
$deadline = microtime(true) + 3;
while ($status['running'] && microtime(true) < $deadline) {
    usleep(50000);
    $status = proc_get_status($proc);
}
$code = $status['running'] ? -1 : (int) $status['exitcode'];
proc_close($proc);
check($code === 0, sprintf('干净退出 exit=0（实际 %d）', $code));

@unlink($sentinel);
echo $failed ? "\nM4 pty FAIL\n" : "\nM4 pty PASS\n";
exit($failed ? 1 : 0);

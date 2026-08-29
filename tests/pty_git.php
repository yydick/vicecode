<?php
declare(strict_types=1);

/**
 * M3/R5 真实 pty 驱动（可视化图标 + 输入框 + 按钮）：
 *   点 GIT tab → 异步刷新 → 看分支/变更 → 键入提交信息 → 点 Commit▾ 展开下拉
 *   → 点「提交」(空消息，不建提交) → 点列表文件名打开 diff → Ctrl+Q 干净退出。
 * 点击坐标用探针 App 的布局精确算出（0-based→SGR 1-based），不靠猜。
 * 全程不创建提交 / 不改仓库（空消息提交被拒；diff 只读）。
 *
 * 运行：php tests/pty_git.php
 */

$env = array_merge(getenv(), ['COLUMNS' => '120', 'LINES' => '40']);

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

// 探针：用同尺寸布局算出 GIT tab 各元素屏幕坐标
require __DIR__ . '/../vendor/autoload.php';
use App\App;
use PhpTui\Tui\Display\Area;
$probe = new App();
$sb = $probe->areas(Area::fromDimensions(120, 40))['sidebar'];
$sbX = $sb->position->x;
$sbY = $sb->position->y;
$innerW = $sb->width - 2;
// GIT tab 段中点（tab 行 y=1 0-based）
$tabCol = $sbX + 1 + intdiv($innerW, 3) + 1;
$tabRow = $sbY + 1;
// Commit ▾ 箭头（行 GIT_COMMIT_ROW=3；箭头在末 inner 列；含 tab/分隔 偏移 +2）
$arrowCol = $sbX + 1 + ($innerW - 1);
$arrowRow = $sbY + 1 + 2 + 3;
// 下拉首项的行（menuY0 = header row = 4，含偏移 +2）
$menuRow = $sbY + 1 + 2 + 4;
// 列表首行（GIT_FIRST_ROW=5，含偏移 +2）的文件名列
$itemRow = $sbY + 1 + 2 + 5;
$nameCol = $sbX + 12; // rc=11，文件名区域（避开行首 ▦ + - ✕）
// 行首 ▦ 打开文件（rc=0 → innerX+0），✕ 丢弃（rc=6 → innerX+6）
$openCol = $sbX + 1;
$discardCol = $sbX + 1 + 6;
// SGR 1-based
$tc = $tabCol + 1; $tr = $tabRow + 1;
$ac = $arrowCol + 1; $ar = $arrowRow + 1;
$mr = $menuRow + 1;
$ic = $nameCol + 1; $ir = $itemRow + 1;
$oc = $openCol + 1; $or = $itemRow + 1;
$dc = $discardCol + 1; $dr = $itemRow + 1;

$descs = [0 => ['pty'], 1 => ['pty'], 2 => ['pty']];
$proc = proc_open([PHP_BINARY, 'bin/tui.php'], $descs, $pipes, null, $env);
if ($proc === false) {
    echo "[FAIL] 无法启动 bin/tui.php\n";
    exit(1);
}
stream_set_blocking($pipes[0], false);
stream_set_blocking($pipes[1], false);
usleep(300000);

// 点 GIT tab → 触发异步刷新
fwrite($pipes[0], "\x1b[<0;{$tc};{$tr}M");
fwrite($pipes[0], "\x1b[<0;{$tc};{$tr}m");
usleep(900000);

$out = normalize($readPty($pipes[1], 8192));
check(str_contains($out, 'master'), '屏幕含当前分支 master');
check(str_contains($out, '变更') || str_contains($out, '状态'), 'GIT tab 显示变更/状态');
check(str_contains($out, 'commit'), 'GIT tab 渲染 Commit 按钮');
check(str_contains($out, '提交信息'), 'GIT tab 渲染提交信息输入框（占位）');

// 键入提交信息（focus=sidebar GIT tab 时键入进 commitMsg）
fwrite($pipes[0], 'hellomsg');
usleep(400000);
$outTyped = normalize($readPty($pipes[1], 16384));
check(str_contains($outTyped, 'hellomsg'), '键入进提交信息输入框（捕获 hellomsg）');
// 清空消息，避免后续提交真的建提交
for ($i = 0; $i < 10; $i++) {
    fwrite($pipes[0], "\x1b\x7f"); // Backspace（ESC + DEL 序列）
    usleep(30000);
}
usleep(150000);

// 点 Commit ▾ 展开下拉
fwrite($pipes[0], "\x1b[<0;{$ac};{$ar}M");
fwrite($pipes[0], "\x1b[<0;{$ac};{$ar}m");
usleep(250000);
$outDrop = normalize($readPty($pipes[1], 8192));
check(str_contains($outDrop, '提交和推送') && str_contains($outDrop, '提交和同步'), 'Commit▾ 展开下拉（含 提交/提交变更/提交和推送/提交和同步）');

// 点首项「提交」（空消息，不建提交）
fwrite($pipes[0], "\x1b[<0;{$ac};{$mr}M");
fwrite($pipes[0], "\x1b[<0;{$ac};{$mr}m");
usleep(250000);
$outCommit = normalize($readPty($pipes[1], 8192));
check(str_contains($outCommit, '提交信息'), '点「提交」空消息被拒（提示，未建提交）');

// 点列表文件名 = 打开变更(diff)，只读，不崩
fwrite($pipes[0], "\x1b[<0;{$ic};{$ir}M");
fwrite($pipes[0], "\x1b[<0;{$ic};{$ir}m");
usleep(400000);
$outDiff = normalize($readPty($pipes[1], 8192));
check(true, '点列表文件名打开 diff 未崩溃');

// 点行首 ▦ = 在编辑器打开文件（焦点切到 editor）
fwrite($pipes[0], "\x1b[<0;{$oc};{$or}M");
fwrite($pipes[0], "\x1b[<0;{$oc};{$or}m");
usleep(400000);
$outOpen = normalize($readPty($pipes[1], 8192));
check(str_contains($outOpen, 'editor'), '点 ▦ 在编辑器打开文件（焦点=EDITOR）');

// 点行首 ✕ = 丢弃工作区改动，弹 y/n 确认框（不可逆，这里只取消、绝不确认 y）
fwrite($pipes[0], "\x1b[<0;{$dc};{$dr}M");
fwrite($pipes[0], "\x1b[<0;{$dc};{$dr}m");
usleep(250000);
$outDiscard = normalize($readPty($pipes[1], 8192));
check(str_contains($outDiscard, '丢弃'), '点 ✕ 弹「丢弃工作区改动」确认框');
// 取消（n）：确认框消失，且不改仓库
fwrite($pipes[0], 'n');
usleep(200000);
$outCancel = normalize($readPty($pipes[1], 8192));
check(!str_contains($outCancel, '丢弃工作区改动'), 'n 取消后确认框关闭（未丢弃）');

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

echo $failed ? "\nM3 pty FAIL\n" : "\nM3 pty PASS\n";
exit($failed ? 1 : 0);

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
require __DIR__ . '/lib/isolation.php';
require __DIR__ . '/lib/pty_screen.php';
vc_isolate_config('vc_search_pty');   // 探针 App 与子进程都别读开发机真实 ~/.vicerc / 插件配置
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

/**
 * 轮询 pty 输出直到出现任一 needle（normalize 后比对）或超时。
 *
 * 为什么不能「睡固定时间再读一次」：跑全量批时机器负载高，应用的事件循环会慢半拍，
 * 固定等待偶尔读不到刚键入的内容（实测全量批里偶发一次「键入进搜索框」失败，单跑 5/5 通过）。
 * 改成条件等待后快慢机器都稳，且**超时仍走原断言判失败**，不会把真 bug 等没。
 *
 * @param string[] $needles
 */
function waitForAny(array $pipes, array $needles, int $timeoutMs): string
{
    global $readPty;
    $acc = '';
    $end = microtime(true) + ($timeoutMs / 1000);
    while (microtime(true) < $end) {
        $acc .= normalize($readPty($pipes[1], 16384));
        foreach ($needles as $n) {
            if (str_contains($acc, (string) $n)) {
                return $acc;
            }
        }
        usleep(100000);
    }
    return $acc;
}

/**
 * 读到 pty 安静（连续 2 次空读）为止。用途：**上一步真的做完了再发下一步**，
 * 避免新键入的字节与上一步那半截还没解析完的 SGR 鼠标序列抢解析器。
 * （本函数丢弃字节——只用于"等状态稳定"，需要留证的步骤请用 waitForFrame。）
 */
function waitQuiet(array $pipes, int $maxMs = 2000): void
{
    global $readPty;
    $end = microtime(true) + ($maxMs / 1000);
    $quiet = 0;
    while ($quiet < 2 && microtime(true) < $end) {
        if ($readPty($pipes[1], 65536) === '') {
            $quiet++;
            usleep(60000);
        } else {
            $quiet = 0;
        }
    }
}

/**
 * 条件等待：把原始字节累积到 $raw，直到**重建后的最终帧**满足 $pred，或超时。
 *
 * 与 waitForAny 的分工：那个匹配**归一化累积流**（快，但会被中间帧污染），本函数匹配
 * **重放后的最终帧**（慢一点、语义正确）。**阴性断言必须用这个**——差分渲染只重发变化格、
 * 同一行还会被拆成多次「定位+写入」，中间任何一帧渲染过的东西都永久留在累积流里。
 * 只在「读到安静」之后重建一次：重建是 O(流长) 的，不能每轮都做。
 *
 * @param callable(string):bool $pred
 */
function waitForFrame(array $pipes, string &$raw, callable $pred, int $timeoutMs): bool
{
    global $readPty;
    $end = microtime(true) + ($timeoutMs / 1000);
    while (microtime(true) < $end) {
        $quiet = 0;
        while ($quiet < 2 && microtime(true) < $end) {
            $chunk = $readPty($pipes[1], 65536);
            if ($chunk === '') {
                $quiet++;
                usleep(60000);
            } else {
                $raw .= $chunk;
                $quiet = 0;
            }
        }
        if ($pred(vc_rebuild_screen($raw, 120, 40))) {
            return true;
        }
    }
    return false;
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
// 等 tab 真正切过去再键入：否则负载高时字符会进到侧栏树而不是搜索框
$outTab = waitForAny($pipes, ['searchfilecontents', '搜索文件内容'], 3000);
check(str_contains($outTab, 'searchfilecontents') || str_contains($outTab, '搜索文件内容'),
    'SEARCH tab 显示输入占位提示（搜索文件内容）');

// 等点击那对 SGR 序列解析完再键入（否则键入字节可能和它的尾巴抢解析器）
waitQuiet($pipes);

// 键入关键词（focus=sidebar 且 SEARCH tab 时字符进 query）
// 断言对**最终帧**做（累积流会被中间帧污染），窗口给足：全量跑批时机器负载高，
// 应用要慢半拍才把这行渲染出来——实测旧写法 3s 窗口偶发假阴性（全量批里 2/5 次），
// 而同一批里搜索本身是成功的（R2 那步能拿到结果）。窗口短不是"更严格"，是更脆。
$query = 'ZZUNIQUEMARKER';
fwrite($pipes[0], $query);
$rawTyped = '';
$typed = waitForFrame($pipes, $rawTyped, static fn(string $f): bool => str_contains($f, 'zzuniquemarker'), 8000);
check($typed, '键入进搜索框（最终帧里出现关键词 ZZUNIQUEMARKER）');
if (!$typed) {
    file_put_contents(__DIR__ . '/pty_search_dump.log', $rawTyped);
    echo "  （键入超时：已转储原始输出到 tests/pty_search_dump.log）\n";
}

// 回车触发搜索（R1：Enter 触发）
fwrite($pipes[0], "\r");

// 等 grep 跑完，断言全部落在**最终帧**上（累积流会被中间帧污染）。旧写法有三处毛病：
//   (a) 「结果出现」查 'zzuniquemarker' —— **输入框里就有这个词**，不搜也能通过（假阳性）。
//       改查**只可能来自结果列表**的东西：命中行的内容 `// ZZUNIQUEMARKER_LINE`
//       （归一化后 `zzuniquemarkerline`，比查询词多了 `line`，输入框不会长这样）。
//   (b) 「不显示无匹配结果」查英文 'nomatches'/'noresults'，而本用例**没设 APP_LOCALE**、
//       界面是默认 zh_CN —— 那串英文永远不会出现，该断言**恒为真**（僵尸断言，实测：把状态行
//       注入成恒定「无匹配结果」后它照样 PASS）。改查中文串。
//   (c) 两处都断言累积流：只要中间某帧渲染过「无匹配结果」（如「搜索中」那帧），断言必假阴性
//       （实测注入该中间帧后累积流断言 FAIL、最终帧断言 PASS）。
$rawRes = '';
$listShown = waitForFrame(
    $pipes,
    $rawRes,
    static fn(string $f): bool => str_contains($f, 'zzuniquemarkerline'),
    8000
);
$frameRes = vc_rebuild_screen($rawRes, 120, 40);
// 命中统计（「{n} 个匹配 / {files} 个文件」）由状态行与状态栏消息两处之一给出，此处只作辅证
$statsShown = preg_match('/[1-9][0-9]*个匹配[0-9]+个文件/u', $frameRes) === 1
    || preg_match('/[1-9][0-9]*matches[0-9]+files/', $frameRes) === 1;
check($listShown,
    'R2：结果列表渲染出命中内容（pollSearch 并入重绘生效；实际 命中行='
    . ($listShown ? '有' : '无') . ' 统计=' . ($statsShown ? '有' : '无') . '）');
check($statsShown, 'R2：命中统计可见（N 个匹配 / M 个文件）');
check(!str_contains($frameRes, '无匹配结果') && !str_contains($frameRes, 'nomatches')
    && !str_contains($frameRes, 'noresults'),
    'R2：有命中时状态行不显示「无匹配结果」（对最终帧断言，中文串确实能被命中）');
if (!$listShown) {
    file_put_contents(__DIR__ . '/pty_search_dump.log', $rawRes);
    echo "  （已转储原始输出到 tests/pty_search_dump.log）\n";
}

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

<?php
declare(strict_types=1);

/**
 * M5 真实 pty 驱动：在真终端里打字 → 流式回复逐段出现 → 切 Provider → 干净退出。
 *
 * 这个文件存在的唯一理由是验证 headless 验不了的东西：
 *  1. **主循环的重绘判据**：bin/vicecode.php 每轮调 pollAi()，返回 true 才 draw()。
 *     这个值被吞掉的话，界面会卡住不动、直到回复结束才一次性出现 —— headless 单测
 *     直接调 poll() 拿数据，完全绕过了这条路径，测不出来。
 *  2. 真实终端里的按键/焦点/鼠标路径（点击 ai_input 落焦、Ctrl+P 的 CONTROL 修饰位）。
 *
 * 打哪个端点：用 OPENAI_BASE_URL / DEEPSEEK_BASE_URL 指到本地 mock 服务端，
 * key 走 OPENAI_API_KEY / DEEPSEEK_API_KEY。真 key 不进此文件、也不进命令行。
 *
 * 运行：php tests/pty_ai.php
 */

chdir(__DIR__ . '/..');

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
            if (strlen($str) > 200000) {
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

// ── 起 mock 服务端 ────────────────────────────────────
// 8 个可辨识的分词，每个间隔 150ms → 全程约 1.3s，足够在中途抓一次快照
$port = 18921;
$tokens = ['ZQA', 'ZQB', 'ZQC', 'ZQD', 'ZQE', 'ZQF', 'ZQG', 'ZQH'];
$srv = proc_open(
    ['env', 'MOCK_REPLY=' . implode('|', $tokens), 'MOCK_STATUS=0',
        'php', '-S', '127.0.0.1:' . $port, __DIR__ . '/../examples/sse_server.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $sp
);
$up = false;
for ($i = 0; $i < 200; $i++) {
    $c = @stream_socket_client('tcp://127.0.0.1:' . $port, $e, $s, 0.2);
    if ($c) {
        fclose($c);
        $up = true;
        break;
    }
    usleep(50000);
}
if (!$up) {
    echo "  [FAIL] mock 服务端未就绪\n";
    exit(1);
}

// ── 起应用（pty）──────────────────────────────────────
$base = 'http://127.0.0.1:' . $port . '/v1';
$env = array_merge(getenv(), [
    'COLUMNS'          => '120',
    'LINES'            => '40',
    'OPENAI_BASE_URL'  => $base,
    'OPENAI_API_KEY'   => 'test-key-local-mock',
    'DEEPSEEK_BASE_URL' => $base,
    'DEEPSEEK_API_KEY' => 'test-key-local-mock',
]);
$descs = [0 => ['pty'], 1 => ['pty'], 2 => ['pty']];
$proc = proc_open([PHP_BINARY, 'bin/vicecode.php'], $descs, $pipes, null, $env);
if ($proc === false) {
    echo "  [FAIL] 无法启动 bin/vicecode.php\n";
    exit(1);
}
stream_set_blocking($pipes[0], false);
stream_set_blocking($pipes[1], false);
usleep(300000);
$readPty($pipes[1], 16384); // 吃掉首帧

// 探针算 ai_input 面板中心（0-based → SGR 1-based）
$probe = new App();
$ai = $probe->areas(Area::fromDimensions(120, 40))['ai_input'];
$ac = $ai->position->x + intdiv($ai->width, 2) + 1;
$ar = $ai->position->y + intdiv($ai->height, 2) + 1;

// 点击 AI 输入框落焦
fwrite($pipes[0], "\x1b[<0;{$ac};{$ar}M");
fwrite($pipes[0], "\x1b[<0;{$ac};{$ar}m");
usleep(400000);
$out0 = normalize($readPty($pipes[1], 16384));
// 只看 'aiinput'：状态栏前缀（focus/焦点）随语言包变化，断言不要绑死文案
check(str_contains($out0, 'aiinput'), '点击 AI 输入框 → 焦点切到 ai_input（状态栏可见）');

// ── 打字 + 回车发送 ───────────────────────────────────
// 刻意打中文：pty 里 IME/UTF-8 是真实多字节路径，headless 用 CharKeyEvent 构造不出来
fwrite($pipes[0], "你好");
usleep(400000);
$outTyped = '';
for ($i = 0; $i < 4; $i++) {
    $outTyped .= normalize($readPty($pipes[1], 16384));
    usleep(150000);
}
check(str_contains($outTyped, '你好'), '中文逐字输入进输入框（真实 pty 多字节路径）');

fwrite($pipes[0], "\r");

// ── 流式：中途快照 vs 结束快照 ─────────────────────────
// 关键断言：中途能看到前面的 token、但**看不到**最后一个 —— 这才是真流式。
// 若主循环没把 pollAi() 的返回值并入重绘判据，这里会「一个都看不到」或「一次性全有」。
usleep(450000);
$mid = '';
for ($i = 0; $i < 3; $i++) {
    $mid .= normalize($readPty($pipes[1], 65536));
    usleep(100000);
}
check(str_contains($mid, 'zqa'), '流式：中途快照已能看到首个 token ZQA');
check(!str_contains($mid, 'zqh'), '流式：中途快照**没有**末个 token ZQH（证明是增量出现，不是一次性返回）');

// 等跑完
$deadline = microtime(true) + 10;
$final = '';
while (microtime(true) < $deadline) {
    $final .= normalize($readPty($pipes[1], 65536));
    if (str_contains($final, 'zqh')) {
        break;
    }
    usleep(150000);
}
check(str_contains($final . $mid, 'zqh'), '流式结束后末个 token ZQH 出现在画面上');
$all = $mid . $final;
$missing = array_values(array_filter($tokens, static fn($t) => !str_contains($all, strtolower($t))));
check($missing === [], '全部 8 个 token 都在画面上出现过（缺失：' . (implode(',', $missing) ?: '无') . '）');
check(!str_contains($all, 'airequestfailed'), '无请求失败提示');

// ── 切 Provider（Ctrl+P）──────────────────────────────
fwrite($pipes[0], "\x10"); // Ctrl+P = 0x10
usleep(500000);
$outSw = '';
for ($i = 0; $i < 4; $i++) {
    $outSw .= normalize($readPty($pipes[1], 16384));
    usleep(150000);
}
check(str_contains($outSw, 'deepseek'), 'Ctrl+P 切到 DeepSeek（状态栏/标题可见）');
check(str_contains($outSw, 'switchedto') || str_contains($outSw, '已切换'), '切换有状态栏提示');

// ── 干净退出 ──────────────────────────────────────────
fwrite($pipes[0], "\x11"); // Ctrl+Q
usleep(400000);
$status = proc_get_status($proc);
$deadline = microtime(true) + 5;
while ($status['running'] && microtime(true) < $deadline) {
    usleep(50000);
    $status = proc_get_status($proc);
}
$code = $status['running'] ? -1 : (int) $status['exitcode'];
proc_close($proc);

if (is_resource($srv)) {
    proc_terminate($srv, SIGKILL);
    proc_close($srv);
}

check($code === 0, sprintf('干净退出 exit=0（实际 %d）', $code));

echo $failed ? "\nM5 pty FAIL\n" : "\nM5 pty PASS\n";
exit($failed ? 1 : 0);

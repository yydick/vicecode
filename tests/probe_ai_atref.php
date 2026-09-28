<?php
declare(strict_types=1);

/**
 * 探针：验证 @文件 引用展开（真实 pty + 真 HTTP mock 服务端）。
 *
 * 已有 tests/pty_ai.php 覆盖 M5 主流程（输入/流式/Ctrl+P/退出），但 @文件 展开
 * 这条「把文件内容塞进请求体」的路径没有任何端到端验证。本探针用 MOCK_BODY_FILE
 * 把完整请求体落盘，直接断言「模型真的收到了文件内容」——只看回复文案会被假数据源骗过。
 *
 * 运行：php tests/probe_ai_atref.php   （不参与跑批，仅探索用）
 */

chdir(__DIR__ . '/..');

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/isolation.php';
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
            if (strlen($str) > 200000) {
                break;
            }
        }
        return $str;
    } finally {
        restore_error_handler();
    }
};

$drainPty = static function ($stream, float $idleSec = 0.25) use ($readPty) {
    $s = '';
    $idle = 0.0;
    $start = microtime(true);
    while ($idle < $idleSec && (microtime(true) - $start) < 8.0) {
        $chunk = $readPty($stream, 65536);
        if ($chunk === '' || $chunk === false) {
            usleep(20000);
            $idle += 0.02;
        } else {
            $s .= $chunk;
            $idle = 0.0;
        }
    }
    return $s;
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

// ── mock 服务端（带请求体落盘）───────────────────────────
$port = 18931;
$tokens = ['ATA', 'ATB', 'ATC'];
$bodyFile = tempnam(sys_get_temp_dir(), 'vc_body_');
$srv = proc_open(
    ['env', 'MOCK_REPLY=' . implode('|', $tokens), 'MOCK_STATUS=0', 'MOCK_BODY_FILE=' . $bodyFile,
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

// ── 起应用（pty）────────────────────────────────────────
$base = 'http://127.0.0.1:' . $port . '/v1';
$cfg = vc_isolate_config('vc_atref_pty');
$env = array_merge(getenv(), [
    'COLUMNS'          => '200',
    'LINES'            => '50',
    'VICECODE_CONFIG' => $cfg,
    'OPENAI_BASE_URL'  => $base,
    'OPENAI_API_KEY'   => 'test-key-local-mock',
    'DEEPSEEK_BASE_URL' => $base,
    'DEEPSEEK_API_KEY' => 'test-key-local-mock',
]);

$pluginsDir = __DIR__ . '/../plugins';
$pluginsBackup = $pluginsDir . '.disabled_for_atref';
if (is_dir($pluginsDir)) {
    rename($pluginsDir, $pluginsBackup);
    register_shutdown_function(static function () use ($pluginsDir, $pluginsBackup): void {
        if (is_dir($pluginsBackup)) {
            rename($pluginsBackup, $pluginsDir);
        }
    });
}

$descs = [0 => ['pty'], 1 => ['pty'], 2 => ['pty']];
$proc = proc_open([PHP_BINARY, 'bin/vicecode.php'], $descs, $pipes, null, $env);
if ($proc === false) {
    echo "  [FAIL] 无法启动 bin/vicecode.php\n";
    exit(1);
}
stream_set_blocking($pipes[0], false);
stream_set_blocking($pipes[1], false);
usleep(300000);
$readPty($pipes[1], 16384);

// ai_input 中心坐标
$probe = new App();
$ai = $probe->areas(Area::fromDimensions(200, 50))['ai_input'];
$ac = $ai->position->x + intdiv($ai->width, 2) + 1;
$ar = $ai->position->y + intdiv($ai->height, 2) + 1;

// ── 场景 1：@composer.json 展开 ─────────────────────────
fwrite($pipes[0], "\x1b[<0;{$ac};{$ar}M");
fwrite($pipes[0], "\x1b[<0;{$ac};{$ar}m");
usleep(400000);
$clickRaw = $drainPty($pipes[1]);
check(str_contains(strtolower($clickRaw), '_input'), '点击 AI 输入框 → 焦点切到 ai_input');

// 输入 @引用 并发送
fwrite($pipes[0], '@composer.json');
usleep(300000);
$readPty($pipes[1], 16384);
fwrite($pipes[0], "\r");

// 等流式结束
$deadline = microtime(true) + 10;
$streamed = '';
while (microtime(true) < $deadline) {
    $streamed .= normalize($readPty($pipes[1], 65536));
    if (str_contains($streamed, 'atc')) {
        break;
    }
    usleep(150000);
}
usleep(300000);

// ① 屏幕出现「引用文件」标记（用户所见即所得）
check(str_contains($streamed, '引用文件composerjson') || str_contains($streamed, '引用文件composer'), '屏幕显示「引用文件 composer.json」标记');

// ② 请求体真的带进了文件内容（最强断言：模型收到了）
$body = is_file($bodyFile) ? (string) file_get_contents($bodyFile) : '';
check($body !== '', 'MOCK_BODY_FILE 已写入请求体');
// composer.json 含 "yydick/vicecode"（JSON 转义后字母不变为 vicecode）；若展开失败，
// 请求体里只有 "@composer.json" 文本、无文件内容，就查不到这个强特征。
check(str_contains($body, 'vicecode') || str_contains($body, 'swoole'), '@文件 内容已注入请求体（请求体含 composer.json 内容）');
check(!str_contains($body, 'airequestfailed'), '无请求失败提示');

// ── 场景 2：负路径 —— 绝对路径应被安全拒绝且不崩溃 ───────
if (is_file($bodyFile)) {
    unlink($bodyFile); // 清空，便于只看第二次请求
}
// 回到输入（上一轮已自动回到底部看回复；再点一次确保聚焦）
fwrite($pipes[0], "\x1b[<0;{$ac};{$ar}M");
fwrite($pipes[0], "\x1b[<0;{$ac};{$ar}m");
usleep(200000);
$before = normalize($drainPty($pipes[1]));
fwrite($pipes[0], '@/etc/passwd');
usleep(200000);
$readPty($pipes[1], 16384);
fwrite($pipes[0], "\r");
usleep(800000);
$neg = normalize($readPty($pipes[1], 65536));
// 绝对路径被 resolve() 拒绝 → 状态栏提示「忽略 / 越出项目根 / outside」
$rejected = str_contains($neg, '忽略') || str_contains($neg, 'skipped') || str_contains($neg, 'outside') || str_contains($neg, '越出项目根');
check($rejected, '绝对路径 @引用 被安全拒绝并提示（不静默）');
// 第二次请求体不应含 /etc/passwd 真实内容（证明拒绝生效）
$body2 = is_file($bodyFile) ? (string) file_get_contents($bodyFile) : '';
check(!str_contains($body2, 'root:x:'), '被拒路径未泄露文件内容到请求体');

// ── 干净退出 ────────────────────────────────────────────
fwrite($pipes[0], "\x11"); // Ctrl+Q
usleep(400000);
$status = proc_get_status($proc);
$dline = microtime(true) + 5;
while ($status['running'] && microtime(true) < $dline) {
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

echo $failed ? "\n@文件 探针 FAIL\n" : "\n@文件 探针 PASS\n";
exit($failed ? 1 : 0);

<?php
declare(strict_types=1);

/**
 * AI V2 真实 pty 驱动（三轮应用会话）：
 *  会话1：@文件 引用展开（真实打字路径）→ Agent 工具两轮全程（autoRun）→ 干净退出；
 *  会话2：同一配置目录重启 → 对话从存档恢复（消息流可见上一轮内容）；
 *  会话3：toolAutoRun=false → 工具调用挂起等确认（状态栏提示）→ 按 y 放行 → 工具执行。
 *
 * pty 纪律：禁用 plugins/（clock 数字穿插断言）、条件等待 drainPty、焦点标签用 _input
 * 连续子串、视口 200x50（窄屏焦点段被优先级丢弃）。
 *
 * 运行：php tests/pty_ai_v2.php
 */

chdir(__DIR__ . '/..');

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/isolation.php';

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

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
            if (strlen($str) > 300000) {
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

/** 条件等待：轮询读 pty 直到出现任一目标串（超时返回已读内容，不放水） */
$waitFor = static function ($stream, array $needles, float $timeoutSec = 12.0) use ($readPty): string {
    $s = '';
    $deadline = microtime(true) + $timeoutSec;
    while (microtime(true) < $deadline) {
        foreach ($needles as $n) {
            if (str_contains(normalize($s), normalize($n))) {
                return $s;
            }
        }
        $s .= $readPty($stream, 65536);
        usleep(50000);
    }
    return $s;
};

// ── 临时「项目」目录：应用的 cwd，@引用与工具的 root ──
// 用 vc_tmp_dir（递归清理）：原先手写的 register_shutdown_function 只 rmdir 空的 src/，
// 而 src/ 里有 Foo.php → rmdir 静默失败 → 目录连同存档一起永久留在 /tmp。
$tmpDir = vc_tmp_dir('vice_pty_v2');
@mkdir($tmpDir . '/src', 0777, true);
file_put_contents($tmpDir . '/src/Foo.php', "<?php\necho 'footoken';\n");
file_put_contents($tmpDir . '/sample.txt', 'HELLOCONTEXT');
// 配置与 AI 存档同目录（restore 按 dirname(VICECODE_CONFIG) 找档）
$cfgPath = $tmpDir . '/.vicerc';
file_put_contents($cfgPath, json_encode(['ai' => ['persist' => true]]));
// 插件配置也指进独占目录（走 getenv() 合并进子进程 env）：否则子进程会读开发机
// 真实 ~/.vicecode.plugins.json，断言会随本机配置漂移。
putenv('VICECODE_PLUGINS_CONFIG=' . $tmpDir . '/.vicecode.plugins.json');
putenv('VICECODE_PROVIDERS_CONFIG=' . $tmpDir . '/.vicecode.providers.php');   // 别读开发机的模型配置
// 清理交给 lib/isolation.php 的 VcTemp（递归删，能处理 src/ 里的文件）

// ── mock 服务端：MOCK_TOOLS=1（首轮回 tool_calls，收到 tool 结果后回文本）──
$port = 18961;
$srv = proc_open(
    ['env', 'MOCK_TOOLS=1', 'php', '-S', '127.0.0.1:' . $port, __DIR__ . '/../examples/sse_server.php'],
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

// 禁用插件（clock 数字穿插会污染断言，见 feedback §1.5）
$pluginsDir = __DIR__ . '/../plugins';
$pluginsBackup = $pluginsDir . '.disabled_for_pty_v2';
if (is_dir($pluginsDir)) {
    rename($pluginsDir, $pluginsBackup);
    register_shutdown_function(static function () use ($pluginsDir, $pluginsBackup): void {
        if (is_dir($pluginsBackup)) {
            rename($pluginsBackup, $pluginsDir);
        }
    });
}

$baseEnv = array_merge(getenv(), [
    'COLUMNS'           => '200',
    'LINES'             => '50',
    'OPENAI_BASE_URL'   => 'http://127.0.0.1:' . $port . '/v1',
    'OPENAI_API_KEY'    => 'test-key-local-mock',
    'DEEPSEEK_BASE_URL' => 'http://127.0.0.1:' . $port . '/v1',
    'DEEPSEEK_API_KEY'  => 'test-key-local-mock',
]);

/** 起一轮应用会话，返回 [proc, pipes] */
$launch = static function (array $extraEnv = []) use ($baseEnv, $tmpDir): array {
    $env = array_merge($baseEnv, ['VICECODE_CONFIG' => $tmpDir . '/.vicerc'], $extraEnv);
    $descs = [0 => ['pty'], 1 => ['pty'], 2 => ['pty']];
    $proc = proc_open([PHP_BINARY, __DIR__ . '/../bin/vicecode.php'], $descs, $pipes, $tmpDir, $env);
    if ($proc === false) {
        echo "  [FAIL] 无法启动 bin/vicecode.php\n";
        exit(1);
    }
    stream_set_blocking($pipes[0], false);
    stream_set_blocking($pipes[1], false);
    usleep(400000);
    return [$proc, $pipes];
};

/** 干净退出（Ctrl+Q + 等待退出码） */
$quit = static function ($proc, $pipes) use ($readPty): int {
    fwrite($pipes[0], "\x11"); // Ctrl+Q
    usleep(400000);
    $readPty($pipes[1], 16384);
    $status = proc_get_status($proc);
    $deadline = microtime(true) + 5;
    while ($status['running'] && microtime(true) < $deadline) {
        usleep(50000);
        $status = proc_get_status($proc);
    }
    $code = $status['running'] ? -1 : (int) $status['exitcode'];
    proc_close($proc);
    return $code;
};

/** 点击 AI 输入框落焦（探针动态算坐标） */
$focusAiInput = static function ($proc, $pipes) use ($drainPty): string {
    $probe = new App\App();
    $ai = $probe->areas(PhpTui\Tui\Display\Area::fromDimensions(200, 50))['ai_input'];
    $ac = $ai->position->x + intdiv($ai->width, 2) + 1;
    $ar = $ai->position->y + intdiv($ai->height, 2) + 1;
    fwrite($pipes[0], "\x1b[<0;{$ac};{$ar}M");
    fwrite($pipes[0], "\x1b[<0;{$ac};{$ar}m");
    usleep(400000);
    return $drainPty($pipes[1]);
};

// ═══════════ 会话 1：@展开 + 工具两轮（autoRun）═══════════
echo "== 会话1：@文件 引用 + Agent 工具（真实 pty）==\n";
[$proc, $pipes] = $launch();
$clickRaw = $focusAiInput($proc, $pipes);
check(str_contains(strtolower($clickRaw), '_input'), '会话1：点击 AI 输入框落焦');

// @文件 引用：真实打字 → 发送 → 展开后的围栏块内容出现在消息流
fwrite($pipes[0], '看看 @sample.txt');
usleep(300000);
fwrite($pipes[0], "\r");
$got = $waitFor($pipes[1], ['hellocontext'], 12);
check(str_contains(normalize($got), 'hellocontext'), '@sample.txt 展开的文件内容出现在消息流（真实打字路径）');

// Agent 工具：MOCK_TOOLS=1 → 第一轮 read_file/list_files（autoRun）→ 第二轮回文本
fwrite($pipes[0], '跑个工具试试');
usleep(300000);
fwrite($pipes[0], "\r");
$got = $waitFor($pipes[1], ['tok1'], 20);
$all = normalize($got);
check(str_contains($all, 'readfilepathsrcfoophp'), '流内可见 assistant 的调用行（→ read_file(path=src/Foo.php)）');
check(str_contains($all, 'readfilesrcfoophp'), '流内可见工具结果摘要行（⚙ read_file(src/Foo.php) ✓）——结果只渲染摘要不倾倒内容');
check(str_contains($all, 'listfiles'), '两个工具调用都展示（list_files）');
check(str_contains($all, 'tok1'), '第二轮模型收到工具结果后回了最终文本（mock 只在收到 role:tool 后才回文本=工具确实执行）');
check(!str_contains($all, '请求失败') || str_contains($all, 'tok1'), '无请求失败提示');

$code = $quit($proc, $pipes);
check($code === 0, '会话1 干净退出 exit=0（实际 ' . $code . '）');

// ═══════════ 会话 2：重启恢复 ═══════════
echo "\n== 会话2：重启恢复对话 ==\n";
[$proc, $pipes] = $launch();
$boot = '';
$deadline = microtime(true) + 8;
while (microtime(true) < $deadline) {
    $boot .= $readPty($pipes[1], 65536);
    if (str_contains(normalize($boot), 'hellocontext')) {
        break;
    }
    usleep(80000);
}
$bootN = normalize($boot);
check(str_contains($bootN, 'hellocontext'), '重启后 @引用的文件内容从存档恢复（消息流可见）');
check(str_contains($bootN, 'readfile'), '重启后工具调用摘要行从存档恢复（⚙ read_file…）');
$code = $quit($proc, $pipes);
check($code === 0, '会话2 干净退出 exit=0（实际 ' . $code . '）');

// ═══════════ 会话 3：逐次确认模式 y 放行 ═══════════
echo "\n== 会话3：逐次确认模式（toolAutoRun=false）==\n";
file_put_contents($cfgPath, json_encode(['ai' => ['persist' => false, 'toolAutoRun' => false]]));
[$proc, $pipes] = $launch();
$focusAiInput($proc, $pipes);
fwrite($pipes[0], '要工具');
usleep(300000);
fwrite($pipes[0], "\r");
$got = $waitFor($pipes[1], ['y允许'], 15);
$preN = normalize($got);
check(str_contains($preN, 'y允许'), '工具调用挂起，状态栏提示 y 允许 / n 拒绝');
check(str_contains($preN, 'readfilepathsrcfoophp'), '挂起时调用行已展示（→ read_file(path=…)）');
check(!str_contains($preN, 'readfilesrcfoophp'), '放行前工具未执行（无结果摘要行 ⚙ … ✓）');
fwrite($pipes[0], 'y');
$got = $waitFor($pipes[1], ['readfilesrcfoophp'], 15);
check(str_contains(normalize($got), 'readfilesrcfoophp'), '按 y 后工具真实执行（结果摘要行 ⚙ … ✓ 出现）');
$got .= $waitFor($pipes[1], ['tok1'], 15);
check(str_contains(normalize($got), 'tok1'), '放行后第二轮模型回复照常出现');
$code = $quit($proc, $pipes);
check($code === 0, '会话3 干净退出 exit=0（实际 ' . $code . '）');

if (is_resource($srv)) {
    proc_terminate($srv, SIGKILL);
    proc_close($srv);
}

echo $failed ? "\nAI V2 pty FAIL\n" : "\nAI V2 pty PASS\n";
exit($failed ? 1 : 0);

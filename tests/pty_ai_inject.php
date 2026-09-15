<?php
declare(strict_types=1);

/**
 * AI 面板「不可信内容」真实 pty 端到端验收（V2.1）。
 *
 * 为什么必须走 pty：headless 只能证明「span 里没有 ESC」；**终端转义注入**的最终证明是
 * 「终端到底收到了什么字节」。本地 mock 服务端（examples/sse_server.php，MOCK_REPLY）
 * 让模型回复里带上：
 *   - `ESC ] 52 ; c ; … BEL`（OSC 52 写系统剪贴板——应用自己只在复制时才发这条）；
 *   - 制表符 `\t`（终端按 8 列制表位展开，与 dispWidth 的 1 列算法不一致 → 行错位）。
 * 然后断言**累计 pty 字节流**里既没有带 ESC 的 OSC 载荷、也没有裸 TAB——而正文标记仍在
 * （证明回复确实渲染了，不是"什么都没显示所以查不到"）。
 *
 * 反向验证：把 DisplayWidth::sanitizeContent() 改成恒等函数后本测试必须 FAIL
 * （即那两段异常字节会原样出现在终端流里）。
 *
 * 运行：php tests/pty_ai_inject.php
 */

chdir(__DIR__ . '/..');

require __DIR__ . '/../vendor/autoload.php';

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

// 注入载荷（base64 是本测试专用串，应用自身绝不会发）
$oscPayload = "\x1b]52;c;cC1pbmplY3RlZA==\x07";     // ESC ] 52 ; c ; … BEL：写系统剪贴板
$tabPayload = "\t列A";
$oscText = ']52;c;cC1pbmplY3RlZA==';                // 剥掉 ESC/BEL 后剩下的**惰性**可见文本（本测试用作"回复确实渲染了"的旁证）

$port = 18971;
$tmpDir = sys_get_temp_dir() . '/vc_aiinj_' . getmypid();
@mkdir($tmpDir, 0777, true);
file_put_contents($tmpDir . '/.vicerc', json_encode(['ai' => ['persist' => false]]));
register_shutdown_function(static function () use ($tmpDir): void {
    @unlink($tmpDir . '/.vicerc');
    @unlink($tmpDir . '/.vicecode_ai');
    @rmdir($tmpDir);
});

// 回复分词用 '|' 分隔（服务端约定）：把载荷单独成 token，覆盖"跨 delta 分片"也无妨
$reply = '注入前MARKER|' . $oscPayload . '|' . $tabPayload . '|尾MARKER完';
$srv = proc_open(
    ['env', 'MOCK_REPLY=' . $reply, 'php', '-S', '127.0.0.1:' . $port, __DIR__ . '/../examples/sse_server.php'],
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
register_shutdown_function(static function () use ($srv): void {
    if (is_resource($srv)) {
        proc_terminate($srv);
    }
});

// 禁用插件：clock 每秒重绘会把数字穿插进断言（feedback §1.5）
$pluginsDir = __DIR__ . '/../plugins';
$pluginsBackup = $pluginsDir . '.disabled_for_pty_inj';
if (is_dir($pluginsDir)) {
    rename($pluginsDir, $pluginsBackup);
    register_shutdown_function(static function () use ($pluginsDir, $pluginsBackup): void {
        if (is_dir($pluginsBackup)) {
            rename($pluginsBackup, $pluginsDir);
        }
    });
}

$env = array_merge(getenv(), [
    'COLUMNS'           => '200',
    'LINES'             => '50',
    'APP_LOCALE'        => 'zh_CN',
    'VICECODE_CONFIG'   => $tmpDir . '/.vicerc',
    'OPENAI_BASE_URL'   => 'http://127.0.0.1:' . $port . '/v1',
    'OPENAI_API_KEY'    => 'test-key-local-mock',
    'DEEPSEEK_BASE_URL' => 'http://127.0.0.1:' . $port . '/v1',
    'DEEPSEEK_API_KEY'  => 'test-key-local-mock',
]);

$readPty = static function ($stream, int $len) {
    set_error_handler(static fn () => true);
    try {
        return fread($stream, $len);
    } finally {
        restore_error_handler();
    }
};

$descs = [0 => ['pty'], 1 => ['pty'], 2 => ['pty']];
$proc = proc_open([PHP_BINARY, __DIR__ . '/../bin/vicecode.php'], $descs, $pipes, $tmpDir, $env);
if ($proc === false) {
    echo "  [FAIL] 无法启动 bin/vicecode.php\n";
    exit(1);
}
stream_set_blocking($pipes[0], false);
stream_set_blocking($pipes[1], false);

$raw = '';
$pump = static function () use ($pipes, $readPty, &$raw): void {
    $b = $readPty($pipes[1], 65536);
    if (is_string($b) && $b !== '') {
        $raw .= $b;
    }
    usleep(20000);
};

usleep(400000);
for ($i = 0; $i < 60; $i++) {
    $pump();                                   // 收首帧
}

// 先点击 AI 输入框落焦（默认焦点不在它上面），坐标用探针动态算，不写死
$probe = new App\App();
$aiArea = $probe->areas(PhpTui\Tui\Display\Area::fromDimensions(200, 50))['ai_input'];
$ac = $aiArea->position->x + intdiv($aiArea->width, 2) + 1;
$ar = $aiArea->position->y + intdiv($aiArea->height, 2) + 1;
fwrite($pipes[0], "\x1b[<0;{$ac};{$ar}M");
fwrite($pipes[0], "\x1b[<0;{$ac};{$ar}m");
usleep(400000);
for ($i = 0; $i < 10; $i++) {
    $pump();
}

// 打字 + 回车
fwrite($pipes[0], '注入测试');
usleep(300000);
fwrite($pipes[0], "\r");

// 条件等待回复渲染出来（超时仍继续，交由断言判失败，不放水）
$deadline = microtime(true) + 20.0;
while (microtime(true) < $deadline) {
    $pump();
    if (str_contains($raw, 'MARKER')) {
        break;
    }
}
for ($i = 0; $i < 20; $i++) {
    $pump();
}

fwrite($pipes[0], "\x11");                     // Ctrl+Q
$guard = 0;
while (proc_get_status($proc)['running'] && $guard < 25) {
    usleep(200000);
    fwrite($pipes[0], "\x11");
    $guard++;
}
for ($i = 0; $i < 10; $i++) {
    $pump();
}
foreach ($pipes as $p) {
    if (is_resource($p)) {
        fclose($p);
    }
}
$code = proc_close($proc);

echo "== 真实 pty：不可信内容不得变成终端控制序列 ==\n";
check(str_contains($raw, 'MARKER'), '模型回复确实渲染到终端（累计流含正文标记 MARKER）');
check(!str_contains($raw, $oscPayload), 'OSC 52 载荷（ESC 前缀版）没有出现在终端流里——注入被拦下');
check(!str_contains($raw, "\t"), '终端流里没有任何裸 TAB（制表符已展开为空格，不再与 dispWidth 打架）');
check(str_contains($raw, $oscText) || str_contains($raw, '列A'),
    '被剥掉 ESC/BEL 后的残余文本仍按普通文字显示（内容不丢，只是失去控制能力）');
check($code === 0, 'Ctrl+Q 退出码为 0（实际 ' . $code . '）');
check(!str_contains($raw, 'Fatal') && !str_contains($raw, 'Uncaught'), '整轮输出无 Fatal / Uncaught');

echo $failed ? "\npty AI 注入验收 FAIL\n" : "\npty AI 注入验收全部 PASS\n";
exit($failed ? 1 : 0);

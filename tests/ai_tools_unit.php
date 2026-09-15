<?php
declare(strict_types=1);

/**
 * AI V2 Agent loop —— 无终端单测：
 *  1) AiTools 路径安全（realpath 归一、../ 逃逸、绝对路径、symlink 出根、二进制、截断）；
 *  2) list_files / read_file 行为与错误文本；
 *  3) ChatModel Agent loop 端到端（mock 服务端两轮 tool_calls → 工具执行 → 续跑）；
 *  4) maxSteps 上限（MOCK_TOOLS=2 永远要工具）；
 *  5) 逐次确认模式 approve/deny。
 *
 * 运行：php tests/ai_tools_unit.php
 */

require __DIR__ . '/../vendor/autoload.php';
putenv('APP_LOCALE=zh_CN');

use App\Ai\AiTools;
use App\Ai\ChatModel;
use App\Ai\ProviderRegistry;
use App\App;

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

/** 轮询直到流式结束 */
function drainChat(ChatModel $c, float $timeout = 25.0): void
{
    $dl = microtime(true) + $timeout;
    while ($c->isStreaming() && microtime(true) < $dl) {
        $c->poll();
        usleep(20000);
    }
}

/** 起 mock 服务端（env 前缀注入场景开关） */
function mockServer(int $port, array $env = []): mixed
{
    $cmd = ['php', '-S', '127.0.0.1:' . $port, dirname(__DIR__) . '/examples/sse_server.php'];
    if ($env !== []) {
        $prefix = [];
        foreach ($env as $k => $v) {
            $prefix[] = $k . '=' . $v;
        }
        $cmd = array_merge(['env'], $prefix, $cmd);
    }
    $p = proc_open($cmd, [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', '/dev/null', 'w'],
        2 => ['file', '/dev/null', 'w'],
    ], $pipes);
    for ($i = 0; $i < 200; $i++) {
        $c = @stream_socket_client('tcp://127.0.0.1:' . $port, $e, $s, 0.2);
        if ($c) {
            fclose($c);
            return $p;
        }
        usleep(50000);
    }
    return $p;
}

function stopServer(mixed $p): void
{
    if (is_resource($p)) {
        proc_terminate($p, SIGKILL);
        proc_close($p);
    }
}

function mockRegistry(int $port): ProviderRegistry
{
    putenv('MOCK_KEY=sk-test-local');
    return new ProviderRegistry([
        'mock' => [
            'label'    => 'Mock',
            'key_env'  => 'MOCK_KEY',
            'base_url' => 'http://127.0.0.1:' . $port . '/v1',
            'models'   => ['mock-1'],
            'model'    => 'mock-1',
        ],
    ]);
}

// 测试配置隔离：不碰真实 ~/.vicerc
$tmpCfg = tempnam(sys_get_temp_dir(), 'vice_ai_tools_');
putenv('VICECODE_CONFIG=' . $tmpCfg);

// ─────────────── 1) AiTools 路径安全 ───────────────
echo "== AiTools 路径安全 ==\n";

$root = (string) tempnam(sys_get_temp_dir(), 'vice_root_');
@unlink($root);
@mkdir($root . '/src', 0777, true);
file_put_contents($root . '/src/Foo.php', "<?php\necho 'foo';\n");
file_put_contents($root . '/src/Bar.php', "bar\n");
file_put_contents($root . '/README.md', "# hello\n");
file_put_contents($root . '/binary.bin', "a\0b");
// 出根 symlink：指向项目外
$outside = (string) tempnam(sys_get_temp_dir(), 'vice_outside_');
file_put_contents($outside, "secret");
@symlink($outside, $root . '/evil_link');

$t = new AiTools($root);

check($t->resolve('src/Foo.php') === $root . '/src/Foo.php', '相对路径解析到项目内绝对路径');
check($t->resolve('.') === $root, '“.”解析为项目根');
check($t->resolve('') === null, '空路径拒绝');
check($t->resolve('/etc/passwd') === null, '绝对路径拒绝');
check($t->resolve('C:/Windows/x') === null, 'Windows 盘符路径拒绝');
check($t->resolve('../../etc/passwd') === null, '“../”逃逸拒绝');
check($t->resolve('src/../../../../etc/passwd') === null, '深层 ../ 逃逸拒绝');
check($t->resolve('evil_link') === null, 'symlink 指向根外 → 拒绝（realpath 前缀校验）');
check($t->resolve('not_exist.php') === null, '不存在的路径返回 null');
check($t->resolve("a\0b") === null, '含 NUL 字节的路径拒绝');

// ─────────────── 2) list_files / read_file ───────────────
echo "\n== list_files / read_file ==\n";

$r = $t->listFiles('.');
check($r['ok'], 'list_files 根目录成功');
check(str_contains($r['text'], 'src/') && str_contains($r['text'], 'README.md'), '目录带 / 后缀、文件原名列出（实际：' . str_replace("\n", ' | ', $r['text']) . '）');
$r = $t->listFiles('src');
check($r['ok'] && str_contains($r['text'], 'Foo.php') && str_contains($r['text'], 'Bar.php'), 'list_files 子目录');
$r = $t->listFiles('../outside');
check(!$r['ok'] && str_starts_with($r['text'], 'ERROR:'), 'list_files 越界 → 错误文本（不抛异常）');

$r = $t->readFile('src/Foo.php');
check($r['ok'] && str_contains($r['text'], "echo 'foo';"), 'read_file 读到内容（带 FILE 前缀）');
$r = $t->readFile('binary.bin');
check(!$r['ok'] && str_contains($r['text'], 'binary'), '二进制文件（含 NUL）拒读');
$r = $t->readFile('not_exist');
check(!$r['ok'], 'read_file 不存在 → 错误文本');
// 截断：limit=10 → 内容截到 10 字节并标注
$r = $t->readFile('src/Foo.php', 10);
check($r['ok'] && str_contains($r['text'], 'truncated at 10 bytes'), '超 attach 上限截断并标注（实际：' . json_encode($r['text']) . '）');

// execute() 分发
$r = $t->execute('read_file', '{"path":"src/Bar.php"}');
check($r['ok'] && str_contains($r['text'], 'bar'), 'execute(read_file) 分发正确');
$r = $t->execute('list_files', '{"path":"src"}');
check($r['ok'] && str_contains($r['text'], 'Foo.php'), 'execute(list_files) 分发正确');
$r = $t->execute('read_file', 'not json');
check(!$r['ok'] && str_contains($r['text'], 'path'), 'arguments 非法 JSON → 错误文本');
$r = $t->execute('rm_rf', '{"path":"."}');
check(!$r['ok'] && str_contains($r['text'], "unknown tool"), '未知工具拒绝（白名单之外一律 ERROR）');

// ─────────────── 3) ChatModel Agent loop 端到端 ───────────────
echo "\n== Agent loop 端到端（autoRun，MOCK_TOOLS=1）==\n";

$port1 = 18921;
$srv1 = mockServer($port1, ['MOCK_TOOLS' => '1']);
$app1 = new App();
$chat1 = new ChatModel($app1, mockRegistry($port1));
$chat1->useProvider('mock');
$chat1->setToolsRoot($root);
check($chat1->toolAutoRun() === true, 'toolAutoRun 默认开（vicerc 未配置走默认）');

$chat1->send('看看 src');
drainChat($chat1);
$msgs = $chat1->messages();
$roles = array_map(static fn($m) => $m['role'], $msgs);
check(!$chat1->isStreaming() && $chat1->error() === null, '两轮跑完无错误（实际：' . var_export($chat1->error(), true) . '）');
check($roles === ['user', 'assistant', 'tool', 'tool', 'assistant'],
    '消息形状 user → assistant(tool_calls) → tool×2 → assistant（实际：' . implode('→', $roles) . '）');
check(isset($msgs[1]['tool_calls']) && count($msgs[1]['tool_calls']) === 2, 'assistant 消息带 2 个 tool_calls（wire 形状）');
check(($msgs[1]['tool_calls'][0]['function']['name'] ?? null) === 'read_file'
    && ($msgs[1]['tool_calls'][0]['function']['arguments'] ?? null) === '{"path":"src/Foo.php"}',
    '第一个工具分片累积成完整 arguments（实际：' . var_export($msgs[1]['tool_calls'][0]['function']['arguments'] ?? null, true) . '）');
check(($msgs[1]['tool_calls'][1]['function']['name'] ?? null) === 'list_files', '第二个工具（交错流式）归并正确');
check(($msgs[2]['tool_call_id'] ?? null) === 'call_mock_1', 'tool 结果引用正确的 tool_call_id');
check(str_contains((string) ($msgs[2]['content'] ?? ''), "echo 'foo';"), 'read_file 结果是真实文件内容（root 已注入 tmp 目录）');
check(($msgs[2]['meta']['kind'] ?? null) === 'tool_result' && str_contains((string) ($msgs[2]['meta']['display'] ?? ''), 'read_file(src/Foo.php)'),
    'tool 结果带 meta.display 摘要（实际：' . var_export($msgs[2]['meta']['display'] ?? null, true) . '）');
check(($msgs[3]['meta']['ok'] ?? true) !== false && str_contains((string) ($msgs[3]['content'] ?? ''), 'Foo.php'), 'list_files(src) 结果包含 Foo.php');
check(str_contains((string) ($msgs[4]['content'] ?? ''), 'tok1'), '第二轮模型收到工具结果后回了最终文本');
check($chat1->steps() === 1, 'steps 记了一轮工具执行（实际：' . $chat1->steps() . '）');
stopServer($srv1);

// ─────────────── 4) maxSteps 上限 ───────────────
echo "\n== maxSteps 上限（MOCK_TOOLS=2 永远要工具）==\n";

file_put_contents($tmpCfg, json_encode(['ai' => ['maxSteps' => 2]]));
$port2 = 18922;
$srv2 = mockServer($port2, ['MOCK_TOOLS' => '2']);
$app2 = new App();
$chat2 = new ChatModel($app2, mockRegistry($port2));
$chat2->useProvider('mock');
$chat2->setToolsRoot($root);
$chat2->send('循环要工具');
$deadline = microtime(true) + 40;
while (($chat2->isStreaming() || $chat2->steps() < 2) && microtime(true) < $deadline) {
    $chat2->poll();
    usleep(20000);
}
drainChat($chat2);
$roles2 = array_map(static fn($m) => $m['role'], $chat2->messages());
check($chat2->steps() === 2, '步数封顶在 2（实际：' . $chat2->steps() . '）');
check(str_contains((string) $app2->message, '上限'), '状态栏提示已达上限（实际：' . var_export($app2->message, true) . '）');
$toolPairsOk = true;
foreach ($roles2 as $i => $role) {
    if ($role === 'tool') {
        // 每个 tool 消息前面必须能找到带 tool_calls 的 assistant（成对，不悬空）
        $paired = false;
        for ($j = $i - 1; $j >= 0; $j--) {
            if ($roles2[$j] === 'assistant' && isset($chat2->messages()[$j]['tool_calls'])) {
                $paired = true;
                break;
            }
        }
        if (!$paired) {
            $toolPairsOk = false;
        }
    }
}
check($toolPairsOk, '每个 role:tool 都有前置 assistant(tool_calls) 成对（不悬空，防端点 400）');
$last = $chat2->messages()[count($chat2->messages()) - 1];
check(($last['role'] ?? null) === 'tool' && str_contains((string) ($last['content'] ?? ''), '上限'),
    '末条是「未执行：已达上限」的 tool 结果（实际：' . var_export($last['content'] ?? null, true) . '）');
stopServer($srv2);

// ─────────────── 5) 逐次确认 approve / deny ───────────────
echo "\n== 逐次确认模式（toolAutoRun=false）==\n";

file_put_contents($tmpCfg, json_encode(['ai' => ['toolAutoRun' => false]]));
$port3 = 18923;
$srv3 = mockServer($port3, ['MOCK_TOOLS' => '1']);
$app3 = new App();
$chat3 = new ChatModel($app3, mockRegistry($port3));
$chat3->useProvider('mock');
$chat3->setToolsRoot($root);
check($chat3->toolAutoRun() === false, 'vicerc ai.toolAutoRun=false 生效');

$chat3->send('要工具');
$deadline = microtime(true) + 15;
while ($chat3->isStreaming() && microtime(true) < $deadline) {
    $chat3->poll();
    usleep(20000);
}
check(!$chat3->isStreaming() && $chat3->hasPendingApproval(), '第一轮 tool_calls 后挂起等确认（流式已停、不越权执行）');
$nBefore = count($chat3->messages());
check($nBefore === 2, '挂起时没有提前执行工具（消息仍是 user+assistant）');

$chat3->deny();
drainChat($chat3);
$msgs3 = $chat3->messages();
check(!$chat3->hasPendingApproval(), 'deny() 后确认态清除');
$denied = null;
foreach ($msgs3 as $m) {
    if (($m['role'] ?? null) === 'tool') {
        $denied = $m;
        break;
    }
}
check($denied !== null && str_contains((string) ($denied['content'] ?? ''), '拒绝'), 'deny() 以「用户拒绝」结果入列并续跑');
check(str_contains((string) (end($msgs3)['content'] ?? ''), 'tok1'), '拒绝后模型照样收到结果消息并回最终文本（不卡死）');

// approve 路径
$app4 = new App();
$chat4 = new ChatModel($app4, mockRegistry($port3));
$chat4->useProvider('mock');
$chat4->setToolsRoot($root);
$chat4->send('再看');
$deadline = microtime(true) + 15;
while ($chat4->isStreaming() && microtime(true) < $deadline) {
    $chat4->poll();
    usleep(20000);
}
check($chat4->hasPendingApproval(), 'approve 前置：再次挂起');
$chat4->approve();
drainChat($chat4);
$msgs4 = $chat4->messages();
$toolMsg = null;
foreach ($msgs4 as $m) {
    if (($m['role'] ?? null) === 'tool') {
        $toolMsg = $m;
        break;
    }
}
check($toolMsg !== null && str_contains((string) ($toolMsg['content'] ?? ''), "echo 'foo';"),
    'approve() 真实执行了工具并续跑');
check(!$chat4->hasPendingApproval() && !$chat4->isStreaming(), 'approve 后全程收敛');

stopServer($srv3);

// ─────────────── 6) 渲染：工具过程流内展示 ───────────────
echo "\n== 工具过程流内展示 ==\n";

use PhpTui\Tui\Display\Area as TuiArea;
use PhpTui\Tui\Display\Buffer as TuiBuffer;
use PhpTui\Tui\Extension\Core\CoreExtension;
use PhpTui\Tui\Widget\WidgetRenderer\AggregateWidgetRenderer;
use PhpTui\Tui\Widget\Margin;
use PhpTui\Term\Event\MouseEvent;
use PhpTui\Term\MouseEventKind;
use PhpTui\Term\MouseButton;

$ext = new CoreExtension();
$rlist = [];
foreach ($ext->widgetRenderers() as $r) {
    $rlist[] = $r;
}
$renderer = new AggregateWidgetRenderer($rlist);

/** 渲染一帧，返回 [整屏文本, 逐行数组] */
function renderFrame(AggregateWidgetRenderer $renderer, App $app, int $w, int $h): array
{
    $vp = TuiArea::fromDimensions($w, $h);
    $buf = TuiBuffer::empty($vp);
    $renderer->render($renderer, $app->render($vp), $buf, $buf->area());
    $lines = $buf->toLines();
    return [implode("\n", $lines), $lines];
}

$appR = new App();
$prop = new ReflectionProperty($appR->chat, 'messages');
$prop->setAccessible(true);
$prop->setValue($appR->chat, [
    ['role' => 'user', 'content' => '看看 src'],
    ['role' => 'assistant', 'content' => '', 'tool_calls' => [
        ['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'read_file', 'arguments' => '{"path":"src/Foo.php"}']],
        ['id' => 'c2', 'type' => 'function', 'function' => ['name' => 'list_files', 'arguments' => '{"path":"src"}']],
    ]],
    ['role' => 'tool', 'tool_call_id' => 'c1', 'content' => "FILE src/Foo.php:\n<?php\n", 'meta' => ['kind' => 'tool_result', 'display' => 'read_file(src/Foo.php) ✓']],
    ['role' => 'tool', 'tool_call_id' => 'c2', 'content' => "FILES src:\nFoo.php\nBar.php", 'meta' => ['kind' => 'tool_result', 'display' => 'list_files(src) ✓']],
    ['role' => 'user', 'content' => '[历史摘要] 用户想了解 src', 'meta' => ['kind' => 'summary']],
    ['role' => 'assistant', 'content' => '完成'],
]);

[$txt, $lines] = renderFrame($renderer, $appR, 120, 40);
check(str_contains($txt, '⚙ read_file(src/Foo.php) ✓'), '工具结果渲染成 ⚙ 摘要行（不倾倒文件内容）');
check(str_contains($txt, '→ read_file(path=src/Foo.php)'), 'assistant 的 tool_calls 渲染成 → name(args) 行');
check(str_contains($txt, '→ list_files(path=src)'), '两个 tool_calls 各占一行');
check(str_contains($txt, '完成'), '最终回复照常渲染');
// 带工具消息的渲染不产生超宽行（复用 ai_unit 的全屏行宽检查）
$maxLine = 0;
foreach ($lines as $l) {
    $maxLine = max($maxLine, mb_strwidth($l));
}
check($maxLine <= 120, "120x40 无超宽行（最宽 {$maxLine}）");

// 复制语义（D1）：点工具结果行 → 复制 meta.display 而非文件正文
$vpR = TuiArea::fromDimensions(120, 40);
$aR = $appR->areas($vpR);
$streamR = $aR['ai_stream'];
$innerR = $streamR->inner(new Margin(1, 1));

$findRow = static function (string $needle) use ($lines, $innerR): ?int {
    for ($y = $innerR->position->y; $y < $innerR->position->y + $innerR->height; $y++) {
        if (str_contains($lines[$y] ?? '', $needle)) {
            return $y;
        }
    }
    return null;
};

$appR->clipboardCopy('EMPTY');
$row = $findRow('⚙ read_file');
check($row !== null, '可在屏幕上找到工具结果摘要行');
$appR->handle(MouseEvent::new(MouseEventKind::Down, MouseButton::Left, $innerR->position->x, $row, 0), $vpR);
check($appR->clipboardPeek() === 'read_file(src/Foo.php) ✓',
    '点工具结果行 → 复制 meta.display 摘要（实际：' . var_export($appR->clipboardPeek(), true) . '）');

$row = $findRow('→ read_file');
check($row !== null, '可在屏幕上找到 tool_call 行');
$appR->handle(MouseEvent::new(MouseEventKind::Down, MouseButton::Left, $innerR->position->x, $row, 0), $vpR);
$got = $appR->clipboardPeek();
check(str_contains($got, '→ read_file(') && str_contains($got, '→ list_files('),
    '点 tool_call 行 → 复制该 assistant 的调用摘要（实际：' . var_export($got, true) . '）');

$row = $findRow('[历史摘要]');
check($row !== null, '可在屏幕上找到摘要消息行');
$appR->handle(MouseEvent::new(MouseEventKind::Down, MouseButton::Left, $innerR->position->x, $row, 0), $vpR);
check($appR->clipboardPeek() === '[历史摘要] 用户想了解 src', '点摘要行 → 复制摘要正文');

// 带工具消息的极小视口不崩、无超宽行
foreach ([[40, 10], [20, 6], [10, 4]] as [$W, $H]) {
    $ok = true;
    $err = null;
    try {
        [, $ls] = renderFrame($renderer, $appR, $W, $H);
        foreach ($ls as $l) {
            if (mb_strwidth($l) > $W) {
                $ok = false;
                break;
            }
        }
    } catch (Throwable $e) {
        $ok = false;
        $err = get_class($e) . ': ' . $e->getMessage();
    }
    check($ok, "{$W}x{$H}：带工具消息渲染不崩且无超宽行" . ($err !== null ? "（$err）" : ''));
}
@unlink($tmpCfg);

echo "\n" . ($failed ? "SOME FAILED\n" : "RESULT: PASS\n");
exit($failed ? 1 : 0);

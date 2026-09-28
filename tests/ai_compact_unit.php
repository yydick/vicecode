<?php
declare(strict_types=1);

/**
 * AI V2 上下文压缩 —— 无终端单测（mock 端点端到端）：
 *  1) 超阈值自动触发：send 前先发摘要请求，摘要回来替换旧历史（meta.kind=summary）再发真实请求；
 *  2) 未超阈值不触发；
 *  3) 压缩失败（mock 强制 500）降级：原历史一条不丢，真实请求照发；
 *  4) 保留区边界不拆散 tool_call/tool 对；
 *  5) 手动 compactNow（菜单路径）。
 *
 * 运行：php tests/ai_compact_unit.php
 */

require __DIR__ . '/../vendor/autoload.php';
putenv('APP_LOCALE=zh_CN');

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
            'label' => 'Mock', 'key_env' => 'MOCK_KEY',
            'base_url' => 'http://127.0.0.1:' . $port . '/v1',
            'models' => ['mock-1'], 'model' => 'mock-1',
        ],
    ]);
}

function drainChat(ChatModel $c, float $timeout = 30.0): void
{
    $dl = microtime(true) + $timeout;
    while ($c->isStreaming() && microtime(true) < $dl) {
        $c->poll();
        usleep(20000);
    }
}

// 配置/存档隔离（独立目录，防读到 /tmp 残留对话档）
$tmpDir = sys_get_temp_dir() . '/vice_compact_' . uniqid();
@mkdir($tmpDir, 0700, true);
$tmpCfg = $tmpDir . '/.vicerc';
file_put_contents($tmpCfg, json_encode(['ai' => ['persist' => false]]));
putenv('VICECODE_CONFIG=' . $tmpCfg);
// 一并隔离用户级 provider 配置：否则 ProviderRegistry 会读开发机真实的
// ~/.vicecode.providers.php（文件存在即覆盖内置 provider，断言会随本机配置漂移）
putenv('VICECODE_PROVIDERS_CONFIG=' . $tmpDir . '/.vicecode.providers.php');

// ─────────────── 1) 未超阈值：不触发压缩 ───────────────
echo "== 未超阈值不触发 ==\n";
$port1 = 18941;
$srv1 = mockServer($port1);
$app1 = new App();
$app1->chat = new ChatModel($app1, mockRegistry($port1));
$c1 = $app1->chat;
$c1->useProvider('mock');
$c1->send('短问题');
drainChat($c1);
$roles = array_map(static fn($m) => $m['role'], $c1->messages());
check($roles === ['user', 'assistant'], '短对话发一轮就是两条（实际：' . implode(',', $roles) . '）');
check(!in_array('summary', array_map(static fn($m) => $m['meta']['kind'] ?? '', $c1->messages()), true), '没有 summary 标记（未压缩）');
stopServer($srv1);

// ─────────────── 2) 超阈值：自动压缩 → 摘要替换 → 续发 ───────────────
echo "\n== 超阈值自动压缩 ==\n";
// 阈值调小 + 造长历史（CJK 3 字符/token：600 字 ≈ 200 token，阈值 100 必超）
file_put_contents($tmpCfg, json_encode(['ai' => ['persist' => false, 'compactThreshold' => 100, 'compactKeepRecent' => 4]]));
$port2 = 18942;
$srv2 = mockServer($port2, ['MOCK_SUMMARY' => '1']);
$app2 = new App();
$app2->chat = new ChatModel($app2, mockRegistry($port2));
$c2 = $app2->chat;
$c2->useProvider('mock');
$old = [];
for ($i = 0; $i < 12; $i++) {
    $old[] = ['role' => $i % 2 === 0 ? 'user' : 'assistant', 'content' => '历史消息' . $i . str_repeat('长', 40)];
}
$rp = new ReflectionProperty($c2, 'messages');
$rp->setAccessible(true);
$rp->setValue($c2, $old);
$c2->send('新问题');
drainChat($c2);
check(!$c2->isStreaming() && $c2->error() === null, '压缩 + 真实请求全程无错（实际：' . var_export($c2->error(), true) . '）');
$msgs = $c2->messages();
$roles = array_map(static fn($m) => $m['role'], $msgs);
check($roles[0] === 'user' && ($msgs[0]['meta']['kind'] ?? '') === 'summary', '首位是 summary 消息（meta.kind=summary）');
check(str_contains((string) $msgs[0]['content'], '摘要：'), '摘要内容来自 mock（实际：' . var_export(mb_substr((string) $msgs[0]['content'], 0, 20), true) . '）');
check(count($msgs) === 1 + 4 + 1 + 1, '形状 = summary + 保留 4 条 + 新 user + assistant（实际 ' . count($msgs) . '）');
check(($msgs[count($msgs) - 2]['content'] ?? '') === '新问题', '新 user 消息在保留区之后');
check(str_contains((string) $msgs[count($msgs) - 1]['content'], '摘要：'), '真实请求发出并拿到回复（MOCK_SUMMARY 场景回复=摘要文本）');
// 保留区起点必须是 user 消息（不劈消息中段）
$keptStart = 1;
check(($msgs[$keptStart]['role'] ?? '') === 'user', '保留区起点是 user 消息开头');
stopServer($srv2);

// ─────────────── 3) 压缩失败降级 ───────────────
echo "\n== 压缩失败降级 ==\n";
// MOCK_STATUS=500：摘要请求直接 500 → 降级恢复原历史继续真实请求
$port3 = 18943;
$srv3 = mockServer($port3, ['MOCK_STATUS' => '500']);
$app3 = new App();
$app3->chat = new ChatModel($app3, mockRegistry($port3));
$c3 = $app3->chat;
$c3->useProvider('mock');
$rp->setValue($c3, $old);
$c3->send('新问题');
drainChat($c3);
// 摘要失败（500）→ 降级；真实请求也会拿到 500 → 最终 error 有 500。但历史必须完整（12 条 + 新 user）
$msgs = $c3->messages();
$contents = array_map(static fn($m) => (string) ($m['content'] ?? ''), $msgs);
check(count($msgs) === 13, '降级后历史完整：12 条旧 + 1 条新 user（实际 ' . count($msgs) . '）');
check(!in_array('summary', array_map(static fn($m) => $m['meta']['kind'] ?? '', $msgs), true), '没有 summary 消息（压缩被放弃）');
check(in_array('历史消息0' . str_repeat('长', 40), $contents, true), '旧历史第 0 条原样保留');
check($c3->error() !== null, '最终错误来自真实请求的 500（不吞错）');
stopServer($srv3);

// ─────────────── 4) 保留区不拆散 tool 对 ───────────────
echo "\n== 保留区不拆散 tool 对 ==\n";
$app4 = new App();
$app4->chat = new ChatModel($app4, mockRegistry(18999)); // 不发请求，只测 keptRecent
$c4 = $app4->chat;
$m = new ReflectionMethod($c4, 'keptRecent');
$m->setAccessible(true);
$old4 = [
    ['role' => 'user', 'content' => 'u0'],
    ['role' => 'assistant', 'content' => '', 'tool_calls' => [
        ['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'read_file', 'arguments' => '{}']],
    ]],
    ['role' => 'tool', 'tool_call_id' => 'c1', 'content' => 'r1'],
    ['role' => 'assistant', 'content' => 'a1'],
    ['role' => 'user', 'content' => 'u1'],
];
// keep=1：从末尾回退到 user 起点（u1），tool 对整体留在摘要区
$kept = $m->invoke($c4, $old4, 1);
check(array_map(static fn($x) => $x['role'], $kept) === ['user'], 'keep=1 → 只留 u1（起点回退到 user）');
// keep=2：起点落在 a1（assistant）——assistant 开头合法（无需配对），不强行回退
$kept = $m->invoke($c4, $old4, 2);
check(array_map(static fn($x) => $x['role'], $kept) === ['assistant', 'user'], 'keep=2 → a1 起点合法，保留 a1+u1');
// keep=3：起点落在 tool 消息 → 回退到它的 assistant（带 tool_calls），不能只带 tool 不带 assistant
$kept = $m->invoke($c4, $old4, 3);
check(array_map(static fn($x) => $x['role'], $kept) === ['assistant', 'tool', 'assistant', 'user'],
    'keep=3 → 起点是 tool，回退到带 tool_calls 的 assistant（对完整，保留区=起点到末尾）');
// keep=4：起点恰是带 tool_calls 的 assistant → tool 对完整保留在保留区
$kept = $m->invoke($c4, $old4, 4);
check(array_map(static fn($x) => $x['role'], $kept) === ['assistant', 'tool', 'assistant', 'user'],
    'keep=4 → 起点 u0 之后的 tool 对完整保留');
foreach ($kept as $i => $x) {
    if (($x['role'] ?? '') === 'tool') {
        $prev = $kept[$i - 1] ?? null;
        check(($prev['role'] ?? '') === 'assistant' && isset($prev['tool_calls']),
            'tool 消息的前一条是带 tool_calls 的 assistant（成对）');
    }
}

// ─────────────── 5) 手动 compactNow ───────────────
echo "\n== 手动 compactNow ==\n";
$port5 = 18945;
$srv5 = mockServer($port5, ['MOCK_SUMMARY' => '1']);
$app5 = new App();
$app5->chat = new ChatModel($app5, mockRegistry($port5));
$c5 = $app5->chat;
$c5->useProvider('mock');
$rp->setValue($c5, $old);
$c5->compactNow();
drainChat($c5);
check(!$c5->isStreaming(), '手动压缩完成后不续发真实请求');
$msgs = $c5->messages();
check(count($msgs) === 1 + 4, '手动压缩结果 = summary + 保留 4 条（实际 ' . count($msgs) . '）');
check(($msgs[0]['meta']['kind'] ?? '') === 'summary', '首位是 summary');
check(str_contains((string) $app5->message, '压缩'), '状态栏提示压缩完成（实际：' . var_export($app5->message, true) . '）');
stopServer($srv5);

@unlink($tmpCfg);
@rmdir($tmpDir);

echo "\n" . ($failed ? "SOME FAILED\n" : "RESULT: PASS\n");
exit($failed ? 1 : 0);

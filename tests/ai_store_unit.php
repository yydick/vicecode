<?php
declare(strict_types=1);

/**
 * AI V2 对话持久化 —— 无终端单测：
 *  1) ChatStore 存/取往返（含 tool 消息与私有 meta 键）；
 *  2) 损坏 JSON / 形状非法 / 缺文件 → null；
 *  3) 权限 0600；
 *  4) ChatModel 接线：finish 落盘、restore 恢复 provider/model、Ctrl+L 同步清档；
 *  5) ai.persist=false 不落盘。
 *
 * 运行：php tests/ai_store_unit.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/isolation.php';
putenv('APP_LOCALE=zh_CN');

use App\Ai\ChatModel;
use App\Ai\ChatStore;
use App\Ai\ProviderRegistry;
use App\App;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\KeyModifiers;
use PhpTui\Tui\Display\Area;

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

// 配置与存档全部隔离到**独占目录**
// （tempnam 返回 /tmp 下的文件，dirname 就是 /tmp → 与别的测试共用 /tmp/.vicecode_ai）
$tmpCfg = vc_isolate_config('vc_ai_store');
file_put_contents($tmpCfg, '{}'); // ai.persist 缺省 = 开
$storePath = dirname($tmpCfg) . '/' . ChatStore::FILE_NAME;

// ─────────────── 1) 存/取往返 ───────────────
echo "== ChatStore 存/取往返 ==\n";

$messages = [
    ['role' => 'user', 'content' => '看看 src'],
    ['role' => 'assistant', 'content' => '', 'tool_calls' => [
        ['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'read_file', 'arguments' => '{"path":"src/Foo.php"}']],
    ]],
    ['role' => 'tool', 'tool_call_id' => 'c1', 'content' => "FILE src/Foo.php:\n内容", 'meta' => ['kind' => 'tool_result', 'display' => 'read_file(src/Foo.php) ✓']],
    ['role' => 'user', 'content' => '[历史摘要] 摘要内容', 'meta' => ['kind' => 'summary']],
    ['role' => 'assistant', 'content' => "多行\n回复"],
];
check(ChatStore::save($messages, ['provider' => 'mock', 'model' => 'mock-1']), 'save() 成功');
check(is_file($storePath), '存档文件存在（与 VICECODE_CONFIG 同目录）');
$perm = decoct((int) fileperms($storePath) & 0777);
check($perm === '600', "存档权限 0600（实际 {$perm}）");

$snap = ChatStore::load();
check($snap !== null, 'load() 读回');
check($snap['messages'] === $messages, 'messages 往返一致（tool_calls/meta 原样保留）');
check($snap['provider'] === 'mock' && $snap['model'] === 'mock-1', 'provider/model 一并恢复');

// ─────────────── 2) 损坏 / 非法 / 缺失 ───────────────
echo "\n== 损坏与非法形状 ==\n";

file_put_contents($storePath, '{not json');
check(ChatStore::load() === null, '损坏 JSON → null（不抛）');
file_put_contents($storePath, json_encode(['messages' => [['role' => 'user']]]));
check(ChatStore::load() === null, '消息缺 content 键 → null');
file_put_contents($storePath, json_encode(['messages' => ['notarray']]));
check(ChatStore::load() === null, '消息非数组 → null');
check(ChatStore::clear() && !is_file($storePath), 'clear() 删除存档');
check(ChatStore::load() === null, '缺文件 → null');
check(ChatStore::clear(), 'clear() 幂等');

// ─────────────── 3) ChatModel 接线：finish 落盘 + restore ───────────────
echo "\n== ChatModel 落盘与恢复（mock 端点）==\n";

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
            'models' => ['mock-1', 'mock-2'], 'model' => 'mock-1',
        ],
        'mock2' => [
            'label' => 'Mock2', 'key_env' => 'MOCK_KEY',
            'base_url' => 'http://127.0.0.1:' . $port . '/v1',
            'models' => ['m2'], 'model' => 'm2',
        ],
    ]);
}

$port = 18931;
$srv = mockServer($port);
$app = new App();
// 与 ai_unit 同款：注入指向 mock 的 registry（App 默认 chat 用真实 config，不能碰）
$app->chat = new ChatModel($app, mockRegistry($port));
$chat = $app->chat;
$chat->useProvider('mock');

$chat->send('你好');
$dl = microtime(true) + 20;
while ($chat->isStreaming() && microtime(true) < $dl) {
    $chat->poll();
    usleep(20000);
}
check(!$chat->isStreaming() && $chat->error() === null, '对话完成（实际：' . var_export($chat->error(), true) . '）');
check(is_file($storePath), 'finish 后自动落盘');

// 用「新 App」模拟重启：restore 恢复消息与 provider/model
$app2 = new App();
$app2->chat = new ChatModel($app2, mockRegistry($port));
$app2->chat->restore(); // App 构造只对默认 chat restore，这里对注入的 chat 手动 restore
$chat2 = $app2->chat;
check(count($chat2->messages()) === 2, '重启后恢复 2 条消息（实际 ' . count($chat2->messages()) . '）');
check(($chat2->messages()[0]['content'] ?? '') === '你好' && ($chat2->messages()[1]['content'] ?? '') === 'tok1 tok2 tok3 tok4 tok5 tok6 tok7 tok8 ',
    '恢复的对话内容正确');
check($chat2->spec()?->id === 'mock' && $chat2->spec()?->model === 'mock-1', 'provider/model 随档恢复');

// 恢复后的对话能继续多轮（上下文带旧消息）
$chat2->send('第二轮');
$dl = microtime(true) + 20;
while ($chat2->isStreaming() && microtime(true) < $dl) {
    $chat2->poll();
    usleep(20000);
}
check(count($chat2->messages()) === 4, '恢复后继续对话 → 4 条（历史没丢）');

// ─────────────── 4) Ctrl+L 清空同步清档 ───────────────
$app2->focusIndex = array_search('ai_input', App::PANELS, true);
$vp = Area::fromDimensions(120, 40);
$app2->handle(CharKeyEvent::new('l', KeyModifiers::CONTROL), $vp);
check($app2->chat->messages() === [], 'Ctrl+L 清空对话');
check(!is_file($storePath), 'Ctrl+L 同步清档（重启不会出现幽灵对话）');

// ─────────────── 5) ai.persist=false 不落盘 ───────────────
echo "\n== ai.persist=false ==\n";
@unlink($storePath); // 先清掉上一节的档，断言「不新增」才有意义
file_put_contents($tmpCfg, json_encode(['ai' => ['persist' => false]]));
$app3 = new App();
$app3->chat = new ChatModel($app3, mockRegistry($port));
$chat3 = $app3->chat;
$chat3->useProvider('mock');
// 恢复被关闭：写一份档再构造，不应读回
ChatStore::save([['role' => 'user', 'content' => 'x']]);
$app4 = new App();
$app4->chat = new ChatModel($app4, mockRegistry($port));
$app4->chat->restore();
check($app4->chat->messages() === [], 'persist=false 时 restore 不恢复');
@unlink($storePath);
$chat3->send('你好');
$dl = microtime(true) + 20;
while ($chat3->isStreaming() && microtime(true) < $dl) {
    $chat3->poll();
    usleep(20000);
}
check(!is_file($storePath), 'persist=false 时 finish 不落盘');

stopServer($srv);
@unlink($storePath);
@unlink($tmpCfg);

echo "\n" . ($failed ? "SOME FAILED\n" : "RESULT: PASS\n");
exit($failed ? 1 : 0);

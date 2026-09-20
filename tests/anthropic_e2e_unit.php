<?php
declare(strict_types=1);

/**
 * Anthropic provider 端到端（无终端，走**真 HTTP 进程**的 mock 端点）：
 *  1) 纯文本流式：内容拼接正确、且确实是**增量**到达（不是一次性返回）；
 *  2) Agent 工具完整往返：tool_use → 本地执行 → tool_result 回传 → 第二轮最终文本；
 *     且断言 `$this->messages` 的**内部形状不变**（仍是 OpenAI 语义的 tool_calls / role:tool）
 *     —— 证明协议转换只发生在 provider 内部，没有污染存档/渲染消费的状态；
 *  3) 上下文压缩：摘要请求的请求体里**真的带了旧历史**（docs/BUGFIXES.md T6 的防回归锚点）；
 *  4) 错误路径：401 鉴权失败要被识别为 HTTP 错误（而不是"模型没话说"）。
 *
 * ⚠️ 为什么必须用真 HTTP 而不是把字符串喂给解析器：SSE 的难点一半在传输层
 * （chunk 边界与事件边界无关、缓冲会把流式退化成一次性返回）。mock 端点还**按输入判断**回什么
 * （MOCK_SUMMARY 只在请求体里出现压缩指令特征时才回摘要），否则"假数据源不看输入"会掩盖真 bug。
 *
 * 运行：php tests/anthropic_e2e_unit.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/isolation.php';
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

/** 起 mock 服务端；$env 用 env 前缀注入，不污染本进程 */
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

/**
 * 造一个**走 Anthropic 协议**、指向本地 mock 的 registry。
 *
 * base_url 不带 /v1（Anthropic 惯例是站点根，/v1 由 ProviderSpec 补）—— 顺带验证这一点。
 * @param string $model 模型名（用于断言请求打到了哪个模型）
 */
function mockAnthropicRegistry(int $port, string $model = 'claude-mock-1', ?string $key = 'sk-ant-test'): ProviderRegistry
{
    putenv('MOCK_ANT_KEY=' . ($key ?? ''));
    return new ProviderRegistry([
        'mockanthropic' => [
            'label'      => 'MockAnthropic',
            'protocol'   => 'anthropic',
            'key_env'    => 'MOCK_ANT_KEY',
            'base_url'   => 'http://127.0.0.1:' . $port,   // 不带 /v1
            'max_tokens' => 1234,
            'models'     => [$model],
            'model'      => $model,
        ],
    ]);
}

/** 排空流式直到结束 */
function drainChat(ChatModel $c, float $timeout = 30.0): void
{
    $dl = microtime(true) + $timeout;
    while ($c->isStreaming() && microtime(true) < $dl) {
        $c->poll();
        usleep(20000);
    }
}

// 配置/存档隔离（独占目录，防读到开发机的真实 provider 配置与对话档）
vc_isolate_config('vc_anthropic_e2e');
$cfg = getenv('VICECODE_CONFIG');
file_put_contents((string) $cfg, json_encode(['ai' => ['persist' => false]]));

// ═══════════════════════════════════════════════════════════
// 1) 纯文本流式
// ═══════════════════════════════════════════════════════════
echo "== 1) 纯文本流式（Anthropic 协议）==\n";

$port1 = 18961;
$srv1 = mockServer($port1);
$app1 = new App();
$app1->chat = new ChatModel($app1, mockAnthropicRegistry($port1));
$c1 = $app1->chat;
$c1->useProvider('mockanthropic');
check($c1->spec()?->protocol === 'anthropic', 'spec 的协议是 anthropic');
check(str_ends_with((string) $c1->spec()?->chatUrl(), '/v1/messages'), '端点拼到 /v1/messages（base_url 不带 /v1）');

$c1->send('你好');
check($c1->isStreaming(), 'send() 后进入流式状态');
check(count($c1->messages()) === 2, 'send() 后历史为 user + 空 assistant（实际 ' . count($c1->messages()) . '）');

$lens = [];
$dl = microtime(true) + 25;
while ($c1->isStreaming() && microtime(true) < $dl) {
    $c1->poll();
    $lens[] = mb_strlen((string) ($c1->messages()[1]['content'] ?? ''));
    usleep(15000);
}
$final = (string) ($c1->messages()[1]['content'] ?? '');
check(!$c1->isStreaming(), '流式结束后 isStreaming=false');
check($c1->error() === null, '正常流无错误（实际：' . var_export($c1->error(), true) . '）');
check($final === 'tok1 tok2 tok3 tok4 tok5 tok6 tok7 tok8 ', '回复拼接正确（实际 ' . var_export($final, true) . '）');
// ★ 流式的关键证据：中间出现过「部分内容」
$distinct = array_values(array_unique($lens));
check(count($distinct) >= 3, '长度出现过 >=3 个中间值（真增量，不是一次性返回）。样本：' . implode(',', array_slice($distinct, 0, 8)));
check($distinct[0] < mb_strlen($final), '首个观测值小于最终长度（流式中途就被读到）');
check($c1->messages()[0]['role'] === 'user', '内部历史形状未被协议转换污染（还是 role:user）');
stopServer($srv1);

// ═══════════════════════════════════════════════════════════
// 2) Agent 工具完整往返
// ═══════════════════════════════════════════════════════════
echo "\n== 2) Agent 工具往返（tool_use / tool_result）==\n";

$port2 = 18962;
// MOCK_TOOLS=1：首轮回 tool_use，收到 tool_result 后回最终文本
$srv2 = mockServer($port2, ['MOCK_TOOLS' => '1']);
$app2 = new App();
$reg2 = mockAnthropicRegistry($port2);
$app2->chat = new ChatModel($app2, $reg2);
$c2 = $app2->chat;
$c2->useProvider('mockanthropic');
// 工具只读项目内文件：给一个测试专用根，内容可预期
// ⚠️ mock 发起的调用是 read_file('src/Foo.php') 与 list_files('src')，
// 故**在根下建 src/ 子目录**，否则工具会正确地拒绝（路径不存在）——那样断言失败的是
// 测试的数据布局，而不是被测代码。
$tmpRoot = vc_tmp_dir('vc_anth_root');
mkdir($tmpRoot . '/src', 0700, true);
file_put_contents($tmpRoot . '/src/Foo.php', "<?php\n// FOO_MARKER\n");
$c2->setToolsRoot($tmpRoot);

$c2->send('看看 src/Foo.php');
drainChat($c2);

check($c2->error() === null, '工具往返全程无错误（实际：' . var_export($c2->error(), true) . '）');
check($c2->steps() >= 1, 'Agent loop 至少跑了一步工具（实际 ' . $c2->steps() . '）');

$msgs = $c2->messages();
// 内部形状必须保持 OpenAI 语义（tool_calls / role:tool）——协议转换只在 provider 内
$asstWithCalls = null;
foreach ($msgs as $m) {
    if (($m['role'] ?? '') === 'assistant' && isset($m['tool_calls'])) {
        $asstWithCalls = $m;
    }
}
check($asstWithCalls !== null, '内部出现了带 tool_calls 的 assistant（内部形状仍是 OpenAI 语义）');
if ($asstWithCalls !== null) {
    $ids = array_column($asstWithCalls['tool_calls'], 'id');
    check(count($ids) === 2, '两个工具调用都累积到了（模拟分片 + 多块 index）；实际 ' . count($ids));
    check(in_array('toolu_mock_1', $ids, true) && in_array('toolu_mock_2', $ids, true),
        '工具 id 用的是 Anthropic 的 toolu_* 原值（实际 ' . implode(',', $ids) . '）');
    $names = array_column(array_column($asstWithCalls['tool_calls'], 'function'), 'name');
    check(in_array('read_file', $names, true) && in_array('list_files', $names, true),
        '工具名正确；实际 ' . implode(',', $names));
    // 参数分片必须拼完整（input_json_delta 分 2 片）
    $argsById = [];
    foreach ($asstWithCalls['tool_calls'] as $tc) {
        $argsById[$tc['id']] = $tc['function']['arguments'];
    }
    check(($argsById['toolu_mock_1'] ?? '') === '{"path":"src/Foo.php"}',
        'input_json_delta 分片拼成完整 arguments（实际 ' . var_export($argsById['toolu_mock_1'] ?? null, true) . '）');
}
$toolMsgs = array_values(array_filter($msgs, static fn($m) => ($m['role'] ?? '') === 'tool'));
check(count($toolMsgs) === 2, '内部有两条 role:tool 结果（实际 ' . count($toolMsgs) . '）');
$byId = [];
foreach ($toolMsgs as $m) {
    $byId[$m['tool_call_id'] ?? ''] = (string) ($m['content'] ?? '');
}
check(str_contains($byId['toolu_mock_1'] ?? '', 'FOO_MARKER'), 'read_file 结果内容正确（读到了文件真实内容）');
check(str_contains($byId['toolu_mock_2'] ?? '', 'Foo.php'), 'list_files 结果内容正确（列到了文件）');
// 工具结果消息带 meta（渲染/复制用），证明内部形状完整
check(isset($toolMsgs[0]['meta']['display']), '工具结果消息带 meta.display（渲染层依赖它，不能被协议转换弄丢）');

// 最终文本（第二轮）出现
$last = end($msgs);
check(($last['role'] ?? '') === 'assistant' && str_contains((string) ($last['content'] ?? ''), 'tok'),
    '工具往返后拿到最终文本回复（实际 ' . var_export(mb_substr((string) ($last['content'] ?? ''), 0, 30), true) . '）');
stopServer($srv2);

// ═══════════════════════════════════════════════════════════
// 3) 压缩摘要请求必须带旧历史（BUGFIXES T6 防回归）
// ═══════════════════════════════════════════════════════════
echo "\n== 3) 压缩摘要请求带旧历史（T6 防回归）==\n";

$port3 = 18963;
$bodyLog = vc_tmp_file('vc_anth_bodies');
// MOCK_BODY_FILE 记**完整请求体**（MOCK_LOG_FILE 只记模型名，不足以断言"请求里带了什么"）
$srv3 = mockServer($port3, ['MOCK_SUMMARY' => '1', 'MOCK_BODY_FILE' => $bodyLog]);
// 阈值调小 + 造长历史
file_put_contents((string) $cfg, json_encode(['ai' => [
    'persist' => false, 'compactThreshold' => 100, 'compactKeepRecent' => 2]]));
$app3 = new App();
$app3->chat = new ChatModel($app3, mockAnthropicRegistry($port3));
$c3 = $app3->chat;
$c3->useProvider('mockanthropic');

$marker = 'ANTH_MARKER_7c1d';
$long = $marker . str_repeat('历', 120);
foreach ([$long, $long, '触发压缩的新问题'] as $msg) {
    $c3->send($msg);
    drainChat($c3);
}
check($c3->error() === null, '压缩 + 续发全程无错误（实际：' . var_export($c3->error(), true) . '）');

$msgs3 = $c3->messages();
check(($msgs3[0]['meta']['kind'] ?? '') === 'summary', '首位是 summary 消息（压缩确实发生了）');

// ★ 核心断言：摘要请求的正文里**必须含旧历史特征串**（不是空请求！）
//
// ⚠️ 怎么认出"哪一次请求是压缩请求"：**不能靠消息条数**。普通请求第一轮也是单条 user
// （尾部的空 assistant 是内部占位符，协议转换时会丢掉），用它筛会先命中普通请求。
// 这里用**产品自己的指令文案**当前缀签名 —— 文案改了测试跟着改，不会两边各写一份字面量。
$locale = require dirname(__DIR__) . '/config/locales/zh_CN.php';
$instruction = (string) $locale['ai.compact_instruction'];
$signature = mb_substr($instruction, 0, 12);

$raw = (string) @file_get_contents($bodyLog);
$bodies = array_values(array_filter(explode("\n", $raw), static fn($l) => trim($l) !== ''));
$compactBody = null;
foreach ($bodies as $b) {
    $j = json_decode($b, true);
    $m = is_array($j) ? ($j['messages'] ?? []) : [];
    if (count($m) !== 1 || ($m[0]['role'] ?? '') !== 'user') {
        continue;
    }
    if (str_contains((string) ($m[0]['content'] ?? ''), $signature)) {
        $compactBody = $j;
        break;
    }
}
check($compactBody !== null, '找到了摘要请求（正文以压缩指令开头）');
if ($compactBody !== null) {
    $content = (string) ($compactBody['messages'][0]['content'] ?? '');
    check(str_contains($content, $marker), '摘要请求正文里**含旧历史特征串**（不是空请求！）');
    check(mb_strlen($content) > 200, '摘要请求正文有实质长度（实际 ' . mb_strlen($content) . ' 字符）');
    check(str_contains($content, 'User:') && str_contains($content, 'Assistant:'),
        '摘要请求里带角色标注（历史被文本化了）');
    check(!array_key_exists('tools', $compactBody), '摘要请求不带 tools（纯文本摘要，带工具会诱导模型回 tool_use）');
    check(is_int($compactBody['max_tokens'] ?? null), '摘要请求也带 max_tokens（Anthropic 必填）');
}
stopServer($srv3);

// ═══════════════════════════════════════════════════════════
// 4) 鉴权失败要被识别（而不是"模型没话说"）
// ═══════════════════════════════════════════════════════════
echo "\n== 4) 401 鉴权失败 ==\n";

$port5 = 18965;
$srv5 = mockServer($port5, ['MOCK_STATUS' => '401']);
$app5 = new App();
$app5->chat = new ChatModel($app5, mockAnthropicRegistry($port5));
$c5 = $app5->chat;
$c5->useProvider('mockanthropic');
$c5->send('hi');
drainChat($c5);
check($c5->error() !== null, 'HTTP 401 被识别为错误（不会静默当成空回复）');
check(str_contains((string) $c5->error(), '401'), '错误信息里带状态码 401（实际 ' . var_export($c5->error(), true) . '）');
stopServer($srv5);

// ─────────────── 结论 ───────────────
echo "\n";
if ($failed) {
    echo "RESULT: FAIL\n";
    exit(1);
}
echo "RESULT: PASS（Anthropic 端到端全部通过）\n";

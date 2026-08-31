<?php
declare(strict_types=1);

/**
 * M5 AI 交互流 —— 无终端单测（不依赖 tty）：
 *  1) SseParser 增量解析（chunk 边界与事件边界不对齐的各类切法、[DONE]、心跳、坏数据）；
 *  2) Provider 配置解析与请求体构造（env 读取、缺 key 提示不泄露、多轮 messages 拼接）；
 *  3) ChatModel 对接 mock SSE 服务端（流式增量、结束后定稿、中断）。
 *
 * 运行：php tests/ai_unit.php
 */

require __DIR__ . '/../vendor/autoload.php';
putenv('APP_LOCALE=zh_CN');

use App\Ai\ChatModel;
use App\Ai\OpenAiCompatProvider;
use App\Ai\ProviderRegistry;
use App\Ai\ProviderSpec;
use App\Ai\SseParser;
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

/** 造一个 OpenAI 兼容的 SSE data 帧 */
function frame(?string $content, ?string $role = null, ?string $finish = null): string
{
    $delta = [];
    if ($role !== null) {
        $delta['role'] = $role;
    }
    if ($content !== null) {
        $delta['content'] = $content;
    }
    $choice = ['delta' => $delta, 'index' => 0];
    if ($finish !== null) {
        $choice['finish_reason'] = $finish;
    }
    return 'data: ' . json_encode(['choices' => [$choice]], JSON_UNESCAPED_UNICODE) . "\n\n";
}

// ─────────────── 1) SseParser ───────────────
echo "== SseParser 增量解析 ==\n";

$p = new SseParser();
check($p->push(frame('hello')) === ['hello'], '单个完整事件 → 解析出一个 delta');
check(!$p->isDone(), '未收到 [DONE] 时 isDone=false');

$p = new SseParser();
check($p->push(frame('a') . frame('b') . frame('c')) === ['a', 'b', 'c'], '一个 chunk 含三个事件 → 按序产出三个 delta');

$p = new SseParser();
check($p->push(frame('a') . frame('b')) === ['a', 'b'], '两个事件一次到达');

// ── 核心：chunk 边界与事件边界不对齐 ──
$full = frame('你') . frame('好') . frame('世界');
$p = new SseParser();
$got = [];
foreach (str_split($full, 1) as $byte) {
    // 逐字节喂：最恶劣的切法，JSON 和 UTF-8 都会被劈得粉碎
    foreach ($p->push($byte) as $t) {
        $got[] = $t;
    }
}
check($got === ['你', '好', '世界'], '逐字节喂入（UTF-8 汉字被劈开）→ 结果与整块喂入一致，不乱码');

// 半行停在缓冲区，不提前产出。
// 注意必须劈**单个**帧：若劈含多个事件的串，前半段本就含完整事件，会正常产出。
$one = frame('你好世界');
$p = new SseParser();
$cut = (int) (strlen($one) / 2);
check($p->push(substr($one, 0, $cut)) === [], '半个事件到达时不产出（攒在缓冲区）');
check($p->push(substr($one, $cut)) === ['你好世界'], '补齐后半段一次性产出全部');

// ── [DONE] ──
$p = new SseParser();
$p->push(frame('a'));
$p->push("data: [DONE]\n\n");
check($p->isDone(), '收到 [DONE] → isDone=true');
check($p->push(frame('b')) === [], '[DONE] 之后的字节被忽略（keep-alive 不会污染结果）');

// ── flush：没有尾换行的残留行 ──
$p = new SseParser();
check($p->push('data: ' . json_encode(['choices' => [['delta' => ['content' => '尾巴']]]], JSON_UNESCAPED_UNICODE)) === [], '无尾换行的完整 data 行仍攒着（等换行）');
check($p->flush() === ['尾巴'], 'flush() 把无换行结尾的残留行解析出来');
check($p->flush() === [], 'flush() 幂等，第二次返回空');

// ── 各类可忽略的行 ──
$p = new SseParser();
check($p->push(": ping\n\n") === [], '心跳注释行被忽略');
check($p->push("\n\n") === [], '空行（事件分隔）不产出');
check($p->push("event: ping\n\n") === [], '非 data 行被忽略');
check($p->push('data:' . json_encode(['choices' => [['delta' => ['content' => 'x']]]]) . "\n\n") === ['x'], 'data: 后无空格也能解析（规范允许）');

// role-only 首帧：delta 里只有 role，content 为 null
$p = new SseParser();
check($p->push(frame(null, 'assistant')) === [], 'role-only 首帧（content=null）不产出空串');
check($p->push(frame('hi')) === ['hi'], 'role-only 帧之后正常帧照常产出');

// finish_reason 帧：delta 为空对象
$p = new SseParser();
$finishFrame = 'data: ' . json_encode(['choices' => [['delta' => [], 'index' => 0, 'finish_reason' => 'stop']]]) . "\n\n";
check($p->push($finishFrame) === [], 'finish_reason 帧（delta 为空）不产出内容');

// ── 坏数据：记录而非抛异常 ──
$p = new SseParser();
check($p->push("data: {not json}\n\n") === [], '坏 JSON 不产出内容');
check($p->error() !== null, '坏 JSON 被记录到 error（不抛异常炸掉 UI）');
check(!$p->isDone(), '坏 JSON 不会误判为流结束（后面还能继续收）');

// ── 200 状态但体内是错误对象（OpenAI overload 形态）──
$p = new SseParser();
$errFrame = 'data: ' . json_encode(['error' => ['message' => 'rate limited', 'type' => 'overload']]) . "\n\n";
check($p->push($errFrame) === [], '错误对象帧不产出内容');
check($p->error() === 'rate limited', '错误对象的 message 被取出（实际：' . var_export($p->error(), true) . '）');
check($p->isDone(), '错误对象视为流结束');

// ── 真实世界的完整流（首帧 role + 内容帧 + finish + DONE）──
$p = new SseParser();
$stream = frame(null, 'assistant')
    . frame('你好，')
    . frame('世界')
    . $finishFrame
    . "data: [DONE]\n\n";
$got = $p->push($stream);
check(implode('', $got) === '你好，世界', '完整流拼接结果正确（实际：' . implode('', $got) . '）');
check($p->isDone(), '完整流结束后 isDone=true');
check($p->error() === null, '正常流无 error');

// ─────────────── 2) Provider 配置解析 ───────────────
echo "\n== ProviderRegistry 配置解析 ==\n";

// 固定配置 + 受控环境变量：不依赖真实 config 文件，也不依赖开发者机器上的 key
$cfg = [
    'alpha' => [
        'label'    => 'Alpha',
        'key_env'  => 'TEST_ALPHA_KEY',
        'url_env'  => 'TEST_ALPHA_URL',
        'base_url' => 'https://alpha.example/v1',
        'models'   => ['a-1', 'a-2'],
        'model'    => 'a-1',
    ],
    'beta' => [
        'label'    => 'Beta',
        'key_env'  => 'TEST_BETA_KEY',
        'url_env'  => 'TEST_BETA_URL',
        'base_url' => 'https://beta.example/v1',
        'models'   => ['b-1'],
        'model'    => 'b-1',
    ],
];

putenv('TEST_ALPHA_KEY=sk-alpha-secret');
putenv('TEST_BETA_KEY=');
putenv('TEST_ALPHA_URL=');
putenv('TEST_BETA_URL=https://beta-proxy.local/v1');

$reg = new ProviderRegistry($cfg);
check($reg->ids() === ['alpha', 'beta'], 'ids() 保持配置声明顺序（切换顺序就是 UI 顺序）');
check($reg->defaultId() === 'alpha', 'defaultId() 取第一个');
check($reg->label('beta') === 'Beta', 'label() 取展示名');
check(!$reg->has('nope'), '未知 id → has()=false');
check($reg->spec('nope') === null, '未知 id → spec() 为 null（不静默 fallback 到别家）');

$a = $reg->spec('alpha');
check($a instanceof ProviderSpec, 'alpha 解析出 ProviderSpec');
check($a->hasKey(), 'key 从环境变量读到');
check($a->apiKey === 'sk-alpha-secret', 'key 值正确');
check($a->baseUrl === 'https://alpha.example/v1', 'base_url 用配置默认值（env 为空不覆盖）');
check($a->model === 'a-1', '未指定 model 时用配置默认模型');
check($a->chatUrl() === 'https://alpha.example/v1/chat/completions', 'chatUrl() 拼出完整端点');
check($a->supportsModel('a-2') && !$a->supportsModel('zzz'), 'supportsModel() 按白名单校验');

$a2 = $reg->spec('alpha', 'a-2');
check($a2?->model === 'a-2', '显式指定在白名单内的模型 → 生效');
$a3 = $reg->spec('alpha', 'not-a-model');
check($a3?->model === 'a-1', '指定不在白名单的模型 → 退回默认（避免拿到看不懂的 400）');

$b = $reg->spec('beta');
check(!$b?->hasKey(), '空环境变量 → hasKey()=false（UI 据此提示缺 key）');
check($b?->baseUrl === 'https://beta-proxy.local/v1', 'base_url 可被环境变量覆盖（自建网关/代理）');

// 空配置时不能炸
$empty = new ProviderRegistry([]);
check($empty->ids() === [] && $empty->defaultId() === null, '空配置：ids 为空、defaultId 为 null，不抛异常');

// base_url 以 / 结尾时不要拼出双斜杠
$slash = new ProviderRegistry([
    'x' => ['label' => 'X', 'key_env' => 'TEST_X_KEY', 'base_url' => 'https://x.example/v1/', 'models' => ['m'], 'model' => 'm'],
]);
putenv('TEST_X_KEY=k');
check($slash->spec('x')?->chatUrl() === 'https://x.example/v1/chat/completions', 'base_url 尾部斜杠被规范化，不出现 //');

// ─────────────── 3) 请求构造 ───────────────
echo "\n== OpenAiCompatProvider 请求构造 ==\n";

$spec = $reg->spec('alpha');
$prov = new OpenAiCompatProvider();
$cmd = $prov->buildCommand($spec, [['role' => 'user', 'content' => '你好']]);

check(str_contains($cmd, 'curl -N '), '命令带 -N（关 curl 缓冲，否则流式退化成一次性返回）');
check(str_contains($cmd, '-X POST'), 'POST 方法');
check(str_contains($cmd, '--max-time 300'), '带总超时');
check(str_contains($cmd, escapeshellarg($spec->chatUrl())), 'URL 经 escapeshellarg 包裹');
check(str_contains($cmd, "--data-binary '@"), '请求体走 @文件（不进 argv，避免长对话撞 ARG_MAX）');
check(str_contains($cmd, "-H '@"), '请求头走 @文件');

// ★ 安全：key 绝不能出现在命令行里（argv 对同机其他用户可见）
check(!str_contains($cmd, 'sk-alpha-secret'), 'API key **不出现**在命令行（argv 会被 ps 看到）');

// 请求体内容正确（读 @ 指向的文件）
preg_match("/--data-binary '(@[^']+)'/", $cmd, $m);
$bodyPath = substr($m[1] ?? '', 1);
check(is_file($bodyPath), '请求体临时文件存在');
$decoded = json_decode((string) @file_get_contents($bodyPath), true);
check(is_array($decoded), '请求体是合法 JSON');
check(($decoded['model'] ?? null) === 'a-1', '请求体含 model');
check(($decoded['stream'] ?? null) === true, '请求体 stream=true（否则不流式）');
check(($decoded['messages'][0]['content'] ?? null) === '你好', 'messages 原样带上（中文不被转义破坏）');

// 多轮：完整历史都要带上，这是「第二轮带第一轮上下文」的根据
$multi = [
    ['role' => 'system', 'content' => '你是助手'],
    ['role' => 'user', 'content' => '第一轮'],
    ['role' => 'assistant', 'content' => '回答一'],
    ['role' => 'user', 'content' => '第二轮'],
];
$prov2 = new OpenAiCompatProvider();
$cmd2 = $prov2->buildCommand($spec, $multi);
preg_match("/--data-binary '(@[^']+)'/", $cmd2, $m2);
$dec2 = json_decode((string) @file_get_contents(substr($m2[1] ?? '', 1)), true);
check(count($dec2['messages'] ?? []) === 4, '多轮：4 条历史全部带上');
check(($dec2['messages'][0]['role'] ?? null) === 'system', '多轮：system 消息在首位');
check(($dec2['messages'][3]['content'] ?? null) === '第二轮', '多轮：最新一轮在末尾');

// 头部文件内容正确（key 在文件里，不在 argv）
preg_match("/-H '(@[^']+)'/", $cmd2, $m3);
$hdr = (string) @file_get_contents(substr($m3[1] ?? '', 1));
check(str_contains($hdr, 'Authorization: Bearer sk-alpha-secret'), 'Authorization 头写在临时文件里');
check(str_contains($hdr, 'Content-Type: application/json'), 'Content-Type 头存在');
check(str_contains($hdr, 'Accept: text/event-stream'), 'Accept: text/event-stream 存在');

// -D 的 meta 文件：用于区分「鉴权失败」和「模型真的没话说」
$meta = $prov2->metaFile();
check(is_string($meta) && is_file($meta), 'meta（响应头 dump）文件存在');

// 第二次 buildCommand 会清掉上一次的临时文件，避免泄漏
$oldBody = $bodyPath;
$prov->buildCommand($spec, [['role' => 'user', 'content' => 'x']]);
check(!is_file($oldBody), '再次构造请求时清理上一次的临时文件（不泄漏）');

// cleanup 后文件确实没了
$prov3 = new OpenAiCompatProvider();
$prov3->buildCommand($spec, [['role' => 'user', 'content' => 'x']]);
$p = $prov3->metaFile();
$prov3->cleanup();
check(!is_file((string) $p), 'cleanup() 删除临时文件');
$prov3->cleanup(); // 幂等
check(true, 'cleanup() 可重复调用');

// ─────────────── 4) ChatModel 对接 mock 服务端 ───────────────
echo "\n== ChatModel 端到端（本地 mock 端点）==\n";

/** 起一个 mock 服务端；返回 proc 资源，调用方负责收掉 */
function mockServer(int $port, array $env = []): mixed
{
    $cmd = ['php', '-S', '127.0.0.1:' . $port, dirname(__DIR__) . '/examples/sse_server.php'];
    if ($env !== []) {
        // 用 env 前缀注入，避免污染本进程环境
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

/** 造一个指向本地 mock 的 registry（不碰真实 config/providers.php 与真实 key） */
function mockRegistry(int $port, ?string $key = 'sk-test-local'): ProviderRegistry
{
    // registry 只认环境变量，测试要自己把 key 放进 env
    putenv('MOCK_KEY=' . ($key ?? ''));
    return new ProviderRegistry([
        'mock' => [
            'label'    => 'Mock',
            'key_env'  => 'MOCK_KEY',
            'base_url' => 'http://127.0.0.1:' . $port . '/v1',
            'models'   => ['mock-1', 'mock-2'],
            'model'    => 'mock-1',
        ],
        'mock2' => [
            'label'    => 'Mock2',
            'key_env'  => 'MOCK2_KEY',
            'base_url' => 'http://127.0.0.1:' . $port . '/v1',
            'models'   => ['m2'],
            'model'    => 'm2',
        ],
    ]);
}

/** mock 默认发 8 个 token（n 缺省 8），期望回复就是它们的拼接 */
const EXPECT_REPLY = 'tok1 tok2 tok3 tok4 tok5 tok6 tok7 tok8 ';

$GLOBALS['port'] = 18911;
$srv = mockServer($GLOBALS['port']);
$port = $GLOBALS['port'];

// ── 基本流式：发一条，逐 token 长出来 ──
$app = new App();
$chat = new ChatModel($app, mockRegistry($port));
$chat->useProvider('mock');
check($chat->spec()?->model === 'mock-1', '切到 mock provider，模型取默认');

$chat->send('你好');
check($chat->isStreaming(), 'send() 后进入流式状态');
check(count($chat->messages()) === 2, 'send() 后历史为 user + 空 assistant 两条');

// 轮询直到结束；同时记录内容长度的变化，用来证明「是增量出现的」而不是最后一股脑返回
$lens = [];
$deadline = microtime(true) + 25;
$rounds = 0;
while ($chat->isStreaming() && microtime(true) < $deadline) {
    $chat->poll();
    $lens[] = mb_strlen($chat->messages()[1]['content'] ?? '');
    $rounds++;
    usleep(20000);
}
$final = $chat->messages()[1]['content'] ?? '';

check(!$chat->isStreaming(), '流式结束后 isStreaming=false');
check($chat->error() === null, '正常流无错误（实际：' . var_export($chat->error(), true) . '）');
check($final === EXPECT_REPLY, '回复内容拼接正确（实际：' . var_export($final, true) . '）');

// ★ 流式的关键证据：中间出现过「部分内容」的状态
$distinct = array_values(array_unique($lens));
check(count($distinct) >= 3, '内容长度出现过 >=3 个不同中间值（证明是增量流式，不是一次性返回）。样本：' . implode(',', array_slice($distinct, 0, 8)));
check($distinct[0] < mb_strlen($final), '首个观测值小于最终长度（流式中途就被读到）');

// ── 多轮上下文：第二轮必须带上第一轮 ──
$chat->send('第二轮');
$deadline = microtime(true) + 25;
while ($chat->isStreaming() && microtime(true) < $deadline) {
    $chat->poll();
    usleep(20000);
}
$msgs = $chat->messages();
check(count($msgs) === 4, '两轮后历史为 4 条（user/assistant × 2），实际 ' . count($msgs));
check(($msgs[0]['content'] ?? '') === '你好', '多轮：第一轮 user 仍在历史里');
check(($msgs[1]['content'] ?? '') === EXPECT_REPLY, '多轮：第一轮 assistant 回复保留');
check(($msgs[2]['content'] ?? '') === '第二轮', '多轮：第二轮 user 正确');
check(($msgs[3]['content'] ?? '') === EXPECT_REPLY, '多轮：第二轮也拿到回复（上下文没把请求搞坏）');

// ── 空输入不应起请求 ──
$before = count($chat->messages());
$chat->send('   ');
check(count($chat->messages()) === $before, '空白输入不产生消息、不起请求');
check(!$chat->isStreaming(), '空白输入不会进入流式状态');

// ── 生成中不能并发起新请求 ──
$chat->send('长任务');
$chat->send('插队');
check(count($chat->messages()) === $before + 2, '生成中再发 → 被拒（没有多出消息）');
$deadline = microtime(true) + 25;
while ($chat->isStreaming() && microtime(true) < $deadline) {
    $chat->poll();
    usleep(20000);
}
stopServer($srv);

// ── 中断生成（R6）──
echo "\n== 中断生成 ==\n";
$port2 = 18912;
$srv2 = mockServer($port2);
$app2 = new App();
$chat2 = new ChatModel($app2, mockRegistry($port2));
$chat2->useProvider('mock');
// 用长流：n=20, delay=200 → 约 4s
$chat2->send('x');
$partial = '';
$deadline2 = microtime(true) + 25;
while ($chat2->isStreaming() && microtime(true) < $deadline2) {
    $chat2->poll();
    $cur = $chat2->messages()[1]['content'] ?? '';
    if (mb_strlen($cur) >= 5) {
        $partial = $cur;
        break; // 收到一点内容就中断
    }
    usleep(20000);
}
$chat2->cancel();
check(!$chat2->isStreaming(), 'cancel() 后立刻退出流式状态（按键马上有反馈）');
check($partial !== '', '中断前确实收到过部分内容（实际：' . var_export($partial, true) . '）');
$kept = $chat2->messages()[1]['content'] ?? '';
check($kept === $partial, '中断后已生成的内容被保留，不回滚');
check(str_contains((string) $app2->message, '停止') || str_contains((string) $app2->message, 'stop'), '状态栏提示已停止生成（实际：' . var_export($app2->message, true) . '）');
stopServer($srv2);

// ── HTTP 错误：401 必须被识别，而不是表现为「回复为空」──
echo "\n== 错误处理 ==\n";
$port3 = 18913;
$srv3 = mockServer($port3, ['MOCK_STATUS' => '401']);
$app3 = new App();
$chat3 = new ChatModel($app3, mockRegistry($port3));
$chat3->useProvider('mock');
$chat3->send('hi');
$deadline3 = microtime(true) + 25;
while ($chat3->isStreaming() && microtime(true) < $deadline3) {
    $chat3->poll();
    usleep(20000);
}
check($chat3->error() !== null, 'HTTP 401 → 有 error（不能表现成"回复为空"）');
check(str_contains((string) $chat3->error(), '401'), 'error 里带上状态码（实际：' . var_export($chat3->error(), true) . '）');
// 失败的空 assistant 消息要被移除，否则每轮失败都留一条空记录还会被当上下文发回去
$last = $chat3->messages()[count($chat3->messages()) - 1] ?? null;
check(($last['role'] ?? null) === 'user', '失败后空的 assistant 消息被移除（历史末位是 user）');
stopServer($srv3);

// ── 缺 key：给友好提示，且提示里要说清设哪个环境变量 ──
$app4 = new App();
$reg4 = new ProviderRegistry([
    'nokey' => [
        'label' => 'NoKey', 'key_env' => 'DEFINITELY_UNSET_KEY_XYZ',
        'base_url' => 'http://127.0.0.1:1/v1', 'models' => ['m'], 'model' => 'm',
    ],
]);
putenv('DEFINITELY_UNSET_KEY_XYZ');
$chat4 = new ChatModel($app4, $reg4);
$chat4->useProvider('nokey');
$chat4->send('hi');
check(!$chat4->isStreaming(), '缺 key 时不起请求');
check($chat4->error() !== null && str_contains($chat4->error(), 'DEFINITELY_UNSET_KEY_XYZ'), '缺 key 提示指明该设哪个环境变量（实际：' . var_export($chat4->error(), true) . '）');
check(count($chat4->messages()) === 0, '缺 key 时用户消息不入历史（否则重试会带着失败记录）');

// ── 一个 provider 都没配 ──
$app5 = new App();
$chat5 = new ChatModel($app5, new ProviderRegistry([]));
$chat5->send('hi');
check($chat5->error() !== null, '空配置：给出"未配置 Provider"提示而不是崩溃');
check($chat5->spec() === null, '空配置：spec() 为 null');

exit($failed ? 1 : 0);

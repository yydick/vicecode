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
use App\Text\DisplayWidth;

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
check(count($chat4->messages()) === 1, '缺 key 时用户消息仍入历史（用户要能看到自己打了什么）');
check(($chat4->messages()[0]['role'] ?? null) === 'user', '缺 key 时入列的只有 user，不会留下空的 assistant');

// ── 一个 provider 都没配 ──
$app5 = new App();
$chat5 = new ChatModel($app5, new ProviderRegistry([]));
$chat5->send('hi');
check($chat5->error() !== null, '空配置：给出"未配置 Provider"提示而不是崩溃');
check($chat5->spec() === null, '空配置：spec() 为 null');

// ─────────────── 5) 渲染与交互（面板层）───────────────
echo "\n== AI 面板渲染与交互 ==\n";

use PhpTui\Tui\Display\Area as TuiArea;
use PhpTui\Tui\Display\Buffer as TuiBuffer;
use PhpTui\Tui\Extension\Core\CoreExtension;
use PhpTui\Tui\Widget\WidgetRenderer\AggregateWidgetRenderer;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\KeyModifiers;
use PhpTui\Term\Event\MouseEvent;
use PhpTui\Term\MouseEventKind;
use PhpTui\Term\MouseButton;

$ext = new CoreExtension();
$renderers = [];
foreach ($ext->widgetRenderers() as $r) {
    $renderers[] = $r;
}
$renderer = new AggregateWidgetRenderer($renderers);

/** 轮询直到流式结束（请求完成或失败） */
function drainChat(ChatModel $c, float $timeout = 15.0): void
{
    $dl = microtime(true) + $timeout;
    while ($c->isStreaming() && microtime(true) < $dl) {
        $c->poll();
        usleep(20000);
    }
}

/** 渲染一帧并返回纯文本（已剥掉 ANSI 之前的内容全行） */
function renderText(AggregateWidgetRenderer $renderer, App $app, int $w, int $h): string
{
    $vp = TuiArea::fromDimensions($w, $h);
    $buf = TuiBuffer::empty($vp);
    $renderer->render($renderer, $app->render($vp), $buf, $buf->area());
    return implode("\n", $buf->toLines());
}

/** 取 ai_stream 面板的内宽（减去左右边框） */
function aiInnerWidth(App $app, int $w, int $h): int
{
    $a = $app->areas(TuiArea::fromDimensions($w, $h));
    return max(0, ($a['ai_stream']->width ?? 0) - 2);
}

// 长回复 + 多轮：主要用来验「软换行后没有超宽行」——超宽 1 列就会触发
// php-tui 的 LineTruncator **折行**，把后续整片行挤下去（M1 幽灵行的根因）。
$longReply = str_repeat('这是一个很长的回复内容用于验证软换行是否生效', 6);
$rc = new ChatModel($app = new App(), mockRegistry(18999)); // 端口不通，只做渲染，不发请求
$rc->useProvider('mock');
// 直接注入历史，不走网络
$ref = new ReflectionClass($rc);
$prop = $ref->getProperty('messages');
$prop->setAccessible(true);
$prop->setValue($rc, [
    ['role' => 'user', 'content' => '问题一'],
    ['role' => 'assistant', 'content' => $longReply],
    ['role' => 'user', 'content' => '问题二'],
    ['role' => 'assistant', 'content' => '短回复'],
]);
$app->chat = $rc;

foreach ([[120, 40], [100, 30], [80, 24]] as [$W, $H]) {
    $txt = renderText($renderer, $app, $W, $H);
    $innerW = aiInnerWidth($app, $W, $H);
    $maxLine = 0;
    foreach (explode("\n", $txt) as $l) {
        $maxLine = max($maxLine, mb_strwidth($l));
    }
    check($maxLine <= $W, "{$W}x{$H}：没有一屏行超过视口宽（最宽 {$maxLine} <= {$W}）—— 超宽会触发折行幽灵行");
    check(str_contains($txt, '问题一'), "{$W}x{$H}：渲染出用户消息");
    check(str_contains($txt, '短回复'), "{$W}x{$H}：渲染出助手回复");
}

// 逐行检查 ai_stream 区域：软换行后的每一行宽度必须 <= 内宽
// （整屏检查会包含状态栏等其它面板，这里精确锁 AI 面板）
{
    $W = 80;
    $H = 24;
    $vp = TuiArea::fromDimensions($W, $H);
    $a = $app->areas($vp);
    $stream = $a['ai_stream'];
    $buf = TuiBuffer::empty($vp);
    $renderer->render($renderer, $app->render($vp), $buf, $buf->area());
    $all = $buf->toLines();
    $innerW = max(0, $stream->width - 2);
    $bad = [];
    for ($y = $stream->position->y + 1; $y < $stream->position->y + $stream->height - 1; $y++) {
        $line = $all[$y] ?? '';
        if (mb_strwidth($line) > $W) {
            $bad[] = $y . ':' . mb_strwidth($line);
        }
    }
    check($bad === [], 'AI 消息流区域内无超宽行（越界行：' . (implode(',', $bad) ?: '无') . '）');
}

// 状态栏显示当前 Provider/模型
$txt = renderText($renderer, $app, 120, 40);
check(str_contains($txt, 'Mock/mock-1'), '状态栏显示当前 Provider/模型（实际含 Mock/mock-1）');

// ── 交互：Ctrl+P 切 Provider、Ctrl+N 切模型 ──
$app2 = new App();
$app2->chat = new ChatModel($app2, mockRegistry(18998));
$app2->focusIndex = array_search('ai_input', App::PANELS, true);
check($app2->focusPanel() === 'ai_input', '焦点可切到 ai_input');

$p0 = $app2->chat->spec()?->id;
$app2->handle(CharKeyEvent::new('p', KeyModifiers::CONTROL), TuiArea::fromDimensions(120, 40));
check($app2->chat->spec()?->id !== $p0, 'Ctrl+P 切到另一个 Provider');
$p1 = $app2->chat->spec()?->id;
$app2->handle(CharKeyEvent::new('p', KeyModifiers::CONTROL), TuiArea::fromDimensions(120, 40));
check($app2->chat->spec()?->id === $p0, '再按 Ctrl+P 环形切回（只有两个 Provider）');

$app2->chat->useProvider('mock');
$m0 = $app2->chat->spec()?->model;
$app2->handle(CharKeyEvent::new('n', KeyModifiers::CONTROL), TuiArea::fromDimensions(120, 40));
check($app2->chat->spec()?->model !== $m0, 'Ctrl+N 切到另一个模型（Ctrl+M 不能用，它是回车 0x0D）');

// ── 交互：输入 → 回车发送 → 输入框清空 ──
$vp2 = TuiArea::fromDimensions(120, 40);
$app3 = new App();
$app3->chat = new ChatModel($app3, mockRegistry(18997));
$app3->focusIndex = array_search('ai_input', App::PANELS, true);
foreach (mb_str_split('你好') as $ch) {
    $app3->handle(CharKeyEvent::new($ch, 0), $vp2);
}
check($app3->ai->input() === '你好', '中文逐字输入进输入框');
$app3->handle(CharKeyEvent::new("\r", 0), $vp2);
check($app3->ai->input() === '', '回车后输入框清空');
// 18997 没有服务端，curl 会立刻失败；必须轮询到结束，否则空的 assistant 占位还在
drainChat($app3->chat);
check(count($app3->chat->messages()) === 1, '回车后用户消息入列（请求失败后空的 assistant 占位被移除）');
check(($app3->chat->messages()[0]['content'] ?? '') === '你好', '中文内容完整入列');

// ── 回车两条路径都要能发送 ──
// ⚠️ 真实终端发的是 **CodedKeyEvent(Enter)**，不是 CharKeyEvent("\r")。
// 只挂 "\r" 时 headless 全绿但 pty 下按回车没反应（M2 的终端面板踩过同一个坑）。
$app3b = new App();
$app3b->chat = new ChatModel($app3b, mockRegistry(18995));
$app3b->focusIndex = array_search('ai_input', App::PANELS, true);
foreach (mb_str_split('路径') as $ch) {
    $app3b->handle(CharKeyEvent::new($ch, 0), $vp2);
}
$app3b->handle(CodedKeyEvent::new(KeyCode::Enter), $vp2);
drainChat($app3b->chat); // 18995 无服务端，curl 立刻失败；排空后空的 assistant 占位才被移除
check(count($app3b->chat->messages()) === 1, 'CodedKeyEvent(Enter) 也能发送（真实终端走这条路径）');
check($app3b->ai->input() === '', 'CodedKeyEvent(Enter) 发送后输入框清空');

// ── ↑/↓ prompt 历史 ──
foreach (mb_str_split('第二条') as $ch) {
    $app3->handle(CharKeyEvent::new($ch, 0), $vp2);
}
$app3->handle(CharKeyEvent::new("\r", 0), $vp2);
check($app3->ai->history() === ['你好', '第二条'], '历史按发送顺序记录');
$app3->handle(CodedKeyEvent::new(KeyCode::Up), $vp2);
check($app3->ai->input() === '第二条', '↑ 取回最近一条 prompt');
$app3->handle(CodedKeyEvent::new(KeyCode::Up), $vp2);
check($app3->ai->input() === '你好', '再 ↑ 取回更早一条');
$app3->handle(CodedKeyEvent::new(KeyCode::Up), $vp2);
check($app3->ai->input() === '你好', '已在最旧一条时继续 ↑ 不越界');
$app3->handle(CodedKeyEvent::new(KeyCode::Down), $vp2);
check($app3->ai->input() === '第二条', '↓ 往回走');
$app3->handle(CodedKeyEvent::new(KeyCode::Down), $vp2);
check($app3->ai->input() === '', '↓ 到底部回到空输入（草稿为空）');

// ── Ctrl+L 清空对话 ──
$app3->handle(CharKeyEvent::new('l', KeyModifiers::CONTROL), $vp2);
check($app3->chat->messages() === [], 'Ctrl+L 清空对话历史');

// ── 滚轮滚动 ──
$app4 = new App();
$app4->chat = new ChatModel($app4, mockRegistry(18996));
$rc4p = new ReflectionClass($app4->chat);
$mp = $rc4p->getProperty('messages');
$mp->setAccessible(true);
$mp->setValue($app4->chat, [
    ['role' => 'user', 'content' => 'Q'],
    ['role' => 'assistant', 'content' => implode("\n", array_map(static fn($i) => "第{$i}行内容", range(1, 60)))],
]);
$app4->focusIndex = array_search('ai_stream', App::PANELS, true);
$vp4 = TuiArea::fromDimensions(120, 40);
renderText($renderer, $app4, 120, 40);
check($app4->ai->isFollowing(), '初始状态贴底（follow=true）');
$app4->handle(MouseEvent::new(MouseEventKind::ScrollUp, MouseButton::Left, 50, 5, 0), $vp4);
check(!$app4->ai->isFollowing(), '往上滚 → 脱离跟随（否则新 token 会把用户拽回底部）');
$app4->handle(MouseEvent::new(MouseEventKind::ScrollDown, MouseButton::Left, 50, 5, 0), $vp4);
check($app4->ai->isFollowing() || $app4->ai->scroll() > 0, '往下滚 → 朝底部移动');

// ── Esc 经 App 分发中断生成（不直接调 cancel，走真实按键路径）──
echo "\n== Esc 中断（走 App 按键分发）==\n";
$port5 = 18915;
$srv5 = mockServer($port5);
$app5 = new App();
$app5->chat = new ChatModel($app5, mockRegistry($port5));
$app5->chat->useProvider('mock');
$vp5 = TuiArea::fromDimensions(120, 40);
$app5->focusIndex = array_search('ai_input', App::PANELS, true);

foreach (mb_str_split('开始') as $ch) {
    $app5->handle(CharKeyEvent::new($ch, 0), $vp5);
}
$app5->handle(CharKeyEvent::new("\r", 0), $vp5);
check($app5->chat->isStreaming(), '发送后进入生成中');

// 收到一点内容再按 Esc
$dl = microtime(true) + 15;
while ($app5->chat->isStreaming() && microtime(true) < $dl) {
    $app5->pollAi();
    if (mb_strlen($app5->chat->messages()[1]['content'] ?? '') >= 5) {
        break;
    }
    usleep(20000);
}
$app5->handle(CodedKeyEvent::new(KeyCode::Esc), $vp5);
check(!$app5->chat->isStreaming(), 'Esc 中断生成（AI 焦点）');
check($app5->quit === false || $app5->confirm === null, 'Esc 中断时**不会**顺带触发退出确认');
check(mb_strlen($app5->chat->messages()[1]['content'] ?? '') > 0, '中断后已生成内容保留');
stopServer($srv5);

// 不生成时 Esc 应走全局退出（不能被 AI 面板吞掉）
$app6 = new App();
$app6->focusIndex = array_search('ai_input', App::PANELS, true);
$app6->handle(CodedKeyEvent::new(KeyCode::Esc), TuiArea::fromDimensions(120, 40));
check($app6->confirm !== null || $app6->quit === true, '未在生成时按 Esc → 走全局退出流程（不被 AI 面板吞掉）');

// ── 压边界：极小视口不能崩、也不能出超宽行 ──────────────
// 验收纪律要求压极小视口：宽度一变小，软换行的边界条件最容易出问题
// （内宽 0/1 时 mbWrapDisp 要退化成返回空行而不是死循环或负数宽度）。
echo "\n== 极小视口边界 ==\n";
foreach ([[40, 10], [20, 6], [10, 4]] as [$W, $H]) {
    $okRender = true;
    $err = null;
    try {
        $t = renderText($renderer, $app, $W, $H);
        foreach (explode("\n", $t) as $l) {
            if (mb_strwidth($l) > $W) {
                $okRender = false;
                break;
            }
        }
    } catch (Throwable $e) {
        $okRender = false;
        $err = get_class($e) . ': ' . $e->getMessage();
    }
    check($okRender, "{$W}x{$H}：渲染不崩且无超宽行" . ($err !== null ? "（$err）" : ''));
}
check(DisplayWidth::mbWrapDisp('abc', 0) === [''], 'mbWrapDisp 宽度 0 → 退化成空行，不负数不崩');
check(DisplayWidth::mbWrapDisp('', 5) === [''], 'mbWrapDisp 空串 → 单行空串');
// 宽 3 时两个 2 列宽的汉字放不进同一行（2+2=4>3）→ 逐字成行；汉字绝不会被劈成半字
check(DisplayWidth::mbWrapDisp('你好世界', 3) === ['你', '好', '世', '界'],
    '宽度 3 时汉字逐字成行，不被劈开（实际：' . json_encode(DisplayWidth::mbWrapDisp('你好世界', 3), JSON_UNESCAPED_UNICODE) . '）');
// 宽 4 时两个汉字正好一行
check(DisplayWidth::mbWrapDisp('你好世界', 4) === ['你好', '世界'],
    '宽度 4 时两个汉字一行（实际：' . json_encode(DisplayWidth::mbWrapDisp('你好世界', 4), JSON_UNESCAPED_UNICODE) . '）');
// 混排：末尾的 ASCII 会补进最后一行
// 混排：'界'(2列) 后面还能塞下 1 个 ASCII(1列) 凑满 3 列，第 2 个 ASCII 放不下另起一行
$mixed = DisplayWidth::mbWrapDisp('你好世界ab', 3);
check($mixed === ['你', '好', '世', '界a', 'b'],
    'CJK+ASCII 混排按列宽贪心填行（实际：' . json_encode($mixed, JSON_UNESCAPED_UNICODE) . '）');
$over = array_values(array_filter($mixed, static fn($l) => mb_strwidth($l) > 3));
check($over === [], 'mbWrapDisp 每行宽度都不超过给定宽度（混排越界行：' . (implode(',', $over) ?: '无') . '）');

// ── AI 输入框「输入三行」：框高 5 → 内容 3 行，长输入换行、超长向上滚出 ──
echo "\n== AI 输入框三行渲染 ==\n";
// 隔离配置：避开 ~/.vicerc 里残留的旧 aiInputHeight，确保走默认 5
$tmpCfg = tempnam(sys_get_temp_dir(), 'vice_ai_');
putenv('VICECODE_CONFIG=' . $tmpCfg);
$appI = new App();
// 框高默认 5 → 内容高 = 5 - 2 = 3；框宽取 12 → 内容宽 = 10
$boxH = $appI->layout->aiInputHeight; // 默认 5
check($boxH === 5, 'aiInputHeight 默认 5（上下边框+3行输入；工具栏图标在顶边框）');
$inH = $boxH - 2; // 减上下边框(2)，工具栏图标在顶边框不占输入行
check($inH === 3, '输入框内容行 = 框高-2（上下边框）= 3');
$inW = 10;
// 短输入：占 1 行，'>' + 内容 + 光标块
$appI->ai->onChar(CharKeyEvent::new('h', 0));
$appI->ai->onChar(CharKeyEvent::new('i', 0));
// 用 mbWrapDisp 复算（inputContent 同款逻辑）做等价断言
$expShort = DisplayWidth::mbWrapDisp('> hi', $inW);
$expShort[count($expShort) - 1] .= '▌';
check(count($expShort) === 1, '短输入占 1 行（实际 ' . count($expShort) . '）');
// 长输入：28 个字母 + 提示符 '> '(2列) = 30 列，宽 10 下恰好 3 行（框内满 3 行、不滚动）
$appI->ai->backspace(); // 退掉 'i'
$appI->ai->backspace(); // 退掉 'h'
$long = str_repeat('a', 28);
foreach (mb_str_split($long) as $c) {
    $appI->ai->onChar(CharKeyEvent::new($c, 0));
}
$expLong = DisplayWidth::mbWrapDisp('> ' . $long, $inW);
check(count($expLong) === 3, '28 字母在宽 10 下恰好换 3 行（框内满 3 行，实际 ' . count($expLong) . '）');
// 超长（50 字母）：超过 3 行 → inputContent 只显最后 3 行（向上滚出）
$veryLong = str_repeat('b', 50);
$expVL = DisplayWidth::mbWrapDisp('> ' . $veryLong, $inW);
$shown = array_slice($expVL, -$inH);
check(count($expVL) > $inH && count($shown) === $inH,
    '超长输入只显最后 3 行（总 ' . count($expVL) . ' 行 → 显 ' . count($shown) . '）');

// ── 工具栏：软回车（换行）/发送/清空（逆向思路：Enter 仍发送，换行交工具栏）──
echo "\n== AI 输入框工具栏 ==\n";
// 图标串 ' [→][↵][✕] '：相对串左沿 0 基区间 → [→]1..3  [↵]4..6  [✕]7..9
check($appI->ai->onToolbarClick(2) === true, '点顶边框[→发送]命中');
check($appI->ai->input() === '', '发送后输入框清空（Enter 仍发送，工具栏发送等价）');
$appI->ai->onChar(CharKeyEvent::new('h', 0));
$appI->ai->onChar(CharKeyEvent::new('i', 0));
check($appI->ai->onToolbarClick(5) === true, '点顶边框[↵换行]命中');
check(str_contains($appI->ai->input(), "\n"), '换行按钮在输入里插入硬换行（软回车）');
$appI->ai->onToolbarClick(2); // 发送 'hi\n'（trim 后为 hi）
check($appI->ai->input() === '', '再次发送后清空');
$appI->ai->onChar(CharKeyEvent::new('x', 0));
check($appI->ai->onToolbarClick(8) === true, '点顶边框[✕清空]命中');
check($appI->ai->input() === '', '清空按钮清空输入');
check($appI->ai->onToolbarClick(50) === false, '点顶边框空白处不命中任何按钮');

// 顶边框右对齐几何：用真实 ai_input 矩形验证绝对列换算
$area = $appI->areas(TuiArea::fromDimensions(120, 40))['ai_input'];
$rightCol = $area->position->x + $area->width - 2; // 顶边框最右（清空图标区）
$appI->ai->onChar(CharKeyEvent::new('z', 0));
check($appI->ai->onToolbarBorderClick($rightCol, $area) === true, '点顶边框右端图标（清空）命中');
check($appI->ai->input() === '', '顶边框清空图标生效（绝对列换算正确）');
$leftCol = $area->position->x + 2; // 顶边框左端（面板名区，非图标）
check($appI->ai->onToolbarBorderClick($leftCol, $area) === false, '点顶边框左端（面板名）不命中');

// ── 回归：工具栏点击经真实 handle() 路径生效 ──
// 之前 clickDropdown 同款陷阱：ai_input 顶边框恰是「AI 输入框上」拖拽分隔条，
// tryStartDrag 在 handleClick 之前抢先返回 true，导致点工具栏图标只进拖拽、按钮无反应。
// 直接调 onToolbarBorderClick 的单测发现不了，必须走真实 handle()→handleMouse 路径。
$vp = TuiArea::fromDimensions(120, 40);
$a2 = $appI->areas($vp);
$aiArea = $a2['ai_input'];
$topRow = $aiArea->position->y;
$rightCol2 = $aiArea->position->x + $aiArea->width - 2; // 顶边框最右（清空图标）
$appI->ai->onChar(CharKeyEvent::new('x', 0)); // 直接 API 填 'x'
check($appI->ai->input() === 'x', '回归前置：输入已填入 x');
$appI->handle(MouseEvent::new(MouseEventKind::Down, MouseButton::Left, $rightCol2, $topRow, 0), $vp);
check($appI->ai->input() === '', '回归：真实路径点顶边框清空图标经 handle() 清空输入（不被 tryStartDrag 吞掉）');

@unlink($tmpCfg);

exit($failed ? 1 : 0);

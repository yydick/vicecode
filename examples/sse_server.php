<?php
/**
 * M5 流式验证用的 mock 服务端（供 `php -S` 当 router 用）。
 *
 * 刻意用**真 HTTP 进程**而不是把字符串直接喂给解析器：SSE 的难点一半在传输层
 * （chunk 边界与 `data:` 行边界完全无关、稍不留神就被缓冲成一大块）。
 * 用假数据源验证出来的解析器，到真服务商那里照样可能一次性返回。
 *
 * 三个端点：
 *  - GET  /sse?n=8&delay=150                 —— 传输层探针用（只看分块行为）
 *  - POST /v1/chat/completions               —— **仿 OpenAI 的假端点**，端到端验证用
 *     参数：n（token 数）/ delay（间隔 ms）/ reply（自定义回复，用 | 分词）
 *           status（强制返回该 HTTP 状态码，如 401）/ noauth（不校验 Authorization）
 *  - POST /v1/messages                       —— **仿 Anthropic Messages API 的假端点**
 *     同样的开关语义，但事件名是 Anthropic 形态（message_start / content_block_delta /
 *     message_stop 等，**没有 `[DONE]`**）。
 *
 * ⚠️ 两条协议的 frame 函数是分开的（`sseFrame` / `anthropicFrame`）：OpenAI 端点仍依赖
 * `sseFrame` 的形状，改它会连带弄坏既有测试。
 *
 * 用法：php -S 127.0.0.1:<port> examples/sse_server.php
 */
declare(strict_types=1);

/** 关掉各层输出缓冲：不关的话 PHP 会攒够一个 buffer 才发，客户端一次性收完，测不出流式。 */
function disableBuffering(): void
{
    ini_set('output_buffering', 'off');
    ini_set('zlib.output_compression', 'off');
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
}

function sseFrame(array $choice): void
{
    echo 'data: ' . json_encode(['choices' => [$choice]], JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();
}

/**
 * 发一个 Anthropic 形态的 SSE 事件。
 *
 * 官方是 `event: <类型>` + `data: <json>` 两行，这里**照官方发两行**（而不是偷懒只发 data）：
 * 客户端解析器刻意只认 `data:` 行、类型从 data 里的 `"type"` 读，所以两行都发能验证
 * 「`event:` 行的存在不会干扰解析」；反过来只发 data 行也能工作（兼容网关常见）。
 */
function anthropicEvent(array $payload): void
{
    echo 'event: ' . ($payload['type'] ?? 'unknown') . "\n";
    echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();
}

/**
 * 走完 Anthropic 流的一轮：content_block_start(text) → 正文分片 → content_block_stop
 * → message_delta(stop_reason) → message_stop。
 * @param string[] $tokens 正文分片
 */
function anthropicTextStream(array $tokens, int $delayMs, string $stopReason = 'end_turn'): void
{
    anthropicEvent(['type' => 'message_start', 'message' => [
        'id' => 'msg_mock', 'type' => 'message', 'role' => 'assistant',
        'content' => [], 'model' => 'mock', 'stop_reason' => null, 'stop_sequence' => null,
        'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
    ]]);
    usleep($delayMs * 1000);
    anthropicEvent(['type' => 'ping']);
    anthropicEvent(['type' => 'content_block_start', 'index' => 0,
        'content_block' => ['type' => 'text', 'text' => '']]);
    usleep($delayMs * 1000);
    foreach ($tokens as $t) {
        anthropicEvent(['type' => 'content_block_delta', 'index' => 0,
            'delta' => ['type' => 'text_delta', 'text' => $t]]);
        usleep($delayMs * 1000);
    }
    anthropicEvent(['type' => 'content_block_stop', 'index' => 0]);
    anthropicEvent(['type' => 'message_delta',
        'delta' => ['stop_reason' => $stopReason, 'stop_sequence' => null],
        'usage' => ['output_tokens' => 1]]);
    // ⚠️ 官方**不发** `data: [DONE]`，结束就是 message_stop
    anthropicEvent(['type' => 'message_stop']);
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$n = max(0, (int) ($_GET['n'] ?? 8));
$delayMs = max(0, (int) ($_GET['delay'] ?? 150));

// ── GET /sse：传输层探针用 ─────────────────────────────
if ($uri === '/sse') {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    disableBuffering();
    for ($i = 1; $i <= $n; $i++) {
        sseFrame(['delta' => ['content' => sprintf('tok%d ', $i)], 'index' => 0]);
        usleep($delayMs * 1000);
    }
    echo "data: [DONE]\n\n";
    flush();
    return;
}

// ── POST /v1/chat/completions：仿 OpenAI 假端点 ────────
if ($uri === '/v1/chat/completions' && $method === 'POST') {
    // 模拟鉴权：缺 Authorization 就回 401（真服务端行为），便于验证「鉴权失败」与
    // 「模型没话说」在 UI 上能被区分开。noauth=1 可关掉这层校验。
    $wantAuth = !isset($_GET['noauth']) && getenv('MOCK_NOAUTH') !== '1';
    $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if ($wantAuth && !str_starts_with($auth, 'Bearer ')) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => ['message' => 'missing authorization header', 'type' => 'auth_error']]);
        return;
    }

    // 强制状态码：用来验证 -D dump 出来的响应头能被正确解析。
    // 也可以启动服务端时用环境变量设死（MOCK_STATUS=401），这样测试不用改 URL
    // —— 注意 query 参数加不上，因为客户端的 URL 是 base_url + '/chat/completions' 拼出来的。
    $force = (int) ($_GET['status'] ?: (getenv('MOCK_STATUS') ?: 0));
    if ($force > 0) {
        http_response_code($force);
        header('Content-Type: application/json');
        echo json_encode(['error' => ['message' => 'forced status ' . $force, 'type' => 'forced']]);
        return;
    }

    $raw = (string) file_get_contents('php://input');
    $req = json_decode($raw, true);
    if (!is_array($req)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => ['message' => 'invalid json body', 'type' => 'invalid_request']]);
        return;
    }

    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    disableBuffering();

    // MOCK_LOG_FILE=/path：把每次请求用的 model 追加成一行。
    // 用于断言**请求序列**（"哪条消息被路由到了哪个模型"）——比在 pty 画面上找回显硬得多：
    // 消息流会滚动、差分渲染只重发变化格，而服务端日志是纯粹的调用记录。
    $logFile = getenv('MOCK_LOG_FILE');
    if (is_string($logFile) && $logFile !== '') {
        @file_put_contents($logFile, (is_string($req['model'] ?? null) ? $req['model'] : '(none)') . "\n", FILE_APPEND);
    }
    // MOCK_BODY_FILE：把**完整请求体**逐行（JSON 单行）追加。
    // 与 Anthropic 端点（见下方 /v1/messages）同义——两个协议都接上，这个钩子才能通用。
    // ⚠️ 断言"模型收到了什么"必须看**发出去的请求体**，只看回复文案会被"假数据源不看输入"骗过。
    $bodyFile = getenv('MOCK_BODY_FILE');
    if (is_string($bodyFile) && $bodyFile !== '') {
        @file_put_contents($bodyFile, json_encode($req, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
    }

    // ── V2 场景开关（query 加不上——客户端 URL 是 base_url 拼的，故全走 env / 请求体判断）──
    $wantSummary = getenv('MOCK_SUMMARY') === '1';

    // MOCK_ECHO_TOOLS=1：只回一行 `[tools=1]` / `[tools=0]`，用于端到端断言
    // 「本次请求到底带没带 OpenAI tools 协议」（模型能力开关的验收靠它）。
    // 放在 MOCK_TOOLS 之前：这两个开关互斥，echo 只关心请求体。
    if (getenv('MOCK_ECHO_TOOLS') === '1') {
        $hasTools = isset($req['tools']) && is_array($req['tools']) && $req['tools'] !== [];
        sseFrame(['delta' => ['content' => '[tools=' . ($hasTools ? '1' : '0') . ']'], 'index' => 0]);
        usleep($delayMs * 1000);
        sseFrame(['delta' => [], 'index' => 0, 'finish_reason' => 'stop']);
        echo "data: [DONE]\n\n";
        flush();
        return;
    }
    // MOCK_ECHO_MODEL=1：只回一行 `[model=<请求体里的 model>]`。用于端到端断言
    // 「模型策略切换后，请求真的打到了那个模型」——只看状态栏不算数，得看发出去的请求体。
    if (getenv('MOCK_ECHO_MODEL') === '1') {
        $m = is_string($req['model'] ?? null) ? $req['model'] : '(none)';
        sseFrame(['delta' => ['content' => '[model=' . $m . ']'], 'index' => 0]);
        usleep($delayMs * 1000);
        sseFrame(['delta' => [], 'index' => 0, 'finish_reason' => 'stop']);
        echo "data: [DONE]\n\n";
        flush();
        return;
    }
    // MOCK_TOOLS=1：首轮回 tool_calls，收到 role:tool 后回最终文本（Agent loop 正常往返）
    // MOCK_TOOLS=2：永远回 tool_calls（测 maxSteps 上限——模型不停要工具）
    $toolsMode = getenv('MOCK_TOOLS') ?: '0';
    $wantTools = $toolsMode === '1' || $toolsMode === '2';
    $alwaysTools = $toolsMode === '2';
    $hasToolResult = false;
    foreach ((array) ($req['messages'] ?? []) as $m) {
        if (is_array($m) && ($m['role'] ?? null) === 'tool') {
            $hasToolResult = true;
            break;
        }
    }

    // 首帧只带 role（OpenAI 的真实形态），content 为 null —— 解析器必须忽略它而不是产出空串
    sseFrame(['delta' => ['role' => 'assistant'], 'index' => 0]);
    usleep($delayMs * 1000);

    // summary 场景：回固定摘要文本（压缩流程端到端用）
    if ($wantSummary) {
        foreach (['摘要：', '用户问了', ' mock 问题，', '已回答。'] as $t) {
            sseFrame(['delta' => ['content' => $t], 'index' => 0]);
            usleep($delayMs * 1000);
        }
        sseFrame(['delta' => [], 'index' => 0, 'finish_reason' => 'stop']);
        echo "data: [DONE]\n\n";
        flush();
        return;
    }

    // tools 场景第一轮：回一个 tool_calls（arguments 故意拆成 2 帧，专测分片累积）。
    // 第二轮（请求里已带 role:tool 的工具结果）回最终文本——这是 Agent loop 的真实往返。
    if ($wantTools && ($alwaysTools || !$hasToolResult)) {
        // 帧流：先给 id+name+半截 arguments，下一帧补完（OpenAI 真实形态就是分片）
        sseFrame(['delta' => ['tool_calls' => [
            ['index' => 0, 'id' => 'call_mock_1', 'type' => 'function',
             'function' => ['name' => 'read_file', 'arguments' => '{"pa']],
        ]], 'index' => 0]);
        usleep($delayMs * 1000);
        sseFrame(['delta' => ['tool_calls' => [
            ['index' => 0, 'function' => ['arguments' => 'th":"src/Foo.php"}']],
        ]], 'index' => 0]);
        usleep($delayMs * 1000);
        // 第二个工具与第一个**交错**流式（index=1 先出半截，再补完），测多工具归并
        sseFrame(['delta' => ['tool_calls' => [
            ['index' => 1, 'id' => 'call_mock_2', 'type' => 'function',
             'function' => ['name' => 'list_files', 'arguments' => '{"pa']],
        ]], 'index' => 0]);
        usleep($delayMs * 1000);
        sseFrame(['delta' => ['tool_calls' => [
            ['index' => 1, 'function' => ['arguments' => 'th":"src"}']],
        ]], 'index' => 0]);
        usleep($delayMs * 1000);
        sseFrame(['delta' => [], 'index' => 0, 'finish_reason' => 'tool_calls']);
        echo "data: [DONE]\n\n";
        flush();
        return;
    }

    // 回复内容：默认 tok1..tokN；reply=a|b|c 可自定义分词。
    // 启动服务端时用 MOCK_REPLY 也能设（客户端 URL 拼不出 query，见上面 status 的说明）。
    $replyParam = $_GET['reply'] ?? null;
    if (!is_string($replyParam) || $replyParam === '') {
        $replyParam = getenv('MOCK_REPLY') ?: null;
    }
    if ($replyParam !== null && $replyParam !== false && $replyParam !== '') {
        $tokens = explode('|', $replyParam);
    } else {
        $tokens = [];
        for ($i = 1; $i <= max(1, $n); $i++) {
            $tokens[] = sprintf('tok%d ', $i);
        }
    }
    foreach ($tokens as $t) {
        sseFrame(['delta' => ['content' => $t], 'index' => 0]);
        usleep($delayMs * 1000);
    }

    // 结束帧：delta 为空 + finish_reason，再跟 [DONE]
    sseFrame(['delta' => [], 'index' => 0, 'finish_reason' => 'stop']);
    echo "data: [DONE]\n\n";
    flush();
    return;
}

// ── POST /v1/messages：仿 Anthropic Messages API 假端点 ────────────────
// 与 OpenAI 端点的开关**同名同义**（便于同一个测试骨架套两条协议），差异只在
// ① 鉴权头是 `x-api-key` 而非 `Authorization: Bearer`；
// ② 事件形态是 Anthropic 的；③ 请求体必填 `max_tokens`（这里顺带校验，缺失就 400，
//    好让「忘了带 max_tokens」这个真实约束能被端到端测出来）。
if ($uri === '/v1/messages' && $method === 'POST') {
    $wantAuth = !isset($_GET['noauth']) && getenv('MOCK_NOAUTH') !== '1';
    $key = (string) ($_SERVER['HTTP_X_API_KEY'] ?? '');
    if ($wantAuth && $key === '') {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['type' => 'error', 'error' => [
            'type' => 'authentication_error', 'message' => 'missing x-api-key header']]);
        return;
    }
    // `anthropic-version` 是必需头（缺了官方直接 400）—— 顺带断言它真的被带上了
    if ($wantAuth && (string) ($_SERVER['HTTP_ANTHROPIC_VERSION'] ?? '') === '') {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['type' => 'error', 'error' => [
            'type' => 'invalid_request_error', 'message' => 'missing anthropic-version header']]);
        return;
    }

    $force = (int) ($_GET['status'] ?: (getenv('MOCK_STATUS') ?: 0));
    if ($force > 0) {
        http_response_code($force);
        header('Content-Type: application/json');
        echo json_encode(['type' => 'error', 'error' => [
            'type' => 'forced', 'message' => 'forced status ' . $force]]);
        return;
    }

    $raw = (string) file_get_contents('php://input');
    $req = json_decode($raw, true);
    if (!is_array($req)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['type' => 'error', 'error' => [
            'type' => 'invalid_request_error', 'message' => 'invalid json body']]);
        return;
    }
    // max_tokens 必填（Anthropic 的真实约束）
    if (!isset($req['max_tokens']) || !is_int($req['max_tokens']) || $req['max_tokens'] <= 0) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['type' => 'error', 'error' => [
            'type' => 'invalid_request_error', 'message' => 'max_tokens: required']]);
        return;
    }

    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    disableBuffering();

    $logFile = getenv('MOCK_LOG_FILE');
    if (is_string($logFile) && $logFile !== '') {
        @file_put_contents($logFile, (is_string($req['model'] ?? null) ? $req['model'] : '(none)') . "\n", FILE_APPEND);
    }
    // MOCK_BODY_FILE=/path：把**完整请求体**逐行（JSON 单行）追加。
    // 与 MOCK_LOG_FILE 的分工：那个只记"打到了哪个模型"（断言调用序列），这个用来断言
    // "请求里到底带了什么"（如压缩请求有没有把旧历史带上——见 docs/BUGFIXES.md T6）。
    // ⚠️ 断言"模型收到了什么"必须看**发出去的请求体**，只看回复文案会被"假数据源不看输入"骗过。
    $bodyFile = getenv('MOCK_BODY_FILE');
    if (is_string($bodyFile) && $bodyFile !== '') {
        @file_put_contents($bodyFile, json_encode($req, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
    }

    // MOCK_ECHO_MODEL=1：只回一行 `[model=<请求体里的 model>]`（与 OpenAI 端点同义）
    if (getenv('MOCK_ECHO_MODEL') === '1') {
        $m = is_string($req['model'] ?? null) ? $req['model'] : '(none)';
        anthropicTextStream(['[model=' . $m . ']'], $delayMs);
        return;
    }
    // MOCK_ECHO_TOOLS=1：只回一行 `[tools=1]` / `[tools=0]`
    if (getenv('MOCK_ECHO_TOOLS') === '1') {
        $has = isset($req['tools']) && is_array($req['tools']) && $req['tools'] !== [];
        anthropicTextStream(['[tools=' . ($has ? '1' : '0') . ']'], $delayMs);
        return;
    }
    // MOCK_ECHO_SYSTEM=1：把顶层 system 参数回显出来（验证 system 真的在顶层而不是消息里）
    if (getenv('MOCK_ECHO_SYSTEM') === '1') {
        $s = is_string($req['system'] ?? null) ? $req['system'] : '(none)';
        anthropicTextStream(['[system=' . $s . ']'], $delayMs);
        return;
    }

    // MOCK_TOOLS=1：首轮回 tool_use，收到 tool_result 后回最终文本（Agent loop 正常往返）
    // MOCK_TOOLS=2：永远回 tool_use（测 maxSteps 上限）
    $toolsMode = getenv('MOCK_TOOLS') ?: '0';
    $wantTools = $toolsMode === '1' || $toolsMode === '2';
    $alwaysTools = $toolsMode === '2';
    // 工具结果在 Anthropic 里是 user 消息的 content 块（不是独立 role:tool 消息）
    $hasToolResult = false;
    foreach ((array) ($req['messages'] ?? []) as $m) {
        if (!is_array($m) || !is_array($m['content'] ?? null)) {
            continue;
        }
        foreach ($m['content'] as $blk) {
            if (is_array($blk) && ($blk['type'] ?? null) === 'tool_result') {
                $hasToolResult = true;
                break 2;
            }
        }
    }

    anthropicEvent(['type' => 'message_start', 'message' => [
        'id' => 'msg_mock', 'type' => 'message', 'role' => 'assistant',
        'content' => [], 'model' => is_string($req['model'] ?? null) ? $req['model'] : 'mock',
        'stop_reason' => null, 'stop_sequence' => null,
        'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
    ]]);
    usleep($delayMs * 1000);

    if ($wantTools && ($alwaysTools || !$hasToolResult)) {
        // 工具块：content_block_start 带 id/name（inputs 从空对象开始），
        // 参数走 input_json_delta 分片（**故意拆成 2 片**，专测分片累积）
        anthropicEvent(['type' => 'content_block_start', 'index' => 0, 'content_block' => [
            'type' => 'tool_use', 'id' => 'toolu_mock_1', 'name' => 'read_file', 'input' => (object) []]]);
        usleep($delayMs * 1000);
        anthropicEvent(['type' => 'content_block_delta', 'index' => 0, 'delta' => [
            'type' => 'input_json_delta', 'partial_json' => '{"pa']]);
        usleep($delayMs * 1000);
        anthropicEvent(['type' => 'content_block_delta', 'index' => 0, 'delta' => [
            'type' => 'input_json_delta', 'partial_json' => 'th":"src/Foo.php"}']]);
        usleep($delayMs * 1000);
        anthropicEvent(['type' => 'content_block_stop', 'index' => 0]);
        // 第二个工具块（index=1），验证多工具按 index 归并
        anthropicEvent(['type' => 'content_block_start', 'index' => 1, 'content_block' => [
            'type' => 'tool_use', 'id' => 'toolu_mock_2', 'name' => 'list_files', 'input' => (object) []]]);
        usleep($delayMs * 1000);
        anthropicEvent(['type' => 'content_block_delta', 'index' => 1, 'delta' => [
            'type' => 'input_json_delta', 'partial_json' => '{"path":"src"}']]);
        usleep($delayMs * 1000);
        anthropicEvent(['type' => 'content_block_stop', 'index' => 1]);
        anthropicEvent(['type' => 'message_delta',
            'delta' => ['stop_reason' => 'tool_use', 'stop_sequence' => null],
            'usage' => ['output_tokens' => 1]]);
        anthropicEvent(['type' => 'message_stop']);
        return;
    }

    // MOCK_SUMMARY=1：**按输入判断**——请求里带了我们那条压缩指令的特征串才回摘要文本。
    // ⚠️ 早期 OpenAI 端点的 MOCK_SUMMARY 是无条件回的，结果掩盖了「压缩请求根本没带历史」的
    // 真 bug（见 docs/BUGFIXES.md T6）。新端点不再重复这个错误：不看输入的假数据源
    // 会让整条链路的断言失去意义。
    if (getenv('MOCK_SUMMARY') === '1') {
        $all = json_encode($req['messages'] ?? [], JSON_UNESCAPED_UNICODE) ?: '';
        $isCompact = str_contains($all, 'COMPACT') || str_contains($all, '待压缩');
        $tokens = $isCompact
            ? ['摘要：', '用户问了', ' mock 问题，', '已回答。']
            : ['tok1 ', 'tok2 ', 'tok3 '];
        anthropicTextStream($tokens, $delayMs);
        return;
    }

    // 默认回复：tok1..tokN / reply=a|b|c / MOCK_REPLY
    $replyParam = $_GET['reply'] ?? null;
    if (!is_string($replyParam) || $replyParam === '') {
        $replyParam = getenv('MOCK_REPLY') ?: null;
    }
    if (is_string($replyParam) && $replyParam !== '') {
        $tokens = explode('|', $replyParam);
    } else {
        $tokens = [];
        for ($i = 1; $i <= max(1, $n); $i++) {
            $tokens[] = sprintf('tok%d ', $i);
        }
    }
    anthropicTextStream($tokens, $delayMs);
    return;
}

http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['error' => ['message' => 'not found: ' . $uri, 'type' => 'not_found']]);

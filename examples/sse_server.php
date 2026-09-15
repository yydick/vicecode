<?php
/**
 * M5 流式验证用的 mock 服务端（供 `php -S` 当 router 用）。
 *
 * 刻意用**真 HTTP 进程**而不是把字符串直接喂给解析器：SSE 的难点一半在传输层
 * （chunk 边界与 `data:` 行边界完全无关、稍不留神就被缓冲成一大块）。
 * 用假数据源验证出来的解析器，到真服务商那里照样可能一次性返回。
 *
 * 两个端点：
 *  - GET  /sse?n=8&delay=150                 —— 传输层探针用（只看分块行为）
 *  - POST /v1/chat/completions               —— **仿 OpenAI 的假端点**，端到端验证用
 *     参数：n（token 数）/ delay（间隔 ms）/ reply（自定义回复，用 | 分词）
 *           status（强制返回该 HTTP 状态码，如 401）/ noauth（不校验 Authorization）
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

    // ── V2 场景开关（query 加不上——客户端 URL 是 base_url 拼的，故全走 env / 请求体判断）──
    $wantSummary = getenv('MOCK_SUMMARY') === '1';
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

http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['error' => ['message' => 'not found: ' . $uri, 'type' => 'not_found']]);

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

    // 首帧只带 role（OpenAI 的真实形态），content 为 null —— 解析器必须忽略它而不是产出空串
    sseFrame(['delta' => ['role' => 'assistant'], 'index' => 0]);
    usleep($delayMs * 1000);

    // 回复内容：默认 tok1..tokN；reply=a|b|c 可自定义分词
    if (isset($_GET['reply']) && is_string($_GET['reply']) && $_GET['reply'] !== '') {
        $tokens = explode('|', $_GET['reply']);
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

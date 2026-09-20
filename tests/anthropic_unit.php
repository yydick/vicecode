<?php
declare(strict_types=1);

/**
 * Anthropic Messages API 协议层 —— 无终端单测：
 *  1) `AnthropicSseParser` 增量解析（事件名/结束条件与 OpenAI 完全不同，见下）；
 *  2) `AnthropicProvider` 的请求体转换（消息、工具、max_tokens、system 提顶层）；
 *  3) `ConversationTranscript` 的历史文本化（压缩摘要请求的输入）；
 *  4) `ProviderSpec` 的协议与端点解析（两条协议共存）。
 *
 * ⚠️ 本文件**不复用 ai_unit.php 的 frame()**：那是 OpenAI 的 `choices[].delta` 形状，
 * Anthropic 是 `content_block_delta` + `message_stop`，混用会掩盖协议差异。
 *
 * 运行：php tests/anthropic_unit.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/isolation.php';
putenv('APP_LOCALE=zh_CN');

use App\Ai\AnthropicProvider;
use App\Ai\AnthropicSseParser;
use App\Ai\ConversationTranscript;
use App\Ai\OpenAiCompatProvider;
use App\Ai\ProviderRegistry;
use App\Ai\ProviderSpec;

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

/** 抽正文文本 */
function texts(array $events): array
{
    $out = [];
    foreach ($events as $ev) {
        if (($ev['type'] ?? null) === 'delta' && is_string($ev['text'] ?? null)) {
            $out[] = $ev['text'];
        }
    }
    return $out;
}

/** 抽工具增量事件 */
function toolEvents(array $events): array
{
    return array_values(array_filter($events, static fn($e) => ($e['type'] ?? null) === 'tool_delta'));
}

/** 造一个 Anthropic 事件行（官方是 event: + data: 两行） */
function aEvent(string $type, array $payload): string
{
    return 'event: ' . $type . "\n"
        . 'data: ' . json_encode(['type' => $type] + $payload, JSON_UNESCAPED_UNICODE) . "\n\n";
}

// ═══════════════════════════════════════════════════════════
// 1) AnthropicSseParser
// ═══════════════════════════════════════════════════════════
echo "== AnthropicSseParser 增量解析 ==\n";

// ── 完整一轮文本流（照官方文档的事件序列）──
$p = new AnthropicSseParser();
$stream = aEvent('message_start', ['message' => ['id' => 'msg_1', 'content' => []]])
    . aEvent('content_block_start', ['index' => 0, 'content_block' => ['type' => 'text', 'text' => '']])
    . aEvent('ping', [])
    . aEvent('content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Hello']])
    . aEvent('content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => '!']])
    . aEvent('content_block_stop', ['index' => 0])
    . aEvent('message_delta', ['delta' => ['stop_reason' => 'end_turn', 'stop_sequence' => null]])
    . aEvent('message_stop', []);
$evs = $p->push($stream);
check(texts($evs) === ['Hello', '!'], '正文分片按序产出（实际 ' . json_encode(texts($evs)) . '）');
check($p->isDone(), 'message_stop 置 done（Anthropic **不发** [DONE]）');
check($p->error() === null, '正常流无 error');
$fin = array_values(array_filter($evs, static fn($e) => ($e['type'] ?? null) === 'finish'));
check(($fin[0]['reason'] ?? null) === 'end_turn', 'message_delta 的 stop_reason 映射为 finish 事件');

// ★ 结束条件的反向锚点：只发 message_start + 正文、**不发 message_stop** → 不得 done
$pNoStop = new AnthropicSseParser();
$pNoStop->push(aEvent('content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'x']]));
check(!$pNoStop->isDone(), '没有 message_stop 时不得判定结束（结束条件确实绑在 message_stop 上）');

// ── 工具块：content_block_start 带 id/name，参数走 input_json_delta 分片 ──
$p2 = new AnthropicSseParser();
$evs2 = $p2->push(
    aEvent('content_block_start', ['index' => 1, 'content_block' => [
        'type' => 'tool_use', 'id' => 'toolu_9', 'name' => 'read_file', 'input' => []]])
    . aEvent('content_block_delta', ['index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"pa']])
    . aEvent('content_block_delta', ['index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => 'th":"a.php"}']])
);
$te = toolEvents($evs2);
check(count($te) === 3, 'tool_use 块产出 3 个 tool_delta（1 个 start + 2 片参数），实际 ' . count($te));
check(($te[0]['id'] ?? null) === 'toolu_9' && ($te[0]['name'] ?? null) === 'read_file', 'tool_delta 首片带 id 与 name');
check(($te[1]['id'] ?? null) === null && ($te[1]['name'] ?? null) === null, '后续片不带 id/name（与 OpenAI 分片形态一致）');
check(($te[0]['index'] ?? null) === 1 && ($te[2]['index'] ?? null) === 1, 'index 取自事件的 content_block 下标');
$args = '';
foreach ($te as $e) {
    $args .= (string) $e['args_delta'];
}
check($args === '{"path":"a.php"}', 'input_json_delta 分片拼起来是完整 arguments（实际 ' . $args . '）');

// ── 多工具：两个块各自归并 ──
$p3 = new AnthropicSseParser();
$evs3 = $p3->push(
    aEvent('content_block_start', ['index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_a', 'name' => 'read_file']])
    . aEvent('content_block_start', ['index' => 1, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_b', 'name' => 'list_files']])
    . aEvent('content_block_delta', ['index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"p":1}']])
    . aEvent('content_block_delta', ['index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"p":2}']])
);
$byIdx = [];
foreach (toolEvents($evs3) as $e) {
    $i = (int) $e['index'];
    $byIdx[$i] ??= ['id' => null, 'name' => null, 'args' => ''];
    if ($e['id'] !== null) { $byIdx[$i]['id'] = $e['id']; }
    if ($e['name'] !== null) { $byIdx[$i]['name'] = $e['name']; }
    $byIdx[$i]['args'] .= $e['args_delta'];
}
check(($byIdx[0]['id'] ?? null) === 'toolu_a' && ($byIdx[0]['args'] ?? '') === '{"p":2}', 'index=0 归并到自己那块（id 与参数不串台）');
check(($byIdx[1]['id'] ?? null) === 'toolu_b' && ($byIdx[1]['args'] ?? '') === '{"p":1}', 'index=1 归并到自己那块');
check(($byIdx[0]['name'] ?? null) === 'read_file' && ($byIdx[1]['name'] ?? null) === 'list_files', '两块的名字各自正确');

// ── chunk 边界劈开的各类切法（与 ai_unit 同思路，但事件名不同）──
$whole = aEvent('content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => '你好世界']])
    . aEvent('message_stop', []);
$cases = [
    '整体一次喂'   => str_split($whole, strlen($whole)),
    '一字一节'     => str_split($whole, 1),
    '三字节一节'   => str_split($whole, 3),
    '七字节一节'   => str_split($whole, 7),
];
foreach ($cases as $name => $chunks) {
    $pp = new AnthropicSseParser();
    $acc = '';
    foreach ($chunks as $c) {
        foreach (texts($pp->push((string) $c)) as $t) {
            $acc .= $t;
        }
    }
    check($acc === '你好世界', "chunk 切法「{$name}」下多字节正文不损坏（实际 " . var_export($acc, true) . '）');
    check($pp->isDone(), "chunk 切法「{$name}」下仍能识别 message_stop");
}

// ★ 半行不外泄：把一个汉字劈成两半分别喂，不得产出替换符
$pSplit = new AnthropicSseParser();
$oneChar = aEvent('content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => '中']]);
$cut = (int) floor(strlen($oneChar) * 0.6);
$out = texts($pSplit->push(substr($oneChar, 0, $cut)));
$out = array_merge($out, texts($pSplit->push(substr($oneChar, $cut))));
check($out === ['中'], '半行保持原始字节：从多字节字符中间劈开也能正确拼回（实际 ' . json_encode($out) . '）');
check(!in_array("\u{FFFD}", $out, true), '不产生 U+FFFD 替换符');

// ── flush()：最后一行没有换行符时也要解析 ──
$pNoNl = new AnthropicSseParser();
$noNl = rtrim(aEvent('content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => '尾部']]), "\n");
check(texts($pNoNl->push($noNl)) === [], '无结尾换行时 push() 暂不产出（等 flush）');
check(texts($pNoNl->flush()) === ['尾部'], 'flush() 解析残留的最后一行');

// ── 错误事件 ──
$pErr = new AnthropicSseParser();
$pErr->push('event: error' . "\n" . 'data: ' . json_encode([
    'type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']]) . "\n\n");
check($pErr->error() === 'Overloaded', '流内 error 事件记下 message（实际 ' . var_export($pErr->error(), true) . '）');
check($pErr->isDone(), 'error 事件后流结束（不再等 message_stop）');

// ── 坏数据不炸 ──
$pBad = new AnthropicSseParser();
$pBad->push("data: {坏 JSON\n\n");
check($pBad->error() !== null, '坏 JSON 记 error 而不抛异常');
check(!$pBad->isDone(), '坏 JSON 不判定流结束（一条坏数据不该让回答消失）');

// ── 未知事件类型优雅忽略（官方版本策略要求）──
$pUnknown = new AnthropicSseParser();
$evsU = $pUnknown->push(aEvent('some_future_event', ['whatever' => 1]));
check($evsU === [], '未知事件类型被忽略而不是报错');
check(!$pUnknown->isDone(), '未知事件类型不结束流');

// ── 只认 data 行：单发 data（无 event 行，某些兼容网关如此）也要工作 ──
$pDataOnly = new AnthropicSseParser();
$a = $pDataOnly->push('data: ' . json_encode(['type' => 'content_block_delta', 'index' => 0,
    'delta' => ['type' => 'text_delta', 'text' => 'ok']]) . "\n\n");
check(texts($a) === ['ok'], '只有 data 行（无 event 行）时同样能解析出正文');
$b = $pDataOnly->push('data: ' . json_encode(['type' => 'message_stop']) . "\n\n");
check($pDataOnly->isDone(), '只有 data 行的 message_stop 同样能结束流');

// ── done 后忽略后续字节 ──
$pDone = new AnthropicSseParser();
$pDone->push(aEvent('message_stop', []));
check($pDone->push(aEvent('content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => '迟到']])) === [],
    'done 之后的字节被忽略');

// ═══════════════════════════════════════════════════════════
// 2) AnthropicProvider：请求体转换
// ═══════════════════════════════════════════════════════════
echo "\n== AnthropicProvider 消息转换 ==\n";

// ── system 提到顶层（Messages API 没有 system 角色）──
[$sys, $msgs] = AnthropicProvider::toMessages([
    ['role' => 'system', 'content' => '你是助手'],
    ['role' => 'user', 'content' => '你好'],
]);
check($sys === '你是助手', 'system 消息被提到顶层（实际 ' . var_export($sys, true) . '）');
check(count($msgs) === 1 && ($msgs[0]['role'] ?? null) === 'user', 'system 不出现在 messages 里（实际 ' . count($msgs) . ' 条）');

// ── 纯文本：content 保持字符串简写 ──
$q = AnthropicProvider::toMessages([
    ['role' => 'user', 'content' => '第一轮'],
    ['role' => 'assistant', 'content' => '回答一'],
    ['role' => 'user', 'content' => '第二轮'],
]);
check(count($q[1]) === 3, '多轮历史全部带上');
check(($q[1][0]['content'] ?? null) === '第一轮' && ($q[1][2]['content'] ?? null) === '第二轮', '纯文本 content 保持字符串');
check(($q[1][1]['role'] ?? null) === 'assistant', 'assistant 消息角色正确');

// ── tool_calls → tool_use 内容块 ──
[$sys2, $m2] = AnthropicProvider::toMessages([
    ['role' => 'user', 'content' => '看文件'],
    ['role' => 'assistant', 'content' => '好的', 'tool_calls' => [
        ['id' => 'toolu_1', 'type' => 'function', 'function' => ['name' => 'read_file', 'arguments' => '{"path":"a.php"}']],
        ['id' => 'toolu_2', 'type' => 'function', 'function' => ['name' => 'list_files', 'arguments' => '{}']],
    ]],
    ['role' => 'tool', 'tool_call_id' => 'toolu_1', 'content' => 'FILE a.php: …'],
    ['role' => 'tool', 'tool_call_id' => 'toolu_2', 'content' => 'FILES: …'],
]);
$asst = $m2[1] ?? [];
check(($asst['role'] ?? null) === 'assistant' && is_array($asst['content'] ?? null), 'assistant 带 tool_calls → content 变成块数组');
$blocks = $asst['content'] ?? [];
check(count($blocks) === 3, '块 = 1 个 text + 2 个 tool_use（实际 ' . count($blocks) . '）');
check(($blocks[0]['type'] ?? null) === 'text' && ($blocks[0]['text'] ?? null) === '好的', '正文作为首个 text 块保留');
check(($blocks[1]['type'] ?? null) === 'tool_use' && ($blocks[1]['id'] ?? null) === 'toolu_1', 'tool_use 块带 id');
check(($blocks[1]['name'] ?? null) === 'read_file', 'tool_use 块带 name（从 function.name 提取）');
check(is_object($blocks[1]['input'] ?? null), 'tool_use 的 input 是**对象**（不是数组）——Anthropic 要求对象');
check(($blocks[1]['input']->path ?? null) === 'a.php', 'arguments 解码成 input 的字段');
// ★ input 必须是对象：空参数时 json_encode 要出 {} 而不是 []
$asstJson = json_encode($asst, JSON_UNESCAPED_SLASHES);
check(str_contains($asstJson, '"input":{}'), '空参数的 input 序列化为对象 {}（不是数组 []）');

// ── 连续 role:tool 合并进**同一条** user 消息 ──
$userMsgs = array_values(array_filter($m2, static fn($x) => ($x['role'] ?? '') === 'user'));
check(count($userMsgs) === 2, '两条工具结果合并成一条 user（不各成一条）；实际 user 消息数 ' . count($userMsgs));
$trGroup = $m2[2] ?? [];
check(($trGroup['role'] ?? null) === 'user' && count($trGroup['content'] ?? []) === 2, '工具结果组里有 2 个块');
check(($trGroup['content'][0]['type'] ?? null) === 'tool_result', '块类型是 tool_result');
check(($trGroup['content'][0]['tool_use_id'] ?? null) === 'toolu_1', 'tool_result 靠 tool_use_id 关联（不是 OpenAI 的 tool_call_id）');
check(($trGroup['content'][1]['tool_use_id'] ?? null) === 'toolu_2', '第二个 tool_result 的 id 正确');

// ── 分隔开的 role:tool 不得合并（中间隔了别的消息就是两条 user）──
[, $mSep] = AnthropicProvider::toMessages([
    ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 't1', 'function' => ['name' => 'read_file', 'arguments' => '{}']]]],
    ['role' => 'tool', 'tool_call_id' => 't1', 'content' => 'r1'],
    ['role' => 'user', 'content' => '继续'],
    ['role' => 'tool', 'tool_call_id' => 't2', 'content' => 'r2'],
]);
$uCount = count(array_filter($mSep, static fn($x) => ($x['role'] ?? '') === 'user'));
check($uCount === 3, '被 user 消息隔开的 tool 结果各自成条（不跨消息合并）；实际 ' . $uCount);

// ── meta 私有键不进 wire ──
[, $mMeta] = AnthropicProvider::toMessages([
    ['role' => 'tool', 'tool_call_id' => 't1', 'content' => 'r', 'meta' => ['display' => '内部摘要', 'kind' => 'tool_result']],
]);
check(!str_contains(json_encode($mMeta, JSON_UNESCAPED_UNICODE) ?: '', '内部摘要'), 'meta 的 display 不进 wire');

// ── 空 assistant 占位符必须丢掉（Anthropic text 块最小长度 1，原样发会 400）──
// 这个占位符是 ChatModel::startRequest() 追加的（让流式 delta 有地方落），不是真实内容。
[, $mEmpty] = AnthropicProvider::toMessages([
    ['role' => 'user', 'content' => '问题'],
    ['role' => 'assistant', 'content' => ''],   // ← 内部占位符
]);
check(count($mEmpty) === 1 && ($mEmpty[0]['role'] ?? null) === 'user',
    '空 assistant 占位符被丢掉，不发空的 text 块（实际 ' . count($mEmpty) . ' 条）');
check(($mEmpty[array_key_last($mEmpty)]['role'] ?? null) === 'user',
    '丢掉后请求以 user 结尾（官方期望的"生成下一轮"形状）');
check(!str_contains(json_encode($mEmpty, JSON_UNESCAPED_UNICODE) ?: '', '"content":""'),
    'wire 上没有空的 content 字符串');

// 反面对照：**带 tool_calls 的空 assistant 不算占位符**，必须保留（否则工具对会断）
[, $mCalls] = AnthropicProvider::toMessages([
    ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 't9', 'function' => ['name' => 'read_file', 'arguments' => '{}']]]],
    ['role' => 'tool', 'tool_call_id' => 't9', 'content' => 'r'],
]);
check(count($mCalls) === 2 && ($mCalls[0]['role'] ?? null) === 'assistant',
    '带 tool_calls 的空 assistant 被保留（tool_use/tool_result 成对约束不能破）');

// 反面对照：有正文的 assistant 当然保留
[, $mText] = AnthropicProvider::toMessages([
    ['role' => 'user', 'content' => 'q'],
    ['role' => 'assistant', 'content' => '正文'],
]);
check(count($mText) === 2, '有内容的 assistant 正常保留（丢弃只针对空占位符）');

// ═══════════════════════════════════════════════════════════
// 3) 工具定义转换（OpenAI 形状 → input_schema）
// ═══════════════════════════════════════════════════════════
echo "\n== 工具定义转换 ==\n";

$openaiTools = [[
    'type' => 'function',
    'function' => [
        'name' => 'read_file',
        'description' => 'Read a file',
        'parameters' => ['type' => 'object', 'properties' => ['path' => ['type' => 'string']], 'required' => ['path']],
    ],
]];
$aTools = AnthropicProvider::toTools($openaiTools);
check(count($aTools) === 1, '一个工具转成一个（实际 ' . count($aTools) . '）');
check(($aTools[0]['name'] ?? null) === 'read_file', '保留 name');
check(($aTools[0]['description'] ?? null) === 'Read a file', '保留 description');
check(($aTools[0]['input_schema']['properties']['path']['type'] ?? null) === 'string', 'parameters 变成 input_schema（同一份 schema）');
check(!isset($aTools[0]['type']), '丢掉 OpenAI 的 type:function 外壳');
check(!isset($aTools[0]['function']), '丢掉 function 包裹层');
check(AnthropicProvider::toTools(null) === [] && AnthropicProvider::toTools([]) === [], 'null / 空数组 → 无工具');

// ── 内置的两个真实工具也要能转（防止 AiTools 改形状后这里悄悄坏掉）──
$aReal = AnthropicProvider::toTools(\App\Ai\AiTools::toolDefs());
check(count($aReal) === 2, 'AiTools::toolDefs() 的两个工具都转出来了（实际 ' . count($aReal) . '）');
$names = array_column($aReal, 'name');
check(in_array('list_files', $names, true) && in_array('read_file', $names, true), '两个只读工具的名字都在');

// ═══════════════════════════════════════════════════════════
// 4) buildCommand：命令行、头、字节安全
// ═══════════════════════════════════════════════════════════
echo "\n== buildCommand ==\n";

putenv('ANTHROPIC_TEST_KEY=sk-ant-secret-xyz');
$spec = new ProviderSpec(
    id: 'anthropic', label: 'Anthropic',
    baseUrl: 'https://api.anthropic.com', apiKey: 'sk-ant-secret-xyz',
    models: ['claude-sonnet-4-6'], model: 'claude-sonnet-4-6',
    keyEnv: 'ANTHROPIC_TEST_KEY', capabilities: ['tools'],
    protocol: ProviderSpec::PROTOCOL_ANTHROPIC, maxTokens: 2048,
);
$prov = new AnthropicProvider();
$cmd = $prov->buildCommand($spec, [['role' => 'user', 'content' => '你好']], 60, $openaiTools);

check(str_contains($cmd, 'curl -N '), '命令带 -N（关 curl 缓冲，保持真流式）');
check(str_contains($cmd, '--max-time 60'), '带总超时');
check(str_contains($cmd, escapeshellarg('https://api.anthropic.com/v1/messages')), '端点拼成 /v1/messages');
check(!str_contains($cmd, 'chat/completions'), '命令里不含 OpenAI 端点的路径');
check(str_contains($cmd, "--data-binary '@"), '请求体走 @文件（长对话不撞 ARG_MAX）');
check(str_contains($cmd, "-H '@"), '请求头走 @文件');
// ★ 安全：key 绝不能出现在 argv（`ps` 对同机其他用户可见）
check(!str_contains($cmd, 'sk-ant-secret-xyz'), 'API key **不出现**在命令行');

preg_match("/--data-binary '(@[^']+)'/", $cmd, $m);
$bodyPath = substr($m[1] ?? '', 1);
check(is_file($bodyPath), '请求体临时文件存在');
$dec = json_decode((string) @file_get_contents($bodyPath), true);
check(is_array($dec), '请求体是合法 JSON');
check(($dec['model'] ?? null) === 'claude-sonnet-4-6', '请求体含 model');
check(($dec['stream'] ?? null) === true, '请求体 stream=true');
check(($dec['max_tokens'] ?? null) === 2048, '请求体含 max_tokens（Anthropic 必填），实际 ' . var_export($dec['max_tokens'] ?? null, true));
check(!array_key_exists('system', $dec), '没有 system 消息时不带空的 system 字段');
check(($dec['messages'][0]['content'] ?? null) === '你好', '中文不被转义破坏');
check(!array_key_exists('tool_calls', $dec['messages'][0] ?? []), 'wire 上没有 OpenAI 的 tool_calls 键');

preg_match("/-H '(@[^']+)'/", $cmd, $mh);
$hdr = (string) @file_get_contents(substr($mh[1] ?? '', 1));
check(str_contains($hdr, 'x-api-key: sk-ant-secret-xyz'), 'key 在临时文件的 x-api-key 头里');
check(str_contains($hdr, 'anthropic-version: ' . AnthropicProvider::API_VERSION), '带 anthropic-version 必需头');
check(!str_contains($hdr, 'Authorization: Bearer'), '不用 OpenAI 的 Authorization: Bearer 头（key 在文件里，不算 argv 泄露）');
check(str_contains($hdr, 'Accept: text/event-stream'), 'Accept: text/event-stream 存在');

// ── max_tokens 缺失即必须失败：默认 spec 也要带（不能是 0/null）──
$specDefault = new ProviderSpec(
    id: 'a', label: 'A', baseUrl: 'https://api.anthropic.com', apiKey: 'k',
    models: ['m'], model: 'm',
);
check($specDefault->maxTokens === ProviderSpec::DEFAULT_MAX_TOKENS && $specDefault->maxTokens > 0,
    '未声明 max_tokens 时取默认值且为正数（实际 ' . $specDefault->maxTokens . '）');
$prov2 = new AnthropicProvider();
$cmd2 = $prov2->buildCommand($specDefault, [['role' => 'user', 'content' => 'x']], 30);
preg_match("/--data-binary '(@[^']+)'/", $cmd2, $m2b);
$dec2 = json_decode((string) @file_get_contents(substr($m2b[1] ?? '', 1)), true);
check(is_int($dec2['max_tokens'] ?? null) && $dec2['max_tokens'] > 0, '默认路径上 max_tokens 也真的发出去了');

// ── 带 tools 时 body 含转换后的定义 ──
$prov3 = new AnthropicProvider();
$cmd3 = $prov3->buildCommand($spec, [['role' => 'user', 'content' => 'x']], 30, $openaiTools);
preg_match("/--data-binary '(@[^']+)'/", $cmd3, $m3);
$dec3 = json_decode((string) @file_get_contents(substr($m3[1] ?? '', 1)), true);
check(($dec3['tools'][0]['input_schema']['type'] ?? null) === 'object', '带 tools 时 wire 上是 input_schema 形状');
check(!isset($dec3['tools'][0]['function']), '带 tools 时不带 OpenAI 的 function 包裹层');

// ── 不带 tools 时 body 无 tools 键 ──
$prov4 = new AnthropicProvider();
$cmd4 = $prov4->buildCommand($spec, [['role' => 'user', 'content' => 'x']], 30);
preg_match("/--data-binary '(@[^']+)'/", $cmd4, $m4);
$dec4 = json_decode((string) @file_get_contents(substr($m4[1] ?? '', 1)), true);
check(!array_key_exists('tools', $dec4), '不带 tools 时 body 无 tools 键');

// ── system 真的被带到顶层 ──
$prov5 = new AnthropicProvider();
$cmd5 = $prov5->buildCommand($spec, [['role' => 'system', 'content' => 'SYS'], ['role' => 'user', 'content' => 'x']], 30);
preg_match("/--data-binary '(@[^']+)'/", $cmd5, $m5);
$dec5 = json_decode((string) @file_get_contents(substr($m5[1] ?? '', 1)), true);
check(($dec5['system'] ?? null) === 'SYS', 'system 消息进了顶层 system 参数');
check(count($dec5['messages'] ?? []) === 1, 'system 不在 messages 数组里');

// ── 临时文件生命周期（与 OpenAI 版同一套约束）──
$meta = $prov->metaFile();
check(is_string($meta) && is_file($meta), 'meta（-D dump）文件存在');
$oldBody = $bodyPath;
$prov->buildCommand($spec, [['role' => 'user', 'content' => 'y']], 30);
check(!is_file($oldBody), '再次构造请求时清理上一次的临时文件（不泄漏）');
$p6 = new AnthropicProvider();
$p6->buildCommand($spec, [['role' => 'user', 'content' => 'z']], 30);
$pf = $p6->metaFile();
$p6->cleanup();
check(!is_file((string) $pf), 'cleanup() 删除临时文件');
$p6->cleanup();
check(true, 'cleanup() 可重复调用（幂等）');

// ── httpStatus 从 dump 内容解析（两协议共用同一实现，这里确认 Anthropic 路径也能用）──
$mm = vc_tmp_file('vc_anth_meta');
file_put_contents($mm, "HTTP/1.1 401 Unauthorized\r\nx-request-id: abc\r\n\r\n");
check($p6->httpStatus($mm) === 401, 'httpStatus() 从 dump 里读出 401');
check($p6->httpStatus('/nonexistent/path/xyz') === null, '文件不存在 → null（不抛）');

// ═══════════════════════════════════════════════════════════
// 5) ConversationTranscript：压缩摘要请求的输入
// ═══════════════════════════════════════════════════════════
echo "\n== ConversationTranscript ==\n";

$t = ConversationTranscript::render([
    ['role' => 'user', 'content' => '帮我看看 Foo.php'],
    ['role' => 'assistant', 'content' => '', 'tool_calls' => [
        ['id' => 't1', 'function' => ['name' => 'read_file', 'arguments' => '{"path":"src/Foo.php"}']],
    ]],
    ['role' => 'tool', 'tool_call_id' => 't1', 'content' => 'FILE src/Foo.php: <?php ...', 'meta' => ['display' => 'read_file(src/Foo.php) ✓']],
    ['role' => 'assistant', 'content' => '这个文件是入口。'],
]);
check(str_contains($t, 'User: 帮我看看 Foo.php'), 'user 行有角色前缀');
check(str_contains($t, 'Assistant: 这个文件是入口。'), 'assistant 行有角色前缀');
check(str_contains($t, 'read_file') && str_contains($t, 'src/Foo.php'), '工具调用以可读形式出现（名字 + 参数）');
check(str_contains($t, '[tool result]') && str_contains($t, '<?php'), '工具结果以可读形式出现');
check(!str_contains($t, 'meta'), '私有 meta 不出现在文本里');
check(!str_contains($t, 'read_file(src/Foo.php) ✓'), 'meta.display 不泄漏进文本（只用了 tool 消息的 content）');

// ── 空内容也保留角色行（"这里有一轮"本身是摘要需要的信息）──
$t2 = ConversationTranscript::render([['role' => 'assistant', 'content' => '']]);
check(str_contains($t2, 'Assistant:'), '空 assistant 仍保留角色行');
check(str_contains($t2, '(empty)'), '空内容标为 (empty)');

// ── 超长内容截断（压缩请求本身不该撑爆上下文）──
$t3 = ConversationTranscript::render([['role' => 'user', 'content' => str_repeat('长', 100)]], 20);
check(mb_substr_count($t3, '长') <= 20, '单条内容按字符数截断（实际 ' . mb_substr_count($t3, '长') . ' 个）');
check(str_contains($t3, 'truncated'), '截断有明确标注（否则摘要模型会以为内容就这么短）');
check(mb_check_encoding($t3, 'UTF-8'), '截断不劈坏 UTF-8（截断按字符而非字节）');

check(ConversationTranscript::render([]) === '', '空历史 → 空串（调用方据此判断无内容可压缩）');

// ═══════════════════════════════════════════════════════════
// 6) ProviderSpec / ProviderRegistry：两条协议共存
// ═══════════════════════════════════════════════════════════
echo "\n== 协议解析 ==\n";

check((new ProviderSpec('x', 'X', 'https://api.test/v1', 'k', ['m'], 'm'))->protocol === ProviderSpec::PROTOCOL_OPENAI,
    'ProviderSpec 默认协议是 openai（老配置零迁移）');
$specAnth = new ProviderSpec('x', 'X', 'https://api.anthropic.com', 'k', ['m'], 'm',
    protocol: ProviderSpec::PROTOCOL_ANTHROPIC);
check($specAnth->chatUrl() === 'https://api.anthropic.com/v1/messages', 'Anthropic 端点补 /v1/messages');
check(!$specAnth->chatUrl() === false && !str_contains($specAnth->chatUrl(), 'chat/completions'), 'Anthropic 端点不含 chat/completions');
// 容错：用户按 OpenAI 惯例把 /v1 写进了 base_url
$specAnthSlash = new ProviderSpec('x', 'X', 'https://api.anthropic.com/v1/', 'k', ['m'], 'm',
    protocol: ProviderSpec::PROTOCOL_ANTHROPIC);
check($specAnthSlash->chatUrl() === 'https://api.anthropic.com/v1/messages', 'base_url 带 /v1（或尾斜杠）时不重复拼一层');
check($specAnth->isAnthropic() && !(new ProviderSpec('x', 'X', 'https://o/v1', 'k', ['m'], 'm'))->isAnthropic(), 'isAnthropic() 只对 anthropic 协议为真');

// ── Registry 读 protocol / max_tokens（宽容解析）──
$reg = new ProviderRegistry([
    'def'   => ['label' => 'D', 'key_env' => 'K', 'base_url' => 'https://d/v1', 'models' => ['m'], 'model' => 'm'],
    'anth'  => ['label' => 'A', 'protocol' => 'anthropic', 'base_url' => 'https://api.anthropic.com',
                'models' => ['m'], 'model' => 'm', 'max_tokens' => 8192],
    'upper' => ['label' => 'U', 'protocol' => 'ANTHROPIC', 'base_url' => 'https://api.anthropic.com',
                'models' => ['m'], 'model' => 'm'],
    'junk'  => ['label' => 'J', 'protocol' => 'not-a-protocol', 'base_url' => 'https://j/v1', 'models' => ['m'], 'model' => 'm'],
    'zero'  => ['label' => 'Z', 'protocol' => 'anthropic', 'base_url' => 'https://api.anthropic.com',
                'models' => ['m'], 'model' => 'm', 'max_tokens' => 0],
    'str'   => ['label' => 'S', 'protocol' => 'anthropic', 'base_url' => 'https://api.anthropic.com',
                'models' => ['m'], 'model' => 'm', 'max_tokens' => '3000'],
], null, '');

check($reg->spec('def')?->protocol === ProviderSpec::PROTOCOL_OPENAI, '未写 protocol → openai');
check($reg->spec('anth')?->protocol === ProviderSpec::PROTOCOL_ANTHROPIC, '写了 anthropic → anthropic');
check($reg->spec('anth')?->maxTokens === 8192, 'provider 级 max_tokens 生效');
check($reg->spec('upper')?->protocol === ProviderSpec::PROTOCOL_ANTHROPIC, 'protocol 大小写不敏感（Anthropic/ANTHROPIC 都认）');
check($reg->spec('junk')?->protocol === ProviderSpec::PROTOCOL_OPENAI, '未知协议串退回 openai（不静默失败成另一种协议）');
check($reg->spec('zero')?->maxTokens === ProviderSpec::DEFAULT_MAX_TOKENS, 'max_tokens=0 → 用默认值（不能把输出上限搞成 0）');
check($reg->spec('str')?->maxTokens === 3000, "max_tokens 写字符串 '3000' 也接受（配置是人手写的）");
check($reg->spec('def')?->maxTokens === ProviderSpec::DEFAULT_MAX_TOKENS, '未写 max_tokens → 默认值');

// ── 两条协议**不共用** provider 实例（协议决定实现，不能串）──
$openaiProv = new OpenAiCompatProvider();
$anthProv = new AnthropicProvider();
$cmdO = $openaiProv->buildCommand($reg->spec('def'), [['role' => 'user', 'content' => 'x']], 30);
$cmdA = $anthProv->buildCommand($reg->spec('anth'), [['role' => 'user', 'content' => 'x']], 30);
check(str_contains($cmdO, '/chat/completions'), 'OpenAI 实现打到 chat/completions');
check(str_contains($cmdA, '/v1/messages'), 'Anthropic 实现打到 /v1/messages');
check($openaiProv instanceof App\Ai\ProviderInterface && $anthProv instanceof App\Ai\ProviderInterface,
    '两个实现都满足 ProviderInterface 契约');

// ─────────────── 结论 ───────────────
echo "\n";
if ($failed) {
    echo "RESULT: FAIL\n";
    exit(1);
}
echo "RESULT: PASS（Anthropic 协议层全部通过）\n";

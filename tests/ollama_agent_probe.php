<?php
declare(strict_types=1);

/**
 * Ollama 本地模型接入探针（真机联调用，文件名含 probe 不进跑批）：
 *  1) 配置链：ProviderRegistry 读到 ~/.vicecode.providers.php 的 ollama 段，
 *     key_optional 生效（无 OLLAMA_API_KEY 也能 hasKey）+ tools 能力；
 *  2) 协议链：OpenAiCompatProvider::buildCommand + 真实 curl + SseParser ——
 *     打本机 lukaspetrik/gemma3-tools:4b，验证流式与结构化 tool_calls；
 *  3) Agent loop：工具触发 → AiTools 真执行 → 二轮带结果 → 最终文本回复。
 *
 * 运行：php tests/ollama_agent_probe.php   （需要本机 ollama serve 在跑）
 */

require __DIR__ . '/../vendor/autoload.php';
// ⚠️ 故意不调 vc_isolate_config：它会把 VICECODE_PROVIDERS_CONFIG 指进隔离目录，
// 探针要读的正是真实家目录的 ~/.vicecode.providers.php（Ollama provider 写在那里）。
putenv('APP_LOCALE=zh_CN');

use App\Ai\AiTools;
use App\Ai\OpenAiCompatProvider;
use App\Ai\ProviderRegistry;
use App\Ai\SseParser;

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

$MODEL = 'lukaspetrik/gemma3-tools:4b';

// ─────────────── 1) 配置链 ───────────────
echo "== 配置链 ==\n";
$reg = new ProviderRegistry();          // 默认从文件读：config/providers.php + 用户级
check(in_array('ollama', $reg->ids(), true), '用户级 ollama provider 已注册（ids: ' . implode(',', $reg->ids()) . '）');
$spec = $reg->spec('ollama');
check($spec !== null, 'spec 解析成功');
if ($spec === null) {
    echo "探针 FAIL（配置链断）\n";
    exit(1);
}
check($spec->hasKey(), 'key_optional 生效：无 OLLAMA_API_KEY 也 hasKey()=true');
check($spec->supportsTools(), 'gemma3-tools:4b 声明了 tools 能力');
check($spec->chatUrl() === 'http://127.0.0.1:11434/v1/chat/completions', 'chatUrl 正确（' . $spec->chatUrl() . '）');
check($spec->model === $MODEL, '默认模型 = ' . $spec->model);

// ─────────────── 2) 协议链：一轮带 tools 的流式请求 ───────────────
echo "\n== 协议链（真 curl + SSE）==\n";
$provider = new OpenAiCompatProvider();
$messages = [
    ['role' => 'system', 'content' => 'You are a coding assistant. When the user asks about files, ALWAYS call the list_files tool first. Reply in Chinese.'],
    ['role' => 'user', 'content' => 'List the files in the project root. Use the list_files tool with path ".".'],
];
$cmd = $provider->buildCommand($spec, $messages, 120, AiTools::toolDefs());
$raw = (string) shell_exec($cmd . ' 2>/dev/null');
check($raw !== '', 'curl 收到响应流（' . strlen($raw) . ' 字节）');

$parser = new SseParser();
$text = '';
$toolName = null;
$toolArgs = '';
$finish = null;
foreach ($parser->push($raw) as $ev) {
    if ($ev['type'] === 'delta') {
        $text .= $ev['text'];
    } elseif ($ev['type'] === 'tool_delta') {
        $toolName = $ev['name'] ?? $toolName;
        $toolArgs .= $ev['args_delta'];
    } elseif ($ev['type'] === 'finish') {
        $finish = $ev['reason'];
    }
}
$parser->flush();
echo "    finish=$finish text=" . json_encode(mb_substr($text, 0, 60)) . " tool=$toolName args=" . json_encode($toolArgs) . "\n";
check($finish === 'tool_calls' && $toolName === 'list_files', '流式解析出结构化 tool_calls（list_files）');

// ─────────────── 3) Agent loop：真执行工具 + 二轮 ───────────────
echo "\n== Agent loop ==\n";
$args = json_decode($toolArgs === '' ? '{}' : $toolArgs, true) ?: [];
$path = is_array($args) ? ($args['path'] ?? '.') : '.';
$tools = new AiTools(getcwd() ?: '.');
$out = $tools->listFiles(is_string($path) ? $path : '.');
$result = is_array($out) ? (string) ($out['text'] ?? '') : '';
check(is_string($result) && $result !== '', 'AiTools 真执行 list_files（' . strlen($result) . ' 字节）');

// 二轮：assistant 的 tool_calls + tool 结果回填（OpenAI 形状）
$messages[] = ['role' => 'assistant', 'content' => $text !== '' ? $text : null, 'tool_calls' => [
    ['id' => 'call_probe1', 'type' => 'function', 'function' => ['name' => 'list_files', 'arguments' => $toolArgs === '' ? '{}' : $toolArgs]],
]];
$messages[] = ['role' => 'tool', 'tool_call_id' => 'call_probe1', 'content' => $result];
$cmd2 = $provider->buildCommand($spec, $messages, 120, AiTools::toolDefs());
$raw2 = (string) shell_exec($cmd2 . ' 2>/dev/null');
echo "    二轮原始流: ", strlen($raw2), " 字节, head=", json_encode(mb_substr($raw2, 0, 120)), "\n";
$parser2 = new SseParser();
$final = '';
foreach ($parser2->push($raw2) as $ev) {
    if ($ev['type'] === 'delta') {
        $final .= $ev['text'];
    }
}
$parser2->flush();
echo "    二轮回复: " . json_encode(mb_substr($final, 0, 120)) . "\n";
check(mb_strlen(trim($final)) > 0, '二轮产出最终文本回复（Agent loop 闭环）');

echo $failed ? "\nOllama 探针 FAIL\n" : "\nOllama 探针全部 PASS（本地模型可接管 Agent loop）\n";
exit($failed ? 1 : 0);

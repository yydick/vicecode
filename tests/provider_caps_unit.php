<?php
declare(strict_types=1);

/**
 * 模型能力（capabilities）声明 —— headless 单测。
 *
 * 覆盖三块：
 *  1) **配置解析**：模型级能力 / provider 级默认 / 旧字符串列表兼容 / 未声明默认 ['tools'] /
 *     显式 `[]`（确实无能力）/ 裸串 / 去重与脏值容错 / 未知模型退回默认；
 *  2) **行为开关**：只有声明了 `tools` 的模型，请求里才带 OpenAI `tools` 协议——用 mock 服务端的
 *     `MOCK_ECHO_TOOLS=1` 回报「本次请求带没带 tools」，端到端断言（不是看代码猜）；
 *  3) **可见性**：状态栏 AI 段对无工具模型加标记；AI 面板空态列出当前模型能力。
 *
 * 运行：php tests/provider_caps_unit.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Ai\ChatModel;
use App\Ai\ProviderRegistry;
use App\Ai\ProviderSpec;
use App\App;

putenv('APP_LOCALE=zh_CN');
// 配置目录必须独立：ChatStore 按 dirname(VICECODE_CONFIG) 找存档，指向 /tmp 根会读到别的
// 测试留下的 /tmp/.vicecode_ai（空态就不是空态了）。见 feedback §1.5 的同类教训。
$capsTmpDir = sys_get_temp_dir() . '/vc_caps_unit_' . getmypid();
@mkdir($capsTmpDir, 0700, true);
putenv('VICECODE_CONFIG=' . $capsTmpDir . '/.vicerc');
// 用户级 provider 配置也要隔离：本测试断言的是**内置** config/providers.php 的内容，
// 若开发机上有 ~/.vicecode.providers.php，覆盖合并会让断言随本机状态漂移。
putenv('VICECODE_PROVIDERS_CONFIG=' . $capsTmpDir . '/.vicecode.providers.php');
register_shutdown_function(static function () use ($capsTmpDir): void {
    @unlink($capsTmpDir . '/.vicerc');
    @unlink($capsTmpDir . '/.vicecode_ai');
    @rmdir($capsTmpDir);
});

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

// ═══════════ 1) 配置解析 ═══════════
echo "== 配置解析：模型级声明 / provider 默认 / 旧写法兼容 ==\n";

/** 造一个只含单个 provider 的 registry（不碰真实 config/providers.php 与真实 key） */
function reg(array $provider): ProviderRegistry
{
    return new ProviderRegistry(['p' => $provider]);
}

$base = ['label' => 'P', 'base_url' => 'https://example.invalid/v1'];

// 旧写法（纯模型名列表）→ 全部用默认 = ['tools']（零迁移）
$r = reg($base + ['models' => ['a', 'b'], 'model' => 'a']);
check($r->spec('p')?->capabilities === ['tools'], '旧写法（字符串列表）→ 默认能力 [tools]');
check($r->spec('p')?->supportsTools() === true, '旧写法 → supportsTools() 为真（零迁移）');
check($r->spec('p')?->models === ['a', 'b'], '模型顺序保留（Ctrl+N 轮换顺序不受影响）');

// provider 级默认被未单独声明的模型继承
$r = reg($base + [
    'models' => ['a', 'b' => ['reasoning']],
    'model' => 'a',
    'capabilities' => ['tools', 'vision'],
]);
check($r->spec('p', 'a')?->capabilities === ['tools', 'vision'], 'provider 级 capabilities 被未声明模型继承');
check($r->spec('p', 'b')?->capabilities === ['reasoning'], '模型级声明覆盖 provider 默认');
check($r->spec('p', 'b')?->supportsTools() === false, '声明 [reasoning] 的模型 → 不发 tools');

// 值写 null = 用默认；写 [] = 确实没有能力
$r = reg($base + [
    'models' => ['a' => null, 'b' => []],
    'model' => 'a',
    'capabilities' => ['tools', 'audio'],
]);
check($r->spec('p', 'a')?->capabilities === ['tools', 'audio'], '模型值写 null → 用 provider 默认');
check($r->spec('p', 'b')?->capabilities === [], '模型值写 [] → 确实无能力（不是回落默认）');
check($r->spec('p', 'b')?->supportsTools() === false, '写 [] 的模型 supportsTools() 为假');

// provider 级写 [] → 该 provider 所有未声明模型都无能力
$r = reg($base + ['models' => ['a'], 'model' => 'a', 'capabilities' => []]);
check($r->spec('p')?->capabilities === [], 'provider 级 capabilities 写 [] → 无能力（区别于「没写」）');

// 裸串 + 脏值容错（去重 / trim / 丢非字符串）
$r = reg($base + [
    'models' => ['a' => 'vision'],
    'model' => 'a',
]);
check($r->spec('p')?->capabilities === ['vision'], '模型能力写成裸串 → 视为单项');
$r = reg($base + [
    'models' => ['a' => ['tools', 'tools', '', '  vision  ', 123, null]],
    'model' => 'a',
]);
check($r->spec('p')?->capabilities === ['tools', 'vision'], '能力列表去重 / trim / 丢弃空值与非字符串');

// 未知模型退回默认模型（能力随之取默认模型的），且不抛
$r = reg($base + ['models' => ['a' => ['tools'], 'b' => ['reasoning']], 'model' => 'b']);
check($r->spec('p', 'nope')?->model === 'b', '未知模型退回配置里的默认模型');
check($r->spec('p', 'nope')?->capabilities === ['reasoning'], '退回后能力也跟着默认模型');

// 未知能力名照收（前向扩展），supports 只认声明过的
$r = reg($base + ['models' => ['a' => ['video', 'tools']], 'model' => 'a']);
$s = $r->spec('p');
check($s?->capabilities === ['video', 'tools'], '未知能力名照收（可前向扩展）');
check($s?->supports('video') === true && $s?->supports(ProviderSpec::VISION) === false, 'supports() 只认声明过的能力');

// 真实内置配置：deepseek-reasoner 预声明为 [reasoning]
$real = new ProviderRegistry();
check($real->spec('deepseek', 'deepseek-reasoner')?->supportsTools() === false,
    '内置配置：deepseek-reasoner 声明 [reasoning] → 不发 tools');
check($real->spec('deepseek')?->supportsTools() === true, '内置配置：deepseek-chat 仍带 tools');
check($real->spec('openai', 'gpt-4o')?->supports(ProviderSpec::VISION) === true,
    '内置配置：gpt-4o 声明了 vision');

// ═══════════ 2) 行为开关（端到端）═══════════
echo "== 行为开关：只有声明 tools 的模型，请求里才带 tools ==\n";

/** 起本地 mock（MOCK_ECHO_TOOLS=1：回复 `[tools=1|0]` 回报请求体里有没有 tools） */
function startEchoServer(int $port): mixed
{
    $p = proc_open(
        ['env', 'MOCK_ECHO_TOOLS=1', 'php', '-S', '127.0.0.1:' . $port, __DIR__ . '/../examples/sse_server.php'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes
    );
    for ($i = 0; $i < 200; $i++) {
        $c = @stream_socket_client('tcp://127.0.0.1:' . $port, $e, $s, 0.2);
        if ($c) {
            fclose($c);
            break;
        }
        usleep(50000);
    }
    return $p;
}

$port = 18981;
$srv = startEchoServer($port);
putenv('CAP_KEY=sk-test-local');

/** 跑一轮并把 assistant 回复取回来 */
function ask(int $port, array $models, string $model): string
{
    $app = new App();
    $chat = new ChatModel($app, new ProviderRegistry(['mock' => [
        'label' => 'Mock',
        'key_env' => 'CAP_KEY',
        'base_url' => 'http://127.0.0.1:' . $port . '/v1',
        'models' => $models,
        'model' => $model,
    ]]));
    $chat->send('hi');
    $deadline = microtime(true) + 20;
    while ($chat->isStreaming() && microtime(true) < $deadline) {
        $chat->poll();
        usleep(20000);
    }
    return (string) ($chat->messages()[1]['content'] ?? '');
}

$replyTools = ask($port, ['m-tools' => ['tools']], 'm-tools');
check(str_contains($replyTools, '[tools=1]'), '声明 [tools] 的模型 → 请求带 tools（实测回复 ' . trim($replyTools) . '）');
$replyReason = ask($port, ['m-reason' => ['reasoning']], 'm-reason');
check(str_contains($replyReason, '[tools=0]'), '声明 [reasoning] 的模型 → 请求不带 tools（实测回复 ' . trim($replyReason) . '）');
$replyLegacy = ask($port, ['m-legacy'], 'm-legacy');
check(str_contains($replyLegacy, '[tools=1]'), '旧写法（未声明能力）→ 仍带 tools（零迁移，实测 ' . trim($replyLegacy) . '）');
$replyEmpty = ask($port, ['m-none' => []], 'm-none');
check(str_contains($replyEmpty, '[tools=0]'), '显式 [] → 不带 tools（实测 ' . trim($replyEmpty) . '）');

proc_terminate($srv, SIGKILL);
proc_close($srv);

// ═══════════ 3) 可见性 ═══════════
echo "== 可见性：状态栏标记 + AI 面板空态列出能力 ==\n";

/** 造一个 App，并把它的 chat 换成可控 registry 的 ChatModel */
function appWithChat(array $provider, ?string $model = null): App
{
    $app = new App();
    $chat = new ChatModel($app, new ProviderRegistry(['p' => $provider]));
    $app->chat = $chat;
    if ($model !== null) {
        $chat->useProvider('p', $model);
    }
    return $app;
}

/** 取 AI 面板当前渲染出的全部文本（走生产同一条 buildLinesWithMap） */
function panelText(App $app): string
{
    $m = new ReflectionMethod($app->ai, 'buildLinesWithMap');
    $m->setAccessible(true);
    $txt = '';
    foreach ($m->invoke($app->ai, 80)['lines'] as $line) {
        foreach ($line->spans as $sp) {
            $txt .= $sp->content;
        }
        $txt .= "\n";
    }
    return $txt;
}

$cfgBase = ['label' => '内置', 'base_url' => 'https://example.invalid/v1'];
$noTools = appWithChat($cfgBase + ['models' => ['r' => ['reasoning']], 'model' => 'r']);
$sb = $noTools->statusBar->assemble(200)['text'];
check(str_contains($sb, '无工具'), '无工具模型：状态栏 AI 段带「无工具」标记（实际状态栏含：' . (str_contains($sb, '内置/') ? '内置/r' : '?') . '）');
check(str_contains($sb, '内置/r'), '状态栏仍显示 provider/模型');

$withTools = appWithChat($cfgBase + ['models' => ['t' => ['tools']], 'model' => 't']);
$sb2 = $withTools->statusBar->assemble(200)['text'];
check(!str_contains($sb2, '无工具'), '有工具模型：状态栏不带「无工具」标记（正反对照）');

// AI 面板空态（无消息）会渲染能力行
$rows = panelText($noTools);
check(str_contains($rows, '能力'), 'AI 面板空态列出「能力」行');
check(str_contains($rows, '推理'), '能力行把 reasoning 翻成「推理」');
check(!str_contains($rows, '工具调用'), '无工具模型的能力行里没有「工具调用」');

$rows2 = panelText($withTools);
check(str_contains($rows2, '工具调用'), '有工具模型的能力行含「工具调用」（正反对照）');

// 未知能力名按原文显示，不炸
$unknown = appWithChat($cfgBase + ['models' => ['u' => ['video', 'tools']], 'model' => 'u']);
$rows3 = panelText($unknown);
check(str_contains($rows3, 'video') && str_contains($rows3, '工具调用'), '未知能力名按原文显示（如 video），已知的仍翻译');

echo $failed ? "\nRESULT: FAIL\n" : "\nRESULT: PASS\n";
exit($failed ? 1 : 0);

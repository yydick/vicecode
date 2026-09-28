<?php
declare(strict_types=1);

/**
 * 用户级 provider 配置（~/.vicecode.providers.php）—— headless 单测。
 *
 * 覆盖四块：
 *  1) **合并语义**：按 id 逐字段覆盖 / 未写字段保留内置 / `models` 整表覆盖 / 新 id 追加在末尾 /
 *     脏条目跳过（含 provider 级 capabilities 覆盖）；
 *  2) **加载健壮性**：文件缺失（正常）/ 语法错 / 返回非数组 / 读不出来 —— 一律保留内置配置并
 *     记下 `userError`，**绝不 fatal**（这份文件支持应用内编辑，存坏了不能让应用崩）；
 *  3) **应用内编辑**：`openProvidersConfig()` 生成可解析的模板并送进编辑器；
 *  4) **热重载**：`reloadProviders()` 保住当前 provider/model；provider 被移除才退回默认。
 *
 * 运行：php tests/provider_user_unit.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/isolation.php';

use App\Ai\ChatModel;
use App\Ai\ProviderRegistry;
use App\App;
use App\Core\ConfigStore;

putenv('APP_LOCALE=zh_CN');
// 独占配置目录（含 VICECODE_PROVIDERS_CONFIG，见 lib/isolation.php）
vc_isolate_config('vc_providers_user');

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

/** 造一份小的「内置」配置（不碰真实 config/providers.php） */
function builtin(): array
{
    return [
        'openai' => [
            'label'        => 'OpenAI',
            'key_env'      => 'OPENAI_API_KEY',
            'base_url'     => 'https://api.openai.com/v1',
            'models'       => ['gpt-4o-mini' => ['tools'], 'gpt-4o' => ['tools', 'vision']],
            'model'        => 'gpt-4o-mini',
            'capabilities' => ['tools'],
        ],
        'deepseek' => [
            'label'    => 'DeepSeek',
            'base_url' => 'https://api.deepseek.com/v1',
            'models'   => ['deepseek-chat' => ['tools']],
            'model'    => 'deepseek-chat',
        ],
    ];
}

/** 写一个用户级配置文件（返回其路径），$body 是 `<?php ` 之后的 PHP 源码 */
function withUserFile(string $tag, string $body): string
{
    $path = vc_isolation_dir($tag) . '.php';
    file_put_contents($path, "<?php\n" . $body);
    register_shutdown_function(static function () use ($path): void {
        @unlink($path);
    });
    return $path;
}

// ═══════════ 1) 合并语义 ═══════════
echo "== 合并：按 id 覆盖 + 新 id 追加 ==\n";

$u = withUserFile('vc_pu_merge', <<<'PHP'
declare(strict_types=1);
return [
    'openai' => [
        'base_url' => 'http://gateway.internal/v1',
        'models'   => ['only-one' => ['tools']],
    ],
    'my-gw' => [
        'label'    => '我的网关',
        'key_env'  => 'MYGW_API_KEY',
        'base_url' => 'http://127.0.0.1:11434/v1',
        'models'   => ['qwen2.5:14b' => null],
        'model'    => 'qwen2.5:14b',
    ],
];
PHP);

$r = new ProviderRegistry(builtin(), null, $u);
check($r->ids() === ['openai', 'deepseek', 'my-gw'], 'id 顺序：内置在前，新 provider 追加在末尾（实际 ' . implode(',', $r->ids()) . '）');
check($r->defaultId() === 'openai', 'defaultId 仍是内置第一个（顺序稳定）');
check($r->spec('openai')?->baseUrl === 'http://gateway.internal/v1', '同 id：写了 base_url 就被覆盖');
check($r->spec('openai')?->label === 'OpenAI', '同 id：没写的字段保留内置值（label）');
check($r->spec('openai')?->models === ['only-one'], 'models 是**整表覆盖**，不与内置取并集（实际 ' . implode(',', $r->spec('openai')?->models ?? []) . '）');
check($r->spec('my-gw')?->label === '我的网关' && $r->spec('my-gw')?->model === 'qwen2.5:14b', '新 provider 完整生效（label + model）');
check($r->spec('my-gw')?->baseUrl === 'http://127.0.0.1:11434/v1', '新 provider 的 base_url 生效');
// models 值写 null = 用 provider 默认能力（未写 capabilities → DEFAULT_CAPS = tools）
check($r->spec('my-gw')?->supportsTools() === true, '新 provider 未声明 capabilities → 默认支持 tools（零迁移）');
check($r->userError() === null, '无错：userError 为 null');
check($r->userFile() === $u, 'userFile() 暴露实际读过的用户配置路径');

// provider 级 capabilities 覆盖：只影响**没有模型级声明**的模型
// （模型级声明优先于 provider 级默认，这条语义由 provider_caps_unit 覆盖；这里用 `=> null`
//   让模型回落 provider 默认，从而验证用户覆盖的 provider 级能力真的生效）
$u2 = withUserFile('vc_pu_caps', <<<'PHP'
return ['deepseek' => ['capabilities' => ['reasoning'], 'models' => ['deepseek-chat' => null]]];
PHP);
$r2 = new ProviderRegistry(builtin(), null, $u2);
check($r2->spec('deepseek')?->capabilities === ['reasoning'], 'provider 级 capabilities 可被用户覆盖（模型级写 null 时回落 provider 默认）');
check($r2->spec('deepseek')?->supportsTools() === false, '覆盖成 [reasoning] 后不再发 tools');
check($r2->spec('deepseek')?->models === ['deepseek-chat'], '未写 models 时仍用内置的模型列表');

// 脏条目不影响其它 provider
$u3 = withUserFile('vc_pu_dirty', <<<'PHP'
return [
    'ok' => ['label' => 'OK', 'base_url' => 'http://x/v1', 'models' => ['m'], 'model' => 'm'],
    'bad-string' => 'not-an-array',
    '' => ['label' => '空 id'],
    'also-ok' => ['label' => 'A2', 'base_url' => 'http://y/v1', 'models' => ['m2'], 'model' => 'm2'],
];
PHP);
$r3 = new ProviderRegistry(builtin(), null, $u3);
check($r3->ids() === ['openai', 'deepseek', 'ok', 'also-ok'], '脏条目（非数组 / 空 id）被跳过，其余照常追加（实际 ' . implode(',', $r3->ids()) . '）');
check($r3->spec('ok')?->label === 'OK' && $r3->spec('also-ok')?->label === 'A2', '脏条目之后的 provider 也没被带坏');

// ═══════════ 2) 加载健壮性 ═══════════
echo "== 健壮性：坏文件保留内置配置，绝不 fatal ==\n";

// 文件缺失
$missing = vc_isolation_dir('vc_pu_missing') . '.php';
$r4 = new ProviderRegistry(builtin(), null, $missing);
check($r4->ids() === ['openai', 'deepseek'], '文件不存在 → 行为与没有用户配置一致');
check($r4->userError() === null, '文件不存在**不算错误**（userError 为 null）');

// 语法错（应用内编辑手滑存半截）——必须先被 token_get_all 拦下
$u5 = withUserFile('vc_pu_syntax', "return [\n    'openai' => [\n");   // 故意不闭合
$r5 = new ProviderRegistry(builtin(), null, $u5);
check($r5->ids() === ['openai', 'deepseek'], '语法错 → 内置配置完整保留（没有 fatal、没有半份配置）');
check($r5->userError() === 'syntax', "语法错 → userError = 'syntax'（实际 " . var_export($r5->userError(), true) . '）');
check($r5->spec('openai')?->baseUrl === 'https://api.openai.com/v1', '语法错 → 拿到的仍是内置 config/providers.php 的值');

// 返回非数组
$u6 = withUserFile('vc_pu_shape', "return 'nope';\n");
$r6 = new ProviderRegistry(builtin(), null, $u6);
check($r6->userError() === 'shape' && $r6->ids() === ['openai', 'deepseek'], "返回非数组 → userError = 'shape' 且保留内置配置");

// ═══════════ 3) 应用内编辑：模板 ═══════════
echo "== 应用内编辑：openProvidersConfig() 生成可用模板 ==\n";

$path = ConfigStore::providersPath();
check(!is_file($path), '前置：隔离目录里还没有用户配置文件');
check(str_contains($path, '.vicecode.providers.php'), "providersPath() 指向 ~/.vicecode.providers.php（实际 {$path}）");

$app = new App();
$app->menuAction('ai.providers_config');
check(is_file($path), '菜单动作 ai.providers_config → 文件已生成');
check($app->buffer?->path === $path, '生成的文件已在 ViceCode 自己的编辑器里打开（不是甩给外部编辑器）');

// 入口本身要可发现：AI 菜单组里有这一项 + 命令面板能搜到（否则用户不知道有这功能）
$aiGroup = null;
foreach ($app->menuBar->definitions() as $g) {
    if (($g['label'] ?? '') === $app->t('menu.ai')) {
        $aiGroup = $g;
    }
}
$aiActions = array_column($aiGroup['items'] ?? [], 'action');
check(in_array('ai.providers_config', $aiActions, true), 'AI 菜单组含「编辑模型配置…」（action ai.providers_config）');
check(in_array('ai.providers_config', array_column($app->commandPaletteEntries(), 'id'), true),
    '命令面板也能搜到 ai.providers_config');

$tmpl = (string) file_get_contents($path);
check(str_contains($tmpl, 'return [];'), "模板是可直接保存的合法 PHP（含 'return [];'）");
check(str_contains($tmpl, 'VICECODE') === false, '模板里不写环境变量名以外的内部实现细节（只教 {PREFIX}_API_KEY 约定）');
// 模板必须能被安全加载（语法检查 + require 都通过），否则用户"什么都不改就保存"就会看到错误提示
$rT = new ProviderRegistry(null, null, $path);
check($rT->userError() === null, '模板本身可被安全加载（userError 为 null）');
check($rT->ids() === (new ProviderRegistry())->ids(), '空模板不改变任何 provider（行为与没有该文件一致）');

// ═══════════ 4) 热重载 ═══════════
echo "== 热重载：保住当前选择 ==\n";

$base = builtin();
$app2 = new App();
$chat = new ChatModel($app2, new ProviderRegistry($base, null, null));
$app2->chat = $chat;
$chat->useProvider('deepseek');
check($chat->providerId() === 'deepseek', '前置：已切到 deepseek');

// 情形 A：reload 后该 provider 仍在（只改了别的字段）→ 选择保留
$after = $base;
$after['deepseek']['base_url'] = 'https://new.example/v1';
$msgBefore = $app2->message;   // useProvider 会写「已切换…」，重载不该动它
$chat->reloadProviders(new ProviderRegistry($after, null, null));
check($chat->providerId() === 'deepseek', 'reload 后 provider 仍在 → 当前 provider 保住（不打断用户）');
check($chat->spec()?->baseUrl === 'https://new.example/v1', 'reload 后新 base_url 已生效');
check($app2->message === $msgBefore, 'reload 本身不写状态栏（提示统一由 App::reloadProviders 给）');

// 情形 B：当前模型被移除 → 退回该 provider 的默认模型（spec() 既有语义）
$after2 = $base;
$after2['deepseek']['models'] = ['deepseek-reasoner' => ['reasoning']];
$after2['deepseek']['model'] = 'deepseek-reasoner';
$chat->useProvider('deepseek', 'deepseek-chat');
$chat->reloadProviders(new ProviderRegistry($after2, null, null));
check($chat->providerId() === 'deepseek' && $chat->spec()?->model === 'deepseek-reasoner',
    '当前模型在新配置里没了 → 退回该 provider 的默认模型（实际 ' . ($chat->spec()?->model ?? 'null') . '）');

// 情形 C：当前 provider 被移除 → 退回默认 provider
$after3 = ['openai' => $base['openai']];
$chat->reloadProviders(new ProviderRegistry($after3, null, null));
check($chat->providerId() === null, '当前 provider 被移除 → providerId 清空');
check($chat->spec()?->id === 'openai', '此时 spec() 取默认 provider（openai）');

// 情形 D：App::reloadProviders 会把加载错误提示到状态栏（用户存坏文件要有反馈）
$app3 = new App();
$app3->chat = new ChatModel($app3, new ProviderRegistry($base, null, null));
$bad = ConfigStore::providersPath();
$orig = (string) file_get_contents($bad);
file_put_contents($bad, "<?php return [\n");   // 语法坏
$app3->reloadProviders();
check(str_contains($app3->message, '模型配置未生效'), '存了语法错的配置 → 状态栏明确提示「未生效（保留原配置）」（实际：' . $app3->message . '）');
file_put_contents($bad, $orig);               // 恢复模板
$app3->reloadProviders();
check(str_contains($app3->message, '已重载'), '存了合法配置 → 状态栏提示「已重载」（实际：' . $app3->message . '）');

echo $failed ? "\nRESULT: FAIL\n" : "\nRESULT: PASS\n";
exit($failed ? 1 : 0);

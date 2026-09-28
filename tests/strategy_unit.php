<?php
declare(strict_types=1);

/**
 * 模型策略（用户级配置的 `@strategies` 段）—— headless 单测。
 *
 * 覆盖：
 *  1) **解析**：`@` 保留段不进 provider 列表 / label·model·requires 的缺省与容错 / 脏条目丢弃；
 *  2) **应用**：切 provider+model、记住策略名、状态栏与消息可见；
 *  3) **四类拒绝**（都必须**拒绝而不是静默降级**）：策略不存在 / provider 未配置 /
 *     model 未声明（`spec()` 本会静默回退，这里必须拦住）/ `requires` 能力不满足；
 *  4) **不说谎**：手动切 provider 或 model 后策略名必须清空（否则状态栏挂着错的档位）；
 *  5) 循环切换、持久化与恢复（只在目标仍一致时恢复）；
 *  6) 入口：菜单 AI 组每条策略一项 + 命令面板可搜 + `ai.strategy:<name>` 能分发。
 *
 * 运行：php tests/strategy_unit.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/isolation.php';

use App\Ai\ChatModel;
use App\Ai\ChatStore;
use App\Ai\ProviderRegistry;
use App\App;
use App\Core\ConfigStore;

putenv('APP_LOCALE=zh_CN');   // 断言中文文案：状态栏/提示词都按 zh 写
vc_isolate_config('vc_strategy_unit');

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

/** 造「内置」provider 池（不碰真实 config/providers.php） */
function builtin(): array
{
    return [
        'deepseek' => [
            'label'    => 'DeepSeek',
            'key_env'  => 'DS_KEY',
            'base_url' => 'https://api.deepseek.com/v1',
            'models'   => ['deepseek-chat' => ['tools'], 'deepseek-reasoner' => ['reasoning']],
            'model'    => 'deepseek-chat',
            'capabilities' => ['tools'],
        ],
    ];
}

/**
 * 写一份**独占**的用户级配置文件（返回其路径），退出时清理。
 *
 * ⚠️ 不能都往 `ConfigStore::providersPath()` 写：那样后一个用例会把前一个覆盖掉，
 * 而 `new ProviderRegistry(...)` 是**每次重新读文件**的——下一个 Registry 就读到了别的用例的内容
 * （本轮真踩了：no-strategy 那个用例把 a/b 两条策略冲掉，导致后面的恢复断言莫名其妙地失败）。
 */
function writeUserConfig(string $body): string
{
    $path = vc_isolation_dir('vc_strategy_cfg') . '.php';
    file_put_contents($path, "<?php\n" . $body);
    register_shutdown_function(static function () use ($path): void {
        @unlink($path);
    });
    return $path;
}

/** 造一个 App + 注入 registry 的 ChatModel */
function appWith(array $builtin, string $userFile): array
{
    $app = new App();
    $chat = new ChatModel($app, new ProviderRegistry($builtin, null, $userFile));
    $app->chat = $chat;
    return [$app, $chat];
}

// ═══════════ 1) 解析 ═══════════
echo "== 解析：@ 保留段 / 缺省 / 容错 ==\n";

$u = writeUserConfig(<<<'PHP'
return [
    '@strategies' => [
        'plan'   => ['label' => '计划', 'provider' => 'deepseek', 'model' => 'deepseek-reasoner'],
        'grind'  => ['provider' => 'deepseek'],                       // 省 model/label → 用 provider 默认模型 + name 当 label
        'tooly'  => ['provider' => 'deepseek', 'model' => 'deepseek-chat', 'requires' => 'tools'],  // 裸串
        'dirty1' => 'not-an-array',
        'dirty2' => ['label' => '缺 provider'],
        'dirty3' => ['provider' => '   '],
    ],
    'my-gw' => ['label' => '网关', 'base_url' => 'http://127.0.0.1:1/v1', 'models' => ['m'], 'model' => 'm'],
];
PHP);
$r = new ProviderRegistry(builtin(), null, $u);
check($r->ids() === ['deepseek', 'my-gw'], '@strategies 不被当成 provider（ids 里没有它；实际 ' . implode(',', $r->ids()) . '）');
$st = $r->strategies();
check(array_keys($st) === ['plan', 'grind', 'tooly'], "脏策略条目（非数组 / 缺 provider / 空 provider）被丢弃（实际 " . implode(',', array_keys($st)) . '）');
check($st['plan']->label === '计划' && $st['plan']->model === 'deepseek-reasoner', 'label/model 按配置读取');
check($st['grind']->label === 'grind' && $st['grind']->model === null, '省 label → 用 name；省 model → null（表示用 provider 默认模型）');
check($st['tooly']->requires === ['tools'], 'requires 允许写裸串');

// ═══════════ 2) 应用 + 可见性 ═══════════
echo "== 应用策略：切换 + 可见 ==\n";
[$app, $chat] = appWith(builtin(), $u);
check($chat->strategyName() === null, '初始没有策略（手动选中态）');
check($chat->applyStrategy('plan') === true, 'applyStrategy(plan) 成功');
check($chat->providerId() === 'deepseek' && $chat->spec()?->model === 'deepseek-reasoner', '目标 provider/model 已生效');
check($chat->strategyLabel() === '计划', 'strategyLabel() 取出展示名');
check(str_contains($app->message, '计划') && str_contains($app->message, 'deepseek-reasoner'), '状态栏提示写清切到哪一档哪个模型（实际：' . $app->message . '）');
check(str_contains($app->statusBar->assemble(200)['text'], '策略=计划'), '状态栏出现策略段');
$bb = $app->statusBar->assemble(200)['text'];
check(str_contains($bb, 'deepseek-reasoner'), '状态栏同时显示实际模型（哪一档 + 打到哪个模型都要看得见）');

// ═══════════ 3) 四类拒绝（绝不静默降级）═══════════
echo "== 拒绝：策略/provider/model 写错、能力不满足 ==\n";

check($chat->applyStrategy('nope') === false, '策略不存在 → 拒绝');
check(str_contains($app->message, '没有名为'), '提示写明策略不存在（实际：' . $app->message . '）');
check($chat->strategyLabel() === '计划', '拒绝后仍停在原策略（不会把状态搞成半截）');

$u2 = writeUserConfig(<<<'PHP'
return ['@strategies' => [
    'ghost'    => ['label' => '幽灵', 'provider' => 'not-configured', 'model' => 'x'],
    'typo'     => ['label' => '手滑', 'provider' => 'deepseek', 'model' => 'deepseek-chat-typo'],
    'needtool' => ['label' => '要工具', 'provider' => 'deepseek', 'model' => 'deepseek-reasoner', 'requires' => ['tools']],
]];
PHP);
[$app2, $chat2] = appWith(builtin(), $u2);
$before = $chat2->spec()?->model;

check($chat2->applyStrategy('ghost') === false, 'provider 未配置 → 拒绝');
check(str_contains($app2->message, 'not-configured'), '提示写明是哪个 provider（实际：' . $app2->message . '）');

// ⚠️ 这条是本功能最关键的防呆：spec() 对未登记的模型会**静默回退**成 provider 默认模型
check($chat2->applyStrategy('typo') === false, 'model 未声明 → 拒绝（而不是静默回退）');
check(str_contains($app2->message, '拒绝静默回退'), '提示点明"拒绝静默回退"（实际：' . $app2->message . '）');
check($chat2->spec()?->model === $before, '被拒后模型没被悄悄换掉（仍是 ' . $before . '）');

check($chat2->applyStrategy('needtool') === false, 'requires 能力不满足 → 拒绝');
check(str_contains($app2->message, 'tools'), '提示写明缺哪个能力（实际：' . $app2->message . '）');

// ═══════════ 4) 手动切换必须清空策略名（状态栏不许说谎）═══════════
echo "== 不说谎：手动切档后策略名清空 ==\n";
$chat2->applyStrategy('needtool') === false; // 换个能成功的：先写一条可用的
$u3 = writeUserConfig("return ['@strategies' => ['ok' => ['label' => '好的', 'provider' => 'deepseek', 'model' => 'deepseek-chat']]];");
$chat2->reloadProviders(new ProviderRegistry(builtin(), null, $u3));
$chat2->applyStrategy('ok');
check($chat2->strategyLabel() === '好的', '前置：已应用 ok 策略');
$chat2->cycleProvider();
check($chat2->strategyName() === null, 'Ctrl+P 切 provider 后策略名清空');
$chat2->applyStrategy('ok');
$chat2->cycleModel();
check($chat2->strategyName() === null, 'Ctrl+N 切模型后策略名清空');
$txt = $app2->statusBar->assemble(200)['text'];
check(!str_contains($txt, '策略=好的'), '状态栏不再显示已失效的策略名');

// ═══════════ 5) 循环切换 + 持久化/恢复 ═══════════
echo "== 循环切换与持久化 ==\n";
$u4 = writeUserConfig(<<<'PHP'
return ['@strategies' => [
    'a' => ['label' => 'A档', 'provider' => 'deepseek', 'model' => 'deepseek-chat'],
    'b' => ['label' => 'B档', 'provider' => 'deepseek', 'model' => 'deepseek-reasoner'],
]];
PHP);
[$app3, $chat3] = appWith(builtin(), $u4);
$chat3->cycleStrategy();
check($chat3->strategyName() === 'a', '没有当前策略时，循环切到第一条');
check($chat3->isPinned() === true, '循环选中某条策略 = 手动选档（钉住，自动选档暂停）');
$chat3->cycleStrategy();
check($chat3->strategyName() === 'b', '再切到下一条');
// 循环里含「自动」档（本功能加的）：b → 自动 → a
$chat3->cycleStrategy();
check($chat3->isPinned() === false && $chat3->strategyName() === 'b',
    '再切一次落在「自动」档（解除钉住；策略名保留，状态栏继续显示实际在跑的档）');
$chat3->cycleStrategy();
check($chat3->strategyName() === 'a' && $chat3->isPinned() === true, '环形回到第一条（自动 → a，并重新钉住）');

// 无策略时给明确提示
$u5 = writeUserConfig("<?php\nreturn ['deepseek' => ['label' => 'DeepSeek', 'base_url' => 'https://x/v1', 'models' => ['m'], 'model' => 'm']];");
[$appN, $chatN] = appWith(builtin(), $u5);
$chatN->cycleStrategy();
check($chatN->strategies() === [] && str_contains($appN->message, '没有配置任何模型策略'), '一条策略都没配时给出配置指引（实际：' . $appN->message . '）');

// 持久化：存档里带 strategy；恢复时目标一致才恢复
// 注：restore() 对空对话本就早退（`.vicecode_ai` 里没有消息就不恢复），provider/model 也一样——
// 所以这里先塞一条消息，模拟"真的用过了"。
$prop = new ReflectionProperty($chat3, 'messages');
$prop->setAccessible(true);
$prop->setValue($chat3, [['role' => 'user', 'content' => 'hi']]);
$chat3->applyStrategy('b');
$chat3->saveNow();
$snap = ChatStore::load();
check(($snap['strategy'] ?? null) === 'b', '存档记录了当前策略（实际 ' . var_export($snap['strategy'] ?? null, true) . '）');

$chat4 = new ChatModel($app3, new ProviderRegistry(builtin(), null, $u4));
$chat4->restore();
check($chat4->strategyName() === 'b' && $chat4->spec()?->model === 'deepseek-reasoner', '重启恢复：策略与模型一致 → 策略名恢复');

// 目标不一致（例如用户手选过别的模型）→ 不恢复名字，免得状态栏说谎
ChatStore::save([['role' => 'user', 'content' => 'hi']], ['provider' => 'deepseek', 'model' => 'deepseek-chat', 'strategy' => 'b']);
$chat5 = new ChatModel($app3, new ProviderRegistry(builtin(), null, $u4));
$chat5->restore();
check($chat5->strategyName() === null, '存档里的策略与 provider/model 不匹配 → 不恢复策略名（实际 ' . var_export($chat5->strategyName(), true) . '）');
check($chat5->spec()?->model === 'deepseek-chat', '但仍按存档恢复 model（既有行为不变）');

// ═══════════ 6) 入口：菜单 / 命令面板 / 动作分发 ═══════════
echo "== 入口：菜单 + 命令面板 + 分发 ==\n";
$aiGroup = null;
foreach ($app3->menuBar->definitions() as $g) {
    if (($g['label'] ?? '') === $app3->t('menu.ai')) {
        $aiGroup = $g;
    }
}
$acts = array_column($aiGroup['items'] ?? [], 'action');
check(in_array('ai.strategy:a', $acts, true) && in_array('ai.strategy:b', $acts, true), 'AI 菜单组里每条策略一项（' . implode(',', array_filter($acts, static fn($x) => str_starts_with((string) $x, 'ai.strategy:'))) . '）');
$pal = array_column($app3->commandPaletteEntries(), 'id');
check(in_array('ai.strategy:a', $pal, true), '命令面板能搜到策略（条目由菜单派生）');
$app3->menuAction('ai.strategy:b');
check($app3->chat->strategyName() === 'b', "menuAction('ai.strategy:b') 直接切到该策略");

// 没配策略时不应该有策略条目
$aiGroupN = null;
foreach ($appN->menuBar->definitions() as $g) {
    if (($g['label'] ?? '') === $appN->t('menu.ai')) {
        $aiGroupN = $g;
    }
}
$actsN = array_column($aiGroupN['items'] ?? [], 'action');
check(array_filter($actsN, static fn($x) => str_starts_with((string) $x, 'ai.strategy:')) === [], '没配策略时菜单里不出现策略条目（不干扰既有菜单）');

echo $failed ? "\nRESULT: FAIL\n" : "\nRESULT: PASS\n";
exit($failed ? 1 : 0);

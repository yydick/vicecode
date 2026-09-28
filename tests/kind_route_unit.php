<?php
declare(strict_types=1);

/**
 * 按任务类型自动选档 —— headless 单测。
 *
 * 任务类型只有两个来源（**都不猜**，猜错会静默降级）：
 *  ① 快捷动作的 kind（explain / comment / refactor / unittest）；
 *  ② 输入框开头的**指令前缀** `/plan …`（前缀不发给模型；未知类型拒绝发送并列出已知；`//` 转义字面量）。
 *
 * 优先级是**人工优先**：手动选档（Ctrl+R / 菜单 / 手切 provider·model）会**钉住**，
 * 自动选档暂停；`Ctrl+R` 循环里有一档「自动」可切回。
 *
 * 运行：php tests/kind_route_unit.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/isolation.php';

use App\Ai\ChatModel;
use App\Ai\ProviderRegistry;
use App\App;
use App\Core\ConfigStore;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Tui\Display\Area;

putenv('APP_LOCALE=zh_CN');
$cfgFile = vc_isolate_config('vc_kind_unit');

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

function builtin(): array
{
    return [
        'deepseek' => [
            'label'    => 'DeepSeek',
            'base_url' => 'https://api.deepseek.com/v1',
            'models'   => ['cheap' => ['tools'], 'smart' => ['tools', 'vision']],
            'model'    => 'cheap',
            'capabilities' => ['tools'],
        ],
    ];
}

/** 每个用例一份**独占**配置文件（共用一份会互相覆盖，见 strategy_unit 的教训） */
function cfgFile(string $body): string
{
    $path = vc_isolation_dir('vc_kind_cfg') . '.php';
    file_put_contents($path, "<?php\n" . $body);
    register_shutdown_function(static function () use ($path): void {
        @unlink($path);
    });
    return $path;
}

function appWith(array $builtin, string $userFile): array
{
    $app = new App();
    $chat = new ChatModel($app, new ProviderRegistry($builtin, null, $userFile));
    $app->chat = $chat;
    return [$app, $chat];
}

/** 在 AI 输入框里打字并回车（走真实事件路径：聚焦 → 逐字 → Enter） */
function typeAndSend(App $app, string $text): void
{
    $vp = Area::fromDimensions(140, 40);
    $app->ai->resetForPrompt();   // 清空输入框：拒绝发送时**刻意保留**用户文本，用例之间要各自独立
    $app->focus('ai_input');
    foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
        $app->handle(CharKeyEvent::new($ch, 0), $vp);
    }
    $app->handle(CharKeyEvent::new("\r", 0), $vp);
}

/** 取对话里最后一条 user 消息正文 */
function lastUser(ChatModel $chat): string
{
    $msgs = $chat->messages();
    for ($i = count($msgs) - 1; $i >= 0; $i--) {
        if (($msgs[$i]['role'] ?? '') === 'user') {
            return (string) ($msgs[$i]['content'] ?? '');
        }
    }
    return '';
}

// ═══════════ 1) kinds 解析与 knownKinds ═══════════
echo "== kinds 解析 / 已知类型 ==\n";

$f1 = cfgFile(<<<'PHP'
return ['@strategies' => [
    'plan'  => ['label' => '计划', 'provider' => 'deepseek', 'model' => 'smart', 'kinds' => ['plan']],
    'grind' => ['label' => '干活', 'provider' => 'deepseek', 'model' => 'cheap', 'kinds' => 'comment'],
    'multi' => ['label' => '多类型', 'provider' => 'deepseek', 'model' => 'cheap', 'kinds' => ['refactor', 'refactor', '']],
    'auto'  => ['label' => '保留名', 'provider' => 'deepseek', 'model' => 'cheap'],
]];
PHP);
[$app0, $chat0] = appWith(builtin(), $f1);
$sts = $chat0->strategies();
check(array_keys($sts) === ['plan', 'grind', 'multi'], '策略名 `auto` 是保留的（循环里的"自动"档），用户同名策略被丢弃');
check($sts['plan']->kinds === ['plan'], 'kinds 支持数组');
check($sts['grind']->kinds === ['comment'], 'kinds 支持裸串');
check($sts['multi']->kinds === ['refactor'], 'kinds 去重、丢空串');
check($chat0->knownKinds() === ['comment', 'explain', 'plan', 'refactor', 'unittest'],
    'knownKinds = 4 个快捷动作 + 策略声明的类型（去重排序；实际 ' . implode(',', $chat0->knownKinds()) . '）');

// ═══════════ 2) 快捷动作 kind 自动路由（不钉住）═══════════
echo "== 快捷动作按类型自动选档 ==\n";
check($chat0->isPinned() === false, '初始是「自动」状态（未钉住）');
$chat0->routeByKind('comment');
check($chat0->strategyName() === 'grind', 'kind=comment 命中「干活」档（grind 声明了 comment）');
check($chat0->isPinned() === false, '自动路由**不钉住**（下一条还能按类型改）');
check($chat0->spec()?->model === 'cheap', '自动路由真的换了模型（cheap）');
$chat0->routeByKind('plan');
check($chat0->strategyName() === 'plan' && $chat0->spec()?->model === 'smart', 'kind=plan 又切到「计划」档（smart）');
$before = $chat0->strategyName();
$chat0->routeByKind('unittest');
check($chat0->strategyName() === $before, '没有策略声明 unittest → 不路由、保持当前档（也不报错）');
$chat0->routeByKind(null);
check($chat0->strategyName() === $before, '普通消息（无 kind）不路由、保持当前档');

// ═══════════ 3) 人工优先：钉住后自动不生效 ═══════════
echo "== 人工优先：钉住 ==\n";
$chat0->applyStrategy('grind');
check($chat0->isPinned() === true, '手动 applyStrategy（Ctrl+R/菜单）→ 钉住');
$chat0->routeByKind('plan');
check($chat0->strategyName() === 'grind' && $chat0->spec()?->model === 'cheap',
    '钉住后 kind=plan 不再抢（仍是「干活」档）');
$chat0->useProvider('deepseek', 'smart');
check($chat0->isPinned() === true && $chat0->strategyName() === null, '手动切 provider/model 也钉住（且清空策略名：状态栏不说谎）');

// 切回自动：Ctrl+R 循环里走到「自动」档
$f2 = cfgFile(<<<'PHP'
return ['@strategies' => [
    'a' => ['label' => 'A档', 'provider' => 'deepseek', 'model' => 'cheap', 'kinds' => ['plan']],
    'b' => ['label' => 'B档', 'provider' => 'deepseek', 'model' => 'smart'],
]];
PHP);
[$app2, $chat2] = appWith(builtin(), $f2);
$chat2->applyStrategy('a');
check($chat2->isPinned() === true, '前置：钉住 A 档');
$chat2->cycleStrategy();
check($chat2->strategyName() === 'b' && $chat2->isPinned() === true, 'Ctrl+R 从 A 档 → B 档（仍是手动钉住）');
$chat2->cycleStrategy();
check($chat2->isPinned() === false, 'Ctrl+R 再一次走到「自动」档 → 解除钉住');
check(str_contains($app2->message, '已切回自动选档'), '状态栏回执说明已切回自动（实际：' . $app2->message . '）');
$chat2->routeByKind('plan');
check($chat2->strategyName() === 'a', '切回自动后 kind=plan 立刻生效（A 档）');

// ═══════════ 4) 指令前缀 ═══════════
echo "== 输入框指令前缀（真实事件路径）==\n";
[$app3, $chat3] = appWith(builtin(), $f2);
typeAndSend($app3, '/plan 帮我把这块重构一下');
check($chat3->strategyName() === 'a', '/plan 前缀 → 自动选到 A 档');
check(!str_contains(lastUser($chat3), '/plan'), '前缀**不发给模型**（user 消息里没有它）');
check(str_contains(lastUser($chat3), '帮我把这块重构一下'), '正文照常发给模型（实际：' . lastUser($chat3) . '）');

$n = count($chat3->messages());
typeAndSend($app3, '/nope 随便');
check(count($chat3->messages()) === $n, '未知类型 /nope → **拒绝发送**（消息条数不变）');
check(str_contains($app3->message, '未知任务类型') && str_contains($app3->message, 'plan'),
    '提示写明未知并列出已知类型（实际：' . $app3->message . '）');

typeAndSend($app3, '//plan 这是字面量');
check(str_starts_with(lastUser($chat3), '/plan'), '`//` 转义：字面量斜杠被还原成一个（实际：' . substr(lastUser($chat3), 0, 12) . '…）');

// 钉住时前缀不生效，且必须说出来
[$app4, $chat4] = appWith(builtin(), $f2);
$chat4->applyStrategy('b');           // 钉住 B（smart）
typeAndSend($app4, '/plan 规划一下');
check($chat4->strategyName() === 'b', '钉住状态下 /plan 不抢档（仍是 B）');
// 「被忽略要明说」这条在 pty 里验（tests/pty_strategy.php）：那里请求能真的发出去，
// 提示不会被「缺 key」这类更重要的错误覆盖——本用例没有 key，状态栏必然被错误信息占住。
check(!str_contains(lastUser($chat4), '/plan'), '钉住 + 前缀时，前缀同样不进正文');

// ═══════════ 5) 状态栏：说清"现在听谁的" ═══════════
echo "== 状态栏策略段 ==\n";
[$app5, $chat5] = appWith(builtin(), $f2);
$bar = static fn(App $a): string => $a->statusBar->assemble(200)['text'];
check(str_contains($bar($app5), '策略=自动'), '配了策略且未钉住 → 状态栏显示「策略=自动」');
$chat5->routeByKind('plan');
check(str_contains($bar($app5), '策略=A档·自动'), '自动路由后标出档位且带「·自动」后缀（现在听自动的）');
$chat5->applyStrategy('a');
check(str_contains($bar($app5), '策略=A档') && !str_contains($bar($app5), 'A档·自动'),
    '钉住后不再带「·自动」后缀（现在听用户的）');
[$app6] = appWith(builtin(), cfgFile("return ['deepseek' => ['label' => 'D', 'base_url' => 'https://x/v1', 'models' => ['m'], 'model' => 'm']];"));
check(!str_contains($bar($app6), '策略='), '没配任何策略 → 状态栏不出现策略段（不干扰既有布局）');

// ═══════════ 6) 菜单里多出「自动」档 ═══════════
echo "== 菜单/命令面板入口 ==\n";
$aiGroup = null;
foreach ($app5->menuBar->definitions() as $g) {
    if (($g['label'] ?? '') === $app5->t('menu.ai')) {
        $aiGroup = $g;
    }
}
$acts = array_column($aiGroup['items'] ?? [], 'action');
check(in_array('ai.strategy_auto', $acts, true), 'AI 菜单里有「自动选档」项');
$pal = array_column($app5->commandPaletteEntries(), 'id');
check(in_array('ai.strategy_auto', $pal, true), '命令面板能搜到 ai.strategy_auto');
$chat5->applyStrategy('b');
$app5->menuAction('ai.strategy_auto');
check($app5->chat->isPinned() === false, "menuAction('ai.strategy_auto') → 解除钉住、回到自动");

echo $failed ? "\nRESULT: FAIL\n" : "\nRESULT: PASS\n";
exit($failed ? 1 : 0);

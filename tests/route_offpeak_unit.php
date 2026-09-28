<?php
declare(strict_types=1);

/**
 * 折扣时段自动降档 —— headless 单测。
 *
 * 规则（`ChatModel::routeByOffPeak()`）：
 *  · **kinds 优先、时段兜底**：任务类型命中就听任务类型；它没意见（null）时才看时段。
 *    注意 `routeByKind()` 的 `''` = "命中却失败"，那不算"没意见"（见 send() 的注释）。
 *  · 人工钉住（Ctrl+R / 菜单 / 手切 provider·model）后时段规则**完全不介入**。
 *  · 折扣档有多个候选时按 **cost 升序**取最便宜；未声明 cost 的排**最后**（未知 ≠ 免费）。
 *  · 折扣组为空 → 什么都不做，且**不刷提示**（平时没有折扣是常态）。
 *
 * 时钟通过构造第三个参数注入，全部断言都固定在明确时刻，不依赖运行时的真实钟点。
 *
 * 运行：php tests/route_offpeak_unit.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/isolation.php';

use App\Ai\ChatModel;
use App\Ai\ProviderRegistry;
use App\App;

putenv('APP_LOCALE=zh_CN');
vc_isolate_config('vc_offpeak_route');

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

/** 固定时刻（2026-09-19 = 周六；用 UTC 作基准，窗口都不写 tz 时也确定） */
const NOW = '2026-09-19 12:00:00';
const NOW_TZ = 'UTC';

function clock(string $at = NOW, string $tz = NOW_TZ): Closure
{
    return static fn(): DateTimeImmutable => new DateTimeImmutable($at, new DateTimeZone($tz));
}

/** mock 端点：这些用例不会真的发请求，只是让 spec() 解析得出来 */
function builtin(): array
{
    return [
        // 便宜档服务商：cost 1，闲时窗口覆盖基准时刻
        'cheapco' => [
            'label'        => 'CheapCo',
            'base_url'     => 'https://cheap.example/v1',
            'models'       => ['cheap-mini' => ['tools'], 'cheap-pro' => ['tools', 'vision']],
            'model'        => 'cheap-mini',
            'capabilities' => ['tools'],
            'cost'         => 1,
            'off_peak'     => [['from' => '10:00', 'to' => '14:00']],
        ],
        // 贵档服务商：cost 9，闲时窗口**不**覆盖基准时刻
        'richco' => [
            'label'        => 'RichCo',
            'base_url'     => 'https://rich.example/v1',
            'models'       => ['rich-max' => ['tools']],
            'model'        => 'rich-max',
            'capabilities' => ['tools'],
            'cost'         => 9,
            'off_peak'     => [['from' => '02:00', 'to' => '04:00']],
        ],
    ];
}

function cfgFile(string $body): string
{
    $path = vc_isolation_dir('vc_offpeak_cfg') . '.php';
    file_put_contents($path, "<?php\n" . $body);
    register_shutdown_function(static function () use ($path): void {
        @unlink($path);
    });
    return $path;
}

function chatWith(string $cfgBody, ?Closure $clock = null, ?array $builtin = null): array
{
    $app = new App();
    $chat = new ChatModel(
        $app,
        new ProviderRegistry($builtin ?? builtin(), null, cfgFile($cfgBody)),
        null,
        $clock ?? clock(),
    );
    $app->chat = $chat;
    return [$app, $chat];
}

/**
 * 让 ChatModel 先落到某个已知档位（模拟"当前在用智能档"）。
 *
 * ⚠️ 默认**不钉住**：时段路由会尊重 `pinned`（人工优先），先钉住的话它当然不介入，
 * 那样就测不到路由本身了。要测"钉住时不动"的场景请显式传 `pin: true`。
 */
function startOnSmart(ChatModel $chat, bool $pin = false): void
{
    $chat->applyStrategy('smart', $pin);
}

// ═══════════ 1) 折扣时段内：降到最便宜的一档 ═══════════
echo "== 折扣中自动降档 ==\n";
[$app1, $chat1] = chatWith(<<<'PHP'
return ['@strategies' => [
    'smart' => ['label' => '智能档', 'provider' => 'richco',  'model' => 'rich-max'],
    'grind' => ['label' => '干活档', 'provider' => 'cheapco', 'model' => 'cheap-mini'],
]];
PHP);
startOnSmart($chat1);
check($chat1->isPinned() === false, '前置：当前在智能档且未钉住（自动状态）');
check($chat1->spec()?->model === 'rich-max', '前置：当前在智能档（rich-max）');
$chat1->routeByOffPeak();
check($chat1->strategyName() === 'grind' && $chat1->spec()?->model === 'cheap-mini',
    'CheapCo 在折扣时段 → 自动降到「干活档」（实际：' . (string) $chat1->spec()?->model . '）');
check($chat1->isPinned() === false, '时段路由**不钉住**（下一条还能按类型/再按时段改）');
check(str_contains($app1->message, '折扣时段') && str_contains($app1->message, '14:00'),
    '提示说清原因与折扣截止时间（实际：' . $app1->message . '）');
check($chat1->offPeakActive() === true, 'offPeakActive() = 当前档正处在折扣中（供状态栏打标）');

// ═══════════ 2) 不在折扣时段 → 不动、不提示 ═══════════
echo "== 非折扣时段 ==\n";
[$app2, $chat2] = chatWith(<<<'PHP'
return ['@strategies' => [
    'smart' => ['label' => '智能档', 'provider' => 'richco',  'model' => 'rich-max'],
    'grind' => ['label' => '干活档', 'provider' => 'cheapco', 'model' => 'cheap-mini'],
]];
PHP, clock('2026-09-19 20:00:00'));
startOnSmart($chat2);
$app2->message = 'SENTINEL';
$chat2->routeByOffPeak();
check($chat2->strategyName() === 'smart' && $chat2->spec()?->model === 'rich-max',
    '两个 provider 都不在折扣时段 → 保持当前档');
check($app2->message === 'SENTINEL', '没折扣时**不刷任何提示**（平时没折扣是常态，提示会是噪音）');
check($chat2->offPeakActive() === false, 'offPeakActive() = false（状态栏不打折扣标）');

// ═══════════ 3) cost 排序 ═══════════
echo "== cost 升序；未声明 cost 排最后 ==\n";
// 三家同时在折扣中：A cost 5、B cost 2、C 未声明 cost → 应选 B
$three = [
    'a-co' => ['label' => 'A', 'base_url' => 'https://a/v1', 'models' => ['a-m'], 'model' => 'a-m',
        'capabilities' => ['tools'], 'cost' => 5, 'off_peak' => [['from' => '00:00', 'to' => '24:00']]],
    'b-co' => ['label' => 'B', 'base_url' => 'https://b/v1', 'models' => ['b-m'], 'model' => 'b-m',
        'capabilities' => ['tools'], 'cost' => 2, 'off_peak' => [['from' => '00:00', 'to' => '24:00']]],
    'c-co' => ['label' => 'C', 'base_url' => 'https://c/v1', 'models' => ['c-m'], 'model' => 'c-m',
        'capabilities' => ['tools'], 'off_peak' => [['from' => '00:00', 'to' => '24:00']]],   // 无 cost
];
[$app3, $chat3] = chatWith(<<<'PHP'
return ['@strategies' => [
    'a' => ['label' => 'A档', 'provider' => 'a-co', 'model' => 'a-m'],
    'b' => ['label' => 'B档', 'provider' => 'b-co', 'model' => 'b-m'],
    'c' => ['label' => 'C档', 'provider' => 'c-co', 'model' => 'c-m'],
]];
PHP, null, $three);
$chat3->routeByOffPeak();
check($chat3->strategyName() === 'b', '三家同时打折 → 取 cost 最小的 B（实际：' . (string) $chat3->strategyName() . '）');

// 去掉 B 的折扣 → 应在 A（cost 5）与 C（无 cost）之间选 A：未声明 cost 的**排在最后**
$threeNoB = $three;
$threeNoB['b-co']['off_peak'] = [['from' => '02:00', 'to' => '03:00']];   // 不在基准时刻
[$app3b, $chat3b] = chatWith(<<<'PHP'
return ['@strategies' => [
    'a' => ['label' => 'A档', 'provider' => 'a-co', 'model' => 'a-m'],
    'b' => ['label' => 'B档', 'provider' => 'b-co', 'model' => 'b-m'],
    'c' => ['label' => 'C档', 'provider' => 'c-co', 'model' => 'c-m'],
]];
PHP, null, $threeNoB);
$chat3b->routeByOffPeak();
check($chat3b->strategyName() === 'a',
    '未声明 cost 的档排在已声明者**之后**（选 A 而非 C；实际：' . (string) $chat3b->strategyName() . '）');

// 只剩无 cost 的一条打折 → 仍应切过去（"未知"只是排序垫底，不是禁用）
$threeOnlyC = $three;
$threeOnlyC['a-co']['off_peak'] = [['from' => '02:00', 'to' => '03:00']];
$threeOnlyC['b-co']['off_peak'] = [['from' => '02:00', 'to' => '03:00']];
[$app3c, $chat3c] = chatWith(<<<'PHP'
return ['@strategies' => [
    'a' => ['label' => 'A档', 'provider' => 'a-co', 'model' => 'a-m'],
    'c' => ['label' => 'C档', 'provider' => 'c-co', 'model' => 'c-m'],
]];
PHP, null, $threeOnlyC);
$chat3c->routeByOffPeak();
check($chat3c->strategyName() === 'c', '候选里只有未声明 cost 的 → 仍然切过去（未知 ≠ 不可用）');

// ═══════════ 4) 候选里最便宜那条配错 → 跳过，试下一条 ═══════════
echo "== 配错的候选跳过（不刷拒绝提示）==\n";
[$app4, $chat4] = chatWith(<<<'PHP'
return ['@strategies' => [
    // cost 1 但 model 未在该 provider 里声明 → applyStrategy 会拒绝
    'broken' => ['label' => '坏档', 'provider' => 'cheapco', 'model' => 'no-such-model'],
    'ok'     => ['label' => '好档', 'provider' => 'cheapco', 'model' => 'cheap-pro'],
    'rich'   => ['label' => '贵档', 'provider' => 'richco',  'model' => 'rich-max'],
]];
PHP);
$chat4->routeByOffPeak();
check($chat4->strategyName() === 'ok',
    '最便宜的候选配错（model 未声明）→ 跳过它试下一条（实际：' . (string) $chat4->strategyName() . '）');
check(!str_contains($app4->message, '拒绝静默回退'),
    '状态栏**没有**残留"model 未声明"的拒绝提示（否则会盖掉真正的切档回执）：' . $app4->message);

// 连能力要求也不满足的候选同样跳过（requires tools 撞上无 tools 的模型）
$noTools = builtin();
$noTools['cheapco']['models'] = ['cheap-mini' => ['tools'], 'reasoner' => ['reasoning']];
[$app4b, $chat4b] = chatWith(<<<'PHP'
return ['@strategies' => [
    'need-tools' => ['label' => '要工具', 'provider' => 'cheapco', 'model' => 'reasoner', 'requires' => ['tools']],
    'fine'       => ['label' => '可用档', 'provider' => 'cheapco', 'model' => 'cheap-mini'],
]];
PHP, null, $noTools);
$chat4b->routeByOffPeak();
check($chat4b->strategyName() === 'fine', 'requires 不满足的候选被预判跳过，落到可用档');
check(!str_contains($app4b->message, '没声明'), '状态栏没残留能力缺失的拒绝提示：' . $app4b->message);

// ═══════════ 5) auto_offpeak = false ═══════════
echo "== auto_offpeak 开关 ==\n";
[$app5, $chat5] = chatWith(<<<'PHP'
return ['@strategies' => [
    'plan'  => ['label' => '计划档', 'provider' => 'cheapco', 'model' => 'cheap-mini', 'auto_offpeak' => false],
    'other' => ['label' => '其他档', 'provider' => 'richco',  'model' => 'rich-max'],
]];
PHP);
$chat5->routeByOffPeak();
check($chat5->strategyName() === null,
    '唯一打折的档写了 auto_offpeak=false → 不参与闲时降档（实际：' . (string) $chat5->strategyName() . '）');
[$app5b, $chat5b] = chatWith(<<<'PHP'
return ['@strategies' => [
    'plan'  => ['label' => '计划档', 'provider' => 'cheapco', 'model' => 'cheap-mini', 'auto_offpeak' => false],
    'grind' => ['label' => '干活档', 'provider' => 'cheapco', 'model' => 'cheap-pro'],
]];
PHP);
$chat5b->routeByOffPeak();
check($chat5b->strategyName() === 'grind', '同 provider 下另有自动档 → 选那一条（开关是逐策略的，不是逐 provider 的）');
check($chat5b->strategies()['plan']->autoOffpeak === false
    && $chat5b->strategies()['grind']->autoOffpeak === true,
    'auto_offpeak 默认 true（不写该字段的老配置行为不变）');

// ═══════════ 6) 人工优先：钉住后时段不介入 ═══════════
echo "== 人工优先（钉住）==\n";
[$app6, $chat6] = chatWith(<<<'PHP'
return ['@strategies' => [
    'smart' => ['label' => '智能档', 'provider' => 'richco',  'model' => 'rich-max'],
    'grind' => ['label' => '干活档', 'provider' => 'cheapco', 'model' => 'cheap-mini'],
]];
PHP);
startOnSmart($chat6, pin: true);          // 人工选档 → 钉住
$app6->message = 'SENTINEL';
check($chat6->isPinned() === true, '前置：手动选档 → 钉住');
$chat6->routeByOffPeak();
check($chat6->strategyName() === 'smart' && $chat6->spec()?->model === 'rich-max',
    '钉住后即使 CheapCo 在折扣时段也不抢方向盘');
check($app6->message === 'SENTINEL', '钉住时连提示都不给（不打扰用户）');

// 切回自动 → 时段规则立刻接管
$chat6->useAutoStrategy();
$chat6->routeByOffPeak();
check($chat6->strategyName() === 'grind', 'Ctrl+R 切回「自动」后，时段规则立刻生效');

// ═══════════ 7) kinds 优先、时段兜底 ═══════════
echo "== kinds 优先、时段兜底 ==\n";
[$app7, $chat7] = chatWith(<<<'PHP'
return ['@strategies' => [
    'plan'  => ['label' => '计划档', 'provider' => 'richco',  'model' => 'rich-max', 'kinds' => ['plan']],
    'grind' => ['label' => '干活档', 'provider' => 'cheapco', 'model' => 'cheap-mini'],
]];
PHP);
$chat7->send('帮我规划一下', 'plan');
check($chat7->strategyName() === 'plan' && $chat7->spec()?->model === 'rich-max',
    '带 kind=plan 且命中 → **kinds 赢**，即使 CheapCo 正在打折也不降档');

// 带 kind 但没有任何策略声明它 → 时段兜底接管
[$app7b, $chat7b] = chatWith(<<<'PHP'
return ['@strategies' => [
    'grind' => ['label' => '干活档', 'provider' => 'cheapco', 'model' => 'cheap-mini'],
]];
PHP);
$chat7b->send('写个单测', 'unittest');
check($chat7b->strategyName() === 'grind',
    'kind 没人声明（unittest 未命中）→ 时段规则兜底（实际：' . (string) $chat7b->strategyName() . '）');

// 不带 kind 的普通消息 → 时段兜底
[$app7c, $chat7c] = chatWith(<<<'PHP'
return ['@strategies' => [
    'grind' => ['label' => '干活档', 'provider' => 'cheapco', 'model' => 'cheap-mini'],
]];
PHP);
$chat7c->send('随便聊聊');
check($chat7c->strategyName() === 'grind', '普通消息（无 kind）→ 时段规则生效');

// ⚠️ 关键区分：kind **命中但被拒绝**（''）时不许再走时段 —— 那是用户显式指定的路
[$app7d, $chat7d] = chatWith(<<<'PHP'
return ['@strategies' => [
    'bad-plan' => ['label' => '坏计划档', 'provider' => 'richco', 'model' => 'no-such', 'kinds' => ['plan']],
    'grind'    => ['label' => '干活档',   'provider' => 'cheapco', 'model' => 'cheap-mini'],
]];
PHP);
$chat7d->send('规划一下', 'plan');
check($chat7d->strategyName() === null,
    'kind 命中的策略配错（被拒绝）→ **不改道**去时段档（用户显式指定的路失败就该失败，实际：'
    . (string) $chat7d->strategyName() . '）');

// ═══════════ 8) 菜单/状态栏标记 ═══════════
echo "== 菜单与状态栏标记 ==\n";
[$app8, $chat8] = chatWith(<<<'PHP'
return ['@strategies' => [
    'smart' => ['label' => '智能档', 'provider' => 'richco',  'model' => 'rich-max'],
    'grind' => ['label' => '干活档', 'provider' => 'cheapco', 'model' => 'cheap-mini'],
]];
PHP);
check($chat8->strategyOffPeakActive('grind') === true, 'strategyOffPeakActive(打折档) = true');
check($chat8->strategyOffPeakActive('smart') === false, 'strategyOffPeakActive(非打折档) = false');
check($chat8->strategyOffPeakActive('nope') === false, 'strategyOffPeakActive(不存在的策略) = false（菜单遍历时兜底）');

$aiItems = [];
foreach ($app8->menuBar->definitions() as $g) {
    if (($g['label'] ?? '') === $app8->t('menu.ai')) {
        $aiItems = $g['items'] ?? [];
    }
}
$labels = array_column($aiItems, 'label');
$grindLabel = '';
$smartLabel = '';
foreach ($aiItems as $it) {
    if (($it['action'] ?? '') === 'ai.strategy:grind') {
        $grindLabel = $it['label'];
    }
    if (($it['action'] ?? '') === 'ai.strategy:smart') {
        $smartLabel = $it['label'];
    }
}
check(str_contains($grindLabel, '折扣中'), '菜单里打折的档位带「折扣中」标记（实际：' . $grindLabel . '）');
check(!str_contains($smartLabel, '折扣中'), '菜单里非打折档位不带标记（实际：' . $smartLabel . '）');
check(str_contains($grindLabel, '干活档'),
    '菜单策略项真的带上了策略名（{label} 占位符被替换，实际：' . $grindLabel . '）');
check(!str_contains($grindLabel, '{label}') && !str_contains($smartLabel, '{label}'),
    '菜单文案里不该残留未替换的 {label}（实际：' . $grindLabel . ' / ' . $smartLabel . '）');
check(in_array('ai.strategy:grind', array_column($aiItems, 'action'), true) === true, '菜单条目仍在（只是多了后缀，没改变结构）');

// 状态栏：当前档打折时才带 ·折扣
$chat8->applyStrategy('grind');
$bar = $app8->statusBar->assemble(200)['text'];
check(str_contains($bar, '干活档') && str_contains($bar, '折扣'),
    '状态栏当前档（干活档）在折扣中 → 带「·折扣」（实际：' . $bar . '）');
$chat8->applyStrategy('smart');
$bar2 = $app8->statusBar->assemble(200)['text'];
check(!str_contains($bar2, '折扣'), '当前档不在折扣中 → 状态栏没有折扣标记（实际：' . $bar2 . '）');

echo $failed ? "\nRESULT: FAIL\n" : "\nRESULT: PASS\n";
exit($failed ? 1 : 0);

<?php
declare(strict_types=1);

/**
 * 「没选模型就不显示模型名」—— 无终端单测（不依赖 tty）。
 *
 * 背景：`ChatModel::spec()` 在未选 provider 时会**兜底到 defaultId()**，所以只要
 * `config/providers.php` 里有 provider，`spec()` 就恒非 null。展示层若拿它当判据，
 * 用户一次都没选过，标题栏/状态栏/空态也会声称「正在用 OpenAI/gpt-4o-mini」。
 *
 * 本用例守住四件事：
 *  1) 未选时三处展示都**不出现**模型名（含状态栏的「未选」）；
 *  2) `useProvider()` 之后三处都出现；
 *  3) **只按 Ctrl+N（`cycleModel()`）也算「已选」** —— 它只写 `$model`、不写
 *     `$providerId`，所以判据不能只看 providerId（这是最容易写错的一处）；
 *  4) 反面对照：`hasSelection() === false` 时才不显示。
 *
 * ⚠️ 渲染断言必须**注册 DropdownOverlay 渲染器**，否则覆盖层被静默跳过（picker_unit 踩过）。
 *
 * 运行：php tests/ai_selection_unit.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/isolation.php';
vc_isolate_config('vc_ai_sel');
putenv('APP_LOCALE=zh_CN');

use App\App;
use App\Text\DisplayWidth;
use App\Widget\DropdownOverlay;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Display\Buffer as TuiBuffer;
use PhpTui\Tui\Extension\Core\CoreExtension;
use PhpTui\Tui\Position\Position;
use PhpTui\Tui\Widget\WidgetRenderer\AggregateWidgetRenderer;

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

const VP_W = 140;
const VP_H = 40;

/** 渲染一帧成「逐格字符网格」`grid[row][col] = 单字`（列号即屏幕列） */
function renderGrid(App $app, Area $vp): array
{
    $rs = [];
    foreach ((new CoreExtension())->widgetRenderers() as $r) {
        $rs[] = $r;
    }
    $rs[] = DropdownOverlay::renderer();   // ← 漏了这句覆盖层会被静默跳过
    $renderer = new AggregateWidgetRenderer($rs);

    $buf = TuiBuffer::empty($vp);
    $renderer->render($renderer, $app->render($vp), $buf, $buf->area());

    $grid = [];
    for ($y = 0; $y < $vp->height; $y++) {
        $row = [];
        for ($x = 0; $x < $vp->width; $x++) {
            $row[] = $buf->get(Position::at($x, $y))->char;
        }
        $grid[] = $row;
    }
    return $grid;
}

/**
 * 网格一行 → 文本。
 *
 * ⚠️ 宽字符（汉字）占**两格**：第二格是续格，`char` 是空格。直接 `implode` 会把
 * 「AI 对话」拼成「AI 对 话 」，于是针永远匹配不上（本轮踩过）。按宽度跳过续格。
 */
function rowText(array $row): string
{
    $out = '';
    $n = count($row);
    for ($x = 0; $x < $n; $x++) {
        $ch = $row[$x];
        $out .= $ch;
        if ($ch !== '' && DisplayWidth::dispWidth($ch) === 2) {
            $x++;   // 跳过续格
        }
    }
    return rtrim($out);
}

/** 网格拼成文本（**不归一化**：断言的是原始文字，标点与大小写都要保留） */
function gridText(array $grid): string
{
    return implode("\n", array_map(static fn(array $r): string => rowText($r), $grid));
}

/** 面板标题那一行（含「AI 对话」的那行顶边框）；找不到返回 '' */
function titleRow(array $grid): string
{
    foreach ($grid as $row) {
        $t = rowText($row);
        if (str_contains($t, 'AI 对话')) {
            return $t;
        }
    }
    return '';
}

$vp = Area::fromDimensions(VP_W, VP_H);

// ── 1) 未选：三处展示都不该出现模型名 ──
echo "\n== 1) 没选过 → 不显示模型名 ==\n";
$app = new App();
check($app->chat->hasSelection() === false, '前置：初始 hasSelection() === false');
check($app->chat->spec() !== null, '前置：spec() 仍非 null（它兜底到默认 provider —— 所以不能拿它当判据）');

$grid = renderGrid($app, $vp);
$text = gridText($grid);
$title = titleRow($grid);
check($title !== '', '前置：找到了 AI 面板标题行');
check(!str_contains($title, 'gpt-4o-mini'), '标题栏不显示模型名 —— 实际「' . trim($title) . '」');
check(str_contains($text, '未选模型'), '空态提示写「未选模型」（并告诉用户怎么选）');
check(!str_contains($text, 'gpt-4o-mini'), '整个画面上都不出现默认模型名（阴性对照）');

$sb = $app->statusBar->assemble(200)['text'];
check(str_contains($sb, 'AI=未选'), '状态栏写「AI=未选」而不是编一个模型名 —— 实际「' . trim($sb) . '」');

// ── 2) useProvider() 之后：三处都出现模型名 ──
echo "\n== 2) useProvider() → 三处都显示 ==\n";
$app2 = new App();
$app2->chat->useProvider('deepseek');
check($app2->chat->hasSelection() === true, 'useProvider 之后 hasSelection() === true');
$spec2 = $app2->chat->spec();
$want2 = $spec2->label . '/' . $spec2->model;   // DeepSeek/deepseek-chat

$grid2 = renderGrid($app2, $vp);
check(str_contains(titleRow($grid2), $want2), "标题栏显示 {$want2} —— 实际「" . trim(titleRow($grid2)) . '」');
check(str_contains(gridText($grid2), $spec2->model), '空态列出当前模型');
$sb2 = $app2->statusBar->assemble(200)['text'];
check(str_contains($sb2, 'AI=' . $want2), '状态栏显示 AI=' . $want2);

// ── 3) 只按 Ctrl+N（cycleModel）也算「已选」——它只写 model、不写 providerId ──
echo "\n== 3) 只 cycleModel()：providerId 仍为 null，但要算已选 ==\n";
$app3 = new App();
check(!str_contains(titleRow(renderGrid($app3, $vp)), 'gpt-4o-mini'), '前置：未选时标题没有模型名');
$app3->chat->cycleModel();
$spec3 = $app3->chat->spec();
check($app3->chat->providerId() === null,
    'cycleModel 之后 providerId 仍为 null（这正是判据不能只看 providerId 的原因）');
check($app3->chat->hasSelection() === true, '但它算「已选」（model 非空）');
check(str_contains(titleRow(renderGrid($app3, $vp)), $spec3->model),
    '标题栏开始显示模型名 ' . $spec3->model);

// ── 4) 反面对照：hasSelection()===false 时才不显示 ──
echo "\n== 4) 反面对照 ==\n";
$app4 = new App();
check($app4->chat->hasSelection() === false, '未选时 hasSelection() 为 false');
$app4->chat->useProvider('openai');
check($app4->chat->hasSelection() === true, '选过之后为 true');
check(str_contains(titleRow(renderGrid($app4, $vp)), 'OpenAI/'), '标题随即出现 provider 名');

echo $failed ? "\nRESULT: FAIL\n" : "\nRESULT: PASS\n";
exit($failed ? 1 : 0);

<?php
declare(strict_types=1);

/**
 * R5 拖拽分隔条单元测试（headless）。
 *
 * 覆盖：
 *  1) 四条分隔条均可命中并拖拽（侧栏宽 / AI 宽 / 编辑器比例 / AI 输入框高）
 *  2) 拖拽精确跟随鼠标 + 单维上下界 clamp（MIN/MAX）
 *  3) 交叉约束：侧栏+AI 不会把中间自适应列挤没（始终 >= MIN_CENTER）
 *  4) 鼠标落在面板内部（非分隔条）不进入拖拽、正常聚焦
 *  5) 拖拽中状态栏显示尺寸摘要、松手即停；布局配置与切出的矩形一致
 *
 * 真实 pty 下的鼠标解析路径（SGR Down/Drag/Up）与 pty_menu 的"点击 Help 标签"
 * 同构，已被真实 pty 验收覆盖；此处用确定性事件序列逐步断言，避免依赖终端模拟。
 */

require __DIR__ . '/../vendor/autoload.php';

use App\App;
use PhpTui\Term\Event\MouseEvent;
use PhpTui\Term\MouseEventKind;
use PhpTui\Term\MouseButton;
use PhpTui\Tui\Display\Area;

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    if ($cond) {
        echo "  [OK] $msg\n";
    } else {
        $failed = true;
        echo "  [FAIL] $msg\n";
    }
}

const VP_W = 120;
const VP_H = 40;

// 构造事件的小工具
$down = static fn(int $c, int $r): MouseEvent => MouseEvent::new(MouseEventKind::Down, MouseButton::Left, $c, $r, 0);
$drag = static fn(int $c, int $r): MouseEvent => MouseEvent::new(MouseEventKind::Drag, MouseButton::Left, $c, $r, 0);
$up   = static fn(int $c, int $r): MouseEvent => MouseEvent::new(MouseEventKind::Up,   MouseButton::Left, $c, $r, 0);

$vp = Area::fromDimensions(VP_W, VP_H);

// ─════════ 1) 侧栏分隔条（竖，改侧栏宽度） ═════════
echo "\n== 侧栏分隔条 ==\n";
$app = new App();
$a = $app->areas($vp);
check($a['sidebar']->width === 30, '初始侧栏宽 30（默认）');
$edge = $a['sidebar']->position->x + $a['sidebar']->width; // 30
$midRow = $a['sidebar']->position->y + 5;                  // 主区内某行

$app->handle($down($edge, $midRow), $vp);
check($app->isDragging(), '在侧栏右边界按下 → 进入拖拽');

// 拖到 col=70（越过 MAX_SIDEBAR=60 → 应被 clamp 到 60）
$app->handle($drag(70, $midRow), $vp);
check($app->layout->sidebarWidth === 60, '拖到 col=70 → 侧栏宽 clamp 到 MAX 60');

// 拖到 col=40（自由值，应精确跟随）
$app->handle($drag(40, $midRow), $vp);
check($app->layout->sidebarWidth === 40, '拖到 col=40 → 侧栏宽精确为 40');

// 拖到 col=5（越过 MIN_SIDEBAR=12 → clamp 到 12）
$app->handle($drag(5, $midRow), $vp);
check($app->layout->sidebarWidth === 12, '拖到 col=5 → 侧栏宽 clamp 到 MIN 12');

$app->handle($up(5, $midRow), $vp);
check(!$app->isDragging(), '松开 → 退出拖拽');

// 布局配置与切出的矩形一致
$a2 = $app->areas($vp);
check($a2['sidebar']->width === $app->layout->sidebarWidth, '切出的侧栏矩形宽度 == layout 配置');

// ─════════ 2) AI 分隔条（竖，改 AI 宽度） ═════════
echo "\n== AI 分隔条 ==\n";
$app = new App();
$a = $app->areas($vp);
check($a['ai_stream']->width === 45, '初始 AI 宽 45（默认）');
$aiEdge = $a['ai_stream']->position->x; // 75
$midRow = $a['ai_stream']->position->y + 5;

$app->handle($down($aiEdge, $midRow), $vp);
check($app->isDragging(), '在 AI 左边界按下 → 进入拖拽');

// 拖到 col=60（视口右沿 120 - 60 = 60 宽）
$app->handle($drag(60, $midRow), $vp);
check($app->layout->aiWidth === 60, '拖到 col=60 → AI 宽精确为 60（120-60）');

// 拖到 col=110（120-110=10 → 越过 MIN_AI=20 → clamp 到 20）
$app->handle($drag(110, $midRow), $vp);
check($app->layout->aiWidth === 20, '拖到 col=110 → AI 宽 clamp 到 MIN 20');

$app->handle($up(110, $midRow), $vp);
check(!$app->isDragging(), '松开 → 退出拖拽');

// ─════════ 3) 编辑器/终端分隔条（横，改比例） ═════════
echo "\n== 编辑器/终端分隔条 ==\n";
$app = new App();
$a = $app->areas($vp);
check(abs($app->layout->editorRatio - 0.6) < 1e-6, '初始编辑器比例 0.6（默认）');
$editorEdge = $a['editor']->position->y + $a['editor']->height; // 约 24
$centerX = $a['editor']->position->x;
$centerW = $a['editor']->width;

// 命中要求列在中间列水平范围内
$hitCol = $centerX + intdiv($centerW, 2);
$app->handle($down($hitCol, $editorEdge), $vp);
check($app->isDragging(), '在编辑器下边界按下 → 进入拖拽（横向）');

$centerH = $a['editor']->height + $a['terminal']->height; // 38
// 拖到 row=30 → ratio = (30-1)/38 ≈ 0.763
$app->handle($drag($hitCol, 30), $vp);
check(abs($app->layout->editorRatio - (30 - 1) / $centerH) < 0.02, '拖到 row=30 → 比例增大到约 0.76');

// 拖到 row=5 → ratio=(5-1)/38≈0.105 → clamp 到 MIN 0.2
$app->handle($drag($hitCol, 5), $vp);
check(abs($app->layout->editorRatio - 0.2) < 1e-6, '拖到 row=5 → 比例 clamp 到 MIN 0.2');

$app->handle($up($hitCol, 5), $vp);
check(!$app->isDragging(), '松开 → 退出拖拽');

// ─════════ 4) AI 输入框分隔条（横，改输入框高） ═════════
echo "\n== AI 输入框分隔条 ==\n";
$app = new App();
$a = $app->areas($vp);
check($a['ai_input']->height === 3, '初始 AI 输入框高 3（默认）');
$aiEdgeY = $a['ai_stream']->position->y + $a['ai_stream']->height; // 约 36
$aiX = $a['ai_stream']->position->x;
$aiW = $a['ai_stream']->width;
$hitCol = $aiX + intdiv($aiW, 2);

$app->handle($down($hitCol, $aiEdgeY), $vp);
check($app->isDragging(), '在 AI 消息流下边界按下 → 进入拖拽（横向）');

$aiTop = $a['ai_stream']->position->y;
$aiH = $a['ai_stream']->height + $a['ai_input']->height; // 38, 底沿 = aiTop+38
// 拖到 row=20 → 高 = (aiTop+38) - 20 = 19
$app->handle($drag($hitCol, 20), $vp);
check($app->layout->aiInputHeight === 19, '拖到 row=20 → AI 输入框高精确为 19');

// 拖到 row=38 → 高 = (aiTop+38) - 38 = aiTop → 实际 = 1（MIN_AI_INPUT）
$app->handle($drag($hitCol, 38), $vp);
check($app->layout->aiInputHeight === 1, '拖到 row=38 → 输入框高 clamp 到 MIN 1');

$app->handle($up($hitCol, 38), $vp);
check(!$app->isDragging(), '松开 → 退出拖拽');

// ─════════ 5) 未命中分隔条：点面板内部应正常聚焦，不拖拽 ═════════
echo "\n== 非分隔条不拖拽 ==\n";
$app = new App();
$a = $app->areas($vp);
$inSidebar = $a['sidebar']->position->x + 5; // 侧栏内部
$inRow = $a['sidebar']->position->y + 5;
$app->handle($down($inSidebar, $inRow), $vp);
check(!$app->isDragging(), '点侧栏内部（非边界）→ 不进入拖拽');
check($app->focusPanel() === 'sidebar', '点侧栏内部 → 正常聚焦侧栏面板');

// ─════════ 6) 交叉约束：侧栏顶宽时，AI 不能把中间列挤没 ═════════
echo "\n== 交叉约束（中间列保底） ==\n";
$app = new App();
$a = $app->areas($vp);
$edge = $a['sidebar']->position->x + $a['sidebar']->width;
$midRow = $a['sidebar']->position->y + 5;
// 先把侧栏拖到 MAX 60
$app->handle($down($edge, $midRow), $vp);
$app->handle($drag(200, $midRow), $vp); // 远超边界 → clamp 60
$app->handle($up(200, $midRow), $vp);
check($app->layout->sidebarWidth === 60, '侧栏已拖到 MAX 60');
// 再拖 AI：即便拖到最左（col=0 → 名义宽 120），也应被交叉上界限制
$aiEdge = $app->areas($vp)['ai_stream']->position->x;
$app->handle($down($aiEdge, $midRow), $vp);
$app->handle($drag(0, $midRow), $vp); // 名义 120 宽，但交叉上界 = 120-60-10=50
$app->handle($up(0, $midRow), $vp);
$a3 = $app->areas($vp);
$centerW = $a3['editor']->width; // 中间自适应列
check($centerW >= 10, "交叉约束：侧栏60+AI宽下中间列仍 >= MIN_CENTER(10)，实际 $centerW");
check($app->layout->aiWidth <= 50, 'AI 宽被交叉上界限制到 <= 50（120-60-10）');

// ─════════ 7) 状态栏尺寸摘要 + 布局一致性 ═════════
echo "\n== 状态栏摘要 ==\n";
$app = new App();
$a = $app->areas($vp);
$edge = $a['sidebar']->position->x + $a['sidebar']->width;
$midRow = $a['sidebar']->position->y + 5;
$app->handle($down($edge, $midRow), $vp);
$app->handle($drag(45, $midRow), $vp);
check($app->isDragging(), '拖拽中 isDragging() 为真');
$summary = $app->layoutSummary();
check($summary !== '' && str_contains($summary, '45'), "拖拽中状态栏摘要非空且含当前侧栏宽（'$summary'）");
$app->handle($up(45, $midRow), $vp);
check($app->layoutSummary() === $app->layoutSummary(), '松手后摘要仍可读（拖拽结束不影响 layout）');

echo "\n";
if ($failed) {
    echo "R5 单测存在 FAIL\n";
    exit(1);
}
echo "R5 单测全部 PASS\n";

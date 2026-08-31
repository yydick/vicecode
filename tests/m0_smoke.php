<?php
declare(strict_types=1);

/**
 * M0 无终端冒烟测试（不依赖 tty/STDIN）：
 *  1) 渲染出六个面板的边框与标题；
 *  2) 鼠标点击命中测试 → 焦点切到对应面板；
 *  3) 侧栏 tab 行点击 → 切换 Explorer/GIT/Search；
 *  4) AI 输入框打字 + Enter → 消息进入聊天流；
 *  5) ↑ 在侧栏 → 文件选中上移。
 *
 * 运行：php tests/m0_smoke.php
 */

require __DIR__ . '/../vendor/autoload.php';

// M0 验收原按英文界面编写；固定 en 以匹配其标题断言（运行 app 默认已是 zh_CN）。
putenv('APP_LOCALE=en');

use App\App;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Display\Buffer;
use PhpTui\Tui\Extension\Core\CoreExtension;
use PhpTui\Tui\Widget\WidgetRenderer\AggregateWidgetRenderer;
use PhpTui\Term\Event\MouseEvent;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\MouseEventKind;
use PhpTui\Term\MouseButton;
use PhpTui\Term\KeyCode;

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? "  [OK] " : "  [FAIL] ") . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

$app = new App();
$vp = Area::fromDimensions(120, 40);

// 渲染到 Buffer
$ext = new CoreExtension();
$renderers = [];
foreach ($ext->widgetRenderers() as $r) {
    $renderers[] = $r;
}
$renderer = new AggregateWidgetRenderer($renderers);
$buffer = Buffer::empty($vp);
$renderer->render($renderer, $app->render($vp), $buffer, $buffer->area());
$text = implode("\n", $buffer->toLines());

echo "== 面板标题渲染 ==\n";
foreach (['SIDEBAR', 'EDITOR', 'TERMINAL', 'AI CHAT', 'AI INPUT', 'ViceCode · focus'] as $needle) {
    check(str_contains($text, $needle), "渲染输出包含标题: $needle");
}

echo "== 点击命中测试 ==\n";
$a = $app->areas($vp);
$click = function (Area $ar) use ($app, $vp): void {
    $c = $ar->position->x + intdiv($ar->width, 2);
    $r = $ar->position->y + intdiv($ar->height, 2);
    $app->handle(MouseEvent::new(MouseEventKind::Down, MouseButton::Left, $c, $r, 0), $vp);
};
$click($a['editor']);
check($app->focusPanel() === 'editor', '点击编辑器中心 → 焦点切到 editor');
$click($a['ai_input']);
check($app->focusPanel() === 'ai_input', '点击 AI 输入框 → 焦点切到 ai_input');

echo "== AI 输入发送 ==\n";
// M5 起 AI 面板不再自带占位回复：消息归 ChatModel，发送会真的去调 Provider。
// headless 下没有 API key，故这里断言的是"用户消息入列 + 输入框清空"，
// 而不是 M0 时代的"+2 条（含假回复）"。
$before = count($app->chat->messages());
foreach (['h', 'i'] as $ch) {
    $app->handle(CharKeyEvent::new($ch, 0), $vp);
}
$app->handle(CharKeyEvent::new("\r", 0), $vp);
check(count($app->chat->messages()) === $before + 1, '输入 "hi" + Enter → 用户消息入列');
check($app->ai->input() === '', '发送后输入框清空');

echo "== 侧栏 tab 切换 ==\n";
$sb = $a['sidebar'];
$seg2x = $sb->position->x + intdiv($sb->width, 3) + 1;     // 第二段（GIT）
$app->handle(MouseEvent::new(MouseEventKind::Down, MouseButton::Left, $seg2x, $sb->position->y + 1, 0), $vp);
check($app->sidebar->tabIndex === 1, '点击侧栏 tab 第 2 段 → 切到 GIT');

echo "== 侧栏 ↓ 移动选中 ==\n";
// 先切回 Explorer tab（上面刚切到 GIT tab；GIT tab 的 ↓ 走 git 导航，故这里回到 Explorer 验证树导航）
$app->handle(MouseEvent::new(MouseEventKind::Down, MouseButton::Left, $sb->position->x + 1, $sb->position->y + 1, 0), $vp);
check($app->sidebar->tabIndex === 0, '点击侧栏 tab 第 1 段 → 切回 Explorer');
$app->focusIndex = array_search('sidebar', App::PANELS);
$sel0 = $app->selectedPath;
$app->handle(CodedKeyEvent::new(KeyCode::Down, 0), $vp);
check($app->selectedPath !== '' && $app->selectedPath !== $sel0, '侧栏 ↓ → 文件选中下移');

echo $failed ? "\nRESULT: FAIL\n" : "\nRESULT: PASS\n";
exit($failed ? 1 : 0);

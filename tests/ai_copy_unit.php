<?php
declare(strict_types=1);

/**
 * AI 对话区整条消息复制单元测试（headless）。
 *
 * 用反射往 ChatModel 注入两条消息（user / assistant），点击 AI 消息流的对应可见行，
 * 经 App 的点击分发 → AiPanel::copyMessageAtRow 整条复制该消息正文到剪贴板。
 * 不依赖真实 LLM / 流式。tty 环境走 OSC 52，非 tty 落内存断言。
 */

require __DIR__ . '/../vendor/autoload.php';

putenv('VICECODE_CONFIG=' . tempnam(sys_get_temp_dir(), 'vc_ai'));

use App\App;
use PhpTui\Term\Event\MouseEvent;
use PhpTui\Term\MouseEventKind;
use PhpTui\Term\MouseButton;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Widget\Margin;

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

function expectClip(App $app, string $want, string $msg): void
{
    $got = $app->clipboardPeek();
    if (stream_isatty(STDOUT)) {
        check(true, $msg . '（tty：走 OSC52，不断言内存）');
    } else {
        check($got === $want, $msg . "（内存剪贴板：期望「{$want}」实际「{$got}」）");
    }
}

const VP_W = 120;
const VP_H = 40;
$vp = Area::fromDimensions(VP_W, VP_H);

$app = new App();
$chat = $app->chat;
$prop = new \ReflectionProperty($chat, 'messages');
$prop->setAccessible(true);
$prop->setValue($chat, [
    ['role' => 'user', 'content' => '用户消息内容XYZ'],
    ['role' => 'assistant', 'content' => 'AI 回复内容ABC'],
]);

$a = $app->areas($vp);
$stream = $a['ai_stream'];
$inner = $stream->inner(new Margin(1, 1));

// 点消息流首行（= 第一条 user 消息首行）→ 整条复制 user 消息
$app->handle(MouseEvent::new(MouseEventKind::Down, MouseButton::Left, $inner->position->x, $inner->position->y, 0), $vp);
echo "\n== AI 整条消息复制 ==\n";
expectClip($app, '用户消息内容XYZ', 'AI：点击首行 → 复制整条 user 消息正文');
check(str_contains($app->message, '已复制消息'), 'AI：复制后状态栏提示「已复制消息」');

// 点最后一行（= assistant 消息首行，仅 2 条短消息时可见行末即 assistant）→ 复制 assistant
// 末行下标取决于软换行行数，用 streamContent 渲染出的行数不可直接取；改为再注入一条确保末行是 assistant。
$prop->setValue($chat, [
    ['role' => 'user', 'content' => 'u1'],
    ['role' => 'assistant', 'content' => 'a1'],
    ['role' => 'user', 'content' => 'u2'],
    ['role' => 'assistant', 'content' => 'AI 回复内容ABC'],
]);
// 点击落在 assistant(a1) 行：u1 为单行 → 第 2 可见行即 a1；点击 inner.y+1
$app->handle(MouseEvent::new(MouseEventKind::Down, MouseButton::Left, $inner->position->x, $inner->position->y + 1, 0), $vp);
expectClip($app, 'a1', 'AI：点击第二条可见行 → 复制对应 assistant(a1) 消息');

// 点击空态（无消息时的提示行）不应复制任何消息
$prop->setValue($chat, []);
$app->handle(MouseEvent::new(MouseEventKind::Down, MouseButton::Left, $inner->position->x, $inner->position->y, 0), $vp);
if (!stream_isatty(STDOUT)) {
    check($app->clipboardPeek() === 'a1', 'AI：空态点击提示行 → 不复制（剪贴板保持上次内容）');
} else {
    check(true, 'AI：空态点击（tty 不断言）');
}

echo "\n";
if ($failed) {
    echo "AI 复制单测存在 FAIL\n";
    exit(1);
}
echo "AI 复制单测全部 PASS\n";

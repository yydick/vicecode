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
        return;
    }
    // 超长正文只比对、不回显（否则一条 40k 的消息会把测试输出刷爆）
    if (mb_strlen($want) > 60) {
        check(
            $got === $want,
            $msg . sprintf('（内存剪贴板：长度期望 %d 实际 %d）', mb_strlen($want), mb_strlen($got))
        );
        return;
    }
    check($got === $want, $msg . "（内存剪贴板：期望「{$want}」实际「{$got}」）");
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

// ───── 滚动后点击：可见行 → 全量行的映射必须带 scroll 偏移 ─────
echo "\n== AI 滚动后复制（防「复制错消息」回归）==\n";
$many = [];
for ($i = 0; $i < 80; $i++) {
    $many[] = ['role' => $i % 2 === 0 ? 'user' : 'assistant', 'content' => "msg$i"];
}
$prop->setValue($chat, $many);
$app->render($vp); // 触发 streamContent：算 lineCount 并把 follow 滚到底
$scroll = $app->ai->scroll();
check($scroll > 0, "消息数超过视口高度 → 已滚动（scroll=$scroll > 0）");
$app->clipboardCopy('EMPTY');
$app->handle(MouseEvent::new(MouseEventKind::Down, MouseButton::Left, $inner->position->x, $inner->position->y, 0), $vp);
expectClip($app, 'msg' . $scroll, 'AI：滚动后点首行 → 复制**可见**那条（全量第 ' . $scroll . ' 行）而非 msg0');
$app->handle(MouseEvent::new(MouseEventKind::Down, MouseButton::Left, $inner->position->x, $inner->position->y + $inner->height - 1, 0), $vp);
expectClip($app, 'msg79', 'AI：滚动后点末行 → 复制最后一条消息 msg79');

// ───── 超长消息：软换行不崩、行数与宽度自洽、复制拿到完整正文 ─────
echo "\n== AI 超长消息 ==\n";
$long = str_repeat('字', 20000); // 40000 显示列，远超面板宽
$prop->setValue($chat, [['role' => 'user', 'content' => 'hi'], ['role' => 'assistant', 'content' => $long]]);
$threw = false;
$t0 = microtime(true);
try {
    $app->render($vp);
} catch (\Throwable $e) {
    $threw = true;
    $msg = $e->getMessage();
}
$firstFrameMs = (microtime(true) - $t0) * 1000;
check(!$threw, '20k CJK 超长消息渲染不抛异常' . ($threw ? " ($msg)" : ''));
$rows = $app->ai->lineCount();
check(
    $rows >= 2 && $rows * $inner->width >= 40000,
    sprintf('超长消息软换行行数 %d 与视口宽 %d 自洽（不截断、不漏行）', $rows, $inner->width)
);
// 流式追加期间每帧都重排，超长回复若退化成 O(n²) 会越打字越卡
$t0 = microtime(true);
for ($f = 0; $f < 20; $f++) {
    $prop->setValue($chat, [['role' => 'user', 'content' => 'hi'], ['role' => 'assistant', 'content' => $long . str_repeat('字', 200 * $f)]]);
    $app->render($vp);
}
$perFrame = ((microtime(true) - $t0) * 1000) / 20;
check($perFrame < 100, sprintf('超长消息流式追加单帧 %.1fms < 100ms（首帧 %.1fms）', $perFrame, $firstFrameMs));
$app->clipboardCopy('EMPTY');
$app->handle(MouseEvent::new(MouseEventKind::Down, MouseButton::Left, $inner->position->x, $inner->position->y + $inner->height - 1, 0), $vp);
expectClip($app, $long . str_repeat('字', 200 * 19), 'AI：超长消息点末行 → 复制完整正文（未被截断）');

echo "\n";
if ($failed) {
    echo "AI 复制单测存在 FAIL\n";
    exit(1);
}
echo "AI 复制单测全部 PASS\n";

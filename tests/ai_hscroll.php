<?php
declare(strict_types=1);

/**
 * AI 面板「超长消息 + 横向滚动」边界。
 *
 * 两条已修 bug（详见 docs/BUGFIXES.md B8 / B9）：
 *  - **B8 hScroll 无上界**：消息行是先软换行再渲染的，行宽本就不超过面板宽，
 *    横滚不会露出任何新内容，只会把左侧切掉、右侧留白；没有上界时一路滚下去
 *    **整片空白**（内容像丢了）。现在按「最宽行 − 视口宽」钳制，通常上界为 0。
 *  - **B9 续行缩进把行撑宽**：折行按整宽 $W 折，续行又加 4 列缩进（`AI: `）→
 *    续行实际 $W+4 列，末尾被切掉，长消息的续行末尾字符凭空消失。
 *    现在正文按「扣除缩进后的宽度」折行。
 *
 * 运行：php tests/ai_hscroll.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\App;
use App\Text\DisplayWidth;
use PhpTui\Term\Event\MouseEvent;
use PhpTui\Term\MouseButton;
use PhpTui\Term\MouseEventKind;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Display\Buffer as TuiBuffer;
use PhpTui\Tui\Extension\Core\CoreExtension;
use PhpTui\Tui\Widget\Margin;
use PhpTui\Tui\Widget\WidgetRenderer\AggregateWidgetRenderer;

$vp = Area::fromDimensions(120, 40);

$ext = new CoreExtension();
$renderers = [];
foreach ($ext->widgetRenderers() as $r) {
    $renderers[] = $r;
}
$renderer = new AggregateWidgetRenderer($renderers);

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

// 可数序的长串（0000-0001-…）：拼接回原文时能精确校验"有没有字符被吃掉"
$long = '';
for ($i = 0; $i < 100; $i++) {
    $long .= sprintf('%04d-', $i);
}
$cjk = str_repeat('中文测试', 60);   // 480 列 CJK

/** 造 App：注入消息、可选预设 hScroll，返回 [app, ai区域, hScroll反射属性] */
$mk = static function (array $messages, int $hScroll = 0) use ($vp): array {
    $app = new App();
    $prop = new ReflectionProperty($app->chat, 'messages');
    $prop->setAccessible(true);
    $prop->setValue($app->chat, $messages);
    $ai = $app->areas($vp)['ai_stream'];
    $hp = new ReflectionProperty($app->ai, 'hScroll');
    $hp->setAccessible(true);
    if ($hScroll > 0) {
        $hp->setValue($app->ai, $hScroll);
    }
    return [$app, $ai, $hp];
};

/** 渲染消息流，返回非空行文本数组 */
$draw = static function (App $app, Area $ai) use ($renderer): array {
    // 极小视口下面板区域会退化成 0 宽，buffer 至少要 1×1 才能渲染（产品侧本就不画）
    $area = Area::fromDimensions(max(1, $ai->width), max(1, $ai->height));
    $buf = TuiBuffer::empty($area);
    $renderer->render($renderer, $app->ai->streamContent(max(0, $ai->width - 2), max(0, $ai->height - 2)), $buf, $buf->area());
    $out = [];
    foreach ($buf->toLines() as $l) {
        $t = rtrim($l);
        if ($t !== '') {
            $out[] = $t;
        }
    }
    return $out;
};

echo "== 超长消息：软换行后每行都不超宽（含续行缩进）==\n";
[$app1, $ai1] = $mk([
    ['role' => 'user', 'content' => 'short'],
    ['role' => 'assistant', 'content' => $long],
]);
$W = $ai1->width - 2;
$rows1 = $draw($app1, $ai1);
$maxW = 0;
foreach ($rows1 as $t) {
    $maxW = max($maxW, DisplayWidth::dispWidth($t));
}
check(count($rows1) > 3, '长消息被软换行成多行（实际 ' . count($rows1) . ' 行）');
check($maxW <= $W, "所有行都不超过面板宽（最宽 {$maxW} ≤ {$W}）—— 续行缩进不能把行撑宽");

echo "== 超长消息：续行末尾字符没被吃掉（拼接回原文）==\n";
// 去掉每行前 4 列（首行前缀 'AI: ' / 续行缩进都是 4 列）再拼接，应还原原文
$joined = '';
foreach ($rows1 as $t) {
    if (!str_contains($t, 'AI:') && !str_starts_with($t, '    ')) {
        continue; // user 行
    }
    $joined .= DisplayWidth::mbSubDisp($t, 4, PHP_INT_MAX >> 8);
}
check($joined === $long, '拼接后与原文完全一致（实际长度 ' . mb_strlen($joined) . ' / 期望 ' . mb_strlen($long) . '）');

echo "== CJK 超长消息：同样不超宽、不劈字 ==\n";
[$appC, $aiC] = $mk([
    ['role' => 'user', 'content' => 'x'],
    ['role' => 'assistant', 'content' => $cjk],
]);
$rowsC = $draw($appC, $aiC);
$maxC = 0;
$broken = false;
foreach ($rowsC as $t) {
    $w = DisplayWidth::dispWidth($t);
    $maxC = max($maxC, $w);
    if (str_contains($t, "\x{FFFD}")) {
        $broken = true;
    }
}
check($maxC <= $aiC->width - 2, "CJK 长消息行不超宽（最宽 {$maxC}）");
check(!$broken, 'CJK 长消息折行未劈开汉字（无替换符）');

echo "== 横滚上界：滚过头不能变成白板（B8）==\n";
[$app2, $ai2, $hp2] = $mk([
    ['role' => 'user', 'content' => 'short'],
    ['role' => 'assistant', 'content' => $long],
], 500);
$rows2 = $draw($app2, $ai2);
check((int) $hp2->getValue($app2->ai) === 0, '预设 hScroll=500 → 渲染时被钳回 0（行宽本就不超面板宽）');
check(count($rows2) > 3, '横滚到底后画面仍有内容（不是空白）');

[$app3, $ai3, $hp3] = $mk([
    ['role' => 'user', 'content' => 'short'],
    ['role' => 'assistant', 'content' => $long],
]);
for ($i = 0; $i < 100; $i++) {
    $app3->ai->onScrollH(4);   // 模拟连续 Shift+→ / 触控板横滑
}
$rows3 = $draw($app3, $ai3);
check((int) $hp3->getValue($app3->ai) < $W, '连续横滚 100 次（+400 列）后 hScroll 仍未越过上界');
check(count($rows3) > 3, '连续横滚后画面仍有内容（内容不会"消失"）');

echo "== 横滚状态下点击复制仍复制整条消息 ==\n";
[$app4, $ai4] = $mk([
    ['role' => 'user', 'content' => 'u1'],
    ['role' => 'assistant', 'content' => 'a1'],
], 50);
$inner = $ai4->inner(new Margin(1, 1));
$app4->handle(MouseEvent::new(
    MouseEventKind::Down, MouseButton::Left,
    $inner->position->x, $inner->position->y, 0), $vp);
$clip = $app4->clipboardPeek();
check($clip === 'u1', '预设 hScroll=50 后点首行 → 复制的仍是首条消息（期望「u1」实际「' . $clip . '」）');

echo "== 极窄视口不崩 ==\n";
foreach ([[30, 8], [20, 6], [12, 6]] as [$vw, $vh]) {
    try {
        $app = new App();
        $prop = new ReflectionProperty($app->chat, 'messages');
        $prop->setAccessible(true);
        $prop->setValue($app->chat, [
            ['role' => 'user', 'content' => 'u'],
            ['role' => 'assistant', 'content' => $long],
        ]);
        $small = Area::fromDimensions($vw, $vh);
        $ai = $app->areas($small)['ai_stream'];
        $hp = new ReflectionProperty($app->ai, 'hScroll');
        $hp->setAccessible(true);
        $hp->setValue($app->ai, 200);
        $rows = $draw($app, $ai);      // 极窄视口下区域可能退化，只要不抛异常
        check(true, "极小视口 {$vw}x{$vh}：超大 hScroll 下渲染不抛异常（非空行 " . count($rows) . '）');
    } catch (Throwable $e) {
        check(false, "极小视口 {$vw}x{$vh}：抛异常 " . $e->getMessage());
    }
}

echo $failed ? "\nRESULT: FAIL\n" : "\nRESULT: PASS\n";
exit($failed ? 1 : 0);

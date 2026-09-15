<?php
declare(strict_types=1);

/**
 * AI V2 Markdown 渲染 —— 无终端单测：
 *  1) MarkdownFormatter 块级/行内元素 → 逻辑行；
 *  2) 单换行保留为独立逻辑行（终端聊天的排版根基）；
 *  3) spanWrapDisp 拼接不变量（各段拼接 == 原拼接，多宽度扫描）；
 *  4) AiPanel 集成：定稿消息走 markdown、流式消息走纯文本、缓存命中；
 *  5) 带样式的行软换行不超宽（含 CJK）。
 *
 * 运行：php tests/ai_md_unit.php
 */

require __DIR__ . '/../vendor/autoload.php';
putenv('APP_LOCALE=zh_CN');
putenv('VICECODE_CONFIG=' . tempnam(sys_get_temp_dir(), 'vice_md_'));

use App\Ai\MarkdownFormatter;
use App\App;
use App\Core\Theme;
use App\Text\DisplayWidth;
use PhpTui\Tui\Display\Area as TuiArea;
use PhpTui\Tui\Display\Buffer as TuiBuffer;
use PhpTui\Tui\Extension\Core\CoreExtension;
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

/** 逻辑行 → 纯文本（剥样式） */
function lineText(array $line): string
{
    $t = '';
    foreach ($line as $span) {
        $t .= is_array($span) ? $span[0] : '?';
    }
    return $t;
}

/** 逻辑行列表 → 文本行数组 */
function allText(array $lines): array
{
    return array_map('lineText', $lines);
}

$theme = Theme::default();
$fmt = new MarkdownFormatter($theme);

// ─────────────── 1) 块级/行内 ───────────────
echo "== MarkdownFormatter 块级/行内 ==\n";

$lines = $fmt->format("# 标题\n\n正文 `code` 与 [链接](https://x.com) 和 **粗体** 与 *斜体*。\n");
$t = allText($lines);
check(($t[0] ?? '') === '# 标题', '标题带 # 前缀（实际：' . var_export($t[0] ?? null, true) . '）');
check(str_contains($t[1] ?? '', '正文 code 与 链接 (https://x.com) 和 粗体 与 斜体。'),
    '行内 code/链接(文字+url)/粗/斜 全部展开为纯文本（实际：' . var_export($t[1] ?? null, true) . '）');
$spans1 = $lines[1];
$codeStyled = false;
$linkStyled = false;
foreach ($spans1 as [$text, $style]) {
    if ($text === 'code') {
        $codeStyled = $style->fg !== null;
    }
    if ($text === '链接') {
        $linkStyled = $style->fg !== null;
    }
}
check($codeStyled, '行内 code 有 mdCode 主题色');
check($linkStyled, '链接文字有 mdLink 主题色');

// 列表（有序/无序/嵌套）
$lines = $fmt->format("- 甲\n- 乙\n  - 丙\n\n1. 其一\n2. 其二\n");
$t = allText($lines);
check($t[0] === '• 甲' && $t[1] === '• 乙', '无序列表 • 标记');
check(trim($t[2]) === '• 丙' && str_starts_with($t[2], '  '), '嵌套列表缩进（实际：' . var_export($t[2], true) . '）');
check($t[3] === '1. 其一' && $t[4] === '2. 其二', '有序列表带编号（实际：' . var_export(array_slice($t, 3, 2), true) . '）');

// 引用 / 分隔线 / 围栏代码块
$lines = $fmt->format("> 引用行\n\n---\n\n```php\n<?php\necho 1;\n```\n");
$t = allText($lines);
check($t[0] === '│ 引用行', '引用 │ 前缀');
check($t[1] === '────────────', '分隔线');
check($t[2] === '  <?php' && $t[3] === '  echo 1;', '围栏代码块逐行保留（缩进 2 列）');
$hlStyled = false;
foreach ($lines[3] as [, $style]) {
    if ($style->fg !== null) {
        $hlStyled = true;
    }
}
check($hlStyled, '代码块行有语法高亮着色（scrivo）');

// 不支持语言 / 空 info 的围栏 → 纯文本不丢（代码块统一缩进 2 列）
$lines = $fmt->format("```\nplain\nlines\n```\n");
$t = allText($lines);
check($t === ['  plain', '  lines'], '无语言围栏纯文本逐行保留（实际：' . json_encode($t, JSON_UNESCAPED_UNICODE) . '）');

// ─────────────── 2) 单换行保留 ───────────────
echo "\n== 单换行保留为独立逻辑行 ==\n";
$src = "第一步\n第二步\n第三步";
$lines = $fmt->format($src);
$t = allText($lines);
check($t === ['第一步', '第二步', '第三步'], "软换行不折成空格（实际：" . json_encode($t, JSON_UNESCAPED_UNICODE) . '）');

// ─────────────── 3) spanWrapDisp 拼接不变量 ───────────────
echo "\n== spanWrapDisp 拼接不变量 ==\n";

$cases = [
    'pure ascii 0123456789 abcdef',
    str_repeat('中文测试', 30),
    '混排ABC中文DEF测试' . str_repeat('x', 40) . '尾巴中文',
    'a`b**c**d' . str_repeat('字', 25),
];
$styles = [Theme::default()->style('aiUser'), Theme::default()->style('mdCode'), Theme::default()->style('aiDim')];
$okAll = true;
$detail = '';
foreach ($cases as $ci => $full) {
    // 把原文切成任意形状的 span（测跨 span 切行）
    $spans = [];
    $pos = 0;
    $si = 0;
    while ($pos < mb_strlen($full)) {
        $len = ($ci + $si) % 3 + 1;
        $spans[] = [mb_substr($full, $pos, $len), $styles[$si % count($styles)]];
        $pos += $len;
        $si++;
    }
    $srcConcat = '';
    foreach ($spans as $s) {
        $srcConcat .= $s[0];
    }
    foreach ([2, 3, 5, 8, 13, 40, 120] as $W) { // W=1 时 2 列宽的 CJK 单字必然超宽（与 mbWrapDisp 同语义），不纳入
        $wrapped = DisplayWidth::spanWrapDisp($spans, $W);
        $gotConcat = '';
        $over = false;
        foreach ($wrapped as $phys) {
            $lw = 0;
            foreach ($phys as $s) {
                $gotConcat .= $s[0];
                $lw += DisplayWidth::dispWidth($s[0]);
            }
            if ($lw > $W) {
                $over = true;
            }
        }
        if ($gotConcat !== $srcConcat || $over) {
            $okAll = false;
            $detail = "case$ci W=$W 拼接" . ($gotConcat === $srcConcat ? '等' : '不等') . ($over ? ' 超宽' : '');
            break 2;
        }
    }
}
check($okAll, '多 case × 多宽度：所有物理行拼接==原文且无超宽行' . ($detail !== '' ? "（失败于 {$detail}）" : ''));

// 宽度 0 退化
check(DisplayWidth::spanWrapDisp([['abc', null]], 0) === [[]], '宽度 0 → 单空行，不崩不负数');

// ─────────────── 4) AiPanel 集成 ───────────────
echo "\n== AiPanel 集成（流式纯文本 / 定稿 markdown / 缓存）==\n";

$ext = new CoreExtension();
$rlist = [];
foreach ($ext->widgetRenderers() as $r) {
    $rlist[] = $r;
}
$renderer = new AggregateWidgetRenderer($rlist);

$app = new App();
$prop = new ReflectionProperty($app->chat, 'messages');
$prop->setAccessible(true);
$prop->setValue($app->chat, [
    ['role' => 'user', 'content' => '问题'],
    ['role' => 'assistant', 'content' => "# 结论\n\n- 甲\n- 乙"],
]);
$vp = TuiArea::fromDimensions(120, 40);
$buf = TuiBuffer::empty($vp);
$renderer->render($renderer, $app->render($vp), $buf, $buf->area());
$txt = implode("\n", $buf->toLines());
check(str_contains($txt, '# 结论') && str_contains($txt, '• 甲'), '定稿消息走 markdown 渲染（标题前缀+列表标记可见）');

// 流式中的末条 assistant：markdown 符号原样可见（纯文本路径）+ 光标
$prop->setValue($app->chat, [
    ['role' => 'user', 'content' => '问题'],
    ['role' => 'assistant', 'content' => "# 结论\n\n- 甲"],
]);
$ref = new ReflectionClass($app->chat);
$st = $ref->getProperty('streaming');
$st->setAccessible(true);
$st->setValue($app->chat, true);
$buf = TuiBuffer::empty($vp);
$renderer->render($renderer, $app->render($vp), $buf, $buf->area());
$txt = implode("\n", $buf->toLines());
check(str_contains($txt, '# 结论') && str_contains($txt, '▌'), '流式中消息走纯文本（## 原样 + 光标在出字）');
$st->setValue($app->chat, false);

// 缓存命中：第二次渲染不再重算（mdCache 已有键）
$prop->setValue($app->chat, [
    ['role' => 'user', 'content' => '问题'],
    ['role' => 'assistant', 'content' => "# 结论\n\n- 甲\n- 乙"],
]);
$app->render($vp);
$cacheProp = new ReflectionProperty($app->ai, 'mdCache');
$cacheProp->setAccessible(true);
$cacheBefore = $cacheProp->getValue($app->ai);
check(count($cacheBefore) > 0, '定稿消息渲染后有缓存条目（' . count($cacheBefore) . ' 条）');
$app->render($vp);
$cacheAfter = $cacheProp->getValue($app->ai);
check($cacheBefore === $cacheAfter, '同内容二次渲染缓存命中（缓存未新增未变化）');

// 内容变了缓存键跟着变
$prop->setValue($app->chat, [
    ['role' => 'user', 'content' => '问题'],
    ['role' => 'assistant', 'content' => "# 结论2\n\n- 丙"],
]);
$app->render($vp);
$cacheAfter2 = $cacheProp->getValue($app->ai);
check(count($cacheAfter2) > count($cacheAfter), '内容变化 → 新缓存键');

// ─────────────── 5) 带样式行软换行不超宽（CJK）───────────────
echo "\n== 集成渲染无超宽行 ==\n";
$prop->setValue($app->chat, [
    ['role' => 'user', 'content' => '问' . str_repeat('很长', 60)],
    ['role' => 'assistant', 'content' => str_repeat('回答很长', 60) . "\n\n- " . str_repeat('列表项很长', 40)],
]);
$over = [];
foreach ([[120, 40], [60, 20], [40, 10]] as [$W, $H]) {
    $vpx = TuiArea::fromDimensions($W, $H);
    $bx = TuiBuffer::empty($vpx);
    $renderer->render($renderer, $app->render($vpx), $bx, $bx->area());
    $bad = false;
    foreach ($bx->toLines() as $l) {
        if (mb_strwidth($l) > $W) {
            $bad = true;
        }
    }
    $over[] = "{$W}×{$H}" . ($bad ? '超宽' : 'ok');
}
check(implode(' ', $over) === '120×40ok 60×20ok 40×10ok', '三种视口整屏无超宽行（实际：' . implode(' ', $over) . '）');

echo "\n" . ($failed ? "SOME FAILED\n" : "RESULT: PASS\n");
exit($failed ? 1 : 0);

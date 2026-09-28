<?php
declare(strict_types=1);

/**
 * AI 面板 / Markdown 渲染边界深挖（V2.1）。
 *
 * 三类真问题（都在本轮先用 tests/probe_ai_edge.php 取了最小样本，再修）：
 *  1) **块级子节点内容丢失**：列表项 / 引用块里的子节点除 Paragraph 外还可能是
 *     围栏代码块、嵌套列表、引用、标题——它们的 `children()` 为空（代码在 `getLiteral()`），
 *     原实现一律按行内展开 → 内容**整段静默消失**（`- 步骤` 下的代码块、`> - a` 的列表）。
 *  2) **不可信内容直通终端**：AI 回复与 `@文件`/工具读到的文件内容里的 `\x1b]52;…\x07`
 *     能改写系统剪贴板、`\x1b[2J` 能清屏；TAB 则因终端按 8 列制表位展开，
 *     与 `dispWidth()` 的 1 列算法不一致 → 该行错位/越界。
 *  3) **极窄面板首行超宽**：前缀（`You: ` / `AI: `）宽于面板宽时，首行 = 前缀 + 至少 1 列
 *     正文，远超 W；php-tui 的 LineTruncator 超宽会**折行**，把后续行整体挤下去（幽灵行）。
 *
 * 运行：php tests/ai_edge_unit.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Ai\MarkdownFormatter;
use App\App;
use App\Core\Theme;
use App\Text\DisplayWidth;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Display\Buffer as TuiBuffer;
use PhpTui\Tui\Extension\Core\CoreExtension;
use PhpTui\Tui\Widget\WidgetRenderer\AggregateWidgetRenderer;

require __DIR__ . '/lib/isolation.php';

putenv('APP_LOCALE=zh_CN');
vc_isolate_config('vc_ai_edge');   // 独占配置目录：见 lib/isolation.php 顶部说明

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

$ext = new CoreExtension();
$renderers = [];
foreach ($ext->widgetRenderers() as $r) {
    $renderers[] = $r;
}
$renderer = new AggregateWidgetRenderer($renderers);
$vp = Area::fromDimensions(120, 40);

/** 造 App 并注入消息（不读真实家目录配置） */
function mkApp(array $messages): App
{
    $app = new App();
    $p = new ReflectionProperty($app->chat, 'messages');
    $p->setAccessible(true);
    $p->setValue($app->chat, $messages);
    return $app;
}

/** 取面板「未横滚的物理行 + 消息下标映射」（走生产同一条 buildLinesWithMap） */
function rowsOf(App $app, int $W): array
{
    $m = new ReflectionMethod($app->ai, 'buildLinesWithMap');
    $m->setAccessible(true);
    return $m->invoke($app->ai, $W);
}

/** 行文本拼接（供净化/拼接不变量断言） */
function spanText(array $lines): string
{
    $out = '';
    foreach ($lines as $line) {
        foreach ($line->spans as $sp) {
            $out .= $sp->content;
        }
        $out .= "\n";
    }
    return $out;
}

/** 单行文本（不含换行，供拼接不变量用） */
function lineText(object $line): string
{
    $s = '';
    foreach ($line->spans as $sp) {
        $s .= $sp->content;
    }
    return $s;
}

/** 单行显示宽度 */
function lineWidth(object $line): int
{
    $w = 0;
    foreach ($line->spans as $sp) {
        $w += DisplayWidth::dispWidth($sp->content);
    }
    return $w;
}

/** MarkdownFormatter 逻辑行 → 纯文本行 */
function mdText(array $logical): array
{
    $out = [];
    foreach ($logical as $spans) {
        $s = '';
        foreach ($spans as [$t, ]) {
            $s .= $t;
        }
        $out[] = $s;
    }
    return $out;
}

$fmt = new MarkdownFormatter(Theme::default());

// ── 1) 块级子节点不再静默丢内容 ─────────────────────────────────
echo "== Markdown 结构：列表/引用里的块级子节点都保留 ==\n";
$cases = [
    ["- 步骤一\n\n  ```php\n  \$x = 1;\n  ```\n", ['$x = 1'], '列表项内的围栏代码块'],
    ["> ```php\n> \$y = 2;\n> ```\n", ['$y = 2'], '引用块内的围栏代码块'],
    ["> - alpha\n> - beta\n", ['• alpha', '• beta'], '引用块内的无序列表'],
    ["> 1. 甲\n> 2. 乙\n", ['1. 甲', '2. 乙'], '引用块内的有序列表'],
    ["- 顶部\n\n  > 引用内容\n", ['引用内容'], '列表项内的引用块'],
    ["- 顶部\n\n  ## 小标题\n", ['## 小标题'], '列表项内的标题（前缀与层级不丢）'],
    ["> > 双层引用\n", ['双层引用'], '引用里再嵌套引用'],
];
foreach ($cases as [$md, $needles, $label]) {
    $got = implode('|', mdText($fmt->format($md)));
    $missing = [];
    foreach ($needles as $n) {
        if (!str_contains($got, $n)) {
            $missing[] = $n;
        }
    }
    check($missing === [], $label . '（实际：' . $got . '）');
}

echo "== 既有排版零回归（列表标记 / 缩进 / 引用前缀）==\n";
$reg = mdText($fmt->format("- 甲\n- 乙\n  - 丙\n\n1. 其一\n2. 其二\n"));
check($reg[0] === '• 甲' && $reg[1] === '• 乙', '无序列表 • 标记不变');
check(trim($reg[2]) === '• 丙' && str_starts_with($reg[2], '  '), '嵌套列表缩进不变（实际：' . var_export($reg[2], true) . '）');
check($reg[3] === '1. 其一' && $reg[4] === '2. 其二', '有序列表编号不变');
$qt = mdText($fmt->format("> 引用行\n\n```php\n<?php\necho 1;\n```\n"));
check($qt[0] === '│ 引用行', '引用段落前缀与正文不变（实际：' . var_export($qt[0], true) . '）');
check($qt[1] === '  <?php' && $qt[2] === '  echo 1;', '顶层围栏代码块缩进 2 列不变');
$empty = mdText($fmt->format("- \n- 乙\n"));
check(in_array('• ', $empty, true) || count(array_filter($empty, static fn($t) => str_contains($t, '• '))) === 2,
    '空列表项不吞掉标记（实际：' . json_encode($empty, JSON_UNESCAPED_UNICODE) . '）');

// ── 2) 不可信内容净化（终端转义注入 / 制表符）────────────────────
echo "== 不可信内容：ESC / OSC / TAB / 其它 C0 进不了 span ==\n";
$payload = "\x1b]52;c;cC1pbmplY3RlZA==\x07";          // OSC 52 写剪贴板
$evil = $payload . "\x1b[2J" . "\x1b[31m" . "\tTAB" . "\x00\x07\x1f";

$appU = mkApp([['role' => 'user', 'content' => '前' . $evil . '后']]);
$txtU = spanText(rowsOf($appU, 60)['lines']);
check(!str_contains($txtU, "\x1b"), '纯文本路径：ESC 未进入 span（转义注入面已封）');
check(!str_contains($txtU, "\t"), '纯文本路径：TAB 已展开（dispWidth 与终端制表位不再打架）');
check(preg_match('/[\x00-\x08\x0b-\x1f\x7f]/', $txtU) !== 1, '纯文本路径：其它 C0 控制字符全部剔除');
check(str_contains($txtU, '前') && str_contains($txtU, '后') && str_contains($txtU, 'TAB'),
    '净化不吞正文：前后标记与 TAB 的文字内容仍在');

$appA = mkApp([
    ['role' => 'user', 'content' => 'u'],
    ['role' => 'assistant', 'content' => "普通行" . $evil . "\n\n```php\n\tif (\$a) {\n\t}\n```\n"],
]);
$txtA = spanText(rowsOf($appA, 60)['lines']);
check(!str_contains($txtA, "\x1b"), 'Markdown 路径：ESC 未进入 span');
check(!str_contains($txtA, "\t"), 'Markdown 路径：围栏代码块里的 TAB 也已展开');
check(str_contains($txtA, '    if ($a) {'), '围栏内的 TAB 展开为 4 空格（内容保留、缩进可读）');

// 工具调用行 / 工具摘要行同样净化（它们是模型给的 JSON 参数与文件内容）
$appT = mkApp([
    ['role' => 'user', 'content' => 'u'],
    ['role' => 'assistant', 'content' => '', 'tool_calls' => [[
        'id' => 'c1', 'type' => 'function',
        'function' => ['name' => 'read_file', 'arguments' => '{"path":"\u001b]52;c;cC1pbmplY3RlZA==\u0007\tx.txt"}'],
    ]]],
    ['role' => 'tool', 'content' => 'x', 'meta' => ['display' => "读取\t文件\x1b[31m完成"]],
]);
$txtT = spanText(rowsOf($appT, 80)['lines']);
check(!str_contains($txtT, "\x1b") && !str_contains($txtT, "\t"), '工具调用/工具摘要行同样净化（含 JSON 参数里的转义）');

// 输入框（粘贴进来的内容也是不可信输入）
$appI = mkApp([]);
$paste = "粘贴\x1b[31m红\x07\t内容";
$appI->ai->insertText($paste);
$want = DisplayWidth::mbWrapDisp(DisplayWidth::sanitizeContent('> ' . $paste), 40);
$want[count($want) - 1] .= '▌';                    // 光标块贴末行末位（与生产同序）
$area = Area::fromDimensions(40, 3);
$buf = TuiBuffer::empty($area);
$renderer->render($renderer, $appI->ai->inputContent(40, 3), $buf, $buf->area());
$gotI = array_map(static fn(string $l): string => rtrim($l), $buf->toLines());
$wantLines = array_map(static fn(string $l): string => rtrim($l), $want);
$wantLines = array_slice($wantLines, -3);
while (count($wantLines) < 3) {
    array_unshift($wantLines, '');
}
check($gotI === $wantLines, '输入框显示同样净化（实际：' . json_encode($gotI, JSON_UNESCAPED_UNICODE) . '）');
check(!str_contains(implode('', $gotI), "\x1b"), '输入框渲染缓冲里没有 ESC');
check(str_contains(implode('', $gotI), '内容'), '净化不吞输入框正文');

// ── 3) 极窄宽度：物理行一律不超过面板宽 ──────────────────────────
echo "== 极窄面板：所有物理行都不超过面板宽 ==\n";
$adversarial = [
    ['role' => 'user', 'content' => '中文内容中文内容中文内容'],
    ['role' => 'assistant', 'content' => "中文回复内容\n\n```php\n\$code = 1; // 注释中文\n```\n\n> 引用中文引用"],
    ['role' => 'assistant', 'content' => '', 'tool_calls' => [[
        'id' => 'c1', 'type' => 'function',
        'function' => ['name' => 'read_file', 'arguments' => '{"path":"一个很长很长的中文路径/文件名.php"}'],
    ]]],
    ['role' => 'tool', 'content' => '正文', 'meta' => ['display' => '读取 中文文件.php 完成']],
];
foreach ([2, 3, 4, 5, 6, 8, 10, 16] as $W) {
    $rows = rowsOf(mkApp($adversarial), $W)['lines'];
    $over = [];
    foreach ($rows as $k => $line) {
        $lw = lineWidth($line);
        if ($lw > $W) {
            $over[] = $k . '(' . $lw . ')';
        }
    }
    check($over === [], "W={$W}：无越界行" . ($over === [] ? '' : '（越界：' . implode(',', array_slice($over, 0, 5)) . '）'));
}
// W=1 是物理上无解的边界（2 列宽字素装不进 1 列），只要求不崩
$rows1 = rowsOf(mkApp($adversarial), 1)['lines'];
check(true, 'W=1：不抛异常（非空行 ' . count($rows1) . '）');

// ── 4) 超长围栏代码块：不丢字符（拼接不变量）+ 不超宽 ────────────
echo "== 超长围栏代码块（单行 4000 字符 + 200 行）==\n";
$codeLine = str_repeat('$variable_name = some_function_call(12345, "abcdefghij"); ', 70);
$fence = "```php\n" . $codeLine . "\n" . str_repeat("  \$i++;\n", 200) . "```\n";
$W = 40;
$r = rowsOf(mkApp([['role' => 'user', 'content' => 'u'], ['role' => 'assistant', 'content' => $fence]]), $W);
$sel = [];
foreach ($r['lines'] as $k => $line) {
    if (($r['map'][$k] ?? -1) === 1) {
        $sel[] = $line;
    }
}
check(count($sel) > 200, '超长围栏被折成 200+ 物理行（实际 ' . count($sel) . '）');
$maxW = 0;
foreach ($sel as $line) {
    $maxW = max($maxW, lineWidth($line));
}
check($maxW <= $W, "折行后每行都不超宽（最宽 {$maxW} <= {$W}）");

// 拼接不变量：逐行剥掉首行前缀 'AI: ' / 续行缩进（都是 4 列）后拼回，应等于逻辑行拼接
$joined = '';
foreach ($sel as $line) {
    $joined .= DisplayWidth::mbSubDisp(lineText($line), 4, PHP_INT_MAX >> 8);
}
$expected = '';
foreach ($fmt->format($fence) as $spans) {
    foreach ($spans as [$t, ]) {
        $expected .= $t;
    }
}
check($joined === $expected,
    '折行拼接 == 逻辑行拼接（无字符丢失/重复；实际 ' . mb_strlen($joined) . ' / 期望 ' . mb_strlen($expected) . '）');
check(str_contains($joined, 'abcdefghij'), '超长代码行内容完整（拼接结果含原文片段）');

// 极窄宽度下同一个超长围栏也不丢字符
$rN = rowsOf(mkApp([['role' => 'user', 'content' => 'u'], ['role' => 'assistant', 'content' => $fence]]), 6);
$selN = [];
foreach ($rN['lines'] as $k => $line) {
    if (($rN['map'][$k] ?? -1) === 1) {
        $selN[] = $line;
    }
}
$joinedN = '';
foreach ($selN as $line) {
    $joinedN .= DisplayWidth::mbSubDisp(lineText($line), 4, PHP_INT_MAX >> 8);
}
check($joinedN === $expected, 'W=6 极窄下拼接仍等于逻辑行拼接（前缀截断不影响正文完整性）');

echo $failed ? "\nRESULT: FAIL\n" : "\nRESULT: PASS\n";
exit($failed ? 1 : 0);

<?php
declare(strict_types=1);

/**
 * 探针（非测试，run_tests 按 *probe* 跳过）：AI 面板 / Markdown 渲染边界取证。
 * 目的：在动手修之前，先用最小样本确认问题真实存在。
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Ai\MarkdownFormatter;
use App\App;
use App\Core\Theme;
use App\Text\DisplayWidth;

function dumpSpans(array $lines): string
{
    $out = [];
    foreach ($lines as $spans) {
        $s = '';
        foreach ($spans as [$t, ]) {
            $s .= $t;
        }
        $out[] = $s;
    }
    return implode("\n", $out);
}

$fmt = new MarkdownFormatter(Theme::default());

echo "== 1) 列表项内的围栏代码块 ==\n";
$md1 = "- 步骤一\n\n  ```php\n  \$x = 1;\n  ```\n";
$o1 = dumpSpans($fmt->format($md1));
echo $o1 . "\n";
echo '含 "$x = 1" ? ' . (str_contains($o1, '$x = 1') ? 'YES' : 'NO（内容丢失）') . "\n\n";

echo "== 2) 引用块内的围栏代码块 ==\n";
$md2 = "> ```php\n> \$y = 2;\n> ```\n";
$o2 = dumpSpans($fmt->format($md2));
echo $o2 . "\n";
echo '含 "$y = 2" ? ' . (str_contains($o2, '$y = 2') ? 'YES' : 'NO（内容丢失）') . "\n\n";

echo "== 3) 引用块内的列表 ==\n";
$md3 = "> - alpha\n> - beta\n";
$o3 = dumpSpans($fmt->format($md3));
echo $o3 . "\n";
echo '含 alpha ? ' . (str_contains($o3, 'alpha') ? 'YES' : 'NO（内容丢失）') . "\n\n";

echo "== 4) 列表项内的嵌套引用 / 标题 ==\n";
$md4 = "- 顶部\n\n  > 引用内容\n\n  ## 标题\n";
$o4 = dumpSpans($fmt->format($md4));
echo $o4 . "\n";
echo '含 引用内容 ? ' . (str_contains($o4, '引用内容') ? 'YES' : 'NO（内容丢失）') . "\n";
echo '含 标题 ? ' . (str_contains($o4, '标题') ? 'YES' : 'NO（内容丢失）') . "\n\n";

echo "== 5) 控制字符 / 制表符是否透传到 span（经 AiPanel 真实行构建）==\n";
$mkApp = static function (array $messages) {
    $app = new App();
    $p = new ReflectionProperty($app->chat, 'messages');
    $p->setAccessible(true);
    $p->setValue($app->chat, $messages);
    return $app;
};
$buildRows = static function (App $app, int $W): array {
    $m = new ReflectionMethod($app->ai, 'buildLinesWithMap');
    $m->setAccessible(true);
    return $m->invoke($app->ai, $W)['lines'];
};
$spanText = static function (array $lines): string {
    $all = '';
    foreach ($lines as $line) {
        foreach ($line->spans as $sp) {
            $all .= $sp->content;
        }
        $all .= "\n";
    }
    return $all;
};

$app5 = $mkApp([
    ['role' => 'user', 'content' => "普通文本\x1b[31m红\x1b]52;c;cGF3bg==\x07 结束\n```\n\tif (\$a) {\n\t}\n```\n"],
]);
$pos5 = $spanText($buildRows($app5, 60));
echo '含 ESC(0x1b) ? ' . (str_contains($pos5, "\x1b") ? 'YES（会写进终端 ❌）' : 'NO ✓') . "\n";
echo '含 TAB(0x09) ? ' . (str_contains($pos5, "\t") ? 'YES（制表位错位 ❌）' : 'NO ✓') . "\n";
echo '含 OSC52 载荷 ? ' . (str_contains($pos5, ']52;c;cGF3bg==') ? 'YES（可改写剪贴板 ❌）' : 'NO ✓') . "\n";
echo '含其它 C0 ? ' . (preg_match('/[\x00-\x08\x0b-\x1f\x7f]/', $pos5) === 1 ? 'YES ❌' : 'NO ✓') . "\n";

echo "\n== 6) 越界极窄宽度：物理行不得超过面板宽（经 AiPanel 真实行构建）==\n";
foreach ([2, 3, 4, 5, 6, 8] as $W) {
    $app6 = $mkApp([
        ['role' => 'user', 'content' => '中文内容中文内容'],
        ['role' => 'assistant', 'content' => "中文回复\n\n```php\n\$code = 1;\n```\n"],
    ]);
    $lines6 = $buildRows($app6, $W);
    $over = [];
    foreach ($lines6 as $k => $line) {
        $lw = 0;
        foreach ($line->spans as $sp) {
            $lw += DisplayWidth::dispWidth($sp->content);
        }
        if ($lw > $W) {
            $over[] = "行{$k}={$lw}列";
        }
    }
    echo "  W={$W}: 行数=" . count($lines6) . ' 越界行=' . (implode(',', $over) ?: '无 ✓') . "\n";
}

echo "\n== 7) 超长围栏代码块（单行 3000 字符 + 200 行）==\n";
$big = "```php\n" . str_repeat('$variable_name = some_function_call(12345, "abcdefghij"); ', 70) . "\n"
    . str_repeat("  \$i++;\n", 200) . "```\n";
$t0 = microtime(true);
$lines = $fmt->format($big);
$dt = (microtime(true) - $t0) * 1000;
$maxW = 0;
foreach ($lines as $spans) {
    $w = 0;
    foreach ($spans as [$t, ]) {
        $w += DisplayWidth::dispWidth($t);
    }
    $maxW = max($maxW, $w);
}
echo '  逻辑行数=' . count($lines) . '，最宽=' . $maxW . '，解析耗时=' . round($dt, 1) . "ms\n";
$wrapped = DisplayWidth::spanWrapDisp($lines[0], 40);
$maxPhys = 0;
foreach ($wrapped as $ph) {
    $w = 0;
    foreach ($ph as [$t, ]) {
        $w += DisplayWidth::dispWidth($t);
    }
    $maxPhys = max($maxPhys, $w);
}
echo '  首个逻辑行按 40 列折行后最宽=' . $maxPhys . '（应 ≤ 40）' . "\n";

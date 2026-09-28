<?php
declare(strict_types=1);

/**
 * 文档链接完整性：markdown 里的**相对链接**与**文内锚点**是否可达。
 *
 * ## 为什么需要它
 * markdown 的坏链没有任何别的机制能发现：渲染器不校验、跑批也不读 md。
 * 而文档一改（重命名标题、挪文件、拆目录）链接就会**静默**烂掉。本项目
 * 文档量不小（README×2 / CHANGELOG / docs/ 6 份 / 使用手册×2），靠人肉点一遍
 * 不现实 —— 尤其「目录跳转」这类锚点，肉眼扫过去根本看不出错。
 *
 * ## 校验什么
 *   ① 相对文件链接的目标存在（`](docs/plugins.md)`、`](../CHANGELOG.md)`）；
 *   ② `#锚点` 以及 `文件.md#锚点` 能命中目标文件里的**真实标题**。
 *
 * ## 不校验什么（有意为之）
 *   ③ **http(s) 外链**：跑批不联网。只统计条数并打印，不做可达性判断
 *      （联网校验会让跑批依赖网络，反而不可靠）。
 *   ④ 锚点按 **GitHub（github-slugger）** 的 slug 规则生成。其它渲染器
 *      （某些 IDE 预览、静态站生成器）规则略有差异；本项目文档以 GitHub
 *      为主要阅读场景，故以此为准。
 *
 * ## 阴性断言配了正向锚点
 * 「无坏链」这种断言最容易**假通过**（文件没扫到、glob 写错、链接一个没匹配上，
 * 都会让 `$bad === []` 恒真）。所以末尾额外断言「确实发现了文档」「确实扫到了
 * 链接」，扫不到东西时测试会红而不是绿。
 *
 * 运行：php tests/docs_links.php
 */

$root = dirname(__DIR__);

$failed = false;

function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

/**
 * GitHub（github-slugger）风格的标题锚点：
 * 小写 → 去掉标点/控制字符 → 空格转连字符。
 *
 * 保留字母/数字/组合符/空白/下划线/连字符，其余一律剔除。这与 github-slugger
 * 那个超长 Unicode 标点正则在**本项目标题**上的效果一致，两个边界值得记：
 *   - `&` 被剔除但两侧空格都保留 → `A & B` 得到 `a--b`（**双**连字符，不折叠）；
 *   - 全角标点 `（）：、` 属标点类，同样被剔除（`（默认）` 不留痕）。
 */
function vc_doc_slug(string $heading): string
{
    $h = mb_strtolower($heading, 'UTF-8');
    $h = preg_replace('/[^\p{L}\p{N}\p{M}\s_-]/u', '', $h) ?? '';
    return str_replace(' ', '-', $h);
}

/**
 * 收集一个 md 文件里全部标题的 slug。
 *
 * 重复标题按 github-slugger 的约定追加 `-1` / `-2`……否则同名的第二个标题
 * 在 GitHub 上会得到带后缀的锚点，而这里的校验会误判成"命中"。
 *
 * 代码围栏（``` / ~~~）内的 `# 注释` 不是标题，必须跳过。
 *
 * @return string[]
 */
function vc_doc_heading_slugs(string $path): array
{
    $seen = [];
    $out = [];
    $fence = null;
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^\s{0,3}(`{3,}|~{3,})/', $line, $m) === 1) {
            $mark = $m[1][0];
            $fence = $fence === $mark ? null : ($fence ?? $mark);
            continue;
        }
        if ($fence !== null) {
            continue;
        }
        if (preg_match('/^(#{1,6})\s+(.*?)\s*$/', $line, $m) !== 1) {
            continue;
        }
        $slug = vc_doc_slug($m[2]);
        $n = $seen[$slug] ?? 0;
        $seen[$slug] = $n + 1;
        $out[] = $n === 0 ? $slug : $slug . '-' . $n;
    }
    return $out;
}

/** 待校验的文档：仓库根 *.md + docs/*.md（不递归到 vendor/node_modules） */
function vc_doc_files(string $root): array
{
    $out = [];
    foreach (['/*.md', '/docs/*.md'] as $pattern) {
        foreach (glob($root . $pattern) ?: [] as $p) {
            $out[] = $p;
        }
    }
    sort($out);
    return $out;
}

echo "== 扫描范围 ==\n";
$files = vc_doc_files($root);
foreach ($files as $p) {
    printf("  %-40s %d 个标题\n", substr($p, strlen($root) + 1), count(vc_doc_heading_slugs($p)));
}
// 正向锚点：glob 写错 / 文档被移走时，下面的"无坏链"会假通过，这里先钉住
check(count($files) >= 5, '发现 ' . count($files) . ' 个 markdown 文件（>= 5）');

$slugCache = [];
$slugOf = static function (string $abs) use (&$slugCache): array {
    return $slugCache[$abs] ??= vc_doc_heading_slugs($abs);
};

$checked = 0;
$external = 0;
$badFiles = [];    // "源文件:行号 -> 目标"
$badAnchors = [];  // "源文件:行号 -> 目标#锚点"

foreach ($files as $abs) {
    $rel = substr($abs, strlen($root) + 1);
    $dir = dirname($abs);
    $ownSlugs = $slugOf($abs);

    $fence = null;
    foreach (file($abs, FILE_IGNORE_NEW_LINES) as $i => $line) {
        $lineNo = $i + 1;

        // 围栏内是示例代码，里面的 `](...)` 不该被当成真实链接
        if (preg_match('/^\s{0,3}(`{3,}|~{3,})/', $line, $m) === 1) {
            $mark = $m[1][0];
            $fence = $fence === $mark ? null : ($fence ?? $mark);
            continue;
        }
        if ($fence !== null) {
            continue;
        }

        // 先剥掉**行内代码**（`...`）：里面出现的 `](...)` 是示例文本，不是链接。
        // ⚠️ 这条不是可选项——本文件自己的文档注释、CHANGELOG 里都会写 `` `](...)` ``
        // 这种示例，不剥就会误报「目标不存在：... 」。链接文字含代码是合法的
        // （`` [`x`](a.md) ``），剥掉后剩下的 `](a.md)` 仍能被下面匹配到，不受影响。
        $scan = preg_replace('/`[^`]*`/', '', $line) ?? $line;
        if (preg_match_all('/\]\(([^)\s]+)\)/u', $scan, $mm) === 0) {
            continue;
        }
        foreach ($mm[1] as $target) {
            if (preg_match('#^(https?|mailto):#i', $target) === 1) {
                $external++;
                continue;
            }
            [$filePart, $anchor] = array_pad(explode('#', $target, 2), 2, null);
            $checked++;

            if ($filePart === '') {
                // 纯文内锚点
                if ($anchor !== null && !in_array($anchor, $ownSlugs, true)) {
                    $badAnchors[] = "$rel:$lineNo  #$anchor";
                }
                continue;
            }

            $targetAbs = realpath($dir . '/' . urldecode($filePart));
            if ($targetAbs === false || !file_exists($targetAbs)) {
                $badFiles[] = "$rel:$lineNo  -> $filePart";
                continue;
            }
            // 跨文件的 `xxx.md#anchor` 才校验锚点；指向 .php 等文件的 `#...` 不适用
            if ($anchor !== null && $anchor !== '' && str_ends_with($targetAbs, '.md')
                && !in_array($anchor, $slugOf($targetAbs), true)) {
                $badAnchors[] = "$rel:$lineNo  -> $filePart#$anchor";
            }
        }
    }
}

echo "\n== 相对文件链接 ==\n";
foreach ($badFiles as $b) {
    echo "  [FAIL] 目标不存在：$b\n";
}
check($badFiles === [], '全部相对文件链接的目标存在' . ($badFiles ? '（' . count($badFiles) . " 条失效）" : ''));

echo "\n== 文内锚点（含跨文件 文件.md#锚点）==\n";
foreach ($badAnchors as $b) {
    echo "  [FAIL] 锚点未命中任何标题：$b\n";
}
check($badAnchors === [], '全部锚点命中真实标题' . ($badAnchors ? '（' . count($badAnchors) . " 条失效）" : ''));

// 正向锚点：一条链接都没扫到 → 上面的两个"空数组"断言毫无意义
check($checked > 0, "确实扫到了链接（共 $checked 条待校验）");

echo "\n外链（http/https）$external 条：跑批不联网，只统计不校验可达性。\n";

echo $failed ? "\n文档链接校验 FAIL\n" : "\n文档链接校验全部 PASS\n";
exit($failed ? 1 : 0);

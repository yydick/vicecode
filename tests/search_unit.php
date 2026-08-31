<?php
declare(strict_types=1);

/**
 * M4 搜索面板 —— 无终端单测（不依赖 tty）：
 *  1) SearchClient::buildCommand 命令构造（选项 / 排除目录 / 危险输入）；
 *  2) SearchClient::parseLine 解析 grep -rn 输出（含冒号的 text、带空格路径、噪声行）；
 *  3) SearchClient::group 按文件分组；
 *  4) SearchModel 增量摄入 + 可见行映射 + 折叠 + 上限截断；
 *  5) 渲染：App 切到 Search tab、注入结果，输出含文件名/命中/状态行；折叠后命中行消失；
 *  6) 交互：App::handle 驱动（字符进 query / Backspace / 空 query 回车 / 回车跳转定位到行）；
 *  7) 真实 grep：临时目录跑一遍，结果与基准 grep 一致且排除目录生效。
 *
 * 运行：php tests/search_unit.php
 */

require __DIR__ . '/../vendor/autoload.php';
putenv('APP_LOCALE=zh_CN');

use App\App;
use App\Search\SearchClient;
use App\Search\SearchGroup;
use App\Search\SearchHit;
use App\Search\SearchRow;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Extension\Core\CoreExtension;
use PhpTui\Tui\Widget\WidgetRenderer\AggregateWidgetRenderer;
use PhpTui\Tui\Display\Buffer as TuiBuffer;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\MouseEventKind;

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

// ─────────────── 1) buildCommand ───────────────
echo "== buildCommand ==\n";
$cmd = SearchClient::buildCommand('foo');
check(str_contains($cmd, 'grep -rnI -F -e '), '命令含 grep -rnI -F -e（递归/行号/跳二进制/固定串/显式 pattern）');
check(str_contains($cmd, escapeshellarg('foo')), 'query 经 escapeshellarg 包裹');
check(str_ends_with($cmd, ' .'), '搜索目标为 "."（真实根由 CommandRunner 的 cwd 决定）');

$missing = [];
foreach (SearchClient::DEFAULT_EXCLUDE_DIRS as $dir) {
    if (!str_contains($cmd, '--exclude-dir=' . escapeshellarg($dir))) {
        $missing[] = $dir;
    }
}
check($missing === [], '所有默认排除目录都出现在命令里（缺失：' . (implode(',', $missing) ?: '无') . '）');
check(str_contains($cmd, '--exclude-dir=' . escapeshellarg('vendor')), 'vendor 被排除（否则本项目一次搜索能出上万条）');

// 以 - 开头的 query 不能被 grep 当成选项：-e 的作用就是这个
$dash = SearchClient::buildCommand('--foo');
check(str_contains($dash, '-e ' . escapeshellarg('--foo')), "以 - 开头的 query 走 -e，不会被当成 grep 选项");

// 正则元字符必须当字面量：-F 的作用
$re = SearchClient::buildCommand('a.b*');
check(str_contains($re, '-F ') && str_contains($re, escapeshellarg('a.b*')), '正则元字符走 -F 固定串匹配（不做正则解释）');

// shell 注入防护
$inj = SearchClient::buildCommand("x'; rm -rf /; echo '");
check(!str_contains($inj, '; rm -rf /;') || str_contains($inj, "'\\''"), 'shell 特殊字符被 escapeshellarg 转义（无裸分号注入）');

// ─────────────── 2) parseLine ───────────────
echo "== parseLine ==\n";
$h = SearchClient::parseLine('src/A.php:12:echo "hi"');
check($h instanceof SearchHit && $h->path === 'src/A.php' && $h->line === 12 && $h->text === 'echo "hi"',
    '标准命中行 path/line/text 解析正确');

// text 里含冒号是常态（`foo: bar`、URL、时间戳），必须完整保留
$h2 = SearchClient::parseLine('a/b:3:foo:bar:baz');
check($h2 !== null && $h2->path === 'a/b' && $h2->line === 3 && $h2->text === 'foo:bar:baz',
    'text 含多个冒号时完整保留（explode 限 3 段）');

$h3 = SearchClient::parseLine('my dir/f.php:5:x');
check($h3 !== null && $h3->path === 'my dir/f.php' && $h3->line === 5, '带空格的路径解析正确');

// grep 搜 "." 时输出一律带 ./ 前缀，必须剥掉（否则同一文件会被当成两个 buffer）
$hd = SearchClient::parseLine('./src/A.php:12:x');
check($hd !== null && $hd->path === 'src/A.php', 'grep 的 ./ 路径前缀被剥掉');
check(SearchClient::parseLine('./a/./b.php:1:x')?->path === 'a/./b.php', '只剥开头的 ./，路径中间的不动');

check(SearchClient::parseLine('Binary file x.bin matches') === null, '二进制提示行被忽略');
check(SearchClient::parseLine('garbage') === null, '无冒号的垃圾行被忽略');
check(SearchClient::parseLine('path:notanumber:text') === null, '行号段非数字则忽略');
check(SearchClient::parseLine('path:12') === null, '只有两段（无 text）则忽略');
check(SearchClient::parseLine('') === null, '空行被忽略');

// ANSI 清洗（grep --color 或环境变量导致带色时不能污染渲染）
$hc = SearchClient::parseLine("src/A.php:7:\x1b[01;31mfoo\x1b[0m bar");
check($hc !== null && $hc->text === 'foo bar', 'ANSI 颜色序列被清洗掉（复用 Ansi::sanitize）');

// 中文内容不能被破坏
$hz = SearchClient::parseLine('src/中文.php:9:搜索关键词');
check($hz !== null && $hz->path === 'src/中文.php' && $hz->text === '搜索关键词', '中文路径与中文内容完整保留');

// ─────────────── 3) group ───────────────
echo "== group ==\n";
$groups = SearchClient::group([
    new SearchHit('a.php', 1, 'x'),
    new SearchHit('b.php', 2, 'y'),
    new SearchHit('a.php', 5, 'z'),
]);
check(count($groups) === 2, '3 条命中聚成 2 个文件分组');
check($groups[0] instanceof SearchGroup && $groups[0]->path === 'a.php' && $groups[0]->count() === 2,
    '首个分组是 a.php（首现顺序）且含 2 条命中');
check($groups[1]->path === 'b.php' && $groups[1]->count() === 1, '次个分组是 b.php 含 1 条命中');
check(SearchClient::group([]) === [], '空输入返回空分组');

// ─────────────── 4) SearchModel 状态机（不依赖进程）───────────────
echo "== SearchModel 状态机 ==\n";
$app = new App();
$s = $app->search;

// 模拟 grep 逐行产出（grep 输出经 parseLine 已剥 ./ 前缀）
$s->ingestLine('./src/A.php:1:alpha');
$s->ingestLine('./src/A.php:3:beta');
$s->ingestLine('./src/B.php:2:gamma');
$rows = $s->buildVisibleRows();
check(count($rows) === 5, '2 文件 3 命中 → 2 头 + 3 命中 = 5 可见行');
check($rows[0]->kind === SearchRow::HEADER && $rows[0]->path === 'src/A.php', '首行是 A.php 头');
check($rows[1]->kind === SearchRow::HIT && $rows[1]->hit->line === 1, 'A.php 首命中落在第 1 行');
check($rows[3]->kind === SearchRow::HEADER && $rows[3]->path === 'src/B.php', '第四行是 B.php 头');
check($rows[4]->kind === SearchRow::HIT && $rows[4]->hit->line === 2, 'B.php 命中行号正确保留');

// 折叠 B.php：头仍在、命中隐藏，可见行 -1
$s->toggleGroup('src/B.php');
$rows2 = $s->buildVisibleRows();
check(count($rows2) === 4, '折叠 B.php 后可见行减 1（头在、命中隐）');
$hasBHit = false;
foreach ($rows2 as $r) {
    if ($r->kind === SearchRow::HIT && $r->path === 'src/B.php') {
        $hasBHit = true;
    }
}
check(!$hasBHit, '折叠后 B 的命中已隐藏（无 B 的 HIT 行，只剩 A 的内容）');
// 再展开回来
$s->toggleGroup('src/B.php');
check(count($s->buildVisibleRows()) === 5, '再次展开恢复 5 可见行');

// 上限截断：喂超过 MAX_MATCHES 行，matched 停在上限、truncated 置位
$cap = new App();
$cs = $cap->search;
$max = SearchClient::MAX_MATCHES;
for ($i = 0; $i < $max + 5; $i++) {
    $cs->ingestLine('f.php:' . ($i + 1) . ':x');
}
check($cs->matched === $max, '达 MAX_MATCHES 后停止摄入（matched 不再增长）');
check($cs->truncated === true, 'truncated 标记置位（界面需提示已截断）');

// openHit：打开文件并把光标定位到 grep 行号（1-based → 0-based）
$tmp = tempnam(sys_get_temp_dir(), 'vc_');
file_put_contents($tmp, implode("\n", array_fill(0, 10, 'line')));
$app->search->openHit($tmp, 5);
check($app->buffer !== null && $app->buffer->path === $tmp, 'openHit 打开文件并切换当前 buffer');
check($app->buffer->cursorRow === 4, 'openHit 把光标定位到命中行（行号5 → cursorRow 4）');
unlink($tmp);

// ─────────────── 5) 渲染（App 切到 Search tab，注入结果）───────────────
echo "== 渲染 ==\n";
$vp = Area::fromDimensions(120, 40);
$ext = new CoreExtension();
$renderers = [];
foreach ($ext->widgetRenderers() as $r) {
    $renderers[] = $r;
}
$renderer = new AggregateWidgetRenderer($renderers);

$app = new App();
$app->sidebar->tabIndex = 2;
// 用 ingestLine 注入（与 grep 输出同格式），buildVisibleRows 读的是实时累加器 byPath
foreach ([
    'src/App.php:12:echo "hi"',
    'src/App.php:20:function x()',
    'src/B.php:3:class B',
] as $line) {
    $app->search->ingestLine($line);
}
$app->search->totalMatches = 3;
$app->search->totalFiles = 2;
$app->search->selIdx = 1;

$buf = TuiBuffer::empty($vp);
$renderer->render($renderer, $app->render($vp), $buf, $buf->area());
$txt = implode("\n", $buf->toLines());
check(str_contains($txt, 'src/App.php'), 'SEARCH tab 渲染出文件名分组头 src/App.php');
check(str_contains($txt, 'src/B.php'), 'SEARCH tab 渲染出第二个分组头 src/B.php');
check(str_contains($txt, 'echo "hi"'), 'SEARCH tab 渲染出命中文本 echo "hi"');
check(str_contains($txt, '搜索文件内容') || str_contains($txt, 'Search file contents'),
    'SEARCH tab 输入框显示占位提示');
check($app->sidebar->searchOffset === 0, '渲染后 searchOffset 被夹紧（未滚动时为首行）');

// 空状态：未搜过且 query 为空 → 状态行不应报「无匹配结果」（否则误导用户）
$app2 = new App();
$app2->sidebar->tabIndex = 2;
$buf2 = TuiBuffer::empty($vp);
$renderer->render($renderer, $app2->render($vp), $buf2, $buf2->area());
$txt2 = implode("\n", $buf2->toLines());
check(!str_contains($txt2, '无匹配结果') && !str_contains($txt2, 'No matches'),
    '未搜索且 query 为空时不报「无匹配结果」');

// Edge A-2：状态行在「输入中未搜」时提示「按回车搜索」，搜过无果才报「无匹配结果」
$app = new App();
$app->sidebar->tabIndex = 2;
$app->search->query = 'foo';
$app->search->editingQuery = true;
$app->search->totalMatches = 0;
$app->search->running = false;
$bufH = TuiBuffer::empty($vp);
$renderer->render($renderer, $app->render($vp), $bufH, $bufH->area());
$txtH = implode("\n", $bufH->toLines());
check(str_contains($txtH, '按回车搜索') || str_contains($txtH, 'Press Enter to search'),
    '输入中未搜 → 状态行提示「按回车搜索」（不再误报无匹配）');
$app->search->editingQuery = false;   // 模拟「已搜过、确实 0 命中」
$bufN = TuiBuffer::empty($vp);
$renderer->render($renderer, $app->render($vp), $bufN, $bufN->area());
$txtN = implode("\n", $bufN->toLines());
check(str_contains($txtN, '无匹配结果') || str_contains($txtN, 'No matches'),
    '搜过且 0 命中 → 状态行报「无匹配结果」');

// Edge A-1：SEARCH tab 滚轮滚动结果列表（onScroll 按 tab 分发到 SearchModel::moveSelection）
$app = new App();
$app->sidebar->tabIndex = 2;
foreach (['src/A.php:1:x', 'src/A.php:2:y', 'src/B.php:3:z'] as $l) {
    $app->search->ingestLine($l);
}
$app->search->selIdx = 0;
$app->sidebar->onScroll(MouseEventKind::ScrollDown);
check($app->search->selIdx === 1, 'SEARCH tab 滚轮向下 → 结果选中下移');
$app->sidebar->onScroll(MouseEventKind::ScrollUp);
check($app->search->selIdx === 0, 'SEARCH tab 滚轮向上 → 结果选中上移');

// ─────────────── 6) 交互（App::handle 驱动，不真起进程）───────────────
echo "== 交互 ==\n";
$app = new App();            // 默认焦点 = sidebar（PANELS[0]）
$app->sidebar->tabIndex = 2;

// 字符进 query
foreach (['h', 'e', 'l', 'l', 'o'] as $c) {
    $app->handle(CharKeyEvent::new($c, 0), $vp);
}
check($app->search->query === 'hello', '键入 hello → query 收集到 hello');
check($app->search->editingQuery === true, '键入后处于「编辑查询」态');

// Backspace 删字（CodedKeyEvent → onKey → searchKey）
$app->handle(CodedKeyEvent::new(KeyCode::Backspace, 0), $vp);
check($app->search->query === 'hell', 'Backspace 删掉末字 → hell');

// 空 query 回车 → 只提示「请输入搜索关键词」、不起进程
$app->search->query = '';
$app->search->editingQuery = true;
$app->handle(CodedKeyEvent::new(KeyCode::Enter, 0), $vp);
check($app->message === $app->t('search.empty_query'), '空 query 回车提示「请输入搜索关键词」');
check($app->search->running === false, '空 query 回车未起进程');

// 回车命中 → 打开文件并定位（R3 全链路：CodedKeyEvent → onKey → searchKey → triggerOrActivate → openHit）
$tmp2 = tempnam(sys_get_temp_dir(), 'vc_');
file_put_contents($tmp2, implode("\n", array_fill(0, 8, 'row')));
$app->search->query = 'needle';
$app->search->editingQuery = false;       // 结果态：回车=激活当前行
$app->search->ingestLine($tmp2 . ':6:needle here');
$app->search->totalMatches = 1;
$app->search->totalFiles = 1;
$app->search->selIdx = 1;                 // 第 1 行是那条命中
$app->handle(CodedKeyEvent::new(KeyCode::Enter, 0), $vp);
check($app->buffer !== null && $app->buffer->path === $tmp2, '回车命中 → 打开对应文件');
check($app->buffer->cursorRow === 5, '回车命中 → 光标定位到行号 6（cursorRow 5）');
unlink($tmp2);

// ─────────────── 7) 真实 grep（临时目录，验证命令与排除目录）───────────────
echo "== 真实 grep ==\n";
$dir = sys_get_temp_dir() . '/vc_search_' . uniqid();
mkdir($dir);
mkdir($dir . '/vendor');
mkdir($dir . '/node_modules');
file_put_contents($dir . '/a.php', "foo bar\nbaz\n");
file_put_contents($dir . '/b.php', "foo foo\n");
file_put_contents($dir . '/vendor/lib.php', "foo should be excluded\n");
file_put_contents($dir . '/node_modules/x.js', "foo excluded too\n");

// buildCommand 默认搜 "."，这里 cd 进临时目录跑，grep 会输出 ./ 前缀（与 parseLine 的剥前缀逻辑对应）
$cmd = SearchClient::buildCommand('foo');
$out = shell_exec("cd " . escapeshellarg($dir) . " && " . $cmd . " 2>/dev/null");
$lines = array_values(array_filter(explode("\n", (string) $out), static fn (string $l): bool => $l !== ''));
$outText = implode("\n", $lines);

check(count($lines) === 2, '真实 grep 命中 2 行（a.php / b.php 各 1），排除目录不计入');
check(str_contains($outText, 'a.php') && str_contains($outText, 'b.php'), '命中文件 a.php / b.php 都出现');
check(!str_contains($outText, 'vendor/') && !str_contains($outText, 'node_modules/'),
    '排除目录 vendor / node_modules 不出现在结果中');
// 真实输出能被 parseLine 逐行解析（含 ./ 前缀剥离）
$parsed = 0;
foreach ($lines as $l) {
    if (SearchClient::parseLine($l) !== null) {
        $parsed++;
    }
}
check($parsed === count($lines), '真实 grep 每一行都能被 parseLine 正确解析');

// 清理
foreach (['a.php', 'b.php', 'vendor/lib.php', 'node_modules/x.js'] as $f) {
    @unlink($dir . '/' . $f);
}
@rmdir($dir . '/vendor');
@rmdir($dir . '/node_modules');
@rmdir($dir);

exit($failed ? 1 : 0);

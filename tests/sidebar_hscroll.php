<?php
declare(strict_types=1);

/**
 * 侧栏列表「横向滚动」边界（真实渲染 + 点击命中）。
 *
 * 覆盖两个已修 bug（详见 docs/BUGFIXES.md）：
 *  1. 树行先 mbCutDisp 到 innerW 再 mbSubDisp(hScroll) —— 双重截断导致横滚后
 *     超出 innerW 的内容永远看不到，hScroll 超过 innerW 后**整行空白**。
 *  2. hitArrow() 命中列漏减 hScroll —— 横滚后点三角整体右偏，点在三角上没反应、
 *     点在名称上却 toggle；深目录（三角列超出侧栏）也永远点不到。
 *
 * 运行：php tests/sidebar_hscroll.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\App;
use App\Git\GitFileStatus;
use App\Text\DisplayWidth;
use PhpTui\Term\Event\MouseEvent;
use PhpTui\Term\MouseButton;
use PhpTui\Term\MouseEventKind;
use PhpTui\Tui\Display\Area;
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

$vp = Area::fromDimensions(120, 40);

$ext = new CoreExtension();
$renderers = [];
foreach ($ext->widgetRenderers() as $r) {
    $renderers[] = $r;
}
$renderer = new AggregateWidgetRenderer($renderers);

/** 把侧栏 content 渲染到独立 buffer，返回各行文本（trim 右侧填充） */
function sidebarLines(App $app, Area $sb, AggregateWidgetRenderer $renderer): array
{
    $buf = TuiBuffer::empty($sb);
    $renderer->render($renderer, $app->sidebar->content($sb, true), $buf, $buf->area());
    return array_map(static fn(string $l): string => rtrim($l), $buf->toLines());
}

// ── 场景 1：长名目录（可横滚）──────────────────────────
$root = sys_get_temp_dir() . '/vc_sbh_' . getmypid();
$long = 'd_' . str_repeat('0123456789', 8); // 82 字符，远超 innerW
mkdir($root . '/' . $long . '/inner', 0777, true);
file_put_contents($root . '/' . $long . '/inner/x.txt', "x\n");

$old = getcwd();
chdir($root);

/** 建 App：展开 long → 露出 inner(depth=1)；渲染一帧算 maxHScroll；再设 hScroll */
$mk = static function (int $hScroll) use ($vp): array {
    $app = new App();
    $tree = $app->sidebar->tree();
    $sb = $app->areas($vp)['sidebar'];
    foreach ($tree->roots as $n) {
        if ($n->isDir) {
            $n->ensureChildren();
            $n->expanded = true;
        }
    }
    $target = null;
    $idx = null;
    foreach ($tree->visible() as $i => $n) {
        if ($n->name === 'inner' && $n->isDir) {
            $target = $n;
            $idx = $i;
        }
    }
    $app->sidebar->content($sb, true); // 先渲染：算出 maxHScroll
    $app->sidebar->hScroll = $hScroll;
    $app->sidebar->content($sb, true); // 再渲染：让钳制生效
    return [$app, $target, $idx, $sb];
};

/** 在 hScroll 下点侧栏第 $colOff 列、$idx 行，返回 inner 是否被展开 */
$click = static function (int $hScroll, int $colOff) use ($mk, $vp): ?bool {
    [$app, $target, $idx, $sb] = $mk($hScroll);
    if ($target === null) {
        return null;
    }
    $app->handle(MouseEvent::new(
        MouseEventKind::Down, MouseButton::Left,
        $sb->position->x + $colOff, $sb->position->y + 3 + $idx, 0), $vp);
    return $target->expanded;
};

echo "== 横滚后行文本不丢内容 ==\n";
[$appW, , , $sbW] = $mk(0);
$innerW = $sbW->width - 2;
// 完整行文本（未渲染、未截断）：marker「» 」2 列 + 三角「▶ 」2 列 + 名称 + '/'
// 注：App 默认选中首节点，故 marker 是 '» '（未选中时为 '  '）。
$full = '» ▼ ' . $long . '/'; // 场景里已展开 long，故三角是 ▼
foreach ([0, 10, 30, 50] as $hs) {
    [$a, , , $sb] = $mk($hs);
    $lines = sidebarLines($a, $sb, $renderer);
    $row = $lines[2] ?? '';                       // 0=tab 行 1=分隔线 2=首个树行
    $expect = DisplayWidth::mbSubDisp($full, $a->sidebar->hScroll, $innerW);
    check(
        trim($row) === trim($expect) && DisplayWidth::dispWidth(trim($row)) === $innerW,
        sprintf('hScroll=%-2d 行文本是完整文本的连续切片且占满 innerW=%d 列（实得 %d 列）',
            $hs, $innerW, DisplayWidth::dispWidth(trim($row)))
    );
}

echo "== 点三角命中随 hScroll 偏移 ==\n";
check($click(0, 5) === true, 'hScroll=0：点 +5（三角）→ 展开');
check($click(0, 7) === false, 'hScroll=0：点 +7（名称区）→ 不 toggle');
// 横滚 4 列后，三角（文本列 4）移到屏幕 +1；旧公式仍认 +5 → 错位
check($click(4, 1) === true, 'hScroll=4：点 +1（三角实际位置）→ 展开');
check($click(4, 2) === true, 'hScroll=4：点 +2（命中区含其后 1 列）→ 展开');
check($click(4, 5) === false, 'hScroll=4：点 +5（已变成名称区）→ 不 toggle');
check($click(4, 0) === false, 'hScroll=4：点 +0（三角左侧，已被滚走）→ 不 toggle');

chdir($old ?: '/');
exec('rm -rf ' . escapeshellarg($root));

// ── 场景 2：深层目录树（30 层）────────────────────────
echo "== 深层目录树（30 层）点击 ==\n";
$deepRoot = sys_get_temp_dir() . '/vc_sbh_deep_' . getmypid();
$cur = $deepRoot;
for ($i = 1; $i <= 30; $i++) {
    $cur .= '/l' . $i;
}
mkdir($cur, 0777, true);
$cur = $deepRoot;
for ($i = 1; $i <= 30; $i++) {
    $cur .= '/l' . $i;
    file_put_contents($cur . '/f' . $i . '.txt', "x\n");
}
chdir($deepRoot);

/** 全展开，渲染一帧，设 hScroll，返回 [app, l21节点, l21可见索引, 侧栏Area] */
$mkDeep = static function (int $hScroll) use ($vp): array {
    $app = new App();
    $tree = $app->sidebar->tree();
    $sb = $app->areas($vp)['sidebar'];
    $expand = function (array $nodes) use (&$expand): void {
        foreach ($nodes as $n) {
            if ($n->isDir) {
                $n->ensureChildren();
                $n->expanded = true;
                $expand($n->children ?? []);
            }
        }
    };
    $expand($tree->roots);
    $node = null;
    $idx = null;
    foreach ($tree->visible() as $i => $n) {
        if ($n->name === 'l21' && $n->isDir) {  // depth=20
            $node = $n;
            $idx = $i;
        }
    }
    $app->sidebar->content($sb, true);
    $app->sidebar->hScroll = $hScroll;
    $app->sidebar->content($sb, true);
    return [$app, $node, $idx, $sb];
};

$clickDeep = static function (int $hScroll, int $colOff) use ($mkDeep, $vp): ?bool {
    [$app, $node, $idx, $sb] = $mkDeep($hScroll);
    if ($node === null) {
        return null;
    }
    $app->handle(MouseEvent::new(
        MouseEventKind::Down, MouseButton::Left,
        $sb->position->x + $colOff, $sb->position->y + 3 + $idx, 0), $vp);
    return $node->expanded; // 初始全展开=true，命中则 toggle 成 false
};

[$appD, $nodeD, $idxD, $sbD] = $mkDeep(0);
check($nodeD !== null && $nodeD->depth === 20, '30 层树全展开后能定位到 depth=20 的节点');
check($appD->sidebar->offset === 0, '树首行未被滚动（offset=0，点击行号可直接算）');
$innerWD = $sbD->width - 2;
// 未横滚：depth=20 的三角在文本列 42，远在 innerW=28 之外 → 不可点（不误命中）
check($clickDeep(0, min($innerWD, 27)) === true, '未横滚：点可视区内任一列都不会误 toggle（三角在界外）');
// 横滚 20 列后：三角文本列 42 → 屏幕列 1+2+40-20 = 23，进入可视区
check($clickDeep(20, 23) === false, '横滚 20 列：点 +23（depth=20 三角）→ 折叠生效');
check($clickDeep(20, 24) === false, '横滚 20 列：点 +24（命中区含其后 1 列）→ 折叠生效');
check($clickDeep(20, 26) === true, '横滚 20 列：点 +26（名称区）→ 不 toggle');
// 横滚过头：depth=1 的三角（文本列 4）被滚出左界 → 不命中
check($clickDeep(20, 5) === true, '横滚 20 列：点 +5（浅层三角已滚出左界）→ 不 toggle');

chdir($old ?: '/');
exec('rm -rf ' . escapeshellarg($deepRoot));

// ── 场景 3：超长文件名（文件节点，非目录）──────────────
// 目录长名已在场景 1 覆盖；这里补**文件**：它没有三角（prefix='  '），且真实用法是
// 「横滚找到那个长名文件后点它打开」—— 点击按行定位，不能因为横滚就打不开。
echo "== 超长文件名横滚与点击 ==\n";
$nameRoot = sys_get_temp_dir() . '/vc_sbh_name_' . getmypid();
mkdir($nameRoot, 0777, true);
$longFile = 'f_' . str_repeat('0123456789', 12) . '.log';  // 126 列
$cjkFile = str_repeat('文档', 30) . '.md';                  // 123 列（CJK 每字 2 列）
// 200 字符（文件系统 NAME_MAX=255 上限内）→ 显示宽 205 列，已远超 innerW
$hugeFile = 'h_' . str_repeat('z', 200) . '.dat';
file_put_contents($nameRoot . '/' . $longFile, "x\n");
file_put_contents($nameRoot . '/' . $cjkFile, "y\n");
file_put_contents($nameRoot . '/' . $hugeFile, "z\n");
chdir($nameRoot);

/** 渲染一帧算出 maxHScroll，再按 $hScroll 渲染，返回 [app, 侧栏Area, 行文本数组] */
$mkName = static function (int $hScroll) use ($vp, $renderer): array {
    $app = new App();
    $sb = $app->areas($vp)['sidebar'];
    $app->sidebar->content($sb, true);
    $app->sidebar->hScroll = $hScroll;
    return [$app, $sb, sidebarLines($app, $sb, $renderer)];
};

[$appN0, $sbN, $lines0] = $mkName(0);
$innerWN = $sbN->width - 2;
$rowLong = $lines0[2] ?? '';  // 行 0/1 是 tab 行与分隔线；文件按名排序 f_ < h_ < 文
$rowCjk = $lines0[4] ?? '';
check(trim($rowLong) !== '' && DisplayWidth::dispWidth(trim($rowLong)) === $innerWN, 'hScroll=0：超长文件名行占满 innerW（不空白）');
check(str_starts_with(trim($rowLong), '»'), 'hScroll=0：首节点带选中 marker（»）');

// 滚到最右：行尾应落在文件名末尾（.log），而不是像双重截断那样整行空白。
// ⚠️ 横滚是全局共享的（maxHScroll 取最长那行），故这里按**本行自身宽度**算终点，
// 否则会被更长的 h_ 那行带过头，本行反而滚空（那是合理行为，不是 bug）。
$longEnd = (2 + 2 + DisplayWidth::dispWidth($longFile)) - $innerWN;
[$appNMax, , $linesMax] = $mkName($longEnd);
check(str_ends_with(trim($linesMax[2] ?? ''), '.log'), '滚到最右：超长文件名行以 .log 结尾（尾部可见）');
check(DisplayWidth::dispWidth(trim($linesMax[2] ?? '')) === $innerWN, '滚到最右：超长文件名行仍占满 innerW（不是空白行）');

// CJK 长名：横滚切片必须在字素边界对齐，不能劈开汉字（劈开会少 1 列或出半个字）
[$appNCjk, , $linesCjk] = $mkName(99);
$cjkRow = trim($linesCjk[4] ?? '');
check(str_ends_with($cjkRow, '.md'), 'CJK 长文件名：横滚后以 .md 结尾');
check(DisplayWidth::dispWidth($cjkRow) % 1 === 0 && !str_contains($cjkRow, "\x{FFFD}"), 'CJK 长文件名：横滚切片未劈开汉字（无替换符）');

// 205 列文件名：上界要撑得开，滚到最右仍看得到扩展名
check($appN0->sidebar->maxHScroll >= 200, '205 列文件名：maxHScroll 撑得开（≥200）');
[$appNHuge, , $linesHuge] = $mkName($appN0->sidebar->maxHScroll - $innerWN);
check(str_ends_with(trim($linesHuge[3] ?? ''), '.dat'), '205 列文件名：滚到最右以 .dat 结尾');

/** 在 hScroll 下点第 $rowIdx 个树行（0-based）的第 $colOff 列，返回打开的文件路径 */
$clickFile = static function (int $hScroll, int $rowIdx, int $colOff) use ($vp, $nameRoot): ?string {
    $app = new App();
    $sb = $app->areas($vp)['sidebar'];
    $app->sidebar->content($sb, true);
    $app->sidebar->hScroll = $hScroll;
    $app->sidebar->content($sb, true);
    $app->handle(MouseEvent::new(
        MouseEventKind::Down, MouseButton::Left,
        $sb->position->x + $colOff, $sb->position->y + 3 + $rowIdx, 0), $vp);
    return $app->buffer?->path;
};

// 横滚状态下点长名文件：按行定位，不受 hScroll 影响（没修也能过，但回归要钉死）
$opened = $clickFile(40, 0, 10);
check($opened !== null && basename((string) $opened) === $longFile, '横滚 40 列后点长名文件行 → 该文件被打开');
// 文件节点没有三角：点在「depth=0 三角那一列」也必须照常打开，不能被当成无效点击
$opened2 = $clickFile(40, 0, 3);
check($opened2 !== null && basename((string) $opened2) === $longFile, '文件行点三角列（+3）→ 仍打开文件（文件无三角，不吞点击）');

chdir($old ?: '/');
exec('rm -rf ' . escapeshellarg($nameRoot));

// ── 场景 4：GIT 深路径（长路径横滚 + 行首图标命中）──────
// 深路径在 GIT 面板里很常见（`src/A/B/C/…/File.php`），一行远超侧栏宽度。
// 行首四个图标（▦ + - ✕）是**按列命中的**，而 ✕ = 丢弃工作区改动（不可逆）。
echo "== GIT 深路径横滚与行首图标命中 ==\n";
$deepPath = 'aaaa/bbbb/cccc/dddd/eeee/ffff/gggg/hhhh/target_file_with_long_name.php';

/** 切到 GIT tab、注入一条深路径变更、渲染并按 $hScroll 定位，返回 [app, 侧栏Area] */
$mkGit = static function (int $hScroll) use ($vp, $deepPath): array {
    $app = new App();
    $app->sidebar->tabIndex = 1;                 // 1 = GIT
    $app->git->status = [new GitFileStatus($deepPath, 'M', '')];
    $sb = $app->areas($vp)['sidebar'];
    $app->sidebar->content($sb, true);           // 先渲染：算出 maxHScroll
    $app->sidebar->hScroll = $hScroll;
    $app->sidebar->content($sb, true);           // 再渲染：让钳制生效
    return [$app, $sb];
};

/** 点首条变更行的 inner 第 $off 列，返回是否弹出「丢弃确认」（即命中了 ✕） */
$clickGitX = static function (int $hScroll, int $off) use ($mkGit, $vp): bool {
    [$app, $sb] = $mkGit($hScroll);
    $itemRow = $sb->position->y + 1 + 2 + 5;     // 上边框 + tab/分隔偏移(2) + GIT_FIRST_ROW(5)
    $app->handle(MouseEvent::new(
        MouseEventKind::Down, MouseButton::Left,
        $sb->position->x + 1 + $off, $itemRow, 0), $vp);
    return $app->confirm !== null && ($app->confirm['kind'] ?? '') === 'discard';
};

[$appG, $sbG] = $mkGit(0);
$innerWG = $sbG->width - 2;
check($appG->sidebar->maxHScroll > $innerWG, '深路径（81 列）确实需要横滚（maxHScroll > innerW）');

// GIT 行渲染本来就是「sub 完整文本」（无双重截断），这里钉死：滚到最右能看到路径尾部
// 行文本 = '▦ + - ✕ ' + ' ' + badge + ' ' + 路径（与 SidebarPanel::gitContent 同构）
$gLine = '▦ + - ✕ ' . ' M ' . $deepPath;
$gEnd = DisplayWidth::dispWidth($gLine) - $innerWG;
[$appGEnd] = $mkGit($gEnd);
$linesG = sidebarLines($appGEnd, $sbG, $renderer);
$gRow = trim($linesG[2 + 5] ?? '');              // tab 行 + 分隔(2) + GIT_FIRST_ROW(5)
check($gRow !== '' && DisplayWidth::dispWidth($gRow) <= $innerWG, '深路径行：滚到最右有内容且不超 innerW');
check(str_ends_with($gRow, '.php'), '深路径行：滚到最右能看到路径**尾部** .php（不是空白行、不是只剩头部）');

// 命中列必须跟着 hScroll 走：✕ 在**文本列** 6~7
check($clickGitX(0, 6) === true, 'hScroll=0：点文本列 6（✕）→ 弹出丢弃确认');
check($clickGitX(0, 12) === false, 'hScroll=0：点路径文本区（列 12）→ 不弹丢弃确认');
check($clickGitX(4, 2) === true, 'hScroll=4：✕ 移到屏幕列 2 → 点列 2 弹出丢弃确认');
check($clickGitX(4, 6) === false, 'hScroll=4：点列 6（此刻已是路径文本）→ 不弹丢弃确认（旧代码会误触 ✕）');
check($clickGitX(10, 6) === false, 'hScroll=10：图标已滚出左界 → 点列 6 不弹丢弃确认');
check($clickGitX(10, 0) === false, 'hScroll=10：点列 0（路径文本）→ 不弹丢弃确认');

// ── 场景 5：Search 长路径 / 长命中行 ──────────────────
// Search 列表点击是**按行定位**（searchClick 没有按列分支），所以横滚只影响渲染；
// 这里钉两件事：行文本不双重截断（滚到最右看得到尾部）、横滚后点击仍能打开正确命中。
echo "== Search 长路径与长命中行 ==\n";
$srRoot = sys_get_temp_dir() . '/vc_sbh_sr_' . getmypid();
$deepRel = 'aaaa/bbbb/cccc/dddd/eeee/ffff/target_search_file.php';
mkdir(dirname($srRoot . '/' . $deepRel), 0777, true);
$hitText = 'the_needle_is_at_the_very_end_of_this_extremely_long_line';
file_put_contents($srRoot . '/' . $deepRel, "x\n" . $hitText . "\n");
chdir($srRoot);

/** 切到 Search tab、注入一条深路径命中、按 $hScroll 渲染，返回 [app, 侧栏Area] */
$mkSearch = static function (int $hScroll) use ($vp, $srRoot, $deepRel, $hitText): array {
    $app = new App();
    $app->sidebar->tabIndex = 2;                 // 2 = Search
    $abs = $srRoot . '/' . $deepRel;
    $app->search->ingestLine($abs . ':' . 2 . ':' . $hitText);
    $app->search->totalMatches = 1;
    $app->search->totalFiles = 1;
    $sb = $app->areas($vp)['sidebar'];
    $app->sidebar->content($sb, true);
    $app->sidebar->hScroll = $hScroll;
    $app->sidebar->content($sb, true);
    return [$app, $sb];
};

/** Search 列表首行 y：上边框 + 内容偏移(2) + SEARCH_FIRST_ROW(2) */
$searchRowY = static fn (Area $sb, int $i): int => $sb->position->y + 1 + 2 + 2 + $i;

[$appS, $sbS] = $mkSearch(0);
$innerWS = $sbS->width - 2;
check($appS->sidebar->maxHScroll > $innerWS, 'Search 深路径 + 长命中行确实需要横滚');
// hit 行（可见行 index 1）滚到最右应看得到行尾的 the_needle…
$hitLineText = '  2: ' . $hitText;
$sEnd = DisplayWidth::dispWidth($hitLineText) - $innerWS;
[$appSEnd] = $mkSearch($sEnd);
$linesS = sidebarLines($appSEnd, $sbS, $renderer);
$hitRow = trim($linesS[2 + 2 + 1] ?? '');   // tab 行 + 分隔(2) + 可见行 index 1
check(str_ends_with($hitRow, 'extremely_long_line'), 'Search 命中行：滚到最右看得到行尾（无双重截断）');
check(DisplayWidth::dispWidth($hitRow) <= $innerWS, 'Search 命中行：不超 innerW');

// 横滚后点击（按行定位，不受 hScroll 影响）
$clickSearch = static function (int $hScroll, int $rowIdx) use ($vp, $mkSearch, $searchRowY): ?App {
    [$app, $sb] = $mkSearch($hScroll);
    $app->handle(MouseEvent::new(
        MouseEventKind::Down, MouseButton::Left,
        $sb->position->x + 10, $searchRowY($sb, $rowIdx), 0), $vp);
    return $app;
};
$aHit = $clickSearch(30, 1);
check(
    $aHit !== null && $aHit->buffer !== null
    && str_ends_with((string) $aHit->buffer->path, 'target_search_file.php'),
    'Search 横滚 30 列后点命中行 → 打开的是该命中文件（按行定位不受横滚影响）'
);
[$aHdr, $sbHdr] = $mkSearch(30);
$aHdr->handle(MouseEvent::new(
    MouseEventKind::Down, MouseButton::Left,
    $sbHdr->position->x + 10, $searchRowY($sbHdr, 0), 0), $vp);
$rowsAfter = $aHdr->search->buildVisibleRows();
check(count($rowsAfter) === 1, 'Search 横滚后点分组标题行 → 折叠生效（组下命中行收起）');

chdir($old ?: '/');
exec('rm -rf ' . escapeshellarg($srRoot));

// ── 场景 6：极小视口下的横滚 + 超长分支名 ──────────────
echo "== 极小视口横滚 / 超长分支名 ==\n";
foreach ([[40, 10], [20, 6], [12, 6]] as [$vw, $vh]) {
    foreach ([0, 1, 2] as $tab) {
        $label = "极小视口 {$vw}x{$vh} · tab{$tab}（树/GIT/Search）";
        try {
            $app = new App();
            $app->sidebar->tabIndex = $tab;
            $app->git->status = [new GitFileStatus('a/b/c/d/e/f/g/h/i/j/file.php', 'M', '')];
            $small = Area::fromDimensions($vw, $vh);
            $sb = $app->areas($small)['sidebar'];
            $app->sidebar->hScroll = 200;               // 远超任何可能的上界
            $buf = TuiBuffer::empty($sb);
            $renderer->render($renderer, $app->sidebar->content($sb, true), $buf, $buf->area());
            $limit = max(1, $sb->width);
            $over = false;
            foreach ($buf->toLines() as $l) {
                if (DisplayWidth::dispWidth(rtrim($l)) > $limit) {
                    $over = true;
                    break;
                }
            }
            check(!$over, $label . '：超大 hScroll 下渲染不溢出');
        } catch (Throwable $e) {
            check(false, $label . '：抛异常 ' . $e->getMessage());
        }
    }
}

$longBranch = 'feature/' . str_repeat('branch-name-segment/', 12) . 'end';
$mkBranch = static function (bool $dropdown, int $hScroll) use ($vp, $longBranch): array {
    $app = new App();
    $app->sidebar->tabIndex = 1;
    $app->git->branch = $longBranch;
    $app->git->branches = [$longBranch, 'main'];
    $app->git->branchDropdownOpen = $dropdown;
    $sb = $app->areas($vp)['sidebar'];
    $app->sidebar->content($sb, true);
    $app->sidebar->hScroll = $hScroll;
    return [$app, $sb];
};

[$appB, $sbB] = $mkBranch(false, 0);
$rowB = trim(sidebarLines($appB, $sbB, $renderer)[2] ?? '');   // 分支行 = GIT 内容行 0
check($rowB !== '' && DisplayWidth::dispWidth($rowB) <= $sbB->width - 2, '超长分支名：分支行不超宽');
check(str_starts_with($rowB, '⎇'), '超长分支名：分支行硬切保留头部（⎇ 与分支名开头可见，符合预期）');

[$appBD, $sbBD] = $mkBranch(true, 0);
$appBD->sidebar->hScroll = max(0, $appBD->sidebar->maxHScroll - ($sbBD->width - 2));
$linesBD = sidebarLines($appBD, $sbBD, $renderer);
$item = trim($linesBD[4] ?? '');   // tab 行 + 分隔(2) + 下拉标题(0) + 分隔(1) = 首项在 index 4
check(str_ends_with($item, 'end'), '超长分支名：下拉列表项滚到最右看得到尾部 end');

echo $failed ? "\nRESULT: FAIL\n" : "\nRESULT: PASS\n";
exit($failed ? 1 : 0);

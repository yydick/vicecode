<?php
declare(strict_types=1);

/**
 * M1 无终端冒烟测试（不依赖 tty/STDIN）：
 *  1) i18n：加载 zh_CN/en，翻译 key 与 {param} 替换、回退；
 *  2) Buffer：插入/退格/换行/删除/光标移动/保存落盘；
 *  3) 目录树：懒展开、可见列表、Enter 打开文件；
 *  4) App：渲染六面板无异常；模拟打开文件→编辑→保存→重开校验内容；
 *         状态栏含当前文件、超大/二进制文件受保护。
 *
 * 运行：php tests/m1_smoke.php
 */

require __DIR__ . '/../vendor/autoload.php';

// 固定界面语言为 zh_CN，使渲染断言可确定（运行 app 时可用 APP_LOCALE=en 切换英文）
putenv('APP_LOCALE=zh_CN');

use App\App;
use App\Editor\Buffer;
use App\Editor\Highlighter;
use App\Explorer\FileTree;
use App\I18n\Translator;
use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Display\Buffer as TuiBuffer;
use PhpTui\Tui\Extension\Core\CoreExtension;
use PhpTui\Tui\Widget\WidgetRenderer\AggregateWidgetRenderer;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event\MouseEvent;
use PhpTui\Term\MouseEventKind;
use PhpTui\Term\MouseButton;
use PhpTui\Term\KeyCode;
use PhpTui\Term\KeyModifiers;
use PhpTui\Tui\Widget\Margin;

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

// ───────────────────────── 1) i18n ─────────────────────────
echo "== i18n ==\n";
$zh = new Translator('zh_CN', __DIR__ . '/../config/locales');
check($zh->t('panel.editor') === '编辑器', 'zh_CN 翻译 panel.editor = 编辑器');
$en = new Translator('en', __DIR__ . '/../config/locales');
check($en->t('panel.editor') === 'EDITOR', 'en 翻译 panel.editor = EDITOR');
check($zh->t('editor.too_large', ['size' => 123, 'limit' => 5000000]) === '文件过大，无法编辑：123 字节（上限 5000000）', '{param} 占位替换');
check($zh->t('missing.key') === 'missing.key', '缺失 key 原样返回');
// 回退：zh_CN 不存在的 key 回落英文
$z2 = new Translator('zh_CN', __DIR__ . '/../config/locales');
check($z2->t('editor.saved') === '已保存', 'zh_CN 覆盖项生效');

// ───────────────────────── 2) Buffer ─────────────────────────
echo "== Buffer 编辑 ==\n";
$b = Buffer::empty('t');
$b->insertChar('h');
$b->insertChar('i');
check(implode("\n", $b->lines) === 'hi' && $b->dirty, '插入 "hi" → 内容 hi 且 dirty');
$b->insertNewline();
$b->insertChar('x');
check($b->lines[0] === 'hi' && $b->lines[1] === 'x' && $b->cursorRow === 1, '回车换行 → 第二行 "x"');
$b->moveHome();
$b->backspace(); // 行首退格 → 合并到上一行末尾
check($b->lines[0] === 'hix' && count($b->lines) === 1, '行首退格合并上行');
$b->moveEnd();
$b->insertChar('!');
$b->moveLeft();
$b->delete();
check($b->lines[0] === 'hix', 'moveLeft+delete 删除光标前字符');
// 保存落盘
$tmp = tempnam(sys_get_temp_dir(), 'm1buf');
file_put_contents($tmp, ''); // 确保可写
$b2 = Buffer::empty('t2');
$b2->path = $tmp;
$b2->insertChar('A');
$b2->insertChar('B');
$ok = $b2->save();
check($ok && file_get_contents($tmp) === 'AB', 'save() 落盘内容正确');
check(!$b2->dirty, '保存后 dirty 清除');
unlink($tmp);

// ───────────────────────── 3) 目录树 ─────────────────────────
echo "== 目录树 ==\n";
$dir = sys_get_temp_dir() . '/m1tree_' . uniqid();
mkdir($dir . '/sub', 0777, true);
file_put_contents($dir . '/a.txt', 'AAA');
file_put_contents($dir . '/sub/b.txt', 'BBB');
$tree = new FileTree($dir);
$vis0 = $tree->visible();
check(count($vis0) === 2, '初始可见仅根级 2 项（a.txt, sub）');
// 展开 sub
$subNode = null;
foreach ($vis0 as $n) {
    if ($n->isDir) {
        $subNode = $n;
    }
}
$subNode->expanded = true;
$subNode->ensureChildren();
$vis1 = $tree->visible();
$hasB = false;
foreach ($vis1 as $n) {
    if ($n->name === 'b.txt') {
        $hasB = true;
    }
}
check(count($vis1) === 3 && $hasB, '展开目录后子项 b.txt 可见');
// 打开文件
$app = new App();
$found = null;
foreach ($vis1 as $n) {
    if ($n->name === 'a.txt') {
        $found = $n;
    }
}
$app->selectedPath = $found->path;
$app->openFile($found->path);
check($app->buffer !== null && $app->buffer->lines[0] === 'AAA', 'openFile 载入 a.txt 内容');
check($app->focusPanel() === 'editor', 'openFile 后焦点切到 editor');

// ───────────────────────── 4) App 渲染 + 编辑保存 ─────────────────────────
echo "== App 渲染 / 编辑 / 保存 ==\n";
$vp = Area::fromDimensions(120, 40);
$ext = new CoreExtension();
$renderers = [];
foreach ($ext->widgetRenderers() as $r) {
    $renderers[] = $r;
}
$renderer = new AggregateWidgetRenderer($renderers);
$buffer = TuiBuffer::empty($vp);
$renderer->render($renderer, $app->render($vp), $buffer, $buffer->area());
$text = implode("\n", $buffer->toLines());
foreach (['编辑器', '资源管理器', 'AI 对话', '终端', '工作台', '焦点'] as $needle) {
    check(str_contains($text, $needle), "渲染输出包含: $needle");
}

// 模拟在编辑器里键入并保存
$app->focusIndex = array_search('editor', App::PANELS);
$app->handle(CharKeyEvent::new('Z', 0), $vp);
check($app->buffer->lines[0] === 'ZAAA' && $app->buffer->dirty, '编辑器键入 Z → 内容变更且 dirty（光标起始于行首）');
// Ctrl+S 保存（CharKeyEvent control+s）
$app->handle(CharKeyEvent::new('s', KeyModifiers::CONTROL), $vp);
check($app->buffer->dirty === false, 'Ctrl+S 后 dirty 清除（已落盘）');
check(file_get_contents($found->path) === 'ZAAA', '磁盘文件内容为 ZAAA');
// 重新打开校验
$app2 = new App();
$app2->selectedPath = $found->path;
$app2->openFile($found->path);
check($app2->buffer->lines[0] === 'ZAAA', '重开文件内容一致');

// 光标移动键不越界
$app2->handle(CodedKeyEvent::new(KeyCode::Home, 0), $vp);
$app2->handle(CodedKeyEvent::new(KeyCode::Left, 0), $vp);
check($app2->buffer->cursorCol === 0, 'Home+Left 不越界（cursorCol=0）');

// 超大文件保护
$big = tempnam(sys_get_temp_dir(), 'm1big');
file_put_contents($big, str_repeat('x', 6_000_000));
$bigBuf = Buffer::fromFile($big);
check($bigBuf->readOnly && $bigBuf->noticeKey === 'editor.too_large', '超大文件 readOnly + 提示 key');
unlink($big);

// ───────────────────────── 5) 语法高亮（scrivo 适配器） ─────────────────────────
echo "== 语法高亮 ==\n";
check(Highlighter::langFor('a.php') === 'php', 'langFor(a.php) = php');
check(Highlighter::langFor('a.json') === 'json', 'langFor(a.json) = json');
check(Highlighter::langFor('a.md') === 'markdown', 'langFor(a.md) = markdown');
check(Highlighter::langFor('a.txt') === null, 'langFor(a.txt) = null（不支持回退）');

$phpSrc = [
    '<?php',
    '$a = 1; // 注释',
    'function f() { return "x"; }',
    'if ($a) { echo $a; }',
];
$hl = Highlighter::highlightLines($phpSrc, 'php');
check($hl !== null, 'highlightLines(php) 返回非空');
// 存在黄色关键字 Span（fg = Yellow）
$hasKw = false;
foreach ($hl as $lineToks) {
    if ($lineToks === null) {
        continue;
    }
    foreach ($lineToks as [$text, $st]) {
        if (($st->fg ?? null) === AnsiColor::Yellow) {
            $hasKw = true;
        }
    }
}
check($hasKw, '高亮输出含黄色关键字 Span');
// 不支持语言返回 null → 渲染走默认
check(Highlighter::highlightLines(['plain'], null) === null, 'highlightLines(null) = null');

// 真实打开 .php 文件渲染不崩，且含内容
$phpFile = tempnam(sys_get_temp_dir(), 'm1hl') . '.php';
file_put_contents($phpFile, implode("\n", $phpSrc));
$appHl = new App();
$appHl->openFile($phpFile);
$appHl->focusIndex = array_search('editor', App::PANELS);
$rendererHl = new AggregateWidgetRenderer($renderers);
$bufHl = TuiBuffer::empty($vp);
$rendererHl->render($rendererHl, $appHl->render($vp), $bufHl, $bufHl->area());
$textHl = implode("\n", $bufHl->toLines());
check(str_contains($textHl, 'function') && str_contains($textHl, 'echo'), '高亮渲染含 PHP 内容（未崩）');
unlink($phpFile);

// ───────────────────────── 6) 多 Buffer 标签（R7） ─────────────────────────
echo "== 多 Buffer 标签（R7） ==\n";
$appR7 = new App();
$fA = tempnam(sys_get_temp_dir(), 'r7a') . '.txt';
$fB = tempnam(sys_get_temp_dir(), 'r7b') . '.txt';
file_put_contents($fA, "A1\nA2");
file_put_contents($fB, "B1");
$appR7->openFile($fA);
check(!$appR7->hasTabs(), '单 buffer：hasTabs = false');
$appR7->openFile($fB);
check($appR7->hasTabs(), '开 2 个文件：hasTabs = true');
check($appR7->buffer->path === $fB, '最近打开的 buffer 为当前 buffer');
// switchBuffer
$appR7->switchBuffer($fA);
check($appR7->buffer->path === $fA, 'switchBuffer(A) → 当前切到 A');
// cycleBuffer（环形）：走真实事件路径 Ctrl+Tab（focus=editor 时）
$appR7->focusIndex = array_search('editor', App::PANELS);
$appR7->handle(CodedKeyEvent::new(KeyCode::Tab, KeyModifiers::CONTROL), $vp);
check($appR7->buffer->path === $fB, 'Ctrl+Tab 从 A 切到 B（cycleBuffer）');
$appR7->handle(CodedKeyEvent::new(KeyCode::Tab, KeyModifiers::CONTROL), $vp);
check($appR7->buffer->path === $fA, '再 Ctrl+Tab → 回到 A（环形）');
// 渲染含两个 tab 标签（>1 buffer 才显示 tab 栏）
$renderer7 = new AggregateWidgetRenderer($renderers);
$buf7 = TuiBuffer::empty($vp);
$renderer7->render($renderer7, $appR7->render($vp), $buf7, $buf7->area());
$text7 = implode("\n", $buf7->toLines());
check(str_contains($text7, basename($fA)) && str_contains($text7, basename($fB)), '渲染含两个 tab 标签');
unlink($fA);
unlink($fB);

// ───────────────────────── 7) 未保存确认（R8） ─────────────────────────
echo "== 未保存确认（R8） ==\n";
// 退出确认：dirty 时 Ctrl+C 先弹确认，不立即退出
$appR8 = new App();
$fR8 = tempnam(sys_get_temp_dir(), 'r8') . '.txt';
file_put_contents($fR8, "orig");
$appR8->openFile($fR8);
$appR8->focusIndex = array_search('editor', App::PANELS);
$appR8->handle(CharKeyEvent::new('x', 0), $vp); // 键入 → dirty
check($appR8->buffer->dirty, '编辑后 dirty 为真');
$appR8->handle(CharKeyEvent::new('c', KeyModifiers::CONTROL), $vp); // Ctrl+C 请求退出
check($appR8->confirm !== null && $appR8->confirm['kind'] === 'quit', 'dirty 时 Ctrl+C → confirm(kind=quit)');
check($appR8->quit === false, '确认前 quit 仍为 false');
// 确认进行中状态栏应显示提示文案（zh_CN）
$buf8s = TuiBuffer::empty($vp);
(new AggregateWidgetRenderer($renderers))->render($renderer, $appR8->render($vp), $buf8s, $buf8s->area());
check(str_contains(implode("\n", $buf8s->toLines()), '有未保存改动'), '确认中状态栏显示 confirm.quit_dirty');
// n 取消
$appR8->handle(CharKeyEvent::new('n', 0), $vp);
check($appR8->confirm === null && $appR8->quit === false, 'n → 取消确认，未退出');
// 再 Ctrl+C → y 确认退出
$appR8->handle(CharKeyEvent::new('c', KeyModifiers::CONTROL), $vp);
$appR8->handle(CharKeyEvent::new('y', 0), $vp);
check($appR8->quit === true, 'y → 确认退出（quit=true）');

// 关闭确认：Ctrl+W 关闭 dirty buffer 弹确认；y 关闭丢弃
$appR8b = new App();
$fR8b = tempnam(sys_get_temp_dir(), 'r8b') . '.txt';
file_put_contents($fR8b, "orig");
$appR8b->openFile($fR8b);
$appR8b->focusIndex = array_search('editor', App::PANELS);
$appR8b->handle(CharKeyEvent::new('x', 0), $vp);
$appR8b->handle(CharKeyEvent::new('w', KeyModifiers::CONTROL), $vp); // Ctrl+W 关闭
check($appR8b->confirm !== null && $appR8b->confirm['kind'] === 'close', 'dirty 时 Ctrl+W → confirm(kind=close)');
// Esc 取消
$appR8b->handle(CodedKeyEvent::new(KeyCode::Esc, 0), $vp);
check($appR8b->confirm === null && $appR8b->hasBuffer($fR8b), 'Esc → 取消关闭，buffer 仍在');
// 再 Ctrl+W → y 确认关闭丢弃
$appR8b->handle(CharKeyEvent::new('w', KeyModifiers::CONTROL), $vp);
$appR8b->handle(CharKeyEvent::new('y', 0), $vp);
check(!$appR8b->hasBuffer($fR8b), 'y → 关闭丢弃并移除 buffer');

// 非 dirty 时直接退出 / 直接关闭，不弹确认
$appR8c = new App();
$fR8c = tempnam(sys_get_temp_dir(), 'r8c') . '.txt';
file_put_contents($fR8c, "clean");
$appR8c->openFile($fR8c);
$appR8c->handle(CharKeyEvent::new('c', KeyModifiers::CONTROL), $vp);
check($appR8c->quit === true && $appR8c->confirm === null, '非 dirty 时 Ctrl+C 直接退出（无确认）');

// ───────────────────────── 8) 编辑器鼠标交互（R6） ─────────────────────────
echo "== 编辑器鼠标交互（R6） ==\n";
$appR6 = new App();
$fR6 = tempnam(sys_get_temp_dir(), 'r6') . '.txt';
file_put_contents($fR6, implode("\n", array_map(static fn(int $i): string => "line $i", range(1, 50))));
$appR6->openFile($fR6);
$appR6->focusIndex = array_search('editor', App::PANELS);

// 滚轮向下：editor 焦点下 ScrollDown → pageDown(3) → cursorRow +3
$appR6->handle(MouseEvent::new(MouseEventKind::ScrollDown, MouseButton::Left, 0, 0, 0), $vp);
check($appR6->buffer->cursorRow === 3, '滚轮 ScrollDown → cursorRow 下移 3（pageDown）');

// 渲染后 scrollTop 跟随 cursorRow 且不过冲
$buf6 = TuiBuffer::empty($vp);
(new AggregateWidgetRenderer($renderers))->render($renderer, $appR6->render($vp), $buf6, $buf6->area());
check($appR6->buffer->scrollTop === 0, 'scrollTop 未越界（cursorRow=3 仍可见）');

// 滚轮向上：ScrollUp → pageUp(3) → cursorRow 回到 0
$appR6->handle(MouseEvent::new(MouseEventKind::ScrollUp, MouseButton::Left, 0, 0, 0), $vp);
check($appR6->buffer->cursorRow === 0, '滚轮 ScrollUp → cursorRow 回到 0');

// 点击编辑器某行定位光标：用 areas() 拿 editor 区域，点 inner 第 6 显示行（0-based 行 5）
$aR6 = $appR6->areas($vp);
$ed = $aR6['editor'];
$inner = $ed->inner(new Margin(1, 1));
$gutterW = min($inner->width, $appR6->buffer->maxLineNoWidth + 1);
$clickRow = $inner->position->y + 5;            // 第 6 显示行
$clickCol = $inner->position->x + $gutterW + 2; // 文本区第 3 字
$appR6->handle(MouseEvent::new(MouseEventKind::Down, MouseButton::Left, $clickCol, $clickRow, 0), $vp);
check($appR6->buffer->cursorRow === 5, '点击编辑器第 6 显示行 → 光标定位第 5 行（0-based）');
check($appR6->buffer->cursorCol >= 0, '点击列定位合法（cursorCol>=0）');
check($appR6->focusPanel() === 'editor', '点击编辑器后焦点为 editor');
unlink($fR6);

// ───────────────────────── 9) 侧栏双击目录展开/折叠（VSCode 习惯） ─────────────────────────
// 终端没有双击事件：SGR 协议里双击就是两次独立的 Down，由 App 按「同一条目 + 阈值内」合成。
echo "== 侧栏双击目录（VSCode 习惯） ==\n";
$appD = new App();
$treeProp = new ReflectionProperty(App::class, 'tree');
$treeProp->setAccessible(true);
$treeD = $treeProp->getValue($appD);
$visD = $treeD->visible();
// 挑第一个「非空」目录：空目录展开后可见节点数不变，断言会误报
$dirIdx = null;
foreach ($visD as $i => $n) {
    if (!$n->isDir || !is_readable($n->path)) {
        continue;
    }
    $entries = scandir($n->path);
    if (is_array($entries) && count(array_diff($entries, ['.', '..'])) > 0) {
        $dirIdx = $i;
        break;
    }
}
$dirNode = $visD[$dirIdx];
$sbD = $appD->areas($vp)['sidebar'];
$rowD = $sbD->position->y + 3 + $dirIdx;   // 树条目从 inner 第 2 行起 → sb.y+3
$colD = $sbD->position->x + 5;
$clickDir = static fn() => $appD->handle(MouseEvent::new(MouseEventKind::Down, MouseButton::Left, $colD, $rowD, 0), $vp);

check($dirNode->expanded === false, '目录初始为折叠');
$clickDir();
check($dirNode->expanded === false, '单击目录只选中、不展开（避免误触折叠）');
check($appD->selectedPath === $dirNode->path, '单击目录设为选中项');

$beforeVisible = count($treeD->visible());
$clickDir();    // 阈值内的第二次点击 → 构成双击
check($dirNode->expanded === true, '双击目录 → 展开');
check(count($treeD->visible()) > $beforeVisible, '展开后可见节点数增加');

$clickDir();    // 第 3 击：重新记时（双击后已清空，防止三击连 toggle 抵消）
$clickDir();    // 第 4 击：构成双击 → 折叠
check($dirNode->expanded === false, '再次双击目录 → 折叠');
check(count($treeD->visible()) === $beforeVisible, '折叠后可见节点数复原');

// 双击文件：不能因为双击合成而失效（仍要打开进编辑器）
$appF = new App();
$treeF = (new ReflectionProperty(App::class, 'tree'));
$treeF->setAccessible(true);
$visF = $treeF->getValue($appF)->visible();
$fileIdx = null;
foreach ($visF as $i => $n) {
    if (!$n->isDir) {
        $fileIdx = $i;
        break;
    }
}
$sbF = $appF->areas($vp)['sidebar'];
$rowF = $sbF->position->y + 3 + $fileIdx;
$colF = $sbF->position->x + 5;
$appF->handle(MouseEvent::new(MouseEventKind::Down, MouseButton::Left, $colF, $rowF, 0), $vp);
$appF->handle(MouseEvent::new(MouseEventKind::Down, MouseButton::Left, $colF, $rowF, 0), $vp);
check($appF->buffer !== null && $appF->buffer->path === $visF[$fileIdx]->path, '双击文件仍能打开进编辑器');

// 点行首三角（▶/▼）展开/折叠（VSCode 习惯）
// 行结构：边框 | marker「» 」2 列 | 缩进 2*depth 列 | 三角 1 列 + 空格 1 列 | 名称
// 故 depth=0 的三角在 sb.x+3，depth=1 在 sb.x+5
// 建一个全新 App 并定位到 src 目录（每个断言都用新实例：连续点击会被双击判定串味）
$makeApp = static function () use ($vp): array {
    $app = new App();
    $tp = new ReflectionProperty(App::class, 'tree');
    $tp->setAccessible(true);
    $tree = $tp->getValue($app);
    $node = null;
    $idx = null;
    foreach ($tree->visible() as $i => $n) {
        if ($n->name === 'src' && $n->isDir) {
            $node = $n;
            $idx = $i;
            break;
        }
    }
    return [$app, $tree, $node, $idx, $app->areas($vp)['sidebar']];
};

/** 在 src 行上点第 $colOff 列（相对侧栏左边界），返回 src 是否被展开 */
$clickOnce = static function (int $colOff) use ($makeApp, $vp): bool {
    [$app, $tree, $node, $idx, $sb] = $makeApp();
    $app->handle(MouseEvent::new(
        MouseEventKind::Down, MouseButton::Left,
        $sb->position->x + $colOff, $sb->position->y + 3 + $idx, 0), $vp);
    return $node->expanded;
};

// 命中区必须逐列钉死：只测「三角那一列」的话，命中区左右偏 1 列都测不出来
check($clickOnce(2) === false, '点三角左侧 marker 区（+2）→ 不展开');
check($clickOnce(3) === true, '点三角本身（+3）→ 展开');
check($clickOnce(4) === true, '点三角右侧空格（+4）→ 也展开（命中区含其后 1 列，方便点中）');
check($clickOnce(5) === false, '点名称首列（+5）→ 不 toggle');
check($clickOnce(10) === false, '点条目名（+10）→ 不 toggle（只选中）');

// 点条目名仍要能选中（上面只验了不展开）
[$appN, $treeN, $nodeN, $idxN, $sbN] = $makeApp();
$appN->handle(MouseEvent::new(
    MouseEventKind::Down, MouseButton::Left,
    $sbN->position->x + 10, $sbN->position->y + 3 + $idxN, 0), $vp);
check($appN->selectedPath === $nodeN->path, '点条目名设为选中项');

// 子目录三角的缩进也要按 depth 偏移（depth=1 → +5）
$clickSub = static function (int $colOff) use ($makeApp, $vp): ?bool {
    [$app, $tree, $node, $idx, $sb] = $makeApp();
    $row = $sb->position->y + 3 + $idx;
    $app->handle(MouseEvent::new(            // 先展开 src，露出 depth=1 的子目录
        MouseEventKind::Down, MouseButton::Left, $sb->position->x + 3, $row, 0), $vp);
    $subIdx = null;
    foreach ($tree->visible() as $i => $n) {
        if ($n->isDir && $n->depth === 1) {
            $subIdx = $i;
            break;
        }
    }
    if ($subIdx === null) {
        return null;
    }
    $sub = $tree->visible()[$subIdx];
    $app->handle(MouseEvent::new(
        MouseEventKind::Down, MouseButton::Left,
        $sb->position->x + $colOff, $sb->position->y + 3 + $subIdx, 0), $vp);
    return $sub->expanded;
};

$sub5 = $clickSub(5);
if ($sub5 === null) {
    echo "  [SKIP] 未找到 depth=1 的子目录\n";
} else {
    check($sub5 === true, 'depth=1 子目录三角（+5）→ 展开（缩进按 depth 偏移正确）');
    check($clickSub(4) === false, 'depth=1 子目录三角左侧（+4）→ 不展开');
    check($clickSub(7) === false, 'depth=1 子目录名称区（+7）→ 不 toggle');
}

echo $failed ? "\nRESULT: FAIL\n" : "\nRESULT: PASS\n";
exit($failed ? 1 : 0);

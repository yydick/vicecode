<?php
declare(strict_types=1);

/**
 * M3 GIT 面板 —— 无终端单测（不依赖 tty / 协程）：
 *  1) GitClient::parseStatusPorcelain 解析样例 porcelain（M/A/D/?/R/U）；
 *  2) GitClient::parseLog 解析样例 oneline；
 *  3) GitClient::parseBranch 去换行；
 *  4) GitFileStatus 类别 / badge / 显示路径（重命名 old -> new）；
 *  5) 渲染：App 切到 GIT tab、注入样例数据，渲染输出含分支与状态行、不崩。
 *
 * 运行：php tests/git_unit.php
 */

require __DIR__ . '/../vendor/autoload.php';
putenv('APP_LOCALE=zh_CN');

use App\App;
use App\Git\GitClient;
use App\Git\GitCommit;
use App\Git\GitFileStatus;

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

// ─────────────── 1) parseStatusPorcelain ───────────────
echo "== parseStatusPorcelain ==\n";
$porcelain = implode("\n", [
    'M  src/App.php',
    'A  src/new.php',
    'D  old.php',
    '?? untracked.txt',
    'R  a.php -> b.php',
    'UU conflict.php',
    ' M spaced name.php',
]) . "\n";
$status = GitClient::parseStatusPorcelain($porcelain);
check(count($status) === 7, '解析出 7 个文件状态');

$byPath = [];
foreach ($status as $s) {
    $byPath[$s->path] = $s;
}
check(($byPath['src/App.php']->badge() === 'M'
    && $byPath['src/App.php']->category() === GitClient::STATUS_STAGED), '已暂存修改 M（index）分类正确');
check(($byPath['src/new.php']->badge() === 'A'
    && $byPath['src/new.php']->category() === GitClient::STATUS_STAGED), '新增 A（已暂存）分类正确');
check($byPath['old.php']->category() === GitClient::STATUS_DELETED, '删除 D 分类正确');
check($byPath['untracked.txt']->category() === GitClient::STATUS_UNTRACKED, '未跟踪 ? 分类正确');
check(($byPath['b.php']->oldPath === 'a.php'
    && $byPath['b.php']->category() === GitClient::STATUS_RENAMED), '重命名 R 提取 old/new 且分类正确');
check($byPath['conflict.php']->category() === GitClient::STATUS_CONFLICT, '冲突 UU 分类正确');
check($byPath['spaced name.php']->category() === GitClient::STATUS_MODIFIED, '工作区修改（含空格路径）分类正确（不丢尾）');

// ─────────────── 2) parseLog ───────────────
echo "== parseLog ==\n";
$log = "abc1234|Jane<jane@x.io>|2 hours ago|fix: handle resize\n"
     . "def5678|John|5 days ago|init project\n";
$commits = GitClient::parseLog($log);
check(count($commits) === 2, '解析出 2 条提交');
check($commits[0] instanceof GitCommit
    && $commits[0]->hash === 'abc1234'
    && $commits[0]->author === 'Jane<jane@x.io>'
    && $commits[0]->subject === 'fix: handle resize', '首条提交字段完整');
check($commits[1]->subject === 'init project', '次条提交 subject 正确');

// ─────────────── 3) parseBranch ───────────────
echo "== parseBranch ==\n";
check(GitClient::parseBranch("feature/x\n") === 'feature/x', '分支名去尾部换行');
check(GitClient::parseBranch('') === '(none)', '空分支回退 (none)');

// ─────────────── 3b) parseBranches（R6） ───────────────
echo "== parseBranches ==\n";
$branchOut = implode("\n", ['main', 'dev', 'feature/x']) . "\n";
$branches = GitClient::parseBranches($branchOut);
check($branches === ['main', 'dev', 'feature/x'], '解析出 3 个本地分支（无 * 标记）');
check(GitClient::parseBranches("  \nmain\n\n") === ['main'], '空行被忽略');

// ─────────────── 4) GitFileStatus 显示 ───────────────
echo "== GitFileStatus 显示 ==\n";
$r = new GitFileStatus('b.php', 'R', '', 'a.php');
check($r->displayPath() === 'a.php -> b.php', '重命名显示 old -> new');
check((new GitFileStatus('x', 'M', ''))->badge() === 'M', 'badge 取 index 状态');
check((new GitFileStatus('x', '', 'M'))->badge() === 'M', 'badge 取 worktree 状态');

// ─────────────── 5) 渲染（注入样例数据，不跑 git） ───────────────
echo "== 渲染 GIT tab ==\n";
$app = new App();
$app->git->setInRepo(true);
$app->git->branch = 'master';
$app->git->loading = false;
$app->git->subView = 0;
$app->git->status = [
    new GitFileStatus('src/App.php', 'M', ''),
    new GitFileStatus('untracked.txt', '?', '?'),
    new GitFileStatus('b.php', 'R', '', 'a.php'),
];
$app->sidebar->tabIndex = 1;
$app->sidebar->gitOffset = 0;
$app->git->selIdx = 0;

use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Extension\Core\CoreExtension;
use PhpTui\Tui\Widget\WidgetRenderer\AggregateWidgetRenderer;
use PhpTui\Tui\Display\Buffer as TuiBuffer;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyCode;

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

check(str_contains($text, '⎇ master'), '状态栏/侧栏含分支名 master');
check(str_contains($text, 'src/App.php'), 'GIT tab 渲染出改动文件 src/App.php');
check(str_contains($text, 'b.php'), 'GIT tab 渲染出重命名文件 b.php');
check(str_contains($text, '状态'), 'GIT tab 头部显示「状态」子视图标题');

// log 子视图
$app->git->subView = 1;
$app->git->log = [
    new GitCommit('abc1234', 'Jane', '2 hours ago', 'fix: handle resize'),
];
$buffer2 = TuiBuffer::empty($vp);
$renderer->render($renderer, $app->render($vp), $buffer2, $buffer2->area());
$text2 = implode("\n", $buffer2->toLines());
check(str_contains($text2, 'abc1234') && str_contains($text2, 'fix: handle resize'), 'GIT tab log 子视图渲染出提交');

// 非仓库占位
$app->git->setInRepo(false);
$buffer3 = TuiBuffer::empty($vp);
$renderer->render($renderer, $app->render($vp), $buffer3, $buffer3->area());
$text3 = implode("\n", $buffer3->toLines());
check(str_contains($text3, '不是 git 仓库'), '非仓库显示占位提示');

// GIT 操作提示行渲染（rowsH 足够时显示）
$app->git->setInRepo(true);
$app->git->subView = 0;
$app->git->status = [new GitFileStatus('src/App.php', 'M', '')];
$app->sidebar->tabIndex = 1;
$buffer4 = TuiBuffer::empty($vp);
$renderer->render($renderer, $app->render($vp), $buffer4, $buffer4->area());
$text4 = implode("\n", $buffer4->toLines());
check(str_contains($text4, 'Commit'), 'GIT tab 渲染 Commit 按钮');
check(str_contains($text4, '提交信息'), 'GIT tab 渲染提交信息输入框（占位）');

// ─────────────── 7) R5 可视化交互（图标 + 输入框 + 下拉按钮） ───────────────
echo "== R5 可视化交互 ==\n";
use PhpTui\Term\Event\MouseEvent;
use PhpTui\Term\MouseEventKind;
use PhpTui\Term\MouseButton;

$app2 = new App();
$app2->sidebar->tabIndex = 1;
$app2->git->setInRepo(true);
$app2->git->status = [new GitFileStatus('src/App.php', 'M', '')];
$app2->git->subView = 0;

// a) 键盘：输入提交信息；Enter 提交（空消息不提交，且不触发 git）
$app2->handle(CharKeyEvent::new('f', 0), $vp);
$app2->handle(CharKeyEvent::new('i', 0), $vp);
$app2->handle(CharKeyEvent::new('x', 0), $vp);
check($app2->git->commitMsg === 'fix', 'GIT tab 键入进提交信息输入框');
$app2->git->commitMsg = '';
$app2->handle(CodedKeyEvent::new(KeyCode::Enter, 0), $vp);
check(str_contains($app2->message, '提交信息'), '空消息 Enter 提交被拒（提示）');

// b) 鼠标点 Commit ▾ 展开下拉；菜单含四项
$sb = $app2->areas($vp)['sidebar'];
$arrowCol = $sb->position->x + 1 + max(0, ($sb->width - 2) - 1);
$arrowRow = $sb->position->y + 1 + 2 + 3; // 上边框+tab/分隔偏移+GIT_COMMIT_ROW
$app2->handle(MouseEvent::new(MouseEventKind::Down, MouseButton::Left, $arrowCol, $arrowRow, 0), $vp);
check($app2->git->dropdownOpen, '点 Commit ▾ 展开下拉菜单');
$bufferD = TuiBuffer::empty($vp);
$renderer->render($renderer, $app2->render($vp), $bufferD, $bufferD->area());
$textD = implode("\n", $bufferD->toLines());
check(str_contains($textD, '提交和推送') && str_contains($textD, '提交和同步'), '下拉菜单含 提交/提交变更/提交和推送/提交和同步');

// c) 点菜单项「提交」（空消息）触发派发并关闭下拉
$menuRow = $sb->position->y + 1 + 2 + 4; // 偏移 + GIT_HEADER_ROW（下拉首项起点）
$app2->handle(MouseEvent::new(MouseEventKind::Down, MouseButton::Left, $arrowCol, $menuRow, 0), $vp);
check(!$app2->git->dropdownOpen, '点菜单项后下拉关闭');
check(str_contains($app2->message, '提交信息'), '点「提交」空消息不提交（提示）');

// d) 点列表行文件名 = 打开变更(diff)，不崩
$app2->git->status = [new GitFileStatus('src/App.php', 'M', '')];
$app2->git->selIdx = 0;
$itemRow = $sb->position->y + 1 + 2 + 5; // 偏移 + GIT_FIRST_ROW
$innerX0 = $sb->position->x + 1;
$nameCol = $sb->position->x + 1 + 11; // 文件名区域（rc=11，避开行首 ▦ + - ✕）
$app2->handle(MouseEvent::new(MouseEventKind::Down, MouseButton::Left, $nameCol, $itemRow, 0), $vp);
check(true, '点列表文件名打开变更(diff) 未崩溃');

// e) 行首 ▦ 图标 = 打开文件（进编辑器，非 diff）
$app2->git->status = [new GitFileStatus('src/App.php', 'M', '')];
$app2->git->selIdx = 0;
$openCol = $innerX0 + 0; // rc=0 → ▦
$app2->handle(MouseEvent::new(MouseEventKind::Down, MouseButton::Left, $openCol, $itemRow, 0), $vp);
check($app2->focusPanel() === 'editor' && ($app2->buffer !== null && $app2->buffer->path === 'src/App.php'),
    '点 ▦ 图标在编辑器打开文件');

// f) 行首 ✕ 图标 = 丢弃工作区改动，弹 y/n 确认（不直接执行，不可逆需二次确认）
$app2->sidebar->tabIndex = 1;
$app2->git->status = [new GitFileStatus('src/App.php', 'M', '')];
$app2->git->selIdx = 0;
$app2->git->commitMsg = '';
$discardCol = $innerX0 + 6; // rc=6 → ✕
$app2->handle(MouseEvent::new(MouseEventKind::Down, MouseButton::Left, $discardCol, $itemRow, 0), $vp);
check($app2->confirm !== null && ($app2->confirm['kind'] ?? '') === 'discard', '点 ✕ 弹丢弃确认框（不可逆，未直接执行）');
$statusBuf = TuiBuffer::empty($vp);
$renderer->render($renderer, $app2->render($vp), $statusBuf, $statusBuf->area());
$statusTxt = implode("\n", $statusBuf->toLines());
check(str_contains($statusTxt, '丢弃'), '确认框提示「丢弃工作区改动」');
// 确认后真正丢弃：用不存在的路径冒烟，验证派发到 discard 且不直接改仓库
$app2->confirm = ['kind' => 'discard', 'path' => '/nonexistent/path/xyz.php'];
$app2->handle(CharKeyEvent::new('y', 0), $vp);
check($app2->confirm === null, 'y 确认后关闭确认框（丢弃已派发）');

// ─────────────── 7h) R6 分支切换下拉 ───────────────
echo "== R6 分支切换下拉 ==\n";
$app3 = new App();
$app3->sidebar->tabIndex = 1;
$app3->git->setInRepo(true);
$app3->git->branch = 'main';
$app3->git->branches = ['main', 'dev', 'feature/x'];
$sb3 = $app3->areas($vp)['sidebar'];
$branchRow = $sb3->position->y + 1 + 2 + 0; // 上边框 + tab/分隔偏移 + GIT 内容行0
$app3->handle(MouseEvent::new(MouseEventKind::Down, MouseButton::Left, $sb3->position->x + 1, $branchRow, 0), $vp);
check($app3->git->branchDropdownOpen, '点分支行打开分支切换下拉');
$bufB = TuiBuffer::empty($vp);
$renderer->render($renderer, $app3->render($vp), $bufB, $bufB->area());
$txtB = implode("\n", $bufB->toLines());
check(str_contains($txtB, 'main') && str_contains($txtB, 'feature/x'), '下拉渲染出本地分支列表');
check(str_contains($txtB, '切换分支'), '下拉标题显示「切换分支」');
// 键盘 ↓ 移动选中
$app3->handle(CodedKeyEvent::new(KeyCode::Down, 0), $vp);
check($app3->git->branchSelIdx === 1, '↓ 在分支下拉内移动选中到 dev');
// Esc 关闭
$app3->handle(CodedKeyEvent::new(KeyCode::Esc, 0), $vp);
check(!$app3->git->branchDropdownOpen, 'Esc 关闭分支下拉');

echo $failed ? "\nM3 单测 FAIL\n" : "\nM3 单测全部 PASS\n";

// ─────────────── 6) 异步刷新真跑 git（协程内，非 tty） ───────────────
echo "== 异步刷新（真实 git 仓库） ==\n";
\Swoole\Coroutine\run(function () use (&$failed): void {
    $app = new App();
    $app->git->refresh();
    // 等刷新协程返回（status/log/branch 三并发，至多几百 ms）
    \Swoole\Coroutine\System::sleep(0.6);
    check($app->git->inRepo(), '检测到当前处于 git 仓库');
    check($app->git->branch !== '' && $app->git->branch !== '(none)', '分支名非空: ' . $app->git->branch);
    check(is_array($app->git->status), 'status 已解析为数组');
    check(is_array($app->git->log), 'log 已解析为数组');
    // 本仓库当前有改动（已跟踪文件被修改），应能解析出至少 1 条状态；其中应有 modified
    check(count($app->git->status) >= 1, 'status 解析到至少 1 条改动');
    $hasModified = false;
    foreach ($app->git->status as $s) {
        if (in_array($s->category(), [GitClient::STATUS_MODIFIED, GitClient::STATUS_STAGED], true)) {
            $hasModified = true;
        }
    }
    check($hasModified, 'status 含已修改/已暂存条目（本仓库有改动）');
});

// ─────────────── 9) 分支切换真实切分支（独立临时 git 仓库） ───────────────
echo "== 分支切换（真实 git 仓库） ==\n";
\Swoole\Coroutine\run(function () use (&$failed): void {
    $dir = sys_get_temp_dir() . '/vicecode_br_' . uniqid();
    mkdir($dir);
    chdir($dir);
    exec('git init --initial-branch=main -q'
        . ' && git config user.email t@t && git config user.name t'
        . ' && echo a > f && git add f && git commit -qm init'
        . ' && git branch feature');
    $app = new App();
    $app->git->refresh();
    $app->git->refreshBranches();
    \Swoole\Coroutine\System::sleep(0.4);
    check($app->git->branch === 'main', 'temp repo 当前分支 main: ' . $app->git->branch);
    check(in_array('feature', $app->git->branches, true), '检测到 feature 分支: ' . implode(',', $app->git->branches));
    $app->git->switchBranch('feature');
    \Swoole\Coroutine\System::sleep(0.4);
    check($app->git->branch === 'feature', 'switchBranch 切到 feature: ' . $app->git->branch);
    $app->git->switchBranch('main');
    \Swoole\Coroutine\System::sleep(0.4);
    check($app->git->branch === 'main', 'switchBranch 切回 main: ' . $app->git->branch);
    chdir('/');
    exec('rm -rf ' . escapeshellarg($dir));
});

echo $failed ? "\nM3 单测 FAIL\n" : "\nM3 单测全部 PASS\n";
exit($failed ? 1 : 0);

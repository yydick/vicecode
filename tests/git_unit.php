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
    // 本仓库当前有改动（本测试文件自身是 untracked），应能解析出至少 1 条 ? 状态
    $hasUntracked = false;
    foreach ($app->git->status as $s) {
        if ($s->category() === GitClient::STATUS_UNTRACKED) {
            $hasUntracked = true;
        }
    }
    check($hasUntracked, 'status 解析到未跟踪文件（本测试文件自身 untracked）');
});

echo $failed ? "\nM3 单测 FAIL\n" : "\nM3 单测全部 PASS\n";
exit($failed ? 1 : 0);

<?php
declare(strict_types=1);

/**
 * AI V2 代码上下文附加 —— 无终端单测：
 *  1) @文件 引用：存在文件展开成围栏块、不存在/越界拒绝、多次引用去重、超限截断；
 *  2) 编辑器选区附加（App::aiAttachSelection，走真实 select 矩形 + getTextRect）；
 *  3) 当前文件附加（App::aiAttachCurrentFile，相对路径 + 焦点切换）。
 *
 * 运行：php tests/ai_attach_unit.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/isolation.php';
putenv('APP_LOCALE=zh_CN');

use App\App;
use App\Editor\Buffer;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\KeyModifiers;
use PhpTui\Tui\Display\Area;

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

// 造一个临时「项目」作为 cwd（AiTools 的 root 与侧栏树都吃 getcwd()）
$root = vc_tmp_dir('vc_attach_root');
@mkdir($root . '/src', 0777, true);
file_put_contents($root . '/src/Foo.php', "<?php\necho 'foo';\n");
file_put_contents($root . '/src/Bar.php', "bar\n");
file_put_contents($root . '/big.txt', str_repeat('X', 10000));
file_put_contents($root . '/README.md', "# readme\n");
$outside = vc_tmp_file('vc_attach_out');
file_put_contents($outside, "secret");
@symlink($outside, $root . '/evil_link');

// 配置走独占目录（存档路径 = dirname(VICECODE_CONFIG)/.vicecode_ai，与别的测试隔离）
$tmpCfg = vc_isolate_config('vc_attach');
file_put_contents($tmpCfg, json_encode(['ai' => ['attachMaxBytes' => 2048]]));

chdir($root); // 项目根
$vp = Area::fromDimensions(120, 40);

/** 发送 AI 输入框内容（真实按键路径），返回**最后一条** user 消息正文（可能存在恢复的旧档） */
function sendInput(App $app, string $text): string
{
    $app->focusIndex = array_search('ai_input', App::PANELS, true);
    $app->ai->insertText($text);
    $app->handle(CharKeyEvent::new("\r", 0), Area::fromDimensions(120, 40));
    $msgs = $app->chat->messages();
    for ($i = count($msgs) - 1; $i >= 0; $i--) {
        if (($msgs[$i]['role'] ?? '') === 'user' && ($msgs[$i]['meta']['kind'] ?? '') !== 'summary') {
            return (string) $msgs[$i]['content'];
        }
    }
    return '';
}

// ─────────────── 1) @文件 引用 ───────────────
echo "== @文件 引用 ==\n";

$app = new App();
$content = sendInput($app, '解释一下 @src/Foo.php 谢谢');
check(str_contains($content, '解释一下 @src/Foo.php 谢谢'), '@token 保留在正文里（所见即所得）');
check(str_contains($content, '（引用文件 src/Foo.php：）'), '展开出引用标注（实际含：' . (str_contains($content, '引用文件') ? '引用文件' : '无') . '）');
check(str_contains($content, "```php\n<?php\necho 'foo';\n```"), '文件内容进围栏代码块且语言=php');

// 不存在的文件：提示并丢弃引用，正文照发
$app2 = new App();
$content = sendInput($app2, '看看 @src/Nope.php');
check(!str_contains($content, '引用文件'), '不存在的文件不展开');
check(str_contains($content, '看看 @src/Nope.php'), '原正文照常发送');
check(str_contains((string) $app2->message, 'Nope.php') || str_contains((string) $app2->message, '不存在'),
    '状态栏提示缺失引用（实际：' . var_export($app2->message, true) . '）');

// 越界路径拒绝（../ 与绝对路径与 symlink）
$app3 = new App();
$content = sendInput($app3, '@../../etc/passwd 和 @/etc/passwd 和 @evil_link');
check(!str_contains($content, '引用文件'), '越界/绝对路径/symlink 出根 全部拒绝');

// 多引用去重 + 各自展开
$app4 = new App();
$content = sendInput($app4, '@src/Foo.php 与 @src/Bar.php 与 @src/Foo.php, 与 @README.md');
check(substr_count($content, '（引用文件') === 3, '重复 @ 引用去重（实际：' . substr_count($content, '（引用文件') . ' 次）');
check(str_contains($content, "```php") && str_contains($content, 'bar'), '两个 php 文件各自展开');
check(str_contains($content, '```markdown'), 'md 扩展名经 langFor 得到 markdown 语言标注');

// 超限截断（vicerc ai.attachMaxBytes=2048，文件 10000 字节）
$app5 = new App();
$content = sendInput($app5, '@big.txt 摘要');
check(str_contains($content, '已截断到 2048 字节'), '超 attachMaxBytes 截断并标注');
check(substr_count($content, 'X') <= 2048, '截断后正文不超过上限');

// ─────────────── 2) 编辑器选区附加 ───────────────
echo "\n== 编辑器选区附加 ==\n";

$app6 = new App();
$buf = Buffer::fromFile($root . '/src/Foo.php');
check($buf !== null, '前置：Buffer 打开成功');
$app6->buffer = $buf;
$a = $app6->areas($vp); // 设置 lastVp
$inner = $a['editor']->inner(new \PhpTui\Tui\Widget\Margin(1, 1));
$contentTop = $inner->position->y; // 无 tabs
// 选第一行整行（列取整屏宽，getTextRect 会截到行尾）
$selp = new ReflectionProperty($app6, 'select');
$selp->setAccessible(true);
$selp->setValue($app6, ['panel' => 'editor', 'aRow' => $contentTop, 'aCol' => $inner->position->x,
    'bRow' => $contentTop, 'bCol' => $inner->position->x + $inner->width - 1]);
$app6->aiAttachSelection();
$in = $app6->ai->input();
check(str_contains($in, '```php') && str_contains($in, "<?php"), '选区文本进围栏代码块（实际前 60 字符：' . var_export(mb_substr($in, 0, 60), true) . '）');
check(str_contains($in, '（文件 src/Foo.php：）'), '选区附加带相对路径标注');
check(str_contains((string) $app6->message, '已附加'), '状态栏提示已附加');
check($app6->focusPanel() === 'ai_input', '附加后焦点切到 AI 输入框');

// 无选区时提示（select 是 App 私有属性，反射置 null 模拟「从未拖选」）
$app7 = new App();
$selp7 = new ReflectionProperty($app7, 'select');
$selp7->setAccessible(true);
$selp7->setValue($app7, null);
$app7->aiAttachSelection();
check($app7->ai->input() === '' && str_contains((string) $app7->message, '拖选'),
    '无选区 → 提示先拖选（实际：' . var_export($app7->message, true) . '）');

// ─────────────── 3) 当前文件附加 ───────────────
echo "\n== 当前文件附加 ==\n";

$app8 = new App();
$app8->buffer = Buffer::fromFile($root . '/src/Bar.php');
$app8->areas($vp);
$app8->aiAttachCurrentFile();
$in = $app8->ai->input();
check(str_contains($in, '（文件 src/Bar.php：）') && str_contains($in, 'bar'), '当前文件全文进围栏块（相对路径）');
check($app8->focusPanel() === 'ai_input', '焦点切到 ai_input');

// 无 buffer → 提示
$app9 = new App();
$app9->aiAttachCurrentFile();
check($app9->ai->input() === '' && str_contains((string) $app9->message, '没有打开的文件'),
    '无打开文件 → 提示（实际：' . var_export($app9->message, true) . '）');

// ─────────────── 4) AI 快捷动作 + 菜单/命令面板（M7）───────────────
echo "\n== AI 快捷动作与菜单 ==\n";

$appA = new App();
$appA->buffer = Buffer::fromFile($root . '/src/Bar.php');
$appA->areas($vp);
// AI 菜单组 + 命令面板可搜到 4 个动作
$aiGroup = null;
foreach ($appA->menuBar->definitions() as $g) {
    if (($g['label'] ?? '') === $appA->t('menu.ai')) {
        $aiGroup = $g;
    }
}
check($aiGroup !== null, '菜单含 AI 组');
$actions = array_column($aiGroup['items'] ?? [], 'action');
foreach (['ai.explain', 'ai.comment', 'ai.refactor', 'ai.unittest'] as $needAct) {
    check(in_array($needAct, $actions, true), "菜单 AI 组含 {$needAct}");
}
$paletteActions = array_column($appA->commandPaletteEntries(), 'id');
foreach (['ai.explain', 'ai.comment', 'ai.refactor', 'ai.unittest'] as $needAct) {
    check(in_array($needAct, $paletteActions, true), "命令面板可搜到 {$needAct}");
}
// Ctrl+E（编辑器焦点）：发送带指令 + 代码块的 prompt（无 provider → 消息入列、报缺 key）
$appA->focusIndex = array_search('editor', App::PANELS, true);
$appA->handle(CharKeyEvent::new('e', KeyModifiers::CONTROL), $vp);
$msgsA = $appA->chat->messages();
$lastUser = '';
for ($i = count($msgsA) - 1; $i >= 0; $i--) {
    if (($msgsA[$i]['role'] ?? '') === 'user') {
        $lastUser = (string) $msgsA[$i]['content'];
        break;
    }
}
check(str_contains($lastUser, $appA->t('ai.act.explain')), 'Ctrl+E → 解释指令入列');
check(str_contains($lastUser, '（文件 src/Bar.php：）') && str_contains($lastUser, 'bar'), 'Ctrl+E 的 prompt 带当前文件代码块');
// 工具模式切换
$before = $appA->chat->toolAutoRun();
$appA->menuAction('ai.tool_mode');
check($appA->chat->toolAutoRun() === !$before, '菜单 ai.tool_mode 切换 toolAutoRun');

chdir(__DIR__ . '/..');
// 临时文件/配置目录的清理由 lib/isolation.php 的 shutdown 统一负责（见该文件顶部说明）

echo "\n" . ($failed ? "SOME FAILED\n" : "RESULT: PASS\n");
exit($failed ? 1 : 0);

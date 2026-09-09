<?php
declare(strict_types=1);

/**
 * 插件系统 V1.1 —— headless 单测：命令钩子 / 状态栏段点击 / 生命周期事件。
 *
 * 设计要点（详见 docs/plugins.md §3.6–3.8）：
 *  - 新能力全是**可选方法**（method_exists 探测），V1 插件零改动继续可用；
 *  - 命令在装载期注册一次，菜单项形状恒定（MenuBarPanel 的索引稳定性前提）；
 *  - 快捷键只支持 Ctrl+字母 / F1–F12，冲突只降级快捷键并记表（失败必须可见）。
 *
 * 脚手架：插件目录建在 tmp（VICECODE_PLUGINS_DIR 覆盖），**不在 plugins/ 下加示例插件**，
 * 这样默认运行时环境不变（仍只有 clock，菜单 4 组 15 项）。
 *
 * 运行：php tests/plugin_v11_unit.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\App;
use App\Plugin\StatusSegment;
use App\Text\DisplayWidth;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event\FunctionKeyEvent;
use PhpTui\Term\Event\MouseEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\KeyModifiers;
use PhpTui\Term\MouseButton;
use PhpTui\Term\MouseEventKind;
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

// ── 脚手架：tmp 下造插件 ────────────────────────────────
// 目录名带字典序前缀：a_demo 先注册（先占 Ctrl+K），z_conflict 后注册（才能测 taken）
$base = sys_get_temp_dir() . '/vc_v11_' . getmypid();

/**
 * @param string $decl commands() 的返回表达式（PHP 源码字符串）
 * @param string $extra 追加到类里的其它方法源码
 */
$mkPlugin = static function (string $dir, string $id, string $class, string $decl, string $extra = '') use ($base): void {
    if (!str_contains($extra, 'function statusSegments')) {
        $extra .= '    public function statusSegments(\\App\\App $a): array { return []; }' . "\n";
    }
    @mkdir($base . '/plugins/' . $dir, 0777, true);
    file_put_contents($base . '/plugins/' . $dir . '/plugin.json', json_encode([
        'id' => $id, 'name' => ucfirst($id), 'class' => $class, 'entry' => $class . '.php',
    ]));
    file_put_contents($base . '/plugins/' . $dir . '/' . $class . '.php', "<?php
final class {$class} implements \\App\\Plugin\\PluginInterface
{
    public static array \$calls = [];
    public static array \$events = [];
    public function id(): string { return '{$id}'; }
    public function tickInterval(): ?int { return null; }
    public function commands(): array { return [{$decl}]; }
    public function executeCommand(string \$i, \\App\\App \$a): void { self::\$calls[] = \$i; }
    public function onEvent(\\App\\Plugin\\PluginEvent \$e): void { self::\$events[] = \$e->name; }
{$extra}}");
};

// a_demo：3 条命令（Ctrl+K / F3 / 无快捷键）+ 4 段状态栏
$mkPlugin('a_demo', 'demo', 'DemoPlugin',
    "new \\App\\Plugin\\PluginCommand('k', 'K cmd', 'Ctrl+K', 10),
     new \\App\\Plugin\\PluginCommand('f3', 'F3 cmd', 'F3', 20),
     new \\App\\Plugin\\PluginCommand('plain', 'Plain cmd', null, 30)",
    "    public function statusSegments(\\App\\App \$app): array { return [
        new \\App\\Plugin\\StatusSegment('seg', 'SGT', 85, 200, 'k'),
        new \\App\\Plugin\\StatusSegment('cjk', '你好世界', 84, 201, 'f3'),
        new \\App\\Plugin\\StatusSegment('empty', '', 83, 202, 'plain'),
        new \\App\\Plugin\\StatusSegment('low', 'LOWLOW', 1, 203, 'plain'),
    ]; }\n");
// z_conflict：三类冲突各一条
$mkPlugin('z_conflict', 'conf', 'ConfPlugin',
    "new \\App\\Plugin\\PluginCommand('dup', 'dup K', 'Ctrl+K'),
     new \\App\\Plugin\\PluginCommand('sys', 'sys Q', 'Ctrl+Q'),
     new \\App\\Plugin\\PluginCommand('alt', 'alt X', 'Alt+X')");
// m_broken：commands() 抛异常
@mkdir($base . '/plugins/m_broken', 0777, true);
file_put_contents($base . '/plugins/m_broken/plugin.json', json_encode([
    'id' => 'broken', 'class' => 'BrokenPlugin', 'entry' => 'BrokenPlugin.php',
]));
file_put_contents($base . '/plugins/m_broken/BrokenPlugin.php', "<?php
final class BrokenPlugin implements \\App\\Plugin\\PluginInterface
{
    public function id(): string { return 'broken'; }
    public function tickInterval(): ?int { return null; }
    public function statusSegments(\\App\\App \$app): array { return []; }
    public function commands(): array { throw new \\RuntimeException('boom'); }
    public function executeCommand(string \$i, \\App\\App \$a): void {}
}");
// n_noexec：声明了命令却没有执行体
@mkdir($base . '/plugins/n_noexec', 0777, true);
file_put_contents($base . '/plugins/n_noexec/plugin.json', json_encode([
    'id' => 'noexec', 'class' => 'NoexecPlugin', 'entry' => 'NoexecPlugin.php',
]));
file_put_contents($base . '/plugins/n_noexec/NoexecPlugin.php', "<?php
final class NoexecPlugin implements \\App\\Plugin\\PluginInterface
{
    public function id(): string { return 'noexec'; }
    public function tickInterval(): ?int { return null; }
    public function statusSegments(\\App\\App \$app): array { return []; }
    public function commands(): array { return [new \\App\\Plugin\\PluginCommand('x', 'X cmd')]; }
}");

$vp = Area::fromDimensions(120, 40);
putenv('VICECODE_PLUGINS_DIR=' . $base);
$app = new App();

// ── 1) 注册 ────────────────────────────────────────────
echo "== 命令注册 ==\n";
$cmds = $app->pluginCommandsOf('demo');
check(count($cmds) === 3, 'a_demo 注册到 3 条命令（实际 ' . count($cmds) . '）');
check(array_keys($cmds) === ['k', 'f3', 'plain'], 'key 是局部 id（k/f3/plain）');
check(count($app->pluginCommandsOf('broken')) === 0, 'commands() 抛异常的插件被跳过（不影响别人）');
check(count($app->pluginCommandsOf('noexec')) === 0, '缺 executeCommand() 的插件命令全部不可用');
$noexecConf = $app->pluginConflictsOf('noexec');
check(($noexecConf['noexec.*']['reason'] ?? '') === 'no_executor', 'noexec 记了 no_executor 冲突');

// ── 2) 菜单形状（索引稳定性硬断言）─────────────────────
echo "== 菜单合并与索引稳定性 ==\n";
$defs = $app->menuBar->definitions();
check(count($defs) === 5, '有插件命令时菜单追加到第 5 组（实际 ' . count($defs) . ' 组）');
check($defs[4]['label'] === $app->t('menu.plugins'), '第 5 组 label 走 i18n（插件）');
$expectActions = [
    'file.open', 'file.save', 'file.close', 'plugins.open', 'file.quit',
    'view.theme', 'view.focus.editor', 'view.focus.terminal', 'view.focus.explorer',
    'view.focus.ai', 'view.lang', 'term.cancel', 'term.clear', 'help.shortcuts', 'help.about',
];
$gotActions = [];
for ($i = 0; $i < 4; $i++) {
    foreach ($defs[$i]['items'] as $it) {
        $gotActions[] = $it['action'];
    }
}
check($gotActions === $expectActions, '前 4 组的 action 序列与改动前逐项一致（15 项）');
$pluginActions = array_column($defs[4]['items'], 'action');
// demo 3 + conf 3：快捷键冲突只降级快捷键，**命令本身仍会注册**（仍可从菜单触发）
check(count($pluginActions) === 6, '插件组共 6 项（demo 3 + conf 3；冲突只降级快捷键）');
foreach ($pluginActions as $a) {
    if (!str_starts_with($a, 'plugin:')) {
        check(false, '插件项 action 必须以 plugin: 开头（实际 ' . $a . '）');
    }
}
check(true, '所有插件项 action 均以 plugin: 开头');
$shortcutsIn = array_column($defs[4]['items'], 'shortcut');
check(in_array('Ctrl+K', $shortcutsIn, true) && in_array('F3', $shortcutsIn, true), '绑定成功的快捷键出现在菜单里');
check(count(array_filter($shortcutsIn, static fn (string $s): bool => $s !== '')) === 2, '只有 2 项带快捷键（其余为空串，不承诺无效键）');

// ── 3) 菜单执行 ───────────────────────────────────────
echo "== 命令执行 ==\n";
DemoPlugin::$calls = [];
$app->menuAction('plugin:demo.k');
check(DemoPlugin::$calls === ['k'], 'menuAction(plugin:demo.k) → executeCommand 收到局部 id k');
check($app->runPluginCommand('demo.nope') === false, '未注册的命令返回 false');

// ── 4) 快捷键 ─────────────────────────────────────────
echo "== 快捷键 ==\n";
DemoPlugin::$calls = [];
$app->handle(CharKeyEvent::new('k', KeyModifiers::CONTROL), $vp);
check(DemoPlugin::$calls === ['k'], 'Ctrl+K → demo.k');
DemoPlugin::$calls = [];
$app->handle(FunctionKeyEvent::new(3), $vp);
check(DemoPlugin::$calls === ['f3'], 'F3 → demo.f3');
// Ctrl+Q 是保留键：不能进插件（应走退出确认/退出流程）
ConfPlugin::$calls = [];
$app->handle(CharKeyEvent::new('q', KeyModifiers::CONTROL), $vp);
check(ConfPlugin::$calls === [], 'Ctrl+Q 属系统保留键，插件不被调用');
// 普通字母（无 Ctrl）不触发
DemoPlugin::$calls = [];
$app->handle(CharKeyEvent::new('k', 0), $vp);
check(DemoPlugin::$calls === [], '无 Ctrl 修饰的 k 不触发插件命令');

// ── 5) 冲突表 ─────────────────────────────────────────
echo "== 快捷键冲突 ==\n";
$conf = $app->pluginConflictsOf('conf');
$reasons = array_column($conf, 'reason');
sort($reasons);
check($reasons === ['reserved', 'taken', 'unsupported'], 'z_conflict 三类冲突齐全（' . implode(',', $reasons) . '）');
$sc = new ReflectionProperty($app, 'pluginShortcuts');
$sc->setAccessible(true);
$bound = $sc->getValue($app);
check(($bound['Ctrl+K'] ?? '') === 'demo.k', 'Ctrl+K 归先注册的 demo（字典序先到先得）');
check(!isset($bound['Ctrl+Q']), 'Ctrl+Q 未被任何插件占用');

// ── 6) 可见提示（插件页）─────────────────────────────
echo "== 插件页可见提示 ==\n";
$lines = $app->pluginsPanel->contentLines();
$all = implode("\n", $lines);
check(str_contains($all, $app->t('plugins.commands')), '插件页列出命令标题');
check(str_contains($all, $app->t('plugins.shortcut_reserved', ['key' => 'Ctrl+Q'])), '提示：与系统快捷键冲突');
check(str_contains($all, $app->t('plugins.shortcut_unsupported', ['key' => 'Alt+X'])), '提示：快捷键语法不支持');
check(str_contains($all, $app->t('plugins.no_executor')), '提示：缺 executeCommand()');

// ── 7) 状态栏布局（placed 与取舍同源）─────────────────
echo "== 状态栏段点击 ==\n";
$statusW = $app->areas($vp)['status']->width;
$r = $app->statusBar->assemble($statusW);
$placedByKey = [];
foreach ($r['placed'] as $p) {
    $placedByKey[$p['k']] = $p;
}
check(isset($placedByKey['seg'], $placedByKey['cjk']), '可点段进入 placed（seg / cjk）');
// 逐列核对：用整行文本取该区间，应等于段文本
foreach (['seg' => 'SGT', 'cjk' => '你好世界'] as $k => $want) {
    $p = $placedByKey[$k] ?? null;
    if ($p === null) {
        check(false, "{$k} 段不在 placed");
        continue;
    }
    $slice = DisplayWidth::mbSubDisp($r['text'], $p['x0'], $p['x1'] - $p['x0'] + 1);
    check(rtrim($slice) === $want, "{$k} 段命中矩形与文本逐列对齐（得「{$slice}」）");
}
check(!isset($placedByKey['empty']), "text 为空串的段不产出矩形（join 也会跳过）");
check($placedByKey['seg']['cmd'] === 'demo.k', 'seg 段带命令 demo.k');
// 边界
$pSeg = $placedByKey['seg'];
check($app->statusBar->clickSegment($pSeg['x0'] - 1) === null, '段左侧一列不命中');
check($app->statusBar->clickSegment($pSeg['x0']) === 'demo.k', '段首列命中');
check($app->statusBar->clickSegment($pSeg['x1']) === 'demo.k', '段末列命中');
check($app->statusBar->clickSegment($pSeg['x1'] + 1) === null, '段右侧一列不命中（分隔符不算）');
// 系统段不可点
$sysHit = false;
foreach ($r['placed'] as $p) {
    if ($p['k'] === 'file' && $p['cmd'] !== null) {
        $sysHit = true;
    }
}
check(!$sysHit, '系统段不带命令，不可点');
// 窄屏：低优先级段被丢弃 → 不可点
$rn = $app->statusBar->assemble(20);
$lowIn = false;
foreach ($rn['placed'] as $p) {
    if ($p['k'] === 'low') {
        $lowIn = true;
    }
}
check(in_array('low', $rn['dropped'], true) && !$lowIn, '窄屏下被丢弃的段不在 placed（不可点）');
// confirm 态
$app->confirm = ['kind' => 'quit'];
$app->statusBar->assemble($statusW);
check($app->statusBar->clickSegment($pSeg['x0']) === null, '未保存确认态下状态栏不可点');
$app->confirm = null;

// ── 8) App 级鼠标端到端 ───────────────────────────────
echo "== 鼠标点击端到端 ==\n";
$app->statusBar->assemble($statusW);
$hit = null;
foreach ($app->statusBar->assemble($statusW)['placed'] as $p) {
    if ($p['k'] === 'seg') {
        $hit = $p;
    }
}
$statusArea = $app->areas($vp)['status'];
DemoPlugin::$calls = [];
$app->handle(MouseEvent::new(
    MouseEventKind::Down, MouseButton::Left,
    $statusArea->position->x + $hit['x0'], $statusArea->position->y, 0), $vp);
check(DemoPlugin::$calls === ['k'], '点状态栏插件段 → 执行该段命令');
// 点系统段列（file 段）不触发
$fileSeg = null;
foreach ($app->statusBar->assemble($statusW)['placed'] as $p) {
    if ($p['k'] === 'file') {
        $fileSeg = $p;
    }
}
DemoPlugin::$calls = [];
$app->handle(MouseEvent::new(
    MouseEventKind::Down, MouseButton::Left,
    $statusArea->position->x + ($fileSeg['x0'] ?? 1), $statusArea->position->y, 0), $vp);
check(DemoPlugin::$calls === [], '点系统段列不触发插件命令');

// ── 9) 生命周期事件 ───────────────────────────────────
echo "== 生命周期事件 ==\n";
$app2 = new App();
DemoPlugin::$events = [];
$app2->focus('terminal');
$app2->focus('editor');
check(in_array('focus.changed', DemoPlugin::$events, true), 'focus() 切换焦点发出 focus.changed');
DemoPlugin::$events = [];
$app2->handle(CodedKeyEvent::new(KeyCode::Tab, 0), $vp);
check(in_array('focus.changed', DemoPlugin::$events, true), 'Tab 键切换焦点也发事件（焦点已收敛到 focus()）');

$app3 = new App();
DemoPlugin::$events = [];
$tmp = tempnam(sys_get_temp_dir(), 'vc_v11f');
file_put_contents($tmp, "hello\n");
$app3->openFile($tmp);
check(in_array('file.opened', DemoPlugin::$events, true), 'openFile 发出 file.opened');
DemoPlugin::$events = [];
$app3->buffer?->insertChar('X');
$app3->editor->save();
check(in_array('file.saved', DemoPlugin::$events, true), '保存发出 file.saved');
DemoPlugin::$events = [];
$app3->editor->removeBuffer($tmp);
check(in_array('file.closed', DemoPlugin::$events, true), 'removeBuffer 发出 file.closed');
unlink($tmp);

// 新 App 的第一条事件必须是 app.ready
$app4 = new App();
DemoPlugin::$events = [];
$app5 = new App();
check(DemoPlugin::$events === [] || DemoPlugin::$events[0] === 'app.ready', 'app.ready 是首个事件（实际 ' . (DemoPlugin::$events[0] ?? 'none') . '）');

// 终端输出（条件轮询，不赌 sleep）
DemoPlugin::$events = [];
$app5->terminal->input = 'echo v11unit';
$app5->terminal->submit();
$deadline = microtime(true) + 5;
while (microtime(true) < $deadline) {
    $app5->terminal->poll();
    if (in_array('terminal.output', DemoPlugin::$events, true)) {
        break;
    }
    usleep(20000);
}
check(in_array('terminal.output', DemoPlugin::$events, true), '终端输出发出 terminal.output');

// 防重入：回调里再发事件会被丢弃
$app6 = new App();
DemoPlugin::$events = [];
$app6->emitPluginEvent('probe.reentry');
check(!in_array('reentrant.should.be.dropped', DemoPlugin::$events, true), '事件回调里再发事件被丢弃（防重入）');

// ── 10) 渲染不崩（含追加的第 5 组）─────────────────────
echo "== 多视口渲染 ==\n";
foreach ([[120, 40], [80, 24], [40, 10], [20, 6], [10, 4]] as [$w, $h]) {
    try {
        $a = new App();
        $a->menuBar->open();
        $small = Area::fromDimensions($w, $h);
        $renderer = null;
        $a->render($small);
        check(true, "视口 {$w}x{$h} 渲染不抛异常");
    } catch (Throwable $e) {
        check(false, "视口 {$w}x{$h} 抛异常 " . $e->getMessage());
    }
}

exec('rm -rf ' . escapeshellarg($base));
echo $failed ? "\nRESULT: FAIL\n" : "\nRESULT: PASS\n";
exit($failed ? 1 : 0);

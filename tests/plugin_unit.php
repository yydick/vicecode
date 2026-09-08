<?php
declare(strict_types=1);

/**
 * 插件系统 V1 —— 无终端单测（headless）：
 *  1) PluginLoader 扫描 plugins 下各子目录的 plugin.json 运行时加载（含 clock 示例）；
 *  2) 坏插件（损坏 JSON / 类不存在 / 不实现接口）被跳过且不中断整体；
 *  3) App 构造后聚合插件段与 tick；
 *  4) StatusBarPanel 在宽屏下包含 clock 段、按统一优先级裁剪。
 *
 * 运行：php tests/plugin_unit.php
 */

require __DIR__ . '/../vendor/autoload.php';
putenv('APP_LOCALE=zh_CN');

use App\App;
use App\Core\ConfigStore;
use App\Plugin\PluginLoader;
use App\Plugin\StatusSegment;

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

echo "== 插件加载器 ==\n";

// 1) 加载真实 plugins/ 目录，应含 clock 示例
$loaded = PluginLoader::load(__DIR__ . '/..');
$ids = array_map(static fn($p): string => $p->id(), $loaded);
check(in_array('clock', $ids, true), 'PluginLoader 扫描 plugins/ 加载到 clock 示例');
check(count($loaded) >= 1, '至少加载到一个插件（clock）');

// 2) 坏插件目录：损坏 JSON / 类不存在 / 不实现接口 都跳过，且不抛、不中断
$tmp = sys_get_temp_dir() . '/vc_plugin_test_' . uniqid();
@mkdir($tmp . '/plugins/badjson', 0777, true);
@mkdir($tmp . '/plugins/noclass', 0777, true);
@mkdir($tmp . '/plugins/notiface', 0777, true);
@file_put_contents($tmp . '/plugins/badjson/plugin.json', '{ this is not json');
@file_put_contents($tmp . '/plugins/noclass/plugin.json', json_encode(['class' => 'NoSuchClassXXX']));
@file_put_contents($tmp . '/plugins/notiface/plugin.json', json_encode(['class' => 'DummyNonPlugin', 'entry' => 'DummyNonPlugin.php']));
@file_put_contents($tmp . '/plugins/notiface/DummyNonPlugin.php', "<?php\nclass DummyNonPlugin {}\n");

$badLoaded = PluginLoader::load($tmp);
check($badLoaded === [], '坏插件（JSON损坏/类缺失/不实现接口）全部被跳过，返回空数组');

// 清理临时目录
@unlink($tmp . '/plugins/badjson/plugin.json');
@unlink($tmp . '/plugins/noclass/plugin.json');
@unlink($tmp . '/plugins/notiface/plugin.json');
@unlink($tmp . '/plugins/notiface/DummyNonPlugin.php');
@rmdir($tmp . '/plugins/badjson');
@rmdir($tmp . '/plugins/noclass');
@rmdir($tmp . '/plugins/notiface');
@rmdir($tmp . '/plugins');
@rmdir($tmp);

echo "== App 集成 ==\n";

$app = new App();
check(count($app->plugins) >= 1, 'App 构造后 $plugins 已填充（含 clock）');
check($app->pluginSegments() !== [], 'pluginSegments() 聚合出插件段（含 clock）');
$hasClockSeg = false;
foreach ($app->pluginSegments() as $seg) {
    if ($seg['k'] === 'clock') {
        $hasClockSeg = true;
    }
}
check($hasClockSeg, 'pluginSegments() 含 key=clock 的段');
check($app->minTickInterval() === 1, 'minTickInterval() 聚合出 clock 的 tick=1（秒）');

echo "== 状态栏渲染 ==\n";

$status = $app->statusBar;
$wide = $status->assemble(200);
check($wide['dropped'] === [], '宽屏(200)下所有段（含 clock）都能放下，dropped 为空');
check((bool) preg_match('/\d{2}:\d{2}:\d{2}/', $wide['text']), '宽屏状态下栏文本含 HH:MM:SS 时间串');

$narrow = $status->assemble(12);
check($narrow['dropped'] !== [], '窄屏(12)下触发段裁剪（低优先级段被丢弃，不报错）');

echo "== 插件配置注入（专用插件配置文件覆盖默认）==\n";

// 用临时插件配置文件（与 ~/.vicerc 分离）覆盖 clock 时区为 America/New_York
$vc = sys_get_temp_dir() . '/vc_plugins_test_' . uniqid() . '.json';
@file_put_contents($vc, json_encode([
    'clock' => ['timezone' => 'America/New_York', 'format' => 'H:i:s'],
]));
putenv('VICECODE_PLUGINS_CONFIG=' . $vc);
$app2 = new App();
putenv('VICECODE_PLUGINS_CONFIG'); // 还原，避免影响后续
@unlink($vc);

$clockText = '';
foreach ($app2->pluginSegments() as $seg) {
    if ($seg['k'] === 'clock') {
        $clockText = $seg['t'];
    }
}
$expectedNy = (new \DateTime('now', new \DateTimeZone('America/New_York')))->format('H:i:s');
check($clockText !== '', '配置注入后仍能聚合出 clock 段');
check($clockText === $expectedNy, 'clock 段时区跟随专用插件配置文件（America/New_York），而非默认 Asia/Shanghai');
check($clockText !== (new \DateTime('now', new \DateTimeZone('Asia/Shanghai')))->format('H:i:s'),
    '配置生效：覆盖后的时区与默认 Asia/Shanghai 不同');

echo "== 插件配置入口（菜单 + 侧栏 + 浮层）==\n";

// 「文件 → 已安装插件」项（action=plugins.open），不再是顶层菜单
$allActions = [];
foreach ($app->menuBar->definitions() as $grp) {
    foreach ($grp['items'] as $it) {
        $allActions[] = $it['action'];
    }
}
check(in_array('plugins.open', $allActions, true), '菜单栏「文件 → 已安装插件」含入口（action=plugins.open）');
$grpLabels = array_map(static fn($g): string => $g['label'], $app->menuBar->definitions());
check(!in_array('插件', $grpLabels, true), '插件不再是顶层菜单（已归入「文件」子项）');

// 触发 menuAction 后浮层打开
$app->menuAction('plugins.open');
check($app->pluginsPanel->isOpen(), 'menuAction("plugins.open") 打开插件管理浮层');

// 浮层内容含已加载插件（clock）与配置文件路径
$pc = implode("\n", $app->pluginsPanel->contentLines());
check(str_contains($pc, 'clock'), '浮层列出已安装插件 clock');
check(str_contains($pc, ConfigStore::pluginsPath()), '浮层显示专用插件配置文件路径（与 ~/.vicerc 分离）');
check(str_contains($pc, 'Asia/Shanghai'), '浮层展示 clock 当前有效配置（timezone=Asia/Shanghai）');
$app->pluginsPanel->close();
check(!$app->pluginsPanel->isOpen(), '浮层可被关闭');

// 侧栏「扩展」tab（图标入口）
$tabs = (new ReflectionClass(\App\Panel\SidebarPanel::class))->getConstant('TABS');
check(in_array('plugins', $tabs, true), '侧栏 TABS 含 plugins（扩展 tab 图标入口）');
check($app->icon('plugins') !== '', '侧栏插件 tab 有图标（config/icons.php）');
check($app->t('sidebar.plugins') === '扩展', '侧栏插件 tab 文案为「扩展」');
$app->sidebar->tabIndex = 3;
$app->sidebar->onKey(\PhpTui\Term\Event\CodedKeyEvent::new(\PhpTui\Term\KeyCode::Enter), []);
check($app->pluginsPanel->isOpen(), '侧栏扩展 tab 按 Enter 打开插件配置浮层');
$app->pluginsPanel->close();

echo "== 在 ViceCode 编辑器内改配置（无需外部编辑器）==\n";

// 用临时配置避免污染真实家目录；openPluginConfig / reloadPluginConfig 都走 ConfigStore::pluginsPath()
$vc2 = sys_get_temp_dir() . '/vc_plugins_edit_' . uniqid() . '.json';
putenv('VICECODE_PLUGINS_CONFIG=' . $vc2);
$app->openPluginConfig();
check($app->buffer !== null && $app->buffer->path === ConfigStore::pluginsPath(),
    'openPluginConfig 在 ViceCode 自带编辑器里打开专用插件配置文件（与 ~/.vicerc 分离，非外部编辑器）');
$clock = null;
foreach ($app->plugins as $p) {
    if ($p->id() === 'clock') {
        $clock = $p;
    }
}
// 模拟用户把 clock 时区改成 UTC 并 Ctrl+S：直接改写插件配置文件后重载
@file_put_contents(ConfigStore::pluginsPath(), json_encode([
    'clock' => ['timezone' => 'UTC', 'format' => 'H:i:s'],
]));
$app->reloadPluginConfig();
check($app->pluginEffectiveConfig($clock)['timezone'] === 'UTC', 'reloadPluginConfig 重新注入：clock 时区变为 UTC（保存即生效，无需重启）');
@unlink($vc2);
putenv('VICECODE_PLUGINS_CONFIG');

echo "== StatusSegment 值对象 ==\n";
$seg = new StatusSegment('x', 'hello', 60, 9);
check($seg->key === 'x' && $seg->text === 'hello' && $seg->priority === 60 && $seg->order === 9, 'StatusSegment 字段正确');

echo $failed ? "\n结果：有失败项\n" : "\n结果：全部通过\n";
exit($failed ? 1 : 0);

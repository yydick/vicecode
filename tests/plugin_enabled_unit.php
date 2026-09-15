<?php
declare(strict_types=1);

/**
 * 插件启用/禁用开关（V1.2）—— 无终端单测（headless）。
 *
 * 约定：启用状态写在插件专用配置文件 `~/.vicecode.plugins.json` 的 `<id>.enabled`
 * （缺省启用），是**核心保留键**，不进插件的 configure()/有效配置。切换有两条等价路径：
 *   ① 插件页/侧栏「扩展」tab 选中后按 Space（走 App::togglePlugin → setPluginEnabled）；
 *   ② 直接在配置文件里改 `"enabled"` 再 Ctrl+S（走 App::reloadPluginConfig）。
 * 两者都要**立即热生效**：重算启用子集 + 重建命令/菜单/面板注册表，无需重启。
 *
 * 断言覆盖到「被禁用后整条链路都不参与」：状态栏段、tick、命令与菜单项、自定义面板、
 * 生命周期事件——而 allPlugins 仍保留它（否则禁用后就无从恢复）。
 *
 * 运行：php tests/plugin_enabled_unit.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\App;
use App\Core\ConfigStore;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Display\Buffer as TuiBuffer;
use PhpTui\Tui\Extension\Core\CoreExtension;
use PhpTui\Tui\Widget\WidgetRenderer\AggregateWidgetRenderer;

putenv('APP_LOCALE=zh_CN');

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

// ── 临时插件：一个把所有可观测面都暴露出来的插件 ────────────────────
$base = sys_get_temp_dir() . '/vc_pen_' . getmypid();
@mkdir($base . '/plugins/demo', 0777, true);
file_put_contents($base . '/plugins/demo/plugin.json', json_encode([
    'id' => 'demo', 'name' => 'Demo', 'class' => 'VCEnableDemoPlugin', 'entry' => 'VCEnableDemoPlugin.php',
]));
file_put_contents($base . '/plugins/demo/VCEnableDemoPlugin.php', <<<'PHP'
<?php
final class VCEnableDemoPlugin implements \App\Plugin\PluginInterface
{
    public static array $lastConfig = [];
    public static int $segCalls = 0;
    public static array $events = [];

    public function id(): string { return 'demo'; }
    public function name(): string { return 'Demo'; }
    public function tickInterval(): ?int { return 1; }
    public function configDefaults(): array { return ['word' => 'hi']; }
    public function configure(array $c): void { self::$lastConfig = $c; }
    public function statusSegments(\App\App $app): array
    {
        self::$segCalls++;
        return [new \App\Plugin\StatusSegment('demo', 'DM', 90, 3)];
    }
    public function commands(): array
    {
        return [new \App\Plugin\PluginCommand('run', 'Run', null, 5)];
    }
    public function executeCommand(string $id, \App\App $a): void {}
    public function panels(): array
    {
        return [new \App\Plugin\PluginPanel('p', 'P',
            fn(\App\App $a, int $w, int $h) => \PhpTui\Tui\Extension\Core\Widget\ParagraphWidget::fromString('x'))];
    }
    public function onEvent(\App\Plugin\PluginEvent $e): void { self::$events[] = $e->name; }
}
PHP);

$cfg = $base . '/plugins.json';
$vicerc = $base . '/vicerc.json';
// 测试配置一律指向独立目录：避免读到真实家目录残留（主题/语言/会话）
putenv('VICECODE_CONFIG=' . $vicerc);
putenv('VICECODE_PLUGINS_DIR=' . $base);
putenv('VICECODE_PLUGINS_CONFIG=' . $cfg);

$vp = Area::fromDimensions(120, 40);
$ext = new CoreExtension();
$renderers = [];
foreach ($ext->widgetRenderers() as $r) {
    $renderers[] = $r;
}
$renderer = new AggregateWidgetRenderer($renderers);

/** 渲染侧栏内容为纯文本行（供断言「扩展 tab 里到底显示了什么」） */
function sidebarLines(App $app, Area $sb, AggregateWidgetRenderer $renderer): array
{
    $buf = TuiBuffer::empty($sb);
    $renderer->render($renderer, $app->sidebar->content($sb, true), $buf, $buf->area());
    return array_map(static fn(string $l): string => rtrim($l), $buf->toLines());
}

// ── 1) 缺省启用：不写 enabled 时一切都参与 ──────────────────────────
echo "== 缺省启用（老配置零迁移）==\n";
file_put_contents($cfg, json_encode([]));
$app = new App();
check(count($app->allPlugins) === 1 && $app->allPlugins[0]->id() === 'demo', 'allPlugins 加载到 demo');
check($app->pluginIsEnabled('demo') === true, '未写 enabled → 视为启用');
check(count($app->plugins) === 1, '启用的插件子集含 demo');
check(in_array('demo', array_column($app->pluginSegments(), 'k'), true), '注入状态栏段');
check($app->minTickInterval() === 1, 'tick 参与周期重绘聚合');
check(count($app->pluginCommandsOf('demo')) === 1, '命令已注册');
check(count($app->pluginPanels()) === 1, '自定义面板已汇聚');
check(VCEnableDemoPlugin::$lastConfig === ['word' => 'hi'], 'configure 收到声明默认（word=hi）');

// ── 2) enabled 是核心保留键：不进 configure()，也不进「有效配置」 ────
echo "== enabled 为保留键，不污染插件配置 ==\n";
file_put_contents($cfg, json_encode(['demo' => ['enabled' => true, 'word' => 'yo']]));
$app2 = new App();
check(VCEnableDemoPlugin::$lastConfig === ['word' => 'yo'], 'configure 收到用户覆盖（word=yo）且**不含** enabled');
check(!array_key_exists('enabled', $app2->pluginEffectiveConfig($app2->allPlugins[0])), '有效配置展示里不出现 enabled');
check($app2->pluginIsEnabled('demo') === true, '显式 enabled=true 视为启用');

// ── 3) 禁用：整条链路退出，但插件本体仍在（可再打开） ────────────────
echo "== 禁用后各消费点都不参与 ==\n";
file_put_contents($cfg, json_encode(['demo' => ['enabled' => false]]));
$app3 = new App();
check(count($app3->allPlugins) === 1, '被禁用的插件仍留在 allPlugins（否则无从恢复）');
check($app3->plugins === [], '被禁用的插件不在启用子集里');
check($app3->pluginIsEnabled('demo') === false, 'pluginIsEnabled=false');

VCEnableDemoPlugin::$segCalls = 0;
check($app3->pluginSegments() === [], '不注入任何状态栏段');
check(VCEnableDemoPlugin::$segCalls === 0, 'statusSegments() 根本不被调用（不是调用后丢弃）');
check($app3->minTickInterval() === null, '不参与 tick 聚合（不因禁用插件白重绘）');
check($app3->pluginCommandsOf('demo') === [], '命令未注册');
check($app3->pluginMenuItems() === [], '「插件」菜单组无条目');
check($app3->pluginPanels() === [], '自定义面板未汇聚');

VCEnableDemoPlugin::$events = [];
$app3->emitPluginEvent('file.saved', ['path' => '/tmp/x']);
check(VCEnableDemoPlugin::$events === [], '不接收生命周期事件');

$pc = implode("\n", $app3->pluginsPanel->contentLines());
check(str_contains($pc, 'demo'), '插件页仍列出被禁用的插件');
check(str_contains($pc, $app3->t('plugins.disabled')), '插件页标注「已禁用」');
check($app3->pluginsPanel->selectedPluginId() === 'demo', '浮层默认选中该插件（可按 Space 打开）');
check($app3->pluginsPanel->selectedPluginEnabled() === false, '浮层报告的选中状态为禁用');

// ── 4) 切换热生效 + 只改 enabled、保留其它键 + 落盘 ──────────────────
echo "== 切换：立即热生效并落盘 ==\n";
$app3->togglePlugin('demo');
check($app3->pluginIsEnabled('demo') === true, 'togglePlugin 翻转回启用');
check(count($app3->plugins) === 1, '启用后立即回到启用子集（无需重启）');
check(count($app3->pluginCommandsOf('demo')) === 1, '启用后命令重新注册（旧表已清，不产生 duplicate 冲突）');
check($app3->pluginShortcutOf('demo.run') === null && $app3->pluginCommandsOf('demo') !== [], '重新注册未留下冲突残留');
check($app3->minTickInterval() === 1, '启用后 tick 恢复聚合');
$saved = json_decode((string) file_get_contents($cfg), true);
check(($saved['demo']['enabled'] ?? null) === true, '启用状态已落盘到插件专用配置文件');
check($app3->message === $app3->t('plugins.toggled_on', ['id' => 'demo']),
    '切换后给出明确回执文案（否则用户不确定到底生效没有）');

// 只改 enabled：该插件其它键与其它插件配置都必须原样保留
file_put_contents($cfg, json_encode(['demo' => ['enabled' => true, 'word' => 'keep']]));
$app3->reloadPluginConfig();
$app3->togglePlugin('demo');            // → 关闭
$saved = json_decode((string) file_get_contents($cfg), true);
check(($saved['demo']['enabled'] ?? null) === false, '再次切换写回 enabled=false');
check(($saved['demo']['word'] ?? null) === 'keep', '切换只动 enabled，保留该插件其它配置键');
check(VCEnableDemoPlugin::$lastConfig === ['word' => 'keep'], '重载后 configure 仍收到完整用户配置（无 enabled）');

// ── 5) 改配置文件 + Ctrl+S：与按 Space 是同一条生效路径 ──────────────
echo "== 改配置文件 + Ctrl+S 同一条生效路径 ==\n";
file_put_contents($cfg, json_encode(['demo' => ['enabled' => false]]));
$app3->reloadPluginConfig();
check($app3->pluginIsEnabled('demo') === false && $app3->plugins === [], 'reloadPluginConfig 应用文件里的 enabled=false');

file_put_contents($cfg, json_encode(['demo' => ['enabled' => true]]));
$app3->editor->openFile(ConfigStore::pluginsPath());
$app3->editor->save();                  // 内部按 path 判定并触发 reloadPluginConfig
check($app3->pluginIsEnabled('demo') === true && count($app3->plugins) === 1,
    '编辑器保存插件配置文件后热启用生效（Ctrl+S 路径）');

// ── 6) UI 路径：侧栏「扩展」tab 列出禁用项 + Space 切换 ──────────────
echo "== 侧栏扩展 tab：列出 + Space 切换 ==\n";
file_put_contents($cfg, json_encode(['demo' => ['enabled' => false]]));
$app4 = new App();
$app4->sidebar->tabIndex = 3;
$sb = $app4->areas($vp)['sidebar'];
$lines = sidebarLines($app4, $sb, $renderer);
$joined = implode("\n", $lines);
check(str_contains($joined, $app4->t('plugins.disabled')), '侧栏扩展 tab 标注「已禁用」');
check(str_contains($joined, 'demo'), '侧栏扩展 tab 仍列出被禁用的插件');
check($app4->sidebar->selectedPluginId() === 'demo', '侧栏选中项可用 id 取回');

// 走真实按键路由：keyCharKeyEvent(' ') → App::handle → 侧栏 Space 分支
$app4->focus('sidebar');
$app4->handle(CharKeyEvent::new(' ', 0), $vp);
check($app4->pluginIsEnabled('demo') === true, '侧栏插件 tab 按空格 → 立即启用（经 App::handle 路由）');
$joined = implode("\n", sidebarLines($app4, $app4->areas($vp)['sidebar'], $renderer));
check(str_contains($joined, $app4->t('plugins.enabled')), '启用后侧栏改标「已启用」');

$app4->handle(CharKeyEvent::new(' ', 0), $vp);
check($app4->pluginIsEnabled('demo') === false, '再按空格 → 再次禁用');

// ── 7) 浮层面板内 Space 同样生效，且选中可移动 ───────────────────────
echo "== 插件页浮层：Space 切换 + 选中移动 ==\n";
$app4->pluginsPanel->open();
check($app4->pluginsPanel->selectedPluginId() === 'demo', '浮层打开时选中首项');
$before = $app4->pluginsPanel->selectedPluginEnabled();
$app4->pluginsPanel->onChar(CharKeyEvent::new(' ', 0));
check($app4->pluginsPanel->selectedPluginEnabled() !== $before, '浮层内 Space 翻转启用状态');
check($app4->pluginIsEnabled('demo') === !$before, '浮层切换与 App 状态同源（pluginIsEnabled 同步变化）');
$app4->pluginsPanel->moveSelection(1);
check($app4->pluginsPanel->selectedPluginId() === 'demo', '只有一项时移动不越界');

// ── 清理 ─────────────────────────────────────────────────────────────
putenv('VICECODE_PLUGINS_CONFIG');
putenv('VICECODE_PLUGINS_DIR');
@unlink($cfg);
@unlink($vicerc);
@unlink($base . '/plugins/demo/plugin.json');
@unlink($base . '/plugins/demo/VCEnableDemoPlugin.php');
@rmdir($base . '/plugins/demo');
@rmdir($base . '/plugins');
@rmdir($base);

echo $failed ? "\n结果：有失败项\n" : "\n结果：全部通过\n";
exit($failed ? 1 : 0);

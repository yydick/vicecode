<?php
declare(strict_types=1);

/**
 * 自定义面板（插件浮层宿主）headless 单测。
 *
 * 覆盖：插件 panels() 汇聚、菜单项 + 命令面板条目、浮层打开/渲染、tab 切换、
 * 面板 onChar 输入路由、Esc 关闭、toggle 语义。
 *
 * 脚手架：插件建在 tmp（VICECODE_PLUGINS_DIR 覆盖），不在 plugins/ 下加示例插件，
 * 保持默认运行环境不变。运行：php tests/plugin_panel_unit.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\App;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyCode;
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

// ── 脚手架：tmp 下造一个声明 2 个面板的插件 ──────────────
$base = sys_get_temp_dir() . '/vc_panel_' . getmypid();
@mkdir($base . '/plugins/pp', 0777, true);
file_put_contents($base . '/plugins/pp/plugin.json', json_encode([
    'id' => 'pp', 'name' => 'PP', 'class' => 'PanelPlugin', 'entry' => 'PanelPlugin.php',
]));
file_put_contents($base . '/plugins/pp/PanelPlugin.php', <<<'PHP'
<?php
final class PanelPlugin implements \App\Plugin\PluginInterface
{
    /** 记录面板 onChar 收到的字符，验证输入路由 */
    public static array $chars = [];

    public function id(): string { return 'pp'; }
    public function tickInterval(): ?int { return null; }
    public function statusSegments(\App\App $a): array { return []; }

    public function panels(): array
    {
        return [
            // Panel A：纯展示（无 onChar）
            new \App\Plugin\PluginPanel('a', 'Panel A',
                fn($app, $w, $h) => \PhpTui\Tui\Extension\Core\Widget\ParagraphWidget::fromString('ZZPNL_A')),
            // Panel B：带 onChar 交互回调
            new \App\Plugin\PluginPanel('b', 'Panel B',
                fn($app, $w, $h) => \PhpTui\Tui\Extension\Core\Widget\ParagraphWidget::fromString('ZZPNL_B'),
                fn($e) => self::onCharRec($e)),
        ];
    }

    public static function onCharRec(\PhpTui\Term\Event\CharKeyEvent $e): bool
    {
        self::$chars[] = $e->char;
        return true;
    }
}
PHP);

$vp = Area::fromDimensions(120, 40);
putenv('VICECODE_PLUGINS_DIR=' . $base);
$app = new App();

// 渲染工具：把 App::render() 的结果画进 buffer，返回拼接文本
$ext = new CoreExtension();
$rs = [];
foreach ($ext->widgetRenderers() as $r) {
    $rs[] = $r;
}
$rr = new AggregateWidgetRenderer($rs);
$renderText = static function (App $a, Area $v) use ($rr): string {
    $b = TuiBuffer::empty($v);
    $rr->render($rr, $a->render($v), $b, $b->area());
    return implode("\n", $b->toLines());
};

echo "== 自定义面板：汇聚与入口 ==\n";
check(count($app->pluginPanels()) === 2, 'panels() 被汇聚为 2 个面板');

$foundMenu = false;
foreach ($app->menuBar->definitions() as $m) {
    foreach ($m['items'] as $it) {
        if ($it['action'] === 'panel.host.open') {
            $foundMenu = true;
        }
    }
}
check($foundMenu, '菜单「视图」含「插件面板」(panel.host.open)');

$entryIds = array_column($app->commandPaletteEntries(), 'id');
check(in_array('panel.host.open', $entryIds, true), '命令面板含 panel.host.open（可检索打开）');

echo "== 打开与渲染 ==\n";
$app->menuAction('panel.host.open');
check($app->panelHost->isOpen(), 'menuAction(panel.host.open) 打开浮层');
$txt = $renderText($app, $vp);
check(str_contains($txt, 'ZZPNL_A'), '浮层渲染出 Panel A 内容');
check(!str_contains($txt, 'ZZPNL_B'), '初始只显示 Panel A（未切到 B）');
check(str_contains($txt, 'Panel A') && str_contains($txt, 'Panel B'), 'tab 栏同时显示两个面板标题');

echo "== tab 切换 ==\n";
$app->panelHost->onKey(CodedKeyEvent::new(KeyCode::Tab, 0));
check($app->panelHost->selectedIndex() === 1, 'Tab 切到第二个 tab（Panel B）');
$txt = $renderText($app, $vp);
check(str_contains($txt, 'ZZPNL_B'), '切 tab 后渲染出 Panel B 内容');
// Shift+Tab 回到上一页
$app->panelHost->onKey(CodedKeyEvent::new(KeyCode::Tab, \PhpTui\Term\KeyModifiers::SHIFT));
check($app->panelHost->selectedIndex() === 0, 'Shift+Tab 回到 Panel A');
// 方向键也能切
$app->panelHost->onKey(CodedKeyEvent::new(KeyCode::Right, 0));
check($app->panelHost->selectedIndex() === 1, '→ 也能切到 Panel B');

echo "== 输入路由（面板 onChar）==\n";
PanelPlugin::$chars = [];
$consumed = $app->panelHost->onChar(CharKeyEvent::new('x', 0));
check($consumed === true && PanelPlugin::$chars === ['x'], 'Panel B 的 onChar 收到字符 x 并消费');
// 切到 Panel A（无 onChar）→ 浮层不消费
$app->panelHost->onKey(CodedKeyEvent::new(KeyCode::Left, 0));
$noConsume = $app->panelHost->onChar(CharKeyEvent::new('y', 0));
check($noConsume === false, 'Panel A 无 onChar → 浮层不消费字符');

echo "== 关闭与 toggle ==\n";
$app->panelHost->onKey(CodedKeyEvent::new(KeyCode::Esc, 0));
check(!$app->panelHost->isOpen(), 'Esc 关闭浮层');
$txt = $renderText($app, $vp);
check(!str_contains($txt, 'ZZPNL_A'), '关闭后浮层不再渲染');
$app->menuAction('panel.host.open');
check($app->panelHost->isOpen(), '再次 menuAction 重新打开（toggle 语义）');

echo "== 无面板时仍可用 ==\n";
// 默认（无插件面板）环境：menuAction 打开后是空提示，不崩
$app2 = new App();
$app2->menuAction('panel.host.open');
try {
    $renderText($app2, $vp);
    check(true, '无插件面板时打开浮层渲染不崩');
} catch (Throwable $e) {
    check(false, '无插件面板时打开浮层渲染不崩（实际: ' . $e->getMessage() . '）');
}

exec('rm -rf ' . escapeshellarg($base));
echo $failed ? "\nRESULT: FAIL\n" : "\nRESULT: PASS\n";
exit($failed ? 1 : 0);

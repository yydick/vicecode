<?php
declare(strict_types=1);

/**
 * R7 配置文件持久化单元测试（headless）。
 *
 * 覆盖：
 *  1) ConfigStore 读写 roundtrip（JSON ~/.vicerc，VICECODE_CONFIG 覆盖路径隔离家目录）
 *  2) 坏/缺失文件降级为空配置，不抛
 *  3) App 构造从配置文件加载 layout/theme/locale
 *  4) 环境变量优先级高于配置文件（APP_LOCALE / APP_THEME）
 *  5) App::saveConfig() 把当前偏好落盘，内容正确
 *  6) 无配置时全部走默认（layout 30/45/0.6/3，dark，zh_CN）
 *
 * 真实 pty 下「退出落盘」由 tests/pty_r7.php 验证（Ctrl+Q → finally → 写入文件）。
 */

require __DIR__ . '/../vendor/autoload.php';

use App\App;
use App\Core\ConfigStore;
use App\Core\LayoutConfig;
use PhpTui\Tui\Display\Area;

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    if ($cond) {
        echo "  [OK] $msg\n";
    } else {
        $failed = true;
        echo "  [FAIL] $msg\n";
    }
}

// 用临时文件隔离，避免污染真实 ~/.vicerc
$tmp = sys_get_temp_dir() . '/vicecode_r7_' . uniqid('', true) . '.json';
$tmpNone = sys_get_temp_dir() . '/vicecode_r7_none_' . uniqid('', true) . '.json';
register_shutdown_function(static function () use ($tmp, $tmpNone): void {
    @unlink($tmp);
    @unlink($tmpNone);
});

function setCfg(string $path): void
{
    putenv('VICECODE_CONFIG=' . $path);
}
function clearEnv(): void
{
    // 只清语言/主题环境变量；VICECODE_CONFIG 由各用例自行 setCfg 管理，不可在此删除，
    // 否则 saveConfig() 会回落到真实 ~/.vicerc 并污染其它用例。
    putenv('APP_LOCALE');
    putenv('APP_THEME');
}

echo "\n== 1) ConfigStore 读写 roundtrip ==\n";
setCfg($tmp);
$data = [
    'layout' => ['sidebarWidth' => 50, 'aiWidth' => 55, 'editorRatio' => 0.7, 'aiInputHeight' => 8],
    'theme' => 'midnight',
    'locale' => 'en',
];
check(ConfigStore::save($data), 'save() 成功写入');
$back = ConfigStore::load();
check($back === $data, 'load() 读回与写入完全一致');
check(($back['layout']['sidebarWidth'] ?? 0) === 50, '布局字段保留');
check(($back['theme'] ?? '') === 'midnight', '主题字段保留');
check(($back['locale'] ?? '') === 'en', '语言字段保留');

echo "\n== 2) 坏/缺失文件降级 ==\n";
setCfg($tmpNone); // 不存在
check(ConfigStore::load() === [], '缺失文件 → 空配置（不抛）');
file_put_contents($tmp, 'this is not json {{{');
check(ConfigStore::load() === [], '损坏 JSON → 空配置（不抛）');

echo "\n== 3) App 从配置文件加载 ==\n";
setCfg($tmp);
file_put_contents($tmp, json_encode([
    'layout' => ['sidebarWidth' => 52, 'aiWidth' => 40, 'editorRatio' => 0.33, 'aiInputHeight' => 6],
    'theme' => 'midnight',
    'locale' => 'en',
], JSON_PRETTY_PRINT));
$app = new App();
check($app->layout->sidebarWidth === 52, '布局侧栏宽从文件加载为 52');
check(abs($app->layout->editorRatio - 0.33) < 1e-6, '编辑器比例从文件加载为 0.33');
check($app->theme->id === 'midnight', '主题从文件加载为 midnight');
check($app->locale() === 'en', '语言从文件加载为 en');
$areas = $app->areas(Area::fromDimensions(120, 40));
check($areas['sidebar']->width === 52, '切出的侧栏矩形宽度==配置值 52');

echo "\n== 4) 环境变量优先级高于配置文件 ==\n";
// 配置文件 locale=en，但 APP_LOCALE=zh_CN 应胜出
putenv('APP_LOCALE=zh_CN');
putenv('APP_THEME=dark');
setCfg($tmp);
file_put_contents($tmp, json_encode(['theme' => 'midnight', 'locale' => 'en'], JSON_PRETTY_PRINT));
$app2 = new App();
check($app2->locale() === 'zh_CN', 'APP_LOCALE 覆盖配置文件 locale');
check($app2->theme->id === 'dark', 'APP_THEME 覆盖配置文件 theme');
clearEnv();

echo "\n== 5) saveConfig() 落盘当前偏好 ==\n";
$tmpSave = sys_get_temp_dir() . '/vicecode_r7_save_' . uniqid('', true) . '.json';
@unlink($tmpSave);
setCfg($tmpSave);
clearEnv();
$app3 = new App();  // $tmpSave 不存在 → 默认 dark / zh_CN 起步
$app3->layout = $app3->layout->withSidebarWidth(58)->withAiInputHeight(9);
// 模拟运行时切换：拖拽改布局 + Ctrl+T 切主题 + 切语言，都应反映进落盘
$app3->cycleTheme();   // dark → midnight
$app3->toggleLocale(); // zh_CN → en
check($app3->saveConfig(), 'saveConfig() 返回成功');
$saved = json_decode((string) file_get_contents($tmpSave), true);
check(($saved['layout']['sidebarWidth'] ?? 0) === 58, '落盘 sidebarWidth=58（拖拽后）');
check(($saved['layout']['aiInputHeight'] ?? 0) === 9, '落盘 aiInputHeight=9');
check(($saved['theme'] ?? '') === 'midnight', '落盘 theme=midnight（运行时切换后）');
check(($saved['locale'] ?? '') === 'en', '落盘 locale=en（运行时切换后）');
@unlink($tmpSave);

echo "\n== 6) 无配置走默认 ==\n";
setCfg($tmpNone); // 确保不存在
clearEnv();
$app4 = new App();
check($app4->layout->sidebarWidth === LayoutConfig::DEFAULT_SIDEBAR, '默认侧栏宽 30');
check($app4->layout->aiWidth === LayoutConfig::DEFAULT_AI, '默认 AI 宽 45');
check($app4->theme->id === 'dark', '默认主题 dark');
check($app4->locale() === 'zh_CN', '默认语言 zh_CN');

clearEnv();
echo "\n";
if ($failed) {
    echo "R7 单测存在 FAIL\n";
    exit(1);
}
echo "R7 单测全部 PASS\n";

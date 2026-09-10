<?php
declare(strict_types=1);

/**
 * 命令面板（F1 唤出）headless 单测：开/关、过滤、Backspace、选中执行、Esc 关闭、渲染冒烟。
 *
 * 脚手架：VICECODE_PLUGINS_DIR 指向 tmp 空目录，保证命令清单只有系统 16 项（无插件组），
 * 断言可确定化。运行：php tests/command_palette_unit.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\App;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event\FunctionKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Tui\Display\Area;

$base = sys_get_temp_dir() . '/vc_palette_' . getmypid();
@mkdir($base . '/plugins', 0777, true);
putenv('VICECODE_PLUGINS_DIR=' . $base);

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
$app = new App();

// ── 1) F1 开/关 ────────────────────────────────────────
echo "== 唤起与关闭 ==\n";
$app->handle(FunctionKeyEvent::new(1), $vp);
check($app->palette->isOpen(), 'F1 打开命令面板');
check($app->palette->totalCount() === 16, '命令清单为系统 16 项（无插件组，实际 ' . $app->palette->totalCount() . '）');
check($app->palette->matchCount() === 16, '空过滤时展示全部 16 项');

// 再按 F1 收起
$app->handle(FunctionKeyEvent::new(1), $vp);
check(!$app->palette->isOpen(), '再按 F1 收起命令面板');

// ── 2) 过滤输入 ────────────────────────────────────────
echo "== 过滤 ==\n";
$app->handle(FunctionKeyEvent::new(1), $vp);
foreach (str_split('terminal') as $ch) {
    $app->handle(CharKeyEvent::new($ch), $vp);
}
check($app->palette->filterText() === 'terminal', '过滤串拼接正确（terminal）');
check($app->palette->matchCount() === 1, '过滤 terminal 只剩 1 项（实际 ' . $app->palette->matchCount() . '）');
check($app->palette->selectedId() === 'view.focus.terminal', '唯一匹配即 view.focus.terminal');

// Backspace 缩短过滤串
$app->handle(CodedKeyEvent::new(KeyCode::Backspace), $vp);
check($app->palette->filterText() === 'termina', 'Backspace 删掉末字符');
check($app->palette->matchCount() >= 1, '缩短后仍至少 1 项');

// ── 3) 选中执行（走 menuAction 分发）────────────────────
echo "== 选中执行 ==\n";
// 重新过滤到精确项并回车
$app->palette->close();
$app->handle(FunctionKeyEvent::new(1), $vp);
foreach (str_split('view.focus.terminal') as $ch) {
    $app->handle(CharKeyEvent::new($ch), $vp);
}
check($app->palette->matchCount() === 1, '精确过滤 view.focus.terminal 剩 1 项');
$app->handle(CodedKeyEvent::new(KeyCode::Enter), $vp);
check(!$app->palette->isOpen(), '回车执行后面板关闭');
check($app->focusPanel() === 'terminal', '执行后焦点切到 terminal（命令确实生效，实际 ' . $app->focusPanel() . '）');

// ── 4) Esc 关闭 ────────────────────────────────────────
echo "== Esc 关闭 ==\n";
$app->handle(FunctionKeyEvent::new(1), $vp);
check($app->palette->isOpen(), '再次打开');
$app->handle(CodedKeyEvent::new(KeyCode::Esc), $vp);
check(!$app->palette->isOpen(), 'Esc 关闭面板');

// ── 5) 过滤收窄→放宽（Backspace 使匹配变多）────────────
echo "== 收窄后放宽 ==\n";
$app->handle(FunctionKeyEvent::new(1), $vp);
foreach (str_split('ai') as $ch) {
    $app->handle(CharKeyEvent::new($ch), $vp);
}
$narrow = $app->palette->matchCount();
$app->handle(CodedKeyEvent::new(KeyCode::Backspace), $vp); // -> 'a'
check($app->palette->matchCount() > $narrow, "Backspace 放宽过滤后匹配项变多（{$narrow} → {$app->palette->matchCount()}）");

// ── 6) 渲染冒烟（不抛异常、浮层确实绘出）──────────────
echo "== 渲染冒烟 ==\n";
$app->palette->close();
$wBase = $app->render($vp);
check($wBase instanceof \PhpTui\Tui\Widget\Widget, '关闭状态下 render 返回底层 Widget');

$app->handle(FunctionKeyEvent::new(1), $vp);
foreach (str_split('theme') as $ch) {
    $app->handle(CharKeyEvent::new($ch), $vp);
}
$wOpen = $app->render($vp);
check($wOpen instanceof \PhpTui\Tui\Widget\Widget, '打开+过滤状态下 render 不抛异常并返回 Widget');

echo $failed ? "\n结果：FAIL\n" : "\n结果：全部通过\n";
exit($failed ? 1 : 0);

<?php
declare(strict_types=1);

/**
 * Tab 补全 / 缩进机制 —— 无终端单测（不依赖 tty）。
 *
 * 覆盖：
 *  1) `@文件` 补全（AI 输入框）：触发、前缀过滤、接受后写回；
 *  2) 编辑器 Tab 缩进 / Shift+Tab 反向缩进；
 *  3) 口径「单行输入（搜索/提交）无候选时 Tab 仍切焦点」的回归；
 *  4) 候选浮层真的画出来了（贴锚点、底层不被抹白）。
 *
 * ⚠️ 渲染断言必须**注册 DropdownOverlay 渲染器**，否则浮层会被静默跳过、什么都不画
 * （渲染器按类型匹配，没注册就当普通 widget 处理不了）—— 这是本类测试最容易踩的坑。
 *
 * 运行：php tests/completion_unit.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/isolation.php';
chdir(__DIR__ . '/..');                 // 补全的候选来自 cwd（AiTools 的 root），固定成项目根
vc_isolate_config('vc_completion');
putenv('APP_LOCALE=zh_CN');

use App\App;
use App\Core\CompletionItem;
use App\Core\CompletionState;
use App\Widget\DropdownOverlay;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Display\Backend\DummyBackend;
use PhpTui\Tui\DisplayBuilder;

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

/** 逐字键入（走真实事件路径） */
function type(App $app, string $text, Area $vp): void
{
    foreach (mb_str_split($text) as $ch) {
        $app->handle(CharKeyEvent::new($ch, 0), $vp);
    }
}

function tab(App $app, bool $back, Area $vp): void
{
    $app->handle(CodedKeyEvent::new($back ? KeyCode::BackTab : KeyCode::Tab, $back ? 1 : 0), $vp);
}

/** true = 测试用的注释：断言候选里出现某 label */
function hasLabel(App $app, string $label): bool
{
    foreach ($app->completion()->items() as $it) {
        if ($it instanceof CompletionItem && $it->label === $label) {
            return true;
        }
    }
    return false;
}

// ═══════════ 1) @ 文件补全（AI 输入框）═══════════
echo "== @ 文件补全（AI 输入框）==\n";

$app = new App();
$app->focus('ai_input');
check($app->focusPanel() === 'ai_input', '焦点在 AI 输入框');

// 单独一个 '@' 就应弹出根目录条目（VSCode 同款手感）
type($app, '@', $vp);
check($app->completion()->isActive(), '键入 @ 后候选活跃');
check($app->completion()->items() !== [], '有候选');
check(!str_contains($app->ai->input(), ' '), '触发补全不会改动输入框内容（只弹候选）');

// 前缀过滤：只有以 READM 开头的条目留下
type($app, 'READM', $vp);
check($app->completion()->isActive(), '继续键入后候选仍活跃（前缀已变）');
check(hasLabel($app, 'README.md'), '候选里有 README.md（前缀过滤生效）');
$hasUnrelated = false;
foreach ($app->completion()->items() as $it) {
    if ($it instanceof CompletionItem && !str_starts_with($it->insert, '@READM')) {
        $hasUnrelated = true;
    }
}
check(!$hasUnrelated, '候选里没有不匹配前缀的条目');

// 非 @ token 不触发（普通词的中点也点不出候选）
$app2 = new App();
$app2->focus('ai_input');
type($app2, 'hello world', $vp);
check(!$app2->completion()->isActive(), '普通文本不触发候选（只有 @ 开头才补全）');

// 空格打断：@ 后面带空格就不再是路径 token
$app3 = new App();
$app3->focus('ai_input');
type($app3, '@src 后面', $vp);
check(!$app3->completion()->isActive(), '@src 后接空格 → 不再是补全 token');

// ═══════════ 2) 接受补全（Tab）═══════════
echo "== 接受补全 ==\n";

$c1 = new App();
$c1->focus('ai_input');
type($c1, '请读 @READM', $vp);
check($c1->completion()->isActive(), '前缀 @READM 有候选');
tab($c1, false, $vp);
check($c1->ai->input() === '请读 @README.md',
    'Tab 接受候选：把 @READM 补成 @README.md（实际 ' . var_export($c1->ai->input(), true) . '）');
check(!$c1->completion()->isActive(), '接受后候选关闭');

// 目录候选带尾斜杠（便于继续往里补）
$c2 = new App();
$c2->focus('ai_input');
type($c2, '@src/Co', $vp);
check($c2->completion()->isActive() && hasLabel($c2, 'Core/'), '目录候选 label 带 /（实际候选 ' . count($c2->completion()->items()) . ' 条）');
tab($c2, false, $vp);
check($c2->ai->input() === '@src/Core/', '接受目录候选 → @src/Core/（实际 ' . var_export($c2->ai->input(), true) . '）');

// ═══════════ 3) 编辑器缩进 ═══════════
echo "== 编辑器 Tab 缩进 / Shift+Tab 反向缩进 ==\n";

$file = vc_tmp_file('vc_completion_edit');
file_put_contents($file, "abc");
$ed = new App();
$ed->openFile($file);
$ed->focus('editor');
check($ed->buffer !== null && $ed->buffer->cursorCol === 0, '编辑器打开文件、光标在行首');

tab($ed, false, $vp);
check($ed->buffer->lines[0] === '    abc',
    'Tab 在光标处插 4 空格（实际 ' . var_export($ed->buffer->lines[0], true) . '）');
check($ed->buffer->cursorCol === 4, '缩进后光标右移 4 列（实际 ' . $ed->buffer->cursorCol . '）');

tab($ed, true, $vp);
check($ed->buffer->lines[0] === 'abc',
    'Shift+Tab 反向缩进删掉行首 4 空格（实际 ' . var_export($ed->buffer->lines[0], true) . '）');
check($ed->buffer->cursorCol === 0, '反向缩进后光标回到行首（实际 ' . $ed->buffer->cursorCol . '）');

// 行首没有空白时反向缩进不该改内容、也不该吞键后把光标搞乱
tab($ed, true, $vp);
check($ed->buffer->lines[0] === 'abc', '行首无空白时 Shift+Tab 不改内容');

// ═══════════ 4) 单行输入仍切焦点（口径 3）═══════════
echo "== 单行输入：无候选时 Tab 仍切焦点 ==\n";

$sw = new App();
$sw->sidebar->tabIndex = 2;           // SEARCH tab
$sw->focus('sidebar');
tab($sw, false, $vp);
check($sw->focusPanel() !== 'sidebar',
    '搜索框里 Tab 仍切焦点（口径：单行不缩进；若被吞掉焦点会留在 sidebar，实际 ' . $sw->focusPanel() . '）');
check($sw->search->query === '', '切焦点没有污染搜索框内容');

$sw2 = new App();
$sw2->sidebar->tabIndex = 1;          // GIT tab
$sw2->focus('sidebar');
tab($sw2, false, $vp);
check($sw2->focusPanel() !== 'sidebar', 'GIT 提交框里 Tab 仍切焦点（实际 ' . $sw2->focusPanel() . '）');

// 反向：Shift+Tab 在单行输入里反向切焦点（原本这个键全项目零处理）
$sw3 = new App();
$sw3->sidebar->tabIndex = 2;
$sw3->focus('sidebar');
$before = $sw3->focusPanel();
tab($sw3, true, $vp);
check($sw3->focusPanel() !== $before,
    'Shift+Tab 在非输入上下文反向切焦点（实际 ' . $sw3->focusPanel() . '）');

// ═══════════ 5) 候选浮层渲染 ═══════════
echo "== 候选浮层渲染 ==\n";

/** 渲染一帧成去 ANSI 的文本（必须注册 DropdownOverlay 渲染器） */
function renderApp(App $app, Area $vp): string
{
    $backend = new DummyBackend(120, 40);
    $disp = DisplayBuilder::default($backend)
        ->fullscreen()
        ->addWidgetRenderer(DropdownOverlay::renderer())   // ⚠️ 漏了这句浮层会被静默跳过、什么都不画
        ->build();
    $disp->draw($app->render($vp));
    return (string) preg_replace('/\x1B\[[0-9;?]*[ -\/]*[@-~]/', '', $backend->toString());
}

// 锚点用 `Core/`：它是 **src/ 的子目录**，而侧栏的目录树默认是**折叠**的（只列根条目），
// 所以这个串只可能来自候选浮层本身。⚠️ 不能拿根目录里的名字当锚点（比如 README.zh.md）——
// 那同时也是侧栏树里的一行，浮层关掉它也还在，阴性断言会永远恒假（BUGFIXES 里踩过同类坑）。
$rc = new App();
$rc->focus('ai_input');
$noPopup = renderApp($rc, $vp);
check(!str_contains($noPopup, 'Core/'), '前置对照：没候选时屏幕上没有 Core/（证明该串只可能来自浮层）');

type($rc, '@src/Co', $vp);
check($rc->completion()->isActive(), '渲染前置：候选活跃');
$withPopup = renderApp($rc, $vp);
check(str_contains($withPopup, 'Core/'), '候选浮层出现在屏幕上（含候选 Core/）');
check(str_contains($withPopup, '@src/Co'), '底层 AI 输入框仍可见（浮层是透明叠加，没有抹白底层）');

// 候选关掉后浮层必须消失（上一条已证明浮层能画出来 → 阴性断言有正向锚点兜底）
$rc->completion()->close();
$afterClose = renderApp($rc, $vp);
check(!str_contains($afterClose, 'Core/'), '候选关闭后浮层消失（屏幕上不再有 Core/）');

// ═══════════ 6) 插件补全扩展点（V1.2 completions()）═══════════
echo "== 插件提供的候选（completions 扩展点）==\n";

$plugBase = sys_get_temp_dir() . '/vc_completion_plugin_' . getmypid();
@mkdir($plugBase . '/plugins/repl', 0777, true);
file_put_contents($plugBase . '/plugins/repl/plugin.json', json_encode([
    'id' => 'repl', 'name' => 'Repl', 'class' => 'VcReplPlugin', 'entry' => 'VcReplPlugin.php',
]));
// 插件认 `/` 前缀（核心不做触发符限制，认不认由 provider 自己决定）
file_put_contents($plugBase . '/plugins/repl/VcReplPlugin.php', <<<'PLUGIN'
<?php
final class VcReplPlugin implements \App\Plugin\PluginInterface
{
    public static int $calls = 0;
    public function id(): string { return 'repl'; }
    public function tickInterval(): ?int { return null; }
    public function statusSegments(\App\App $a): array { return []; }
    public function completions(string $context, string $text, int $cursor, string $prefix): array
    {
        self::$calls++;
        if ($context !== 'ai_input' || !str_starts_with($prefix, '/')) {
            return [];
        }
        return [new \App\Core\CompletionItem('/plan', '/plan ', '切到计划档')];
    }
}
PLUGIN);
$plugCfg = vc_isolate_config('vc_completion_plugins');
putenv('VICECODE_PLUGINS_DIR=' . $plugBase);
register_shutdown_function(static function () use ($plugBase): void {
    @unlink($plugBase . '/plugins/repl/plugin.json');
    @unlink($plugBase . '/plugins/repl/VcReplPlugin.php');
    @rmdir($plugBase . '/plugins/repl');
    @rmdir($plugBase . '/plugins');
    @rmdir($plugBase);
});

$pl = new App();
check(count($pl->completion()->items()) === 0, '前置：还没输入时没有候选');
$pl->focus('ai_input');
type($pl, '/pl', $vp);
check($pl->completion()->isActive() && hasLabel($pl, '/plan'),
    '插件提供的候选出现了（实际 ' . count($pl->completion()->items()) . ' 条）');
tab($pl, false, $vp);
check($pl->ai->input() === '/plan ',
    'Tab 接受插件候选（实际 ' . var_export($pl->ai->input(), true) . '）');
// provider 是**同步**的、每次按键都会被调一次（契约写进了 PluginInterface 的注释）
check(VcReplPlugin::$calls > 0, '插件 provider 确实被调用了（实际 ' . VcReplPlugin::$calls . ' 次）');

// 禁用该插件 → 候选立刻消失（与其它插件能力同一套启停链路：整体重建 provider 表）
$pl->setPluginEnabled('repl', false);
$pl2 = new App();
$pl2->focus('ai_input');
type($pl2, '/pl', $vp);
check(!$pl2->completion()->isActive(), '插件被禁用后不再贡献候选（provider 表已重建）');
$pl->setPluginEnabled('repl', true);   // 复原，避免影响后面的用例

echo "\n";
if ($failed) {
    echo "RESULT: FAIL\n";
    exit(1);
}
echo "RESULT: PASS（Tab 补全/缩进全部通过）\n";

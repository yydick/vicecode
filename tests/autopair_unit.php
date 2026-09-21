<?php
declare(strict_types=1);

/**
 * 编辑器「符号自动配对」—— 无终端单测。
 *
 * 覆盖三层：
 *  1) 配置解析（`.vicerc` 的 `editor.autoPairs`：缺省 / 显式关闭 / 脏条目 / 自定义含 `<>`）；
 *  2) 纯决策 `AutoPair::plan()`（打左补右 / 跳过已有右符号 / 引号词后不配对）；
 *  3) 编辑器里的**真实效果**（走 App::handle 的按键与鼠标路径）：
 *     打左补右、打右跳过、退格成对删、选中包裹。
 *
 * 运行：php tests/autopair_unit.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/isolation.php';

use App\App;
use App\Core\ConfigStore;
use App\Editor\AutoPair;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event\MouseEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\MouseButton;
use PhpTui\Term\MouseEventKind;
use PhpTui\Tui\Display\Area;

$cfg = vc_isolate_config('vc_autopair');
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

$vp = Area::fromDimensions(120, 40);

/** 开一个编辑器 + 指定内容的临时文件，返回 [App, 文件路径] */
function editorWith(string $content): array
{
    $file = vc_tmp_file('vc_autopair_file');
    file_put_contents($file, $content);
    $app = new App();
    $app->openFile($file);      // 打开后焦点自动切到 editor
    global $vp;
    $app->render($vp);          // 渲染一帧：面板要记下编辑器矩形（选中包裹要用）
    return [$app, $file];
}

/** 逐字键入 */
function type(App $app, string $text, Area $vp): void
{
    foreach (mb_str_split($text) as $ch) {
        $app->handle(CharKeyEvent::new($ch, 0), $vp);
    }
}

// ═══════════ 1) 配置解析 ═══════════
echo "== 配置解析（editor.autoPairs）==\n";

$default = ConfigStore::editorAutoPairs();
check($default === ['()', '[]', '{}', '""', "''"],
    '缺省 = 默认集且**不含 <>**（实际 ' . json_encode($default) . '）');
check(!in_array('<>', $default, true), '默认集里没有 <>（PHP 里 < 是运算符，自动补 > 会干扰比较）');

file_put_contents($cfg, json_encode(['editor' => ['autoPairs' => []]]));
check(ConfigStore::editorAutoPairs() === [], '显式写 [] = 关闭（与"没写"区分开）');

file_put_contents($cfg, json_encode(['editor' => ['autoPairs' => ['()', '<<', 'x', 42, '()', '()()', '{}']]]));
check(ConfigStore::editorAutoPairs() === ['()', '<<', '{}'],
    '脏条目丢掉、好条目保留：长度 1 的 x / 非字符串 42 / 长度 4 的 ()() / 重复的 () 都不要，'
    . "'<<' 是合法的两字符对（实际 " . json_encode(ConfigStore::editorAutoPairs()) . '）');

file_put_contents($cfg, json_encode(['editor' => ['autoPairs' => ['()', '<>']]]));
check(in_array('<>', ConfigStore::editorAutoPairs(), true), '自定义可以加 <>（用户明确要的）');

file_put_contents($cfg, json_encode(['editor' => ['autoPairs' => 'not-an-array']]));
check(ConfigStore::editorAutoPairs() === $default, '类型不对（写成字符串）→ 退回默认集，不崩');

// ═══════════ 2) 纯决策 AutoPair::plan ═══════════
echo "== 纯决策 AutoPair::plan ==\n";

$ap = new AutoPair(['()', '[]', '{}', '""', "''"]);
check($ap->plan('(', '', '') === AutoPair::PAIR, '行首打 ( → 补右');
check($ap->plan(')', '(', '') === AutoPair::PLAIN,
    '行尾打 ) 就是普通插入（右侧没有同名符号可跳过，别误判成 SKIP）');
check($ap->plan(')', '(', ')') === AutoPair::SKIP, '右符号在光标右侧 → 跳过（不重复插）');
check($ap->plan('(', '', '') === AutoPair::PAIR && $ap->plan('(', 'x', '') === AutoPair::PAIR,
    '左符号前面是词也照常配对（只有引号有词后规则）');
check($ap->plan('"', 'x', '') === AutoPair::PLAIN, '引号紧跟词字符后不配对（don\'t / it\'s）');
check($ap->plan('"', ' ', '') === AutoPair::PAIR, '引号前面是空格 → 正常配对');
check($ap->plan("'", '', '') === AutoPair::PAIR, '行首单引号正常配对');
check($ap->plan('"', 'x', '"') === AutoPair::SKIP, '右引号已在 → 优先跳过（不被词后规则拦住）');
check($ap->plan('z', '', '') === AutoPair::PLAIN, '普通字符不管');
check((new AutoPair([]))->plan('(', '', '') === AutoPair::PLAIN, '关闭状态下打 ( 不配对');
check($ap->emptyPairAt('(', ')'), '() 空对被识别为可成对删');
check(!$ap->emptyPairAt('(', 'x'), '(x 不是空对');
check(!$ap->emptyPairAt('', ')'), '行首右侧的 ) 不算空对（没有左符号配它）');

// ═══════════ 3) 编辑器里的真实效果 ═══════════
echo "== 编辑器效果（走 App::handle）==\n";

// 打左补右 + 光标夹中间
[$a1, ] = editorWith('');
type($a1, '(', $vp);
check($a1->buffer->lines[0] === '()', '打 ( 自动补 )（实际 ' . var_export($a1->buffer->lines[0], true) . '）');
check($a1->buffer->cursorCol === 1, '光标夹在中间（实际 col=' . $a1->buffer->cursorCol . '）');

// 在 () 中间打 ) → 跳过，不变成 ())
type($a1, ')', $vp);
check($a1->buffer->lines[0] === '()', '在 () 中间打 ) 只跳过、不重复插（实际 ' . var_export($a1->buffer->lines[0], true) . '）');
check($a1->buffer->cursorCol === 2, '跳过之后光标在右符号之后（实际 col=' . $a1->buffer->cursorCol . '）');

// 空对退格一次删两个（光标要停在空对**中间**：打 `([` 后光标夹在 `[` `]` 之间）
[$a2, ] = editorWith('');
type($a2, '([', $vp);
check($a2->buffer->lines[0] === '([])' && $a2->buffer->cursorCol === 2,
    '嵌套配对到位且光标在内层中间（实际 ' . var_export($a2->buffer->lines[0], true)
    . ' col=' . $a2->buffer->cursorCol . '）');
$a2->handle(CodedKeyEvent::new(KeyCode::Backspace, 0), $vp);
check($a2->buffer->lines[0] === '()',
    '空对退格一次删两个（实际 ' . var_export($a2->buffer->lines[0], true) . '）');

// 引号词后不配对
[$a3, ] = editorWith('');
type($a3, 'don', $vp);
type($a3, "'", $vp);
check($a3->buffer->lines[0] === "don'", '词后单引号不配对（实际 ' . var_export($a3->buffer->lines[0], true) . '）');

// 关闭自动配对后一切照旧
file_put_contents($cfg, json_encode(['editor' => ['autoPairs' => []]]));
[$a4, ] = editorWith('');
type($a4, '(', $vp);
check($a4->buffer->lines[0] === '(', '配置关闭后打 ( 不补右（实际 ' . var_export($a4->buffer->lines[0], true) . '）');

// ═══════════ 4) 选中包裹 ═══════════
echo "== 选中包裹 ==\n";

file_put_contents($cfg, json_encode(['editor' => ['autoPairs' => ['()', '[]', '{}', '""', "''"]]]));
[$a5, ] = editorWith('ab');
$ed = $a5->areas($vp)['editor'];
$inner = $ed->inner(new \PhpTui\Tui\Widget\Margin(1, 1));
// 文本起始列 = 内边距 + 行号槽（单行文件 maxLineNoWidth=1 → 槽宽 2）
$textX0 = $inner->position->x + min($inner->width, $a5->buffer->maxLineNoWidth + 1);
$row = $inner->position->y;   // 只有 1 个 buffer → 没有 tab 栏，首行即内容行
$mk = static fn (MouseEventKind $k, int $c): MouseEvent => MouseEvent::new($k, MouseButton::Left, $c, $row, 0);
// 选中 "ab"（两列）
$a5->handle($mk(MouseEventKind::Down, $textX0), $vp);
$a5->handle($mk(MouseEventKind::Drag, $textX0 + 1), $vp);
$a5->handle($mk(MouseEventKind::Up, $textX0 + 1), $vp);
$a5->render($vp);   // 让面板拿到刚注入的选区

type($a5, '(', $vp);
check($a5->buffer->lines[0] === '(ab)',
    '选中 ab 后打 ( → 包成 (ab)（实际 ' . var_export($a5->buffer->lines[0], true) . '）');

// 反面对照：没有选区时打 ( 就是普通配对（上面第 3 节已证），这里验证"选区为空不误包"
[$a6, ] = editorWith('ab');
type($a6, '(', $vp);
check($a6->buffer->lines[0] === '()ab', '没有选区时打 ( → 只是配对，不包裹（实际 ' . var_export($a6->buffer->lines[0], true) . '）');

echo "\n";
if ($failed) {
    echo "RESULT: FAIL\n";
    exit(1);
}
echo "RESULT: PASS（自动配对全部通过）\n";

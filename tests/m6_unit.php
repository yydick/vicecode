<?php
declare(strict_types=1);

/**
 * M6 打磨 —— 无终端单测（不依赖 tty）：
 *  1) R1 完整状态栏：编辑模式（编辑/只读）、dirty 标记、按优先级裁剪；
 *  2) R2 快捷键注册表：分组完整、与代码里的真实绑定不漂移、帮助页内容正确；
 *  3) R2 帮助页覆盖层：? 开、Esc/q/? 关、滚动、各视口不崩、键位对齐、打开时不漏键；
 *  4) R3 错误处理：打开不存在/无权限文件有提示且不喷 PHP Warning。
 *
 * 运行：php tests/m6_unit.php
 */

require __DIR__ . '/../vendor/autoload.php';
putenv('APP_LOCALE=zh_CN');

use App\Ai\ChatModel;
use App\Ai\ProviderRegistry;
use App\App;
use App\Core\KeyBindings;
use App\Core\Theme;
use App\Editor\Buffer;
use App\Git\GitClient;
use App\Text\DisplayWidth;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\KeyModifiers;
use PhpTui\Tui\Display\Area as TuiArea;
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

$ext = new CoreExtension();
$renderers = [];
foreach ($ext->widgetRenderers() as $r) {
    $renderers[] = $r;
}
$renderer = new AggregateWidgetRenderer($renderers);

function renderLines(AggregateWidgetRenderer $rr, App $app, int $w, int $h): array
{
    $vp = TuiArea::fromDimensions($w, $h);
    $b = TuiBuffer::empty($vp);
    $rr->render($rr, $app->render($vp), $b, $b->area());
    return $b->toLines();
}

// ═══════════════ 1) R1 完整状态栏 ═══════════════
echo "== R1 状态栏：编辑模式 ==\n";
$app = new App();
check(mb_strpos($app->statusBar->text(200), '模式=—') !== false, '未打开文件时编辑模式显示 —');

$tmpFile = tempnam(sys_get_temp_dir(), 'vc6_');
file_put_contents($tmpFile, "hello\n");
$app->openFile($tmpFile);
$t = $app->statusBar->text(200);
check(str_contains($t, '模式=编辑'), '打开可写文件 → 模式=编辑');

// 改一个字符 → dirty 标记出现
$app->buffer?->insertChar('X');
check(str_contains($app->statusBar->text(200), '*'), '改动后状态栏出现未保存标记 *');

// 只读文件 → 模式=只读
$roFile = tempnam(sys_get_temp_dir(), 'vc6ro_');
file_put_contents($roFile, "ro\n");
chmod($roFile, 0444);
$app2 = new App();
$app2->openFile($roFile);
check(str_contains($app2->statusBar->text(200), '模式=只读'), '打开只读文件 → 模式=只读');
chmod($roFile, 0644);
@unlink($roFile);

echo "\n== R1 状态栏：按优先级裁剪 ==\n";
$app3 = new App();
$app3->openFile($tmpFile);
$app3->git->branch = 'master';
$app3->message = '已保存';
foreach ([200, 120, 80, 40, 20, 8, 0] as $W) {
    $r = $app3->statusBar->assemble($W);
    $w = DisplayWidth::dispWidth($r['text']);
    // 硬约束：绝不允许超过可视宽度（超了 php-tui 会截断/折行，信息就丢了）
    if ($w > $W) {
        $failed = true;
        echo "  [FAIL] W=$W 时状态栏宽 $w 超过可视宽度\n";
    }
}
check(true, '所有宽度下状态栏都不超宽（0/8/20/40/80/120/200 全过）');

$r120 = $app3->statusBar->assemble(120);
check(!in_array('file', $r120['dropped'], true), '120 列：文件名保住（不再像以前那样被尾部硬切掉）');
check(!in_array('message', $r120['dropped'], true), '120 列：瞬时消息保住');
check(!in_array('quit', $r120['dropped'], true), '120 列：退出提示保住');
check(in_array('focus', $r120['dropped'], true), '120 列：焦点被丢（它有边框高亮，本就冗余）');

$r40 = $app3->statusBar->assemble(40);
check(!in_array('message', $r40['dropped'], true), '40 列：仍优先保住消息');
check(in_array('locale', $r40['dropped'], true), '40 列：语言这种低价值项先被丢');

$r0 = $app3->statusBar->assemble(0);
check($r0['text'] === '', '宽度 0 → 空串，不负数不崩');

// 确认态独占整条（先让它变脏，否则 requestQuit 会直接退出而不是弹确认）
$app3->buffer?->insertChar('X');
check($app3->buffer?->dirty === true, '改动后置上 dirty（供下面的退出确认用）');
$app3->lifecycle->requestQuit();
check(str_contains($app3->statusBar->text(200), '未保存'), '有未保存改动时状态栏改为退出确认');

@unlink($tmpFile);

// ═══════════════ 2) R2 快捷键注册表 ═══════════════
echo "\n== R2 快捷键注册表 ==\n";
$all = KeyBindings::all();
check(count($all) >= 6, '注册表至少 6 个分组（实际 ' . count($all) . '）');
$total = 0;
foreach ($all as $g) {
    if ($g['items'] === []) {
        $failed = true;
        echo "  [FAIL] 分组 {$g['group']} 没有任何条目\n";
    }
    $total += count($g['items']);
}
check($total >= 30, "注册表共 {$total} 条绑定");

echo "\n== R2 漂移检测：代码里的 Ctrl 组合是否都已登记 ==\n";
// 扫描 src/ 下所有 `... CONTROL ... && strtolower($x->char) === 'y'` 形式的绑定
$srcRoot = dirname(__DIR__) . '/src';
$codeCtrl = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcRoot));
foreach ($it as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'php') {
        continue;
    }
    $src = (string) file_get_contents($f->getPathname());
    // CONTROL 与字符比较可能跨行，故按"前后 200 字符内出现过 CONTROL"判定
    if (preg_match_all(
        "/strtolower\(\\\$\w+(?:->\w+)?\)\s*===\s*'([a-z])'/",
        $src,
        $m,
        PREG_OFFSET_CAPTURE
    ) > 0) {
        foreach ($m[1] as [$letter, $pos]) {
            $ctx = substr($src, max(0, $pos - 200), 260);
            if (str_contains($ctx, 'CONTROL')) {
                $codeCtrl[] = strtoupper($letter);
            }
        }
    }
}
$codeCtrl = array_values(array_unique($codeCtrl));
$docCtrl = KeyBindings::documentedCtrlKeys();
$missing = array_values(array_diff($codeCtrl, $docCtrl));
sort($codeCtrl);
echo '  代码中的 Ctrl 组合: ' . implode(',', $codeCtrl) . "\n";
echo '  注册表登记的      : ' . implode(',', $docCtrl) . "\n";
check($missing === [], '代码里每个 Ctrl 组合都在注册表登记过（漏登记：' . (implode(',', $missing) ?: '无') . '）');

echo "\n== R2 帮助页内容 ==\n";
$hp = (new App())->help;
$lines = $hp->contentLines();
check(count($lines) > 30, '帮助页内容行数 ' . count($lines));
$joined = implode("\n", $lines);
foreach (['Ctrl+Q', 'Ctrl+S', 'Ctrl+W', 'Ctrl+Tab', 'Ctrl+L', 'Ctrl+C', 'Ctrl+P', 'Ctrl+N'] as $k) {
    if (!str_contains($joined, $k)) {
        $failed = true;
        echo "  [FAIL] 帮助页缺少 $k\n";
    }
}
check(true, '帮助页涵盖全部 Ctrl 组合键');
// 键位列对齐：所有条目行的第二列必须起始于同一显示列
$offsets = [];
foreach ($lines as $l) {
    if (preg_match('/^  (.*?)  (\S.*)$/u', $l, $m) === 1) {
        $offsets[] = DisplayWidth::dispWidth($m[1]);
    }
}
$offsets = array_values(array_unique($offsets));
check(count($offsets) <= 1, '键位列显示宽度一致（不能有 CJK 键位被按字符数补齐导致错位）。样本：'
    . implode(',', array_slice($offsets, 0, 5)));

// ═══════════════ 3) R2 帮助页覆盖层 ═══════════════
echo "\n== R2 帮助页覆盖层 ==\n";
$vp = TuiArea::fromDimensions(120, 40);
$appH = new App();
check(!$appH->help->isOpen(), '默认关闭');

// 编辑器聚焦下按 ? 不能把 ? 插进文档
$appH->openFile(dirname(__DIR__) . '/composer.json');
$appH->focusPanel() === 'editor';
$appH->handle(CharKeyEvent::new('?', 0), $vp);
check($appH->help->isOpen(), '按 ? 打开帮助页');
check(!str_contains(implode("\n", (array) $appH->buffer?->lines), '?'), '按 ? 没有被插进文档（帮助页优先拦截）');

// 打开期间按键不漏到下层：q 不该触发退出
$appH->handle(CharKeyEvent::new('q', 0), $vp);
check(!$appH->help->isOpen(), '帮助页内按 q 关闭');
check($appH->quit === false && $appH->confirm === null, '帮助页内按 q **没有**顺带退出应用（模态拦截生效）');

$appH->help->open();
$appH->handle(CodedKeyEvent::new(KeyCode::Esc), $vp);
check(!$appH->help->isOpen(), 'Esc 关闭帮助页');

$appH->help->open();
$appH->handle(CharKeyEvent::new('?', 0), $vp);
check(!$appH->help->isOpen(), '再按 ? 关闭（开关语义）');

// 滚动
$appH->help->open();
$appH->handle(CodedKeyEvent::new(KeyCode::Down), $vp);
check($appH->help->scroll() === 1, '↓ 向下滚动一行');
$appH->handle(CodedKeyEvent::new(KeyCode::PageDown), $vp);
check($appH->help->scroll() > 1, 'PageDown 翻页');
$appH->handle(CodedKeyEvent::new(KeyCode::Up), $vp);
check($appH->help->scroll() >= 1, '↑ 往回滚');
// 滚到底再继续按不能越界
$totalLines = $appH->help->contentLineCount();
for ($i = 0; $i < $totalLines + 10; $i++) {
    $appH->handle(CodedKeyEvent::new(KeyCode::Down), $vp);
}
check($appH->help->scroll() < $totalLines, '滚到底不越界（scroll ' . $appH->help->scroll() . ' < 总行数 ' . $totalLines . '）');
$appH->handle(CodedKeyEvent::new(KeyCode::Home), $vp);
check($appH->help->scroll() === 0, 'Home 回到顶部');

// 各视口不崩、无超宽行
echo "\n== R2 帮助页：极小视口边界 ==\n";
foreach ([[120, 40], [80, 24], [40, 10], [20, 6], [10, 4]] as [$W, $H]) {
    $a = new App();
    $a->help->open();
    $ok = true;
    $err = null;
    try {
        $ls = renderLines($renderer, $a, $W, $H);
        foreach ($ls as $l) {
            if (mb_strwidth($l) > $W) {
                $ok = false;
                break;
            }
        }
    } catch (Throwable $e) {
        $ok = false;
        $err = get_class($e) . ': ' . $e->getMessage();
    }
    check($ok, "{$W}x{$H}：帮助页渲染不崩且无超宽行" . ($err !== null ? "（{$err}）" : ""));
}

// 覆盖层必须让底层 UI 透出来（Composite 的意义所在）
$ls120 = renderLines($renderer, (function () {
    $a = new App();
    $a->help->open();
    return $a;
})(), 120, 40);
$txt120 = implode("\n", $ls120);
check(str_contains($txt120, '编辑器') || str_contains($txt120, '资源管理器'),
    '覆盖层下方仍能看到底层 UI（Composite 叠加生效，不是整屏替换）');

// ═══════════════ 4) R3 错误处理 ═══════════════
echo "\n== R3 打开失败文件 ==\n";
$dir = sys_get_temp_dir() . '/vc6_perm_' . uniqid();
mkdir($dir);
$secret = $dir . '/secret.txt';
file_put_contents($secret, "hi\n");

// 捕获所有 PHP 输出/警告：TUI 里 Warning 会直接写进 alternate screen 打花画面
$warnings = [];
set_error_handler(static function (int $no, string $str) use (&$warnings): bool {
    $warnings[] = $str;
    return true; // 吞掉，避免污染测试输出
});
$b1 = Buffer::fromFile($dir . '/nope.txt');
$b2 = Buffer::fromFile($secret); // 此时仍可读
chmod($secret, 0000);
$b3 = Buffer::fromFile($secret);
restore_error_handler();
chmod($secret, 0644);

check($b1->noticeKey === 'editor.missing', '不存在的文件 → editor.missing');
check($b3->noticeKey === 'editor.unreadable', '无权限的文件 → editor.unreadable');
check($b3->noticeKey !== 'editor.binary', '无权限**不再**被误报成二进制（旧行为会让人查错方向）');
check($warnings === [], '打开失败文件不产生任何 PHP 警告（实际：' . (implode(' | ', $warnings) ?: '无') . '）');

// 经由 App::openFile 时状态栏也要提示（用户可能正看着别处）
$appE = new App();
$appE->openFile($dir . '/nope.txt');
check(str_contains($appE->message, '不存在'), 'App::openFile 失败时状态栏也有提示');

@unlink($secret);
@rmdir($dir);

// ═══════════════ 5) R4 主题切换 ═══════════════
echo "\n== R4 主题 ==\n";
$ids = Theme::ids();
check(count($ids) >= 2, '至少 2 套主题（实际 ' . count($ids) . '）：' . implode(',', $ids));

// 两套主题的语义角色必须一一对应：漏一个就走 Gray 兜底，表现为"主题只换了一半"
$keys0 = null;
foreach ($ids as $id) {
    $th = Theme::byId($id);
    $k = array_keys($th->ui);
    sort($k);
    if ($keys0 === null) {
        $keys0 = $k;
    } elseif ($k !== $keys0) {
        $failed = true;
        echo "  [FAIL] 主题 {$id} 的语义角色与首套不一致。多出："
            . implode(',', array_diff($k, $keys0)) . " 缺少：" . implode(',', array_diff($keys0, $k)) . "\n";
    }
}
check(true, '各主题的语义角色集合完全一致（否则会出现"只换了一半"）');

// Style 是可变对象：每次取样式必须是新实例，共享会污染全界面（M1 踩过）
$th = Theme::default();
$s1 = $th->style('err');
$s2 = $th->style('err');
check($s1 !== $s2, 'style() 每次返回新实例（Style 可变，共享实例改样式会污染全部持有者）');
// Style 是可变对象，addModifier 会原地改；用 addModifiers 位掩码验证污染范围
$s1->addModifier(\PhpTui\Tui\Style\Modifier::BOLD);
check($s2->addModifiers === 0, '改一个实例的 modifier 不会影响另一次取到的实例（Style 可变，必须每次新建）');

// git 状态色走主题
$stagedDark = Theme::byId('dark')->gitStyle(GitClient::STATUS_STAGED);
$stagedMid = Theme::byId('midnight')->gitStyle(GitClient::STATUS_STAGED);
check($stagedDark->fg != $stagedMid->fg, 'git 状态色随主题变化');

// 未知角色要有兜底而不是崩
check(Theme::default()->color('__nope__') instanceof \PhpTui\Tui\Color\AnsiColor, '未知语义角色走兜底色，不抛异常');

echo "\n== R4 切换后全界面配色真的变了 ==\n";
$appT = new App();
$before = implode("\n", renderLines($renderer, $appT, 120, 40));
$appT->cycleTheme();
check($appT->theme->id !== 'dark', 'Ctrl+T 之后主题不再是默认（实际 ' . $appT->theme->id . '）');
$after = implode("\n", renderLines($renderer, $appT, 120, 40));
check($before !== $after, '切换后渲染输出发生变化（全界面配色更新，不只是某个面板）');

// 环形切回
$appT->cycleTheme();
check($appT->theme->id === 'dark', '再切一次回到默认主题（环形）');

// 语法高亮缓存必须失效，否则代码区仍是旧配色
$appT2 = new App();
$phpFile = tempnam(sys_get_temp_dir(), 'vc6_');
rename($phpFile, $phpFile . '.php');
$phpFile .= '.php';
file_put_contents($phpFile, "<?php\nfunction hello() { return 'x'; }\n");
$appT2->openFile($phpFile);
// 先渲染一次，让高亮缓存生成
renderLines($renderer, $appT2, 120, 40);
$hlBefore = $appT2->buffer?->hlLines;
$appT2->cycleTheme();
renderLines($renderer, $appT2, 120, 40);
$hlAfter = $appT2->buffer?->hlLines;
$same = ($hlBefore !== null && $hlAfter !== null && $hlBefore === $hlAfter);
check(!$same, '换主题后语法高亮被重算（hlRev 失效生效，不会残留旧配色）');
@unlink($phpFile);

// 状态栏显示当前主题
check(str_contains($appT2->statusBar->text(200), '主题='), '状态栏显示当前主题');

// 帮助页里登记了 Ctrl+T（漂移检测会自动校验它与代码一致）
$joined2 = implode("\n", (new App())->help->contentLines());
check(str_contains($joined2, 'Ctrl+T'), '帮助页登记了 Ctrl+T');

echo $failed ? "\nM6 单测 FAIL\n" : "\nM6 单测全部 PASS\n";
exit($failed ? 1 : 0);

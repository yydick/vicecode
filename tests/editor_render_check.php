<?php
declare(strict_types=1);

/**
 * 编辑区渲染保真测试：屏幕上编辑区实际显示的文本与行号，必须严格等于文件的真实内容。
 *
 * 守住三个曾经真实发生过的 bug：
 *  1) Highlighter::parseHtml 用 strncmp($html, '<span class="hljs-', 18) 比较——缺偏移参数，
 *     比的永远是字符串开头而非当前位置 $i，导致除首个标签外所有 hljs span 被当纯文本吞进 buf：
 *     标签碎片泄漏进编辑区、产生无效 UTF-8、高亮行数与文件行数对不上。
 *  2) App 自维护 Unicode 宽度区间表，与 php-tui 的 mb_strwidth 算法漂移（漏了韩文音节
 *     U+AC00–D7A3 等），算出的行宽偏小 → 行溢出 1 列。
 *  3) clipLineSpans 裁剪时只比较字素「起始列」，宽字符（占 2 列）在边界处溢出 1 列。
 *     行一旦超宽，php-tui 的 LineTruncator 会把它**折成两行**（名虽为 Truncator），
 *     后续所有行整体下移、行号错位——即用户报的「幽灵行」。
 *
 * 断言：高亮行数守恒 / 屏幕行号严格连续（折行的直接症状）/ 屏幕文本等于文件内容。
 * 终端模型用 TermSimBackend（模拟宽字符占 2 列），避免 DummyBackend 逐格模型的假阳性。
 *
 * 运行：php tests/editor_render_check.php
 */

require __DIR__ . '/../vendor/autoload.php';

putenv('APP_LOCALE=zh_CN');

use App\App;
use App\Editor\Highlighter;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyModifiers;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Display\Backend;
use PhpTui\Tui\Display\BufferUpdates;
use PhpTui\Tui\Display\ClearType;
use PhpTui\Tui\DisplayBuilder;
use PhpTui\Tui\Position\Position;
use PhpTui\Tui\Widget\Margin;

/** 忠实模拟真实终端：写宽字符会一并覆盖右邻格 */
final class TermSimBackend implements Backend
{
    /** @var array<int,array<int,string>> */
    private array $grid = [];

    public function __construct(private int $width, private int $height)
    {
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $this->grid[$y][$x] = ' ';
            }
        }
    }

    public function draw(BufferUpdates $updates): void
    {
        foreach ($updates as $u) {
            $x = $u->position->x;
            $y = $u->position->y;
            if (!isset($this->grid[$y][$x])) {
                continue;
            }
            $ch = $u->cell->char;
            $this->grid[$y][$x] = $ch;
            if (mb_strwidth($ch) >= 2) {
                $this->grid[$y][$x + 1] = ' ';
            }
        }
    }

    public function flush(): void
    {
    }

    public function size(): Area
    {
        return Area::fromScalars(0, 0, $this->width, $this->height);
    }

    public function clearRegion(ClearType $type): void
    {
        for ($y = 0; $y < $this->height; $y++) {
            for ($x = 0; $x < $this->width; $x++) {
                $this->grid[$y][$x] = ' ';
            }
        }
    }

    public function cursorPosition(): Position
    {
        return new Position(0, 0);
    }

    public function appendLines(int $linesAfterCursor): void
    {
    }

    public function moveCursor(Position $position): void
    {
    }

    public function rowSlice(int $y, int $x0, int $x1): string
    {
        return implode('', array_slice($this->grid[$y] ?? [], $x0, max(0, $x1 - $x0)));
    }
}

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

/** 把文本展开成终端显示形式：每个宽字符占 2 列，其后补 1 个空白列 */
function expandWide(string $s): string
{
    $out = '';
    foreach (mb_str_split($s) as $g) {
        $out .= $g;
        if (mb_strwidth($g) >= 2) {
            $out .= ' ';
        }
    }
    return $out;
}

/** 从字符偏移 $off 起按显示列宽截取最多 $disp 列（不劈开 CJK） */
function mbCutDispSubstr(string $s, int $off, int $disp): string
{
    if ($disp <= 0) {
        return '';
    }
    $w = 0;
    $out = '';
    foreach (mb_str_split(mb_substr($s, $off)) as $g) {
        $cw = mb_strwidth($g);
        if ($w + $cw > $disp) {
            break;
        }
        $out .= $g;
        $w += $cw;
    }
    return $out;
}

function runCase(string $path, int $W, int $H): void
{
    $vp = Area::fromScalars(0, 0, $W, $H);
    $backend = new TermSimBackend($W, $H);
    $display = DisplayBuilder::default($backend)->fixed(0, 0, $W, $H)->build();
    $app = new App();
    $app->openFile($path);
    $display->draw($app->render($vp));

    $buf = $app->buffer;
    if ($buf === null) {
        check(false, "打开 $path 失败");
        return;
    }

    $tag = sprintf('%dx%d', $W, $H);

    // 1) 高亮行数必须与文件行数一致（曾 331 vs 494）
    if (Highlighter::langFor($path) !== null) {
        $hl = $buf->hlLines;
        check($hl !== null && count($hl) === count($buf->lines),
            sprintf('%s 高亮行数 %d == 文件行数 %d', $tag, $hl === null ? -1 : count($hl), count($buf->lines)));
    }

    $a = $app->areas($vp);
    $inner = $a['editor']->inner(new Margin(1, 1));
    $gutterW = min($inner->width, $buf->maxLineNoWidth + 1);
    $textW = max(0, $inner->width - $gutterW);
    $rows = max(0, $inner->height);

    $badNo = 0;
    $badText = 0;
    $firstBad = '';
    for ($i = 0; $i < $rows; $i++) {
        $li = $buf->scrollTop + $i;
        if ($li >= count($buf->lines)) {
            break;
        }
        $y = $inner->position->y + $i;

        // 2) 行号栏必须严格连续：一旦有行被折行，后续行号就会错位
        $gutter = trim($backend->rowSlice($y, $inner->position->x, $inner->position->x + $gutterW));
        if ((string) ($li + 1) !== $gutter) {
            $badNo++;
            if ($firstBad === '') {
                $firstBad = sprintf('屏幕第 %d 行行号=%s 应为=%d', $i + 1, var_export($gutter, true), $li + 1);
            }
        }

        // 3) 去掉行号栏后，屏幕文本必须等于该行的可见前缀
        $screen = rtrim($backend->rowSlice($y, $inner->position->x + $gutterW, $inner->position->x + $inner->width));
        $expect = rtrim(expandWide(mbCutDispSubstr($buf->lines[$li], $buf->scrollLeft, $textW)));
        if ($screen !== $expect) {
            $badText++;
            if ($firstBad === '') {
                $firstBad = sprintf('文件第 %d 行 屏幕=%s 期望=%s', $li + 1, var_export($screen, true), var_export($expect, true));
            }
        }
    }
    check($badNo === 0, sprintf('%s 屏幕行号连续无折行（%d 行）%s', $tag, min($rows, count($buf->lines)), $badNo ? '（首个异常：' . $firstBad . '）' : ''));
    check($badText === 0, sprintf('%s 屏幕文本与文件内容一致（%d 行）%s', $tag, min($rows, count($buf->lines)), $badText ? '（首个异常：' . $firstBad . '）' : ''));

    // 4) 编辑并滚动到中段后再渲染，确认仍保真（高亮重算 + 滚动曾在这里露馅）
    for ($i = 0; $i < 33; $i++) {
        $app->handle(CodedKeyEvent::new(KeyCode::Down), $vp);
    }
    $app->handle(CodedKeyEvent::new(KeyCode::Home), $vp);
    foreach (['Z', 'Z', 'Z'] as $ch) {
        $app->handle(CharKeyEvent::new($ch, KeyModifiers::NONE), $vp);
    }
    $display->draw($app->render($vp));

    if (Highlighter::langFor($path) !== null) {
        check(count($buf->hlLines ?? []) === count($buf->lines),
            sprintf('%s 编辑后高亮行数 %d == 文件行数 %d', $tag, count($buf->hlLines ?? []), count($buf->lines)));
    }
    $badNo2 = 0;
    $firstBad2 = '';
    for ($i = 0; $i < $rows; $i++) {
        $li = $buf->scrollTop + $i;
        if ($li >= count($buf->lines)) {
            break;
        }
        $gutter = trim($backend->rowSlice($inner->position->y + $i, $inner->position->x, $inner->position->x + $gutterW));
        if ((string) ($li + 1) !== $gutter) {
            $badNo2++;
            if ($firstBad2 === '') {
                $firstBad2 = sprintf('屏幕第 %d 行行号=%s 应为=%d', $i + 1, var_export($gutter, true), $li + 1);
            }
        }
    }
    check($badNo2 === 0, sprintf('%s 编辑+滚动后行号仍连续%s', $tag, $badNo2 ? '（首个异常：' . $firstBad2 . '）' : ''));
}

$files = [
    __DIR__ . '/../src/App.php',                    // PHP + 大量中文注释
    __DIR__ . '/../src/Editor/Highlighter.php',     // PHP + 中文
    __DIR__ . '/../plan/MILESTONES.md',             // 中文 markdown（含 emoji）
    __DIR__ . '/../config/locales/zh_CN.php',       // 纯中文
    __DIR__ . '/../config/locales/en.php',          // 英文
];

// 覆盖：标准视口 + 边界小视口（面板变窄更容易触发宽度溢出）
$viewports = [[120, 40], [100, 30], [80, 24]];

foreach ($files as $path) {
    if (!is_file($path)) {
        continue;
    }
    echo '== ' . basename($path) . " ==\n";
    foreach ($viewports as [$W, $H]) {
        runCase($path, $W, $H);
    }
    echo "\n";
}

echo $failed ? "结论：存在渲染保真问题\n" : "结论：编辑区渲染保真（无折行、无错位、无标签泄漏）\n";
exit($failed ? 1 : 0);

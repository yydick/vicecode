<?php
declare(strict_types=1);

/**
 * 差分渲染不变量测试（忠实终端模型版）。
 *
 * 为什么不能用 DummyBackend：它逐格写入、不模拟「宽字符占 2 列」，
 * 会误报残留。真实终端写一个 CJK 字符会同时覆盖它右边的格子，
 * 所以 php-tui 的 diff 跳过「宽字符占位格」在真实终端上是正确的。
 *
 * TermSimBackend 忠实模拟这一点：写宽字符时把右邻格一并覆盖为空白。
 * 于是「持久 backend 的累积网格」若与「全新 backend 的全量渲染」不一致，
 * 就一定是真残留（幽灵字符）。
 *
 * 运行：php tests/diff_invariant2.php
 */

require __DIR__ . '/../vendor/autoload.php';

putenv('APP_LOCALE=zh_CN');

// 隔离插件：clock 等插件让状态栏时钟每秒变化，会使 diff 不变量对比出现非确定残留。
// 测试期间临时移走 plugins 目录，结束时（含异常/exit）经 shutdown 函数恢复。
$pluginsDir = __DIR__ . '/../plugins';
$pluginsBackup = $pluginsDir . '.disabled_for_test';
if (is_dir($pluginsDir)) {
    rename($pluginsDir, $pluginsBackup);
    register_shutdown_function(static function () use ($pluginsDir, $pluginsBackup): void {
        if (is_dir($pluginsBackup)) {
            rename($pluginsBackup, $pluginsDir);
        }
    });
}

use App\App;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\KeyModifiers;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Display\Backend;
use PhpTui\Tui\Display\BufferUpdates;
use PhpTui\Tui\Display\ClearType;
use PhpTui\Tui\DisplayBuilder;
use PhpTui\Tui\Position\Position;
use PhpTui\Tui\Widget\Widget;

/** 忠实模拟真实终端：写宽字符会覆盖右邻格 */
final class TermSimBackend implements Backend
{
    /** @var array<int,array<int,string>> */
    private array $grid;

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
            // 宽字符占 2 列：右邻格被它的右半覆盖，视觉上是空白
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

    /** @return string[] */
    public function toLines(): array
    {
        return array_map(static fn (array $row): string => implode('', $row), $this->grid);
    }
}

$W = 120;
$H = 40;
$vp = Area::fromScalars(0, 0, $W, $H);

// 接收「已渲染出的 Widget」：persist 与 fullRender 各自独立调用 $app->render()，
// 贴近真实环境每帧重建 Widget 的语义。时钟等非确定源由测试开头临时移走 plugins 消除。
function fullRender(Widget $widget, int $W, int $H): array
{
    $b = new TermSimBackend($W, $H);
    $d = DisplayBuilder::default($b)->fixed(0, 0, $W, $H)->build();
    $d->draw($widget);
    return $b->toLines();
}

function diffGrids(array $got, array $exp): array
{
    $out = [];
    foreach ($exp as $y => $expLine) {
        $g = mb_str_split($got[$y] ?? '');
        $e = mb_str_split($expLine);
        $n = max(count($g), count($e));
        for ($x = 0; $x < $n; $x++) {
            $gc = $g[$x] ?? ' ';
            $ec = $e[$x] ?? ' ';
            if ($gc !== $ec) {
                $out[] = ['y' => $y, 'x' => $x, 'got' => $gc, 'exp' => $ec];
            }
        }
    }
    return $out;
}

$cases = [
    'ascii' => fn (int $n): array => array_map(fn ($i) => sprintf('line %02d aaaaaaaaaaaaaaaaaaaaaaaaaaaa', $i), range(1, $n)),
    'cjk' => fn (int $n): array => array_map(fn ($i) => sprintf('第 %02d 行 中文内容中文内容中文内容', $i), range(1, $n)),
    'mix' => fn (int $n): array => array_map(fn ($i) => sprintf('第 %02d 行 mixed 中英混合 code%d(); // 注释', $i, $i), range(1, $n)),
];

$totalFailed = false;
$realFile = __DIR__ . '/../plan/MILESTONES.md';

foreach (['ascii', 'cjk', 'mix'] as $name) {
    echo "== 用例：$name ==\n";
    $file = sys_get_temp_dir() . "/di2_{$name}_" . getmypid() . ".txt";
    $gen = $cases[$name];
    file_put_contents($file, implode("\n", $gen(60)) . "\n");
    runCase($file, $W, $H, $vp, $totalFailed);
    unlink($file);
    echo "\n";
}

echo "== 用例：真实文件 plan/MILESTONES.md（含中文 + markdown 高亮）==\n";
runCase($realFile, $W, $H, $vp, $totalFailed);
echo "\n";

function runCase(string $file, int $W, int $H, Area $vp, bool &$totalFailed): void
{
    $persist = new TermSimBackend($W, $H);
    $display = DisplayBuilder::default($persist)->fixed(0, 0, $W, $H)->build();
    $app = new App();
    $app->openFile($file);

    $step = function (?object $e, string $label) use ($app, $vp, $display, $persist, $W, $H, &$totalFailed): void {
        if ($e !== null) {
            $app->handle($e, $vp);
        }
        $display->draw($app->render($vp));
        $d = diffGrids($persist->toLines(), fullRender($app->render($vp), $W, $H));
        if ($d === []) {
            echo "  [OK] $label\n";
            return;
        }
        $totalFailed = true;
        echo "  [FAIL] $label —— 真残留 " . count($d) . " 格，前 6 个：\n";
        foreach (array_slice($d, 0, 6) as $c) {
            printf("        y=%d x=%d 残留=%s 应为=%s\n", $c['y'], $c['x'], var_export($c['got'], true), var_export($c['exp'], true));
        }
    };

    $step(null, '首帧');
    for ($i = 0; $i < 33; $i++) {
        $app->handle(CodedKeyEvent::new(KeyCode::Down), $vp);
    }
    $app->handle(CodedKeyEvent::new(KeyCode::Home), $vp);
    $step(null, '下移到第 34 行行首');
    foreach (['Z', 'Z', 'Z'] as $i => $ch) {
        $step(CharKeyEvent::new($ch, KeyModifiers::NONE), '输入第 ' . ($i + 1) . ' 个 Z');
    }
    for ($i = 0; $i < 33; $i++) {
        $app->handle(CodedKeyEvent::new(KeyCode::Up), $vp);
    }
    $step(null, '回到第 1 行');
    $app->handle(CodedKeyEvent::new(KeyCode::End), $vp);
    $step(null, 'End（水平滚动）');
    $step(CharKeyEvent::new('Q', KeyModifiers::NONE), '行尾输入 Q');
    $app->handle(CodedKeyEvent::new(KeyCode::Home), $vp);
    $step(null, 'Home（滚回左端）');
}

echo $totalFailed ? "结论：仍存在真残留\n" : "结论：无真残留（phptui 的 diff 在忠实终端模型下是正确的）\n";
exit($totalFailed ? 1 : 0);

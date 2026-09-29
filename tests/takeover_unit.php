<?php
declare(strict_types=1);
/**
 * 终端独占（Takeover）单测（无 TTY 也能跑）：覆盖 TerminalPanel 的接管支撑方法
 * 与「PTY 死亡自动退出独占」状态机。真终端下的端到端保真度（F11 切换、vim 可用）
 * 需在真实 pty 里手动验收（见 tests/pty_run.php）。
 */
require __DIR__ . '/../vendor/autoload.php';

use App\App;
use PhpTui\Tui\Display\Area;

$pass = 0; $fail = 0;
function check(string $name, bool $cond): void
{
    global $pass, $fail;
    if ($cond) {
        echo "  [OK] $name\n";
        $pass++;
    } else {
        echo "  [FAIL] $name\n";
        $fail++;
    }
}

/** 读私有属性（单测观察面，避免为测试加 public 访问器） */
function readPrivate(object $obj, string $prop)
{
    $r = new ReflectionProperty($obj, $prop);
    $r->setAccessible(true);
    return $r->getValue($obj);
}

$app = new App();
$app->focus('terminal');
$term = $app->terminal;

// 初始 runner 态
check('初始 mode=runner', $term->mode === 'runner');
check('ptyAlive=false (runner)', $term->ptyAlive() === false);

// 进入交互式 PTY（headless 下 proc_open pty 正常起 shell）
$term->toggleInteractive();
usleep(250000);
check('toggleInteractive → mode=pty', $term->mode === 'pty');
check('toggleInteractive → captured', $term->captured === true);
check('ptyAlive=true', $term->ptyAlive() === true);

// resizeToViewport：拉到整帧尺寸
$term->resizeToViewport(120, 40);
check(
    'resizeToViewport 设整帧尺寸 (lastCols/lastRows)',
    readPrivate($term, 'lastCols') === 120 && readPrivate($term, 'lastRows') === 40
);

// resizeToPanel：恢复成面板子矩形（内边距 1 + 提示行 1，右缘 gutter 1）
// 60x20 → 内 58x18 → 去提示行 outH=17 → cw=58-1=57
$panel = Area::fromScalars(0, 0, 60, 20);
$term->resizeToPanel($panel);
check(
    'resizeToPanel 收缩到面板尺寸 (cw=57/outH=17)',
    readPrivate($term, 'lastCols') === 57 && readPrivate($term, 'lastRows') === 17
);

// 窄面板退化：放不下 gutter 时不留滚动条列
$panel2 = Area::fromScalars(0, 0, 4, 4);
$term->resizeToPanel($panel2); // 内 2x2 → outH=1, W=2<3 → cw=2
check(
    'resizeToPanel 窄面板退化无 gutter (cw=2)',
    readPrivate($term, 'lastCols') === 2
);

// takeoverPump：PTY 有输出时不致命，且不改变 terminalTakeover
$app->terminalTakeover = true;
$term->sendToPty("echo takeover_unit\n");
usleep(150000);
$term->takeoverPump();
check('takeoverPump 后 terminalTakeover 仍 true', $app->terminalTakeover === true);

// 让 shell 退出 → 接管应自动退出
$term->sendToPty("exit\n");
for ($i = 0; $i < 50; $i++) {
    $term->poll();
    if ($term->mode === 'runner') {
        break;
    }
    usleep(50000);
}
$term->takeoverPump(); // 主循环里的 death 检测
check('pty 死亡后 terminalTakeover 自动置 false', $app->terminalTakeover === false);
check('pty 死亡后 mode=runner', $term->mode === 'runner');
check('pty 死亡后 ptyAlive=false', $term->ptyAlive() === false);

echo "\nRESULT: " . ($fail === 0 ? 'PASS' : 'FAIL') . " ($pass ok / $fail fail)\n";
exit($fail === 0 ? 0 : 1);

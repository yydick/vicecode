<?php
declare(strict_types=1);
/**
 * KeyToPty 鼠标 → SGR 序列编码单测（Phase B 核心逻辑）。
 * 验证接管期透传给 PTY 的鼠标字节与 xterm ?1006h 格式一致。
 */
require __DIR__ . '/../vendor/autoload.php';

use App\Terminal\KeyToPty;
use PhpTui\Term\Event\MouseEvent;
use PhpTui\Term\KeyModifiers;
use PhpTui\Term\MouseButton;
use PhpTui\Term\MouseEventKind;

$pass = 0; $fail = 0;
function expect(string $name, string $got, string $want): void
{
    global $pass, $fail;
    if ($got === $want) {
        echo "  [OK] $name\n";
        $pass++;
    } else {
        echo "  [FAIL] $name\n    got =" . bin2hex($got) . "\n    want=" . bin2hex($want) . "\n";
        $fail++;
    }
}

// 坐标 (0-based 9,4) → 1-based (10,5)
$me = static fn (MouseEventKind $k, MouseButton $b, int $m = KeyModifiers::NONE)
    => MouseEvent::new($k, $b, 9, 4, $m);

expect('左键按下', KeyToPty::encode($me(MouseEventKind::Down, MouseButton::Left)), "\e[<0;10;5M");
expect('左键松开 (btn+3)', KeyToPty::encode($me(MouseEventKind::Up, MouseButton::Left)), "\e[<3;10;5m");
expect('中键按下', KeyToPty::encode($me(MouseEventKind::Down, MouseButton::Middle)), "\e[<1;10;5M");
expect('右键按下', KeyToPty::encode($me(MouseEventKind::Down, MouseButton::Right)), "\e[<2;10;5M");
expect('右键+Alt 按下 (mods 8)', KeyToPty::encode($me(MouseEventKind::Down, MouseButton::Right, KeyModifiers::ALT)), "\e[<10;10;5M");
expect('左键拖拽 (+32)', KeyToPty::encode($me(MouseEventKind::Drag, MouseButton::Left)), "\e[<32;10;5M");
expect('滚轮上 (64)', KeyToPty::encode($me(MouseEventKind::ScrollUp, MouseButton::None)), "\e[<64;10;5M");
expect('滚轮下 (65)', KeyToPty::encode($me(MouseEventKind::ScrollDown, MouseButton::None)), "\e[<65;10;5M");
expect('Ctrl+左键 (mods 16)', KeyToPty::encode($me(MouseEventKind::Down, MouseButton::Left, KeyModifiers::CONTROL)), "\e[<16;10;5M");
expect('Shift+左键 (mods 4)', KeyToPty::encode($me(MouseEventKind::Down, MouseButton::Left, KeyModifiers::SHIFT)), "\e[<4;10;5M");
expect('移动(无按键, +32)', KeyToPty::encode($me(MouseEventKind::Moved, MouseButton::None)), "\e[<32;10;5M");

// 非鼠标事件不应被鼠标编码器影响（回归保护：F2 由 App 退出捕获，编码返回 null）
expect('F2 仍返回 null', KeyToPty::encode(\PhpTui\Term\Event\FunctionKeyEvent::new(2)) ?? 'NULL', 'NULL');

echo "\nRESULT: " . ($fail === 0 ? 'PASS' : 'FAIL') . " ($pass ok / $fail fail)\n";
exit($fail === 0 ? 0 : 1);

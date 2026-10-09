<?php
declare(strict_types=1);
/**
 * KeyToPty 键盘 → PTY 字节编码单测（退格 / 方向键 / Home/End 回归保护）。
 * 验证发往 PTY 的序列与 shell/readline/vim 在 smkx（应用键模式）下期待的一致。
 */
require __DIR__ . '/../vendor/autoload.php';

use App\Terminal\KeyToPty;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event\FunctionKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\KeyModifiers;

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

// 退格：CodedKeyEvent(Backspace) 与两种 CharKeyEvent 形式都归一到 DEL(\x7f)
expect('Backspace(Coded) → DEL', KeyToPty::encode(CodedKeyEvent::new(KeyCode::Backspace)), "\x7f");
expect('Backspace(BS \\x08) → DEL', KeyToPty::encode(CharKeyEvent::new("\x08")), "\x7f");
expect('Backspace(DEL \\x7f) → DEL', KeyToPty::encode(CharKeyEvent::new("\x7f")), "\x7f");

// 方向键：应用光标键模式 \eO + 字母（readline/vim 在 smkx 后期待此序列，不是裸 \e[A）
expect('Up → \\eOA', KeyToPty::encode(CodedKeyEvent::new(KeyCode::Up)), "\x1bOA");
expect('Down → \\eOB', KeyToPty::encode(CodedKeyEvent::new(KeyCode::Down)), "\x1bOB");
expect('Right → \\eOC', KeyToPty::encode(CodedKeyEvent::new(KeyCode::Right)), "\x1bOC");
expect('Left → \\eOD', KeyToPty::encode(CodedKeyEvent::new(KeyCode::Left)), "\x1bOD");
expect('Ctrl+Up → \\e[1;5A', KeyToPty::encode(CodedKeyEvent::new(KeyCode::Up, KeyModifiers::CONTROL)), "\x1b[1;5A");
expect('Ctrl+Left → \\e[1;5D', KeyToPty::encode(CodedKeyEvent::new(KeyCode::Left, KeyModifiers::CONTROL)), "\x1b[1;5D");

// Home/End：应用键模式 \eOH / \eOF
expect('Home → \\eOH', KeyToPty::encode(CodedKeyEvent::new(KeyCode::Home)), "\x1bOH");
expect('End → \\eOF', KeyToPty::encode(CodedKeyEvent::new(KeyCode::End)), "\x1bOF");
expect('Ctrl+Home → \\e[1;5H', KeyToPty::encode(CodedKeyEvent::new(KeyCode::Home, KeyModifiers::CONTROL)), "\x1b[1;5H");

// 其余常用键不变
expect('Enter → \\r', KeyToPty::encode(CodedKeyEvent::new(KeyCode::Enter)), "\r");
expect('Tab → \\t', KeyToPty::encode(CodedKeyEvent::new(KeyCode::Tab)), "\t");
expect('Delete → \\e[3~', KeyToPty::encode(CodedKeyEvent::new(KeyCode::Delete)), "\x1b[3~");
expect('普通字母原样', KeyToPty::encode(CharKeyEvent::new('a')), 'a');
expect('F2 返回 null（App 退捕获用）', KeyToPty::encode(FunctionKeyEvent::new(2)) ?? 'NULL', 'NULL');

echo "\nRESULT: " . ($fail === 0 ? 'PASS' : 'FAIL') . " ($pass ok / $fail fail)\n";
exit($fail === 0 ? 0 : 1);

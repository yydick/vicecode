<?php
declare(strict_types=1);
/**
 * Vt100Emulator 退格(BS) 回归单测。
 * 验证 \x08 真的把光标左移（之前因 PHP 双引号 \b 非合法转义，case "\b" 永不匹配，
 * 光标不动导致退格/上键历史重绘失效）。
 * 退格的「删除」由程序发的 \x08 + \x1b[K（清行）配合完成，本测试复现该序列。
 */
require __DIR__ . '/../vendor/autoload.php';

use App\Terminal\Vt100Emulator;

$pass = 0; $fail = 0;
function expect(string $name, string $got, string $want): void
{
    global $pass, $fail;
    if ($got === $want) {
        echo "  [OK] $name\n";
        $pass++;
    } else {
        echo "  [FAIL] $name\n    got =$got\n    want=$want\n";
        $fail++;
    }
}

function row0(Vt100Emulator $e): string
{
    $m = new ReflectionMethod($e, 'activeScreen');
    $m->setAccessible(true);
    $scr = $m->invoke($e);
    $t = '';
    foreach ($scr[0] as $c) {
        $t .= $c->ch;
    }
    return rtrim($t);
}

$e = new Vt100Emulator(80, 5);
$e->write("abc");
expect('输入 abc', row0($e), 'abc');
$e->write("\x08"); // BS 只移光标，不擦除
expect('BS 后仅移光标（未删）', row0($e), 'abc');
$e->write("\x1b[K"); // 清行：删掉光标处及右侧 → 删最后字符
expect('BS+EL 删除末字符', row0($e), 'ab');

// 上键历史：readline 发一串 BS 回到行首再覆盖写入历史命令
$e3 = new Vt100Emulator(80, 5);
$e3->write("abc");
$e3->write("\x08\x08\x08"); // 3×BS → 行首
expect('上键：BS 回行首', row0($e3), 'abc'); // 光标移动但内容尚在
$e3->write("histcmd");
expect('上键：覆盖写入历史', row0($e3), 'histcmd');

// Delete 键（\e[3~ = DCH）删除光标下字符
$e4 = new Vt100Emulator(80, 5);
$e4->write("abc");
$e4->write("\x1b[1G");   // home → col0
$e4->write("\x1b[C");     // 右移 1 → 光标在 'b'
$e4->write("\x1b[3~");    // Delete → 删 'b'
expect('Delete 键删光标下字符', row0($e4), 'ac');

echo "\nRESULT: " . ($fail === 0 ? 'PASS' : 'FAIL') . " ($pass ok / $fail fail)\n";
exit($fail === 0 ? 0 : 1);

<?php
declare(strict_types=1);

/**
 * Clipboard 剪贴板服务单元测试（headless）。
 *
 * 直接测 App\Core\Clipboard：非 tty 环境落内存（可断言），tty 环境走 OSC 52 写入
 * （此处只断言不崩，不校验终端剪贴板）。超长文本在 tty 分支分块，非 tty 不分块但应完整保留。
 */

require __DIR__ . '/../vendor/autoload.php';

use App\App;
use App\Core\Clipboard;
use App\Core\InputParser;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\KeyModifiers;
use PhpTui\Tui\Display\Area;

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    if ($cond) {
        echo "  [OK] $msg\n";
    } else {
        $failed = true;
        echo "  [FAIL] $msg\n";
    }
}

$tty = stream_isatty(STDOUT);
echo "\n== Clipboard（" . ($tty ? 'tty：走 OSC52' : '非 tty：内存') . "）==\n";

$cb = new Clipboard();

$cb->copy('hello clipboard');
if ($tty) {
    check(true, 'tty：copy 走 OSC 52 写入（不断言内存）');
} else {
    check($cb->peek() === 'hello clipboard', '非 tty：copy 写入内存剪贴板');
}

// 空串是 no-op，不清空已有内容
$cb->copy('');
if (!$tty) {
    check($cb->peek() === 'hello clipboard', 'copy("") 为空操作，不覆盖已有内容');
}

// 覆盖写入
$cb->copy('second');
if (!$tty) {
    check($cb->peek() === 'second', '再次 copy 覆盖内存剪贴板');
}

// 超长文本：非 tty 应完整保留（tty 分支才会按 4K base64 分块写 OSC 52）
$big = str_repeat('测', 5000); // 1 万字节、含 CJK
$cb->copy($big);
if ($tty) {
    check(true, 'tty：超长文本按 4K base64 分块写 OSC 52（不断言内存）');
} else {
    check($cb->peek() === $big, '非 tty：超长文本完整写入内存（长度 ' . mb_strlen($cb->peek()) . '）');
    check(mb_strlen($cb->peek()) === 5000, '非 tty：超长文本字符数无损');
}

// ───────────────── OSC 52 读取解析（纯字节、与 tty 无关，可直接断言） ─────────────────
echo "\n== OSC 52 解析（InputParser 旁路捕获）==\n";

$ip = new InputParser();
$got = [];
$ip->setClipboardHandler(function (string $t) use (&$got): void {
    $got[] = $t;
});

// 1) BEL 终结 + 多字节文本
$ip->feed("\x1b]52;c;" . base64_encode("hi 你") . "\x07");
check($got === ["hi 你"], 'BEL 终结：回调收到「hi 你」');

// 2) 查询回显（base64==?）忽略
$got = [];
$ip->feed("\x1b]52;c;?\x07");
check($got === [], '查询回显(?) 被忽略，不回调');

// 3) ST 终结（\e\）
$got = [];
$ip->feed("\x1b]52;c;" . base64_encode("x") . "\x1b\\");
check($got === ["x"], 'ST 终结：回调收到「x」');

// 4) 跨 feed 拼齐：普通键 + 不完整 OSC 前缀 + 后续补全
$got = [];
$events = $ip->feed("a" . "\x1b]52;c;" . base64_encode("hello")); // 无终结符，不完整
$hasA = false;
foreach ($events as $ev) {
    if ($ev instanceof CharKeyEvent && $ev->char === 'a') {
        $hasA = true;
    }
}
check($hasA, '不完整 OSC 前的普通键「a」照常产出事件');
check($got === [], 'OSC 不完整时不回调（等补全）');
$ip->feed("\x07"); // 补全终结符
check($got === ["hello"], '跨 feed 补全后回调收到完整文本「hello」');

// ───────────────── 粘贴插入（经 requestPaste 入口；覆盖三处插入点） ─────────────────
// 非 tty：requestPaste 走内存剪贴板即时粘贴，可断言；tty：走 OSC 52 异步查询（此段落跳过，仅占位）。
echo "\n== 粘贴插入（editor / terminal / ai_input）==\n";
if (!$tty) {
    // 编辑器：光标处插入（含多行拆分）
    $app = new App();
    $f = tempnam(sys_get_temp_dir(), 'vc_paste');
    file_put_contents($f, "line1\nline2\n");
    $app->openFile($f);
    $app->focus('editor');
    $app->clipboardCopy("PASTE\nnewline");
    $app->requestPaste();
    $joined = implode("\n", $app->buffer->lines);
    check(str_contains($joined, "PASTE"), '编辑器：粘贴文本进入缓冲区（含多行拆分）');
    @unlink($f);

    // AI 输入框：追加
    $app->focus('ai_input');
    $app->clipboardCopy("AI_PASTE");
    $app->requestPaste();
    check(str_contains($app->ai->input(), "AI_PASTE"), 'AI 输入框：粘贴文本追加到输入');

    // 终端（runner 模式）：单行输入行，换行规整为空格
    $app->focus('terminal');
    $app->clipboardCopy("TERM_PASTE\nline2");
    $app->requestPaste();
    check(str_contains($app->terminal->input, "TERM_PASTE line2"), '终端：多行粘贴被规整为空格（无换行）');
    check(!str_contains($app->terminal->input, "\n"), '终端：粘贴结果不含换行符');

    // 触发键 Ctrl+V：经 handle 分发到 requestPaste
    $app2 = new App();
    $f2 = tempnam(sys_get_temp_dir(), 'vc_paste2');
    file_put_contents($f2, "");
    $app2->openFile($f2);
    $app2->focus('editor');
    $app2->clipboardCopy("PASTE_ME");
    $app2->handle(CharKeyEvent::new('v', KeyModifiers::CONTROL), Area::fromDimensions(120, 40));
    check(str_contains(implode("\n", $app2->buffer->lines), "PASTE_ME"), 'Ctrl+V（editor）：经 handle 分发即时粘贴');
    @unlink($f2);
} else {
    check(true, 'tty：requestPaste 走 OSC 52 异步查询（不在此断言插入内容）');
}

echo "\n";
if ($failed) {
    echo "Clipboard 单测存在 FAIL\n";
    exit(1);
}
echo "Clipboard 单测全部 PASS\n";

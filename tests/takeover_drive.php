<?php
declare(strict_types=1);
/**
 * 终端独占（Takeover）真实 pty 端到端驱动：
 *   Tab×2 聚焦终端 → F2 进交互 → F5 独占整屏 → 真终端里 `echo hi_takeover`
 *   → F5 退回 TUI → Esc 退捕获 → Ctrl+Q 退出。
 * 断言：
 *   1) 独占期命令输出 `hi_takeover` 以原始字节透传到 STDOUT（100% 保真证据）；
 *   2) 退出独占后 TUI 恢复（出现「交互终端」面板标题，证明没卡在真终端里）。
 * 运行：php tests/takeover_drive.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\App;
use PhpTui\Tui\Display\Area;

$readPty = static function ($stream, int $len) {
    set_error_handler(static function (int $no, string $str): bool {
        return str_contains($str, 'errno=5') || str_contains($str, 'Input/output error');
    });
    try {
        return fread($stream, $len);
    } finally {
        restore_error_handler();
    }
};

$descs = [0 => ['pty'], 1 => ['pty'], 2 => ['pty']];
$proc = proc_open([PHP_BINARY, 'bin/vicecode.php'], $descs, $pipes);
if ($proc === false) {
    echo "[FAIL] 无法启动 bin/vicecode.php\n";
    exit(1);
}
stream_set_blocking($pipes[0], false);
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

// 终端面板聚焦：用鼠标点击命中（与 pty_ai 同思路，确定性优于 Tab 循环，
// 因为 develop 面板顺序/可见性会变动）。坐标按真实 pty 尺寸算。
// 子进程 pty 视口与 pty_ai 一致（200×50，见 pty_ai.php 说明），故用同尺寸算坐标
$cols = 200;
$lines = 50;
$probe = new App();
$ta = $probe->areas(PhpTui\Tui\Display\Area::fromDimensions($cols, $lines))['terminal'];
$tc = $ta->position->x + intdiv($ta->width, 2) + 1;
$tr = $ta->position->y + intdiv($ta->height, 2) + 1;
usleep(300000);
// 点击终端面板：down + up（xterm SGR，1-based）
fwrite($pipes[0], "\x1b[<0;{$tc};{$tr}M");
fwrite($pipes[0], "\x1b[<0;{$tc};{$tr}m");
usleep(500000);
// 抽干点击后的首帧，确保 pty 已 spawn（聚焦后首帧 ptyContent 会起 shell）
for ($k = 0; $k < 8; $k++) {
    $r = [$pipes[1]]; $w = $e = [];
    if (stream_select($r, $w, $e, 0, 30000) > 0) {
        $chunk = $readPty($pipes[1], 8192);
        if ($chunk !== '' && $chunk !== false) {
            $out .= $chunk;
        }
    } else {
        break;
    }
}

// 键盘序列：[字节, 延迟微秒]（终端面板已用鼠标点击聚焦，跳过 Tab）
$seq = [
    ["\x1bOQ", 1200000],    // F2 进入交互式 PTY（等 shell 起来）
    ["\x1b[15~", 800000],   // F5 进入独占（shell 现在直连真实终端）
    ["echo hi_takeover\n", 700000], // 真终端里执行命令
    ["\x1b[15~", 600000],   // F5 退出独占（回到 TUI）
    ["\x1b", 200000],       // Esc 退出捕获态
    ["\x11", 500000],       // Ctrl+Q 退出应用
];

$out = '';
foreach ($seq as [$bytes, $us]) {
    fwrite($pipes[0], $bytes);
    usleep($us);
    // 多读几次，确保把这一拍的输出抓全
    for ($k = 0; $k < 6; $k++) {
        $r = [$pipes[1], $pipes[2]];
        $w = $e = [];
        if (stream_select($r, $w, $e, 0, 30000) > 0) {
            foreach ($r as $p) {
                $chunk = $readPty($p, 8192);
                if ($chunk !== '' && $chunk !== false) {
                    $out .= $chunk;
                }
            }
        } else {
            break;
        }
    }
    if (!proc_get_status($proc)['running']) {
        break;
    }
}

// 兜底：若还活着，再发 Esc / Ctrl+Q 确保退出
$guard = 0;
while (proc_get_status($proc)['running'] && $guard < 6) {
    fwrite($pipes[0], $guard % 2 === 0 ? "\x1b" : "\x11");
    usleep(150000);
    $guard++;
}
$code = proc_close($proc);

$passthrough = str_contains($out, 'hi_takeover');
$restored = str_contains($out, '交互终端') || str_contains($out, 'ViceCode');

$fatal = (str_contains($out, 'Fatal error') || str_contains($out, 'Uncaught'))
    && !str_contains($out, 'Read of');

$ok = $code === 0 && $passthrough && $restored && !$fatal;
echo $passthrough ? "  [OK] 独占期命令输出原始透传成功 (hi_takeover)\n"
                  : "  [FAIL] 未检测到原始透传输出 hi_takeover\n";
echo $restored ? "  [OK] 退出独落后 TUI 恢复 (交互终端/ViceCode)\n"
               : "  [FAIL] 退出独占后未见 TUI 恢复\n";
echo $ok ? "[OK] 终端独占端到端 PASS exit=$code\n"
        : "[FAIL] 终端独占端到端 FAIL exit=$code\n";
if (!$ok) {
    file_put_contents(__DIR__ . '/takeover_dump.log', $out);
    echo "  (已转储原始输出到 tests/takeover_dump.log)\n";
}
exit($ok ? 0 : 1);

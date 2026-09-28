<?php
declare(strict_types=1);

/**
 * 演示：存活标记的提示**实际长什么样**，以及它是不是真的留在普通屏上。
 *
 * 做法：在 pty 里先让 bash 打一行假提示符、再 exec 应用；退出后按真实终端语义
 * 把普通屏内容拼出来 —— `?1049h`（进备用屏）之前 + 最后一次 `?1049l`（离开）之后，
 * 中间那段是备用屏（退出时被真实终端丢弃、不影响普通屏）。
 *
 * 同时跑一次**没有标记**的对照：提示必须不出现。
 */

chdir('/home/yydick/works/tui');
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/isolation.php';
require __DIR__ . '/lib/pty_screen.php';

const W = 100;
const H = 30;

function runOnce(?int $stalePid, string $locale): array
{
    $dir = vc_tmp_dir('vc_demo_stale');
    $cfg = $dir . '/.vicerc';
    if ($stalePid !== null) {
        file_put_contents(
            $dir . '/.vicecode_alive',
            json_encode(['since' => '2026-01-01T08:30:00+00:00', 'pid' => $stalePid], JSON_UNESCAPED_SLASHES) . "\n"
        );
    }

    // 先打一行假提示符（证明提示是「跟在用户 shell 提示符后面」的），再 exec 应用
    $script = 'printf "yydick@dick:~/works/tui$ php bin/vicecode.php\\n"; exec php bin/vicecode.php';
    $descs = [0 => ['pty'], 1 => ['pty'], 2 => ['pty']];
    $proc = proc_open(
        ['bash', '-c', $script],
        $descs,
        $pipes,
        getcwd(),
        array_merge(getenv(), [
            'VICECODE_CONFIG' => $cfg,
            'APP_LOCALE' => $locale,
            'COLUMNS' => (string) W,
            'LINES' => (string) H,
        ])
    );

    $read = static function ($s, int $n): string {
        set_error_handler(static fn(): bool => true);
        try {
            return (string) fread($s, $n);
        } finally {
            restore_error_handler();
        }
    };

    // 等应用进入备用屏
    $out = '';
    $end = microtime(true) + 10;
    while (microtime(true) < $end && !str_contains($out, "\e[?1049h")) {
        $r = [$pipes[1]];
        $w = $e = [];
        if (stream_select($r, $w, $e, 0, 200000) > 0) {
            $c = $read($pipes[1], 65536);
            if ($c === '') {
                break;
            }
            $out .= $c;
        }
    }
    usleep(900_000);

    fwrite($pipes[0], "\x11");   // Ctrl+Q 正常退出
    $end = microtime(true) + 8;
    while (microtime(true) < $end) {
        $r = [$pipes[1]];
        $w = $e = [];
        if (stream_select($r, $w, $e, 0, 200000) > 0) {
            $c = $read($pipes[1], 65536);
            if ($c === '') {
                break;
            }
            $out .= $c;
        }
        if (!proc_get_status($proc)['running']) {
            break;
        }
    }
    if (proc_get_status($proc)['running']) {
        proc_terminate($proc, SIGKILL);
    }
    proc_close($proc);

    // 普通屏 = 首个 ?1049h 之前 + 最后一次 ?1049l 之后（中间是备用屏）
    $enter = strpos($out, "\e[?1049h");
    $leave = strrpos($out, "\e[?1049l");
    $normal = $enter === false
        ? $out
        : substr($out, 0, $enter) . ($leave === false ? '' : substr($out, $leave + 8));

    return [
        'normalBytes' => $normal,
        'screen' => rtrim(vc_rebuild_screen($normal, W, H)),
        'log' => is_file($dir . '/.vicecode_fatal.log') ? trim((string) file_get_contents($dir . '/.vicecode_fatal.log')) : '',
    ];
}

foreach (['zh_CN', 'en'] as $locale) {
    echo str_repeat('═', 70), "\n";
    echo "【{$locale}】有残留标记（模拟「上次被 SIGKILL / 段错误弄死」）\n";
    echo str_repeat('═', 70), "\n";
    $a = runOnce(4194304, $locale);   // 一个肯定不存在的 pid
    echo "── 退出后用户看到的普通屏（重建）──\n";
    foreach (explode("\n", $a['screen']) as $line) {
        if (trim($line) !== '') {
            echo '  │ ', $line, "\n";
        }
    }
    echo "\n── 致命日志 ──\n";
    echo $a['log'] !== '' ? '  │ ' . $a['log'] . "\n" : "  （空）\n";

    echo "\n【{$locale}】对照：没有残留标记（上次正常退出）\n";
    $b = runOnce(null, $locale);
    $hit = str_contains($b['normalBytes'], '上次会话未正常结束')
        || str_contains($b['normalBytes'], 'previous session did not exit');
    echo '  普通屏里出现提示：', $hit ? '**出现了（不对劲！）**' : '没有 ✓', "\n";
    echo '  日志：', $b['log'] !== '' ? $b['log'] : '（空）✓', "\n\n";
}

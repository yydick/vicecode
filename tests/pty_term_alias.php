<?php
declare(strict_types=1);

/**
 * 真实 pty 验收：终端**默认就是交互式 shell** —— 用户的别名 / 函数 / 提示符都能用。
 *
 * 背景（用户最初报障的原文）：runner 模式走 `sh -c`，**看不到** ~/.bashrc 里的别名，
 * 所以在终端里敲 `ll` 一律 `command not found`。默认改成 pty 后应当直接可用。
 *
 * 做法：给子进程一个**自造的 HOME**，里面的 .bashrc 只定义
 *   `alias ll='echo LL_ALIAS_OK'`
 * 然后跑**两次**（分开跑是刻意的：同一份输出里既有「别名成功」也有「runner 失败」，
 * 拿整份输出做子串断言会互相污染 —— 第一次就踩了：pty 段的「无 command not found」
 * 被 runner 段自己的 `ll: command not found` 判红）：
 *   - 运行 1（pty 段）：Tab 到终端 → **直接敲 `ll` + 回车**（不必先按 F2）→ 出现 LL_ALIAS_OK，
 *     且整份输出里**不出现** command not found；
 *   - 运行 2（runner 段）：打字 `exit` 结束 shell 落到 runner → 同样的 `ll` → 出现
 *     `command not found`，且**不出现** LL_ALIAS_OK。
 *     这条反向对照是关键：证明运行 1 的成功**是真实 shell 展开了别名**，而不是
 *     「ll 恰好是个真命令」或断言宽松。
 *
 * ⚠️ 固定 SHELL=/bin/bash：注入 rcfile 的机制是 bash 专用的（见 PtyProcess::start），
 * 开发机 $SHELL 若是 zsh 之类，别名这条链根本不会走 .bashrc。
 *
 * 运行：timeout 120 php tests/pty_term_alias.php
 */

require __DIR__ . '/lib/isolation.php';

$cfgFile = vc_isolate_config('vc_term_alias');
file_put_contents($cfgFile, (string) json_encode(['persistSession' => false]));

// 自造 HOME：只放一个 .bashrc，里面就是那条别名（其余一概不提供，避免沾到开发机环境）
$home = vc_tmp_dir('vc_alias_home');
file_put_contents($home . '/.bashrc', "alias ll='echo LL_ALIAS_OK'\n");

$env = array_merge(getenv(), [
    'COLUMNS' => '120',
    'LINES' => '40',
    'APP_LOCALE' => 'zh_CN',
    'VICECODE_CONFIG' => $cfgFile,
    'HOME' => $home,
    'SHELL' => '/bin/bash',
]);
$descs = [0 => ['pty'], 1 => ['pty'], 2 => ['pty']];

/** 剥 ANSI 后只留字母数字与汉字（差分渲染会把整词拆成带定位序列的碎块） */
function normalize(string $raw): string
{
    $s = (string) preg_replace('/\x1B\][^\x07\x1B]*(?:\x07|\x1B\\\\)/', '', $raw);
    $s = (string) preg_replace('/\x1B\[[0-9;?]*[ -\/]*[@-~]/', '', $s);
    $s = (string) preg_replace('/\x1B[@-Z\\\\-_]/', '', $s);
    return strtolower((string) preg_replace('/[^a-zA-Z0-9\x{4e00}-\x{9fff}]/u', '', $s));
}

/**
 * 跑一次应用，按序列喂键，返回 ['out' => 原始输出, 'code' => 退出码]。
 * @param array<array{0:string,1:int}> $seq
 */
function runApp(string $bin, array $env, array $descs, array $seq): array
{
    $proc = proc_open([PHP_BINARY, $bin], $descs, $pipes, null, $env);
    if ($proc === false) {
        return ['out' => '', 'code' => -1];
    }
    stream_set_blocking($pipes[0], false);
    stream_set_blocking($pipes[1], false);
    // 子进程退出后读 pty 主端会触发 EIO(errno=5)，视为 EOF；仅精确忽略该错误
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
    $out = '';
    $feed = static function (string $bytes) use ($pipes): void {
        if ($bytes !== '') {
            fwrite($pipes[0], $bytes);
        }
    };
    $drain = static function () use ($pipes, $readPty, &$out): void {
        while (true) {
            $r = [$pipes[1]];
            $w = $e = [];
            if (stream_select($r, $w, $e, 0, 30000) <= 0) {
                return;
            }
            $chunk = $readPty($pipes[1], 8192);
            if ($chunk === '' || $chunk === false) {
                return;
            }
            $out .= $chunk;
        }
    };
    foreach ($seq as [$bytes, $us]) {
        if (!proc_get_status($proc)['running']) {
            break;
        }
        $feed($bytes);
        usleep($us);
        $drain();
    }
    // 兜底退出：Esc（pty 非捕获态 / runner 空输入都退出）→ Ctrl+Q
    $guard = 0;
    while (proc_get_status($proc)['running'] && $guard < 6) {
        $feed($guard % 2 === 0 ? "\x1b" : "\x11");
        usleep(200000);
        $drain();
        $guard++;
    }
    return ['out' => $out, 'code' => proc_close($proc)];
}

$bin = __DIR__ . '/../bin/vicecode.php';

// 运行 1：pty 段 —— 不按 F2，直接打字（首个字符自动进捕获并转发给真实 shell）
$ptySeq = [
    ["\t", 700000],          // sidebar -> editor
    ["\t", 700000],          // editor -> terminal
    ['ll', 700000],          // 自动捕获 → 真实 shell 展开别名
    ["\r", 1200000],
    ["\x1b", 800000],        // Esc：非捕获态 → 退出应用
    ["\x11", 1800000],       // Ctrl+Q 保底
];

// 运行 2：runner 段 —— 先 exit 结束 shell，之后同样的 ll 应当失败（反向对照）
$runnerSeq = [
    ["\t", 700000],
    ["\t", 700000],
    ['exit', 700000],
    ["\r", 1500000],         // shell 退出 → 回落 runner
    ['ll', 700000],
    ["\r", 1200000],
    ["\x1b", 800000],        // Esc：runner 空输入 → 退出
    ["\x11", 1800000],
];

$r1 = runApp($bin, $env, $descs, $ptySeq);
$r2 = runApp($bin, $env, $descs, $runnerSeq);

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

$n1 = normalize($r1['out']);
$n2 = normalize($r2['out']);
$fatal = str_contains($r1['out'], 'Fatal error') || str_contains($r2['out'], 'Fatal error')
    || str_contains($r1['out'], 'Uncaught') || str_contains($r2['out'], 'Uncaught');

echo "== 终端别名：pty 段（默认交互式 shell）==\n";
check($r1['code'] === 0, "干净退出 exit=$r1[code]");
check(!$fatal, '无 Fatal / Uncaught');
check(str_contains($n1, 'llaliasok'), '敲 `ll` 跑出 alias 定义的 LL_ALIAS_OK（真实 shell 展开了别名）');
check(!str_contains($n1, 'commandnotfound'), '整份输出里不出现 command not found');

echo "\n== 终端别名：runner 段（反向对照）==\n";
check($r2['code'] === 0, "干净退出 exit=$r2[code]");
check(str_contains($n2, '交互终端已退出'), '打字 exit 后 shell 退出并回落 runner');
check(str_contains($n2, 'llcommandnotfound'), '同样的 `ll` 在 runner 段确实 command not found');
check(!str_contains($n2, 'llaliasok'), 'runner 段没有别名可展开（与 pty 段形成对照）');

if ($failed) {
    file_put_contents(__DIR__ . '/pty_term_alias_dump.log', "=== run1 ===\n" . $r1['out'] . "\n=== run2 ===\n" . $r2['out']);
    echo "  (已转储原始输出到 tests/pty_term_alias_dump.log)\n";
}
echo $failed ? "\nRESULT: FAIL\n" : "\nRESULT: PASS\n";
exit($failed ? 1 : 0);

<?php
declare(strict_types=1);

/**
 * M2 真实 pty 验收：在真终端里切到终端面板、跑命令、看输出、中断长命令、退出。
 *
 * 终端**默认就是交互式 pty**（B15），runner 是 shell 退出后的回落态。本用例跑**两次**，
 * 两段各自确定性起手（同一份输出里混两种模式的文字会互相污染断言，pty_scroll 已踩过一次）：
 *   A. pty 段：Tab 到终端后**直接打字**即自动进捕获 → 命令由真实 shell 执行、输出渲染到画面；
 *   B. runner 段：**预置 runner 会话快照**起手（`exit 3` 这类「退出码 3」语义只有 runner 有；
 *      在 pty 段它会杀掉 shell 本身），覆盖退出码 / stderr / Ctrl+C 中断。
 *
 * ⚠️ runner 段为什么不用「打字 `exit` 结束 shell」到达：那取决于 bash 启动快慢 ——
 * 实测慢时 `exit` 与它后面的命令会一起被攒到提示符之后才执行，而 shell 在**同一轮 poll**
 * 里就被判定退出、回落 runner，命令输出还没被读出来就随仿真器一起丢了 → 用例偶发假红。
 * 预置快照则从构造起就是 runner，零竞态（顺带覆盖 maybeRestore 的 runner 分支）。
 *
 * 覆盖 R1/R2/R3/R5/R7 与「命令运行期间 UI 不崩、退出干净」。
 *
 * 运行：timeout 120 php tests/pty_term.php
 * 注意：禁止裸跑 bin/vicecode.php，一律走本脚本（外层加 timeout 兜底）。
 */

require __DIR__ . '/lib/isolation.php';

$cfgFile = vc_isolate_config('vc_pty_term');
file_put_contents($cfgFile, (string) json_encode(['persistSession' => true]));
$sessionFile = dirname($cfgFile) . '/.vicecode_session';
@unlink($sessionFile);

// ⚠️ 自造 HOME：交互式 shell 会 source `$HOME/.bashrc`，而开发机的 .bashrc 会加载 nvm + conda
// （`conda shell.bash hook` 真的起一个 Python 子进程）—— 启动要数秒且随机器负载抖动，
// 于是「打字→出结果」的窗口不可预测（实测同一脚本 3/6 假红）。本用例验的是终端管线，
// 不该被用户 rc 的启动速度牵着走，故给一个只有一行的 .bashrc。
$home = vc_tmp_dir('vc_term_home');
file_put_contents($home . '/.bashrc', "PS1='ready$ '\n");

// pty 默认没有窗口尺寸，php-tui 会拿到 0×0 从而渲染不出任何内容
// （Terminal 依次试 SizeFromEnvVarProvider → SizeFromSttyProvider，pty 下两者都为空）。
// 显式给 COLUMNS/LINES，让画面真的画出来，命令输出才可见、可断言。
$env = array_merge(getenv(), [
    'COLUMNS' => '120',
    'LINES' => '40',
    'APP_LOCALE' => 'zh_CN',
    'VICECODE_CONFIG' => $cfgFile,
    'HOME' => $home,
    'SHELL' => '/bin/bash',
]);
$descs = [0 => ['pty'], 1 => ['pty'], 2 => ['pty']];

/**
 * 画面是差分渲染：同一行的字符会被拆成多次「带光标定位」的写入，
 * 直接 grep 整词匹配不到（实际是 "p t y - s t d e r r"）。
 * 故先剥掉 ANSI 序列，再只保留字母数字与汉字后比对。
 */
function normalize(string $raw): string
{
    $s = (string) preg_replace('/\x1B\][^\x07\x1B]*(?:\x07|\x1B\\\\)/', '', $raw);   // OSC
    $s = (string) preg_replace('/\x1B\[[0-9;?]*[ -\/]*[@-~]/', '', $s);             // CSI
    $s = (string) preg_replace('/\x1B[@-Z\\\\-_]/', '', $s);
    return strtolower((string) preg_replace('/[^a-zA-Z0-9\x{4e00}-\x{9fff}]/u', '', $s));
}

/**
 * 跑一次应用，按序列喂键，返回 ['out' => 原始输出, 'code' => 退出码]。
 *
 * 每一步是 `[字节, 等待微秒, ?锚点]`：给了锚点时改为**条件等待** —— 在等待上限内轮询排空，
 * 直到输出里出现该锚点就继续（`不赌 sleep`，这是 pty 用例的老纪律）。交互式 shell 的启动
 * 快慢受机器负载影响很大，固定 sleep 会让断言偶发假红。
 *
 * @param array<array{0:string,1:int,2?:string}> $seq
 */
function runApp(string $bin, array $env, array $descs, array $seq): array
{
    $proc = proc_open([PHP_BINARY, $bin], $descs, $pipes, null, $env);
    if ($proc === false) {
        return ['out' => '', 'code' => -1];
    }
    stream_set_blocking($pipes[0], false);
    stream_set_blocking($pipes[1], false);
    // 子进程退出后读 pty 主端会触发 EIO(errno=5)，视为 EOF；仅精确忽略该错误，不掩盖其它异常
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
    foreach ($seq as $step) {
        [$bytes, $us] = $step;
        $anchor = $step[2] ?? null;
        if (!proc_get_status($proc)['running']) {
            break;
        }
        $feed($bytes);
        $deadline = microtime(true) + $us / 1000000;
        do {
            usleep(20000);
            $drain();
            // ⚠️ 锚点比对必须走 normalize()：差分渲染会把一个词拆成「带光标定位的多次写入」，
            // 原始字节流里根本没有连续的中文/单词（实测 '退出码3' 永远匹配不上、白等到超时）。
            if ($anchor === null || str_contains(normalize($out), normalize($anchor))) {
                break;
            }
        } while (microtime(true) < $deadline);
    }
    // 兜底退出：Esc（捕获态 → 退捕获）+ Ctrl+Q（真正的全局退出键）。
    // ⚠️ 别用普通字符顶（如 'q'）：非捕获 pty 下敲任何可打印字符都会**自动进捕获**并把该键
    // 转发给 shell（这正是本期的设计），于是「Esc 退捕获 → q 又进捕获」死循环，永远退不出去。
    $guard = 0;
    while (proc_get_status($proc)['running'] && $guard < 8) {
        $feed($guard % 2 === 0 ? "\x1b" : "\x11");
        usleep(200000);
        $drain();
        $guard++;
    }
    return ['out' => $out, 'code' => proc_close($proc)];
}

$bin = __DIR__ . '/../bin/vicecode.php';

// ── A. pty 段：不打 F2，Tab 到终端后直接打字 ──
// 命令刻意用「输出与命令行不同」的形式：echo $((6*7)) 输出 42，
// 而命令行归一化后是 echo67（不含 42）→ 断言到 42 才能证明「命令真的执行并渲染」。
// 第三步等「42 出现」而不是等固定时间：交互式 shell 的启动快慢随负载变化很大。
$seqPty = [
    ["", 10000000, 'ctrlq'],               // 先等应用起来（状态栏画出 Ctrl+Q）：启动期灌键是另一类竞态
    ["\t", 60000],                        // sidebar -> editor
    ["\t", 60000],                        // editor -> terminal
    ['echo $((6*7))', 60000],             // 自动捕获 + 转发；pty 会回显命令行
    ["\r", 8000000, '42'],                // 等到 42 真的渲染出来（上限 8s）
    ["\x1b", 400000],                     // Esc：退捕获（shell 仍在跑）
    ["\x11", 1000000],                    // Ctrl+Q：退出应用
];

$r1 = runApp($bin, $env, $descs, $seqPty);

// ── B. runner 段：预置 runner 快照，从构造起就是 runner ──
@unlink($sessionFile);
file_put_contents($sessionFile, (string) json_encode([
    'mode' => 'runner',
    'cwd' => getcwd() ?: '.',
    'lines' => [['runner seeded line', 0]],
    'savedAt' => time(),
]));
$seqRunner = [
    ["", 10000000, 'runnerseededline'],    // 先等应用起来并且确实落在 runner（快照那行已渲染）
    ["\t", 60000],
    ["\t", 60000],
    ['echo err-marker >&2; exit 3', 60000],
    ["\r", 4000000, '退出码3'],           // 提交后等它跑完并渲染（stderr 与退出码都在其后）
    ['sleep 30', 60000],
    ["\r", 500000],                       // 起一条长命令
    ["\x03", 4000000, '已中断'],          // R7 Ctrl+C：只中断命令，不退出应用
    ['echo alive-marker', 60000],         // 若应用还活着，这条能提交并出结果
    ["\r", 4000000, 'alivemarker'],
    ["\x1b", 400000],                     // Esc：空输入 → 退出
];
$r2 = runApp($bin, $env, $descs, $seqRunner);
@unlink($sessionFile);

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

echo "== M2 真实 pty 验收 · A. pty 段（默认交互式 shell）==\n";
check($r1['code'] === 0, "干净退出 exit=$r1[code]");
check(!$fatal, '无 Fatal / Uncaught');
// 42 只可能来自 ($((6*7))) 求值 —— 命令行本身归一化后不含这两个数字连写
check(str_contains($n1, '42'), 'R2/R3 命令真的执行了，且输出被渲染到画面（输出 42）');
check(!str_contains($n1, 'commandnotfound'), '没有 command not found（命令确实交给了真实 shell）');

echo "\n== M2 真实 pty 验收 · B. runner 段（shell 退出后的回落态）==\n";
check($r2['code'] === 0, "干净退出 exit=$r2[code]");
check(str_contains($n2, 'runnerseededline'), '起手就是 runner：快照里的旧输出可见（runner 快照恢复生效）');
check(str_contains($n2, 'errmarker'), 'R5 stderr 输出进入面板');
check(str_contains($n2, '退出码3'), 'R5 非零退出码提示可见');
check(str_contains($n2, '已中断'), 'R7 Ctrl+C 中断提示可见');
check(str_contains($n2, 'alivemarker'), 'R7 中断后应用仍存活（Ctrl+C 未误退出）');

if ($failed) {
    file_put_contents(__DIR__ . '/pty_term_dump.log', "=== run A ===\n" . $r1['out'] . "\n=== run B ===\n" . $r2['out']);
    echo "  (已转储原始输出到 tests/pty_term_dump.log)\n";
}
echo $failed ? "RESULT: FAIL\n" : "RESULT: PASS\n";
exit($failed ? 1 : 0);

<?php
declare(strict_types=1);

/**
 * R3 真实 pty：未捕获异常也必须还原终端、且必须**收掉子进程与临时文件**。
 *
 * 为什么单独开一个文件：终端被留在 raw mode + alternate screen 时，用户的 shell
 * 直接废掉（无回显、无光标），是所有故障里最严重的一种。而这条路径在正常退出时
 * 测不出来 —— 必须在**真的崩了**的情况下验证。
 *
 * 怎么造崩溃而不污染产品代码：运行时把 bin/vicecode.php 复制一份、注入一行 `throw`，
 * 跑这个副本。产品代码里不留任何测试开关。两个场景：
 *   A. 启动后立刻崩（主循环之前）—— 只验终端还原；
 *   B. **进入交互 pty、shell 活着时**崩 —— 验资源回收（pty 子进程 + `--rcfile` 临时文件）。
 *      这条路径只靠 `Lifecycle` 覆盖不到（异常不经过它），必须由 `start()` 的 finally 兜底。
 *
 * 断言什么：
 *   A. 进程非 0 退出，但**崩溃前仍发出了还原终端的转义序列**（`ESC[?1049l` / `ESC[?25h`）——
 *      光看退出码不够，崩溃时它必然非 0，看不出终端有没有被还原。
 *   B. 崩溃后 `/tmp/vicetui_rc_*` **一个不多**（pty 子进程用过的 bash rc 临时文件必须被删）。
 *
 * 运行：php tests/pty_crash.php
 */

chdir(__DIR__ . '/..');
$root = getcwd();
require __DIR__ . '/lib/isolation.php';
$cfgFile = vc_isolate_config('vc_crash_pty');   // 否则应用退出时会写开发机真实 ~/.vicerc

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

/** 生成一份「会崩」的入口副本，返回路径 */
function makeCrashingEntry(string $root): string
{
    $src = (string) file_get_contents($root . '/bin/vicecode.php');
    $marker = '->build();';
    if (!str_contains($src, $marker)) {
        throw new RuntimeException('找不到注入点，bin/vicecode.php 结构变了');
    }
    $out = str_replace(
        $marker,
        $marker . "\n    throw new \\RuntimeException('crash injected by tests/pty_crash.php');",
        $src
    );
    // ⚠️ 必须放在**项目根下面**：入口用 __DIR__.'/../vendor/autoload.php' 定位自动加载，
    // 放到 /tmp 会解析成 /vendor/autoload.php 而直接 Fatal（那样测的是加载失败，不是崩溃还原）。
    // 用点开头的目录，避免污染 bin/ —— pty_mouse 会按固定坐标点 bin/ 里的条目。
    $dir = $root . '/.vicecode_crash_' . getmypid();
    @mkdir($dir);
    $path = $dir . '/vicecode.php';
    file_put_contents($path, $out);
    return $path;
}

/**
 * 生成一份「**pty 会话进行中**才崩」的入口副本。
 *
 * 场景 A 的 throw 注入在 DisplayBuilder 之后、主循环之前，那时还没有任何子进程，
 * 因此**覆盖不到**「shell 还活着时崩溃」这条路径 —— 而那正是资源回收最要紧的场景
 * （不回收就只能靠内核在 pty 主端关闭时发 SIGHUP 兜底）。
 *
 * 注入点：Swoole 主循环里唯一的 `$app->handle($ev, …)` 之后，判「标记文件出现」才抛。
 * 测试先按 F2 把 shell 起起来，再创建标记文件 + 发一个无害按键（handle 才会被调到）。
 */
function makeCrashyAfterPtyEntry(string $root): string
{
    $src = (string) file_get_contents($root . '/bin/vicecode.php');
    $anchor = '$app->handle($ev, $display->viewportArea());';
    if (!str_contains($src, $anchor)) {
        throw new RuntimeException('找不到注入点，bin/vicecode.php 主循环结构变了');
    }
    $inject = $anchor
        . "\n            \$__m = getenv('VC_CRASH_MARKER');"
        . "\n            if (\$__m !== false && is_file(\$__m)) {"
        . "\n                throw new \\RuntimeException('crash injected while pty alive');"
        . "\n            }";
    $out = str_replace($anchor, $inject, $src);
    $dir = $root . '/.vicecode_crash_pty_' . getmypid();
    @mkdir($dir);
    $path = $dir . '/vicecode.php';
    file_put_contents($path, $out);
    return $path;
}

/**
 * 数当前还活着的 `bash --rcfile` 进程（应用起交互 pty 时用的那种 shell）。
 *
 * grep 模式用 `[b]ash` 方括号技巧，避免把 grep 自己（命令行里含该模式）算进去。
 */
function bashRcfileCount(): int
{
    exec("ps -eo args 2>/dev/null | grep -c '[b]ash --rcfile'", $lines);
    return (int) ($lines[0] ?? 0);
}

/**
 * 生成一份「**不可捕获的致命错误**发生在终端已就绪之后」的入口副本（场景 C）。
 *
 * 为什么单独一个场景：场景 A/B 注入的都是 `throw`（Throwable），能被 `start()` 的
 * try/catch/finally 接住 → 终端照常还原。但 PHP 的**致命错误**（E_ERROR /
 * E_USER_ERROR / 内存耗尽）**不会执行 finally** —— 实测：内存耗尽与未捕获 Error 下
 * finally 都不跑，而 `register_shutdown_function` 会跑。
 * 于是这类死亡会把终端留在 raw + alternate screen + **鼠标上报开着**：用户回到 shell 后
 * 每动一下鼠标就被灌进 `ESC[<b;x;yM`（`ESC [ <` 被终端当控制序列吃掉，只剩数字
 * → `-bash: 35: command not found`）。这是用户实测报上来的现象。
 *
 * 注入点放在 `start()` 里终端已进入 raw/备用屏/鼠标**之后**，否则测不到"烂摊子"。
 */
function makeFatalEntry(string $root): string
{
    $src = (string) file_get_contents($root . '/bin/vicecode.php');
    $anchor = "    \$term->flush();\n\n    // ⚠️ 从这里到函数结束";
    if (!str_contains($src, $anchor)) {
        throw new RuntimeException('找不到注入点（终端已就绪之后），bin/vicecode.php 结构变了');
    }
    $inject = "    \$term->flush();\n"
        . "    trigger_error('fatal injected by pty_crash', E_USER_ERROR);\n\n"
        . "    // ⚠️ 从这里到函数结束";
    $out = str_replace($anchor, $inject, $src);
    $dir = $root . '/.vicecode_fatal_' . getmypid();
    @mkdir($dir);
    $path = $dir . '/vicecode.php';
    file_put_contents($path, $out);
    return $path;
}

/**
 * 场景 B 的编排：起应用 → 焦点切到终端 → F2 进交互 pty → **等到交互 shell 真的起来**
 * → 创建标记文件 + 发一个按键触发崩溃 → 收输出。
 *
 * 「shell 起来了」的判据用 `bash --rcfile` **进程数增加**，不用 rc 临时文件是否出现：
 * 自 D9 起 rc 由 bash 读完即自删（`PtyProcess::buildIntegrationRc`），文件只在毫秒级窗口内
 * 存在，靠 30ms 轮询去看它会偶发看不到（假阴性）。
 *
 * @return array{0:int,1:string,2:bool,3:int,4:int} [exit, 输出并集, shell 是否真的起来, rcBefore, rcAfter]
 */
function runCrashWhilePtyAlive(string $script, string $cfg, string $marker): array
{
    $rcGlob = sys_get_temp_dir() . '/vicetui_rc_*';
    $rcBefore = count((array) glob($rcGlob));
    $bashBefore = bashRcfileCount();
    @unlink($marker);

    $descs = [0 => ['pty'], 1 => ['pty'], 2 => ['pty']];
    $env = array_merge(getenv(), [
        'COLUMNS' => '120',
        'LINES' => '40',
        'VICECODE_CONFIG' => $cfg,
        'VC_CRASH_MARKER' => $marker,
    ]);
    $p = proc_open([PHP_BINARY, $script], $descs, $pipes, $root = getcwd(), $env);
    if ($p === false) {
        return [-1, '', false, $rcBefore, $rcBefore];
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $out = '';
    $shellStarted = false;
    $sent = 0;
    $deadline = microtime(true) + 20;
    while (microtime(true) < $deadline) {
        foreach ([1, 2] as $fd) {
            $c = @fread($pipes[$fd], 8192);
            if (is_string($c) && $c !== '') {
                $out .= $c;
            }
        }
        $elapsed = 20 - ($deadline - microtime(true));
        // ① 首帧后把焦点切到终端面板（Tab×2），② F2 进交互 pty
        if ($sent === 0 && $elapsed > 0.8) {
            fwrite($pipes[0], "\t\t");
            $sent = 1;
        } elseif ($sent === 1 && $elapsed > 1.3) {
            fwrite($pipes[0], "\x1bOQ");
            $sent = 2;
        } elseif ($sent === 2 && bashRcfileCount() > $bashBefore) {
            // shell 真的起来了（`bash --rcfile` 进程出现）→ 造崩溃：标记文件 + 一个无害按键触发 handle()
            $shellStarted = true;
            file_put_contents($marker, '1');
            fwrite($pipes[0], "\t");
            $sent = 3;
        }
        $st = proc_get_status($p);
        if (!$st['running']) {
            break;
        }
        usleep(30000);
    }
    foreach ([1, 2] as $fd) {
        $c = @fread($pipes[$fd], 8192);
        if (is_string($c) && $c !== '') {
            $out .= $c;
        }
    }
    $st = proc_get_status($p);
    $code = $st['running'] ? -1 : (int) $st['exitcode'];
    if ($st['running']) {
        proc_terminate($p, SIGKILL);
    }
    proc_close($p);
    @unlink($marker);

    return [$code, $out, $shellStarted, $rcBefore, count((array) glob($rcGlob))];
}


/**
 * 场景 A / 对照组用：起一个入口副本，可选在启动后发 Ctrl+Q。
 *
 * @param bool $sendQuit 是否在启动后发 Ctrl+Q（对照组需要，崩溃组不需要——它自己会死）
 */
function runInPty(string $script, array $envExtra, bool $sendQuit = false): array
{
    $descs = [0 => ['pty'], 1 => ['pty'], 2 => ['pty']];
    $env = array_merge(getenv(), ['COLUMNS' => '120', 'LINES' => '40'], $envExtra);
    $p = proc_open([PHP_BINARY, $script], $descs, $pipes, $env['PWD'] ?? null, $env);
    if ($p === false) {
        return [-1, '', ''];
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    if ($sendQuit) {
        usleep(500000);
        fwrite($pipes[0], "\x11"); // Ctrl+Q
    }

    $out = '';
    $err = '';
    $deadline = microtime(true) + 8;
    while (true) {
        $c = @fread($pipes[1], 8192);
        if (is_string($c) && $c !== '') {
            $out .= $c;
        }
        $c2 = @fread($pipes[2], 8192);
        if (is_string($c2) && $c2 !== '') {
            $err .= $c2;
        }
        $st = proc_get_status($p);
        if (!$st['running']) {
            break;
        }
        if (microtime(true) > $deadline) {
            proc_terminate($p, SIGKILL);
            break;
        }
        usleep(30000);
    }
    // 收尾再读一轮
    $c = @fread($pipes[1], 8192);
    if (is_string($c)) {
        $out .= $c;
    }
    $c2 = @fread($pipes[2], 8192);
    if (is_string($c2)) {
        $err .= $c2;
    }
    $st = proc_get_status($p);
    $code = $st['running'] ? -1 : (int) $st['exitcode'];
    proc_close($p);

    // ⚠️ proc_open 的三个 ['pty'] 描述符**共用同一个 pty**（实测：子进程同时写
    // STDOUT/STDERR 时，$pipes[1] 一次就读到 "OUTERR"，$pipes[2] 读到 false）。
    // 所以两个管道是在抢同一份数据，先读的拿走。断言必须看两者的并集，
    // 只查 $out 会随机漏掉尾部（表现为"还原序列时有时无"）。
    // [2] 返回 $err 本身（此前恒返回空串 —— 场景 A 的断言查它，等于永假分支，
    // 见下方 BUGFIXES D8/T4 的说明）。
    return [$code, $out . $err, $err];
}

$crash = makeCrashingEntry($root);
register_shutdown_function(static function () use ($crash): void {
    @unlink($crash);
    @rmdir(dirname($crash));
});

echo "== 崩溃路径（注入 throw）==\n";
[$code, $out, $err] = runInPty($crash, ['VICECODE_CONFIG' => $cfgFile]);
check($code !== 0, '崩溃时进程非 0 退出（实际 ' . $code . '）');
// ⚠️ 断言只读 $out（stdout+stderr 的并集），不看 $err：pty 三描述符共用同一 pty，输出会
// 随机落在任一路上（历史上这里写的是 `$err`，而 runInPty 的 [2] 恒为空串 → 该分支永假，
// 这条断言其实一直靠 `$out` 里含 "Uncaught" 才「通过」；改用 reportFatal 后打不出
// "Uncaught"，假断言才暴露 —— 见 BUGFIXES T4）。
check(str_contains($out, 'crash injected'), '异常被顶层捕获并报错（不是静默死掉）');
check(str_contains($out, 'ViceCode 异常退出'), '走 reportFatal 的人话提示');
check(!str_contains($out, 'Fatal error') && !str_contains($out, 'Uncaught'),
    '不喷 PHP Fatal / Uncaught 堆栈（协程内异常必须被接住）');
check($code === 1, '异常退出码恰为 1（PHP Fatal 会是 255）—— 实际 ' . $code);
// 核心断言：还原序列必须在崩溃后仍然发出
check(str_contains($out, "\x1b[?1049l"), '崩溃后仍发出 ESC[?1049l（退出 alternate screen）');
check(str_contains($out, "\x1b[?25h"), '崩溃后仍发出 ESC[?25h（显示光标）');
check(str_contains($out, "\x1b[?1000l"), '崩溃后仍发出 ESC[?1000l（关闭鼠标捕获）');

echo "== 对照组：正常退出也要还原（不能因为改了结构就丢）==\n";
[$code2, $out2, $err2] = runInPty($root . '/bin/vicecode.php', ['VICECODE_CONFIG' => $cfgFile], true);
check($code2 === 0, '正常路径 exit=0（实际 ' . $code2 . '）');
check(str_contains($out2, "\x1b[?1049l"), '正常路径也发出 ESC[?1049l');
check(str_contains($out2, "\x1b[?25h"), '正常路径也发出 ESC[?25h');
// 还原序列只应出现一次：多来一遍说明还原逻辑被挂了两条（defer + finally 重复）
$restoreCount = preg_match_all('/\x1b\[\?1049l/', $out2);
check($restoreCount === 1, '还原序列只发一次（实际 ' . $restoreCount . ' 次）—— 重复说明还原被挂了多处');

echo "== 崩溃路径续：**交互 pty 活着时**崩溃，必须收掉子进程与 rc 临时文件 ==\n";
$crashB = makeCrashyAfterPtyEntry($root);
register_shutdown_function(static function () use ($crashB): void {
    @unlink($crashB);
    @rmdir(dirname($crashB));
});
$marker = sys_get_temp_dir() . '/vc_crash_marker_' . getmypid();
$orphBefore = bashRcfileCount();
[$codeB, $outB, $shellUp, $rcBefore, $rcAfter] = runCrashWhilePtyAlive($crashB, $cfgFile, $marker);
usleep(300000);   // 让孤儿进程（若真漏了）完成 reparent 再数
$orphAfter = bashRcfileCount();
// ★ 正向锚点：先证明「shell 真的起来了」，否则下面那条阴性断言是空转（feedback §1.3）
check($shellUp, 'F2 后交互 shell 真的起来了（看到 bash --rcfile 的临时文件出现）');
// 收紧为「恰为 1」：挂住时这里会得到 -1（proc_terminate 之前进程还活着），而旧的
// `!== 0` 判据把挂住也算通过。挂住的根因见 BUGFIXES D8：协程内异常被 catch 后，
// Coroutine\run() 仍在等读键协程结束，必须由 start() 的 catch 置 $app->quit 才能返回。
check($codeB === 1, '崩溃时进程恰为 1 退出（挂住会得到 -1）—— 实际 ' . $codeB);
check(str_contains($outB, 'crash injected while pty alive'),
    '异常被顶层捕获并报错（不是静默死掉）');
check(!str_contains($outB, 'Fatal error') && !str_contains($outB, 'Uncaught'),
    '场景 B 同样不喷 PHP Fatal / Uncaught 堆栈');
check(str_contains($outB, "\x1b[?1049l") && str_contains($outB, "\x1b[?25h"),
    '崩溃后仍发出终端还原序列');
// 核心断言（本场景独有）：异常退出路径也必须收掉 pty 子进程留下的 rc 临时文件。
// 只靠 Lifecycle 的关闭闭包覆盖不到这条路径，必须靠 start() 的 finally 兜底。
check($rcAfter <= $rcBefore,
    sprintf('崩溃后 /tmp 未残留 pty rc 文件（vicetui_rc_*：%d → %d）', $rcBefore, $rcAfter));
// 更严重的症状：shell 还活着时崩溃，若不收它会变成一个**活着的孤儿 shell**（实测每次漏一个，
// 一直占着 pty；文件泄漏只是它的附带症状）。所以这条比上面那条更要紧。
check($orphAfter <= $orphBefore,
    sprintf('崩溃后没有孤儿 shell（bash --rcfile 进程：%d → %d）', $orphBefore, $orphAfter));

@unlink($crash);
@unlink($crashB);
@rmdir(dirname($crash));
@rmdir(dirname($crashB));

echo "== 场景 C：**不可捕获的致命错误**也必须还原终端 ==\n";
$crashC = makeFatalEntry($root);
register_shutdown_function(static function () use ($crashC): void {
    @unlink($crashC);
    @rmdir(dirname($crashC));
});
[$codeC, $outC, $errC] = runInPty($crashC, ['VICECODE_CONFIG' => $cfgFile]);
// 正向锚点：先证明致命错误真的发生了，否则下面的断言是空转（feedback §1.3）
check(str_contains($outC, 'fatal injected'), '注入的致命错误确实发生（正向锚点）');
check($codeC !== 0, '致命错误时进程非 0 退出（实际 ' . $codeC . '）');
// ★ 核心三条：致命错误下 finally 不执行，必须靠 register_shutdown_function 兜底
check(str_contains($outC, "\x1b[?1000l"),
    '致命错误后仍关闭鼠标捕获 ESC[?1000l（否则鼠标上报会灌进用户的 shell）');
check(str_contains($outC, "\x1b[?1049l"), '致命错误后仍退出 alternate screen ESC[?1049l');
check(str_contains($outC, "\x1b[?25h"), '致命错误后仍显示光标 ESC[?25h');
// 还原序列同样只应出现一次（shutdown 兜底不能与 finally 重复发）
$restoreCountC = preg_match_all('/\x1b\[\?1049l/', $outC);
check($restoreCountC === 1, '致命错误路径的还原序列也只发一次（实际 ' . $restoreCountC . ' 次）');
// 致命错误必须**落盘**：还原终端只能止血，用户还得知道"为什么死"。
// 原本那行 `PHP Fatal error:` 是打在 alternate screen 上的，还原后就被冲掉了 —— 用户实测
// 报这个 bug 时，正是因为没有这行日志，只能看到终端乱掉。日志落在配置同目录，测试隔离时自动清理。
$fatalLog = dirname($cfgFile) . '/.vicecode_fatal.log';
check(is_file($fatalLog) && str_contains((string) file_get_contents($fatalLog), 'fatal injected'),
    '致命错误被写入日志（下次不必靠猜）—— ' . $fatalLog);
check(str_contains($outC, '若终端仍不正常'), '致命错误后打印了终端自救提示（reset / stty sane）');
@unlink($crashC);
@rmdir(dirname($crashC));

echo $failed ? "\nR3 崩溃还原 FAIL\n" : "\nR3 崩溃还原 PASS\n";
exit($failed ? 1 : 0);

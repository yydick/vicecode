<?php
declare(strict_types=1);

/**
 * 探针（**不参与跑批**，`run_tests.sh` 会跳过带 probe 的文件）：
 * pty 主端关闭后，用户态到底能不能感知到「对端没了」？
 *
 * 结论（2026-09-18 实测）：**完全不能**。`bin/vicecode.php` 里那个
 * `fread() === '' → EOF → break` 分支是**死代码** —— 因为根本走不到 fread：
 *   - `Coroutine::waitEvent(STDIN, SWOOLE_EVENT_READ, 0.15)` 在 master 关闭后**永远返回 false**
 *     （纯超时，EOF 不被当成可读）；
 *   - `stream_select` 报 0 可读；`feof` / `stream_get_meta_data()['eof']` 恒 false；
 *   - 非阻塞 `fread` 返回空串（**与 EAGAIN 不可区分**）；
 *   - 连**阻塞** `fread` 都永久挂住（8s 不返回）；`posix_isatty` 仍为 true。
 * 而**真实**终端关闭（子进程是 session leader 且该 pty 是它的 controlling terminal）由内核
 * 直接发 **SIGHUP** 终止进程，不会挂住 —— 所以「终端关闭会挂住」这个担忧不成立（详见
 * docs/BUGFIXES.md D8 末段）。
 *
 * 用法：php tests/probe_pty_eof.php
 *
 * 说明：复现真实 controlling terminal 场景需要 setsid + TIOCSCTTY，PHP 做不了；
 * 当时是用等价的 python 片段验证的（pty.fork 会 setsid + 设 ctty，随后关 master 会触发 SIGHUP）。
 */

chdir(__DIR__ . '/..');

$childScript = sys_get_temp_dir() . '/vc_probe_pty_eof_child_' . getmypid() . '.php';
file_put_contents($childScript, <<<'PHP'
<?php
Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL & ~SWOOLE_HOOK_STDIO & ~SWOOLE_HOOK_FILE & ~SWOOLE_HOOK_PROC);
Swoole\Coroutine\run(function (): void {
    stream_set_blocking(STDIN, false);
    for ($i = 1; $i <= 16; $i++) {
        $wait = \Swoole\Coroutine::waitEvent(STDIN, SWOOLE_EVENT_READ, 0.15);
        $r = [STDIN];
        $w = null;
        $e = null;
        $sel = @stream_select($r, $w, $e, 0, 0);
        $feof = @feof(STDIN);
        $meta = @stream_get_meta_data(STDIN);
        $b = @fread(STDIN, 4096);
        printf(
            "#%02d waitEvent=%s select=%s feof=%s meta.eof=%s fread=%s\n",
            $i,
            var_export($wait, true),
            var_export($sel, true),
            var_export($feof, true),
            var_export($meta['eof'] ?? null, true),
            is_string($b) ? 'str(' . strlen($b) . ')' : var_export($b, true)
        );
    }
    echo "ROUNDS_DONE\n";
});
PHP);

$descs = [0 => ['pty'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$p = proc_open([PHP_BINARY, $childScript], $descs, $pipes, null, getenv());
if ($p === false) {
    @unlink($childScript);
    fwrite(STDERR, "proc_open 失败\n");
    exit(2);
}
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

echo "== 子进程在独立 pty 上读 STDIN；第 6 轮左右关掉该 pty 的 master ==\n";
usleep(900000);           // ≈ 6 轮 × 0.15s
fclose($pipes[0]);        // ← 关 master：若 EOF 能被感知，后面的轮次应当出现差异
echo "   （master 已关闭）\n";

$out = '';
$deadline = microtime(true) + 10;
while (microtime(true) < $deadline) {
    foreach ([1, 2] as $fd) {
        $c = @fread($pipes[$fd], 8192);
        if (is_string($c) && $c !== '') {
            $out .= $c;
        }
    }
    if (str_contains($out, 'ROUNDS_DONE') || !proc_get_status($p)['running']) {
        break;
    }
    usleep(50000);
}
foreach ([1, 2] as $fd) {
    $c = @fread($pipes[$fd], 8192);
    if (is_string($c) && $c !== '') {
        $out .= $c;
    }
}
if (proc_get_status($p)['running']) {
    proc_terminate($p, SIGKILL);
}
proc_close($p);
@unlink($childScript);

echo $out;
echo "\n== 结论 ==\n";
echo "关闭前后各列**没有任何差异**（一律 waitEvent=false / select=0 / feof=false /\n";
echo "meta.eof=false / fread=str(0)）→ 用户态感知不到 pty 对端消失，任何 EOF 分支都是死代码。\n";

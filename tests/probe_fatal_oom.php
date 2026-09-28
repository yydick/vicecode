<?php
declare(strict_types=1);

/**
 * 探针（不进跑批）：**内存耗尽**时，终端还原兜底自己还跑得动吗？
 *
 * ## 为什么单独测这一种
 * `tests/pty_crash.php` 场景 C 注入的是 `E_USER_ERROR`，它验证了
 * 「致命错误不走 finally、但 register_shutdown_function 会走」这条机制。
 * 但 `E_USER_ERROR` 与**内存耗尽**有个本质差别：后者发生时堆已经顶到上限，
 * 而兜底闭包要做的三件事（`saveConfig()` / `shutdownResources()` /
 * `restoreTerminal()`）**全都要分配内存** —— 一旦分配失败，兜底等于没跑，
 * 终端照样留在 raw + 备用屏 + 鼠标上报，日志也写不出来。
 *
 * 这直接决定一件事：D10 修完之后，用户下次真崩了**到底能不能拿到日志**
 * （也就是「等下次复现」这个计划是否成立）。所以必须实测，不能推断。
 *
 * 注入方式：在 `$term->flush()` 之后把 memory_limit 压到「当前用量 + 6MB」，
 * 再申请远超它的内存 → 触发 `Allowed memory size ... exhausted`（E_ERROR）。
 *
 * 运行：php tests/probe_fatal_oom.php
 */

chdir(__DIR__ . '/..');
$root = getcwd();
require __DIR__ . '/lib/isolation.php';
$cfgFile = vc_isolate_config('vc_probe_oom');

/** 造一份「终端就绪后立刻内存耗尽」的入口副本 */
function makeOomEntry(string $root): string
{
    $src = (string) file_get_contents($root . '/bin/vicecode.php');
    $anchor = "    \$term->flush();\n\n    // ⚠️ 从这里到函数结束";
    if (!str_contains($src, $anchor)) {
        throw new RuntimeException('找不到注入点，bin/vicecode.php 结构变了');
    }
    $inject = "    \$term->flush();\n"
        . "    \\ini_set('memory_limit', (string) (int) \\ceil((\\memory_get_usage() + 6 * 1024 * 1024) / 1048576) . 'M');\n"
        . "    \$hog = [];\n"
        . "    for (\$i = 0; \$i < 8000; \$i++) { \$hog[] = \\str_repeat('x', 512 * 1024); }\n"
        . "    \\fwrite(\\STDERR, \"OOM-INJECT-FAILED\\n\");\n\n"
        . "    // ⚠️ 从这里到函数结束";
    $out = str_replace($anchor, $inject, $src);
    $dir = $root . '/.vicecode_oom_' . getmypid();
    @mkdir($dir);
    $path = $dir . '/vicecode.php';
    file_put_contents($path, $out);
    return $path;
}

$entry = makeOomEntry($root);
register_shutdown_function(static function () use ($entry): void {
    @unlink($entry);
    @rmdir(dirname($entry));
});

$descs = [0 => ['pty'], 1 => ['pty'], 2 => ['pty']];
$proc = proc_open([PHP_BINARY, $entry], $descs, $pipes, $root, ['VICECODE_CONFIG' => $cfgFile]);

$readPty = static function ($stream, int $len) {
    set_error_handler(static fn(int $no, string $str): bool
        => str_contains($str, 'errno=5') || str_contains($str, 'Input/output error'));
    try {
        return fread($stream, $len);
    } finally {
        restore_error_handler();
    }
};

$out = '';
$end = microtime(true) + 20.0;
while (microtime(true) < $end) {
    $r = [$pipes[1]];
    $w = $e = [];
    if (stream_select($r, $w, $e, 0, 200000) > 0) {
        $chunk = $readPty($pipes[1], 65536);
        if ($chunk === '' || $chunk === false) {
            break;
        }
        $out .= $chunk;
    }
    if (!proc_get_status($proc)['running']) {
        break;
    }
}
$status = proc_get_status($proc);
$exit = $status['running'] ? null : $status['exitcode'];
if ($status['running']) {
    proc_terminate($proc, SIGKILL);
}
proc_close($proc);

$has = static fn(string $n): string => str_contains($out, $n) ? '有' : '**没有**';
echo "退出码：" . var_export($exit, true) . "\n";
echo "内存耗尽确实发生（stderr 带 Allowed memory size）：" . $has('Allowed memory size') . "\n";
echo "（若出现 OOM-INJECT-FAILED，说明注入没生效、下面的结论无效）："
    . $has('OOM-INJECT-FAILED') . "\n\n";

echo "--- ① 终端还原（兜底能否在堆已顶满时干活）---\n";
echo "  关鼠标 ?1000l：" . $has("\e[?1000l") . "   ← **没有**=兜底自己也没跑起来\n";
echo "  离备用屏 ?1049l：" . $has("\e[?1049l") . "\n";
echo "  显示光标 ?25h：" . $has("\e[?25h") . "\n";

echo "\n--- ② 致命日志（决定「等下次复现」是否成立）---\n";
$log = dirname($cfgFile) . '/.vicecode_fatal.log';
$exists = is_file($log);
echo "  日志文件：" . ($exists ? '已生成' : '**没有生成**') . "  $log\n";
if ($exists) {
    echo "  内容：" . trim((string) file_get_contents($log)) . "\n";
}

echo "\n--- ③ 屏幕上的提示 ---\n";
echo "  「ViceCode 致命错误」：" . $has('ViceCode 致命错误') . "\n";
echo "  「若终端仍不正常」：" . $has('若终端仍不正常') . "\n";

@unlink($log);

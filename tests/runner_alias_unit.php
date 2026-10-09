<?php
declare(strict_types=1);
// runner 命令运行器应认 shell 别名（如 ll）。runner 只 source「别名定义文件」
// （/etc/profile.d/colorls.sh 系统别名 + ~/.bash_aliases 用户别名），不 source
// 整个 ~/.bashrc（在 WSL/conda/nvm 系统上初始化 ~700ms，会拖垮流式命令）。
// ~/.bash_aliases 是 bash 读取用户别名的标准位置，确定性验证，不依赖用户真实 rc。
require __DIR__ . '/../vendor/autoload.php';

use App\Terminal\CommandRunner;

$tmp = sys_get_temp_dir() . '/vc_rt_' . uniqid();
mkdir($tmp);
file_put_contents($tmp . '/.bash_aliases', "alias vc_lltest='echo VC_ALIAS_OK'\n");
$origHome = getenv('HOME');
putenv('HOME=' . $tmp);

$cr = new CommandRunner();
$failed = false;
function check(bool $c, string $m): void {
    global $failed;
    echo ($c ? '  [OK] ' : '  [FAIL] ') . $m . "\n";
    if (!$c) $failed = true;
}

if (!$cr->start('vc_lltest', null)) {
    echo "  [FAIL] 起不了进程\n";
    $failed = true;
} else {
    $buf = '';
    $t0 = microtime(true);
    while ($cr->isRunning() && microtime(true) - $t0 < 3) {
        $cr->poll(static function (string $b, bool $e) use (&$buf): void { $buf .= $b; });
        usleep(10000);
    }
    $cr->poll(static function (string $b, bool $e) use (&$buf): void { $buf .= $b; });
    check(str_contains($buf, 'VC_ALIAS_OK'), 'runner 认 ~/.bash_aliases 别名（ll 类）');
    // 常规命令仍正常
    $cr2 = new CommandRunner();
    $cr2->start('echo plain_ok; printf "%s" "$(echo sub)"', null);
    $buf2 = '';
    $t1 = microtime(true);
    while ($cr2->isRunning() && microtime(true) - $t1 < 3) {
        $cr2->poll(static function (string $b, bool $e) use (&$buf2): void { $buf2 .= $b; });
        usleep(10000);
    }
    $cr2->poll(static function (string $b, bool $e) use (&$buf2): void { $buf2 .= $b; });
    check(str_contains($buf2, 'plain_ok') && str_contains($buf2, 'sub'), 'runner 常规命令/替换仍正常');
}

putenv('HOME=' . $origHome);
array_map('unlink', glob($tmp . '/*') ?: []);
@rmdir($tmp);
echo $failed ? "RESULT: FAIL\n" : "RESULT: PASS\n";
exit($failed ? 1 : 0);

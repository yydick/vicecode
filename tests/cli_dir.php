<?php
declare(strict_types=1);

/**
 * CLI 目录参数解析单测（headless）：验证 resolveStartArg 的各分支。
 *
 *  - 无参数 / '.' → 默认当前目录（openFile/chdirTo 皆 null）
 *  - 选项（'-' 开头）→ 跳过
 *  - 目录 → chdirTo=realpath（相对路径也解析为绝对）
 *  - 可读文件 → openFile=该路径
 *  - 既非目录也非可读文件的路径 → 忽略（皆 null）
 *
 * 本文件 require 入口脚本，靠底部的「仅直接执行才进 TUI」守卫，require 时不会误启界面。
 * 运行：php tests/cli_dir.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../bin/vicecode.php'; // 仅定义函数，不启动 TUI（守卫）

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

echo "== resolveStartArg ==\n";

// 无参数 → 默认当前目录
$r = resolveStartArg(['vicecode.php']);
check($r['openFile'] === null && $r['chdirTo'] === null, '无参数 → 默认当前目录（皆 null）');

// '.' → 默认当前目录
$r = resolveStartArg(['vicecode.php', '.']);
check($r['openFile'] === null && $r['chdirTo'] === null, "'.' → 默认当前目录（皆 null）");

// 选项跳过 → 无位置参数则皆 null
$r = resolveStartArg(['vicecode.php', '--help']);
check($r['openFile'] === null && $r['chdirTo'] === null, "'--help' 选项被跳过 → 皆 null");

// 目录（真实存在的目录）→ chdirTo 为 realpath 绝对路径
$tmpDir = sys_get_temp_dir();
$r = resolveStartArg(['vicecode.php', $tmpDir]);
check($r['chdirTo'] === realpath($tmpDir), '目录参数 → chdirTo=' . realpath($tmpDir));

// 相对目录 → 解析为绝对 realpath
$rel = basename($tmpDir); // 通常不可靠；改用本仓库父目录的相对表示
$cwd = getcwd() ?: '.';
$relParent = '..' . DIRECTORY_SEPARATOR . basename($cwd); // 指向上一级同名目录，确保存在
$r = resolveStartArg(['vicecode.php', $relParent]);
check($r['chdirTo'] === realpath($relParent), '相对目录参数 → chdirTo 解析为绝对 realpath');

// 可读文件 → openFile
$r = resolveStartArg(['vicecode.php', __FILE__]);
check($r['openFile'] === __FILE__, '可读文件参数 → openFile=' . __FILE__);

// 既非目录也非可读文件的路径 → 忽略（皆 null）
$r = resolveStartArg(['vicecode.php', '/nonexistent_path_xyz_' . uniqid()]);
check($r['openFile'] === null && $r['chdirTo'] === null, '不存在的路径 → 忽略（皆 null）');

// 首个匹配位置参数优先：'--flag' 在前、目录在后 → 取目录
$r = resolveStartArg(['vicecode.php', '--flag', $tmpDir]);
check($r['chdirTo'] === realpath($tmpDir), '选项在前、目录在后 → 取目录参数');

echo $failed ? "\ncli_dir FAIL\n" : "\ncli_dir PASS\n";
exit($failed ? 1 : 0);

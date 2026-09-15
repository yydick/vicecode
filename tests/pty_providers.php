<?php
declare(strict_types=1);

/**
 * 用户级模型（provider）配置 —— 真实 pty 验收。
 *
 * 为什么必须走 pty：headless 已覆盖**语义**（合并规则 / 语法错保留原配置 / 热重载保住选择，
 * 见 `tests/provider_user_unit.php`），但这条链路的**接线**只有真实运行才验证得到：
 *   1) 启动时用户配置文件真的被读到，并一路显示到状态栏（`label/model` 段）；
 *   2) 在编辑器里对**这份文件**按 Ctrl+S 会走 EditorPanel 的保存钩子 → `App::reloadProviders()`
 *      → 状态栏出现重载回执（`file.save` 菜单路径与 Ctrl+S 是同一条路）。
 *
 * 断言手法：**重建最终帧**再匹配（`vc_rebuild_screen`，见 tests/lib/pty_screen.php）。
 * 差分渲染只重发变化格、同一行还会被拆成多次「定位+写入」，直接对累积流做子串匹配会踩坑
 * （实测状态栏 `Model config reloaded` 在流里成了 `modelconfig` + `eloaded`）。
 * 终端还原序列那条断言必须看**原始流**（转义序列不进字符网格）。
 * 文案用 en 包（ASCII），避免中文在重建里的宽度歧义。
 *
 * 运行：timeout 120 php tests/pty_providers.php
 */

chdir(__DIR__ . '/..');
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/isolation.php';
require __DIR__ . '/lib/pty_screen.php';

use App\Core\ConfigStore;

const W = 120;
const H = 40;

$cfgFile = vc_isolate_config('vc_providers_pty');     // 含 VICECODE_PROVIDERS_CONFIG
$provPath = ConfigStore::providersPath();

// 用户配置：只覆盖内置 openai 的 label（其余字段沿用内置），这样
// 「状态栏 AI 段」会显示 ZZGWONE/<内置默认模型>，无需按 Ctrl+P 就能观察到是否加载成功。
file_put_contents($provPath, "<?php\nreturn ['openai' => ['label' => 'ZZGWONE']];\n");

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
$env = array_merge(getenv(), [
    'COLUMNS' => (string) W,
    'LINES' => (string) H,
    'APP_LOCALE' => 'en',
    'TERM' => 'xterm-256color',
    'VICECODE_CONFIG' => $cfgFile,
    'VICECODE_PROVIDERS_CONFIG' => $provPath,
    'OPENAI_API_KEY' => 'test-key-not-used',       // 只为了避免「缺 key」提示干扰画面
]);
// 首个位置参数 = 初始打开的文件 → 编辑器直接载入**这份 provider 配置文件**，且 openFile 会聚焦编辑器
$proc = proc_open([PHP_BINARY, 'bin/vicecode.php', $provPath], $descs, $pipes, getcwd(), $env);
if ($proc === false) {
    echo "[FAIL] 无法启动 bin/vicecode.php\n";
    exit(1);
}
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

$out = '';
/** 边收边判：重建当前最终帧，等到归一化后的屏幕里出现 $needle 或超时 */
$waitFor = static function (string $needle, float $seconds) use ($pipes, $readPty, &$out): bool {
    $deadline = microtime(true) + $seconds;
    while (true) {
        $r = [$pipes[1], $pipes[2]];
        $w = $e = [];
        if (stream_select($r, $w, $e, 0, 50000) > 0) {
            foreach ([1, 2] as $fd) {
                $chunk = $readPty($pipes[$fd], 8192);
                if (is_string($chunk) && $chunk !== '') {
                    $out .= $chunk;
                }
            }
        }
        if (str_contains(vc_rebuild_screen($out, W, H), $needle)) {
            return true;
        }
        if (microtime(true) >= $deadline) {
            return false;
        }
    }
};
$feed = static function (string $bytes) use ($pipes): void {
    fwrite($pipes[0], $bytes);
};

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

// ── 1) 启动：用户配置已加载并显示在状态栏 ──
echo "== 真实 pty：启动即加载用户级 provider 配置 ==\n";
$loaded = $waitFor('zzgwone', 8.0);          // 状态栏 AI 段：ZZGWONE/gpt-4o-mini
check($loaded, '最终帧里状态栏 AI 段显示用户配置的 label（ZZGWONE）——用户文件在启动时真的被读到');
// 阴性断言的正向锚点就是上一条；这里证明重载回执不是启动就有的
check(!str_contains(vc_rebuild_screen($out, W, H), 'modelconfigreloaded'), '未保存前不出现「已重载」回执（回执只由保存触发）');

// ── 2) Ctrl+S：保存这份文件 → 走热重载路径 ──
echo "== 真实 pty：编辑器里 Ctrl+S 保存该文件 → 热重载回执 ==\n";
$feed("\x13");                               // 0x13 = Ctrl+S；openFile 已把焦点放在编辑器上
$reloaded = $waitFor('modelconfigreloaded', 8.0);
check($reloaded, 'Ctrl+S 保存 provider 配置文件 → 状态栏出现「模型配置已重载」回执（热重载接线生效）');
check(str_contains(vc_rebuild_screen($out, W, H), 'zzgwone'), '重载后 provider 仍指向用户配置（没把配置弄丢）');

// ── 3) 干净退出 ──
echo "== 干净退出 ==\n";
$feed("\x11");                               // Ctrl+Q
$deadline = microtime(true) + 8;
$empty = 0;
while ($empty < 3 && microtime(true) < $deadline) {
    $got = false;
    foreach ([1, 2] as $fd) {
        $chunk = $readPty($pipes[$fd], 8192);
        if (is_string($chunk) && $chunk !== '') {
            $out .= $chunk;
            $got = true;
        }
    }
    $empty = $got ? 0 : $empty + 1;
    if (!proc_get_status($proc)['running'] && $empty >= 1) {
        continue;                               // 进程已退出：再多读几轮把尾巴收干净（还原序列在最后）
    }
    usleep(40000);
}
$st = proc_get_status($proc);
$code = $st['running'] ? -1 : (int) $st['exitcode'];
if ($st['running']) {
    proc_terminate($proc, SIGKILL);
}
proc_close($proc);

check($code === 0, "干净退出 exit=$code");
check(!str_contains($out, 'Fatal error') && !str_contains($out, 'Uncaught'), '无 Fatal / Uncaught');
// 这条必须看**原始流**：转义序列不进字符网格
check(str_contains($out, "\x1b[?1049l"), '退出仍还原终端（未破坏既有退出链路）');

@unlink($provPath);
echo $failed ? "\nRESULT: FAIL\n" : "\nRESULT: PASS\n";
exit($failed ? 1 : 0);

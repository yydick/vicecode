<?php
declare(strict_types=1);

/**
 * 模型能力开关 —— 真实 pty 验收。
 *
 * 验证「无 tools 能力的模型」在真实终端上确实**看得见**：
 *  1) 把 config/providers.php 临时换成一个 gpt-4o-mini 只声明 ['reasoning'] 的版本 →
 *     状态栏 AI 段应出现「无工具」标记，AI 面板空态应列出能力（推理）；
 *  2) 换回原配置（gpt-4o-mini 声明 ['tools']）→ 标记必须消失（**正反对照**，
 *     否则「标记出现」可能只是文案碰巧命中）。
 *
 * 纪律：禁用 plugins/（clock 每秒写数字会穿插状态栏断言）；解除差分渲染的顾虑一律用
 * 多字节感知的全屏重建；阴性断言都配了正向锚点（见 feedback §1.10）。
 *
 * 运行：timeout 120 php tests/pty_provider_caps.php
 */

chdir(__DIR__ . '/..');

require __DIR__ . '/../vendor/autoload.php';

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

const W = 200;
const H = 50;

// ── 临时替换 providers.php（真文件改名备份，还原用 rename，保证字节级一致）──
$providers = __DIR__ . '/../config/providers.php';
$backup = $providers . '.pty_caps_bak';
if (!rename($providers, $backup)) {
    echo "  [FAIL] 无法备份 config/providers.php\n";
    exit(1);
}
// 内置配置里的 provider 个数：决定第二段要按几次 Ctrl+P 才回到 ids[0]
$origIds = array_keys(require $backup);
$variantIds = ['openai'];   // 下面的变体只声明 openai
$restore = static function () use ($providers, $backup): void {
    if (is_file($backup)) {
        @unlink($providers);
        @rename($backup, $providers);
    }
};
register_shutdown_function($restore);

/** 变体：openai 的 gpt-4o-mini 只声明 reasoning（即不发 tools） */
file_put_contents($providers, <<<'PHP'
<?php
declare(strict_types=1);
return [
    'openai' => [
        'label'    => 'OpenAI',
        'key_env'  => 'OPENAI_API_KEY',
        'url_env'  => 'OPENAI_BASE_URL',
        'base_url' => 'https://api.openai.com/v1',
        'models'   => ['gpt-4o-mini' => ['reasoning']],
        'model'    => 'gpt-4o-mini',
    ],
];
PHP);

// ── 禁用插件：clock 数字会穿插状态栏断言（feedback §1.5）──
$pluginsDir = __DIR__ . '/../plugins';
$pluginsBackup = $pluginsDir . '.disabled_for_pty_caps';
if (is_dir($pluginsDir)) {
    rename($pluginsDir, $pluginsBackup);
    register_shutdown_function(static function () use ($pluginsDir, $pluginsBackup): void {
        if (is_dir($pluginsBackup)) {
            rename($pluginsBackup, $pluginsDir);
        }
    });
}

// 屏幕重建用**共享**实现（tests/lib/pty_screen.php）：本文件原先内联了一份自己的
// 重建器，多次重绘后会残留交错字符（实测把 `无工具` 拼成 `无M工具`、把状态栏两段
// 叠成 `标签焦资点源AISTREAM`），导致断言无故失败。共享版逐行归一化、行间保留 \n
// （「一个词」不可能跨行拼出来造成假阳性），是这类断言的正解。
//
// ⚠️ 它输出**已归一化**（小写、去标点空白、保留汉字）的文本，行间用 \n 连接，
// 所以断言里的针一律用小写字面量（如 'openaigpt4omini'）。
require __DIR__ . '/lib/pty_screen.php';

$readPty = static function ($stream, int $len) {
    set_error_handler(static fn () => true);
    try {
        return fread($stream, $len);
    } finally {
        restore_error_handler();
    }
};

/**
 * 起一轮应用：收首帧 → 可选发一段「前奏键」再收一帧 → 干净退出。
 *
 * ⚠️ 为什么要「前奏」：本用例断言的是「无工具」标记与能力行，而按 D12 起
 * **没显式选过 provider 就不显示模型名**，所以得先真的选一次（Tab×3 聚焦 AI 面板 +
 * Ctrl+P 切 provider）。首帧单独留一份，用来做「未选时不出现模型名」的阴性对照。
 *
 * @param array<int,array{0:string,1:int}> $prelude 每项 = [键字节, 等待微秒]
 * @return array{0:string,1:string,2:string,3:int} [首帧, 前奏后的帧, 原始流, 退出码]
 */
function runOnce(array $env, callable $readPty, array $prelude = []): array
{
    $descs = [0 => ['pty'], 1 => ['pty'], 2 => ['pty']];
    $proc = proc_open([PHP_BINARY, 'bin/vicecode.php'], $descs, $pipes, null, $env);
    if ($proc === false) {
        return ['', '', '', 1];
    }
    stream_set_blocking($pipes[0], false);
    stream_set_blocking($pipes[1], false);

    $drain = static function (bool $waitContent = true, float $timeoutMs = 3000.0) use ($pipes, $readPty): string {
        $acc = '';
        $empty = 0;
        $t0 = microtime(true);
        while (true) {
            $b = $readPty($pipes[1], 65536);
            if ($b === '' || $b === false) {
                $empty++;
            } else {
                $acc .= $b;
                $empty = 0;
            }
            if ($empty >= 4 && (!$waitContent || $acc !== '')) {
                break;
            }
            if ((microtime(true) - $t0) * 1000 > $timeoutMs) {
                break;
            }
            usleep(20000);
        }
        return $acc;
    };

    $raw = $drain();
    $frameBefore = vc_rebuild_screen($raw, W, H);

    foreach ($prelude as [$bytes, $us]) {
        fwrite($pipes[0], $bytes);
        usleep($us);
        $raw .= $drain();
    }
    $frameAfter = vc_rebuild_screen($raw, W, H);

    fwrite($pipes[0], "\x11");                 // Ctrl+Q
    $guard = 0;
    while (proc_get_status($proc)['running'] && $guard < 25) {
        usleep(200000);
        fwrite($pipes[0], "\x11");
        $guard++;
    }
    $raw .= $drain();
    foreach ($pipes as $p) {
        if (is_resource($p)) {
            fclose($p);
        }
    }
    $code = proc_close($proc);

    return [$frameBefore, $frameAfter, $raw, $code];
}

/**
 * 把焦点移到 AI 面板，并把 provider 选到**配置里的第一个**（= openai）。
 *
 * - Tab×3：无打开文件时 `completionContext()` 返回空串，Tab 落回全局焦点循环
 *   sidebar → editor → terminal → **ai_stream**。
 * - Ctrl+P：`cycleProvider()` 是**环形**的，从「未选」起按 k 次落到 `ids[k % n]`，
 *   所以按 n 次正好回到 `ids[0]`。**次数必须按当前配置的 provider 个数算**：
 *   本用例前后用的是两份不同配置（变体 1 个 / 内置 3 个），写死 1 次会在第二段
 *   落到 deepseek 上（本轮踩过）。
 *
 * @param string[] $ids 当前配置里的 provider id 列表
 * @return array<int,array{0:string,1:int}>
 */
$selectFirstProvider = static function (array $ids): array {
    $seq = [["\t", 200000], ["\t", 200000], ["\t", 200000]];
    for ($i = 0; $i < count($ids); $i++) {
        $seq[] = ["\x10", 500000];
    }
    return $seq;
};

// ⚠️ 配置目录必须独立：ChatStore 按 dirname(VICECODE_CONFIG) 找存档，配置放 /tmp 根会读到
// 别的测试留下的 /tmp/.vicecode_ai，于是「空态」根本不是空的（本轮踩过：能力行断言失败）。
$tmpDir = sys_get_temp_dir() . '/vc_caps_pty_' . getmypid();
@mkdir($tmpDir, 0700, true);
register_shutdown_function(static function () use ($tmpDir): void {
    @unlink($tmpDir . '/.vicerc');
    @unlink($tmpDir . '/.vicecode_ai');
    @rmdir($tmpDir);
});

$env = array_merge(getenv(), [
    'COLUMNS'          => (string) W,
    'LINES'            => (string) H,
    'TERM'             => 'xterm-256color',
    'APP_LOCALE'       => 'zh_CN',
    'VICECODE_CONFIG'  => $tmpDir . '/.vicerc',
    'VICECODE_PROVIDERS_CONFIG' => $tmpDir . '/.vicecode.providers.php',   // 断言的是内置配置，别被开发机的用户配置覆盖
    'OPENAI_API_KEY'   => 'test-key-not-used',   // 只要有 key，别触发「缺 key」提示
]);

echo "== 真实 pty：无 tools 能力的模型要被看见 ==\n";
// 未选状态先留一份凭证（阴性对照），再真的选一次 provider。
[$beforeA, $frameA, $rawA, $codeA] = runOnce($env, $readPty, $selectFirstProvider($variantIds));
check(str_contains($beforeA, '未选'), '未选时状态栏写「未选」而不是编一个模型名');
check(!str_contains($beforeA, 'openaigpt4omini'), '未选时画面上**不出现**默认模型名（阴性对照）');
check(str_contains($frameA, 'openaigpt4omini'), '选中后 OpenAI/gpt-4o-mini 出现（正向锚点）');
check(str_contains($frameA, '无工具'), '无 tools 能力 → 状态栏带「无工具」标记');
check(str_contains($frameA, '能力') && str_contains($frameA, '推理'), 'AI 面板空态列出能力（推理）');
check(str_contains($frameA, '工具调用') === false, '无工具模型的能力行里不出现「工具调用」');
check($codeA === 0, 'Ctrl+Q 退出码为 0（实际 ' . $codeA . '）');

// ── 换回原配置（gpt-4o-mini 声明 tools）→ 标记必须消失 ──
$restore();
check(is_file($providers) && !is_file($backup), 'providers.php 已还原（备份文件不残留）');

echo "== 真实 pty：有 tools 能力的模型不该有标记（正反对照）==\n";
// 第二段用的是**内置**配置（3 个 provider），要按 3 次 Ctrl+P 才回到 ids[0]=openai
[$beforeB, $frameB, $rawB, $codeB] = runOnce($env, $readPty, $selectFirstProvider($origIds));
check(!str_contains($beforeB, 'openaigpt4omini'), '未选时仍不显示模型名（阴性对照）');
check(str_contains($frameB, 'openaigpt4omini'), '选中后 OpenAI/gpt-4o-mini 出现（正向锚点）');
check(!str_contains($frameB, '无工具'), '有 tools 能力 → 状态栏不带「无工具」标记');
check(str_contains($frameB, '工具调用'), 'AI 面板能力行含「工具调用」');
check($codeB === 0, 'Ctrl+Q 退出码为 0（实际 ' . $codeB . '）');

$all = $rawA . $rawB;
check(!str_contains($all, 'Fatal') && !str_contains($all, 'Uncaught'), '两轮输出无 Fatal / Uncaught');
check(!preg_match('/PHP (Warning|Notice)/', $all), '两轮输出无 PHP Warning / Notice');

echo $failed ? "\npty 模型能力验收 FAIL\n" : "\npty 模型能力验收全部 PASS\n";
exit($failed ? 1 : 0);

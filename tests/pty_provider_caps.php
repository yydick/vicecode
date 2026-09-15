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

/** @return string[] 多字节感知的全屏重建（每格一个「字素」，宽字符占 2 列） */
function rebuildScreenLines(string $raw, int $w, int $h): array
{
    $grid = array_fill(0, $h, array_fill(0, $w, ' '));
    $r = 0;
    $c = 0;
    $len = strlen($raw);
    $i = 0;
    while ($i < $len) {
        $ch = $raw[$i];
        if ($ch === "\x1b") {
            if (isset($raw[$i + 1]) && $raw[$i + 1] === '[') {
                $j = $i + 2;
                $params = '';
                while ($j < $len && !ctype_alpha($raw[$j]) && $raw[$j] !== '~') {
                    $params .= $raw[$j];
                    $j++;
                }
                $cmd = ($j < $len) ? $raw[$j] : '';
                $j++;
                $nums = array_map('intval', explode(';', $params === '' ? '1' : $params));
                switch ($cmd) {
                    case 'H':
                    case 'f':
                        $r = max(0, ($nums[0] ?? 1) - 1);
                        $c = max(0, ($nums[1] ?? 1) - 1);
                        break;
                    case 'A': $r = max(0, $r - ($nums[0] ?? 1)); break;
                    case 'B': $r = min($h - 1, $r + ($nums[0] ?? 1)); break;
                    case 'C': $c = min($w - 1, $c + ($nums[0] ?? 1)); break;
                    case 'D': $c = max(0, $c - ($nums[0] ?? 1)); break;
                    case 'J':
                        if (($nums[0] ?? 0) === 2 || ($nums[0] ?? 0) === 3) {
                            $grid = array_fill(0, $h, array_fill(0, $w, ' '));
                        }
                        break;
                    case 'K':
                        if (($nums[0] ?? 0) === 0) {
                            for ($k = $c; $k < $w; $k++) { $grid[$r][$k] = ' '; }
                        } elseif (($nums[0] ?? 0) === 1) {
                            for ($k = 0; $k <= $c; $k++) { $grid[$r][$k] = ' '; }
                        } elseif (($nums[0] ?? 0) === 2) {
                            for ($k = 0; $k < $w; $k++) { $grid[$r][$k] = ' '; }
                        }
                        break;
                }
                $i = $j;
                continue;
            }
            $i++;
            while ($i < $len && !ctype_alpha($raw[$i]) && $raw[$i] !== "\x1b") {
                $i++;
            }
            if ($i < $len) {
                $i++;
            }
            continue;
        }
        if ($ch === "\r") { $c = 0; $i++; continue; }
        if ($ch === "\n") { $r = min($h - 1, $r + 1); $c = 0; $i++; continue; }
        if ($ch === "\x00" || $ch === "\x08") { $i++; continue; }
        $len1 = 1;
        $b = ord($ch);
        if ($b >= 0xF0) { $len1 = 4; } elseif ($b >= 0xE0) { $len1 = 3; } elseif ($b >= 0xC0) { $len1 = 2; }
        $seq = substr($raw, $i, $len1);
        $cw = \App\Text\DisplayWidth::dispWidth($seq);
        if ($cw < 1) { $cw = 1; }
        if ($r >= 0 && $r < $h && $c >= 0 && $c < $w) {
            $grid[$r][$c] = $seq;
        }
        $c += $cw;
        if ($c >= $w) { $c = 0; $r = min($h - 1, $r + 1); }
        $i += $len1;
    }
    return array_map(static fn (array $row): string => implode('', $row), $grid);
}

/** 归一化（去空白标点，留字母数字与汉字）：差分渲染会把同行文本拆成多段写入 */
function norm(array $lines): string
{
    $t = implode("\n", $lines);
    $r = preg_replace('/[^a-zA-Z0-9\x{4e00}-\x{9fff}]/u', '', $t);
    return $r === null ? (string) preg_replace('/[^a-zA-Z0-9]/', '', $t) : $r;
}

$readPty = static function ($stream, int $len) {
    set_error_handler(static fn () => true);
    try {
        return fread($stream, $len);
    } finally {
        restore_error_handler();
    }
};

/** 起一轮应用、收首帧后干净退出，返回 [归一化帧, 原始流, 退出码] */
function runOnce(array $env, callable $readPty): array
{
    $descs = [0 => ['pty'], 1 => ['pty'], 2 => ['pty']];
    $proc = proc_open([PHP_BINARY, 'bin/vicecode.php'], $descs, $pipes, null, $env);
    if ($proc === false) {
        return ['', '', 1];
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

    return [norm(rebuildScreenLines($raw, W, H)), $raw, $code];
}

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
    'OPENAI_API_KEY'   => 'test-key-not-used',   // 只要有 key，别触发「缺 key」提示
]);

echo "== 真实 pty：无 tools 能力的模型要被看见 ==\n";
[$frameA, $rawA, $codeA] = runOnce($env, $readPty);
check(str_contains($frameA, norm(['OpenAI/gpt-4o-mini'])), '首帧状态栏显示 OpenAI/gpt-4o-mini（正向锚点）');
check(str_contains($frameA, '无工具'), '无 tools 能力 → 状态栏带「无工具」标记');
check(str_contains($frameA, '能力') && str_contains($frameA, '推理'), 'AI 面板空态列出能力（推理）');
check(str_contains($frameA, '工具调用') === false, '无工具模型的能力行里不出现「工具调用」');
check($codeA === 0, 'Ctrl+Q 退出码为 0（实际 ' . $codeA . '）');

// ── 换回原配置（gpt-4o-mini 声明 tools）→ 标记必须消失 ──
$restore();
check(is_file($providers) && !is_file($backup), 'providers.php 已还原（备份文件不残留）');

echo "== 真实 pty：有 tools 能力的模型不该有标记（正反对照）==\n";
[$frameB, $rawB, $codeB] = runOnce($env, $readPty);
check(str_contains($frameB, norm(['OpenAI/gpt-4o-mini'])), '首帧状态栏仍显示 OpenAI/gpt-4o-mini（正向锚点）');
check(!str_contains($frameB, '无工具'), '有 tools 能力 → 状态栏不带「无工具」标记');
check(str_contains($frameB, '工具调用'), 'AI 面板能力行含「工具调用」');
check($codeB === 0, 'Ctrl+Q 退出码为 0（实际 ' . $codeB . '）');

$all = $rawA . $rawB;
check(!str_contains($all, 'Fatal') && !str_contains($all, 'Uncaught'), '两轮输出无 Fatal / Uncaught');
check(!preg_match('/PHP (Warning|Notice)/', $all), '两轮输出无 PHP Warning / Notice');

echo $failed ? "\npty 模型能力验收 FAIL\n" : "\npty 模型能力验收全部 PASS\n";
exit($failed ? 1 : 0);

<?php
declare(strict_types=1);

/**
 * 测试隔离：给每个测试一个**独占目录**放配置与存档，并保证临时文件/目录自动清理。
 *
 * ⚠️ 为什么不能用 `tempnam(sys_get_temp_dir(), …)` 当配置路径：
 * ChatStore / SessionStore 的存档路径是 `dirname(VICECODE_CONFIG)/.vicecode_ai`（同 `.vicecode_session`），
 * 而 `tempnam()` 返回的是 `/tmp` 下的**文件**——`dirname()` 就是 `/tmp` 本身。
 * 于是所有这么写的测试共用 `/tmp/.vicecode_ai`：`aiPersist` 默认开启、`App` 构造末尾就会
 * `ChatModel::restore()`，后跑的测试直接**恢复了前一个测试的对话**（表现为「空态不是空的」、
 * 断言用的消息条数/行号整体平移）。目录不独立时这类串档还会随跑批顺序变化，属于偶发假阴性。
 *
 * ⚠️ 同样的道理适用于**不设配置**的测试：`ConfigStore` 在没有 `VICECODE_CONFIG` 时会回落到
 * 真实家目录，于是测试会读开发机上的 `~/.vicerc`（布局/主题/语言）与 `~/.vicecode.plugins.json`。
 * 实测：把 `~/.vicerc` 换成非默认布局 + 主题语言、`~/.vicecode_ai` 放一份对话，就有 6 个测试挂
 * （主题环、菜单标签、GIT tab、AI 折行宽、侧栏横滚命中、翻页边界）——即「只在我这台机器上绿」。
 * 所以任何 `new App()` 的测试都应当先调 `vc_isolate_config()`。
 *
 * 用法：
 *   require __DIR__ . '/lib/isolation.php';
 *   vc_isolate_config('vc_ai');                     // 独占 .vicerc 与插件配置，shutdown 自动清理
 *   $cfg = vc_isolate_config('vc_ai');               // 需要给 pty 子进程显式传时取返回值
 *   $env = ['VICECODE_CONFIG' => $cfg, ...];
 *   $f = vc_tmp_file('vc_data', '.txt');             // 独占临时文件（自动清理）
 *   $d = vc_tmp_dir('vc_tree');                      // 独占临时目录（自动创建 + 自动清理）
 */

/** 造一个未被占用的独占目录路径（**不创建**；供需要自己控制目录生命周期的用例使用） */
function vc_isolation_dir(string $tag): string
{
    return rtrim(sys_get_temp_dir(), '/\\') . '/' . $tag . '_' . getmypid() . '_' . bin2hex(random_bytes(4));
}

/**
 * 登记退出时清理的路径（文件或目录，目录递归删）。进程内只注册一次清理器，后进先出。
 * 独立成类是为了让「登记表」有确定的归属，而不是散在 `$GLOBALS` 里。
 */
final class VcTemp
{
    /** @var list<string> */
    private static array $paths = [];

    private static bool $registered = false;

    /** 登记一个退出时要删的路径，原样返回（便于链式使用） */
    public static function track(string $path): string
    {
        self::$paths[] = $path;
        if (!self::$registered) {
            self::$registered = true;
            register_shutdown_function([self::class, 'cleanup']);
        }
        return $path;
    }

    /** 清理全部登记路径（测试正常退出与 `exit()` 都会走到 shutdown） */
    public static function cleanup(): void
    {
        foreach (array_reverse(self::$paths) as $p) {
            is_dir($p) ? self::removeDir($p) : @unlink($p);
        }
        self::$paths = [];
    }

    /** 递归删目录 */
    public static function removeDir(string $dir): void
    {
        // ⚠️ 不能用 glob("$dir/*")：glob 默认**不匹配点号开头的文件**，而配置（.vicerc）
        // 与存档（.vicecode_ai / .vicecode_session）全是隐藏文件 → 一个都删不掉、
        // rmdir 因目录非空失败，/tmp 里就留下一堆残留目录（实测踩过 15 个）。
        foreach ((array) scandir($dir) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = $dir . '/' . $f;
            is_dir($p) ? self::removeDir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}

/**
 * 隔离配置：建独占目录，把 `VICECODE_CONFIG` 与 `VICECODE_PLUGINS_CONFIG` 都指进去，
 * 返回 `.vicerc` 的绝对路径（pty 用例把它塞进子进程 env 即可，父子同源）。
 *
 * 插件配置一并隔离很重要：只隔离 `VICECODE_CONFIG` 时，`new App()` 仍会去读开发机的
 * `~/.vicecode.plugins.json`（实测你机器上就有 clock 的 format/timezone 覆盖）。
 *
 * @param string $tag      目录名前缀，用测试名便于残留时定位（如 `vc_ai_copy`）
 * @param string $fileName 配置文件名，默认 `.vicerc`（与生产一致）
 */
function vc_isolate_config(string $tag, string $fileName = '.vicerc'): string
{
    $dir = VcTemp::track(vc_isolation_dir($tag));
    @mkdir($dir, 0700, true);
    $cfg = $dir . '/' . $fileName;
    putenv('VICECODE_CONFIG=' . $cfg);
    putenv('VICECODE_PLUGINS_CONFIG=' . $dir . '/.vicecode.plugins.json');
    return $cfg;
}

/**
 * 独占临时文件（自动清理）。`$suffix` 给需要扩展名的场景（如 `.php` / `.txt`）。
 * 替代裸 `tempnam()`：走它才不会在 /tmp 堆垃圾。
 */
function vc_tmp_file(string $tag, string $suffix = ''): string
{
    $f = tempnam(sys_get_temp_dir(), $tag);
    if ($f === false) {
        throw new RuntimeException('tempnam() 失败：' . $tag);
    }
    if ($suffix !== '') {
        $withSuffix = $f . $suffix;
        @rename($f, $withSuffix);
        return VcTemp::track($withSuffix);
    }
    return VcTemp::track($f);
}

/** 独占临时目录（自动创建 + 自动清理）。替代「`sys_get_temp_dir().'/name_'.getmypid()` + mkdir」 */
function vc_tmp_dir(string $tag): string
{
    $d = vc_isolation_dir($tag);
    @mkdir($d, 0700, true);
    return VcTemp::track($d);
}

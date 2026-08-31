<?php
declare(strict_types=1);

namespace App\Search;

use App\Terminal\Ansi;

/**
 * 搜索客户端：命令构造 + 输出解析，全是静态纯函数（无 I/O，可单测）。
 *
 * 与 GitClient 同构的分层理由：单测只喂样例字符串给 buildCommand / parseLine，
 * 不起子进程、不触 grep。真正的「跑命令」在 SearchModel 里交给 CommandRunner。
 *
 * 为什么不用 GitClient::exec()：那是原生阻塞 @exec，git status 很快所以能接受，
 * 但搜索可能跑几秒，阻塞式执行会卡死整个事件循环，违背 M4/R2「搜索期间界面仍可操作」。
 */
final class SearchClient
{
    /**
     * 默认排除目录。
     *
     * 不排除的话本项目 vendor/ 一次搜索就能匹配出成千上万条，侧栏直接不可用。
     * 做成常量而非配置：本次 R1–R3 不引入配置项；将来要让用户改，
     * 照 Config::loadPhp 的既有范式加 config/search.php 即可，解析层不用动。
     */
    public const DEFAULT_EXCLUDE_DIRS = [
        '.git', 'vendor', 'node_modules', 'dist', 'build',
        '.idea', '.vscode', '.svn', '.cache', '__pycache__', 'target',
    ];

    /**
     * 结果总数硬上限。
     *
     * grep 的 -m 只能限「每个文件」的命中数，限不住总数，所以总数上限只能在解析层数着截断
     * （见 SearchModel::ingestLine）。达到上限就 kill 掉 grep，省下无谓的遍历。
     */
    public const MAX_MATCHES = 2000;

    /**
     * 构造 grep 命令。目标固定为 "."，真实搜索根由 CommandRunner::start($cmd, $cwd) 的 $cwd 决定。
     *
     * 选项逐个说明（都是有意选的，别随手改）：
     *  -r  递归子目录；
     *  -n  输出行号（解析依赖它，去掉就没有 path:line:text 了）；
     *  -I  跳过二进制文件。顺带消掉 "Binary file X matches" 噪声行。
     *      **不要**换成 -a：-a 会把二进制当文本吐出来，污染 TUI 渲染；
     *  -F  固定串匹配（字面量）。本次不做 R4 正则开关，用户期望就是「搜子串」，
     *      -F 还能让 . * ( 这类字符不被当正则元字符，也杜绝了正则注入；
     *      将来做 R4 时按开关切 -F / -E 即可，parseLine 完全不用动；
     *  -e  显式引入 pattern。这样即使 query 以 "-" 开头（如 "-foo"）也不会被 grep
     *      当成选项——escapeshellarg 只防 shell 层，防不了 grep 自己的参数解析。
     */
    public static function buildCommand(string $query): string
    {
        $cmd = 'grep -rnI -F -e ' . escapeshellarg($query);
        foreach (self::DEFAULT_EXCLUDE_DIRS as $dir) {
            $cmd .= ' --exclude-dir=' . escapeshellarg($dir);
        }
        return $cmd . ' .';
    }

    /**
     * 解析 grep 的一行 stdout（格式 path:line:text）。返回 null 表示不是命中行，忽略。
     *
     * explode 限 3 段是关键：text 里几乎一定会有冒号（`foo: bar`、URL、时间戳……），
     * 不限段数会把代码片段切碎。限 3 段后 text 无论含多少冒号都完整保留。
     *
     * 已知边界：假设路径本身不含冒号（Linux 工作区成立，与 GitClient 的同类假设一致）。
     * 若将来要支持含冒号的路径，得改用 `grep -Z`（NUL 分隔 path）并重写本函数。
     */
    public static function parseLine(string $raw): ?SearchHit
    {
        $line = rtrim(Ansi::sanitize($raw), "\n");
        if ($line === '') {
            return null;
        }
        // -I 理论上已经消掉了，仍挡一下：真出现时它不是命中行，混进结果会渲染出怪东西
        if (str_starts_with($line, 'Binary file ') && str_ends_with($line, ' matches')) {
            return null;
        }
        $parts = explode(':', $line, 3);
        if (count($parts) < 3) {
            return null; // 形如 "path:line" 或压根没冒号，不是合法命中行
        }
        [$path, $lineNo, $text] = $parts;
        if (!ctype_digit($lineNo)) {
            return null; // 中间段不是数字 → 不是 grep -n 的命中行（可能是某种提示信息）
        }
        // 搜索目标是 "."，故 grep 输出一律带 "./" 前缀（实测 `./src/a.php:1:...`）。
        // 剥掉它：侧栏窄，两个字符很值钱；而且 openFile 用相对路径当 buffer key，
        // 带不带 "./" 会被当成两个不同 buffer，同一文件可能开出两个标签。
        if (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }
        return new SearchHit($path, (int) $lineNo, $text);
    }

    /**
     * 从累积的 stderr 里取第一条有效错误，供状态栏单行展示。
     *
     * grep 遇到没权限的目录会为每一个都打一行（`grep: ./x: Permission denied`），
     * 几十行全塞进状态栏没意义；取第一条 + 剥掉 "grep: " 前缀即可表达"哪里出了问题"。
     */
    public static function firstErrorLine(string $stderr): string
    {
        foreach (explode("\n", Ansi::sanitize($stderr)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, 'grep: ')) {
                $line = substr($line, 6);
            }
            return $line;
        }
        return '';
    }

    /**
     * 按文件分组（保持首现顺序）。
     *
     * SearchModel 走的是增量分组（边读边聚，不必等命令跑完），这个纯函数版本
     * 提供同样的语义给单测用，两边的行为约定由测试锁住。
     *
     * @param SearchHit[] $hits
     * @return SearchGroup[]
     */
    public static function group(array $hits): array
    {
        /** @var array<string,SearchGroup> $byPath */
        $byPath = [];
        foreach ($hits as $hit) {
            $byPath[$hit->path] ??= new SearchGroup($hit->path, []);
            $byPath[$hit->path]->hits[] = $hit;
        }
        return array_values($byPath);
    }
}

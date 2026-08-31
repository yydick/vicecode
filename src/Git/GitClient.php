<?php
declare(strict_types=1);

namespace App\Git;

/**
 * GIT 客户端：纯解析函数（无 I/O，可单测）+ 执行封装。
 *
 * 解析函数与「怎么跑命令」解耦——单测只喂样例字符串给 parse*，不触协程 / 不触 git。
 * 执行封装 exec() 在协程内走 Swoole\Coroutine\System::exec（不阻塞 reactor），
 * 非协程上下文（headless 单测里直接验证解析时不会调到它）回退 proc_open 同步执行。
 *
 * porcelain v1 状态行格式：XY <path>（XY 为两字符索引/工作区状态）。
 *   X = 暂存区（index）状态，Y = 工作区（worktree）状态。
 *   常见：M 修改 / A 新增 / D 删除 / R 重命名 / C 拷贝 / U 未合并 / ? 未跟踪 / ! 忽略。
 */
final class GitClient
{
    public const STATUS_STAGED = 'staged';
    public const STATUS_MODIFIED = 'modified';
    public const STATUS_UNTRACKED = 'untracked';
    public const STATUS_RENAMED = 'renamed';
    public const STATUS_DELETED = 'deleted';
    public const STATUS_CONFLICT = 'conflict';
    public const STATUS_IGNORED = 'ignored';

    /**
     * 解析 `git status --porcelain` 输出。
     * @return GitFileStatus[]
     */
    public static function parseStatusPorcelain(string $out): array
    {
        $result = [];
        $lines = preg_split('/\r\n|\n|\r/', $out) ?: [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            // 重命名/拷贝行：R  <old> -> <new>，path 段含 " -> "
            $xy = substr($line, 0, 2);
            $rest = substr($line, 3); // 跳过 "XY "
            $index = $xy[0] === ' ' ? '' : $xy[0];
            $work = $xy[1] === ' ' ? '' : $xy[1];

            $old = null;
            $path = $rest;
            if (str_contains($rest, ' -> ')) {
                [$old, $path] = explode(' -> ', $rest, 2);
            }

            $result[] = new GitFileStatus(
                path: $path,
                indexStatus: $index,
                workStatus: $work,
                oldPath: $old,
            );
        }
        return $result;
    }

    /**
     * 解析 `git log --pretty=format:%h|%an|%ar|%s` 输出。
     * @return GitCommit[]
     */
    public static function parseLog(string $out): array
    {
        $result = [];
        $lines = preg_split('/\r\n|\n|\r/', $out) ?: [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $parts = explode('|', $line, 4);
            if (count($parts) < 4) {
                continue;
            }
            $result[] = new GitCommit(
                hash: $parts[0],
                author: $parts[1],
                relDate: $parts[2],
                subject: $parts[3],
            );
        }
        return $result;
    }

    /** 解析 `git rev-parse --abbrev-ref HEAD`（去掉尾部换行；detached 时返回原串） */
    public static function parseBranch(string $out): string
    {
        $b = trim($out);
        return $b === '' ? '(none)' : $b;
    }

    /**
     * 解析 `git branch --format=%(refname:short)` 输出，返回本地分支名列表（不含当前标记）。
     * @return string[]
     */
    public static function parseBranches(string $out): array
    {
        $result = [];
        $lines = preg_split('/\r\n|\n|\r/', $out) ?: [];
        foreach ($lines as $line) {
            $b = trim($line);
            if ($b !== '') {
                $result[] = $b;
            }
        }
        return $result;
    }

    /**
     * 执行 git 子命令，返回 ['code'=>int,'output'=>string]。
     *
     * 统一走原生 exec（切到仓库目录再跑，2>&1 合并错误输出）：
     *  - 本工程在 bin/vicecode.php 刻意关闭 SWOOLE_HOOK_PROC（否则 proc_close 返回值被改写、
     *    headless 测试驱动不了 runner），而 Coroutine\System::exec 依赖该 HOOK，
     *    关掉后会返回 false → refresh 全失败、branch 永远空。故不依赖 System::exec。
     *  - 在协程内（M5 异步刷新）这是阻塞调用，但 refresh() 自身跑在独立 \go 子协程里，
     *    只阻塞该子协程、不卡主循环；git 命令很快，UI 无感。
     *  - 非协程（单测）直接同步跑，行为一致。
     *
     * @param string[] $args
     */
    public static function exec(string $cwd, array $args): array
    {
        $cmd = 'git ' . implode(' ', array_map('escapeshellarg', $args));
        $full = 'cd ' . escapeshellarg($cwd) . ' && ' . $cmd . ' 2>&1';
        $out = @exec($full, $lines, $code);
        unset($out);
        return ['code' => (int) $code, 'output' => implode("\n", $lines)];
    }
}

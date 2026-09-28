<?php
declare(strict_types=1);

namespace App\Ai;

/**
 * 两条协议共用的 **curl 子进程 + 非阻塞管道** 传输骨架。
 *
 * ## 为什么是 trait 而不是各写一份
 * 这段代码里有三处是**安全/正确性关键**，重复两份意味着「改了一处忘了另一处」：
 *  1. **API key 不能进 argv**：命令行参数对同机其他用户可见（`ps`）。故请求头写进 0600
 *     临时文件，用 `-H @file` 让 curl 自己读。
 *  2. **请求体也不能进 argv**：长对话 body 动辄几十上百 KB，会撞 ARG_MAX。走 `--data-binary @file`。
 *  3. **临时文件的生命周期**：curl 是**启动后**才读这两个文件的，必须等进程退出才能删，
 *     提前删会拿到空请求体。故由调用方在收尾/中断/退出时调 `cleanup()`。
 *
 * ## 为什么走 curl 子进程而不是 Swoole 协程 curl
 * 实测（`docs/swoole_study.md` §9、探针 `tests/sse_probe*.php`）：
 * `SWOOLE_HOOK_NATIVE_CURL` 会**静默吞掉** `CURLOPT_WRITEFUNCTION`；`curl_multi` 在该 hook 下
 * 直接段错误；关掉 hook 后 `curl_exec` 是阻塞调用，会卡死整个协程调度器（界面僵死）。
 * 子进程 + 非阻塞管道是本项目唯一同时满足「流式」与「不阻塞 UI」的已验证范式。
 *
 * ## `-D <meta>` 的用处
 * 把响应头单独 dump 到文件（响应体照旧走 stdout 流式输出）。没有它，401/429 这类错误
 * 在界面上只表现为「回复为空」，看不出是鉴权失败还是模型真的没话说。
 */
trait CurlSseTransport
{
    /** @var array<string,string> role => path，本次请求创建的临时文件，cleanup() 负责删除 */
    private array $tmpFiles = [];

    /**
     * 组装完整的 curl 命令（stdout 即流式响应体）。
     *
     * ⚠️ 调用方负责把 $body / $headers 内容**完整**给出（含结尾换行与否）：
     * 本方法只做落盘与拼命令行，不解析内容。
     *
     * @param string $body     请求体（已 json_encode 的字符串）
     * @param string $headers  完整请求头文本（每行一条，行尾 `\n`）—— **key 在这里，不在 argv**
     */
    protected function curlc(string $url, string $body, string $headers, int $timeout): string
    {
        $this->cleanup(); // 上一次请求若没清干净，先清掉，避免临时文件泄漏

        $bodyFile = $this->writeTemp('body', $body);
        $hdrFile  = $this->writeTemp('header', $headers);
        $metaFile = $this->writeTemp('meta', '');

        return implode(' ', [
            'curl',
            '-N',                    // 关掉 curl 自己的输出缓冲，否则流式退化成一次性返回
            '-sS',                   // 静默但保留错误信息
            '--max-time', (string) max(1, $timeout),
            '-X', 'POST',
            // 注意用 escapeshellarg 包住整个 `@路径`：既处理路径里的空格，也不破坏 `@` 前缀语义
            '-H', escapeshellarg('@' . $hdrFile),
            '-D', escapeshellarg($metaFile),
            '--data-binary', escapeshellarg('@' . $bodyFile),
            escapeshellarg($url),
        ]);
    }

    /**
     * 从 `-D` 的 dump 文件里取 HTTP 状态码；读不到返回 null（进程还没写/已被删）。
     * 只看**第一个**状态行：重定向时文件里会有多段响应头，取首段才是最终跳转前的状态，
     * 实际上这两家都不会重定向，取首段足够且行为可预测。
     */
    public function httpStatus(string $metaFile): ?int
    {
        if (!is_file($metaFile)) {
            return null;
        }
        $raw = (string) @file_get_contents($metaFile);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^HTTP\/[\d.]+\s+(\d{3})/m', $raw, $m) === 1) {
            return (int) $m[1];
        }
        return null;
    }

    /** 响应头 dump 文件路径（cleanup() 会删它，故只能在 cleanup() 之前读） */
    public function metaFile(): ?string
    {
        return $this->tmpFiles['meta'] ?? null;
    }

    /** 删除本次请求创建的所有临时文件。可重复调用。 */
    public function cleanup(): void
    {
        foreach ($this->tmpFiles as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        $this->tmpFiles = [];
    }

    public function __destruct()
    {
        $this->cleanup();
    }

    /** 建一个 0600 权限的临时文件并写入内容，返回路径 */
    private function writeTemp(string $role, string $contents): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'vcai');
        @chmod($path, 0600);
        file_put_contents($path, $contents);
        $this->tmpFiles[$role] = $path;
        return $path;
    }

    /**
     * 剥掉消息里的本项目私有键（`meta`），保证 wire 上只有协议字段。
     * meta 是给渲染/复制用的（工具摘要、summary 标记），发出去既浪费带宽也可能被端点拒收。
     *
     * 与协议无关，故两条协议共用（放在 trait 里而不是某个 provider 上）。
     * @param array<int,array<string,mixed>> $messages
     * @return array<int,array<string,mixed>>
     */
    public static function stripMeta(array $messages): array
    {
        $out = [];
        foreach ($messages as $m) {
            if (!is_array($m)) {
                continue;
            }
            unset($m['meta']);
            $out[] = $m;
        }
        return $out;
    }
}

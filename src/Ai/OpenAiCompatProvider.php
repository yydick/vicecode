<?php
declare(strict_types=1);

namespace App\Ai;

/**
 * OpenAI 兼容协议的 Provider（M5 R1/R2）。
 *
 * ## 为什么走 `curl` 子进程而不是 Swoole 协程 curl
 * 实测（`docs/swoole_study.md` §9、探针 `tests/sse_probe*.php`）：
 *  - `SWOOLE_HOOK_NATIVE_CURL` 会**静默吞掉** `CURLOPT_WRITEFUNCTION`（不报错，只是没数据）；
 *  - `curl_multi` 在该 hook 下直接**段错误**；
 *  - 关掉 hook 后 libcurl 能流式，但 `curl_exec` 是阻塞调用，会卡死整个协程调度器
 *    （表现：请求期间 TUI 完全僵死）。
 * 故沿用 M2（终端命令）/ M4（搜索）同一套 `CommandRunner`：子进程 + 非阻塞管道 +
 * 主循环每轮 poll()。好处是传输层零新代码，HTTPS 交给 curl，「停止生成」就是 `cancel()`。
 *
 * ## 为什么要临时文件（两个）
 *  - **请求体**：长对话的 body 动辄几十上百 KB，塞进 argv 会撞 ARG_MAX。
 *  - **请求头**：API key 不能出现在 argv —— 命令行参数对同机其他用户可见（`ps`）。
 *    用 `-H @file` 让 curl 自己读文件，key 就不进进程参数表。
 * 代价是临时文件的生命周期要自己管：curl 是在启动后才读这两个文件的，
 * **必须等进程退出才能删**，提前删会拿到空请求体。故由调用方在收尾/中断/退出时调 cleanup()。
 *
 * ## `-D <meta>` 的用处
 * 把响应头单独 dump 到文件（响应体照旧走 stdout 流式输出）。没有它，401/429 这类
 * 错误在界面上只表现为「回复为空」，看不出是鉴权失败还是模型真的没话说。
 */
final class OpenAiCompatProvider
{
    /** 单次请求总超时（秒）。流式响应可能很长，给得宽一些。 */
    public const DEFAULT_TIMEOUT = 300;

    /** @var array<string,string> role => path，本次请求创建的临时文件，cleanup() 负责删除 */
    private array $tmpFiles = [];

    /**
     * 构造可直接交给 `CommandRunner::start()` 的 shell 命令。
     *
     * @param array<int,array{role:string,content:string}> $messages
     */
    public function buildCommand(ProviderSpec $spec, array $messages, int $timeout = self::DEFAULT_TIMEOUT): string
    {
        $this->cleanup(); // 上一次请求若没清干净，先清掉，避免临时文件泄漏

        $body = json_encode([
            'model'    => $spec->model,
            'messages' => $messages,
            'stream'   => true,
        ], JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            $body = '{"model":"","messages":[],"stream":true}';
        }

        $bodyFile = $this->writeTemp('body', $body);
        $hdrFile = $this->writeTemp('header',
            "Authorization: Bearer " . ($spec->apiKey ?? '') . "\n"
            . "Content-Type: application/json\n"
            . "Accept: text/event-stream\n"
        );
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
            escapeshellarg($spec->chatUrl()),
        ]);
    }

    /**
     * 从 `-D` 的 dump 文件里取 HTTP 状态码；读不到返回 null（进程还没写/已被删）。
     * 只看**第一个**状态行：重定向时文件里会有多段响应头，取首段才是最终跳转前的状态，
     * 实际上 OpenAI 系不会重定向，取首段足够且行为可预测。
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
}

<?php
declare(strict_types=1);

namespace App\Ai;

/**
 * 一家 LLM 服务商的**传输实现**（协议层），与「配置解析」（ProviderRegistry/ProviderSpec）分离。
 *
 * ## 为什么要这层接口
 * 在接入 Claude 之前，`ChatModel` 直接 `new OpenAiCompatProvider()`——具体类型、写死协议。
 * 而 OpenAI 兼容协议与 Anthropic Messages API 的差异**不止端点路径**：
 * 请求体形状（`max_tokens` 必填 / `system` 是顶层参数）、消息形状
 * （`tool_calls`+`role:tool` vs `tool_use`+`tool_result` 内容块）、流事件名
 * （`choices[].delta` vs `content_block_delta`）全都不一样。
 * 把这些差异关在这层接口后面，`ChatModel` 的状态机（流式累积、Agent loop、压缩、存档）
 * **一行都不用改**，两条协议共享同一套已验证过的控制流。
 *
 * ## 契约里为什么有 curl 专属的 `httpStatus` / `metaFile`
 * 本项目的流式传输只有一条已验证的路：curl 子进程 + 非阻塞管道 + 每轮 poll
 * （Swoole 协程 curl 是坏的，见 `docs/swoole_study.md` §9 与 OpenAiCompatProvider 顶部说明）。
 * `-D <file>` 把响应头 dump 到文件、再由 `httpStatus()` 读回状态码，是「鉴权失败」与
 * 「模型没话说」能被区分开的唯一手段。故这组方法属于接口契约，而不是某个实现的细节。
 *
 * ⚠️ **临时文件的生命周期是硬约束**：curl 是在启动后才读请求体/请求头文件的，
 * 必须等进程退出才能删（提前删会拿到空请求体）。所以 `cleanup()` 由调用方在
 * 收尾 / 中断 / 退出时显式调用，`metaFile()` 也只能在 `cleanup()` 之前读。
 */
interface ProviderInterface
{
    /**
     * 单次请求总超时（秒）。流式响应可能很长，给得宽一些。
     *
     * 定义在**接口**上而不是某个实现里：`ChatModel` 不该为了拿超时值去引用某个具体实现
     * （那也是耦合）。接口常量可通过实现类名访问（`OpenAiCompatProvider::DEFAULT_TIMEOUT`
     * 依然有效），故既有调用点零改动。
     */
    public const DEFAULT_TIMEOUT = 300;

    /**
     * 构造可直接交给 `CommandRunner::start()` 的 shell 命令（stdout 走流式响应体）。
     *
     * @param array<int,array<string,mixed>>      $messages 本项目内部消息形状（含私有 `meta` 键，发送前必须剥掉）
     * @param array<int,array<string,mixed>>|null $tools    工具定义；null = 不带。注意形状是**本项目的中立形状**，
     *                                                      各实现负责转成自己的协议形状（OpenAI 原样、
     *                                                      Anthropic 转 `input_schema`）。
     * @return string 完整 shell 命令
     */
    public function buildCommand(ProviderSpec $spec, array $messages, int $timeout, ?array $tools): string;

    /**
     * 从 `-D` dump 文件里取 HTTP 状态码；读不到返回 null（进程还没写 / 已被删）。
     * 必须在 `cleanup()` 之前调用。
     */
    public function httpStatus(string $metaFile): ?int;

    /** 响应头 dump 文件路径（`cleanup()` 会删它，故只能在 `cleanup()` 之前读） */
    public function metaFile(): ?string;

    /** 删除本次请求创建的所有临时文件。可重复调用。 */
    public function cleanup(): void;
}

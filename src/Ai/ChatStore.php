<?php
declare(strict_types=1);

namespace App\Ai;

/**
 * AI 对话存档（V2）：把 ChatModel 的 messages + provider/model 落盘，重启恢复。
 *
 * ## 与 PTY 会话（Terminal\SessionStore）同款哲学
 *  - 路径隔离：设了 VICECODE_CONFIG → `dirname/.vicecode_ai`（测试不污染真实家目录）；
 *    否则 `$HOME/.vicecode_ai`（Windows 回退 $USERPROFILE）。
 *  - 隐私：对话内容可能含敏感信息，写后 chmod 0600（仅属主可读写）。
 *  - 全程容错：文件缺失 / JSON 损坏 / 消息形状非法一律返回 null「不恢复」，绝不抛异常。
 *
 * ## 写盘时机（不在每个 token 后写）
 * finish() 收尾（成功/失败都写）、clear() 清档、shutdown() 退出前。
 *
 * ## 消息形状校验
 * messages 必须是 list 且每条有 string role + content 键（tool 消息/meta 原样保留——
 * meta 是本项目私有键，恢复后渲染/复制语义不丢）。
 */
final class ChatStore
{
    /** 对话存档文件名 */
    public const FILE_NAME = '.vicecode_ai';

    /** 返回存档绝对路径（与 SessionStore 同款隔离规则） */
    public static function path(): string
    {
        $override = getenv('VICECODE_CONFIG');
        if (is_string($override) && $override !== '') {
            return rtrim(dirname($override), '/\\') . '/' . self::FILE_NAME;
        }
        $home = getenv('HOME');
        if ($home === false || $home === '') {
            $home = getenv('USERPROFILE'); // Windows
        }
        if ($home === false || $home === '') {
            $home = sys_get_temp_dir();
        }
        return rtrim($home, '/\\') . '/' . self::FILE_NAME;
    }

    /**
     * 写入对话存档。失败静默返回 false（不抛）。
     * @param array<int,array<string,mixed>> $messages
     * @param array{provider?:?string,model?:?string} $meta
     */
    public static function save(array $messages, array $meta = []): bool
    {
        $file = self::path();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $data = [
            'version'  => 1,
            'savedAt'  => time(),
            'provider' => is_string($meta['provider'] ?? null) ? $meta['provider'] : null,
            'model'    => is_string($meta['model'] ?? null) ? $meta['model'] : null,
            'strategy' => is_string($meta['strategy'] ?? null) ? $meta['strategy'] : null,
            'messages' => $messages,
        ];
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }
        if (@file_put_contents($file, $json) === false) {
            return false;
        }
        @chmod($file, 0600); // 隐私：仅属主可读写
        return true;
    }

    /**
     * 读取对话存档；不存在 / 损坏 / 消息形状非法返回 null。
     * @return array{messages:array<int,array<string,mixed>>,provider:?string,model:?string,strategy:?string,savedAt:int}|null
     */
    public static function load(): ?array
    {
        $file = self::path();
        if (!is_file($file)) {
            return null;
        }
        $txt = @file_get_contents($file);
        if ($txt === false) {
            return null;
        }
        $data = json_decode($txt, true);
        if (!is_array($data) || !isset($data['messages']) || !is_array($data['messages'])) {
            return null;
        }
        // 消息形状校验：每条要有 string role 和 content 键（其余键——tool_calls/meta——原样保留）
        foreach ($data['messages'] as $m) {
            if (!is_array($m) || !is_string($m['role'] ?? null) || !array_key_exists('content', $m)) {
                return null;
            }
        }
        return [
            'messages' => $data['messages'],
            'provider' => is_string($data['provider'] ?? null) ? $data['provider'] : null,
            'model'    => is_string($data['model'] ?? null) ? $data['model'] : null,
            'strategy' => is_string($data['strategy'] ?? null) ? $data['strategy'] : null,
            'savedAt'  => is_int($data['savedAt'] ?? null) ? $data['savedAt'] : 0,
        ];
    }

    /** 删除存档（Ctrl+L 清空时同步清档）。失败静默返回 false。 */
    public static function clear(): bool
    {
        $file = self::path();
        if (!is_file($file)) {
            return true;
        }
        return @unlink($file);
    }
}

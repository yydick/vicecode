<?php
declare(strict_types=1);

namespace App\Terminal;

/**
 * 交互式 PTY 会话快照存取（仅纯文本 + 启动 cwd）。
 *
 * 落盘隐私：PTY 滚动历史可能含密码/令牌。默认不开启（由 ConfigStore::persistSession 控制），
 * 开启后文件权限设为 0600（仅属主可读写）。
 *
 * 路径隔离（与 pty 测试 VICECODE_CONFIG 一致）：
 *  - 设了 VICECODE_CONFIG → 取 `dirname(该路径)/.vicecode_session`，使快照与配置同目录、随测试隔离；
 *  - 否则 `$HOME/.vicecode_session`（Windows 回退 $USERPROFILE）。
 *
 * 读写全程容错：文件缺失 / JSON 损坏 / 字段缺失都返回 null，调用方据此「不恢复」，绝不抛异常。
 */
final class SessionStore
{
    /** 会话快照文件名 */
    public const FILE_NAME = '.vicecode_session';

    /**
     * 返回会话快照绝对路径。
     */
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
     * 写入会话快照。失败静默返回 false（不抛）。
     * @param array{cwd:string,text:string,savedAt:int,cols?:int,rows?:int} $data
     */
    public static function save(array $data): bool
    {
        $file = self::path();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
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
     * 读取会话快照；不存在 / 损坏 / 缺关键字段返回 null。
     * @return array{cwd:string,text:string,savedAt:int}|null
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
        if (!is_array($data) || !isset($data['cwd']) || !isset($data['text']) || !is_string($data['cwd']) || !is_string($data['text'])) {
            return null;
        }
        return [
            'cwd' => $data['cwd'],
            'text' => $data['text'],
            'savedAt' => isset($data['savedAt']) && is_int($data['savedAt']) ? $data['savedAt'] : 0,
        ];
    }

    /** 删除快照文件（恢复成功后清理，避免下次启动重复恢复）。失败静默返回 false。 */
    public static function clear(): bool
    {
        $file = self::path();
        if (!is_file($file)) {
            return true;
        }
        return @unlink($file);
    }
}

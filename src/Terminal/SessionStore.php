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
     * @param array{cwd:string,savedAt:int,mode?:string,text?:string,cells?:array,lines?:array} $data
     *   mode 缺省 'pty'。pty：cells(彩色)/text(纯文本回退) 至少其一；
     *   runner：lines（输出缓冲 [text,err] 元组）。
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
     * 返回 mode（缺省 'pty'）+ 对应负载：pty 优先 cells 再 text；runner 取 lines。
     * @return array{cwd:string,savedAt:int,mode:string,cells?:array,text?:string,lines?:array}|null
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
        if (!is_array($data) || !isset($data['cwd']) || !is_string($data['cwd'])) {
            return null;
        }
        $mode = isset($data['mode']) && is_string($data['mode']) ? $data['mode'] : 'pty';
        $result = [
            'cwd' => $data['cwd'],
            'savedAt' => isset($data['savedAt']) && is_int($data['savedAt']) ? $data['savedAt'] : 0,
            'mode' => $mode,
        ];
        if (isset($data['cells']) && is_array($data['cells'])) {
            $result['cells'] = $data['cells'];
        }
        if (isset($data['text']) && is_string($data['text'])) {
            $result['text'] = $data['text'];
        }
        if (isset($data['lines']) && is_array($data['lines'])) {
            $result['lines'] = $data['lines'];
        }
        // 校验：runner 必须有 lines；pty 须有 cells 或 text
        if ($mode === 'runner') {
            if (!isset($result['lines'])) {
                return null;
            }
        } elseif (!isset($result['cells']) && !isset($result['text'])) {
            return null;
        }
        return $result;
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

<?php
declare(strict_types=1);

namespace App\Core;

/**
 * 用户配置文件读写（R7）：把可持久化的偏好（布局尺寸 / 主题 / 语言）存到 ~/.vicerc。
 *
 * 格式选 JSON 而非项目里 config/*.php 那种 PHP 数组 return——配置文件由程序**写入**，
 * 生成 JSON 比转义生成 PHP 代码简单且不会有注入/语法风险；人类也能直接编辑。
 *
 * 读取失败（文件缺失 / JSON 损坏 / 字段类型不对）一律降级为「空配置」，调用方走默认分支，
 * 绝不抛异常把整个应用卡死。写入同理容错：创建目录失败、磁盘满等都静默吞掉——
 * 偏好没存成只是下次启动不恢复，不该比实际功能更重要。
 *
 * 路径：默认 `~/.vicerc`；测试与沙箱用环境变量 `VICECODE_CONFIG` 覆盖，避免污染真实家目录。
 */
final class ConfigStore
{
    /** 配置文件名（家目录下） */
    public const FILE_NAME = '.vicerc';

    /** 插件专用配置文件名（与 .vicerc 分离：.vicerc 只存 ViceCode 自身配置） */
    public const PLUGINS_FILE_NAME = '.vicecode.plugins.json';

    /** 返回家目录（测试/沙箱用 HOME/USERPROFILE，缺失退回临时目录） */
    private static function homeDir(): string
    {
        $home = getenv('HOME');
        if ($home === false || $home === '') {
            $home = getenv('USERPROFILE'); // Windows
        }
        if ($home === false || $home === '') {
            $home = sys_get_temp_dir();
        }
        return rtrim($home, '/\\');
    }

    /** 返回配置文件绝对路径（VICECODE_CONFIG 优先，否则 $HOME/$USERPROFILE 下的 .vicerc） */
    public static function path(): string
    {
        $override = getenv('VICECODE_CONFIG');
        if (is_string($override) && $override !== '') {
            return $override;
        }
        return self::homeDir() . '/' . self::FILE_NAME;
    }

    /** 返回插件配置专用文件绝对路径（VICECODE_PLUGINS_CONFIG 优先，否则 ~/.vicecode.plugins.json） */
    public static function pluginsPath(): string
    {
        $override = getenv('VICECODE_PLUGINS_CONFIG');
        if (is_string($override) && $override !== '') {
            return $override;
        }
        return self::homeDir() . '/' . self::PLUGINS_FILE_NAME;
    }

    /**
     * 读取配置；任何异常都返回空数组。
     * @return array<string,mixed>
     */
    public static function load(): array
    {
        $file = self::path();
        if (!is_file($file)) {
            return [];
        }
        $txt = @file_get_contents($file);
        if ($txt === false) {
            return [];
        }
        $data = json_decode($txt, true);
        return is_array($data) ? $data : [];
    }

    /**
     * 是否持久化交互式 PTY 会话（opt-in，默认关闭）。
     *
     * 默认 false：滚动历史可能含密码/令牌，落盘有隐私风险，必须由用户显式开启。
     * 仅当配置中该键为真值时返回 true；缺失 / 类型不对一律 false。
     */
    public static function persistSession(): bool
    {
        $v = self::load()['persistSession'] ?? false;
        return $v === true || $v === 1 || $v === '1';
    }

    /**
     * 读取插件专用配置：整份文件即「插件 id => 配置」映射，无 plugins 包裹层。
     * 文件不存在时回退到旧版写在 .vicerc 的 plugins 段（一次性兼容，用户首次编辑后
     * 即写入专用文件）；两者皆无则返回空数组。
     * @return array<string,mixed>
     */
    public static function loadPlugins(): array
    {
        $file = self::pluginsPath();
        if (is_file($file)) {
            $txt = @file_get_contents($file);
            $data = $txt === false ? [] : json_decode($txt, true);
            return is_array($data) ? $data : [];
        }
        $legacy = self::load()['plugins'] ?? [];
        return is_array($legacy) ? $legacy : [];
    }

    /** 写入插件专用配置文件（整份即插件配置映射）；失败静默返回 false。 */
    public static function savePlugins(array $data): bool
    {
        $file = self::pluginsPath();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }
        return @file_put_contents($file, $json) !== false;
    }

    /**
     * 写入配置；失败静默返回 false（不抛）。
     * @param array<string,mixed> $data
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
        return @file_put_contents($file, $json) !== false;
    }
}

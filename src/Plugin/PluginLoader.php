<?php
declare(strict_types=1);

namespace App\Plugin;

/**
 * 运行时插件加载器（V1）。
 *
 * 扫描 <baseDir>/plugins/<id>/plugin.json，读取 entry/class 后**运行时 require**
 * 入口文件并 new $class()。刻意**不走 composer autoload**（其 Closure::bind 不被
 * AOT 支持），且 require 发生在方法体内（非文件级），符合 AOT 编码规范。
 *
 * 单个插件加载失败（JSON 损坏 / 类不存在 / 实例化抛错 / 不实现接口）仅跳过该插件，
 * 不中断整体加载。
 *
 * @return list<PluginInterface>
 */
final class PluginLoader
{
    /**
     * 扫描并实例化所有插件。无 plugins 目录或全失败时返回空数组。
     * @param string $baseDir 项目根目录（其下 plugins/ 为插件目录）
     */
    public static function load(string $baseDir): array
    {
        $plugins = [];
        $root = rtrim($baseDir, '/') . '/plugins';
        if (!is_dir($root)) {
            return $plugins;
        }
        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $metaPath = $dir . '/plugin.json';
            if (!is_file($metaPath)) {
                continue;
            }
            $meta = @json_decode((string) file_get_contents($metaPath), true);
            if (!is_array($meta)) {
                continue;
            }
            $class = $meta['class'] ?? null;
            if (!is_string($class) || $class === '') {
                continue;
            }
            $entry = $meta['entry'] ?? (str_contains($class, '\\')
                ? basename(str_replace('\\', '/', $class)) . '.php'
                : $class . '.php');
            $file = $dir . '/' . $entry;
            if (!is_file($file)) {
                continue;
            }
            // 运行时动态加载（非 composer autoload）：方法体内 require_once，
            // 不触发 AOT 对「文件级 require」的限制；class_exists(..., false)
            // 不自动加载（我们刚 require 了文件，应已存在）。
            require_once $file;
            if (!class_exists($class, false)) {
                continue;
            }
            try {
                /** @var mixed $plugin */
                $plugin = new $class();
            } catch (\Throwable $e) {
                continue;
            }
            if (!$plugin instanceof PluginInterface) {
                continue;
            }
            $plugins[] = $plugin;
        }
        return $plugins;
    }
}

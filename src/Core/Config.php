<?php
declare(strict_types=1);

namespace App\Core;

/**
 * 读取 `return` 数组的 PHP 配置文件（config/ 下的图标、语言包等）。
 *
 * 拆自 App::loadConfig()，与 Translator 内部那份加载逻辑是同一套；
 * M6 要做 ~/.tuirc 偏好持久化时，也在这里加「用户配置覆盖默认配置」。
 */
final class Config
{
    /** 文件缺失或返回的不是数组时给空数组，让调用方走默认分支。 @return array<string,mixed> */
    public static function loadPhp(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $data = require $file;
        return is_array($data) ? $data : [];
    }
}

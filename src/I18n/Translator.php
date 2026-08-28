<?php
declare(strict_types=1);

namespace App\I18n;

/**
 * 极简 i18n 翻译器。
 *
 * 语言包是 `config/locales/<locale>.php` 返回 `key => 字符串` 的数组。
 * 语言通过环境变量 APP_LOCALE 选择（默认 zh_CN）；缺失的 key 回退到英文包，
 * 再缺失则原样返回 key（便于发现遗漏）。
 *
 * 模板支持 {name} 占位替换：t('editor.too_large', ['size' => 123])。
 */
final class Translator
{
    /** @var array<string,string> */
    private array $messages;

    private string $locale;
    private const FALLBACK = 'en';        // 缺失 key 的兜底语言
    private const DEFAULT_LOCALE = 'zh_CN'; // 未设置 APP_LOCALE 时的默认界面语言

    public function __construct(string $locale, string $baseDir)
    {
        $this->locale = $locale;
        $this->messages = $this->load($baseDir, $locale);
        if ($locale !== self::FALLBACK) {
            // 英文作为兜底，保证任何语言下字符串都不为空
            $this->messages += $this->load($baseDir, self::FALLBACK);
        }
    }

    public static function fromEnv(string $baseDir): self
    {
        $loc = getenv('APP_LOCALE');
        if ($loc === false || $loc === '') {
            $loc = ($_SERVER['APP_LOCALE'] ?? '') ?: self::DEFAULT_LOCALE;
        }
        return new self($loc, $baseDir);
    }

    public function locale(): string
    {
        return $this->locale;
    }

    /** @param array<string,mixed> $params */
    public function t(string $key, array $params = []): string
    {
        $s = $this->messages[$key] ?? $key;
        if ($params !== [] && is_string($s)) {
            $s = preg_replace_callback('/\{(\w+)\}/', static function (array $m) use ($params): string {
                return array_key_exists($m[1], $params) ? (string) $params[$m[1]] : $m[0];
            }, $s);
        }
        return $s;
    }

    /** @return array<string,string> */
    private function load(string $baseDir, string $loc): array
    {
        $file = rtrim($baseDir, '/') . '/' . $loc . '.php';
        if (!is_file($file)) {
            return [];
        }
        $data = require $file;
        return is_array($data) ? $data : [];
    }
}

<?php
declare(strict_types=1);

namespace App\I18n;

use App\Core\Config;

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
    private string $baseDir;
    private const FALLBACK = 'en';        // 缺失 key 的兜底语言
    private const DEFAULT_LOCALE = 'zh_CN'; // 未设置 APP_LOCALE 时的默认界面语言

    public function __construct(string $locale, string $baseDir)
    {
        $this->locale = $locale;
        $this->baseDir = $baseDir;
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

    /**
     * 运行时切换语言（顶部菜单「视图 → 语言」用）。
     *
     * 重新加载目标语言包并合并英文兜底，与构造时完全一致——不保留旧语言残留条目。
     */
    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
        $this->messages = $this->load($this->baseDir, $locale);
        if ($locale !== self::FALLBACK) {
            $this->messages += $this->load($this->baseDir, self::FALLBACK);
        }
    }

    /** 当前已加载的语言包里所有 key（供 toggle 列出可选语言） */
    public function available(): array
    {
        // 已知两语言；后续新增语言包在这里加即可
        return ['zh_CN', 'en'];
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
        // 与 App 的 config 加载是同一套（Config::loadPhp），避免两份实现漂移
        return Config::loadPhp(rtrim($baseDir, '/') . '/' . $loc . '.php');
    }
}

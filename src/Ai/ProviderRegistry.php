<?php
declare(strict_types=1);

namespace App\Ai;

use App\Core\Config;

/**
 * 读 `config/providers.php` + 环境变量，产出 `ProviderSpec`。
 *
 * 除 key/base_url/模型列表外，还解析**模型能力**（`capabilities`，见该配置文件顶部的说明）：
 * 模型级声明优先于 provider 级默认，未声明一律按 `DEFAULT_CAPS`（= 支持 tools）。
 *
 * 构造时可注入配置数组（单测用），默认从文件读。
 *
 * ⚠️ **key 只从环境变量取，且不对外暴露**：`spec()` 返回的 ProviderSpec 里带着 key，
 * 但任何把它拼进日志/状态栏/命令行 argv 的地方都是事故——真实请求走临时文件传 header
 * （见 OpenAiCompatProvider），就是为了不让 key 出现在 `ps` 里。
 */
final class ProviderRegistry
{
    /**
     * 未声明能力时的默认能力：按历史行为视为**支持 tools**（老配置零迁移）。
     * 只有显式写了 `capabilities`（provider 级）或给模型写了能力列表，才按声明执行。
     */
    private const DEFAULT_CAPS = [ProviderSpec::TOOLS];

    /** @var array<string,array<string,mixed>> */
    private array $config;

    /** @param array<string,array<string,mixed>>|null $config 注入用（单测） */
    public function __construct(?array $config = null, ?string $configFile = null)
    {
        if ($config !== null) {
            $this->config = $config;
            return;
        }
        $file = $configFile ?? dirname(__DIR__, 2) . '/config/providers.php';
        /** @var array<string,array<string,mixed>> $loaded */
        $loaded = Config::loadPhp($file);
        $this->config = $loaded;
    }

    /** @return string[] */
    public function ids(): array
    {
        return array_keys($this->config);
    }

    /** 第一个 provider（配置为空时返回 null，调用方须处理「一个都没配」的情况） */
    public function defaultId(): ?string
    {
        return $this->ids()[0] ?? null;
    }

    public function has(string $id): bool
    {
        return isset($this->config[$id]);
    }

    /** 标签（UI 展示用，如 "OpenAI"） */
    public function label(string $id): string
    {
        $v = $this->config[$id]['label'] ?? null;
        return is_string($v) ? $v : $id;
    }

    /**
     * 解析出某个 provider 的完整规格。$model 为空则用配置里的默认模型。
     * 未知 id / 配置损坏 → null（调用方负责提示，不要静默 fallback 到别家）。
     */
    public function spec(string $id, ?string $model = null): ?ProviderSpec
    {
        $c = $this->config[$id] ?? null;
        if (!is_array($c)) {
            return null;
        }

        $label = is_string($c['label'] ?? null) ? $c['label'] : $id;

        $baseUrl = is_string($c['base_url'] ?? null) ? $c['base_url'] : '';
        $urlEnv = is_string($c['url_env'] ?? null) ? $c['url_env'] : null;
        if ($urlEnv !== null) {
            $override = getenv($urlEnv);
            if (is_string($override) && trim($override) !== '') {
                $baseUrl = trim($override);
            }
        }

        $keyEnv = is_string($c['key_env'] ?? null) ? $c['key_env'] : null;
        $apiKey = null;
        if ($keyEnv !== null) {
            $v = getenv($keyEnv);
            if (is_string($v) && trim($v) !== '') {
                $apiKey = trim($v);
            }
        }

        $models = [];
        $capsOf = [];   // 模型 id => 能力列表（保持声明顺序供 Ctrl+N 轮换）
        // provider 级默认能力：**显式写了 `capabilities` 就用它**（写 [] 表示确实没有能力），
        // 没写才落回 DEFAULT_CAPS。用 array_key_exists 而不是 ?? —— 要区分「没写」与「写了空」。
        $defaultCaps = array_key_exists('capabilities', $c)
            ? self::parseCaps($c['capabilities'])
            : self::DEFAULT_CAPS;
        foreach ((array) ($c['models'] ?? []) as $k => $v) {
            if (is_int($k)) {
                // 旧写法（纯模型名列表）：'models' => ['a', 'b'] —— 用 provider 默认能力
                if (!is_string($v) || trim($v) === '') {
                    continue;
                }
                $mid = trim($v);
                $caps = $defaultCaps;
            } else {
                // 新写法：'models' => ['a' => ['tools','vision'], 'b' => null]
                // 值写 null（或省略）表示「用 provider 默认能力」；写 [] 表示「确实没有任何能力」
                $mid = trim((string) $k);
                if ($mid === '') {
                    continue;
                }
                $caps = $v === null ? $defaultCaps : self::parseCaps($v);
            }
            if (isset($capsOf[$mid])) {
                continue;                       // 同名重复声明：保留先出现的那个
            }
            $models[] = $mid;
            $capsOf[$mid] = $caps;
        }
        $defModel = is_string($c['model'] ?? null) ? $c['model'] : ($models[0] ?? '');
        $chosen = $model ?? $defModel;
        if ($chosen !== '' && $models !== [] && !isset($capsOf[$chosen])) {
            $chosen = $defModel; // 配错的模型退回默认，避免拿到看不懂的 400
        }

        if ($baseUrl === '' || $chosen === '') {
            return null;
        }

        return new ProviderSpec(
            id: $id,
            label: $label,
            baseUrl: $baseUrl,
            apiKey: $apiKey,
            models: $models,
            model: $chosen,
            keyEnv: $keyEnv,
            capabilities: $capsOf[$chosen] ?? $defaultCaps,
        );
    }

    /**
     * 把配置里的能力值规范化成「去重后的字符串列表」。
     * 容忍几种写法：`['tools','vision']`、裸串 `'tools'`、含空串/非字符串的杂数组（丢弃）。
     * 不做白名单校验——写个新能力名（如 `'video'`）也接受，只是 UI 按原文显示，保证可前向扩展。
     */
    private static function parseCaps(mixed $raw): array
    {
        $out = [];
        foreach (is_array($raw) ? $raw : [$raw] as $v) {
            if (!is_string($v)) {
                continue;
            }
            $v = trim($v);
            if ($v === '' || in_array($v, $out, true)) {
                continue;
            }
            $out[] = $v;
        }
        return $out;
    }
}

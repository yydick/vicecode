<?php
declare(strict_types=1);

namespace App\Ai;

use App\Core\Config;

/**
 * 读 `config/providers.php` + 环境变量，产出 `ProviderSpec`。
 *
 * 构造时可注入配置数组（单测用），默认从文件读。
 *
 * ⚠️ **key 只从环境变量取，且不对外暴露**：`spec()` 返回的 ProviderSpec 里带着 key，
 * 但任何把它拼进日志/状态栏/命令行 argv 的地方都是事故——真实请求走临时文件传 header
 * （见 OpenAiCompatProvider），就是为了不让 key 出现在 `ps` 里。
 */
final class ProviderRegistry
{
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
        foreach ((array) ($c['models'] ?? []) as $m) {
            if (is_string($m) && $m !== '') {
                $models[] = $m;
            }
        }
        $defModel = is_string($c['model'] ?? null) ? $c['model'] : ($models[0] ?? '');
        $chosen = $model ?? $defModel;
        if ($chosen !== '' && $models !== [] && !in_array($chosen, $models, true)) {
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
        );
    }
}

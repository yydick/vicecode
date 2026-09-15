<?php
declare(strict_types=1);

namespace App\Ai;

use App\Core\Config;
use App\Core\ConfigStore;

/**
 * 读 `config/providers.php`（内置）+ `~/.vicecode.providers.php`（用户级）+ 环境变量，产出 `ProviderSpec`。
 *
 * **合并语义**：内置文件先读，用户文件按 **id 逐字段覆盖**（`array_replace`），用户文件里
 * 出现的**新 id 追加在后面**。因此「只想把 openai 指到自建网关」只需写一个 id + 几个字段，
 * 内置的 deepseek 自动保留；`models` 是**整字段覆盖**（写了就以用户的为准，不做并集）——
 * 语义是「看到什么就是什么」，代价是删不掉内置模型（那属于换 provider，不是覆盖）。
 * 注意 id 的**顺序**决定 `defaultId()`（= 第一个），内置永远在前。
 *
 * 除 key/base_url/模型列表外，还解析**模型能力**（`capabilities`，见内置配置顶部的说明）：
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

    /**
     * 保留段键名：模型策略定义（`@strategies`）。
     * 配置里所有 `@` 前缀的键都是**保留段**，不会被当成 provider id（见构造函数）。
     */
    public const STRATEGIES_KEY = '@strategies';

    /**
     * 保留的**策略名**：`auto` 表示"让系统按任务类型自己选"（循环切档里的一个档位）。
     * 用户配置里若真有一条叫 `auto` 的策略，会被丢弃——否则循环里会出现两个"自动"，
     * 用户分不清按了几次。
     */
    public const AUTO = 'auto';

    /** @var array<string,array<string,mixed>> */
    private array $config;

    /** @var array<string,ModelStrategy> 用户级配置里的模型策略（按声明顺序） */
    private array $strategies = [];

    /**
     * 用户级配置文件的加载问题：null = 正常（含"文件不存在"）。
     * 取值：`syntax`（语法错）/ `runtime`（require 时抛）/ `shape`（没 return 数组）/ `unreadable`。
     * 调用方（App::reloadProviders）据此提示「已保留原配置」——用户在自己编辑器里改的
     * 就是这份 PHP 文件，静默吞掉语法错误会让他以为改了没生效。
     */
    private ?string $userError = null;

    /** 实际读过的用户级配置路径（便于 UI/测试展示"配置在哪"） */
    private ?string $userFile = null;

    /**
     * @param array<string,array<string,mixed>>|null $config     注入的**内置**配置（单测用）
     * @param string|null $configFile 覆盖内置配置文件路径
     * @param string|null $userFile   用户级配置文件路径；**null = 只有在内置也是从文件读时才去看默认路径**
     *
     * ⚠️ `$config` 被注入（单测）且 `$userFile` 为 null 时**不读默认用户文件**——否则测试会
     * 读到开发机真实的 `~/.vicecode.providers.php`（同类坑见 tests/lib/isolation.php 顶部说明）。
     */
    public function __construct(?array $config = null, ?string $configFile = null, ?string $userFile = null)
    {
        if ($config !== null) {
            $this->config = $config;
        } else {
            $file = $configFile ?? dirname(__DIR__, 2) . '/config/providers.php';
            /** @var array<string,array<string,mixed>> $loaded */
            $loaded = Config::loadPhp($file);
            $this->config = $loaded;
            // 只有"内置也从文件读"时才落默认用户路径：注入配置的单测不该被家目录影响
            $userFile ??= ConfigStore::providersPath();
        }
        if ($userFile !== null && $userFile !== '') {
            $this->userFile = $userFile;
            $this->config = self::mergeUser($this->config, $this->loadUserFile($userFile));
        }
        // 拆出保留段（`@` 前缀）并**从 provider 表里摘掉**：否则 `@strategies` 会被当成一个
        // provider id，Ctrl+P 会切到它、`ids()` 里混进一个假服务商。
        $this->strategies = self::parseStrategies($this->config[self::STRATEGIES_KEY] ?? null);
        foreach (array_keys($this->config) as $k) {
            if (is_string($k) && str_starts_with($k, '@')) {
                unset($this->config[$k]);
            }
        }
    }

    /**
     * 可用策略（按配置里的声明顺序；里面每一项目标是否真的可用由 `ChatModel::applyStrategy()`
     * 在**切换时**校验——这样"策略写了但 provider/model 配错"能在菜单里看得见、点了有明确报错，
     * 而不是从列表里静默消失）。
     *
     * @return array<string,ModelStrategy>
     */
    public function strategies(): array
    {
        return $this->strategies;
    }

    /**
     * 解析 `@strategies` 段。**宽容**策略：
     *  - 非数组 / 缺 `provider`（或不是非空字符串）→ 丢弃（拿不到目标，留着也没用）；
     *  - `model` 非字符串 → 视为 null（用 provider 默认模型）；
     *  - `requires` 复用能力规范化（接受裸串 / 数组 / 去重去空）。
     * 目标 provider/model 是否存在**不在这里判**——留到 applyStrategy 时报错，见上。
     *
     * @return array<string,ModelStrategy>
     */
    private static function parseStrategies(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $name => $entry) {
            if (!is_string($name) || trim($name) === '' || !is_array($entry)) {
                continue;
            }
            if ($name === self::AUTO) {
                continue;                       // auto 是保留名（循环切档里的"自动"档位）
            }
            $provider = $entry['provider'] ?? null;
            if (!is_string($provider) || trim($provider) === '') {
                continue;
            }
            $label = $entry['label'] ?? null;
            $model = $entry['model'] ?? null;
            $out[$name] = new ModelStrategy(
                name: $name,
                label: is_string($label) && trim($label) !== '' ? trim($label) : $name,
                providerId: trim($provider),
                model: is_string($model) && trim($model) !== '' ? trim($model) : null,
                requires: self::parseCaps($entry['requires'] ?? null),
                kinds: self::parseCaps($entry['kinds'] ?? null),
            );
        }
        return $out;
    }

    /** 用户级配置路径（未启用/未读时为 null） */
    public function userFile(): ?string
    {
        return $this->userFile;
    }

    /** 用户级配置的加载问题（null = 正常）；见属性说明 */
    public function userError(): ?string
    {
        return $this->userError;
    }

    /**
     * 按 id 逐字段合并用户配置：内置先、用户覆盖；新 id 追加在末尾。
     *
     * @param array<string,mixed> $base
     * @param array<string,mixed> $user
     * @return array<string,mixed>
     */
    private static function mergeUser(array $base, array $user): array
    {
        foreach ($user as $id => $entry) {
            if (!is_string($id) || $id === '' || !is_array($entry)) {
                continue;                       // 脏条目直接跳过，不让它影响其它 provider
            }
            if (!isset($base[$id]) || !is_array($base[$id])) {
                $base[$id] = $entry;            // 新 provider：追加在末尾
                continue;
            }
            $base[$id] = array_replace($base[$id], $entry);   // 字段级覆盖（models 整表覆盖）
        }
        return $base;
    }

    /**
     * 加载用户级 PHP 配置文件，**出错不致命**。
     *
     * 这份文件支持「在应用内编辑 + 保存即重载」，用户手滑存个半截文件绝不能让整个应用崩掉。
     * 好消息是**不必自己预检语法**：实测（PHP 8.3）`require` 遇到解析错会抛**可捕获的
     * `ParseError`**（`include` 同样），且不会往 stdout/stderr 喷任何东西（`display_errors=1`
     * 也如此，不会污染 alternate screen）。所以直接 require + 分档 catch 即可：
     * `ParseError` → `syntax`（给用户更准确的提示），其余 Throwable → `runtime`。
     *
     * ⚠️ 别再加 `token_get_all($src, TOKEN_PARSE)` 预检那层：功能上冗余（上面已覆盖），
     * 多一次全文件扫描而已。（这条结论是实测出来的，不是推断的。）
     *
     * @return array<string,mixed>
     */
    private function loadUserFile(string $file): array
    {
        if (!is_file($file)) {
            return [];                          // 没写用户配置是最常见情况，不是错误
        }
        try {
            $data = require $file;
        } catch (\ParseError) {
            $this->userError = 'syntax';
            return [];
        } catch (\Throwable) {
            $this->userError = 'runtime';
            return [];
        }
        if (!is_array($data)) {
            $this->userError = 'shape';
            return [];
        }
        return $data;
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

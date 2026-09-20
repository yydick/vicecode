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

    /** 用户级模型（provider）配置文件名（与内置 config/providers.php 分离） */
    public const PROVIDERS_FILE_NAME = '.vicecode.providers.php';

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
     * 返回用户级模型（provider）配置文件的绝对路径。
     *
     * 优先级：`VICECODE_PROVIDERS_CONFIG` 环境变量 → `~/.vicecode.providers.php`。
     *
     * 为什么是 `.php` 而不是像插件配置那样的 JSON：这里的配置形状与内置
     * `config/providers.php` **完全一致**（含 `key_env` / `url_env` / `models` / `capabilities`），
     * 用户可以直接从内置文件拷一段过来改；PHP 还允许写注释，而这份文件的主要用途正是
     * 「教会用户怎么写」。代价是「应用内编辑 PHP 可能存出语法错误」——由
     * `ProviderRegistry` 的语法安全加载兜住（语法错则保留上一份好配置并提示）。
     */
    public static function providersPath(): string
    {
        $override = getenv('VICECODE_PROVIDERS_CONFIG');
        if (is_string($override) && $override !== '') {
            return $override;
        }
        return self::homeDir() . '/' . self::PROVIDERS_FILE_NAME;
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

    // ── AI V2 配置（.vicerc 的 "ai" 段；缺失/类型不对一律走默认值）──────

    /** 读 ai 段（顶层键 "ai"），非数组返回空 */
    private static function aiSection(): array
    {
        $v = self::load()['ai'] ?? [];
        return is_array($v) ? $v : [];
    }

    /** AI 对话自动存盘（默认开；Ctrl+L 清空时同步清档） */
    public static function aiPersist(): bool
    {
        $v = self::aiSection()['persist'] ?? true;
        return $v !== false && $v !== 0 && $v !== '0';
    }

    /** Agent 工具（只读 list_files/read_file）自动执行；false=逐次弹确认（默认自动） */
    public static function aiToolAutoRun(): bool
    {
        $v = self::aiSection()['toolAutoRun'] ?? true;
        return $v !== false && $v !== 0 && $v !== '0';
    }

    /** Agent loop 步数上限（一轮 send 起最多执行几轮工具调用，默认 8） */
    public static function aiMaxSteps(): int
    {
        $v = self::aiSection()['maxSteps'] ?? 8;
        return is_int($v) ? max(1, $v) : (is_numeric($v) ? max(1, (int) $v) : 8);
    }

    /** 上下文压缩触发阈值：估算 token 超过它才压缩（默认 24000，最小 10 便于测试/小模型） */
    public static function aiCompactThreshold(): int
    {
        $v = self::aiSection()['compactThreshold'] ?? 24000;
        return is_int($v) ? max(10, $v) : (is_numeric($v) ? max(10, (int) $v) : 24000);
    }

    /** 压缩后保留的最近消息条数（默认 6） */
    public static function aiCompactKeepRecent(): int
    {
        $v = self::aiSection()['compactKeepRecent'] ?? 6;
        return is_int($v) ? max(2, $v) : (is_numeric($v) ? max(2, (int) $v) : 6);
    }

    /** @文件/选区/当前文件 单次注入上限字节数（默认 64KB，超限截断并标注） */
    public static function aiAttachMaxBytes(): int
    {
        $v = self::aiSection()['attachMaxBytes'] ?? 65536;
        return is_int($v) ? max(1024, $v) : (is_numeric($v) ? max(1024, (int) $v) : 65536);
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

    /**
     * 插件是否启用：约定用插件自身配置对象里的保留键 `enabled`（见 docs/plugins.md 启用/禁用小节）。
     * 缺失视为启用（老配置零迁移）；显式 false / 0 / "0" 视为禁用；其余值视为启用。
     * @param array<string,mixed> $pluginsConfig ConfigStore::loadPlugins() 的结果
     */
    public static function pluginEnabled(array $pluginsConfig, string $id): bool
    {
        $sec = $pluginsConfig[$id] ?? null;
        if (!is_array($sec) || !array_key_exists('enabled', $sec)) {
            return true;
        }
        $v = $sec['enabled'];
        return $v !== false && $v !== 0 && $v !== '0';
    }

    /**
     * 持久化单个插件的启用状态：读出现有映射、只改该插件的 `enabled` 键、整份写回。
     * 保留该插件其它配置键与其它插件的配置（绝不整份覆盖），失败静默返回 false。
     */
    public static function savePluginEnabled(string $id, bool $enabled): bool
    {
        $all = self::loadPlugins();
        $sec = $all[$id] ?? null;
        if (!is_array($sec)) {
            $sec = [];
        }
        $sec['enabled'] = $enabled;
        $all[$id] = $sec;
        return self::savePlugins($all);
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
     * 用户级 provider 配置的**初始模板**（文件不存在时写入，供应用内编辑）。
     *
     * 只写注释 + `return [];`，**不写当前生效配置**：这份文件是「按 id 覆盖」语义，
     * 把内置那两条抄进来会变成"冻结当前版本"，以后内置更新（改默认模型/加能力）就被它盖住了。
     * 保持空数组 = 行为与没有这个文件完全一致，用户想改哪个再从注释里的例子抄。
     */
    public static function providersTemplate(): string
    {
        return <<<'PHP'
<?php
declare(strict_types=1);

/**
 * ViceCode 用户级模型（provider）配置。
 *
 * 与内置 `config/providers.php` 的关系：内置先读，这份文件按 **id 逐字段覆盖**，
 * 新 id 追加在后面。所以只想把 openai 指到自建网关时，只写这一个 id 和要改的字段即可，
 * 内置的 deepseek 会原样保留。`models` 是**整表覆盖**（写了就以这里为准，不做并集）。
 *
 * 密钥约定：key 只从环境变量读，**不要写进这个文件**（这个文件会被存进家目录）：
 *   {PREFIX}_API_KEY   必填，缺了 AI 面板会提示
 *   {PREFIX}_BASE_URL  可选，覆盖 base_url
 *
 * 例子（把 return 那一行换成下面这段即可，注意取消注释）：
 *
 *   return [
 *       // 覆盖内置 openai：只写要改的字段，其余（模型列表等）继续用内置的
 *       'openai' => [
 *           'base_url' => 'https://my-gateway.internal/v1',
 *       ],
 *       // 追加一条任何 OpenAI 兼容端点（OpenRouter / Kimi / 千问 / GLM / Ollama / vLLM / 自建网关）
 *       'my-gw' => [
 *           'label'    => '我的网关',
 *           'key_env'  => 'MYGW_API_KEY',
 *           'base_url' => 'http://127.0.0.1:11434/v1',
 *           'models'   => ['qwen2.5:14b' => ['tools']],
 *           'model'    => 'qwen2.5:14b',
 *       ],
 *   ];
 *
 * 保存后即时生效（Ctrl+S），无需重启；语法写错会提示并沿用上一份可用配置。
 * 改完回 AI 面板按 Ctrl+P 切 provider、Ctrl+N 切模型，确认新配置认到了。
 *
 * ── 协议：OpenAI 兼容 与 Anthropic 二选一（可选，默认 openai）──────────────
 *
 * **不写 `protocol` 就是 OpenAI 兼容协议**（老配置零迁移）。要接 Anthropic Messages API
 * 就写 `'protocol' => 'anthropic'`——两条协议**不是一回事**，差异由应用内部处理：
 *
 *   · 鉴权头     x-api-key（而非 Authorization: Bearer）+ 必需头 anthropic-version
 *   · 端点       <base_url>/v1/messages（而非 <base_url>/chat/completions）
 *   · max_tokens **必填**（缺了直接 400）→ 用 provider 的 `max_tokens` 声明，默认 4096
 *   · system     是**顶层参数**（Messages API 没有 system 角色；内部 system 消息会自动提上去）
 *   · 工具形状   {name, description, input_schema}（而非 {type:'function', function:{…}}）
 *   · 流式结束   是 message_stop 事件（**没有 `data: [DONE]`**）
 *
 * 内置已有一条 `anthropic`（只认 `ANTHROPIC_API_KEY`），通常直接用即可；下面是要改时的写法。
 * ⚠️ **模型 ID 必须逐字符准确**（点号/日期后缀写错 = `model not found`）：4.6 代及更新用
 * 无日期格式（`claude-sonnet-4-6`），4.5 代及更早需要完整日期后缀（`claude-haiku-4-5-20251001`）。
 * 以官方 `GET /v1/models` 的当前列表为准。
 *
 *   'anthropic' => [                                   // 覆盖内置那条（只写想改的字段）
 *       'protocol'   => 'anthropic',
 *       'base_url'   => 'https://my-gateway.internal', // 自建网关/代理；结尾不要带 /v1
 *       'max_tokens' => 8192,                          // 必填项，按模型上限调
 *       'models'     => ['claude-sonnet-4-6' => ['tools']],  // models 是整表覆盖
 *       'model'      => 'claude-sonnet-4-6',
 *   ],
 *
 * ── 模型策略（可选，见下面例子）──────────────────────────────────────────
 *
 * 把「这次要干什么」映射到一个具体模型，用于快速换档：
 *   · 在 AI 面板按 **Ctrl+R** 循环切换；
 *   · 或从菜单/命令面板的 AI 组里直接选（每条策略一个条目）；
 *   · 状态栏右侧显示当前策略（手动切 provider/模型会自动脱离策略，不会显示错的档位）。
 *
 * 可以写 `requires` 声明能力要求：切换时若目标模型不具备该能力会**拒绝并提示**。
 * 这条不是洁癖——Agent 工具依赖 `tools`，若"降级到便宜模型"撞上一个没声明 tools 的模型，
 * 表现是工具**静默失效**（不报错），用户只会觉得"Agent 怎么不动了"。
 *
 * 自动选档（按任务类型）：策略可以写 `kinds`，请求带上这些类型时**自动**用本档。
 * 任务类型只有两个来源，都不靠猜：① 快捷动作（explain / comment / refactor / unittest）；
 * ② 输入框开头的指令前缀，如 `/plan 帮我把这块重构一下`（前缀不发给模型；写了未知类型会拒绝发送
 * 并列出已知类型；想发字面量斜杠就写两个 `//`）。
 * **人工优先**：手动选档（Ctrl+R 或菜单里选某条）会**钉住**，自动选档暂停；Ctrl+R 循环里
 * 有一档「自动」可以切回去。
 *
 *   '@strategies' => [
 *       'plan'  => ['label' => '计划', 'provider' => 'deepseek', 'model' => 'deepseek-reasoner',
 *                   'kinds' => ['plan']],
 *       'grind' => ['label' => '干活', 'provider' => 'deepseek', 'model' => 'deepseek-chat',
 *                   'requires' => ['tools'], 'kinds' => ['comment', 'explain']],
 *   ],
 *
 * ── 折扣时段与成本档（可选，见下面例子）──────────────────────────────────
 *
 * 闲时想自动切到便宜的档位，就在 **provider** 上声明折扣时段与成本档（折扣是服务商的属性，
 * 多家策略共用一套时段）：
 *
 *   'my-gw' => [
 *       // ... 上面的 provider 字段 ...
 *       'cost' => 1,                                        // 整数，越小越便宜；不写 = 未知（排最后）
 *       'off_peak' => [                                     // 多条，任一命中即打折
 *           ['days' => ['sat', 'sun'], 'from' => '00:00', 'to' => '24:00'],
 *           ['days' => ['*'], 'from' => '23:30', 'to' => '08:30', 'tz' => '+08:00'],
 *       ],
 *   ],
 *
 *   · `days`    三字母缩写 mon..sun 或 '*'；省略 = 任意星期。**指窗口开始那一天**：
 *               ['sat'], 23:30→08:30 = 周六晚→周日早。
 *   · from/to   `HH:MM`，to 可写 24:00；from > to = 跨天，from === to = 全天。
 *   · tz        `±HH:MM` 偏移（如 '+08:00'）；省略 = 本机时区。
 *
 * 规则：任务类型（快捷动作 / /前缀）**优先**，它没命中时才看时段；人工选档会钉住、时段规则
 * 不介入；折扣结束**不主动切回**（没有"该回哪档"的确定答案，方向盘交回用户）。
 * 策略里写 `'auto_offpeak' => false` 可让某一档不参与闲时降档（如"计划档"）。
 *
 * 注：`@` 开头的键都是**保留段**，不会被当成 provider；策略名 `auto` 是保留的（循环里的"自动"档）；
 * 策略里 model 写错会拒绝切换，不会静默回退到别的模型。
 */

return [];
PHP;
    }

    /**
     * 写入用户级 provider 配置文件；失败静默返回 false（不抛）。
     *
     * 写失败也不该让编辑入口失败——文件没建出来时调用方仍会把编辑器指向该路径，
     * 用户手动保存即可（编辑器自己会报「无权限」）。
     */
    public static function saveProviders(string $content): bool
    {
        $file = self::providersPath();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return @file_put_contents($file, $content) !== false;
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

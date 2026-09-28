<?php
declare(strict_types=1);

/**
 * LLM Provider 定义（M5）。
 *
 * 设计要点：
 *  - **不存 key**：只存「从哪个环境变量读」，key 永远留在环境里，不入库、不落盘。
 *  - base_url 有默认值，同时允许用环境变量覆盖（自建网关/代理/兼容端点都靠它）。
 *  - `protocol` 决定走哪套协议：**不写 = `openai`**（OpenAI 兼容，`<base>/chat/completions`）；
 *    写 `'anthropic'` 走 Anthropic Messages API（`<base>/v1/messages`，见文件末尾那条）。
 *    刻意**不按 base_url 或 id 猜**：代理/镜像站的域名与 id 都不可靠，猜错的表现是
 *    「请求发出去了但端点拒绝」，比多写一行配置难查得多。
 *
 * ── 两条协议的差异（用不到 Anthropic 可跳过）──────────────────────────────
 *
 * Anthropic 的 Messages API 与 OpenAI 兼容协议**不是同一个协议**，差异不止端点路径：
 *  - 鉴权头是 `x-api-key`（不是 `Authorization: Bearer`），且 `anthropic-version` 是**必需**头；
 *  - `max_tokens` **必填**（缺了直接 400），故 provider 上可用 `max_tokens` 声明（默认 4096）；
 *  - **没有 `system` 角色**，系统提示是顶层 `system` 参数（本应用会自动把内部 system 消息提上去）；
 *  - 工具定义形状是 `{name, description, input_schema}`（不是 `{type:'function',function:{…}}`）；
 *  - 流式事件名不同（`content_block_delta` 等），且**结束是 `message_stop`、没有 `[DONE]`**。
 * 这些都已在 `AnthropicProvider` / `AnthropicSseParser` 里处理，配置侧只需写 `protocol`。
 *
 * 环境变量约定：
 *  - `{PREFIX}_API_KEY`   —— 必填，缺失时 AI 面板给出提示而不是静默失败
 *  - `{PREFIX}_BASE_URL`  —— 可选，覆盖下面的 base_url
 *
 * ── 模型能力（capabilities）：声明「这个模型能做什么」 ──────────────────────
 *
 * 写法（两种粒度，模型级优先）：
 *  - `capabilities`（provider 级）：该 provider 下**未单独声明**的模型的默认能力；不写 = `['tools']`。
 *  - `models` 里按模型声明：`'模型名' => ['tools', 'vision']`。
 *    · 值写 `null`（或省略）→ 用 provider 级默认；
 *    · 值写 `[]` → 确实没有任何能力（连 tools 也没有）；
 *    · 也兼容旧写法：`'models' => ['a', 'b']`（纯模型名列表 → 全部用 provider 默认能力）。
 *
 * 能力取值（写别的字符串也接受，只是 UI 按原文显示，方便前向扩展）：
 *  - `tools`      函数调用。**当前唯一影响行为的一项**：只有声明了 tools 的模型，请求里才会带
 *                 OpenAI `tools` 协议，Agent loop（list_files / read_file）才可能被调用。
 *                 纯推理模型（如 deepseek-reasoner）拿 tools 打过去通常会被服务商拒掉整轮，
 *                 所以这类模型应当**只声明 `reasoning`**。
 *  - `reasoning`  推理 / 思维链模型（声明式，供 UI 展示与后续功能预留）
 *  - `vision`     识图（声明式；当前 AI 面板只发文本，接图片输入时才用得上）
 *  - `audio`      语音（声明式，同上）
 *
 * ⚠️ 未声明能力 = 按历史行为视为**支持 tools**（老配置零迁移）。想关掉某个模型的工具，
 *    要么给它写能力列表（如 `['reasoning']`），要么在该 provider 写 `'capabilities' => []`。
 *
 * 当前模型的能力会显示在 AI 面板空态提示里；缺少 `tools` 时状态栏 AI 段会带「无工具」标记。
 *
 * ── 用户级覆盖（不改本文件也能加/改模型）──────────────────────────────────
 *
 * 菜单「AI → 编辑模型配置…」会在 `~/.vicecode.providers.php`（env `VICECODE_PROVIDERS_CONFIG`
 * 可覆盖）生成一份**带注释的模板**并送进内置编辑器；保存即热重载，无需重启。
 * 合并规则：本文件先读，用户文件**按 id 逐字段覆盖**，新 id 追加在后面（因此 `ids()` 的第一项
 * = 默认 provider 永远是这里的第一条）；`models` 是**整表覆盖**，不与本文件取并集。
 * 于是「只想把 openai 换成自建网关」只需在用户文件里写一个 id + 要改的字段。
 * 用户文件语法写错时会保留上一份可用配置并在状态栏提示，不会让应用崩。
 *
 * ── 模型策略（用户文件里的保留段 `@strategies`）──────────────────────────
 *
 * 把「这次要干什么」映射到一个模型，用于快速换档：AI 面板 `Ctrl+R` 循环切换，
 * 或从菜单/命令面板的 AI 组里直接选；状态栏常显当前档位（`策略=<展示名>`）。
 * 策略可声明 `requires`（能力要求），切换时目标模型不具备就**拒绝并提示**——
 * 例如 `requires: ['tools']` 撞上没声明 tools 的便宜模型，工具会静默失效，宁可拒绝。
 * 另可声明 `kinds`（任务类型）做**自动选档**：类型来源只有快捷动作与输入框 `/前缀`（都不猜）；
 * 人工选档会钉住、自动暂停，`Ctrl+R` 循环里有一档「自动」可切回。
 * `@` 前缀的键是保留段，不会被当成 provider id；策略名 `auto` 保留。形状见用户配置模板里的例子。
 *
 * ── 折扣时段与成本档（可选）────────────────────────────────────────────
 *
 * 服务商常有闲时折扣（如某些 API 在夜间半价）。给 provider 写 `off_peak` 声明时段、
 * 写 `cost` 声明成本档，应用就能在**折扣时段里自动降到最便宜的那一档**：
 *
 *   'off_peak' => [                                  // 多条，任一命中即打折
 *       ['days' => ['sat','sun'], 'from' => '00:00', 'to' => '24:00'],
 *       ['days' => ['*'], 'from' => '23:30', 'to' => '08:30', 'tz' => '+08:00'],
 *   ],
 *   'cost' => 2,                                     // 整数，越小越便宜；不写 = 未知（排最后）
 *
 * 语法：`days` 用三字母缩写 mon..sun 或 `'*'`（省略 = 任意星期）；`from`/`to` 用 `HH:MM`
 * （`to` 可写 `24:00`；`from > to` 表示跨天，`from === to` 表示全天）；`tz` 是 `±HH:MM` 偏移，
 * 省略 = 本机时区。**`days` 指窗口「开始」那一天**——`['sat'], 23:30→08:30` 是周六晚到周日早。
 *
 * 规则：任务类型（快捷动作 / `/前缀`）**优先**；它没命中时才看时段。人工选档（Ctrl+R 等）会
 * 钉住，时段规则不介入。折扣结束时**不主动切回**（没有"该回哪档"的确定答案，方向盘交回用户），
 * 状态栏的「·折扣」标记按当前时刻实时计算，折扣一结束就消失。
 *
 *   'deepseek' => [
 *       'label' => 'DeepSeek',
 *       'cost'  => 1,
 *       // 示例（北京时间夜间闲时）——**请以服务商官方文档为准，按自己情况改**：
 *       // 'off_peak' => [['days' => ['*'], 'from' => '00:30', 'to' => '08:30', 'tz' => '+08:00']],
 *   ],
 *
 * 策略侧可用 `'auto_offpeak' => false` 让某一档**不参与**闲时降档（例如"计划档"即使闲时
 * 也不该被便宜档顶掉）。
 */
return [
    'openai' => [
        'label'    => 'OpenAI',
        'key_env'  => 'OPENAI_API_KEY',
        'url_env'  => 'OPENAI_BASE_URL',
        'base_url' => 'https://api.openai.com/v1',
        'models'   => [
            'gpt-4o-mini'  => ['tools'],
            'gpt-4o'       => ['tools', 'vision'],
            'gpt-4.1-mini' => ['tools', 'vision'],
        ],
        'model'    => 'gpt-4o-mini',
        'capabilities' => ['tools'],   // 未单独声明的模型用它
    ],
    'deepseek' => [
        'label'    => 'DeepSeek',
        'key_env'  => 'DEEPSEEK_API_KEY',
        'url_env'  => 'DEEPSEEK_BASE_URL',
        'base_url' => 'https://api.deepseek.com/v1',
        'models'   => [
            'deepseek-chat'     => ['tools'],
            // 纯推理模型：不发 tools，否则整轮请求会被服务商拒掉
            'deepseek-reasoner' => ['reasoning'],
        ],
        'model'    => 'deepseek-chat',
        'capabilities' => ['tools'],
    ],

    // ── Anthropic Messages API（非 OpenAI 兼容协议，见文件顶部说明）────────────
    // ⚠️ 模型 ID **必须逐字符准确**（多一个点号、少一段日期后缀都是 `model not found`）：
    //    · `claude-*-4-6` 及更新的代次用**无日期**格式；
    //    · 4.5 代及更早的模型需要**完整日期后缀**。
    //    模型 ID 会随服务商上下线变化，**请以官方 `GET /v1/models` 或控制台的当前列表为准**，
    //    下面这几条按自己账号实际可用的改。
    'anthropic' => [
        'label'      => 'Anthropic',
        'protocol'   => 'anthropic',          // 决定协议与端点：POST <base_url>/v1/messages
        'key_env'    => 'ANTHROPIC_API_KEY',
        'url_env'    => 'ANTHROPIC_BASE_URL', // 可指向自建网关/代理
        'base_url'   => 'https://api.anthropic.com',
        'max_tokens' => 4096,                 // Anthropic 必填（缺了 400）；可按模型上限调大
        'models'     => [
            'claude-opus-4-8'            => ['tools'],
            'claude-sonnet-4-6'          => ['tools'],
            // 4.5 代的 ID 带日期后缀，缩写会 404
            'claude-haiku-4-5-20251001'  => ['tools'],
        ],
        'model'        => 'claude-sonnet-4-6',
        'capabilities' => ['tools'],
        // 可选：写上 `cost` 后，折扣时段功能就能在闲时把 Anthropic 的档位也算进候选（见顶部说明）
        // 'cost' => 3,
    ],
];

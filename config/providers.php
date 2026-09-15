<?php
declare(strict_types=1);

/**
 * LLM Provider 定义（M5）。
 *
 * 设计要点：
 *  - **不存 key**：只存「从哪个环境变量读」，key 永远留在环境里，不入库、不落盘。
 *  - base_url 有默认值，同时允许用环境变量覆盖（自建网关/代理/兼容端点都靠它）。
 *  - 这里列出的都是 **OpenAI 兼容协议** 的服务商，共用 `OpenAiCompatProvider`。
 *    Claude 的 SSE 事件名不同（content_block_delta），若以后要接得单开一个 provider 类，
 *    不要往这里塞。
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
];

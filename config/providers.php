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
 */
return [
    'openai' => [
        'label'    => 'OpenAI',
        'key_env'  => 'OPENAI_API_KEY',
        'url_env'  => 'OPENAI_BASE_URL',
        'base_url' => 'https://api.openai.com/v1',
        'models'   => ['gpt-4o-mini', 'gpt-4o', 'gpt-4.1-mini'],
        'model'    => 'gpt-4o-mini',
    ],
    'deepseek' => [
        'label'    => 'DeepSeek',
        'key_env'  => 'DEEPSEEK_API_KEY',
        'url_env'  => 'DEEPSEEK_BASE_URL',
        'base_url' => 'https://api.deepseek.com/v1',
        'models'   => ['deepseek-chat', 'deepseek-reasoner'],
        'model'    => 'deepseek-chat',
    ],
];

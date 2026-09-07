<?php
declare(strict_types=1);

/**
 * 图标配置（可定制）。
 *
 * 空串 '' 表示不使用图标，仅显示文字标签。填入任意字符串即可，例如：
 *   - 通用 Unicode（多数终端可见）： '' 文件夹、'' 放大镜、'' 链接
 *   - Nerd Font 私用区（需安装 Nerd Font 才显示，否则是豆腐块）：
 *       'explorer' => "\u{ef5b}",   //  nf-dev-folders /  nf-fa-folder
 *       'git'      => "\u{f02b}",   //  nf-fa-code_fork
 *       'search'   => "\u{f002}",   //  nf-fa-search
 *
 * 注意：emoji / 全角字形在真实终端占 2 列，代码已按显示列宽预算，不会撑爆布局。
 * 想“只用图标不用文字”，把对应语言包里的 sidebar.* 设为空串即可（config/locales/zh_CN.php）。
 */
return [
    'explorer' => '📁',  // 资源管理器
    'git'      => '🔗',  // GIT（分支）
    'search'   => '🔍',  // 搜索
    'plugins'  => '🧩',  // 扩展（插件）
    // 面板标题也可带图标（App 渲染标题时如需可调用 icon()）
    'sidebar'  => '',
    'editor'   => '',
    'terminal' => '',
    'ai_chat'  => '',
    'ai_input' => '',
];

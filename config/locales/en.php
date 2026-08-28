<?php
declare(strict_types=1);

/**
 * 英文语言包。所有界面字符串都从这里取 key。
 * zh_CN 包只需覆盖需要翻译的项；缺失的自动回退到此文件。
 */
return [
    'app.title'        => 'Workbench',

    // 面板标题
    'panel.sidebar'    => 'SIDEBAR',
    'panel.editor'     => 'EDITOR',
    'panel.terminal'   => 'TERMINAL',
    'panel.ai_chat'    => 'AI CHAT',
    'panel.ai_input'   => 'AI INPUT',

    // 侧栏 tab
    'sidebar.explorer' => 'EXPLORER',
    'sidebar.git'      => 'GIT',
    'sidebar.search'   => 'SEARCH',

    // 状态栏
    'status.focus'     => 'focus',
    'status.tab'       => 'tab',
    'status.file'      => 'file',
    'status.dirty'     => '*',
    'status.readonly'  => 'RO',
    'status.locale'    => 'lang',
    'status.quit'      => 'Ctrl+C quit',

    // AI 输入框提示
    'ai.input_hint'    => '(Enter send)',

    // 编辑器占位 / 提示
    'editor.no_file'   => '(no file open — pick a file in Explorer, Enter to open)',
    'editor.readonly'  => '(read-only)',
    'editor.too_large' => 'File too large to edit: {size} bytes (limit {limit})',
    'editor.binary'    => 'Binary file — not editable',
    'editor.saved'     => 'Saved',
    'editor.save_failed' => 'Save failed: {msg}',
    'editor.open'      => 'Open',
    'editor.untitled'  => 'untitled',

    'confirm.quit_dirty'  => 'Unsaved changes: y quit / n cancel',
    'confirm.close_dirty' => 'File not saved: y close & discard / n cancel',

    // 终端（M2）
    'term.running'       => 'running',
    'term.exit'          => 'exit code {code}',
    'term.killed'        => 'interrupted (SIGKILL)',
    'term.busy'          => 'A command is running — wait for it or press Ctrl+C to interrupt',
    'term.spawn_failed'  => 'Failed to spawn command process',
    'term.empty'         => '(focus this panel to type a command, Enter to run; ↑/↓ history, Ctrl+L clear)',

    // 侧栏其它标签占位
    'git.placeholder'    => 'git status (M3)',
    'git.sub'            => ' - changed files',
    'search.placeholder' => 'search (M4)',
    'search.prompt'      => ' > ',
];

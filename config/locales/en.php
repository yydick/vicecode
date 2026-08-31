<?php
declare(strict_types=1);

/**
 * 英文语言包。所有界面字符串都从这里取 key。
 * zh_CN 包只需覆盖需要翻译的项；缺失的自动回退到此文件。
 */
return [
    'app.title'        => 'ViceCode',

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
    'status.branch'    => 'branch',
    'status.provider'  => 'AI',
    'status.quit'      => 'Ctrl+Q quit',

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
    'confirm.discard'     => 'Discard working changes (IRREVERSIBLE!): y discard / n cancel: {path}',

    // 终端（M2）
    'term.running'       => 'running',
    'term.exit'          => 'exit code {code}',
    'term.killed'        => 'interrupted (SIGKILL)',
    'term.busy'          => 'A command is running — wait for it or press Ctrl+C to interrupt',
    'term.spawn_failed'  => 'Failed to spawn command process',
    'term.empty'         => '(focus this panel to type a command, Enter to run; ↑/↓ history, Ctrl+L clear)',

    // 侧栏其它标签占位
    'git.not_repo'       => '(not a git repository)',
    'git.view_status'    => 'status {n}',
    'git.view_log'       => 'log {n}',
    'git.loading'        => '(loading…)',
    'git.clean'          => '(working tree clean)',
    'git.no_log'         => '(no commit history)',
    'git.no_diff'        => 'no diff for this file',
    'git.commit_title'   => 'Commit message',
    'git.msg_placeholder'=> 'Type commit message…',
    'git.changes'        => 'Changes {n}',
    'git.log_title'      => 'Log {n}',
    'git.stage_all'      => 'Staged all changes',
    'git.stage_file'     => 'Staged: {path}',
    'git.unstage_file'   => 'Unstaged: {path}',
    'git.unstage_all'    => 'Unstaged all',
    'git.discard_file'   => 'Discarded working changes: {path}',
    'git.committed'      => 'Committed',
    'git.commit_empty'   => 'Empty message, aborted',
    'git.push_done'      => 'Pushed',
    'git.push_fail'      => 'Push failed: {msg}',
    'git.op_fail'        => 'Operation failed: {msg}',
    'git.branch_pick'    => 'Switch branch',
    'git.no_branch'      => '(no other branch)',
    'git.switch_done'    => 'Switched to branch: {branch}',
    'git.switch_fail'    => 'Switch branch failed: {msg}',
    'git.hint'           => '[a]stage all [s]stage [c]commit [p]push [L]log [Enter]diff [R]refresh',
    'search.placeholder'      => 'Search file contents…',
    'search.hint'             => 'Press Enter to search…',
    'search.prompt'           => ' > ',
    'search.status_searching' => 'Searching…',
    'search.status_no_results' => 'No matches',
    'search.results'          => '{n} matches / {files} files',
    'search.status_error'     => 'Search error: {msg}',
    'search.truncated'        => 'Truncated (first {n}), narrow your query',
    'search.empty_query'      => 'Type a search keyword',

    'ai.no_provider'     => 'No provider configured',
    'ai.no_key'          => 'Missing API key: set env {env}',
    'ai.streaming'       => 'Generating…',
    'ai.cancelled'       => 'Generation stopped',
    'ai.error'           => 'AI request failed: {msg}',
    'ai.http_error'      => 'HTTP {code}',
    'ai.empty'           => '(model returned nothing)',
    'ai.busy'            => 'Already generating, cannot start a new request',
    'ai.switched'        => 'Switched to {provider} / {model}',
    'ai.clear_hint'      => '(Ctrl+L clears the conversation)',
];

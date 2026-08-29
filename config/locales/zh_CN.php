<?php
declare(strict_types=1);

/**
 * 简体中文语言包。只覆盖需要翻译的项；缺失 key 由 Translator 回退到 en.php。
 */
return [
    'app.title'        => '工作台',

    'panel.sidebar'    => '侧栏',
    'panel.editor'     => '编辑器',
    'panel.terminal'   => '终端',
    'panel.ai_chat'    => 'AI 对话',
    'panel.ai_input'   => 'AI 输入',

    'sidebar.explorer' => '资源管理器',
    'sidebar.git'      => 'GIT',
    'sidebar.search'   => '搜索',

    'status.focus'     => '焦点',
    'status.tab'       => '标签',
    'status.file'      => '文件',
    'status.dirty'     => '*',
    'status.readonly'  => '只读',
    'status.locale'    => '语言',
    'status.branch'    => '分支',
    'status.quit'      => 'Ctrl+Q 退出',

    'ai.input_hint'    => '（回车发送）',

    'editor.no_file'   => '（未打开文件 — 在资源管理器选中文件后按 Enter 打开）',
    'editor.readonly'  => '（只读）',
    'editor.too_large' => '文件过大，无法编辑：{size} 字节（上限 {limit}）',
    'editor.binary'    => '二进制文件 — 不可编辑',
    'editor.saved'     => '已保存',
    'editor.save_failed' => '保存失败：{msg}',
    'editor.open'      => '打开',
    'editor.untitled'  => '未命名',

    'confirm.quit_dirty'  => '有未保存改动：y 退出 / n 取消',
    'confirm.close_dirty' => '该文件未保存：y 关闭丢弃 / n 取消',
    'confirm.discard'     => '丢弃工作区改动（不可恢复！）：y 确认丢弃 / n 取消：{path}',

    'term.running'       => '运行中',
    'term.exit'          => '退出码 {code}',
    'term.killed'        => '已中断 (SIGKILL)',
    'term.busy'          => '有命令正在运行，等它结束或按 Ctrl+C 中断',
    'term.spawn_failed'  => '无法启动命令进程',
    'term.empty'         => '（聚焦此面板后可输入命令，回车执行；↑/↓ 翻历史，Ctrl+L 清屏）',

    'git.not_repo'       => '（当前目录不是 git 仓库）',
    'git.view_status'    => '状态 {n}',
    'git.view_log'       => '日志 {n}',
    'git.loading'        => '（加载中…）',
    'git.clean'          => '（工作区干净）',
    'git.no_log'         => '（无提交历史）',
    'git.no_diff'        => '该文件无 diff',
    'git.commit_title'   => '提交信息',
    'git.msg_placeholder'=> '输入提交信息…',
    'git.changes'        => '变更 {n}',
    'git.log_title'      => '日志 {n}',
    'git.stage_all'      => '已暂存全部改动',
    'git.stage_file'     => '已暂存：{path}',
    'git.unstage_file'   => '已取消暂存：{path}',
    'git.unstage_all'    => '已取消全部暂存',
    'git.discard_file'   => '已丢弃工作区改动：{path}',
    'git.committed'      => '已提交',
    'git.commit_empty'   => '提交信息为空，已取消',
    'git.push_done'      => '已推送',
    'git.push_fail'      => '推送失败：{msg}',
    'git.op_fail'        => '操作失败：{msg}',
    'git.hint'           => '[a]全暂存 [s]暂存 [c]提交 [p]推送 [L]日志 [Enter]diff [R]刷新',
    'search.placeholder' => '搜索（M4 接入）',
    'search.prompt'      => ' > ',
];

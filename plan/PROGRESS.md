# 进度总结（PROGRESS）

> 最后更新：2026-08-27
> 配套文档：`plan/PLAN.md`（总计划）、`plan/MILESTONES.md`（M0–M7 里程碑）
> 详细坑与 API 见记忆 `project_php_tui_facts.md`

## 一、当前状态

- **教学系列已完成并通过验收**：6 个最小可验证样本 `examples/01`~`06` + headless 快照工具 `examples/snapshot.php`。
- 全部在真实 pty 下验证：**退出码 0 + 终端干净还原**（无花屏、退出后回显正常）。
- 整体技术路线已定：**终端完全交给 `php-tui/term` 管，Swoole 只留给后续真正的异步场景**（M5 的 LLM SSE 流式、命令/目录/git 异步执行）。不再用 Swoole 驱动终端。
- **M0 与 M1 均已组装并通过 headless + 真实 pty 双验收**（见第五、六节）。

## 二、已完成文件

| 文件 | 主题 | 关键 API / 验证点 |
|---|---|---|
| `examples/01-hello.php` | 生命周期四步 | `Terminal`/`Actions`/`Display::draw`/`disableRawMode` |
| `examples/02-layout.php` | 分栏布局 | `GridWidget` 声明式 + `Layout::split`/`Areas` 取矩形 |
| `examples/03-keyboard.php` | 键盘模型 | `CharKeyEvent`/`CodedKeyEvent`/`KeyModifiers` 位标志（`&` 检测） |
| `examples/04-mouse.php` | 鼠标/命中 | `MouseEvent`/`enableMouseCapture`/`Area::containsPosition` |
| `examples/05-text-input.php` | 文本 I/O | 输入缓冲 + 回车提交 + 退格 + Ctrl+C |
| `examples/06-list-scroll.php` | 滚动列表 | 视口数学（可见窗口）+ `scandir` 目录遍历 |
| `examples/snapshot.php` | 验收工具 | headless 渲染到 `Buffer::toLines()`，输出 ASCII 快照 |
| `examples/lib.php` | 共享阻塞源 | `BlockingTtyEventProvider`（stream_select 阻塞等键），被 01~06 + minimal 复用，破解“非阻塞闪退” |

旧 Swoole 版 M0（`src/App.php`、`src/Core/EventLoop.php`、`src/Core/InputParser.php`、`bin/tui.php`、`tests/smoke.php`）已被当前方向取代，**组装 M0 时优先基于 examples 重写**，不要修补旧 Swoole 代码。

## 三、关键技术结论（避免重踩）

- **php-tui 真实版本是 `0.2.1`**，不是计划里假设的 `^3.0`；配套 `php-tui/term 0.3.4`、`php-tui/cassowary 0.1.0`。
- **Swoole 在真实 pty 上 `Event::add(STDIN)` 不触发回调** → 键读不到、被迫杀终端、raw mode/alternate screen 未还原 → 花屏 + 无回显（这是早期失败根因）。已用 `php-tui/term` 的 `$term->events()->next()`（阻塞式同步读键）根治。
- 0.2.1 API 要点：`DisplayBuilder::default($b)->fullscreen()->build()`；`Layout::constraints($array)`（收数组非 splat）；`GridWidget` 的 `constraints(...)`/`widgets(...)` 收 splat；`MouseEvent::new(...)`（构造私有）；`Display::draw()` 内部 `autoresize()`，无需手动处理 SIGWINCH。
- 非 tty 环境（管道/CI）：`enableRawMode()` 需 `stream_isatty()` 守卫；渲染测试用 `COLUMNS/LINES` 或 headless `Buffer`。

## 四、如何验收

```bash
# 真实终端（WSL）里逐个交互验收，q/Esc 退出
php examples/01-hello.php
php examples/02-layout.php      # Tab/数字 1-5 切焦点
php examples/03-keyboard.php    # 打字/方向键，看按键日志
php examples/04-mouse.php       # 点面板、滚轮
php examples/05-text-input.php  # 打字回车提交
php examples/06-list-scroll.php # ↑/↓/滚轮/点击浏览

# 不依赖终端的 ASCII 快照验收
php examples/snapshot.php       # 渲染 6 个例子的初始画面
```

## 五、M0 已组装并验证 ✅（2026-08-27）

直接用已验证的 6 个零件拼成了真实 M0（纯 php-tui/term 驱动，无 Swoole 终端驱动）：

- `src/App.php` — 状态 + `areas()`（布局矩形）+ `render()/build()`（Sidebar[Explorer/GIT/Search] + Editor + Terminal + AI 聊天流 + AI 输入框 + StatusBar）+ `handle()`（键鼠分发：Tab/点击切焦点、侧栏 tab 切换、AI 输入打字+Enter 发送、↑/↓ 滚动文件列表、Ctrl+Q/q/Esc 退出）。
- `bin/tui.php` — 终端生命周期（alternateScreen + enableMouseCapture + rawMode）+ 渲染循环（`draw` + `events()->next()`）+ 干净还原。
- `tests/m0_smoke.php` — headless 验收（面板标题、点击命中、AI 发送、tab 切换、↑ 移动）。

**验证结果**：headless smoke 全 PASS；pty 三种输入（q / Tab+点击 / 点击AI输入打字发送+q）均 `exit=0` 且终端干净还原。修掉两个真实 bug：① `CodedKeyEvent` 字段是 `$code` 非 `$keyCode`（examples 曾因只验 exit=0 漏掉，已全局修正）；② 零尺寸 pty 下 `str_repeat` 负数 → 宽度夹紧 ≥0。

```
┌ SIDEBAR ┬──────── EDITOR ────────┬ AI CHAT ──┐
│EXPLORER │                         │           │
│GIT      ├──────── TERMINAL ──────┤           │
│SEARCH   │                         ├ AI INPUT ─┤
└─────────┴─────────────────────────┴───────────┘
 M0 · focus=... · Tab/点击切焦点 · Ctrl+Q 退出
```

## 六、M1 已组装并验证 ✅（2026-08-28）

在 M0 的 6 个零件基础上实现了资源管理器 + 编辑器（纯 php-tui/term 驱动，未引入 Swoole 终端驱动）：

- **R1 目录树**：`Explorer/FileTree.php` 懒展开、上下移动、Enter 展开/打开；`handleSidebarEnter` 分发。
- **R2 打开文件**：`App::openFile` → `Buffer::fromFile`（行数组），编辑器渲染行号；超大/二进制 `readOnly` + 提示。
- **R3 滚动与光标**：方向键/Home/End/`Buffer::pageUp/pageDown`；`editorContent` 按 `scrollTop/scrollLeft` 裁剪视口并恒保光标可见。
- **R4 基础编辑与保存**：`Buffer` 插入/退格/换行/删除；`Ctrl+S` → `saveBuffer` 落盘、`*` 标记清除。
- **R5 语法高亮 [P1]**：`Editor/Highlighter.php` 适配 `scrivo/highlight.php`，按 lang 着色、缓存到 `Buffer::hlRev`，不影响滚动。
- **R6 编辑器鼠标交互 [P1]**：`handleMouse` 滚轮 `ScrollDown/Up` 在 editor 焦点下 `pageDown/pageUp`；`handleClick` → `positionCursorAtClick` 点击编辑器映射回 `(row,col)` 定位光标。
- **R7 多 Buffer 标签 [P2]**：`buffers` 映射 + `hasTabs/switchBuffer/cycleBuffer`（Ctrl+Tab 环形），编辑器顶部 tab 栏。
- **R8 未保存确认 [P2]**：`requestQuit/requestClose` → `confirm` 状态机，`y/n/Esc` 响应；`Esc` 取消关闭保留 buffer。

**验收**：`tests/m1_smoke.php` 覆盖 i18n / Buffer / 目录树 / 编辑保存 / 高亮 / 多标签 / 未保存确认 / 鼠标交互，全 PASS；`tests/pty_run.php` + `tests/pty_drive.php` 真实 pty 全流程 `exit=0`、无致命错误、终端干净还原。

**已完成**：
- **M1.5（M1 与 M2 之间）**：底层切到 Swoole 协程（见 `MILESTONES.md`）。✅ 完成。Terminal I/O 仍用 php-tui/term，Swoole 做协程运行时/事件循环底座；读键用 `Swoole\Coroutine::waitEvent(STDIN)`（非 `System::fread`——6.0 已移除；也非 `SWOOLE_HOOK_STDIO`——对 TTY 不协程化）。真实 pty 双工具 `pty_run`/`pty_drive` 均 exit=0，headless `m1_smoke`/`coroutine_channel_test` 均 PASS。踩坑见 `docs/swoole_study.md`。

## 七、M2 已完成并验证 ✅（2026-08-28）

Terminal 面板从占位变成真正能跑命令的终端。R1–R7 全做。

- **R1 命令输入行**：聚焦终端后可打印字符进输入行（`q` 也进，不再误退出），支持 Backspace / 左右 / Home / End / Delete / Ctrl+L 清屏。
- **R2 子进程执行**：`src/Terminal/CommandRunner.php` —— `proc_open(['/bin/sh','-c',$cmd])`，stdin 给 `/dev/null`（防命令抢读终端），stdout/stderr 双管道非阻塞，主循环每轮 `poll()` 排空。**退出码一律取 `proc_get_status()['exitcode']`**（`proc_close()` 返回值在 Swoole HOOK 下不可信）。
- **R3 流式输出**：`src/Terminal/TerminalBuffer.php` —— 块边界不定在换行上，用 tail 攒半行；跨流时先断行避免串色；上限 2000 行；清洗 ANSI/CRLF/TAB/C0/非法 UTF-8（`ls --color` 输出干净）。输出随主循环刷新，命令运行中 pop 超时压到 10ms 让输出跟手。
- **R4 历史**：↑/↓ 在命令历史间切换，到底回到空输入，上限 200 条、去重。
- **R5 退出码 + stderr 着色**：非零退出码追加红色提示行，被中断追加黄色提示行，stderr 行着红。
- **R6 滚轮回看**：滚轮 / PageUp / PageDown 翻页，滚离底部即退出 follow 模式。
- **R7 可中断**：命令运行中 `Ctrl+C` / `Esc` 只 `proc_terminate(SIGKILL)` 子进程，**不退出应用**（判定顺序排在全局 Ctrl+C 之前）；运行中再提交给 busy 提示。

**关键实测结论**（`tests/m2_probe_runner.php`，改代码前请重跑）：
1. `Swoole\Process->start()` 在协程内 **Fatal**（`must be forked outside the coroutine`）——故 M2 未采用预 fork worker 方案。
2. `proc_close()` 退出码在 `SWOOLE_HOOK_ALL` 下被改写（`exit 42` → `0`），必须走 `proc_get_status()['exitcode']`。
3. `SWOOLE_HOOK_FILE` 接管管道 fd，`proc_close()` 后往 **stdout**（非 stderr）吐 `socket_free_defer` 警告，会打花 alternate screen —— 关掉即消失。
4. 最终 flags：`SWOOLE_HOOK_ALL & ~SWOOLE_HOOK_STDIO & ~SWOOLE_HOOK_FILE & ~SWOOLE_HOOK_PROC`。

**验收踩到的两个真问题**（都已修）：
- **pty 下回车是 `CodedKeyEvent(Enter)`，不是 `CharKeyEvent("\r")`** —— 提交逻辑只挂在 CharKeyEvent 上时，headless 全绿但 pty 下提交不了命令。已在 `handleCoded` 的 Enter 分支补 terminal，并在 `m2_smoke.php` 里显式覆盖这条路径防回归。
- **pty 默认无窗口尺寸**（`COLUMNS`/`LINES` 皆空）→ php-tui 拿到 0×0，画面渲染不出任何内容。`tests/pty_term.php` 已显式传 `COLUMNS=120 LINES=40`。

**验收**：`m2_smoke`（headless，48 项）、`m2_probe_runner`（三组对照：无 HOOK / 旧 flags / M2 flags）、`pty_term`（真实 pty，7 项）全 PASS；`m0_smoke`/`m1_smoke`/`m1_edge`/`coroutine_channel_test`/`pty_run`/`pty_drive` 回归全绿（9/9）。

**顺手修复的 M1 遗留 bug**：编辑器光标把**整行**反显而非仅光标字符 —— `Style::addModifier()` 是原地修改
（返回 `$this`），而 `editorContent()` 让整行共用同一个 `Style::default()` 实例，展开成字素后共享，加
`REVERSED` 时整行都被反显。修法：反显前 `(clone $st)`。`tests/m1_edge.php` 现已全 PASS（光标精确落在 `(34,1)`）。
**教训**：php-tui 0.2.1 的 `Style` 是**可变对象**，任何「共享实例 + 局部改样式」处都要先 `clone`。

**下一步（按里程碑）**：
- 之后：M3 GIT；M4 Search；M5 多 Provider LLM 流式（Swoole 协程底座在这里才真正派上用场）。
- 每步延续"先最小验证再拼接"。

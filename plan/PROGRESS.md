# 进度总结（PROGRESS）

> 最后更新：2026-08-31
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

旧 Swoole 版 M0（`src/App.php`、`src/Core/EventLoop.php`、`src/Core/InputParser.php`、`bin/vicecode.php`、`tests/smoke.php`）已被当前方向取代，**组装 M0 时优先基于 examples 重写**，不要修补旧 Swoole 代码。

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
- `bin/vicecode.php` — 终端生命周期（alternateScreen + enableMouseCapture + rawMode）+ 渲染循环（`draw` + `events()->next()`）+ 干净还原。
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

## 八、M3 GIT 面板已接入（2026-08-29）

重构第 1–12 步完成「面板契约」后，按里程碑进入 M3。GIT 内容挂在 Sidebar 的 GIT tab（原占位处），分支进 StatusBar。

- **R1 git status 异步展示** ✅：`Git/GitClient::parseStatusPorcelain`（纯解析，单测覆盖 M/A/D/?/R/U）+ `Git/GitModel::refresh()` 协程内 `git status --porcelain`（Swoole `System::exec` 不阻塞 reactor）。状态着色（staged 绿 / modified 黄 / untracked 红 / renamed 品红 / conflict 亮红）。
- **R2 git log 展示** ✅：`git log --pretty=format:%h|%an|%ar|%s` 解析，`[L]` 在 status/log 子视图间切换，可滚动。
- **R3 分支显示在状态栏** ✅：`git rev-parse --abbrev-ref HEAD` 进 `StatusBarPanel`（`状态.branch`）。
- **R4 git diff 查看** ✅：GIT tab 选中文件 `Enter` → `git diff [--cached] -- <path>` 载入编辑器只读 Buffer 查看（复用编辑器渲染）。
- **R5 基础操作 stage/commit/push** ✅（**可视化重做，弃用字母快捷键**）：用户反馈不喜欢 `a/s/c/p` 字符说明，且 `p` 会导致键位"漏"到终端。改为 VSCode 风格：
  - 顶部**提交信息输入框**（聚焦 GIT tab 时键入即进 `commitMsg`；空时显占位 `提交信息`）；
  - **`Commit ▾` 按钮**（REVERSED 高亮），右侧 `▾` 展开下拉菜单，含四项：**提交**(commit) / **提交变更**(commitAll) / **提交和推送**(commitAndPush) / **提交和同步**(commitAndSync)；
  - 变更标题行右侧 ` + - ` 图标：点击 `+`=`stage all`（`git add -A`）、`-`=`clear all changed`（`git restore --staged .`，**安全、可恢复**）；
  - 每条变更行首四个图标：`▦`=在编辑器打开文件、`+`=stage 选中、`-`=unstage 选中（`git restore --staged -- <path>`，**安全、可恢复**）、`✕`=discard 工作区改动（`git restore -- <path>`，**不可逆**，弹 y/n 确认框防误触；未跟踪文件走 `git clean -f -- <path>`）；点击文件名=`打开 diff`（对比视图，只读）。
  - 键盘等价于图标：`+`/`=`=stage 选中、`-`=unstage 选中、`Enter`=提交、`Backspace`=删 commitMsg 末字符、`Esc`=关下拉。
  - 所有操作为协程异步；下拉四项各自调 `doCommit`/`pushNow`/`syncNow`（pull --rebase + push），完成后刷新状态/分支并写状态栏消息。
  - **已删除**旧的通用单行输入提示（App::$prompt / openPrompt / closePrompt）与 `c` 字母提交模态弹窗——用户明确要"输入框+按钮"而非模态。
- 交互：GIT tab 内 `↑/↓` 移动、`Enter` 看 diff或提交、`L` 切 status/log、`R` 刷新；切到 GIT tab 自动触发异步刷新。

**关键修复（2026-08-30）**：`GitClient::exec` 原本在协程内走 `Swoole\Coroutine\System::exec`，但该函数依赖 `SWOOLE_HOOK_PROC`，而 bin/vicecode.php 刻意关闭了该 HOOK（否则 `proc_close` 返回值被改写、headless 测不动 runner）。结果真实 pty 下 `System::exec` 返回 false → `refresh()` 全失败、`branch` 永远空、GIT 面板退化成"不是 git 仓库"占位，且**headless 单测全绿却 pty 漏判**（单测跑在协程外走 `@exec` 回退分支才过）。已统一改为原生 `cd <cwd> && git ... 2>&1` 的 `@exec`，协程内由独立 `\go` 子协程承载、不卡主循环。这是典型的 headless≠pty 落差，务必双跑验收。

**新增文件**：`src/Git/{GitClient,GitFileStatus,GitCommit,GitModel}.php`；`Buffer::fromString`（虚拟只读文档）；`EditorPanel::openVirtual`。
**验收**：`tests/git_unit.php`（解析 + 渲染 + 键入/空消息拒绝 + 下拉/菜单命中 + ▦开文件/✕丢弃确认 + 真实异步刷新，全 PASS）；`tests/pty_git.php`（真实 pty 点 GIT tab→看分支 master/状态→键入提交信息→点 Commit▾ 展开四项下拉→点「提交」空消息被拒→点文件名开 diff→点 ▦ 开文件→点 ✕ 弹丢弃确认→n 取消→Ctrl+Q 退出，exit=0）。m0/m1/m1_edge/m2/m2_probe_runner/editor_render_check/diff_invariant2/coroutine_channel_test/pty_run 回归全绿。

**R6 分支切换（2026-08-31 补完）**：状态栏分支名右侧 `▾` 下拉，列出本地分支、点击即 `git switch`，
切换后异步刷新 status/log/分支名。下拉打开时字符键既不进提交框也不触发 `+/-`。至此 **M3 全部完成**。
验收：`tests/git_unit.php` 增 `switchBranch` 往返切换用例；`tests/pty_git.php` 真实 pty PASS。

## 九、M4 Search 面板已接入（2026-08-31）

挂在 Sidebar 的 SEARCH tab。范围刻意收窄：**只搜内容、无大小写/正则开关、无替换**（R4–R6 不做）。

- **R1 输入框**：聚焦 SEARCH tab 时键入即进 `SearchModel::$query`，回车触发。
- **R2 非阻塞递归 grep**：`Search/SearchClient.php` 构造 `grep` 命令（排除 vendor/node_modules 等），
  `Search/SearchModel.php` **复用 M2 的 `CommandRunner`**（子进程 + 非阻塞管道 + 主循环每轮 `poll()`）。
  - ⚠️ **没有用协程**：搜索可能跑几秒，而 `\go()` + 阻塞 `@exec` 不是可让出的 I/O，会把整个事件循环
    卡死，直接违背 R2「搜索期间界面仍可操作」。这点与 M3 的 `GitModel`（毫秒级 `git status`，阻塞无所谓）
    不同——**长任务一律 `CommandRunner`，短任务才 `\go()` + `@exec`**。
  - 增量分组（边读边聚，不等命令跑完）；`MAX_MATCHES=2000` 达限即 `cancel()` 杀进程并提示「已截断」。
  - grep **退出码 1 = 无匹配（正常）**，2 = 真实错误。把 1 当报错会每次搜不到都误报。
  - 跨 chunk 半行**保持原始字节**，凑齐 `\n` 后才 sanitize——提前清洗会把被 chunk 劈开的汉字「修」成替换符。
- **R3 结果跳转**：按文件分组、可折叠；回车在 HEADER 上折叠、在 HIT 上 `openFile` + 设 `cursorRow` 定位。

**关键设计**：`buildVisibleRows()` 每帧现算可见行，渲染与点击命中**都调它**、不缓存快照——
折叠一变缓存就和屏幕对不上，点击会打开错误的行。

**验收**：`tests/search_unit.php`（`parseLine` 对真实 grep 输出逐行正确 + 排除目录 + 折叠后行下标、
`mbDispToCharIndex` 边界）、`tests/pty_search.php`（真实 pty exit=0）。回归全绿。

## 十、面板横向滚动（2026-08-31）

用户 2026-08-30 反馈「各窗口都无法横向移动」。编辑器 / 侧栏 / 终端三处已补上，**触发方式为鼠标横向滚轮**。

- 新增 `DisplayWidth::mbSubDisp()`（按显示列切片，字素边界对齐、不劈开 CJK）与
  `mbDispToCharIndex()`（显示列 → 字符索引）。
- **根因（编辑器）**：`Buffer::$cursorCol` 是**字符索引**，`scrollLeft`/`textW` 是**显示列**，
  原 `content()` 把字符索引当显示列比 → CJK 下光标被推出屏外、End 行尾不现；点击把鼠标显示列
  直接赋给 `cursorCol` → 汉字错位。加上每帧把 `scrollLeft` 拉回光标，横滚等于无效。
  → 引入 `scrollPinned`：滚轮横滚置 `true`（自由 pan），光标移动/点击/输入/开新文件置 `false`（恢复跟随）。
- 上界按「本帧最宽行显示列 − 视口内宽」钳制（非 `maxW-1`，否则长行尾部永远看不到），且**渲染前**钳好。
- 顺手补 `Buffer::moveLeft/moveRight` 跨行移动（行尾右移→下一行首，行首左移→上一行尾）。
- **仍未做**：键盘触发（Editor 方向键被光标占用，键位留给 M6 快捷键体系统一约定）、边界指示符、AI 面板横滚。

**验收**：`tests/hscroll_unit.php`（切片/反查/跨行移动边界）全 PASS；m0/m1/m1_edge/m2/git/search/
editor_render_check/diff_invariant2/coroutine_channel_test 与全部 pty 测试回归全绿（19/19）。

## 十一、M5 AI 交互流已接入（2026-08-31）

右上消息流 + 右下输入框接上真实 LLM 流式。范围：OpenAI + DeepSeek 两家（同一协议共用实现），
本地 mock 端点验收（真 key 从环境变量读，不入库）。

### 传输层：先验证再选型（这一步省不得）

里程碑原本写「Swoole 协程 curl + WRITEFUNCTION」。**实测这条路是坏的**，详见
`docs/swoole_study.md` §9 与 `tests/sse_probe*.php`：

| 方案 | 结果 |
|---|---|
| libcurl + WRITEFUNCTION，含 `NATIVE_CURL` hook | ❌ 回调被**静默吞掉**（不报错、没数据） |
| `curl_multi` + `NATIVE_CURL` hook | ❌ **段错误** |
| libcurl + WRITEFUNCTION，关掉 CURL hook | ⚠️ 能流式，但**阻塞整个调度器**（打点=1） |
| `Coroutine\Http\Client` + `recv()` | ❌ `recv()` 是 WebSocket 方法，HTTP 没有增量读的口子 |
| **`curl` 子进程 + 非阻塞管道** | ✅ 流式 + 不阻塞（打点=29） |

选最后一条：**复用 M2/M4 的 `CommandRunner`，传输层零新代码**，HTTPS 交给 curl，
「停止生成」就是 `cancel()` → `proc_terminate`（M2 R7 已验证）。
命令行必须带 `-N`（关 curl 缓冲，否则流式退化成一次性返回）。

顺带记一个 API 坑：`Swoole\Coroutine::run()` 静态方法**不存在**，只有函数
`Swoole\Coroutine\run()`。

### 实现要点

- `src/Ai/SseParser.php`：增量解析。**半行保持原始字节**，凑齐 `\n` 才解析——
  UTF-8 汉字是 3 字节，chunk 边界随时会劈开它，提前 sanitize 会永久乱码（同 M4 的教训）。
- `src/Ai/OpenAiCompatProvider.php`：请求体与请求头都走**临时文件**。
  长对话不撞 ARG_MAX，且 **API key 不进 argv**（命令行对同机其他用户可见）。
  用 `-D` 单独 dump 响应头，才能区分「401 鉴权失败」和「模型真的没话说」。
- `src/Ai/ChatModel.php`：`send/poll/cancel`。流式在数据层就是「最后一条 assistant
  消息在长」，渲染层无需为「正在生成的消息」开特例。
- `src/Panel/AiPanel.php`：**必须自己软换行**（新增 `DisplayWidth::mbWrapDisp`）。
  `ParagraphWidget` 默认走 `LineTruncator`，超宽时是**折行不是截断**，一行超 1 列就把
  后续整片行挤下去（M1 幽灵行根因）。LLM 回复动辄超宽，这个坑必踩。
- 交互：回车发送、`Ctrl+P` 切 Provider、`Ctrl+N` 切模型、`Ctrl+L` 清空、`↑/↓` prompt 历史
  （含草稿保存）、滚轮滚动（上滚脱离 follow，否则新 token 把人拽回底部）、`Esc` 中断生成
  （不生成时放回全局退出）。**不能用 Ctrl+M——它就是回车 0x0D**。

### 顺手修掉的两个真 bug（都是 headless 测不出来的）

1. **全应用 CJK 输入失效**：编辑器 / 终端 / AI 输入 / GIT 提交框 / SEARCH 输入框五处的
   可打印字符判定都写成 `strlen($char) === 1`，而 UTF-8 的「你」是 3 字节 →
   **中文和全角标点根本打不进去**。实测 php-tui/term 的 EventParser 会正确解码成单个
   `CharKeyEvent(char:'你')`，故只需放宽字节数判断。统一抽到 `Core\KeyInput::isPrintable()`。
2. **AI 面板回车发不出去**：真实终端里回车是 `CodedKeyEvent(Enter)`，不是
   `CharKeyEvent("\r")`。只在 `onChar` 挂 `"\r"` 的话 headless 全绿、pty 下按回车没反应
   （M2 的终端面板踩过一模一样的坑）。两条路径都补了测试钉死。

### 验收

`tests/ai_unit.php`（142 项）+ `tests/pty_ai.php`（真实 pty）。
pty 里的关键断言是**中途快照能看到首 token、但看不到末 token** —— 这才能证明主循环
确实把 `pollAi()` 的返回值并入了重绘判据；headless 直接调 `poll()` 绕过这条路径，测不出来。
全部 21 个测试（11 headless + 10 pty）无回归。


## 十二、M6 打磨已交付（2026-08-31）

范围按用户选择：P0 的 R1–R3 全做 + P1 的 R4 主题切换。R5（拖拽分隔条）、
R7（`~/.vicerc` 持久化）、R8（中文文案）与 Backlog 的顶部 Menu Bar 未做。

### R1 完整状态栏

补上第四项「编辑模式」（编辑 / 只读 / —）。真正的改动是**裁剪策略**：

原先状态栏超宽时交给 php-tui 处理，而它是**从尾部硬切**——实测 80 列下文件名、
瞬时消息、退出提示全被切掉，只剩开头几项最没用的。用户症状是「我刚才那条提示
怎么没了」。改成自己算宽度、按优先级丢弃低价值项、最后兜底硬截断（带省略号）。

优先级两个非显然判断（都写进注释了）：
- 焦点 / 语言排最低：焦点已被面板边框高亮表达，纯冗余。
- **标签不能排太低**：侧栏 tab 只显示图标不显示文字，状态栏那行是它唯一的
  文字标识，丢了用户就分不清当前在哪个 tab。（这条是 m1_smoke 报错提醒的。）

120 列下最终保留：文件 · 模式 · 分支 · AI · 标签 · 消息 · Ctrl+Q 退出（115/120）。

### R2 快捷键体系与帮助页

- `src/Core/KeyBindings.php`：声明式注册表，**帮助页的唯一数据源**。
  ⚠️ 它**不参与事件分发**——真正的绑定散落在 `App::handle` 与各面板的
  onChar/onKey。把分发也改成读表要重写全部面板的事件处理，风险与收益不成比例。
  为对冲「描述与实际漂移」，`tests/m6_unit.php` 有自动检测：扫描 `src/` 里所有
  `Ctrl+X` 绑定，断言每一个都已登记，新增快捷键忘了写进帮助页会红。
- `src/Panel/HelpPanel.php`：`?` 唤出的全屏居中覆盖层。
  用 php-tui 的 `CompositeWidget` 叠加（它注释原话是 "useful for showing
  dialogues"）——底层 UI 先画、帮助页浮在上面，底下仍看得见自己在哪个面板。
  打开期间**独占键盘**：否则按 `q` 会顺带把应用退了（q 是全局「非输入态退出」）。

踩坑记录：
- 键位列一开始用 `mbPad`（按字符数）补齐，而键位里混着 CJK（「横向滚轮」4 字 8 列），
  结果 CJK 行短一大截、右列参差不齐 → 改用 `mbPadDisp`（按显示列宽）。
- 极小视口 20x6 崩溃：面板宽度用了 `max(20, min(W-2, …))`，一旦追平视口，两侧
  留白格被分到 0 宽，Grid 产出 0xN area，Paragraph 写入抛 OutOfBoundsException。
  → 改成严格 `min(W-2, …)`，并对 W<8 || H<5 直接不画浮层。

### R3 错误处理

修了两个真 bug，详见 MILESTONES「已知问题」：
1. 打开无权限/不存在的文件 → PHP Warning 直接写进 alternate screen 打花画面，
   且 `isBinary()` 把「权限不足」误报成「二进制文件」。改为读前先判状态。
2. 抛未捕获异常时终端不还原（raw mode + alternate screen 残留，用户 shell 被废）。
   `start()` 加 try/finally，顶层 catch 转一行可读信息。

### R4 主题切换

`src/Core/Theme.php` 把 7 个文件里 68 处硬编码颜色收拢成「语义角色 → 颜色」，
两套配色（深色 / 午夜蓝），`Ctrl+T` 切换，状态栏显示当前主题。

⚠️ 两个必须记住的约束：
- **Style 是可变对象**（`fg()`/`addModifier()` 原地改）。Theme 的
  `style()`/`gitStyle()`/`syntaxStyle()` 每次都新建 Style、**不做任何缓存**——
  共享实例被某处 addModifier 会污染全界面（M1 曾因此整行反显）。
- **换主题必须让高亮缓存失效**：`hlLines` 存的是已带 Style 的实例，
  不把 `hlRev` 推成 -1 的话代码区残留旧配色，看着像「主题只换了一半」。

语法高亮配色也进了主题：只换面板色会导致「界面换了、代码还是原色」的割裂感。

### 验收

`tests/m6_unit.php`（62 项断言）+ `tests/pty_m6.php`（真实 pty）+ `tests/pty_crash.php`。
全部 24 个测试（12 headless + 12 pty）无回归。

pty 里的关键断言：`?` 在真实终端确实唤出帮助页（真实终端给的是可打印字符事件，
与 headless 自己 new 的对象路径不同）、滚到底能露出 AI 分组、`Ctrl+T` 真的换主题、
以及崩溃后仍发出还原序列。

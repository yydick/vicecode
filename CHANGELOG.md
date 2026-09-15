# 更新日志

> 本文件是**发版摘要**：每个版本改了什么、该跑什么。
> 每个 BUG 的**现象 / 根因 / 修复 / 防回归测试**都在 [docs/BUGFIXES.md](docs/BUGFIXES.md)，
> 那里是根因的权威归处，本文件不重复细节。

版本格式：`[版本号] — 日期`，未发版的改动都归在 `Unreleased`。

---

## [Unreleased]

> 本轮主题：**AI V2** —— 把 AI 从「孤岛聊天框」接进工作台：代码上下文、只读工具 Agent loop、上下文压缩、对话持久化与 Markdown 渲染。

### 新增（AI V2）

- **代码上下文**：AI 输入框支持 `@文件` 引用（发送前展开为围栏代码块，路径经 realpath + 项目根前缀校验，`../` / 绝对路径 / symlink 出根 / 二进制一律拒绝；单文件上限 `ai.attachMaxBytes` 默认 64KB，超限截断标注）；菜单「AI → 附加编辑器选区 / 附加当前文件」；引用缺失时状态栏提示且不阻断发送。
- **只读工具调用（Agent loop）**：模型可调用 `list_files` / `read_file`（OpenAI tools 协议，`SseParser` 升级为结构化事件解析 `tool_calls` 分片与 `finish_reason`），本地同步执行后以 `role:tool` 消息续跑，最多 `ai.maxSteps`（默认 8）轮；达到上限时补写「未执行」结果保持 wire 成对。工具过程在消息流内展示（调用行 `→ name(args)`、结果行 `⚙ name(path) ✓`，只渲染摘要不倾倒文件内容）；点击复制的语义：tool 行复制摘要、tool_calls 行复制调用清单。逐次确认模式（`ai.toolAutoRun=false`）下挂起等 `y`/`n`，`Esc` 取消确认。
- **上下文压缩**：估算 token（CJK 按 3 字符/token）超过 `ai.compactThreshold`（默认 24000）且消息数足够时，发送前先经同一条 curl 管线请求摘要，用 `[历史摘要]` 消息替换旧历史并保留最近 `ai.compactKeepRecent`（默认 6）条；保留区起点若落在 `role:tool` 消息上自动回退到其 assistant（不拆散 tool_call/tool 对）；压缩失败降级为不压缩、历史一条不丢。菜单「AI → 立即压缩对话历史」可手动触发。
- **对话持久化**：默认开启（`ai.persist=false` 关闭）。每次收尾/清空/退出落盘到 `~/.vicecode_ai`（`0600`），重启自动恢复对话与 provider/model；`Ctrl+L` 清空同步清档。
- **Markdown 渲染**：定稿消息（user/assistant）按 league/commonmark 解析为 AST 后自渲染 TUI span——标题 `#` 前缀、有序/无序列表（含嵌套缩进与编号）、引用 `│` 前缀、围栏代码块复用 scrivo 语法高亮、行内代码/链接/粗斜体各有主题色（`mdHeading/mdCode/mdQuote/mdLink`，dark 与 midnight 两主题同步）；**单换行保留为独立行**（终端聊天的排版根基）；流式中的消息走纯文本路径（每 token 全量解析会卡帧），定稿后按 `theme:md5(content)` 缓存逻辑行。新增 `DisplayWidth::spanWrapDisp()`（span 感知软换行，拼接不变量：所有物理行拼接 == 原拼接）。
- **AI 快捷动作**：菜单「AI」组（解释代码 / 加注释 / 重构建议 / 编写单元测试），进命令面板可搜；编辑器内 `Ctrl+E` 一键「解释代码」（自动附当前文件或选区、起全新对话直接发送）。
- **配置**：`.vicerc` 新增 `ai` 段：`persist` / `toolAutoRun` / `maxSteps` / `compactThreshold` / `compactKeepRecent` / `attachMaxBytes`。

### 新增（插件启用 / 禁用开关）

- **插件开关**：约定写在 `~/.vicecode.plugins.json` 的 `<id>.enabled`（**核心保留键**，不注入插件的 `configure()`、不进「有效配置」展示；缺省即启用，老配置零迁移）。入口两处等价：侧栏「扩展」tab 与「已安装插件」浮层里选中插件后按 `Space` 切换。
- **立即热生效**：切换当场重算「启用的插件子集」并重建命令 / 菜单 / 自定义面板注册表（**不重新实例化插件**，插件自身运行期状态保留），无需重启；直接改配置文件再 `Ctrl+S` 是**同一条生效路径**（重读配置 → 重算启用集 → 重建注册表）。
- **被禁用 = 整条链路退出**：不注入状态栏段（`statusSegments()` 不再被调用）、不参与 tick、命令从菜单与命令面板消失且快捷键解绑、自定义面板从浮层消失、不再接收生命周期事件；但仍列在插件页/侧栏并标注 `[已禁用]`（可再打开，否则禁用后无从恢复）。切换后状态栏给出明确回执。
- 文案新增 `plugins.disabled` / `plugins.toggled_on` / `plugins.toggled_off`，并更新 `plugins.hint`（中英两包同步）。

### 修复（AI 面板边界深挖）

- **Markdown 块级子节点内容整段丢失**：列表项 / 引用块内的子节点除段落外还可能是围栏代码块、嵌套列表、引用、标题——它们的 `children()` 为空（代码在 `getLiteral()`），原实现一律按行内展开 → 内容**静默消失**（`- 步骤` 下缩进的代码块、`> - a` 的列表整段不见，无任何报错）。改为「段落走行内展开（保留引用正文色），其余块级子节点递归 `renderBlock()` 后逐行挂前缀」；列表标记/续行对齐语义与空列表项标记一并保住。（`BUGFIXES` B11）
- **不可信内容原样写进终端（终端转义注入）**：模型回复与 `@文件`/工具读到的文件内容里的 `ESC ] 52 ; c ; … BEL` 会被逐字写进终端 → 可**改写系统剪贴板**（`ESC [ 2 J` 之类可清屏）；TAB 则因终端按 8 列制表位展开、`dispWidth()` 只按 1 列计，导致该行内容错位散行。新增 `DisplayWidth::sanitizeContent()`（TAB 展开为 4 空格 + 剔除**除换行外**的 C0/`\x7F`），在消息正文、工具调用参数、工具摘要、错误行与 AI 输入框（粘贴内容）入口统一净化；Markdown 在解析前净化（保留换行，块结构不受影响）。（`BUGFIXES` D5）
- **极窄面板下前缀把首行撑宽**：W 小于前缀宽（`You: ` / `AI: `）时首行 = 前缀 + 至少 1 列正文，远超面板宽 → 触发 php-tui 的 `LineTruncator` 折行，把后续行整体挤下去（幽灵行）。前缀改为按 `min(前缀宽, max(0, W-2))` 夹紧（给正文留 2 列，2 列宽字素也放得下）。（`BUGFIXES` B12）
- **Markdown 折行把前缀劈成两半**：前缀曾被当作折行流的第一个 span 交给 `spanWrapDisp`，缩进宽 > W/2 时会被从中间切开（实测 W=6 渲染成「`AI`」「`:`」两行）。统一为「前缀/缩进不进折行流」，与纯文本路径同语义。（B13）

### 新增（模型能力声明 / tools 开关）

- **模型能力（capabilities）**：`config/providers.php` 支持按模型声明能力——`tools`（函数调用）、`reasoning`（推理）、`vision`（识图）、`audio`（语音），写自定义名字也接受（UI 按原文显示，便于前向扩展）。两种粒度：`models` 里按模型声明（值写 `null` 走 provider 默认、写 `[]` 表示确实无能力），或 provider 级 `capabilities` 作为未声明模型的默认；仍兼容旧写法（`models` 为纯模型名列表）。**未声明 = 默认支持 `tools`**（老配置零迁移）。
- **`tools` 能力真正影响行为**：只有声明了 `tools` 的模型，请求里才带 OpenAI `tools` 协议（Agent loop 的 `list_files` / `read_file` 依赖它）。纯推理模型因此不会因为携带工具被服务商拒掉整轮请求——内置配置已把 `deepseek-reasoner` 预声明为 `['reasoning']`；`gpt-4o` / `gpt-4.1-mini` 标了 `vision`。
- **可见性**：AI 面板空态新增「能力: …」行（当前模型能力）；模型缺少 `tools` 时状态栏 AI 段带「无工具」标记，避免「Agent 工具从不触发」的无从排查。新增文案 `ai.caps` / `ai.caps_none` / `status.no_tools` / `cap.*`（中英两包同步）。

### 修复（交互终端）

- **每开一次交互终端，`/tmp` 里就永久多一个 `vicetui_rc_*`**：bash 的 `--rcfile` 集成脚本用临时文件承载，而 `TerminalPanel::pollPty()` 在 shell 退出（Ctrl+D / `exit`）时直接 `$this->pty = null` 丢掉实例、不调 `shutdown()`；`PtyProcess::shutdown()` 开头的「进程句柄已回收就 `return`」也**提前跳过**了文件清理。两条路径叠加 → 一次 shell 会话漏一个文件，应用退出也不会回收（本机实测攒了 33 个）。新增 `TerminalPanel::dropPty()`（先 `shutdown()` 再置空）并在四处丢弃点统一调用；`PtyProcess` 把 rc 文件清理抽成 `cleanupRcFile()`，早退分支也调用。（`BUGFIXES` D6）
- **交互 shell 还活着时异常退出 → 留下活着的孤儿 shell**：子进程回收原先只挂在 `Lifecycle::quit()` 的关闭闭包上，而只有正常退出会经过它；未捕获异常等路径走到 `bin/vicecode.php` 的 `start()` finally，那里只做了 `saveConfig + restoreTerminal`。实测每次异常退出漏 **1 个活着的 bash**（被 reparent 到 init、一直占着 pty，且**忽略 SIGTERM**、只有 SIGKILL 能收）外加一个 rc 文件。新增 `App::shutdownResources()`（幂等）并在 finally 里兜底，与 Lifecycle 路径重复调用无害。（`BUGFIXES` D7）

### 依赖

- 新增 `league/commonmark ^2.10`（Markdown 解析，只走 AST 遍历，不用其 HTML 渲染器）。

### 测试

- 新增 headless：`tests/ai_tools_unit.php`（路径安全 / Agent loop 端到端 / maxSteps / approve-deny / 流内渲染）、`tests/ai_md_unit.php`（Markdown 元素 / spanWrapDisp 拼接不变量 / 缓存命中）、`tests/ai_store_unit.php`（存取往返 / 0600 / Ctrl+L 清档 / persist=false）、`tests/ai_attach_unit.php`（@展开与拒绝 / 选区与当前文件附加 / 快捷动作与菜单）、`tests/ai_compact_unit.php`（自动触发 / 失败降级 / tool 对不拆散 / 手动压缩）。
- 新增 pty：`tests/pty_ai_v2.php`（三轮真实会话：@展开与两轮工具 / 重启恢复 / y 确认放行）。
- `tests/ai_unit.php` 的 SseParser 断言升级为结构化事件形状（V2 唯一破坏性 API 变更）；`tests/plugin_v11_unit.php` 菜单组索引断言随 AI 组插入顺延。
- 新增 `tests/plugin_enabled_unit.php`（headless：缺省启用 / `enabled` 不注入插件 / 禁用后状态栏段·tick·命令·菜单·面板·事件全部不参与 / 切换落盘且只改 `enabled` / 配置文件 + Ctrl+S 同路径 / 侧栏与浮层 Space）与 `tests/pty_plugin_toggle.php`（真实 pty：会话内标注由「已启用」翻为「已禁用」、重启后该插件根本未加载、再按 Space 段立刻回来、40×10 极小视口不崩）。
- 新增 `tests/ai_edge_unit.php`（headless：Markdown 块级嵌套内容不丢 + 既有排版零回归 / ESC·OSC·TAB·C0 进不了 span / 极窄 W=2…16 行宽上界 / 超长 4000 字符围栏的拼接不变量）与 `tests/pty_ai_inject.php`（真实 pty：mock 回复带 OSC 52 与 TAB，断言累计字节流里无带 ESC 的 OSC 载荷、无任何裸 TAB，而正文标记仍在）。两者都做过**强制失败注入**验证（停用 `sanitizeContent()` / 反转断言后 exit 非 0）。
- 新增探针 `tests/probe_ai_edge.php`（不进跑批）：Markdown 块级丢失 / 控制字符透传 / 极窄宽度行宽的取证样本。
- 新增 `tests/provider_caps_unit.php`（headless：能力解析全形状含旧写法兼容 / 端到端断言「只有声明 tools 的模型请求才带 tools」/ 状态栏标记与面板能力行的正反对照）与 `tests/pty_provider_caps.php`（真实 pty：临时替换 `config/providers.php` 后断言状态栏「无工具」标记出现，换回原配置后必须消失）。两者都做过强制失败注入验证。`examples/sse_server.php` 新增 `MOCK_ECHO_TOOLS=1`（回复 `[tools=1|0]` 回报请求体是否携带 tools）。
- **测试隔离修复（两轮，共 51 个测试 + 1 个新 helper）**：
  1. **配置目录不再落在 `/tmp` 根**：`tempnam()` / `sys_get_temp_dir().'/x.json'` 当 `VICECODE_CONFIG` 时，存档路径 `dirname(VICECODE_CONFIG)/.vicecode_ai` 会退化成 `/tmp/.vicecode_ai`——全体测试共用一份对话存档，`aiPersist` 默认开启，`App` 构造末尾的 `ChatModel::restore()` 会把**上一个测试的对话**恢复进来（「空态不是空的」、断言行号整体平移，随跑批顺序偶发）。**25 个测试**改用 `vc_isolate_config('tag')`。
  2. **配置不再依赖开发机家目录**：25 个建了 `App` 却完全没设 `VICECODE_CONFIG` 的测试会读真实 `~/.vicerc`（布局/主题/语言）与 `~/.vicecode.plugins.json`。实测把 `~/.vicerc` 换成非默认布局 + 其它主题语言、`~/.vicecode_ai` 放一份对话后，**6 个测试挂**（`m6_unit` 主题环、`menu_dropdown` 菜单标签、`pty_git` GIT tab、`ai_hscroll` 折行宽、`sidebar_hscroll` 折叠命中列、`m1_edge` 翻页边界）——即"只在我这台机器上绿"。全部改为 `vc_isolate_config()`（同时隔离 `VICECODE_CONFIG` 与 `VICECODE_PLUGINS_CONFIG`）。
  3. 新增 `tests/lib/isolation.php` 统一承载：`vc_isolate_config()`（独占目录 + 自动清理，pty 用例把返回路径塞进子进程 env，父子同源）、`vc_tmp_file()` / `vc_tmp_dir()`（替代裸 `tempnam()`，退出时自动删）。**41 处 `tempnam` 全部改走 helper**，`/tmp` 不再堆垃圾（含 `command_palette_unit` 与 `pty_ai_v2` 两处**从不清理**的目录——后者原来只 `rmdir` 空 `src/`，目录非空时静默失败）。
  4. 新增断言：`pty_interactive` / `pty_session` / `pty_alt_screen` 断言"运行前后 `/tmp/vicetui_rc_*` 数量不增加"（`BUGFIXES` D6 的防回归）；`pty_crash` 新增**场景 B**（交互 shell 活着时崩溃）断言 rc 文件与 `bash --rcfile` 孤儿进程都不增加（D7 的防回归），并补了"shell 真的起来了"的正向锚点。**注入验证**：把 `dropPty()` 改回 `= null` 后前三个用例全部 FAIL（0→2 / 0→2 / 0→5）；摘掉 `bin` finally 里的 `shutdownResources()` 后场景 B 的两条断言都 FAIL（rc 0→1、孤儿 +1）。恢复后全绿。完整根因与取证见 `BUGFIXES` T2 / D6 / D7。

---

## [0.0.2] — 2026-09-10

> 本轮主题：**边界深挖**（侧栏横滚 / 深层与超长路径 / 状态栏长值）+ **插件系统 V1.1**（命令钩子 / 状态栏段点击 / 生命周期事件）+ **插件自定义面板** + 若干修复。已提交至 `develop`，待合并 `master` 发版。

### 修复

- **侧栏树行「双重截断」**：先 `mbCutDisp(innerW)` 再 `mbSubDisp(hScroll)`，导致横滚看不到超出 innerW 的内容、`hScroll` 超过 innerW 后**整行空白**。改为只对完整文本切片。（`BUGFIXES` B5）
- **侧栏三角命中列漏减 `hScroll`**：横滚后点三角没反应、点文件名却展开/折叠；目录超过约 13 层时三角落在侧栏右边界外，**鼠标根本折不了**。命中列改为减 `hScroll` 并夹在可视区内。（B6）
- **GIT 行首图标命中列漏减 `hScroll`**：深路径横滚后点路径文本，会弹出「✕ 丢弃工作区改动」确认（不可逆操作）。判定改为先换算回文本列。（B7）
- **AI 面板 hScroll 无上界**：消息行是先软换行再渲染的，行宽本就不超过面板宽，横滚不会露出新内容、只会把左侧切掉；没有上界时一路滚下去**整片空白**（内容像丢了）。改为按「最宽行 − 视口宽」钳制，通常上界为 0。（`BUGFIXES` B8）
- **AI 消息续行末尾字符被吃掉**：正文按整宽折行、续行又加 4 列缩进 → 续行超出面板宽被切（实测 500 字符的消息拼回来只剩 456）。改为按「扣除缩进后的宽度」折行。（B9）
- **终端横滚后拖拽选区复制错位**：`getTextRect()` 把选区绝对列换算成文本列时**减**了 `hScroll`（符号反了），渲染是「屏幕列 = 文本列 − hScroll」，换算该加回来。结果横滚后选中屏幕上的字符，复制出来的是**行首那几个字**（常只有 1 个）。（`BUGFIXES` B10）
- **状态栏超长 cwd 整段消失**：120 列下 cwd 超过约 35 列就被整段丢弃。值型段（目录/文件/分支）改为按剩余空间截断保留**尾部**（`目录=…/尾部`），剩余宽度 < 12 列才整段丢。（E3）
- **外部值夹带控制字符**：shell 上报的 cwd、文件名、分支名里的 `\n`/ESC 不显示却占 1 列宽度，导致状态栏少显内容。新增 `DisplayWidth::stripControl()` 净化。（D4）
- **插件配置文件 `Ctrl+S` 不热加载**：热加载判断原只挂在 `App::menuAction('file.save')`，而编辑器内 `Ctrl+S` 走 `EditorPanel::onChar` → `EditorPanel::save()`，绕过 `menuAction`，导致 `reloadPluginConfig()` 永不触发。改为在 `EditorPanel::save()` 保存成功后判断 `path === ConfigStore::pluginsPath()` 并触发重载；并修 `ClockPlugin` 过时注释（原指向 `~/.vicerc` 的 `plugins.clock` 段）。新增 headless 回归测试（`tests/plugin_unit.php`）。

### 改进

- **侧栏 ←/→ 树导航**（VSCode 语义）：`→` 目录未展开则展开、已展开则选中第一个子项；`←` 已展开则折叠、否则（已折叠目录 / 文件）回到父节点；文件上按 `→` 无动作。帮助页与中英语言包同步新增 `help.x_nav`。
- **编辑器横滚上界改为「当前屏可见区最宽行」**：原先按**光标所在行**算，光标停在短行时整屏都滚不动。现在与右侧 `›` 指示符同源（指示说"右边还有内容"时就一定能滚过去）。

### 新增

- `DisplayWidth::mbTailDisp()`：按显示列宽取**尾部**（字素边界对齐），供状态栏截断复用。
- `DisplayWidth::stripControl()`：字节级剔除控制字符（非法 UTF-8 也不会让 preg 返回 null）。
- **插件系统 V1.1**（详见 `docs/plugins.md` §3.6–3.8，向后兼容 V1 老插件）：
  - **命令钩子**：插件通过 `commands()` 声明 `PluginCommand`，由菜单「插件（🔌）」组与可选快捷键（`Ctrl+字母` / `F1`–`F12`）触发，经 `executeCommand(string $id, App $app)` 执行。快捷键与系统保留键或其它插件撞键时自动降级为该命令不可用（仍可从菜单触发），并在「已安装插件」浮层给出原因。
  - **状态栏段点击**：`StatusSegment` 第 5 参数绑定命令局部 id，渲染时记录每段 `[起始列, 宽度]`，点击命中即触发其命令；被裁剪丢弃的段不可点。
  - **生命周期事件**：`onEvent(PluginEvent)` 接收 `app.ready` / `config.reloaded` / `focus.changed` / `editor.opened|saved|bufferChanged` / `terminal.output` 等事件，带防重入与单插件容错。`terminal.output` 给的是经过 pty 的**原始字节**（可能含 ANSI 与 OSC 7 的 cwd 上报），插件自行解析。
  - 配套值对象 `src/Plugin/PluginCommand.php`、`src/Plugin/PluginEvent.php`；加载支持环境变量 `VICECODE_PLUGINS_DIR` 覆盖插件根目录。
  - **插件自定义面板**：插件经 `PluginPanelHost` 在 ViceCode 内渲染自己的面板，`PluginPanel` 值对象声明面板元信息；侧栏「扩展」tab 与「已安装插件」浮层同步扩展，可查看 / 触发插件面板。配套 `src/Panel/PluginPanelHost.php`、`src/Plugin/PluginPanel.php`；`PluginInterface` / `StatusSegment` 扩展，插件管理浮层与侧栏扩展 tab 若干交互问题修复。

### 测试

- 新增 `tests/sidebar_hscroll.php`：侧栏横滚六个场景 —— 长名目录行文本切片 / 30 层深树三角命中 / 超长文件名（含 CJK、205 列）/ GIT 深路径与行首图标命中 / Search 长路径与长命中行（含分组折叠）/ 极小视口（40×10、20×6、12×6）三 tab 超大 hScroll 与超长分支名。
- 新增 `tests/pty_sidebar_hscroll.php`：真实 pty 复验 —— 横滚到底长名目录尾部 `TAIL` 可见、超长文件名尾部 `zzend` 可见、点新位置能 toggle 而点旧位置不 toggle、横滚后点文件能打开进编辑器。
- 新增 `tests/ai_hscroll.php`：AI 面板超长消息（ASCII 长串 / CJK）—— 每行不超宽、拼接回原文逐字符相等、横滚上界（预设 500 与连续 100 次横滚都不越界且画面非空）、横滚后点击仍复制整条消息、极窄视口不崩。
- `tests/m1_smoke.php` 增加侧栏 ←/→ 导航 6 条断言（展开 / 进子项 / 回父级 / 折叠 / 文件上无动作）。
- `tests/hscroll_unit.php` 补充五节：横滚后点击定位光标（ASCII / CJK —— 屏幕显示列必须加 `scrollLeft` 才是字符索引）、超长行 200k（上界钳到「行宽 − 视口宽」、行尾可见、渲染 60ms）、极小视口（8×3 / 5×3 / 2×2）横滚不为负不抛异常、**终端长输出**（5000 列上界与行尾可见、横滚后拖拽选区复制的内容必须跟着偏移、极小视口）、**多行文件光标在短行也能横滚**（上界为可见最长行）。编辑器相关节**没发现新 bug**（本来就正确），终端那节抓到 B10。 `tests/probe_edge_deep.php`、`tests/probe_tree_hscroll.php`（`run_tests.sh` 会跳过带 `probe` 的文件）。
- `tests/m6_unit.php` 增加「状态栏长值截断 + 外部值净化」11 条断言。
- 新增 `tests/plugin_v11_unit.php`（headless）：命令注册 / 菜单合并且不破坏前 4 组 action / 快捷键三级冲突（系统保留 · 占用 · 语法不支持）/ 状态栏段点击命中（逐列含 CJK、dropped 段不可点、confirm 态不可点）/ 生命周期事件序列（标准 6 点 + 防重入）。所有新断言做过回退注入校验。
- 新增 `tests/pty_plugin_v11.php`：真实 pty 复验 Ctrl+K 快捷键、`F10`→右×4→`Enter` 菜单链路、状态栏段点击（含点空白列不触发）三条端到端路径。
- 全量 **54/54 通过**（`tools/run_tests.sh`，约 244s）。所有新断言都做了「回退修复注入」校验，确认能失败。

### 发版前要做

- 把 `docs/BUGFIXES.md` 里标 `[本次]` 的条目换成实际提交 hash（`[@xxxxxxx]`）。
- 更新 `composer.json` 的 `version` 字段，并 `composer update --lock` 同步哈希。
- 跑 `./tools/run_tests.sh` 确认全绿；`composer test` 是同一入口。

---

## [v0.0.1] — 2026-09-08（tag `758a2c9`）

首个定版。功能范围（里程碑 M0–M7 + R5/R7/R8）：

- **六面板布局**：Sidebar（资源管理器 / GIT / 搜索 / 插件）+ 编辑器 + 终端 + AI 对话 + AI 输入 + 状态栏；键鼠焦点、拖拽分隔条。
- **编辑器**：目录树懒展开、多 Buffer、语法高亮、行号光标、`Ctrl+S` 保存、未保存确认。
- **终端**：真实 PTY 交互（F2 进捕获）、会话持久化、实时 cwd 捕获、单元格颜色序列化、交替屏程序（vim/less/top）干净退出。
- **GIT**：status / log / 分支切换 / diff / 提交推送。
- **AI**：OpenAI 与 DeepSeek 共用 Provider，curl 子进程流式输出。
- **插件系统 V1**：运行时动态加载 `plugins/*/plugin.json`，可往状态栏加段（`clock` 为内置示例）。
- **文本选择与剪贴板**：OSC52 写入 + 内存降级；编辑器/终端拖拽矩形选区。
- **i18n**：zh_CN / en 双语，缺失 key 回退英文；配置持久化在 `~/.vicerc`。

### 已知

- 该 tag 只包含到 `5c0d2df`（9/7 封版），**不含 9/8 之后的打磨**：`composer.json` 没写 `version` 字段、英文模式下插件页仍显示中文「(无插件)」、`tools/run_tests.sh` 跑批脚本、`pty_demo` 的失败出口修复、`docs/BUGFIXES.md` 都不在 tag 内。

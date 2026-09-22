# 更新日志

> 本文件是**发版摘要**：每个版本改了什么、该跑什么。
> 每个 BUG 的**现象 / 根因 / 修复 / 防回归测试**都在 [docs/BUGFIXES.md](docs/BUGFIXES.md)，
> 那里是根因的权威归处，本文件不重复细节。

版本格式：`[版本号] — 日期`，未发版的改动都归在 `Unreleased`。

---

## [Unreleased]

> 本轮主题：**AI V2** —— 把 AI 从「孤岛聊天框」接进工作台：代码上下文、只读工具 Agent loop、上下文压缩、对话持久化与 Markdown 渲染。

### 新增（面板显隐 + 终端最大化）

- **侧栏 / AI 栏 / 终端三块面板可随时收起展开，终端可最大化**（中列只留终端、编辑器让位，左右两列保留）。入口**只在菜单「视图」与命令面板（`F1`）**里 —— 刻意不占 Ctrl 快捷键（用户定的）；菜单文案**随当前状态切换**（显示「隐藏侧栏」= 现在可见），文案不说谎。
- 新增 `visiblePanels()` / `focusNext()` / `ensureFocusVisible()`：**焦点若落在被隐藏的面上，自动回落到最近的可见面板**；`Tab` / `Shift+Tab` 只在可见面板间循环（`App::PANELS` 常量**不动** —— 大量测试按它取 focusIndex，可见性是叠加在它之上的一层过滤）。
- 隐藏终端会**一并取消最大化**（不留「隐藏着又最大化」的矛盾态）；反过来在隐藏状态下点「最大化终端」会先把它显示出来，而不是点了毫无变化。
- 状态栏在有隐藏/最大化时追加 `隐藏:侧栏/AI/终端` / `终端最大化`（**默认态输出一字不变**，避免动到既有宽度/内容断言）。
- **根除索引移位（本期最大的风险）**：`LayoutFactory::split()` 原用**定位索引**取矩形（`$main->get(1)` 当中间列、`$main->get(2)` 当 AI 列），一隐藏侧栏就把 AI 的矩形当成中间列、整个界面错位。改为 **keys/constraints 对偶**：`mainKeys()/mainConstraints()`、`centerKeys()/centerConstraints()`，`split()` 与 `build()` 都按同一对 keys 遍历、按下标与切出的矩形配对，**返回数组里没有隐藏面板的键**；中列至少保留一段（最大化→终端，隐藏终端→编辑器）。
- **只构造可见面板的 widget**：`content()` 不是纯函数（编辑器每帧在里面更新横滚边界、终端在聚焦首帧会真的起 shell，隐藏时都不该跑），而且隐藏面板的 `$a['…']` 根本不存在。
- **守卫所有 `$a[...]` 硬引用**：四条分隔条的拖拽判定、`click` 命中、拖选（`updateSelect` / `finishSelect`）、补全锚点、自动捕获路径的终端矩形。隐藏后点它原来的位置、或拖到一半把它隐藏，都不能变成 `Undefined array key` / 对 null 取属性。拖拽上界也改成只保留**可见**列的宽度（AI 隐藏时那部分宽度可以全给侧栏）。
- 偏好落盘：`layout` 段新增 `sidebarVisible` / `aiVisible` / `terminalVisible` / `terminalMaximized` 四个布尔；`LayoutConfig::fromArray()` 用**宽松布尔解析**（手写 `"false"` / `0` 也要读成隐藏 —— `(bool) "false"` 是 `true`，是个经典坑）。
- **测试**：新增 `tests/panels_unit.php`（areas() 键集与约束段数同源 / 隐藏后标题真的消失、其它面板照常 / 焦点回落 / Tab 跳过隐藏面板 / 最大化时中列只剩终端 / 隐藏与最大化的相互作用 / 菜单与命令面板两个入口 / 隐藏态下点原位置不崩 / 落盘往返）；同步 `menu_unit`（27→31 项 + action 白名单）、`plugin_v11_unit`（前 4 组 action 序列 +4，插在 `view.lang` 之前）、`command_palette_unit`（27→31、过滤 `terminal` 收敛到 3 项）、`r7_unit`（显隐字段的加载 / 落盘 / 字符串布尔断言）。
- **六条反向注入全部实测变红**（均为 `[FAIL]` 断言失败）：`visiblePanels()` 忽略配置 / 不做焦点回落 / 去掉拖拽与点击守卫（会捕获到 `containsPosition() on null`）/ 不清矩形缓存（切回显示时命中到旧矩形）/ 隐藏终端不取消最大化 / `fromArray` 用 `(bool)` 直转。

### 变更（终端默认进交互式 shell：别名 / 函数 / 全屏程序直接可用）

- **终端面板默认就是一个真实 PTY**（`bash --rcfile`，会 source 用户的 `~/.bashrc`）：**别名 / 函数 / 提示符都在**，`ll` 这类别名直接可用（用户报障原文就是「终端里 `ll` 用不了」），`vim` / `top` / `less` / `ssh` 等全屏程序也能跑。原先默认的「命令运行器」保留为 **shell 退出（`exit` / `Ctrl+D`）后的回落态**，不再是启动态；runner 仍用 `sh -c`，因此**看不到**别名——这一点写进了 README 与使用手册。
- **聚焦后直接打字即接管键盘**：第一个可打印字符或回车自动进捕获并把该键转发给 shell，不必先按 `F2`。判据刻意**只认「打字」**：`Tab` / `Shift+Tab` 仍切焦点、`?` 仍开帮助页、`Esc` 仍退出应用、方向键 / `PageUp` 仍翻回退 —— 少任何一条，焦点一进终端就再也出不来（帮助页更会永久打不开，因为捕获态的按键全部转发给 shell）。
- **shell 只在「聚焦后的首帧」起**，不在构造时起：`content()` 与焦点无关、`App::render()` 每帧都构造它，若首帧无条件 spawn，几十个 headless 渲染用例会各起一个 bash（孤儿进程 + 秒级开销）。未聚焦且 shell 未起时面板显示「（聚焦此面板即启动交互式 shell）」。
- 自动捕获路径上会**先用当前面板尺寸把 shell 起好再转发**：触发捕获的那个键就在这一帧的事件里，等渲染完再转发它已经丢了（实测一次性灌入 `exit\r` 时首轮只处理事件、三个字节全丢）。
- **修 Esc 在非捕获 pty 下被静默吞掉**：判据原先是 `isRunning()`，而 pty 模式下它**恒真**（交互 shell 一直活着）→ 落进 `cancel()`（对 pty 是空操作，什么都不发生）。改为只在 **runner** 模式走「中断命令 / 清空输入」，否则退出应用。
- **修 shell 退出瞬间的按键被喂给已经死掉的 pty**：主循环每轮才 poll 一次，同一批按键里靠后的部分会落进 runner 输入行 —— 表现为命令被**截掉开头**（实操：`printf '…` 的前 8 个字节连开引号一起没了，`sh` 报 `unexpected EOF while looking for matching '`）。新增 `TerminalPanel::syncShellState()`，在按键与粘贴路径上先结算一次 shell 状态（真实场景：刚 `exit` 就粘贴一条命令）。
- **粘贴在非捕获 pty 下也接受**（粘贴即打字，先进捕获再转发）：原先直接忽略，默认 pty 下按 `Ctrl+V` 毫无反应。
- **修「刚起 shell 就打字，命令被丢掉」**：bash 在 readline 初始化之前会**丢弃**先到的输入 —— tty 回显看得见那行命令，但它既不执行、也不留在行里，随后直接出现一个新提示符。默认 pty 把 shell 的启动时机和用户第一次按键拉到了一起（聚焦即 spawn），启动慢的 `.bashrc`（nvm/pyenv/conda）能把这个窗口拉到几百毫秒，人手速足够快就会撞上。现在 `TerminalPanel` 会把就绪前的按键**攒起来**，等提示符（`PROMPT_COMMAND` 的 cwd OSC）或兜底时限到了再按原顺序补发。⚠️ 兜底时限的判定必须放在 `pollPty()` 的**任何 return 之前**（含「本轮无输出」那条），否则「bash 还没打出提示符」这种正是要兜底的情形永远走不到、攒下的按键成了死锁（实测 `pty_term` 段 A 直接挂住）。
- **新增退出兜底回收**：`register_shutdown_function` 里回收 pty 子进程。产品的正常/异常退出路径早已覆盖（Lifecycle 关闭闭包 / bin 的 finally / 信号 handler），但**直接 `new App()` 的调用方**没有任何保证 —— headless 用例只要「聚焦终端 + 渲染一帧」就会真起 shell，进程结束后它被 reparent 到 init 一直活着（实测 `command_palette_unit` 漏 1 个 bash + 1 个 rc 文件；交互式 bash **扛得住 SIGTERM**，只有 SIGKILL 收得掉）。
- `PtyProcess::pollExited()` 的退出码改为**只认第一次**（`proc_get_status()` 之后再问会变成 -1），因为它现在有了第二个调用点（按键路径）。
- **脚本/自动化喂键的坑（写进测试注释）**：非捕获 pty 下敲**任何可打印字符**都会自动进捕获 —— 于是「Esc 退捕获 → 再按 `q` 想退出应用」会变成「q 又被当成打字、重新进捕获」的死循环，脚本永远退不出去。退出应用一律用 `Ctrl+Q`（捕获态下它会被转发给 shell，所以先 Esc 退捕获再 Ctrl+Q）。
- 文案：底部提示行改为「在此输入即接管键盘（Esc 退出程序）」；`exit` 后的横幅补「F2 可重新进入」；帮助页 `F2` 条目改为「F2 切换终端键盘捕获（交互 shell 是默认模式）」。标题保持不随默认模式变化（只有捕获态才显示 `交互终端 · 捕获中`）。
- **测试**：新增 `tests/pty_term_alias.php`（真终端：自造 `HOME` 的 `.bashrc` 里 `alias ll=…`，断言敲 `ll` 跑出别名结果、且整份输出**没有** command not found；再 `exit` 落到 runner 后同样的 `ll` **确实** command not found —— 反向对照证明成功来自别名而非「`ll` 恰好是个真命令」）；新增 `tests/term_default_unit.php`（headless：自动捕获只认打字、Tab/`?`/修饰键/方向键都不捕获、Esc 不空吞、shell 退出后按键不丢、**刚起 shell 就打字命令仍被执行**）。
- **⚠️ 一条测试环境的硬事实（本轮踩得最久）**：交互式 shell 会 source 用户的 `~/.bashrc`，而开发机那份要加载 **nvm + conda**（`conda shell.bash hook` 真的起一个 Python 子进程）—— 启动要**数秒**且随机器负载抖动。任何「打字 → 等结果」的 pty 用例若把窗口写死在几百毫秒，就会随负载偶发假红（实测同一脚本 3/6 红）。对策：测终端管线的用例给一个**自造 HOME**（里面只有一行 `.bashrc`），并把盲等改成**等锚点出现**（`pty_term` 的 `runApp` 支持 `[字节, 上限, 锚点]`）。
- 迁移：`pty_term`（拆成**两次运行**：pty 段「Tab×2 直接打字 → 42 渲染」+ runner 段「预置 runner 快照起手 → 退出码/stderr/中断」，两段都用自造 HOME 与条件等待；原先把两段塞进一次运行，`exit` 之后 shell 退出与命令输出同轮发生、输出会随仿真器一起丢，偶发假红）、`pty_scroll`（runner 场景改用**预置的 runner 会话快照**起手——原先靠脚本喂 `exit` 会被 bash 启动快慢牵着走、偶发假红；pty 场景改为自动捕获，顺带覆盖 `?` 打开帮助层）、`pty_rc_cleanup`（新增「不调 `shutdown()` 的调用方在进程退出后不留交互 shell / rc 文件」场景，带「子进程运行期间确实起来过 shell」的正向锚点）、`interactive_term_unit`（默认 pty / 未聚焦不起 shell / 聚焦首帧起 shell）、`m2_smoke` / `scroll_unit` / `hscroll_unit` / `clipboard_unit` / `selection_unit` / `session_unit` / `plugin_v11_unit`（测 runner 语义的用例显式钉住 `mode = 'runner'`，不再依赖默认值）。
- **九条反向注入全部实测变红**（且都是 `[FAIL]` 断言失败而非崩溃）：关掉自动捕获 / 过度捕获（连 Tab、`?`、修饰键一起吞）/ Esc 判据退回只看 `isRunning()` / runner 快照不回落 `mode` / 未聚焦也 spawn / 去掉 `pollPty` 的「待启动」保留判据 / `syncShellState()` 空操作 / 粘贴不自动捕获 / 去掉就绪前的按键缓冲。
- **顺手修两条与本轮无关、但同样随负载偶发假红的旧断言**（都会让跑批结果不可信）：`pty_strategy` 的「状态栏标出 `ZZSMART·自动`」原先在策略段切换后**立刻**读帧，而 AI 段要等自动选档解析出模型、慢一帧 → 改成 `waitForFrame` 等它出现；同文件的「退出仍还原终端」在排空收手后**没再读一次**，应用退出前写的 `?1049l` 可能还压在 pty 缓冲里 → 收尾补一次读取。（两条都是「单独跑全绿、批跑偶发红」。）

### 修复（没选模型却显示模型名）

- **一次都没选过 provider/模型，标题栏、状态栏、AI 空态就声称「正在用 OpenAI/gpt-4o-mini」**（`BUGFIXES` B14）：展示层拿 `ChatModel::spec() !== null` 当「用户选过模型」的判据，而 `spec()` 在未选时会**兜底到 `defaultId()`** —— 只要配了 provider 就恒非 null。新增 `ChatModel::hasSelection()`，三处展示改用它；未选时分别显示「`AI 对话`」「`AI=未选`」「未选模型 · Ctrl+P 选 Provider、Ctrl+N 选模型」。发送路径不变（未选时请求仍走默认 provider，那是实现细节）。
- **⚠️ 判据是 `providerId !== null || model !== null`，不能只看 `providerId`**：`cycleModel()`（Ctrl+N）只写 `$model`、不写 `$providerId`（有意保留「Provider 还是默认那个」的语义），所以「从没选过 → 直接按 Ctrl+N」会得到 `providerId=null, model!=null` 这个合法状态。
- 新增 `tests/ai_selection_unit.php`（未选三处都不出现模型名 / `useProvider` 后都出现 / **只 Ctrl+N 也算已选** / 反面对照），**四条反向注入全部实测可 FAIL**。原先靠「默认 provider 一定显示」当锚点的 `pty_provider_caps` / `pty_providers` / `provider_caps_unit` 已改为**显式选一次再断言**。

### 新增（编辑器多光标：多行同时编辑）

- **Alt+↑ / Alt+↓** 在上/下一行加一个编辑光标，**Alt+点击**在点击处加一个；此后打字、退格、Delete、Enter、Tab/Shift+Tab 缩进都**同时作用于所有光标**；**Esc** 取消多光标回到单光标（单光标时 Esc 仍是原来的全局退出语义）。状态栏在 `文件=` 段追加「N 个光标」，否则用户不知道自己处于多光标态、也就想不到用 Esc 收掉。
- **每行最多一个光标**（刻意简化）：同行多光标会让 Enter/退格在**同一行内**分裂出复杂的行列位移，而本功能的用途是"在多行上同时改"。Alt+点击落在已有光标的行不新增；Alt+↑/↓ 到顶/到底时**什么都不做**（不把主光标挪走，否则会跑到已有光标的行上形成歧义态）。
- **多光标下明确不参与**的两项：**自动配对**（不同位置"该不该配对"可能不同，一次输入产生不同结果无法预期）、**粘贴**（只在主光标处插）。两条都写进了测试的反面对照。
- 实现要点：`Buffer` 加 `extraCursors`（主光标仍是标量，单光标路径与改造前完全一致、零额外开销）；扇出 `editsAtEachCursor()` 按 (行,列) **降序**逐个复用既有单光标原语，行数变化用 **`count($lines)` 的差值**补偿已处理光标（⚠️ 不能取"光标自己的行号变化"——`delete()` 在行尾会并掉下一行但光标行号不变）；渲染侧 `SpanClip::clip` 的签名由「单布尔 + 单列」改为**光标列数组**。
- 顺带确认了一个前提（本轮最大的技术风险）：**Alt+方向键在真实终端可用**（xterm 的 `;N` 修饰位，`ESC[1;3A` → `KeyModifiers::ALT`），**Alt+点击也可用**（`MouseEvent::$modifiers` 来自 SGR 按钮码的 `bit8`，不需要改 vendor）。这与项目里「Alt+**字母**不可用」的旧结论不矛盾：字母键没有承载修饰位的位置。

### 新增（编辑器：符号自动配对，可配置）

- **自动配对**：编辑器里打左符号自动补右并把光标夹在中间。`.vicerc` 的 `editor.autoPairs` 配置列表（每项「左+右」两个字符）；**默认集 `() [] {} "" ''`，刻意不含 `<>`**（PHP 里 `<` 是运算符，自动补 `>` 会干扰比较运算；想要自己加，README 与配置说明里有示例）；显式写 `[]` 即关闭（与"没写"区分开，否则没法真的关掉）；脏条目（非字符串 / 长度不等于 2 / 重复）逐条丢掉而不是整体失效。在编辑器里保存 `~/.vicerc` 即生效（缓存随保存失效）。
- **四个行为细节**（用户逐条确认）：① 右侧已经是同一个右符号时**跳过**而不是重复插（`()` 中间再打 `)` 不会变成 `())`）；② 光标夹在空对中间时退格**一次删两个**；③ 有选区时打左符号**包裹选中内容**（多行选区也支持）；④ 引号紧跟字母/数字/下划线之后**不配对**（`don't` / `it's`）。
- 实现：决策抽成纯函数 `App\Editor\AutoPair::plan()`（`SKIP` / `PAIR` / `PLAIN` 三种结局，可直接单测每条规则）；`Buffer` 新增 `insertPair()` 与 `replaceLineRange()`（后者跨行，供选中包裹用）；选中包裹的屏幕→buffer 坐标映射与 `getTextRect()` 共用同一套原语，避免"包出来的范围与复制到的不一样"。

### 新增（Tab 补全 / 缩进机制，含插件扩展点）

- **Tab 在输入上下文里重定义为「补全 / 缩进」**：有候选时 `Tab` 接受、`Shift+Tab` 选上一个候选、`Esc` 只关候选（不再顺带退出程序）；无候选时 `Tab` 在**多行上下文**（AI 输入框 / 编辑器）插 4 空格缩进、`Shift+Tab` 反向缩进（编辑器删行首、AI 输入框删末尾）。**单行输入（搜索框 / GIT 提交框）与其它面板不吞 Tab**，仍是切焦点 —— 单行没有"行"可缩进，这样键盘切面板的能力也不丢。编辑器没打开文件（无可缩进处）时同样落回切焦点。`Shift+Tab` 此前**全项目零处理**（被静默吞掉），现在在非输入上下文用作**反向切焦点**，与 `Tab` 对称。
- **`@文件` 路径补全（AI 输入框）**：输入 `@` 即弹出项目内路径候选，支持前缀过滤、目录带尾斜杠（便于继续往里补）；路径安全复用 `AiTools::resolve()`（realpath + 根前缀校验），`../` 与出根 symlink 一律不会出现在候选里。候选浮层复用 `DropdownOverlay` 的**透明叠加**机制贴在被补全的那一行上（AI 输入框贴其上沿、编辑器贴光标行、侧栏贴输入行），底层输入框仍看得见。
- **插件扩展点 `completions()`（V1.2，可选能力）**：插件为 `ai_input` / `editor` / `search` / `commit` 四个上下文提供候选（`list<CompletionItem>`，含 `label` / `insert` / `detail`）。**按键由核心独占、插件只提供数据** —— 插件拿不到按键，因此不可能把全局键玩坏，也不必关心候选怎么画（与既有「声明数据 + 核心渲染」同一取向）。内建的 `@文件` 补全与插件候选走**同一条路**，所以这个扩展点是被真实功能用着的，不是预留接口。插件 provider 抛异常或返回脏类型时只跳过它自己（每次按键都会重算，插件 bug 不该让打字废掉）；启用/禁用沿用同一套「整体重建、只遍历启用子集」链路，`Space` 一关候选即消失。
- **核心不判触发符**：`@` 只是内建文件补全自己的约定，`$prefix` 会把光标前那段非空白 token 原样交给 provider，插件可以认任何前缀（例如 AI 输入框已有的 `/plan` 指令前缀）。
- 文档：`docs/plugins.md` 新增 §3.12（含参数表与两个约束：同步调用、失败只影响自己）并更新 §9；`README` 中英补一段；帮助页新增 `Tab` / `Shift+Tab` 两条说明（原 `help.g_focus` 停用）。

### 新增（Claude 原生 Provider：Anthropic Messages API）

- **协议抽象**：新增 `App\Ai\ProviderInterface`（`buildCommand` / `httpStatus` / `metaFile` / `cleanup`）与共用的 curl+SSE 传输 trait `CurlSseTransport`（**安全关键代码不复制两份**：key 走 `-H @文件` 不进 argv、body 走 `--data-binary @文件` 避开 ARG_MAX、0600 临时文件、`-D` dump 取状态码）。`OpenAiCompatProvider` 行为零变更。
- **provider 级 `protocol` 显式声明**：`'protocol' => 'openai'`（默认，老配置零迁移）或 `'anthropic'`。未写即 openai；写了不认识的值也退回 openai（不静默变成另一种协议）。端点按协议拼：Anthropic 为 `<base>/v1/messages`（base 已带 `/v1` 时去重）。
- **Anthropic 协议实现**：`x-api-key` + **必需的 `anthropic-version: 2023-06-01`** 头；`system` 作为**顶层参数**（Messages API 没有 system 角色）；`max_tokens` **必填**（provider 级 `max_tokens` 可配，默认 4096）。
- **工具完整往返**：`tools` 转 `{type:'custom', name, description, input_schema}` 形状；assistant 的 `tool_calls` 转 `tool_use` 内容块（`input` 序列化为**对象**，空参数是 `{}` 而非 `[]`）；连续 `role:tool` 合并为**同一条 user 的多个 `tool_result` 块**（用 `tool_use_id` 关联）。**内部消息形状（OpenAI 语义）保持不变**——协议转换只发生在 provider 出口，存档 / 渲染 / 复制三处零改动。
- **`AnthropicSseParser`**：`content_block_delta`（`text_delta` 与 `input_json_delta` 按 `index` 归并到对应工具块）、`message_delta.stop_reason` → finish、**以 `message_stop` 判定结束**（Anthropic **不发** `data: [DONE]`）、`ping` 忽略、`error` 事件映射为错误。与 `SseParser` 同契约，`ChatModel` 侧零改动。
- **内置一条 `anthropic`**：模型 `claude-opus-4-8` / `claude-sonnet-4-6` / `claude-haiku-4-5-20251001`，key 取 `ANTHROPIC_API_KEY`、base 可用 `ANTHROPIC_BASE_URL` 覆盖；`ConfigStore::providersTemplate()` 同步补 `protocol` / `max_tokens` 写法说明。

### 新增（状态栏可点段：语言 / 主题的选项列表）

- **点状态栏的 `语言=` 段 → 在状态栏上方弹出语言列表**：`↑`/`↓` 移动（环形、不越界）、`Enter` 或**单击列表项**应用、`Esc` 或点别处关闭。列表里当前语言前标 `●`；进列表时高亮落在**当前值**上（不是第一项），避免"顺手回车把语言点乱了"。
- **做成通用机制，不是给语言写死**：段规格新增可选 `pick`（与插件段的 `cmd` 互斥），`clickSegment()` 命中后返回该段的 `k/cmd/pick/x0/x1`；App 侧 `openPicker(id)` 按 id 给数据源。目前已接两个：`locale`（数据源 `Translator::available()`）与 `theme`（`Theme::ids()`）。
- **状态栏新增常显的 `主题=` 段（可点）**：与 `语言=` 段相邻。为此把 `app=ViceCode` 段的**丢弃优先级降到最低** —— 状态栏要腾地方给两个可点段时，「应用名」是这里唯一的纯装饰（单应用终端里没有第二处需要它），而 `focus` 段又必须继续在 120 列被丢掉（`m6` 有断言：它有边框高亮、本就冗余）。实测 120 列（中文、打开了文件）下两个可点段都在，英文因标签更长到 140 列才齐。
- **主题**：菜单「视图 → 主题」打开同一个列表；`Ctrl+T` **仍是快速循环**（快捷键适合循环、菜单适合给列表，两者并存不冲突）。
- **菜单「视图 → 语言」也改成打开列表**（原先是在两种语言间盲目循环，切到哪个全靠记）。`toggleLocale()` 随之删除 —— 它已无 UI 路径。
- 浮层复用 `DropdownOverlay` 的透明叠加（不动底层），几何由 `PickerOverlay::geometry()` **一处**给出、**渲染与鼠标命中共用**。
- ⚠️ 列表打开期间**独占按键**（字符键也吞掉，免得顺手打进编辑器），但**功能键放行**（F10/F1/F2 照常生效，只是顺手关掉列表）。
- 语言名刻意**不进 i18n 包**（`中文 (zh_CN)` / `English (en)`）：语言名是专名，该用它自己的语言写，否则用户在看不懂当前界面语言时反而找不到自己的语言。

### 修复（GIT「Commit ▾」的三角点不到）

- **点 `Commit ▾` 右边的三角没反应**（`BUGFIXES` E4）：渲染把 `▾` 画在 `' Commit '`（8 列）之后，命中判定却写成了 `innerX + $innerW - 1`（**面板最右一列**）—— 两者差大半个面板宽，于是点真正的 ▾ 落进 `else` 去 `commit()`（空消息还提示「提交信息为空」）。修法：按钮文案提成常量 `GIT_COMMIT_LABEL`，**渲染与命中判定共用**它算列。
- **测试为什么没发现**：`git_unit.php` 原先**照抄了产品代码那条错公式**算 `$arrowCol`，两边一致地错、一直绿。现改为**从渲染帧逐格量出 `▾` 的列**再点。

### 文档（完整使用手册 + 帮助页补齐）

- **新增 `docs/manual.zh.md` / `docs/manual.md`（中英双语完整使用手册）**：从环境要求与启动、界面与布局、通用按键，到编辑器（含 Tab 缩进/自动配对/多光标）、终端两种模式与会话持久化、资源管理器、GIT 面板（提交动作下拉、分支切换）、搜索面板、AI 助手（协议/能力/策略/自动选档/`@路径` 补全/Agent loop/压缩/持久化/渲染/快捷动作）、配置全表、插件系统、故障排查（含终端被留成乱码时的自救命令）。README 只保留功能概览并链过去。
- **帮助页补齐本轮新功能**：全局组加 `Alt+点击`（加光标、不产生选区）；编辑器组加 `Tab / Shift+Tab`（缩进 / 反向缩进）与 `() [] {} "" ''`（符号自动配对，说明其四条行为）；AI 组加 `@`（引用项目内文件）与 `Tab / Shift+Tab`（接受补全 / 缩进）。此前这些能力在帮助页只体现为一条合并的全局说明，用户查"打 `(` 为什么自己补了 `)`"时找不到出处。
- `tests/m6_unit.php` 增一条**帮助页覆盖漂移守护**：断言上述六条（含既有的 `Alt+↑/↓`）的 desc 仍登记在 `KeyBindings::all()` 里，删掉任一行即红。三条注入验证可失败（删自动配对 / `@` 引用 / `Alt+点击` 登记行）。

### 修复

- **被信号终止时终端不还原、且没有任何日志**（`BUGFIXES` D11）：D10 修的是「致命错误不执行 `finally`」，靠 `register_shutdown_function` 兜底 —— 但**信号根本不走 shutdown function**。没装 handler 时默认动作是「进程立即终止」：既不跑 shutdown function、也不走 `finally`，而 `error_get_last()` 是 null（**信号不是 error**）→ 终端留在 raw + 备用屏 + 鼠标上报（与 D10 表象一模一样的 `-bash: 35: command not found`），**日志也不写**，事后完全查不出发生过。现装 SIGTERM / SIGHUP / SIGINT 处理（在终端被改动之前装）：收尾走同一份幂等闭包 + 写一行「被信号终止：SIGTERM（终端已还原）」进日志。⚠️ handler 里**不能** `exit(128+$sig)`——协程内 Swoole 会把它转成 `Swoole\ExitException`，被顶层 catch 接住后退出码变 1；正解是「恢复默认处置 + 重发信号并直接返回」（实测两种底座都得到 `signaled=true, termsig=15/1/2`）。用 pcntl 而非 `Swoole\Process::signal`，两种底座行为一致。
- **新增「本次会话还活着」标记**（`<配置目录>/.vicecode_alive`，`BUGFIXES` D11）：启动时写、**只有正常退出**才删；下次启动发现残留就往日志写 WARN + 状态栏提示。它覆盖**连信号 handler 都够不到**的那类死亡 —— **SIGKILL / 段错误**：进程没有任何机会执行代码，这个标记是唯一能证明「上次异常结束」的东西。标记带 pid 并做存活检查，否则同时开两个实例时，先启动那个的正常退出会被误报。
- **压缩摘要请求是空请求**（`BUGFIXES` T6）：`beginCompact()` 原先只把历史挪走就 `startRequest()`，发出去的是 `{"role":"assistant","content":""}` —— 既没历史也没指令，真实端点上等于让模型续写空回复、续写文本被当摘要**覆盖整段真实历史**。改为构造真正的单条 user 消息（i18n 指令 + `ConversationTranscript` 文本化的历史）。新增 `ConversationTranscript`（角色前缀、工具调用/结果可读化、按字符截断）与 `ai.compact_instruction` 文案（中英两包）。
- **压缩请求不再带 tools**（`BUGFIXES` T7）：摘要是一段纯文本，带工具定义白烧 token、还给了模型"回 `tool_use` 当摘要"的机会（后果与 T6 同级）。
- **Anthropic 请求丢掉尾部空 assistant 占位符**（`BUGFIXES` T8）：那个空 assistant 是内部占位符（让流式 delta 有地方落），OpenAI 容忍、但 Anthropic 的 text 块**最小长度 1**，原样发会 400。丢掉后请求正好以 user 结尾。带 `tool_calls` 的空 assistant 不算占位符、照常保留。
- **mock 端点新增 `MOCK_BODY_FILE`**：记**完整请求体**（`MOCK_LOG_FILE` 只记模型名，不足以断言"请求里到底带了什么"）；`/v1/messages` 的 `MOCK_SUMMARY` **按输入判断**（这一条 T6 已修，此处补齐 Anthropic 端点）。

### 新增（折扣时段 + 成本档自动切档）

- **服务商折扣时段**：provider 级可写 `off_peak` 声明闲时窗口（`days` 三字母缩写或 `*`、`from`/`to` 用 `HH:MM`、`tz` 用 `±HH:MM` 偏移；`from > to` = 跨天、`from === to` = 全天；`days` 指**窗口开始那天**）。解析与判定是纯函数 `App\Ai\OffPeak`（坏条目只丢自己），因此能用**固定时刻**直接单测，无需 sleep 到某个钟点。
- **成本档 `cost`**：provider 级整数，越小越便宜。折扣时段内**有多个候选时取 `cost` 最小的一档**；未声明 `cost` 的排在所有已声明者之后（未知 ≠ 免费）。
- **模型策略 `auto_offpeak`**：策略可写 `'auto_offpeak' => false` 退出闲时降档（如"计划档"不该被便宜档顶掉）；默认 `true`，老配置零迁移。
- **优先级**：任务类型（快捷动作 / `/前缀`）**优先**，它没命中时才轮到时段兜底；命中却被拒绝（如 `requires` 不满足）时**不再改道**——那是用户显式指定的路。人工选档（`Ctrl+R` / 菜单 / 手切 provider/model）会钉住，时段规则完全不介入。
- **折扣结束不主动回切**：没有"该回哪一档"的确定答案（"之前那档"需记忆且热重载后可能失效），把方向盘交回用户。为此状态栏的「·折扣」标记按**当前时刻实时计算**，折扣一结束就消失（不记忆历史，避免说谎）；菜单 AI 组里此刻打折的档位也带标记，一眼看出该切哪一档。
- **配置示例**已补进内置 `config/providers.php` 注释与用户级配置模板（`ConfigStore::providersTemplate()`）。内置文件里**不替服务商断言折扣**，只给注释掉的写法示例。新增文案 `status.strategy_offpeak` / `ai.strategy_offpeak_applied` / `ai.strategy_offpeak_item` / `ai.strategy_offpeak_all_day`（中英两包同步）。

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

- **编辑器按回车没反应 + 点侧栏搜索框/提交框打字不进去**（用户实测报障，一次三个）：① `EditorPanel::onKey` 缺 `KeyCode::Enter`，而 `onChar` 里那条 `"\r"` 在真实终端**永远不会走到**（终端发 `CodedKeyEvent(Enter)`）—— 与当年「AI 面板回车发不出去」同一个坑，那次只修了 AI 输入框；② `SidebarPanel::searchClick`/`gitClick` 点到输入框只置内部状态、**不调 `focus('sidebar')`**，而 `App::handleClick` 在侧栏 `onClick()` 返回 true 后会提前 return 不再聚焦，于是从编辑器点进搜索框/提交框再打字，字符落到原焦点面板（两个方法的注释都写着会聚焦，代码里没有）。三条防回归断言都**先写红再修**。（`BUGFIXES` A5）
- **每开一次交互终端，`/tmp` 里就永久多一个 `vicetui_rc_*`**：bash 的 `--rcfile` 集成脚本用临时文件承载，而 `TerminalPanel::pollPty()` 在 shell 退出（Ctrl+D / `exit`）时直接 `$this->pty = null` 丢掉实例、不调 `shutdown()`；`PtyProcess::shutdown()` 开头的「进程句柄已回收就 `return`」也**提前跳过**了文件清理。两条路径叠加 → 一次 shell 会话漏一个文件，应用退出也不会回收（本机实测攒了 33 个）。新增 `TerminalPanel::dropPty()`（先 `shutdown()` 再置空）并在四处丢弃点统一调用；`PtyProcess` 把 rc 文件清理抽成 `cleanupRcFile()`，早退分支也调用。（`BUGFIXES` D6）
- **交互 shell 还活着时异常退出 → 留下活着的孤儿 shell**：子进程回收原先只挂在 `Lifecycle::quit()` 的关闭闭包上，而只有正常退出会经过它；未捕获异常等路径走到 `bin/vicecode.php` 的 `start()` finally，那里只做了 `saveConfig + restoreTerminal`。实测每次异常退出漏 **1 个活着的 bash**（被 reparent 到 init、一直占着 pty，且**忽略 SIGTERM**、只有 SIGKILL 能收）外加一个 rc 文件。新增 `App::shutdownResources()`（幂等）并在 finally 里兜底，与 Lifecycle 路径重复调用无害。（`BUGFIXES` D7）
- **不可捕获的致命错误下终端不还原 → 鼠标上报灌进 shell**：跑完 `vicecode <目录>` 回到 shell 后，每动一下鼠标就刷出一片 `-bash: 35: command not found`（终端把 SGR 鼠标上报 `ESC[<b;x;yM` 灌给了 bash，`ESC [ <` 被当控制序列吃掉、只剩数字）。根因：终端还原（离开备用屏 / 关鼠标 / 显示光标）**只挂在 `finally`** 上，而 PHP 的**致命错误**（`E_ERROR` / `E_USER_ERROR` / 内存耗尽）**不执行 `finally`**（实测；`register_shutdown_function` 则会执行）。现在收尾抽成一份闭包，由 `finally` 与顶层 `register_shutdown_function` 两条路径共用且幂等（还原序列仍只发一次）；注册时机提前到 `enableRawMode()` **之前**，堵住「raw mode 已生效、收尾还没挂上」的窗口。顺带把致命错误**落盘**到 `<配置目录>/.vicecode_fatal.log` 并打印一句自救提示（`reset` / `stty sane`）——原先那行 `PHP Fatal error:` 打在备用屏上、一还原就被冲掉，用户只能看到终端乱掉却不知原因。（`BUGFIXES` D10）

### 修复（启动入口 / 异常退出）

- **非 tty 的 stdin → 启动即 `PHP Fatal error` + exit 255**：`vicecode </dev/null`（或把 stdin 重定向 / 管道）时，php-tui 的 raw mode 初始化（靠 `stty -g` 探测）必然失败并抛异常，而 `bin/vicecode.php` 顶层的 `catch (Throwable)` 挂在 `Swoole\Coroutine\run()` **外面** —— Swoole 不把协程内抛出的异常传播给调用者，于是那个 catch 形同虚设：用户看到的是一屏 PHP 堆栈、退出码 255。现在：① 加 `stream_isatty(STDIN)` 前置守卫，非 tty 直接给「需要交互式终端：stdin 不是 TTY」并以 1 退出（发生在**任何终端操作之前**，不进 alternate screen、不跑 stty）；② 把 try/catch 挪进**协程闭包内部**，异常带出后统一经 `reportFatal()` 输出一行人话 + exit 1 —— 于是**任何**启动/运行期异常都不再喷 PHP Fatal 堆栈。（`BUGFIXES` D8）
- **异常退出时读键协程不退 → 进程挂住**：只做上面 ①② 会让异常路径挂住 —— `Coroutine\run()` 必须等容器内**所有**子协程结束才返回，而读键协程还挂在 `while (!$app->quit)` 里，异常就永远报告不出去。现在 `start()` 的 `catch` 里先置 `$app->quit = true;` 再上抛（实测崩溃场景的退出码由 **-1** 变 **1**）。（`BUGFIXES` D8）
- **证伪一条旧候选**（不是修复，是澄清）：曾以为「终端关闭会因 stdin EOF 让主循环空转挂住」。实测**不成立** —— pty 主端关闭后，`Coroutine::waitEvent` 永远返回 false、`stream_select` 报 0、`feof`/`meta.eof` 恒 false、非阻塞 `fread` 返回空串（与 EAGAIN 不可区分）、**连阻塞 `fread` 都永久挂住** → 用户态根本感知不到，那个 `fread === '' → EOF` 分支是死代码；而**真实**终端关闭（带 controlling terminal）由内核 **SIGHUP 直接终止**进程。（`BUGFIXES` D8 末段）
- **终端关闭（SIGHUP）→ 交互 shell 的 rc 临时文件永久残留**：rc 临时文件原先只在「正常退出 / 走 `finally`」时删（D6/D7 修的就是这两条），而终端关闭是内核发 SIGHUP 直接终止进程、`finally` 不执行 → 实测每关一次终端 `/tmp/vicetui_rc_*` **+1**。修法**不枚举退出路径**，而是让 **bash 读完 rc 就自删**（rc 最后一行 `command rm -f -- <自身路径>`）：文件寿命只剩毫秒级，之后无论进程怎么死都不可能残留（`unlink` 不影响 bash 已持有的 fd，实测 bash 照常起、命令照常跑）。⚠️ 连带影响：`rc 文件出现`不再是「shell 起来了」的可靠证据（只存活毫秒级），`tests/pty_crash.php` 场景 B 的判据已改为 `bash --rcfile` **进程数**。（`BUGFIXES` D9）

### 新增（用户级模型配置：免改仓库接入任意端点）

- **`~/.vicecode.providers.php`**（env `VICECODE_PROVIDERS_CONFIG` 可覆盖）：用户级 provider 配置，格式与内置 `config/providers.php` **完全一致**（`label` / `key_env` / `url_env` / `base_url` / `models` / `capabilities`），可直接从内置文件拷一段改。
- **合并语义**：内置先读，用户文件**按 id 逐字段覆盖**，新 id **追加在后面**（所以默认 provider 恒为内置第一条）；**`models` 是整表覆盖**，不与内置取并集——语义是「看到什么就是什么」。于是「只想把 `openai` 指向自建网关」只需写一个 id + 要改的字段，内置的 `deepseek` 自动保留。
- **应用内编辑 + 保存即热重载**（与插件配置同一条纪律）：菜单「AI → 编辑模型配置…」（命令面板也可搜到）在**内置编辑器**里打开该文件；文件不存在时先落一份**带注释的模板**（`return [];`，行为与没有该文件一致——**不写当前生效配置**，否则会冻结内置版本、把以后的内置更新盖掉）。`Ctrl+S` 与菜单保存走同一条路径（EditorPanel 保存钩子）→ 重读配置并重建注册表，**无需重启**；重载会**保住当前 provider/model**（仍存在就不动，被删掉才退回默认）。
- **出错不致命**：用户文件语法错/返回非数组时保留上一份可用配置，并在状态栏明确提示「模型配置未生效（syntax），已沿用原配置」——不写反馈的话，用户在自己改的 PHP 里存错了会以为"改了没生效"。实现说明：实测 PHP 8.3 下 `require` 遇到解析错会抛**可捕获的 `ParseError`**（且不往 stdout/stderr 喷东西），因此不需要额外的语法预检层。
- 新增文案 `ai.providers_label` / `ai.providers_reloaded` / `ai.providers_bad`（中英两包同步）。

### 新增（模型策略：多档位互相切换）

- **`@strategies` 段**（写在同一份用户级模型配置里）：把「这次要干什么」映射到一个具体模型，例如 `plan` → `deepseek-reasoner`、`grind` → `deepseek-chat`。
- **切换入口**：AI 面板 `Ctrl+R` 循环切换；菜单/命令面板的 AI 组里**每条策略一项**（直接选，不用循环）。帮助页登记 `Ctrl+R`（`KeyBindings` 漂移检测覆盖）。
- **可见性**：状态栏新增策略段（`策略=<展示名>`，优先级高于 AI 段——窄屏上宁可先丢 provider/model 也要保住"我在哪一档"）；切换时状态栏给出「已切到策略 X → provider/model」回执。
- **记住选择**：当前策略随对话存档落盘，重启后**仅在仍指向同一 provider+model 时**才恢复名字（策略被删/改过、或用户手选过别的模型，就不显示那个名字）。
- **校验：四类拒绝，绝不静默降级**（这是本功能最要紧的一条）——① 策略不存在；② 目标 provider 未配置；③ 目标 model 未声明（⚠️ `spec()` 对未登记模型会**静默退回该 provider 的默认模型**，所以必须自己比对才算校验过）；④ 策略声明的 `requires` 能力目标模型不具备（典型：`requires: ['tools']` 撞上没声明 `tools` 的便宜模型 —— Agent 工具会**静默失效**、不报错）。四类都给出具体原因的提示。
- **不说谎**：手动 `Ctrl+P` 切 provider / `Ctrl+N` 切模型会自动清空当前策略名——状态栏挂着「策略=优质档」而实际跑着便宜模型，比不显示更糟。
- `@` 前缀的键为保留段，**不会**被当成 provider id（`ids()` 里不会混进 `@strategies`）。新增文案 `ai.strategy_*` / `status.strategy` / `help.a_strategy`（中英两包同步）。
- `examples/sse_server.php` 新增 `MOCK_ECHO_MODEL=1`（把请求体里的 model 回显成 `[model=xxx]`），用于端到端断言"换档真的换了模型"。

### 新增（按任务类型自动选档）

- **策略可声明 `kinds`**（任务类型），请求带上这些类型时**自动**用该档。于是「任务类型 → 用哪一档」写在策略自己身上，不必另开映射表。例：`'grind' => [..., 'kinds' => ['comment', 'explain']]`、`'plan' => [..., 'kinds' => ['plan']]`。
- **任务类型只有两个来源，都不靠猜**（猜错会静默降级，代价不对称）：
  1. **快捷动作**（explain / comment / refactor / unittest）——`App::aiQuickAction($kind)` 本来就有 kind，本轮把它一路透传到 `ChatModel`；
  2. **输入框指令前缀**，如 `/plan 帮我把这块重构一下`——前缀**不发给模型**；写了**未知类型会拒绝发送**并列出已知类型（在输入框打斜杠显然是想下指令，把它当普通消息发出去还带着斜杠是更差的结果）；想发字面量斜杠写两个（`//plan` → `/plan`）。
- **人工优先**：手动选档（`Ctrl+R`、菜单里的某条策略、手切 provider/model）会**钉住**，自动选档暂停；`Ctrl+R` 的循环里加了「**自动**」这一档（`@strategies` 里的策略名 `auto` 因此是保留名），状态栏与菜单都能切回。手动钉住时收到带类型的请求会**明说**「自动选档未生效」——不许静默失效。
- **状态栏说清"现在听谁的"**：`策略=自动`（配了策略即开启）／`策略=计划·自动`（自动路由选中的档）／`策略=计划`（人工钉住）。窄屏上该段优先级仍高于 AI 段。
- **可见性**：菜单/命令面板的 AI 组里多出「自动选档（按任务类型）」一项（在每条策略之前）。新增文案 `ai.strategy_auto_item` / `ai.strategy_auto_on` / `ai.strategy_applied_pinned` / `ai.kind_unknown` / `ai.kind_ignored_pinned` / `status.strategy_auto`（中英两包同步）。
- `examples/sse_server.php` 新增 `MOCK_LOG_FILE`（把每次请求的 model 追加成一行）——用于断言**调用序列**（"哪条消息被路由到了哪个模型"），比在 pty 画面上找回显可靠得多。

### 依赖

- 新增 `league/commonmark ^2.10`（Markdown 解析，只走 AST 遍历，不用其 HTML 渲染器）。

### 测试

- 新增 `tests/ai_selection_unit.php`（headless，`BUGFIXES` B14）：未选时标题栏/状态栏/空态**都不出现**模型名、`useProvider()` 后三处都出现、**只按 Ctrl+N（`cycleModel()`）也算已选**（它只写 `model`）、反面对照。四条反向注入全部实测可 FAIL（标题判据改回 `spec() !== null` / 状态栏判据改回 / `hasSelection()` 只看 `providerId` / 空态去掉未选分支）。
- 三处「靠默认 provider 一定显示」当锚点的用例改为**显式选一次再断言**：`pty_provider_caps`（Tab×3 + Ctrl+P，**Ctrl+P 次数按当前配置里的 provider 个数算** —— 写死 1 次会在第二段落到 deepseek 上）、`pty_providers`（点 AI 输入框落焦再 Ctrl+P；它用 argv 打开了文件，编辑器**有 buffer** 时 Tab 被当缩进吃掉、切不到面板）、`provider_caps_unit`（`appWithChat()` 改为总是 `useProvider()`）。
- ⚠️ 顺带把 `pty_provider_caps` 的**内联屏幕重建器**换成共享的 `tests/lib/pty_screen.php::vc_rebuild_screen`：前者多次重绘后会残留交错字符（实测把 `无工具` 拼成 `无M工具`、把状态栏两段叠成 `标签焦资点源AISTREAM`），导致断言无故失败；共享版逐行归一化、行间保留 `\n`，不会跨行拼出假阳性。
- 新增 `tests/ai_selection_unit.php` 时踩到一个读屏幕的坑：宽字符占**两格**、第二格是空格，直接 `implode` 会把「AI 对话」拼成「AI 对 话 」，针永远匹配不上 —— 读网格必须先判宽度、跳过续格。
- 新增 `tests/picker_unit.php`（headless）：点语言段弹列表 / 初始高亮落在当前值 / ↑↓ 环形 / Enter 应用（语言真的变）/ Esc 只关列表不退出 / **点不可点的段无反应** / 鼠标点列表项应用、点别处关闭 / **点主题段弹主题列表且切换真的生效** / **语言与主题段在 120 列下都必须可见**（宽度预算的回归守护）/ 浮层真的画在屏幕上且底边紧贴状态栏上一行 / 关闭时屏幕上没有它（阴性对照）。**八条反向注入全部实测可 FAIL**：段不声明 pick、`clickSegment` 不认 pick、Esc 不消费、浮层下移一格压住状态栏、鼠标命中行算错一格、主题段不声明 pick、整段删掉主题段、把 `app` 段优先级调回去（会把语言段挤出 120 列）。⚠️ 写这条时踩了三个坑：`findInGrid` 用 `strlen()` 当**格数**（多字节字符永远匹配不上）；「注入后变红」**也得确认是断言失败而不是崩溃**（第一次的「浮层位置」注入其实把面板挤出了视口、php-tui 直接抛 `Position` 越界）；**宽度预算那条断言最初用空态测**（`文件=—` 太窄、腾出的空间让退化观察不到），改成打开文件后才真正守住。
- `tests/git_unit.php`：`Commit ▾` 的点击断言改为**从渲染帧逐格量出 `▾` 的列**（原先照抄产品代码的错公式，见 `BUGFIXES` E4）。
- `tests/plugin_v11_unit.php`：`clickSegment()` 返回值形状变了（`?string` → 命中详情数组），4 条断言同步；「系统段不可点」改为「系统段不带插件命令（cmd）」（V1.2 起系统段可带自己的 `pick`）。
- `tests/menu_unit.php` / `tests/r7_unit.php`：菜单「视图 → 主题/语言」语义变了（打开列表而非盲目循环），断言改为走完「开列表 → ↓ → Enter」；`toggleLocale()` 删除后改用 picker。
- `tests/m0_smoke.php` / `tests/m1_smoke.php`：它们拿状态栏的 `ViceCode`（app 段）当「状态栏渲染了」的锚点，而 app 段这轮被降到最低优先级 → 换成 `Mode=` / `模式`（高优先级段）。**教训**：拿**会被裁剪的段**当渲染锚点，优先级一动就误报（`测试里 picker_unit` 自己也踩了同一个坑，已改用状态栏行的 `Ctrl+Q` 并顺带断言「浮层没盖住状态栏那行」）。
- 新增 `tests/pty_signals.php`（真实 pty，`BUGFIXES` D11）：三个信号（SIGTERM / SIGHUP / SIGINT）各自断言「还原三连 + 日志记到信号名 + `signaled=true` 且 `termsig` 等于该信号 + 存活标记残留」，另覆盖**非 Swoole 底座**（`TUI_USE_SWOOLE=0`）的 SIGTERM 一例（两种底座退出路径不同），以及「残留标记 → 下次启动写 WARN」。**反面对照**（正常退出 → 退出码 0 / 标记被清 / 不产生日志）不能省，否则「标记残留」那几条断言可能只是恒真。**四条反向注入全部实测可 FAIL**。⚠️ 写这个用例本身踩了三个坑，都已写进 BUGFIXES D11 的教训：看到还原序列就立刻读进程状态（此刻进程还活着 → termsig 永远是 9）、`proc_get_status()` 在循环外**又取一次**（PHP 语义：首次报告未运行的那次才带正确 exitcode，之后再调得 -1）、`?1049h` 一出现就发按键（初始化窗口的 termios 变更会冲掉 pty 待读输入 → 丢键）。
- 新增 `tests/docs_links.php`（**文档链接完整性，进跑批**）：校验全仓 markdown 的**相对文件链接目标存在**与**文内锚点命中真实标题**（含 `文件.md#锚点` 跨文件形式）。锚点按 GitHub（github-slugger）的 slug 规则生成，两个边界要记住：`&` 被剔除但两侧空格都保留，故 `A & B` → `a--b`（**双**连字符、不折叠）；全角标点 `（）：、` 属标点类、一并剔除。跳过**代码围栏**与**行内代码**（里面的 `# 注释` 不是标题、`](...)` 不是链接 —— 后者踩过：CHANGELOG 里写了示例 `` `](...)` `` 就被当成真链接报「目标不存在：...」），并处理重复标题的 `-1` 后缀。**外链只统计不校验**（跑批不联网，联网校验反而不可靠）。「无坏链」这类断言最容易**假通过**，故另断言「确实发现 ≥5 个文档」「确实扫到链接」作正向锚点。四条注入验证可失败：目标改错 / 锚点改错 / 让扫描一个文件都找不到 / **行内代码里的假链接必须不报错**（验修复）。
- 新增 `tests/multicursor_unit.php`（headless：加光标规则 / 打字各自右移 / 退格含跨行合并的行号补偿 / Enter 每行插行且补偿正确 / Tab 缩进 / Esc 两条路径 + 单光标反面对照 / 多格 REVERSED 渲染 / Alt+点击全链路且不产生选区（带"不带 Alt 会复制"的反面对照）/ 自动配对与粘贴不参与）。**六条反向注入全部实测可 FAIL**：扇出改升序、去掉行数 delta 补偿、`SpanClip` 只反显第一列、删掉 Esc 分支、Alt+点击不判修饰键、去掉扇出的按行去重。
  - 其中「`SpanClip` 只反显第一列」第一次注入**没变红** —— 因为「每行最多一个光标」的不变量让整屏永远不会出现同一行两格，整屏断言**根本观察不到**它。补了一条**直接调 `SpanClip::clip`** 的原语级断言才覆盖住。教训：断言要挑一个"退化后真的会变"的观察面。
- 新增真实 pty 探针 `tests/probe_alt_arrows.php`：写 xterm 序列 `ESC[1;3B`（Alt+↓）→ 打一个字符 → 断言**两行同时被改**（`xAaa`/`xBbb` 同现）→ Esc 后再打一个字符**只改一行**（反向可失败）。headless 单测跳过「终端字节 → 解析器」这一段，这条补上了。
- 新增 `tests/autopair_unit.php`（headless：`editor.autoPairs` 配置解析（缺省/显式关闭/脏条目/自定义 `<>`/类型不对）+ `AutoPair::plan()` 每条规则 + 编辑器里的真实效果（打左补右、打右跳过、空对退格成对删、词后引号不配对、选中包裹））。**六条反向注入全部实测可 FAIL**：plan 恒返回 PLAIN、去掉"引号别跟在词后"、去掉 SKIP 分支、去掉退格成对删、去掉选中包裹、显式 `[]` 也回退默认集。
- 新增 `tests/completion_unit.php`（headless：`@文件` 触发/前缀过滤/接受写回、编辑器 Tab 缩进与 Shift+Tab 反向缩进、**口径 3 的回归**（单行输入 Tab 仍切焦点）、候选浮层真的画在屏幕上且底层不被抹白、插件 `completions()` 候选与「禁用后消失」）。**六条反向注入全部实测可 FAIL**：编辑器 Tab 不消费 → 缩进断言红；去掉「刚接受过」的一次性抑制 → 「接受后候选关闭」红（补全后的 token 仍匹配前缀，候选会立刻弹回）；插件 provider 不注册 → 插件三条红；搜索框也消费 Tab → 口径 3 回归红；`@` 补全不按前缀过滤 → 过滤断言红；**测试里删掉 `addWidgetRenderer(DropdownOverlay::renderer())` → 浮层断言红**（证明浮层确实来自覆盖层，且渲染器漏注册会静默不画）。渲染断言用 `src/` 的子目录名当锚点（侧栏树默认折叠，该串只可能来自浮层）—— 根目录名同时也在侧栏树里，拿它做阴性断言会永远恒假。
- 新增 `tests/pty_rc_cleanup.php`（真实 pty：交互 shell 启动后 rc 临时文件**已自删**；再发 SIGHUP 断言仍不残留；含三条正向锚点并自行清理孤儿 shell）。**注入验证**：注释掉自删行后两条核心断言都 FAIL（`vicetui_rc_*：0 → 1`）—— 正是用户报的现象原样复现。
- 新增 `tests/pty_notty.php`：**非 tty 的 stdin**（`['file','/dev/null','r']` + stdout/stderr 走管道）跑 `bin/vicecode.php`，两个底座分支各 6 条断言（退出码**恰为 1** / 人话提示 / 不喷 `Fatal error`·`Uncaught`·`Stack trace` / **不进 alternate screen**）。这条路径此前**从未被覆盖**（所有跑 bin 的测试都用 `['pty']`）。三组注入验证见 `BUGFIXES` D8。
- `tests/pty_crash.php` 断言收紧并修掉一处**假断言**（`BUGFIXES` T4）：`runInPty()` 第 3 个返回值原先恒为空串，场景 A 却拿它做判断（永假分支，一直靠 `$out` 含 `"Uncaught"` 蒙过）；现改为读 `$out` 并查 `crash injected`，两处崩溃场景都收紧为「exit **恰为 1**」+「不含 `Fatal error`/`Uncaught`」。
- 新增 `tests/provider_user_unit.php`（用户级配置：合并四种情形 / 文件缺失 / 语法错 / 返回非数组 / 脏条目跳过 / env 覆盖路径 / `openProvidersConfig()` 生成的模板可用 / 菜单与命令面板入口 / 热重载保住 provider 与 model / 坏配置的状态栏提示）与 `tests/pty_providers.php`（真实 pty：启动即加载用户配置并显示在状态栏；在该文件上按 `Ctrl+S` 出现热重载回执；干净退出仍还原终端）。
- 新增 `tests/lib/pty_screen.php`：**多字节感知**的屏幕重建助手（重放 CSI 定位/擦除得到最终帧，行内归一化后匹配）。差分渲染只重发变化格、同一行会被拆成多次「定位+写入」，直接对累积流做子串匹配会踩坑——本轮实测状态栏 `Model config reloaded` 在流里成了 `modelconfig` + `eloaded`。旧 pty 测试里那几份**逐字节**内联重建器只对 ASCII 成立（中文断言会静默失效），未迁移，但已在文件头注明「新测试用这份」。
- 用户级配置纳入测试隔离：`vc_isolate_config()` 现在同时覆盖 `VICECODE_CONFIG` / `VICECODE_PLUGINS_CONFIG` / `VICECODE_PROVIDERS_CONFIG`（否则 `ProviderRegistry` 会读开发机真实的 `~/.vicecode.providers.php`）；7 个显式设 env、未走 helper 的老测试逐一手工补上。
- 新增 headless：`tests/ai_tools_unit.php`（路径安全 / Agent loop 端到端 / maxSteps / approve-deny / 流内渲染）、`tests/ai_md_unit.php`（Markdown 元素 / spanWrapDisp 拼接不变量 / 缓存命中）、`tests/ai_store_unit.php`（存取往返 / 0600 / Ctrl+L 清档 / persist=false）、`tests/ai_attach_unit.php`（@展开与拒绝 / 选区与当前文件附加 / 快捷动作与菜单）、`tests/ai_compact_unit.php`（自动触发 / 失败降级 / tool 对不拆散 / 手动压缩）。
- 新增 pty：`tests/pty_ai_v2.php`（三轮真实会话：@展开与两轮工具 / 重启恢复 / y 确认放行）。
- `tests/ai_unit.php` 的 SseParser 断言升级为结构化事件形状（V2 唯一破坏性 API 变更）；`tests/plugin_v11_unit.php` 菜单组索引断言随 AI 组插入顺延。
- 新增 `tests/plugin_enabled_unit.php`（headless：缺省启用 / `enabled` 不注入插件 / 禁用后状态栏段·tick·命令·菜单·面板·事件全部不参与 / 切换落盘且只改 `enabled` / 配置文件 + Ctrl+S 同路径 / 侧栏与浮层 Space）与 `tests/pty_plugin_toggle.php`（真实 pty：会话内标注由「已启用」翻为「已禁用」、重启后该插件根本未加载、再按 Space 段立刻回来、40×10 极小视口不崩）。
- 新增 `tests/ai_edge_unit.php`（headless：Markdown 块级嵌套内容不丢 + 既有排版零回归 / ESC·OSC·TAB·C0 进不了 span / 极窄 W=2…16 行宽上界 / 超长 4000 字符围栏的拼接不变量）与 `tests/pty_ai_inject.php`（真实 pty：mock 回复带 OSC 52 与 TAB，断言累计字节流里无带 ESC 的 OSC 载荷、无任何裸 TAB，而正文标记仍在）。两者都做过**强制失败注入**验证（停用 `sanitizeContent()` / 反转断言后 exit 非 0）。
- 新增探针 `tests/probe_ai_edge.php`（不进跑批）：Markdown 块级丢失 / 控制字符透传 / 极窄宽度行宽的取证样本。
- 新增 `tests/provider_caps_unit.php`（headless：能力解析全形状含旧写法兼容 / 端到端断言「只有声明 tools 的模型请求才带 tools」/ 状态栏标记与面板能力行的正反对照）与 `tests/pty_provider_caps.php`（真实 pty：临时替换 `config/providers.php` 后断言状态栏「无工具」标记出现，换回原配置后必须消失）。两者都做过强制失败注入验证。`examples/sse_server.php` 新增 `MOCK_ECHO_TOOLS=1`（回复 `[tools=1|0]` 回报请求体是否携带 tools）。
- **测试隔离修复（两轮，共 51 个测试 + 1 个新 helper）**：
  1. **配置目录不再落在 `/tmp` 根**：`tempnam()` / `sys_get_temp_dir().'/x.json'` 当 `VICECODE_CONFIG` 时，存档路径 `dirname(VICECODE_CONFIG)/.vicecode_ai` 会退化成 `/tmp/.vicecode_ai`——全体测试共用一份对话存档，`aiPersist` 默认开启，`App` 构造末尾的 `ChatModel::restore()` 会把**上一个测试的对话**恢复进来（「空态不是空的」、断言行号整体平移，随跑批顺序偶发）。**25 个测试**改用 `vc_isolate_config('tag')`。
  2. **配置不再依赖开发机家目录**：25 个建了 `App` 却完全没设 `VICECODE_CONFIG` 的测试会读真实 `~/.vicerc`（布局/主题/语言）与 `~/.vicecode.plugins.json`。实测把 `~/.vicerc` 换成非默认布局 + 其它主题语言、`~/.vicecode_ai` 放一份对话后，**6 个测试挂**（`m6_unit` 主题环、`menu_dropdown` 菜单标签、`pty_git` GIT tab、`ai_hscroll` 折行宽、`sidebar_hscroll` 折叠命中列、`m1_edge` 翻页边界）——即"只在我这台机器上绿"。全部改为 `vc_isolate_config()`（同时隔离 `VICECODE_CONFIG` / `VICECODE_PLUGINS_CONFIG` / `VICECODE_PROVIDERS_CONFIG` 三份用户级配置）。
  3. 新增 `tests/lib/isolation.php` 统一承载：`vc_isolate_config()`（独占目录 + 自动清理，pty 用例把返回路径塞进子进程 env，父子同源）、`vc_tmp_file()` / `vc_tmp_dir()`（替代裸 `tempnam()`，退出时自动删）。**41 处 `tempnam` 全部改走 helper**，`/tmp` 不再堆垃圾（含 `command_palette_unit` 与 `pty_ai_v2` 两处**从不清理**的目录——后者原来只 `rmdir` 空 `src/`，目录非空时静默失败）。
  4. 新增断言：`pty_interactive` / `pty_session` / `pty_alt_screen` 断言"运行前后 `/tmp/vicetui_rc_*` 数量不增加"（`BUGFIXES` D6 的防回归）；`pty_crash` 新增**场景 B**（交互 shell 活着时崩溃）断言 rc 文件与 `bash --rcfile` 孤儿进程都不增加（D7 的防回归），并补了"shell 真的起来了"的正向锚点。**注入验证**：把 `dropPty()` 改回 `= null` 后前三个用例全部 FAIL（0→2 / 0→2 / 0→5）；摘掉 `bin` finally 里的 `shutdownResources()` 后场景 B 的两条断言都 FAIL（rc 0→1、孤儿 +1）。恢复后全绿。完整根因与取证见 `BUGFIXES` T2 / D6 / D7。
- **强制失败注入**（用户级模型配置这两条，均实测能让对应断言 FAIL）：`mergeUser()` 直接 `return $base`（合并失效 → 单测 8 条红）；摘掉 EditorPanel 的 `providersPath()` 分支（pty 精确红在「热重载回执」那条，其余断言仍绿）。
- **自动选档的测试**：`tests/kind_route_unit.php`（kinds 解析 / 保留名 `auto` 被丢 / knownKinds / 快捷动作路由且不钉住 / 钉住后不抢 / 切回自动 / **前缀不发给模型** / 未知类型拒绝并列出已知 / `//` 转义 / 状态栏三种形态）与 `tests/pty_strategy.php` 扩展（钉住时 `/plan` 被忽略要明说；**用 mock 的服务端日志断言调用序列** `smart → smart → fast → fast`）。**强制失败注入**：`routeByKind` 无视 `pinned`（→ 单测两条红 + pty 序列变 `smart → fast → fast → fast`）/ kind 匹配恒假（→ 单测四条红 + pty 序列四条全 `smart`）/ 前缀不剥离（→ 单测两条红）。
- **模型策略的测试**：`tests/strategy_unit.php`（解析 / `@` 保留段不进 provider / 四类拒绝 / 手动切档清空策略名 / 循环 / 持久化与一致性恢复 / 菜单+命令面板入口）与 `tests/pty_strategy.php`（真实 pty：`Ctrl+R` 切换 → 状态栏出现档位；**发消息后 mock 回显的请求 model 确实换了** `zz-model-fast` → `zz-model-smart`）。**强制失败注入**（均实测能让对应断言 FAIL）：摘掉「模型未声明」校验（→ 单测两条红，且消息暴露出静默回退到别的模型）/ 菜单不发策略条目（→ 菜单与命令面板两条红）/ `applyStrategy` 忽略目标 model（→ pty 精确红在「换档真的换了模型」那条）。
- **修 `tests/pty_search.php` 的三处断言缺陷**（`BUGFIXES` T3）：①「键入进搜索框」3s 窗口在负载下假阴性（复现于全量批；同一次里搜索其实是成功的）→ 改 8s + 断言最终帧；②「不显示无匹配结果」查英文串，而该用例界面是默认 `zh_CN` → **恒真的僵尸断言**（注入"恒定无匹配结果"后照样 PASS）；③「结果出现」查 `zzuniquemarker`，可输入框里就有这个词 → **假阳性**（注入"结果列表不渲染"后照样 PASS）。三条都改为对**重建后的最终帧**断言，命中锚点换成只可能来自结果列表的命中行内容；切换 tab 后加 `waitQuiet()` 再键入。三组注入（中间帧污染 / 最终状态错 / 列表不渲染）分别验证了"必须用最终帧"与两条新断言各自的牙齿。

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

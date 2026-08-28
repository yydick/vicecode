# TUI 大模型交互工作台 — 开发计划

> 目标：用 Swoole + PHP-TUI 开发类 Trae / CodeBuddy-CLI 的大模型交互界面。
> 界面风格参考 VSCode：左侧资源管理器/GIT/搜索，中间上编辑器、下终端，右侧上 AI 交互流。
> 所有交互均基于 Swoole 的异步/协程实现。

---

## 一、技术栈

| 关注点 | 选型 | 说明 |
|---|---|---|
| 运行时 | PHP 8.3 + Swoole 6.2.1 | 协程/异步事件循环驱动一切 |
| UI 框架 | `php-tui/php-tui` | Ratatui 风格，Layout + Widget 成熟 |
| 终端后端 | `php-tui/term` (CrosstermBackend / 自写薄封装) | 负责 ANSI 绘制与输入 |
| HTTP 流式 | Swoole 协程 `curl`（`CURLOPT_WRITEFUNCTION`） | 协程化的逐块回调，最稳的 SSE 方案 |
| 进程/命令 | `Swoole\Process` / `Co::exec` | GIT、搜索、终端命令异步执行 |
| 语法高亮 | `scrivo/highlight.php`（纯 PHP，无外部进程） | 编辑器高亮，可选 |
| 自动加载 | Composer PSR-4 | 项目管理 |

核心思路：**Swoole 事件循环是唯一的"主循环"**，php-tui 只负责"把当前状态画一帧"，由
Swoole Timer 定时驱动；所有输入、文件 I/O、子进程、LLM 请求都在协程里非阻塞完成，结果
直接写入共享的 `AppState`，绘制帧读取最新状态。由于 Swoole 是单线程协作式调度，
**无竞态，无需加锁**。

---

## 二、核心架构：Swoole 驱动 php-tui

```
Swoole\Coroutine\run(function () {
    $backend  = CrosstermBackend::new(StreamWriter::stdout());
    $terminal = Terminal::new($backend);
    $terminal->alternateScreen(true);
    $terminal->enableMouseCapture();
    $app = new App($terminal);

    // 1) 输入协程：协程 fread 非阻塞读 STDIN，解析为 KeyEvent 派发
    go(function () use ($app) {
        while (true) {
            $chunk = Co\System::fread(STDIN, 8192);   // 无数据时协程挂起
            if ($chunk === '' || $chunk === false) break;
            foreach (KeyParser::parse($chunk) as $ev) $app->dispatch($ev);
        }
    });

    // 2) 渲染循环：Timer 驱动 php-tui 逐帧绘制（60fps 上限，按需降频）
    Swoole\Timer::tick(1000/60, fn() => $terminal->draw(
        fn(Frame $f) => $app->render($f)
    ));

    // 3) 各功能协程按需 spawn（AI 流式、git、搜索、命令执行…）
});
```

- **输入解析（键鼠统一）**：`Co::fread` 拿到的是原始字节，需把方向键/功能键的 ANSI 转义序列
  解析成 php-tui 的 `KeyEvent`，**同时解析 SGR 鼠标转义序列**（`\e[<...M` / `\e[<...m`）得到
  鼠标按下/释放/移动/滚轮事件（含坐标）。优先复用 `php-tui/term` 的 `EventParser`（喂事件）；
  若 API 不匹配则自写一个覆盖常用键与鼠标的小解析器。鼠标事件路由规则：
  单击面板 → 切换焦点；单击 Sidebar 标签 → 切换标签；滚轮 → 滚动对应面板内容。
  坐标用于判断命中哪个面板/标签，因此 `AppState` 需保存各面板当前布局矩形（已在 `LayoutFactory`
  计算），做命中测试。
- **raw mode**：进入 alternate screen 前用 `stty` 或 php-tui 的 raw mode 接口关回显/行缓冲，
  退出时恢复（务必 `defer` 恢复，避免终端坏掉）。
- **Phase 0 先验证这一层能跑通**——这是最大不确定性，先搭最小骨架确认 Swoole+php-tui
  集成无误，再往上堆功能。

---

## 三、布局（类 VSCode）

```
┌──────────┬────────────────────────┬──────────────┐
│ Sidebar  │  Editor (上, 大)        │  AI 交互流   │
│ Expl/Git │                        │  (右, 上)    │
│ /Search  ├────────────────────────┼──────────────┤
│  (Tab)   │  Terminal (下)          │  AI 输入框   │
│          │                        │  (右, 下)    │
├──────────┴────────────────────────┴──────────────┤
│ StatusBar (当前文件 / git 分支 / provider / 模式)  │
└───────────────────────────────────────────────────┘
```

用 php-tui `Layout` 嵌套实现：
- 横向三段：`[Sidebar 约30列] [Editor+Terminal 弹性] [AI 约45列]`
- 中段（中间列）纵向：`[Editor 最小50%] [Terminal 剩余]`
- 右段（AI 列）纵向：`[AI 交互流 最小60%] [AI 输入框 固定约3行]`
- 底部：`[StatusBar 1行]`
- **鼠标**：`Tab`/方向键切换焦点；点击任意面板切换焦点、点击 Sidebar 标签切换
  Expl/Git/Search、在编辑器/终端/AI 面板内滚轮滚动内容（更细规则见「鼠标支持」）。

---

## 四、面板组件设计

1. **资源管理器 Explorer**（协程）：懒展开目录树（`scandir` 协程非阻塞），高亮选中，
   回车打开文件 → 载入 Editor Buffer。
2. **GIT 面板**（协程）：`git status` / `git log` / `git diff` 经 `Swoole\Process` 异步执行并
   解析，彩色展示；进阶动作 stage/commit/push 后续迭代。
3. **搜索 Search**（协程）：输入框 + 递归 grep（子进程或 PHP 正则遍历文件），结果列表，
   回车跳转到编辑器对应行。
4. **编辑器 Editor**：`Buffer` 模型（行数组 + 光标行列）。渲染带行号 + 语法高亮；基础编辑
   （插入/退格/回车/移动/保存，保存用协程文件写入）。**查看+基础编辑**为本期目标。
5. **终端 Terminal（命令运行器）**：输入行 + 输出区；提交后用协程 `Co::exec`/`Process`
   捕获 stdout/stderr，流式写入输出 buffer（协程）。**不做交互 PTY**（见风险）。
6. **AI 交互流 AI**（右上）：多轮消息列表 + 流式打字效果。可插拔 Provider 抽象（见五）。
   - **AI 输入框**（右下，独立子区域）：多行文本输入，回车（或 `Ctrl+Enter`）发送；
     支持发送前编辑、历史命令（↑/↓ 调取上一条 prompt）。输入内容随焦点进入此框时激活，
     发送后清空并等待流式响应（响应写入右上消息流）。框高度固定约 3 行，满则滚动。
7. **鼠标支持**（贯穿各面板）：PHP-TUI 原生支持 SGR 鼠标协议，已在 Phase 0 启用
   `enableMouseCapture()`。鼠标事件与键盘事件统一进入事件分发：
   - 单击面板区域 → 把焦点切到该面板（点哪进哪）；
   - 单击 Sidebar 顶部 Expl/Git/Search 标签 → 切换标签；
   - 在 Editor / Terminal / AI 消息流内滚动滚轮 → 对应内容滚动；
   - 在 AI 输入框内点击 → 定位光标（若做多行编辑）。
   见「二、输入解析（键鼠统一）」对鼠标事件的解析说明。

---

## 五、可插拔多 Provider（AI 层）

```php
interface ChatProvider {
    public function chat(array $messages, callable $onToken, array $opts): array;
    // 返回完整 assistant message
}
```

- 实现：`OpenAIProvider` / `DeepSeekProvider` / `ClaudeProvider` / `OllamaProvider`，
  均基于 OpenAI 兼容的 `/v1/chat/completions` SSE 接口，差别仅在 base_url、key、模型列表、少量字段。
- **流式**：用 Swoole 协程 `curl`，`CURLOPT_WRITEFUNCTION` 回调中按 `\n` 切分、`data:` 前缀行
  解析 JSON 取 `delta.content`，逐块调用 `$onToken`（写入 `AppState` 的 AI 消息，绘制帧自动刷新）。
- 配置：`config/providers.php` 从**环境变量**读取 key（避免明文），UI 内可切换当前 Provider / 模型。

---

## 六、状态与事件

- `AppState` 集中存放：焦点面板、Sidebar 当前 Tab、各 Buffer、AI 消息、git 状态、
  搜索结果、终端输出、**以及各面板当前布局矩形**（供鼠标命中测试用）。
- 事件分发：KeyEvent / MouseEvent → 当前焦点面板 handler（鼠标事件先按坐标做命中测试，
  决定聚焦到哪个面板再派发）；功能协程（AI/git/搜索）完成后直接改 `AppState`，
  绘制帧下一 tick 自然反映。
- 跨协程通信用 `Swoole\Channel` 传递较大载荷（如文件内容、大段输出），简单状态直接写 `AppState`。

---

## 七、目录结构

```
tui/
  composer.json
  bin/tui.php                  # 入口：启动 Swoole + App
  src/
    App.php
    Core/{EventLoop,KeyParser,State,LayoutFactory}.php
    Panels/{SidebarPanel,Explorer,GitPanel,SearchPanel,EditorPanel,TerminalPanel,AIPanel}.php
    Editor/{Buffer,Highlighter}.php
    AI/{ProviderInterface,OpenAIProvider,DeepSeekProvider,ClaudeProvider,OllamaProvider,StreamParser}.php
    Git/GitClient.php
    Terminal/CommandRunner.php
  config/providers.php
```

---

## 八、分阶段路线图

- **Phase 0 — 集成骨架**：composer 初始化 + 装 php-tui；Swoole alternate-screen 循环 +
  渲染 tick + 键鼠输入（启用 `enableMouseCapture`，解析键鼠事件）；画出静态 VSCode 布局、
  键盘焦点切换，以及**最基础的鼠标能力：点击面板切换焦点、点击 Sidebar 标签切换标签**。
  **先验证 Swoole+php-tui 能跑**。
- **Phase 1 — 资源管理器 + 编辑器**：协程目录树、打开文件、行号+高亮、滚动（含**鼠标滚轮滚动**）、
  基础编辑与保存。
- **Phase 2 — 终端命令运行器**：输入 + 协程执行输出渲染 + 历史。
- **Phase 3 — GIT 面板**：status/log/diff 展示与基础操作。
- **Phase 4 — 搜索面板**：协程递归 grep + 结果跳转。
- **Phase 5 — AI 交互流（多 Provider）**：ProviderInterface + 4 家实现 + SSE 流式 + 多轮对话。
- **Phase 6 — 打磨**：状态栏、主题、快捷键、provider 配置文件、错误处理；补全鼠标细节
  （输入框光标定位、终端/AI 面板滚轮滚动、拖拽分隔条调整面板宽度等进阶交互）。
- **Phase 7（进阶，可选）— 交互式 PTY 终端**：`Swoole\Process` + pty 跑 bash + 轻量 VT100 模拟器。

---

## 九、风险与开放点

- **Swoole+php-tui 集成**（raw mode、draw 调用上下文）是最大不确定项，Phase 0 专门验证。
- **SSE 逐块读取**用协程 `curl` 的 `WRITEFUNCTION` 最稳；若某版本不协程化则退化为
  "读取完整体再动画"。
- **交互式 PTY 终端**需要 VT100 模拟器，工作量大，明确放到 Phase 7，本期只做命令运行器。
- **语法高亮**引入 `scrivo/highlight.php`（纯 PHP）性价比最高；否则自写轻量 tokenizer。
- **编辑器完整编辑**（撤销栈/多选/查找替换）留作后续迭代。

---

## 十、下一步

计划已就绪。确认后从 **Phase 0** 开始：初始化 Composer 项目、安装 php-tui、搭出可运行的
布局骨架（空面板 + 焦点切换 + 输入循环），先把最关键的 Swoole↔php-tui 集成跑通。

---

_（本文件待评审，请在修改意见中标注要调整的小节。）_

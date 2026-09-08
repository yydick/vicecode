# ViceCode BUG 修复清单

> 覆盖范围：v0.0.1 定版前（2026-08 ~ 2026-09）全部已修复缺陷，共 **15 个真实产品 BUG + 9 类测试伪失败 + 1 个测试自身缺陷 + 1 处 vendor 补丁**。
>
> 写这份清单有两个目的：
> 1. **防止同类再犯** —— 每条都记「现象 → 根因 → 修复」，根因比现象值钱。
> 2. **改动时知道该跑谁** —— 每条都标了防回归测试。
>
> 引用格式 `[@hash]` 为对应提交的短 hash，可用 `git show <hash>` 回看完整上下文。

## 阅读约定

| 分类 | 含义 |
| --- | --- |
| **真 BUG** | 产品逻辑错误，用户能直接感知。 |
| **伪失败** | 产品是对的，是测试/验收方法错了。**单独列一章** —— 这类最费时间，且极易被误当成产品 bug 去"修"，把正确代码改坏。 |
| **vendor 补丁** | 改的是 `vendor/` 里的依赖代码，`composer update` 会冲掉，必须重打。 |

---

## 一、真实产品 BUG

### A. 输入与编码

#### A1. CJK / 全角字符全部打不进去 `[@38cd231]`
- **现象**：编辑器、终端、AI 输入框、GIT 提交框、SEARCH 输入框 —— **五处**输入中文/全角都无反应。
- **根因**：五处的可打印判定统一写成 `strlen($char) === 1 && ord($char) >= 32`。UTF-8 的「你」是 3 字节，`strlen` 判 1 直接被拒。
- **修复**：实测 `php-tui/term` 的 `EventParser` 会正确解码成单个 `CharKeyEvent(char:'你')`，故只需放宽字节数判断。统一抽到 `App\Core\KeyInput::isPrintable()`（合法 UTF-8 + 首字节非 C0/DEL），五处调用点一并替换。
- **防回归**：`tests/m1_smoke.php`、`tests/pty_ai.php`（pty 里真打中文）。
- **教训**：**绝不用 `strlen($char) === 1` 判可打印字符**；`$char` 是多字节安全的。

#### A2. AI 面板回车发不出去 `[@8fdaccd]`
- **现象**：headless 全绿，真实 pty 下按回车毫无反应。
- **根因**：真实终端发的是 `CodedKeyEvent(Enter)`，**不是** `CharKeyEvent("\r")`；只在 `onChar` 里挂 `"\r"` 就漏了。与 M2 终端面板是同一个坑。
- **修复**：`onKey` 补 Enter 分支。
- **防回归**：`tests/ai_unit.php` 为**两条路径各补一条**回归断言（只测一条还会再漏）。
- **教训**：回车提交有两条事件路径，两条都要挂、都要测。

#### A3. 单独按 Esc 退不出捕获态 / 关不掉菜单 `[@33140d5]`
- **现象**：真实 pty 下单独按 Esc 失灵，且 `tests/pty_session.php` 直接 hang（Ctrl+Q 被当普通字符转发给 shell）。
- **根因**：读键协程空闲时调 `advance('', false)` 想冲刷孤立 ESC，但上游 `EventParser::advance()` 里 `str_split('')` 在 PHP 8.3 返回**空数组** → 循环体不执行 → 孤立 `\x1b` 永远挂在 buffer 里，等下个字节来时被当转义序列前缀吞掉。
- **修复**：`vendor` 打补丁（见第三章）+ 主循环空闲超时 1.0s→0.15s、`CharKeyEvent("\x1b")` 与 `CodedKeyEvent(Esc)` 双路关闭。
- **防回归**：`tests/pty_session.php`、`tests/pty_menu.php`。

#### A4. `vicecode <file>` 把脚本自身当待打开文件 `[@413b32c]`
- **现象**：命令行传文件名打开时，误把 `$argv[0]`（脚本自身路径）当待打开文件。
- **修复**：argv 解析跳过 `$argv[0]`。
- **防回归**：`tests/cli_dir.php`。

---

### B. 渲染与布局

#### B1. 编辑器「幽灵行」 `[@6e4c65f]`
- **现象**：在第 34 行行首输入，第 16 行同步出现同样内容。
- **根因**：三个因素叠加导致**行宽超出面板**，被 php-tui 折行后所有后续行整体下移：
  1. `Highlighter::parseHtml` 的 `strncmp()` **缺偏移参数**，比的永远是字符串开头而非 `$i` → 除首个外所有 hljs span 标签被当纯文本吞进 buf，标签碎片泄漏进编辑区（曾渲染出 `<?phpeyword">declare class="hljs-numbe`）。
  2. `App::cpWidth` 自维护 Unicode 宽度区间表，与 php-tui 的 `mb_strwidth` **漂移**（漏韩文音节 U+AC00–D7A3：我们算 1、`mb_strwidth` 算 2）。
  3. `App::clipLineSpans` 裁剪只比字素起始列，宽字符（占 2 列）在边界溢出 1 列。
- **修复**：补 `strncmp` 偏移；删自维护宽度表、统一改用 `mb_strwidth` **同源**；裁剪改为「起始列 + 自身宽度 <= 右边界」。
- **防回归**：`tests/editor_render_check.php`（高亮行数守恒 + 屏幕行号严格连续）。
- **教训**：
  - **`LineTruncator` 名不副实**：行超宽时是**折行**不是截断 → 行超面板 1 列即后续全行下移。
  - 列宽必须与 `mb_strwidth` 同源，不自维护 Unicode 区间表。

#### B2. 编辑器标签栏命中矩形残留 `[@d14850d]`
- **现象**：buffers 降到 1 个后旧的 `$tabRects` 不清掉。虽因 `onClick()`/`content()` 都判 `hasTabs()` 而不会真正误命中，属脏状态。
- **修复**：`content()` 开头无条件清空 + `tabLine()` 重建前再清空。
- **防回归**：`tests/m1_smoke.php`。

#### B3. GIT 面板所有点击 Y 偏移 2 行 `[@ec878d3]`
- **现象**：点 Commit 按钮误命中列表行。
- **根因**：`SidebarPanel::content` 在 `gitContent` 前渲染了 tab 行 + 分隔线**两行**，`gitRects` 漏算这两行。
- **修复**：新增常量 `GIT_CONTENT_OFFSET = 2` 修正，单测/pty 坐标同步 +2。
- **防回归**：`tests/git_unit.php`、`tests/pty_git.php`。

#### B4. Swoole 分支主循环缺初始 `draw()` `[@413b32c]`
- **现象**：真实 pty 下首屏空白，按第一个键才出画面。
- **修复**：主循环进入前补一次初始 `draw()`。
- **防回归**：`tests/pty_run.php`。

---

### C. 集成与外部命令

#### C1. GIT 面板在真实 pty 下 refresh 全失败、branch 永远空 `[@ec878d3]`
- **现象**：headless 正常，pty 下 GIT 数据全空。
- **根因**：协程内原走 `Coroutine\System::exec`，它依赖 `SWOOLE_HOOK_PROC`，而 `bin/vicecode.php` **刻意关闭**了该 hook（关掉才解除「proc_open 只能在协程内调用」）。
- **修复**：统一为原生 `cd <cwd> && git ... 2>&1` 的 `@exec`（子协程承载）。
- **防回归**：`tests/pty_git.php`。
- **教训**：**调外部命令一律原生 `exec`/`proc_open`**，协程 API 在 hook 关闭时行为不一致，是典型的「headless 绿但 pty 漏判」。

---

### D. 健壮性与崩溃

#### D1. 打开无权限/不存在的文件喷 PHP Warning，且误报成「二进制文件」 `[@7ab542a]`
- **现象**：PHP Warning 直接写进 alternate screen **打花画面**；且 `isBinary()` 在 `fopen` 失败时返回 `true`，把「权限不足」误报成「二进制文件」—— 用户据此去查二进制问题，方向全错。
- **修复**：**读前先判状态**（`is_file` / `is_readable`），新增 `editor.missing` / `editor.unreadable` 两条提示；`isBinary` 只在确认可读后调用。
- **注意**：**没用 `@` 压警告** —— 压掉也解决不了「用户看到空文件却不知道为什么」。
- **防回归**：`tests/m1_edge.php`。

#### D2. 崩溃后终端被留在 raw mode + alternate screen `[@7ab542a]`
- **现象**：一旦抛未捕获异常，终端无回显、无光标，**用户的 shell 直接废掉**（M0 早期花屏的根因）。
- **修复**：`start()` 用 `try/finally` 包住主体，顶层再 `catch Throwable` 转成人类可读一行。原先的 `Coroutine\defer` 因此变重复（实测每次退出发**两遍**还原序列），已移除，由 `finally` 单点负责。
- **防回归**：`tests/pty_crash.php` —— 断言**崩溃后仍发出还原序列**（`ESC[?1049l` / `ESC[?25h` / `ESC[?1000l`）。光看退出码不够：崩溃时它必然非 0，看不出终端有没有被还原。

#### D3. 退出时还原序列发两遍 `[@7ab542a]`
- 见 D2。`Coroutine\defer` 与 `finally` 重复。已由 `finally` 单点负责，并保留「还原序列只发一次」断言防再次挂重。

---

### E. 交互回归

#### E1. R5 拖拽手柄吞掉 AI 输入框焦点 `[@e25cb7b]`
- **现象**：`ai_input` 仅 3 行高，其中心点击被拖拽容差判成拖分隔条，**无法聚焦**。
- **修复**：`App::tryStartDrag` 在点击落在过小相邻面板内部时交还焦点；从大面板一侧仍可拖动调整。
- **防回归**：`tests/r5_unit.php`、`tests/pty_r5.php`。

---

### F. 国际化（i18n）

#### F1. 四处绕过 i18n 的硬编码中文 `[@ac6b42b]`
- **现象**：默认 zh_CN 与 headless 单测**都发现不了** —— 切到英文模式才中英混杂。
- **修复**：
  - `EditorPanel::save()` 无写权限错误 → 专用 key `editor.save_no_permission`（原先把中文塞进 `editor.save_failed` 的 `{msg}`）。
  - `GitModel::DROPDOWN` 四项标签 → `git.action_commit*`，渲染处过 `t()`。
  - `Theme::$label`（深色/午夜蓝）→ `theme.dark` / `theme.midnight`。
  - 帮助页 `KeyBindings` 触发列（滚轮/单击/可打印字符…）→ `keys.*`，并对**触发列也翻译**、按翻译后宽度算列宽。
- **防回归**：`tests/i18n_parity.php`（两语言包 key 集合一致 + 非空）。

#### F2. 插件页「(无插件)」硬编码中文 `[本次定版]`
- **现象**：`SidebarPanel::pluginsContent()` 在无插件时直接写中文字面量 `(无插件)`，英文模式下中英混杂。
- **根因**：插件系统 V1（2026-09-07）新增代码，未走 R8 通读的 i18n 检查。
- **修复**：新增 `plugins.no_plugins` 键（zh: `(无插件)` / en: `(no plugins)`），渲染处改 `$this->shell->t('plugins.no_plugins')`。
- **防回归**：`tests/i18n_parity.php`；排查手法见下方「F 类通用排查命令」。

> **F 类通用排查命令**（新增界面文案后应跑，实测：干净代码库 0 命中，硬编码中文必被抓出）：
> ```bash
> grep -rnP "['\"][^'\"]*[\x{4e00}-\x{9fff}][^'\"]*['\"]" src/ --include="*.php" \
>   | grep -v "^src/I18n" \
>   | grep -vP ':\d+:\s*(/\*|\*|//)' \
>   | grep -vP '//[^:]*[\x{4e00}-\x{9fff}]'
> ```
> 期望输出为空。三段过滤依次排除：语言包自身、整行注释、行尾 `//` 注释（**最后一段不能省** —— 否则正则会从代码里的引号一路跨到行尾注释，产生假阳性）。
>
> 注意：只扫中文**不够**，硬编码英文在中文模式下同样暴露，但难以自动识别，靠人工 review。

---

## 二、伪失败（产品本就正确，是验收方法错了）

> 这一章的每一条都曾让人怀疑产品有 bug。**动手改产品代码前先来这里对照一遍。**

### P1. AI 焦点标签在差分渲染里被拆词
- **假象**：断言 `aiinput` 整词找不到。
- **真相**：焦点标签恒为 `strtoupper(panelId)`，渲染 `=AI_INPUT`。差分渲染把它拆成 `A`@col68 + 跳过 col69 + `_INPUT`@col70，**缺口 col69 是边框分隔符、不是上一帧残留的 I** —— 跨帧屏幕重建也补不出 `aiinput`。
- **正解**：断言**点击帧原始字节里连续的 `_input`**（五个面板标识符仅 `AI_INPUT` 带下划线，唯一）。

### P2. 状态栏焦点段在窄屏被丢弃
- **假象**：`_input` / `AI_INPUT` 断言必失败。
- **真相**：焦点标签在 `StatusBarPanel` 优先级最低（p=35），状态栏宽度偏紧（**≤约 120 列**）时按优先级被丢弃、根本不渲染。焦点状态其实已切对（反射验证过）。
- **正解**：在**更宽视口（≥200 列）**下观测。

### P3. 内置 clock 插件把时间数字穿插进断言（最阴的一条）
- **假象**：`你好` → `你14:32好`、`hellomsg` → `h9ellomsg`；因时钟每秒变而**偶发**。
- **真相**：差分渲染只重发变化字节、单元格写序不保证左→右，状态栏时钟数字穿插进归一化流。
- **正解**（三选一）：① pty 测试启动前把 `plugins/` 改名，`register_shutdown_function` 里还原（最简单）；② 对原始字节做全屏重建（`rebuildScreen()`）后再子串匹配；③ 直接断言点击帧原始字节里的连续子串。

### P4. 侧栏树节点屏幕坐标写死
- **假象**：旧测试写死的 `(6,16)` 突然失效。
- **真相**：树条目行号随目录增删**整体平移**（新增 `src/Text/` 让后续节点下移）。
- **正解**：**动态探针** —— 在测试进程里 `new App()` 取 `areas(120x40)['sidebar']`，再按 `SidebarPanel::content()` 同款 offset 规则算行号。三角命中列 `sb.x+3+2*depth`，名称列 `sb.x+5`。

### P5. GIT 测试依赖「仓库此刻是脏的 / 分支是 master」
- **假象**：仓库干净或不在 master 时测试失败。
- **正解**：分支断言改读**实际分支**；不依赖工作区脏状态。`[@9116a91]` `[@b5c264b]` `[@e25cb7b]`

### P6. pty 三个 `['pty']` 描述符共用同一个 pty
- **假象**：只查 `$pipes[1]` 会**随机**漏掉尾部输出。
- **真相**：子进程同时写 STDOUT/STDERR 时，两个管道在**抢同一份数据**，先读的拿走。
- **正解**：断言须看 `$pipes[1] . $pipes[2]` 的**并集**。

### P7. 差分渲染残留类 bug 在 headless 复现不出
- **真相**：headless 每次新建 buffer，**无前后帧 diff**。
- **正解**：涉及滚动/重绘残留必须用真实 `Display` 连续 `draw()`。

### P8. pty 默认无窗口尺寸 → 极易误判通过
- **真相**：pty 默认 `COLUMNS`/`LINES` 为空 → php-tui 拿 0×0 **不渲染但逻辑照跑、退出码照样 0**。
- **正解**：pty 脚本**显式传** `COLUMNS`/`LINES`。

### P9. 用 grep 粗筛测试输出会把测试名里的字样当失败
- **假象**：本次全量跑批显示 6 个 pty 测试失败。
- **真相**：测试名 `[OK] 无 Fatal / Uncaught / Warning` 里含 "Uncaught"，被 `grep -i "uncaught"` 命中。
- **正解**：**以退出码为准**（`exit($failed ? 1 : 0)` 是全体一致的约定），不要 grep 关键字粗筛。
  也**不能反过来靠解析打印的 `PASS` 标记** —— 49 个测试里有 24 个压根不打印通过标记，
  且通过文案五花八门（"RESULT: PASS" / "全部通过" / "结论：…正常"），无法统一解析。
  直接用 `tools/run_tests.sh`。

---

## 三、测试自身的缺陷

> 测试也会坏，而且坏得最隐蔽 —— **它坏掉的时候是绿的**，没人会去看。

#### T1. `pty_demo.php` 永远绿 `[本次定版]`
- **现象**：失败分支只 `echo "[FAIL] exit=$code"`，**没有 `exit(1)`**，进程落到底部自然返回 0。
- **后果**：应用崩溃或挂死时，它打印 `[FAIL]` 却**仍报通过**。它是 49 个测试里唯一漏掉失败出口的（其余 48 个已逐个验证能正确报错）。
- **修复**：补 `exit($code === 0 ? 0 : 1);`。
- **为何长期没发现**：全量跑批一直显示"全绿"，没人会怀疑某个测试**根本没能力失败**。
- **如何防扩散**：已做「强制失败注入」校验 —— 按每个测试各自的失败变量名（`$failed` / `$ok` / `$totalFailed` / `$hasClock`）注入必失败值，确认退出码非 0。**新增测试后应复跑此校验。**

## 四、已知 vendor 补丁

### `php-tui/term` — `EventParser::advance()` 空行冲刷
- **文件**：`vendor/php-tui/term/src/EventParser.php`
- **为何必需**：真实 pty 读键必须 `more=true`（序列常被 `fread` 拆段），孤立 ESC 的冲刷分支是必走路径。**不打此补丁 `tests/pty_session.php` 必 hang、单独 Esc 全失灵。**
- **风险**：`composer update` 会冲掉，须手工重打。
- **补丁内容**（`advance()` 开头）：
  ```php
  if ($line === '') {
      if ($this->buffer === []) return;
      try { $event = $this->parseEvent($this->buffer, false); }
      catch (ParseError) { $this->buffer = []; return; }
      if ($event !== null) { $this->events[] = $event; $this->buffer = []; }
      return;
  }
  ```
- **原则**：**不改 vendor 里的依赖代码是原则**。任何解析层/终端协议层诉求，先判断能否只动 `src/` 绕开；确实绕不开须向维护者点明并让其拍板。

---

## 五、防回归索引（改了哪块 → 跑哪些测试）

> **跑批统一用 `tools/run_tests.sh`**（或 `composer test`）：它按**退出码**判定，支持按文件名过滤
> （`tools/run_tests.sh pty`）、单测超时、失败时回显末 15 行。**不要用 grep 关键字粗筛输出**，理由见 P9。

| 改动区域 | 必跑测试 |
| --- | --- |
| 编辑器 / 高亮 / 折行 | `m1_smoke` `m1_edge` `editor_render_check` `hscroll_unit` |
| 侧栏树 / 鼠标坐标 | `m0_smoke` `pty_mouse`（坐标用动态探针） |
| GIT 面板 | `git_unit` `pty_git` |
| Search | `search_unit` `pty_search` |
| AI / 流式 | `ai_unit` `ai_copy_unit` `pty_ai`（≥200 列） |
| 终端 / PTY | `interactive_term_unit` `pty_term` `pty_interactive` `pty_session` `pty_persist` `pty_alt_screen` `pty_scroll` |
| 布局 / 拖拽 | `r5_unit` `pty_r5` |
| 配置持久化 | `r7_unit` `pty_r7` |
| 菜单 / 快捷键 | `menu_unit` `menu_dropdown` `m6_unit` `pty_menu` |
| 插件 | `plugin_unit` `pty_plugin` |
| 剪贴板 / 选择 | `clipboard_unit` `selection_unit` |
| 语言包 | `i18n_parity` |
| 崩溃与还原 | `pty_crash` |
| 极小视口 | `m1_edge`（已含退化尺寸至 1×1） |

> `tests/` 下带 `probe` 字样的文件（`m2_probe_runner`、`sse_probe*`）是**探索性探针，不是测试**，不参与验收。

## 六、验收纪律（从上述 bug 中提炼）

1. **跑批一律走 `tools/run_tests.sh`**，别自己写循环 + grep 判定（见 P9 与 T1）。
2. **不能只信 headless 绿** —— 大功能必须在真实 pty 下复验，断言 `exit=0` 且无 Fatal/Warning（C1、A2、A3 都是 headless 全绿、pty 才暴露的）。
3. **先拆最小可验证样本**，逐个验收，有证据再推进。
3. **压边界**：极小视口（含 1×1）、空文件、超长单行、数百行翻页、CJK、null buffer 时滚轮/键入。
4. **禁止裸跑 `bin/vicecode.php`** —— 曾阻塞读键挂死、未还原 raw mode 花屏。一律走 pty 工具并套 `timeout`。
5. **渲染层 `str_repeat`/切片宽度先 `max(0, …)` 夹紧**。
6. 遇到疑似渲染 bug，**先查第二章的伪失败清单**再动手改产品代码。

# ViceCode BUG 修复清单

> 覆盖范围：v0.0.1 定版前后（2026-08 ~ 2026-09）全部已修复缺陷，共 **28 个真实产品 BUG + 9 类测试伪失败 + 1 个测试自身缺陷 + 1 处 vendor 补丁**。
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

#### B5. 侧栏树行「双重截断」：横滚后内容丢失、滚过头整行空白 `[@3ff7e1c]`
- **现象**：侧栏（只有 28 列 inner）里放长文件名 / 深目录时，横向滚动后**看不到后面半个名字**；`hScroll` 超过 inner 宽度后**整行变空白**。
- **根因**：树行先 `mbCutDisp($text, $innerW)` 截到 innerW，再 `mbSubDisp($text, hScroll, $innerW)` 横滚切片。第一次截断已经把超出 innerW 的内容永久丢掉，第二次切片只能在「已截断的这 innerW 列」里滑动 —— `hScroll` 越大露出越少，超过 innerW 后全是空。
  同文件里 GIT/Search/分支下拉的列表行都是直接 `mbSubDisp(完整文本, …)`，**只有树行多了一次 cut**，属于拷贝时的偏差。
- **修复**：去掉提前的 `mbCutDisp`，只对完整文本做 `mbSubDisp($text, $hScroll, $innerW)`（切片函数本身已负责取满 innerW 列）。
- **防回归**：`tests/sidebar_hscroll.php`（hScroll=0/10/30/50 逐档断言行文本是完整文本的连续切片且占满 innerW；**文件节点**同样覆盖：126 列长名滚到最右以 `.log` 结尾、CJK 长名不劈字、205 列名上界撑得开）、`tests/pty_sidebar_hscroll.php`（真实 pty：横滚到底后长名目录尾部 TAIL / 长名文件尾部 zzend 可见）。
  **同类面**（都直接 `mbSubDisp` 完整文本、本来就没这 bug，但已各钉一条防回归）：Search 命中行 / 分支下拉列表项 / 极小视口三 tab。
- **注**：横滚偏移是**侧栏内全局共享**的（`maxHScroll` 取最长那行），所以比最长行短的行会被"带过头"而滚空 —— 这是合理行为，写断言时要按**该行自身宽度**算终点，不能用全局 `maxHScroll`。

#### B6. 侧栏三角命中列漏减 `hScroll`：横滚后点三角错位、深目录永远点不到 `[@3ff7e1c]`
- **现象**：① 横滚后点在三角上**没反应**，点在文件**名**上却展开/折叠了（整体右偏 hScroll 列）；② 目录层级深（>13 层）时三角列 `x+3+2*depth` 已在侧栏右边界外，**鼠标根本没法折叠**。
- **根因**：`hitArrow()` 按 `$sb->x + 1 + 2 + $depth*2` 算命中列，而行是按 `mbSubDisp(text, hScroll, innerW)` 渲染的 —— **屏幕列 = 文本列 − hScroll**，公式漏了减 `hScroll`。
- **修复**：公式减 `$this->hScroll`；并把命中列夹在 inner 左右界内 —— 三角被滚出可视区（左溢出，或深到还没滚进来）时返回 false，避免点在名称区却误 toggle。深目录在横滚后三角进入可视区即可点击。
- **防回归**：`tests/sidebar_hscroll.php`（hScroll=4 时点 +1/+2 命中、+5 不命中；30 层树横滚 20 列后点 depth=20 三角生效，未横滚时不误命中）、`tests/pty_sidebar_hscroll.php`（真实 pty：横滚 4 列后点新位置能 toggle、点旧位置不 toggle）。
- **教训**：**凡是「渲染时减了滚动偏移」的列表，命中判定必须同步减同一个偏移** —— 与 E2（AI 复制漏 `scroll`）是同一类映射错误。

#### B7. GIT 面板行首图标命中列漏减 `hScroll`：深路径横滚后点路径会误触「✕ 丢弃」 `[@3ff7e1c]`
- **现象**：GIT 面板里深路径（`src/A/B/C/…/File.php`，81 列）在 28 列的侧栏里必须横滚。横滚后点**路径文本**，弹出的却是「丢弃工作区改动」确认框 —— 而 ✕ 是**不可逆操作**，用户只是想点开 diff。
- **根因**：`gitClick()` 用 `$rc = $col - $innerX` 直接按屏幕列判 0~1/2~3/4~5/6~7 四个图标，但列表行是按 `mbSubDisp(完整文本, hScroll, innerW)` 渲染的，**屏幕列 = 文本列 − hScroll**。与 B6 完全同源（`gitRects()` 里那些 `itemOpenX/itemPlusX/itemMinusX/itemDiscardX` 其实是**文本列**常量，注释却写成 inner 列，容易再次误导）。
- **修复**：判定前换算回文本列 `$tc = ($col - $innerX) + $this->hScroll`；图标被滚出左界时 `$tc > 7` 自然落到「文件名 = 开 diff」分支。
- **防回归**：`tests/sidebar_hscroll.php` 场景 4（hScroll=0 点列 6 弹确认 / hScroll=4 ✕ 移到列 2 且列 6 不再误触 / hScroll=10 图标滚出后任何列都不弹）。
- **教训**：**「按列命中」的判定，只要那一行参与横滚，就必须先换算回文本列**。Search 列表是「按行定位」所以没事 —— 判断依据看的是**有没有按列分支**。

#### B8. AI 面板 hScroll 无上界：一路横滚把消息流滚成白板 `[@3ff7e1c]`
- **现象**：在 AI 面板连按 Shift+→（或触控板横滑），消息先是左侧被切掉，滚过约一个面板宽后**整片空白**——内容像凭空消失，且要反向滚很多次才回得来。
- **根因**：`AiPanel::onScrollH()` 只做了 `max(0, …)`，**没有上界**。而消息行是先 `mbWrapDisp` 软换行再渲染的，行宽**本来就不超过面板宽**，横滚不会露出任何新内容，只会「左边切掉 + 右边留白」。侧栏有 `computeMaxHScroll` 并钳制，AI 面板漏了这一半。
- **修复**：`buildLinesWithMap()` 里先构造原始行、统计最宽行，再把 `hScroll` 钳到 `max(0, 最宽行 - W)`；因为折行后行宽 ≤ W，上界通常为 0（= 横滚自然失效），只有极窄视口出现超宽行时才允许滚一点。
- **防回归**：`tests/ai_hscroll.php`（预设 hScroll=500 → 渲染后钳回 0、连续横滚 100 次仍不越界、画面始终非空）。

#### B9. AI 消息续行缩进把行撑宽，续行末尾字符被吃掉 `[@3ff7e1c]`
- **现象**：长消息折行后，**续行末尾几个字符不见了**（实测 500 字符的消息拼回来只剩 456 个，丢 44 个）。
- **根因**：正文按整宽 `$W` 折行，续行却还要再加 4 列缩进（`AI: ` 的宽度）→ 续行实际 `$W + 4` 列，超出面板的部分被 `mbSubDisp` 切掉。首行不受影响（前缀本就在折行输入里），所以短消息、只看首行都发现不了。
- **修复**：正文按**扣除缩进后的宽度**折行（`$bodyW = W - 缩进宽`），首行前缀与续行缩进等宽，所有行都刚好 ≤ W。
- **防回归**：`tests/ai_hscroll.php`（断言每行 ≤ 面板宽；把各行去掉前 4 列拼接后必须与原文**逐字符相等**）。
- **教训**：**「折行宽度」要扣掉行首装饰（前缀 / 缩进 / 图标）的宽度**；校验软换行是否正确，最硬的断言是「拼回去等于原文」，只数行数看不出来。

#### B10. 终端横滚后拖拽选区复制的是「行首那几个字」 `[@3ff7e1c]`
- **现象**：终端（runner 模式）横向滚动后，拖着鼠标选中屏幕上看到的几个字符，松手复制进剪贴板的却是**这一行的开头几个字**（且常只有 1 个字符）。未横滚时完全正常。
- **根因**：`getTextRect()` 里 `dispA/dispB = absCol - inner.x - hScroll` —— **符号反了**。渲染是 `mbSubDisp(text, hScroll, W)`，屏幕第 d 列显示的是**文本第 d+hScroll 列**，所以换算要**加**回偏移。减了之后两个端点都被 `max(0, …)` 挤到 0，选区退化成「行首 1 字符」。
- **修复**：改成 `+ $this->hScroll`，注释一并改正（原注释的推导就是错的，照着它改只会再错一次）。
- **防回归**：`tests/hscroll_unit.php` §12（未横滚拖 0..4 → `ABCDE`；横滚 10 列拖 0..4 → `KLMNO`；横滚 26 列 → `0123`）。
- **注**：pty 模式**不走** hScroll（仿真器按面板宽度折行，内容本来就不超宽），选区直接从可见网格取，所以只有 runner 模式（命令输出超长单行）会踩到。
- **教训**：侧栏/GIT 是「命中列 − 偏移」，编辑器/终端选区是「屏幕列 **+** 偏移」——**同一个 hScroll，两个方向**。改之前先确认这一处是「屏幕 → 文本」还是「文本 → 屏幕」。

#### B11. Markdown 里列表项 / 引用块内的**块级**子节点内容整段丢失 `[本轮]`
- **现象**：AI 回复里带「列表 + 缩进代码块」或「引用块里放列表/代码」时，**内容凭空消失**——例如
  `- 步骤一` 下面缩进的 ```php 代码块整块不见；`> - alpha\n> - beta` 只剩一个空的 `│ ` 行。
  短文本、纯段落看不出来，只有嵌套块级结构才触发。
- **根因**：`MarkdownFormatter` 的 `BlockQuote` / `renderListItem` 分支一律用
  `renderInlines($child->children())` 展开子节点，**假设子节点都是行内**。但这两处的子节点是
  **块级**：围栏/缩进代码的内容在 `getLiteral()`（`children()` 为空），列表/引用的子节点是
  `ListItem` / 块节点（都不是 `AbstractInline`），于是 `renderInlines` 的 `foreach` 一个分支都
  不命中 → **静默丢弃**（无任何报错）。
- **修复**：两处都改为「Paragraph 走行内展开（保留引用正文色等既有语义），其余块级子节点
  递归 `renderBlock()` 后逐行挂前缀」；列表项的标记前缀/续行对齐语义保持不变；空列表项补标记
  兜底，避免 `- ` 这种空项在屏幕上消失。
- **防回归**：`tests/ai_edge_unit.php`（列表内代码块 / 引用内代码块 / 引用内有序与无序列表 /
  列表内引用 / 列表内标题 / 双层引用，共 7 例 + 既有排版零回归断言）。
- **教训**：写 AST 渲染器时，**每一层的子节点类型都要先问清是块级还是行内**；块级节点用行内
  展开器遍历是「静默丢内容」而不是报错，测试必须用嵌套结构样本，纯段落样本测不出来。

#### B12. 极窄面板下前缀把首行撑宽 → 触发 php-tui 折行（幽灵行） `[本轮]`
- **现象**：AI 面板被布局压到极窄（W < 前缀宽 `You: ` / `AI: ` = 5 列）时，首行 = 前缀 + 至少
  1 列正文，**远超面板宽**；php-tui 的 `LineTruncator` 超宽会**折行**，把该行之后的整片内容
  向下挤（即 B1「幽灵行」的同一机制），窄视口下消息流右侧还会出现别处的字符串。
- **根因**：`pushTextRows()` / `pushMarkdownRows()` 用 `$bodyW = max(1, $W - 前缀宽)` 给正文留量，
  但**前缀本身从不夹紧**——W 小于前缀宽时 `max(1, 负数)` 兜底成 1，首行宽度恒为「前缀 + 1」。
- **修复**：前缀先按 `min(前缀宽, max(0, W - 2))` 截断（给正文留 2 列，保证 2 列宽的字素也放得下）
  再算折行宽度；缩进沿用截断后的宽度，因此所有物理行恒 ≤ W。
- **防回归**：`tests/ai_edge_unit.php`（W=2/3/4/5/6/8/10/16 下逐行断言行宽 ≤ W，样本含 CJK、
  Markdown、超长工具调用参数与工具摘要行）。
- **教训**：`max(1, W - 装饰宽)` 只是「别算出负数」，不等于「装饰本身合法」。**装饰件也要有
  宽度上界**，否则它在窄屏下直接把行撑爆。

#### B13. Markdown 折行把前缀劈成两半（`AI` / `:` 分处两行） `[本轮]`
- **现象**：极窄宽度下（实测 W=6）Markdown 消息的首行渲染成「`AI`」独占一行、下一行以「`:`」
  开头，前缀与正文被搅在一起；顺带首行能放的内容比应有的更少。
- **根因**：`pushMarkdownRows()` 把前缀当成折行流的**第一个 span** 交给 `spanWrapDisp`，而流宽
  `flowW = W - 缩进宽` 在前缀宽于它时（`缩进宽 > W/2`）会把前缀**从中间切开**；
  `pushTextRows()` 一直是「前缀在流外」，两条路径语义不一致。
- **修复**：统一为「前缀/缩进不进折行流」——正文单独按 `flowW` 折行，首物理行挂前缀、其余行挂
  等宽缩进。行宽恒 ≤ 缩进 + flowW = W，前缀永远完整，两条路径语义一致。
- **防回归**：`tests/ai_edge_unit.php`（W=6 极窄下「逐行剥掉 4 列前缀/缩进后拼接 == 逻辑行拼接」，
  以及超长围栏在 W=40 下的同一不变量）。
- **教训**：行首装饰（前缀/缩进）与正文应当**分开处理**：装饰进折行流，就等于允许它在任意位置
  被切断。B9 是「宽度算错」，B13 是「装饰位置算错」，同一个装饰件的两种错法。

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

#### D4. 外部上报值（cwd / 文件名 / 分支）夹带控制字符 `[@3ff7e1c]`
- **现象**：shell 经 OSC 报来的 cwd 若含 `\n` / `\r` / ESC 序列（路径可含任意字节，程序也能伪造 OSC），这些字符**不显示却仍被 `dispWidth` 算作 1 列**，固定宽度的状态栏因此少显内容、文本被莫名截断。
- **修复**：`DisplayWidth::stripControl()`（字节级剔 `\x00-\x1F\x7F`，不加 `/u`，非法 UTF-8 也不会让 preg 返回 null），在状态栏组装前净化 cwd / 文件名 / 分支名。
- **注**：本条修的是**宽度计算与显示不一致**（控制字符不显示却占 1 列）。
  ⚠️ 原文还写过「php-tui 渲染时会丢弃控制字符，所以不是安全漏洞」——**这条结论在 D5 被实测推翻**：
  php-tui 会把 span 文本逐字写进终端，`ESC]52;…BEL` 能原样到达终端。请勿再据该结论放过净化。
- **防回归**：`tests/m6_unit.php`（断言状态栏文本不含控制字符、净化后可见文本连续）。

#### D5. AI 回复 / `@文件` / 工具结果里的控制字符原样写进终端（终端转义注入）`[本轮]`
- **现象**：模型回复（或 `@文件`、工具读到的文件内容）里若含 `ESC ] 52 ; c ; <base64> BEL`，会被
  **原样写进终端**——xterm 等终端会照做，等于把**系统剪贴板**交给模型输出或一个被读入的文件；
  同理 `ESC [ 2 J` 可清屏、其它 CSI 可挪光标改颜色。TAB 另有一害：终端按 8 列制表位展开，
  而 `dispWidth()` 只按 1 列计，两者不一致 → 该行内容错位、被挤到别的行上（实测同一行拆成两行）。
- **根因**：内容从 `ChatModel` 直接进 `AiPanel` 的 span 文本，中间**没有任何净化**；而 span 文本是
  逐字写进终端的。D4 当时记过「php-tui 会丢弃控制字符」，本轮**实测不成立**：把净化改成恒等函数后，
  pty 字节流里能抓到完整的 `ESC]52;c;…BEL`（取证见下）。
- **修复**：新增 `DisplayWidth::sanitizeContent()`（TAB 先展开为 4 空格，再剔除**除 `\n` 外**的 C0
  与 `\x7F`），在 `AiPanel::pushTextRows()` / `pushMarkdownRows()`（覆盖全部消息正文、工具调用参数、
  工具摘要、错误行）与 `inputContent()`（粘贴进来的内容）入口统一净化。Markdown 必须在**解析前**
  净化（保留 `\n`，块结构不受影响），逻辑行缓存键也基于净化后的文本。
- **防回归**：headless `tests/ai_edge_unit.php`（span 里无 ESC / TAB / 其它 C0，且正文不丢）；
  pty `tests/pty_ai_inject.php`（mock 回复里带 OSC 52 载荷与 TAB：累计字节流里既无带 ESC 的 OSC
  载荷、也无**任何**裸 TAB，同时正文标记仍在）。**反向验证**：把 `sanitizeContent()` 改成恒等函数，
  该 pty 测试必须 FAIL（实测能抓到 `ESC]52;…BEL`）。
- **教训**：**凡是外部/模型给的文本进终端，都要过控制字符净化**。`stripControl()`（状态栏外部值，
  D4）与 `sanitizeContent()`（保留换行的内容，本条）是两个场景的两个工具，不能互相替代；
  且「框架会帮我们丢掉控制字符」这类假设**必须用 pty 字节流证实**，读代码推断不算数。

#### D6. 每开一次交互终端，`/tmp` 里就永久多一个 `vicetui_rc_*` `[本轮]`
- **现象**：`/tmp/vicetui_rc_XXXXXXXX` 越积越多（本机一天跑批 + 手工用应用后攒了 33 个），
  每个 190 字节，内容就是 bash `--rcfile` 用的那段集成脚本（stty + source ~/.bashrc + cwd 上报钩子）。
- **根因**（两个叠加，缺一不可）：
  1. `TerminalPanel::pollPty()` 在 shell 退出（Ctrl+D / `exit`）时**直接 `$this->pty = null`**，
     丢掉实例却不调 `shutdown()` —— 而只有 `PtyProcess::shutdown()` 会 `unlink()` 那个 rc 文件。
     用户每退一次 shell 就漏一个；`PtyProcess` 也没有 `__destruct` 兜底。
  2. `PtyProcess::shutdown()` 开头的 `if (!is_resource($this->proc)) { … return; }` **提前返回**时
     跳过了 rc 文件清理，于是「进程句柄已回收」这条路径也漏。
- **修复**：`TerminalPanel` 新增 `dropPty()`（**先 `shutdown()` 再置空**），
  `pollPty()` / `startPty()` 失败分支 / `restoreSession()` 失败分支 / `shutdown()` 四处统一走它；
  `PtyProcess::shutdown()` 抽出 `cleanupRcFile()`，早退分支**也**调用（幂等）。
- **防回归**：`tests/pty_interactive.php` `pty_persist.php` `pty_session.php` `pty_alt_screen.php`
  （断言"运行前后 `/tmp/vicetui_rc_*` 数量不变"）。**反向验证**：不调 `dropPty()`（改回 `= null`）时
  四个测试各至少新增 1 个残留文件（其中 `pty_alt_screen` +3）。
- **注**：这条**不会**留下孤儿 shell —— 因为它的触发场景是「shell 自己先退出了」（Ctrl+D / `exit`），
  漏的只是文件。**「shell 还活着时退出」是另一条路径，会漏一个活着的孤儿 shell**，见 D7。
  不要把这两条混成一件事：D6 的位置是**正常退出**链路，D7 的位置是**异常退出**链路。

#### D7. 交互 shell 还活着时异常退出 → 留下**活着的孤儿 shell** + rc 临时文件 `[本轮]`
- **现象**：开着交互终端（shell 活着）时应用异常退出，那个 bash 会**继续活着**（实测每次运行漏 1 个，
  `Ps` 状态、被 reparent 到 init、一直占着 pty），同时 `/tmp/vicetui_rc_*` 也留下。
  **它扛得住 SIGTERM**（交互式 bash 忽略 SIGTERM），只有 SIGKILL 能收掉 —— 所以这些孤儿会一直堆着。
- **根因**：子进程回收只挂在 `Lifecycle::quit()` 的关闭闭包上（`chat/search/terminal->shutdown()`），
  而**只有正常退出（Ctrl+Q 等）会经过它**。`bin/vicecode.php` 的 `start()` 里那个 finally
  只做了 `saveConfig()` + `restoreTerminal()`（那是当年修「崩溃后终端废掉」时加的，同样出于
  「所有退出路径都要覆盖」的考虑，却漏了资源回收）→ 未捕获异常等路径上没人杀 pty 子进程。
  ⚠️ 曾以为「pty 主端关闭时内核会发 SIGHUP 兜底」——**实测不成立**，bash 照活。
- **修复**：`App::shutdownResources()`（**幂等**，`$resourcesDown` 闸住：正常路径由 Lifecycle 调、
  异常路径由 finally 调，只真正回收一次），并把 `bin/vicecode.php` 的 finally 改成
  `saveConfig()` → `shutdownResources()` → `restoreTerminal()`。
- **防回归**：`tests/pty_crash.php` 新增场景 B —— 注入的 `throw` 不在启动时抛，而是**等 F2 把 shell
  起起来之后**（以「`/tmp/vicetui_rc_*` 出现」为 shell 起没起的判据）再抛，然后断言
  「rc 文件数不增加」+「`bash --rcfile` 进程数不增加」。**反向验证**：摘掉 finally 里的
  `shutdownResources()` 后两条断言都 FAIL（rc 0→1、孤儿进程 +1）。
  该用例同时补了正向锚点「shell 真的起来了」，否则阴性断言是空转。

---

### E. 交互回归

#### E1. R5 拖拽手柄吞掉 AI 输入框焦点 `[@e25cb7b]`
- **现象**：`ai_input` 仅 3 行高，其中心点击被拖拽容差判成拖分隔条，**无法聚焦**。
- **修复**：`App::tryStartDrag` 在点击落在过小相邻面板内部时交还焦点；从大面板一侧仍可拖动调整。
- **防回归**：`tests/r5_unit.php`、`tests/pty_r5.php`。

#### E2. AI 消息流滚动后点击复制的是「顶部那条」 `[@d3b9273]`
- **现象**：对话超过一屏后，滚到中间点某条消息复制，进剪贴板的却是**第一条**消息；未滚动（`scroll=0`）时一切正常。
- **根因**：`AiPanel::copyMessageAtRow()` 把**可见行下标 `$v`** 直接当成 `buildLinesWithMap()` 返回 map 的下标，而 map 的下标是**软换行后的全量行**——可见第 v 行对应全量第 `scroll + v` 行。漏掉 `$this->scroll` 偏移，就永远取到顶部那条。
  同类映射在编辑器（`$buf->scrollTop`）和侧栏列表（`$this->offset`）都是对的，只有 AI 面板漏了。
- **修复**：先算 `$abs = $this->scroll + $v`，再取 `$map[$abs]`。
- **防回归**：`tests/ai_copy_unit.php` 新增「滚动后点首行 / 点末行」两条断言（注入 80 条消息强制 `scroll > 0`）。
- **教训**：**「可见行 → 全量行」映射必须显式加滚动偏移**；这类 bug 只在「内容超过一屏」时暴露，短样本永远测不出来——写点击类测试前先造出滚动。

#### E3. 状态栏超长 cwd 整段消失（值型段不放截断） `[@3ff7e1c]`
- **现象**：终端里 `cd` 进深目录后，状态栏的「目录=…」**整段不见**，也没有任何省略号提示；实测 120 列下 cwd 超过约 35 列就被丢。用户在深目录里最需要看到路径时，恰恰看不到。
- **根因**：`assemble()` 的取舍是「塞得下就留、塞不下就整段丢」。这对短段（语言、焦点）合理，但 cwd / 文件名 / 分支名是**值型**信息，价值集中在**尾部**（当前目录名、扩展名），整段丢等于信息归零。
- **修复**：段结构新增可选 `pfix`（不参与截断的标签前缀，如 `目录=`）与可截断的 `t`（值本身）。塞不下时 `truncateValue()` 按剩余空间截成 `目录=…/尾部`（`DisplayWidth::mbTailDisp()` 取尾部、保留字素边界）；只有剩余宽度 < `MIN_TRUNC`(12) 时才整段丢弃。
- **防回归**：`tests/m6_unit.php`「长值截断 + 外部值净化」段（超长 cwd 不被 dropped、保留尾部、不超宽；40 列时仍整段丢弃）。
- **注意**：`join()` 必须拼 `pfix . t`，且 **t 为空时段整体跳过** —— 否则「非终端焦点」时会露出一个空的 `目录=`。

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

#### T2. 51 个测试的配置不隔离：串档 + 依赖开发机家目录 `[本轮]`
- **现象一（串档）**：测试用 `tempnam(sys_get_temp_dir(), …)` 或 `sys_get_temp_dir().'/x.json'` 当
  `VICECODE_CONFIG`。存档路径是 `dirname(VICECODE_CONFIG)/.vicecode_ai`，而这两种写法的 `dirname()`
  都落在 `/tmp` 根 → 全体测试**共用 `/tmp/.vicecode_ai`**。`aiPersist` 默认开启、`App` 构造末尾会
  `ChatModel::restore()`，于是后跑的测试**恢复了前一个测试的对话**（「空态不是空的」、断言行号整体
  平移），且随跑批顺序偶发。**25 个测试**中招。
- **现象二（依赖开发机家目录）**：另有一批测试建了 `App` 却**完全不设** `VICECODE_CONFIG`，
  `ConfigStore` 回落到真实 `~/.vicerc`，插件配置回落到 `~/.vicecode.plugins.json`。
  **测定手法**：不动真实 home，用 `HOME=<毒化目录>` 跑对照组（毒化 home 放一份非默认布局+其它主题
  语言的 `~/.vicerc` 与一份带 tool_calls 的 `~/.vicecode_ai`，另跑空 home 作 CONTROL）。
  结果：CONTROL 25/25 全过，POISON **挂 6 个** ——
  `m6_unit`（主题环起点）、`menu_dropdown`（菜单标签）、`pty_git`（GIT tab 全挂）、
  `ai_hscroll`（折行宽 430≠500）、`sidebar_hscroll`（折叠命中列）、`m1_edge`（翻页边界）。
  即这些断言**只在本机配置下成立**。本机 `~/.vicerc` 本来就是 `aiInputHeight=8`（默认 5）、
  `sidebarWidth=30`，所以这层依赖**早就在生效**，只是差异还没大到把断言顶翻。
- **修复**：新增 `tests/lib/isolation.php`：
  `vc_isolate_config('tag')`（独占目录，**同时**隔离 `VICECODE_CONFIG` 与 `VICECODE_PLUGINS_CONFIG`，
  shutdown 自动清理；pty 用例把返回路径塞进子进程 env，父子同源）、
  `vc_tmp_file()` / `vc_tmp_dir()`（替代裸 `tempnam`，退出时自动删）。
  51 个测试改用它，**41 处 `tempnam` 全部收编**。
- **同一轮挖出的两个次生缺陷**：`pty_ai_v2` 手写的清理只 `rmdir` 空的 `src/`（目录非空 → 静默失败，
  目录连同存档永久留下）；`command_palette_unit` 的 `/tmp/vc_palette_<pid>` 压根没有清理。
- **探针纪律**：`glob("$dir/*")` **不匹配点号开头的文件**，头一版清理器因此一个文件都没删掉、
  `rmdir` 静默失败，跑批后 `/tmp` 留了 15 个残留目录 —— **清理/遍历这类"看不到报错"的收尾动作，
  必须跑完用 `ls` 实地确认**。
- **防回归**：毒化 HOME 复跑 POISON **归零**（天然的"反向可失败"证据）；跑批后
  `/tmp` 零残留、无 `/tmp/.vicerc`/`.vicecode_ai`/`.vicecode_session`/`.vicecode.plugins.json`。

#### T3. `pty_search` 三连：假阴性 + 僵尸断言 + 假阳性 `[本轮]`
- **复现**：连续跑全量批，第 2 轮抓到（此前约 5 轮里挂 2 轮；`run_tests.sh` 只给退出码，
  失败详情里能看出挂在哪条）：
  ```
  [OK]   SEARCH tab 显示输入占位提示
  [FAIL] 键入进搜索框（捕获关键词 MARKER）      ← 假阴性
  [OK]   R2：搜索结果在真实终端中出现              ← 却是"假过"
  [OK]   R2：有命中时不显示「无匹配结果」           ← 恒真（没测到任何东西）
  ```
- **三个独立的毛病**（都在同一个用例里，性质完全不同）：
  1. **假阴性**：「键入进搜索框」用 `waitForAny(..., 3000)` 断言 3s 内出现，而同一批里
     搜索本身是成功的 —— 只是**负载高时应用慢半拍**才把那一行渲染出来。窗口短不是"更严格"，
     是更脆。改成 8s 条件等待，且断言落在**重建后的最终帧**上。
  2. **僵尸断言**：本用例**没设 `APP_LOCALE`**、界面是默认 `zh_CN`，而「不显示无匹配结果」
     查的是英文 `nomatches`/`noresults` —— **那串英文永远不可能出现，断言恒为真**。
     实测证明：把状态行注入成恒定「无匹配结果」后，这条英文断言照样 PASS。
  3. **假阳性**：「结果出现」查 `zzuniquemarker`，而**输入框里就有这个词** —— 就算搜索一行
     结果都没渲染，断言也会过。实测证明：注入「结果列表不渲染」后，旧的 `zzuniquemarker`
     断言会 PASS。改成只可能来自结果列表的**命中行内容**（`// ZZUNIQUEMARKER_LINE`
     归一化后 `zzuniquemarkerline`，比查询词多一个 `line`），并另加一条「命中统计可见」辅证。
- **正确的断言姿势**（三条一起改）：一切断言都建在**重放后的最终帧**上
  （`tests/lib/pty_screen.php`），而不是累积流 —— 差分渲染只重发变化格、同一行还会被拆成多次
  「定位+写入」，中间任何一帧渲染过的东西都永久留在累积流里，**阴性断言**尤其会被它污染。
  同时在切换 tab 之后加 `waitQuiet()`（等解析器安静）再键入，避免新字节与上一步半截 SGR 序列抢解析。
- **注入验证**（三条断言各自的牙齿都验过）：
  | 注入 | 预期 | 实测 |
  | --- | --- | --- |
  | A. 「搜索中」那一帧也渲染「无匹配结果」（中间帧污染） | 新写法不受影响 | 5 条断言**全绿**；把阴性断言临时改回**累积流**风格 → **FAIL**（证明必须用最终帧） |
  | B. 最终状态错（有命中却渲染「无匹配结果」） | 阴性断言必须 FAIL | 新断言 **FAIL**；同一状态旧**英文**断言 **PASS**（僵尸实证） |
  | C. 结果列表不渲染 | 命中行锚点必须 FAIL | **FAIL**（`命中行=无 统计=有`）；旧的 `zzuniquemarker` 断言会 PASS（假阳性实证） |
- **教训**：**阴性断言要问三件事** —— ①它断言的是"最终状态"还是"历史流"？②那个串在当前语言包下
  真的会出现吗（本用例默认 zh_CN，却写了英文串）？③有没有别的地方也带着这个串（输入框/状态栏消息
  都能让"结果出现"假过）。三问任一没答，断言就可能永远绿着。
- **同类第二例（`pty_m6`，同一轮发现）**：它断言「滚到底后 AI 分组的 Ctrl+P / Ctrl+N 进入视口」，
  用的是**差分累积流** —— 差分流只重发变化格，滚到底时 `ctrlp` 那几格恰好没被重发 → 假失败。
  重建最终帧的实测数据完全相反：默认视口帧 `ctrlp=0,ctrln=0`、滚到底帧 `ctrlp=1,ctrln=1,ctrlr=1`。
  改为对重建帧断言后通过，并顺手加了「滚到底能看到新增的 Ctrl+R」。
  同一条纪律：**判"屏上有什么"一律重建最终帧**；且**段名是本地化的**（zh 下是「焦点=」而不是
  `focus`），锚点要挑与语言无关的（值里的标识符 `AI_INPUT`、或 app 标题 `ViceCode`）。

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
| 侧栏树 / 鼠标坐标 | `m0_smoke` `m1_smoke`（三角命中列）`sidebar_hscroll`（横滚 + 深层树点击）`pty_mouse` `pty_sidebar_hscroll`（坐标用动态探针） |
| 状态栏段取舍 | `m6_unit`（按优先级裁剪 + 长值截断 + 外部值净化） |
| GIT 面板 | `git_unit` `pty_git` `sidebar_hscroll`（深路径横滚 + 行首图标命中） |
| Search | `search_unit` `pty_search` |
| AI / 流式 | `ai_unit` `ai_copy_unit` `ai_hscroll`（超长消息折行 + 横滚上界） `pty_ai`（≥200 列） |
| AI 内容渲染 / Markdown / 不可信内容 | `ai_md_unit`（Markdown 元素与 spanWrapDisp 不变量）`ai_edge_unit`（块级嵌套内容不丢 / 控制字符净化 / 极窄宽度行宽上界 / 超长围栏拼接不变量）`pty_ai_inject`（终端转义注入，mock 回复带 OSC 52 + TAB） |
| 终端 / PTY | `interactive_term_unit` `hscroll_unit`（超长输出横滚 + 选区抓取） `selection_unit` `pty_term` `pty_interactive` `pty_session` `pty_persist` `pty_alt_screen` `pty_scroll` |
| 布局 / 拖拽 | `r5_unit` `pty_r5` |
| 配置持久化 | `r7_unit` `pty_r7` |
| 菜单 / 快捷键 | `menu_unit` `menu_dropdown` `m6_unit` `pty_menu` |
| 插件 | `plugin_unit` `pty_plugin` |
| 剪贴板 / 选择 | `clipboard_unit` `selection_unit` |
| 语言包 | `i18n_parity` |
| 崩溃与还原 | `pty_crash`（含**场景 B**：交互 shell 活着时崩溃 → 终端还原 + 资源回收 + 无孤儿进程） |
| 极小视口 | `m1_edge`（已含退化尺寸至 1×1） |

> `tests/` 下带 `probe` 字样的文件是**探索性探针，不是测试**，不参与验收（`run_tests.sh` 会跳过）：
> `m2_probe_runner`、`sse_probe*`、`probe_edge_deep`（长 cwd × 视口宽度扫描 / 30 层深树）、
> `probe_tree_hscroll`（侧栏横滚命中与行文本）、`probe_ai_edge`（Markdown 块级丢失 / 控制字符透传 /
> 极窄宽度行宽的取证）。探针用来**先看事实再决定改不改**，结论要落到正式测试里。

## 六、验收纪律（从上述 bug 中提炼）

1. **跑批一律走 `tools/run_tests.sh`**，别自己写循环 + grep 判定（见 P9 与 T1）。
2. **不能只信 headless 绿** —— 大功能必须在真实 pty 下复验，断言 `exit=0` 且无 Fatal/Warning（C1、A2、A3 都是 headless 全绿、pty 才暴露的）。
3. **先拆最小可验证样本**，逐个验收，有证据再推进。
3. **压边界**：极小视口（含 1×1）、空文件、超长单行、数百行翻页、CJK、null buffer 时滚轮/键入。
4. **禁止裸跑 `bin/vicecode.php`** —— 曾阻塞读键挂死、未还原 raw mode 花屏。一律走 pty 工具并套 `timeout`。
5. **渲染层 `str_repeat`/切片宽度先 `max(0, …)` 夹紧**。
6. 遇到疑似渲染 bug，**先查第二章的伪失败清单**再动手改产品代码。

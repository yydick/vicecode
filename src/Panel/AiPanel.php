<?php
declare(strict_types=1);

namespace App\Panel;

use App\Ai\ChatModel;
use App\Ai\MarkdownFormatter;
use App\App;
use App\Core\CompletionState;
use App\Core\KeyInput;
use App\Text\DisplayWidth;
use PhpTui\Tui\Display\Area;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\KeyModifiers;
use PhpTui\Term\MouseEventKind;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Widget\Margin;
use PhpTui\Tui\Widget\Widget;

/**
 * AI 对话面板：右上消息流 + 右下输入框（M5 接真实 LLM 流式）。
 *
 * 与其它面板不同，AI 在布局里占**两个**区域（`ai_stream` 与 `ai_input`），
 * 故不套用单一的 content()，而是分别提供 streamContent() / inputContent()；
 * 外层的 Block（边框、标题、聚焦着色）仍由 App::build() 统一加。
 *
 * 面板契约（本项目无 interface，靠下面这组签名保持一致）：
 *   onChar() / onKey() / onClick() / onScroll() 返回 bool 表示是否已消费该事件。
 * 状态归面板自己持有，App 只负责把事件分发给当前聚焦面板。
 *
 * ⚠️ 本面板**不持有对话内容**：消息、流式状态、Provider 全在 `ChatModel` 里
 * （与 GitModel / SearchModel 同构）。这里只负责「把状态画出来」和「把按键翻译成意图」。
 *
 * ⚠️ 渲染必须自己软换行（`DisplayWidth::mbWrapDisp`），不能交给 ParagraphWidget：
 * 它默认走 `LineTruncator`，超宽时是**折行**而非截断，会把后续行整体挤下去
 * （M1 的「幽灵行」）。LLM 回复动辄超宽，这个坑必踩。
 */
final class AiPanel
{
    private string $input = '';

    /** 消息流的纵向滚动（软换行后的行单位） */
    private int $scroll = 0;

    /** 是否贴住底部（新内容自动滚到底）。用户手动上滚后置 false。 */
    private bool $follow = true;

    /** 横向滚动（显示列），横向滚轮调整 */
    private int $hScroll = 0;

    /** 本帧软换行后的总行数（供滚动上界计算与测试断言） */
    private int $lineCount = 0;

    /** 上一帧可见宽度；变化时要重算换行（宽度影响行数） */
    private int $lastWidth = -1;

    /** 上一帧可视高度；scrollBy 靠它判断「是否已滚到底」 */
    private int $lastHeight = 1;

    /** @var string[] prompt 历史（↑/↓ 调取，越靠后越新） */
    private array $history = [];

    /** ↑/↓ 在历史中的位置；-1 = 正在编辑新输入（不在历史里） */
    private int $histIdx = -1;

    /** 尚未提交的编辑草稿：从历史里往上翻时要先存下来，翻回来能恢复 */
    private string $draft = '';

    /** Markdown 逻辑行缓存：键 = theme:md5(content)。定稿消息内容不变，跨帧零重算 */
    private array $mdCache = [];

    /** @var array<string,MarkdownFormatter> 每主题一个解析器（Environment 构造不便宜） */
    private array $mdFormatters = [];

    /** @文件 引用展开用的只读工具（惰性构造） */
    private ?\App\Ai\AiTools $tools = null;

    public function __construct(private App $shell)
    {
    }

    // ── 状态读取（供 App 渲染与测试断言）──────────────

    public function input(): string
    {
        return $this->input;
    }

    public function lineCount(): int
    {
        return $this->lineCount;
    }

    public function scroll(): int
    {
        return $this->scroll;
    }

    public function isFollowing(): bool
    {
        return $this->follow;
    }

    /** @return string[] */
    public function history(): array
    {
        return $this->history;
    }

    private function chat(): ChatModel
    {
        return $this->shell->chat;
    }

    // ── 内容 Widget ──────────────────────────────────

    /**
     * 消息流（ai_stream 区域的内容）。
     *
     * 每次都**重新软换行**而不是缓存：宽度一变行数就变，缓存会和屏幕对不上。
     * 消息总量有对话长度限制，每帧重算的成本可忽略。
     */
    public function streamContent(int $width, int $height): Widget
    {
        $W = max(0, $width);
        $H = max(0, $height);
        $this->lastWidth = $W;
        $this->lastHeight = max(1, $H);

        $lines = $this->buildLines($W);
        $this->lineCount = count($lines);

        // 上界：滚到底时最后一行的行尾正好贴住视口底部
        $maxScroll = max(0, $this->lineCount - $H);
        $this->scroll = $this->follow ? $maxScroll : max(0, min($this->scroll, $maxScroll));

        $visible = array_slice($lines, $this->scroll, $H);
        while (count($visible) < $H) {
            $visible[] = Line::fromSpans(Span::styled('', Style::default()));
        }

        return ParagraphWidget::fromLines(...$visible);
    }

    /**
     * 输入框（ai_input 区域的内容，末位是光标块）。
     *
     * 按显示列宽软换行：输入较长时占满框内 3 行（默认），超长部分向上滚出，
     * 光标块始终贴在末行末位。框高 - 2 个边框 = 可用内容行，
     * 调用方（App::build）已把「框宽 - 2 / 框高 - 2」传进来。
     */
    public function inputContent(int $width, int $height): Widget
    {
        $W = max(1, $width);
        $H = max(1, $height);
        $cursor = '▌';

        // 首行带提示符 '> '，整段按显示列宽软换行（mbWrapDisp 已处理输入里的既有 \n）
        // 净化：粘贴进来的内容同样可能带 ESC/TAB（终端转义注入与制表符错位，同消息流）
        $wrapped = DisplayWidth::mbWrapDisp(DisplayWidth::sanitizeContent('> ' . $this->input), $W);
        if ($wrapped === []) {
            $wrapped = [''];
        }
        // 光标块追加到最后一个可见片段（软换行后的真正末行）
        $wrapped[count($wrapped) - 1] .= $cursor;
        // 只保留最后 $H 行：长输入从顶部滚出
        $wrapped = array_slice($wrapped, -$H);
        while (count($wrapped) < $H) {
            array_unshift($wrapped, '');
        }

        $lines = [];
        foreach ($wrapped as $wl) {
            $lines[] = Line::fromSpans(Span::styled($wl, Style::default()));
        }
        return ParagraphWidget::fromLines(...$lines);
    }

    // ── 工具栏（图标放 ai_input 顶边框，不占输入行）────────
    // 逆向思路：Enter 仍负责「发送」，硬换行（软回车）由工具栏图标插入，
    // 这样既不改 vendor/终端协议（Shift+Enter 在标准 TTY 与 Enter 撞字节、无法区分，
    // 且本项目原则不改 vendor），又契合「后面还要加工具栏」的计划。
    // 图标放在顶边框（右对齐），输入区始终为框高-2（上下边框）= 最少 3 行。
    // 渲染串与点击命中共用 TOOLBAR_BUTTONS 同一份定义，避免两处漂移。
    /** 工具栏图标按钮（图标 + 动作）。顺序即顶边框右对齐的排列顺序。 */
    private const TOOLBAR_BUTTONS = [
        ['icon' => '→', 'action' => 'send'],     // 提交发送
        ['icon' => '↵', 'action' => 'newline'],  // 软回车：插入硬换行
        ['icon' => '✕', 'action' => 'clear'],    // 清空输入
    ];

    /** 顶边框右对齐的图标串（仅串首 1 空格留白，末字符即最右按钮末列），渲染与命中几何共用 */
    public function toolbarTitleString(): string
    {
        $segs = '';
        foreach (self::TOOLBAR_BUTTONS as $b) {
            $segs .= '[' . $b['icon'] . ']';
        }
        return ' ' . $segs;
    }

    /**
     * 各按钮的显示列区间（0 基，相对图标串左沿），点击命中复用。
     * @return array<int,array{0:int,1:int,2:string}>
     */
    private function toolbarRanges(): array
    {
        $ranges = [];
        $x = 1; // 串首 1 空格留白
        foreach (self::TOOLBAR_BUTTONS as $b) {
            $seg = '[' . $b['icon'] . ']';
            $w = DisplayWidth::dispWidth($seg);
            $ranges[] = [$x, $x + $w - 1, $b['action']];
            $x += $w; // 按钮紧贴（seg 已含括号），无额外间隔
        }
        return $ranges;
    }

    /**
     * 纯命中判定：鼠标是否落在顶边框图标区（仅判断，不触发动作）。
     * 供 App::tryStartDrag 在「AI 输入框上」拖拽分隔条与工具栏图标冲突时让位——
     * 工具栏图标正好画在 ai_input 顶边框（= 拖拽手柄行），若不豁免会被拖拽吞掉点击。
     * @param int $col 鼠标绝对列
     * @param int $row 鼠标绝对行
     * @param Area $area ai_input 面板矩形
     */
    public function isToolbarBorderHit(int $col, int $row, Area $area): bool
    {
        if ($row !== $area->position->y) {
            return false; // 只在顶边框行
        }
        $innerW = max(0, $area->width - 2);
        $t = $this->toolbarTitleString();
        $tLen = DisplayWidth::dispWidth($t);
        $startX = $area->position->x + 1 + max(0, $innerW - $tLen); // 右对齐起点（绝对列）
        return $col >= $startX && $col < $startX + $tLen;
    }

    /**
     * 点击落在顶边框图标区：把绝对列换算成图标串内相对列并定位按钮。
     * @param int $col 鼠标绝对列
     * @param Area $area ai_input 面板矩形
     */
    public function onToolbarBorderClick(int $col, Area $area): bool
    {
        $innerW = max(0, $area->width - 2);
        $t = $this->toolbarTitleString();
        $tLen = DisplayWidth::dispWidth($t);
        $startX = $area->position->x + 1 + max(0, $innerW - $tLen); // 右对齐起点（绝对列）
        if ($col < $startX || $col >= $startX + $tLen) {
            return false;
        }
        return $this->onToolbarClick($col - $startX);
    }

    /** 图标串内相对列命中按钮并触发动作（0 基） */
    public function onToolbarClick(int $relX): bool
    {
        foreach ($this->toolbarRanges() as [$s, $e, $action]) {
            if ($relX >= $s && $relX <= $e) {
                return $this->runToolbarAction($action);
            }
        }
        return false;
    }

    private function runToolbarAction(string $action): bool
    {
        switch ($action) {
            case 'send':
                $this->send();
                return true;
            case 'newline':
                $this->input .= "\n"; // 软回车：输入里插入硬换行（Enter 仍用于发送）
                return true;
            case 'clear':
                // 工具栏贴在输入区，「清空」清空输入框文本（对话清理由 Ctrl+L 负责，避免同名词义冲突）
                $this->input = '';
                $this->draft = '';
                $this->histIdx = -1;
                return true;
        }
        return false;
    }

    /**
     * 构造软换行后的「显示行」列表（含样式），并附每条可见行对应的消息下标。
     * map[k] = 该可见行属于第几条消息（-1=非消息：空态提示 / 错误行）。
     * 供 buildLines() 渲染、copyMessageAtRow() 由屏幕行反推消息复用，避免两套换行逻辑漂移。
     *
     * V2：行从「单样式文本」升级为 **span 行**（Markdown 渲染需要行内多样式）；
     * 每行仍是「前缀/缩进 + 内容」结构，悬挂缩进 4 列不变（ai_hscroll 的拼接不变量靠它）。
     *
     * @return array{lines: Line[], map: list<int>}
     */
    private function buildLinesWithMap(int $W): array
    {
        // 先构造「未横滚」的原始行（spans + 所属消息下标），最后统一切片。
        // 这样 hScroll 的上界能在本帧就算出来并生效，不必依赖上一帧的统计。
        /** @var list<array{0:list<array{0:string,1:Style}>,1:int}> $rows */
        $rows = [];
        $chat = $this->chat();
        $theme = $this->shell->theme;

        if ($chat->messages() === []) {
            // 空态给操作提示：Provider/模型怎么切、怎么停。没有这段用户无从发现 Ctrl+P。
            foreach ($this->helpLines() as $t) {
                $this->pushTextRows($rows, 'You: ', $theme->style('aiDim'), $t, $W, -1, $theme);
            }
        } else {
            foreach ($chat->messages() as $i => $m) {
                $role = $m['role'] ?? '';

                // ── 工具结果 → 单行摘要（meta.display），dim 色，一眼看出 AI 做了什么 ──
                if ($role === 'tool') {
                    $text = $m['meta']['display'] ?? null;
                    if (!is_string($text) || $text === '') {
                        // 无 meta（旧存档/异常形状）：退化为 content 首行截断
                        $first = explode("\n", (string) ($m['content'] ?? ''))[0];
                        $text = DisplayWidth::mbCutDisp($first, 60);
                    }
                    $this->pushTextRows($rows, '', $theme->style('aiDim'), '⚙ ' . $text, $W, $i, $theme);
                    continue;
                }

                $isUser = $role === 'user';
                $prefix = $isUser ? 'You: ' : 'AI: ';
                $style = $isUser
                    ? $theme->style('aiUser')
                    : Style::default();
                // 压缩摘要消息（meta.kind=summary）：内容是「总结出来的过去」，淡化避免与真实提问混淆
                if ($isUser && (($m['meta']['kind'] ?? null) === 'summary')) {
                    $style = $theme->style('aiDim');
                }

                $body = (string) ($m['content'] ?? '');
                $hasCalls = isset($m['tool_calls']) && is_array($m['tool_calls']) && $m['tool_calls'] !== [];
                // 最后一条 assistant 消息且正在生成 → 末尾追加光标 + **纯文本路径**
                //（流式期间每 token 全量 parse markdown 会卡帧；定稿后才切 markdown 渲染）
                //（带 tool_calls 的 assistant 是「已收完工具请求」的定稿形态，不加光标）
                $streaming = !$isUser && !$hasCalls && $chat->isStreaming() && $i === count($chat->messages()) - 1;

                if ($body !== '') {
                    if ($streaming) {
                        $this->pushTextRows($rows, $prefix, $style, $body . '▌', $W, $i, $theme);
                    } else {
                        $this->pushMarkdownRows($rows, $prefix, $style, $body, $W, $i, $theme);
                    }
                }

                // ── assistant 发起的工具调用 → 每个调用一行「→ name(args)」，dim 色 ──
                if ($hasCalls) {
                    foreach ($m['tool_calls'] as $tc) {
                        $fn = is_array($tc['function'] ?? null) ? $tc['function'] : [];
                        $name = (string) ($fn['name'] ?? '?');
                        $args = (string) ($fn['arguments'] ?? '');
                        $decoded = json_decode($args, true);
                        if (is_array($decoded)) {
                            $parts = [];
                            foreach ($decoded as $k => $v) {
                                $parts[] = $k . '=' . (is_scalar($v) ? (string) $v : '…');
                            }
                            $argStr = implode(', ', $parts);
                        } else {
                            $argStr = $args;
                        }
                        $argStr = DisplayWidth::mbCutDisp($argStr, 48);
                        $this->pushTextRows($rows, '    ', $theme->style('aiDim'), '→ ' . $name . '(' . $argStr . ')', $W, $i, $theme);
                    }
                }
            }

            if ($chat->error() !== null) {
                $this->pushTextRows($rows, '', $theme->style('aiErr'), '! ' . $chat->error(), $W, -1, $theme);
            }
        }

        // ⚠️ hScroll 必须钳到「最宽行 - 视口宽」：消息行是先软换行再渲染的，
        // 行宽**本来就不超过 $W**，横滚不会露出任何新内容，只会把左侧切掉、右侧留白；
        // 没有上界时一路滚下去整片空白（内容像丢了），且要反向滚很多次才回得来。
        // 上界通常为 0（= 不可横滚），只有退化到极窄视口出现超宽行时才允许滚一点。
        $maxW = 0;
        foreach ($rows as [$spans, ]) {
            $w = 0;
            foreach ($spans as [$t, ]) {
                $w += DisplayWidth::dispWidth($t);
            }
            $maxW = max($maxW, $w);
        }
        $this->hScroll = max(0, min($this->hScroll, max(0, $maxW - $W)));

        $lines = [];
        $map = [];
        foreach ($rows as [$spans, $msgIdx]) {
            $spans = $this->sliceRow($spans, $this->hScroll, $W);
            if ($spans === []) {
                $spans = [['', Style::default()]];
            }
            $lineSpans = [];
            foreach ($spans as [$t, $s]) {
                $lineSpans[] = Span::styled($t, $s);
            }
            $lines[] = Line::fromSpans(...$lineSpans);
            $map[] = $msgIdx;
        }
        return ['lines' => $lines, 'map' => $map];
    }

    /**
     * 纯文本行入列（工具摘要/错误行/流式中的消息）：按前缀+悬挂缩进的旧语义软换行。
     * @param list<array{0:list<array{0:string,1:Style}>,1:int}> $rows
     */
    private function pushTextRows(array &$rows, string $prefix, Style $style, string $text, int $W, int $msgIdx, \App\Core\Theme $theme): void
    {
        // 模型回复 / @文件 / 工具读到的文件内容都是不可信输入：先展开 TAB、剔除控制字符
        // （终端转义注入面 + TAB 会按 8 列制表位展开导致错位，见 DisplayWidth::sanitizeContent）
        $text = DisplayWidth::sanitizeContent($text);
        // ⚠️ 前缀不能吃掉整行：极窄面板下（W < 前缀宽）原先会产出「前缀 + 正文」远超 W 的行，
        // php-tui 的 LineTruncator 超宽会**折行**，把后面所有行挤下去（幽灵行）。
        // 这里给正文留至少 2 列（2 列宽的字素也放得下），前缀按需截断。
        $indW = min(mb_strwidth($prefix), max(0, $W - 2));
        $prefix = DisplayWidth::mbCutDisp($prefix, $indW);
        $indent = str_repeat(' ', $indW);
        $bodyW = max(1, $W - $indW);
        // ⚠️ 正文要按「扣除缩进后的宽度」折行：续行会再加上 $indent，
        // 若按整宽 $W 折，续行就是 $W + 缩进宽 → 超出面板（B9：长消息末尾少字符）。
        $first = true;
        foreach (DisplayWidth::mbWrapDisp($text, $bodyW) as $wl) {
            $spans = [];
            if ($first && $prefix !== '') {
                $spans[] = [$prefix, $style];
            } elseif (!$first || $prefix === '') {
                $spans[] = [$first ? '' : $indent, Style::default()];
            }
            if ($wl !== '' || $spans === []) {
                $spans[] = [$wl, $style];
            }
            $rows[] = [$spans, $msgIdx];
            $first = false;
        }
        if ($first) {
            $rows[] = [[[ $prefix !== '' ? $prefix : $indent, $style]], $msgIdx]; // 空文本防丢行
        }
    }

    /**
     * Markdown 消息入列：逻辑行（MarkdownFormatter 产出）→ 前缀/缩进 + span 感知软换行。
     * 首物理行带前缀；同消息的其余行（含换行产生的续行）一律同宽缩进对齐——
     * 「拼回去等于原文」的拼接不变量在 ai_hscroll 里钉死，别动这里的结构。
     * 前缀宽度会按面板宽夹紧（极窄视口下截断，见函数内注释）。
     * @param list<array{0:list<array{0:string,1:Style}>,1:int}> $rows
     */
    private function pushMarkdownRows(array &$rows, string $prefix, Style $pStyle, string $markdown, int $W, int $msgIdx, \App\Core\Theme $theme): void
    {
        // 同 pushTextRows：Markdown 也可能来自模型或 @文件（不可信输入），先净化。
        // 保留 \n（块结构靠它），只展开 TAB 并剔除其它控制字符；缓存键基于净化后的文本。
        $logical = $this->markdownLines(DisplayWidth::sanitizeContent($markdown), $theme);
        // 前缀同样不能吃掉整行（理由见 pushTextRows）
        $indW = min(mb_strwidth($prefix), max(0, $W - 2));
        $prefix = DisplayWidth::mbCutDisp($prefix, $indW);
        $indent = str_repeat(' ', $indW);
        $flowW = max(1, $W - $indW);
        $first = true;
        foreach ($logical as $spans) {
            // ⚠️ 前缀/缩进**不进折行流**（与 pushTextRows 同款）：若把前缀拼进 flow 再交给
            // spanWrapDisp，当前缀宽于流宽（极窄面板：indW > flowW）时它会被**从中间劈开**
            // （实测 W=6 时渲染成「AI」「:」两行），且首行能放的内容反而更少。
            // 放在流外则每行宽度恒为 indW + 内容(≤ flowW) ≤ W，前缀也永远完整。
            foreach (DisplayWidth::spanWrapDisp($spans, $flowW) as $k => $phys) {
                $isLead = $first && $k === 0;
                $pre = $isLead ? [$prefix, $pStyle] : [$indent, Style::default()];
                $rows[] = [[$pre, ...$phys], $msgIdx];
            }
            $first = false;
        }
    }

    /**
     * Markdown → 逻辑行（带缓存：hash+theme 定键，逻辑行与视口宽无关所以不进键）。
     * @return list<list<array{0:string,1:Style}>>
     */
    private function markdownLines(string $markdown, \App\Core\Theme $theme): array
    {
        $hash = md5($markdown);
        $key = $theme->id . ':' . $hash;
        if (isset($this->mdCache[$key])) {
            return $this->mdCache[$key];
        }
        if (count($this->mdCache) > 128) {
            $this->mdCache = []; // 粗暴防膨胀：正常对话规模到不了，防御异常输入
        }
        if (!isset($this->mdFormatters[$theme->id])) {
            $this->mdFormatters[$theme->id] = new MarkdownFormatter($theme);
        }
        return $this->mdCache[$key] = $this->mdFormatters[$theme->id]->format($markdown);
    }

    /**
     * span 行横滚切片（跳过 $skip 列再取 $w 列）：样式跟随 span，不整行变单色。
     * @param list<array{0:string,1:Style}> $spans
     * @return list<array{0:string,1:Style}>
     */
    private function sliceRow(array $spans, int $skip, int $w): array
    {
        if ($skip <= 0) {
            return $spans;
        }
        $out = [];
        $outW = 0;
        $x = 0;
        foreach ($spans as [$text, $style]) {
            if ($outW >= $w) {
                break;
            }
            $tw = DisplayWidth::dispWidth($text);
            $inner = max(0, $skip - $x);
            if ($inner < $tw) {
                $piece = DisplayWidth::mbSubDisp($text, $inner, $w - $outW);
                if ($piece !== '') {
                    $out[] = [$piece, $style];
                    $outW += DisplayWidth::dispWidth($piece);
                }
            }
            $x += $tw;
        }
        return $out;
    }

    /**
     * 构造软换行后的「显示行」列表（含样式）。
     *
     * @return Line[]
     */
    private function buildLines(int $W): array
    {
        return $this->buildLinesWithMap($W)['lines'];
    }

    /**
     * 点击 AI 消息流某行 → 整条复制该消息正文到剪贴板（不拖拽选区，符合「整条消息复制」）。
     * @return bool 是否命中有消息的行（命中则已复制并提示）
     */
    public function copyMessageAtRow(int $row, Area $area): bool
    {
        $inner = $area->inner(new Margin(1, 1));
        $v = $row - $inner->position->y; // 可见行下标（0=消息流首行）
        if ($v < 0) {
            return false;
        }
        $W = max(1, $inner->width);
        $map = $this->buildLinesWithMap($W)['map'];
        // ⚠️ map 的下标是**全量行**（软换行后的完整列表），不是可见行：
        // 消息流滚动后可见第 v 行对应全量第 scroll+v 行。漏掉 scroll 会复制顶部那条消息
        // （滚到中间点「第 50 条」实际复制第 1 条），且未滚动时一切正常，极难发现。
        $abs = $this->scroll + $v;
        if (!isset($map[$abs]) || $map[$abs] < 0) {
            return false;
        }
        $msgs = $this->chat()->messages();
        $idx = $map[$abs];
        if (!isset($msgs[$idx])) {
            return false;
        }
        $m = $msgs[$idx];
        $text = (string) ($m['content'] ?? '');
        if (($m['role'] ?? null) === 'tool') {
            // 工具结果行：复制摘要而非正文（正文可能是几万字节的文件内容，
            // 「整条消息复制」的意图是拿到可粘贴的引用，不是倾倒整个文件）
            $text = is_string($m['meta']['display'] ?? null) && $m['meta']['display'] !== ''
                ? $m['meta']['display']
                : explode("\n", $text)[0];
        } elseif (isset($m['tool_calls']) && is_array($m['tool_calls'])) {
            // 发起工具调用的 assistant：正文 + 每个调用的摘要
            $parts = [trim($text)];
            foreach ($m['tool_calls'] as $tc) {
                $fn = is_array($tc['function'] ?? null) ? $tc['function'] : [];
                $parts[] = '→ ' . (string) ($fn['name'] ?? '?') . '(' . (string) ($fn['arguments'] ?? '') . ')';
            }
            $text = implode("\n", array_filter($parts, static fn($p) => $p !== ''));
        }
        $this->shell->clipboardCopy($text);
        $this->shell->setMessage($this->shell->t('status.copied_msg'));
        return true;
    }

    /** @return string[] */
    private function helpLines(): array
    {
        $spec = $this->chat()->spec();
        if ($spec === null) {
            return [
                $this->shell->t('ai.no_provider'),
                $this->shell->t('ai.clear_hint'),
            ];
        }
        // 当前模型能力（声明见 config/providers.php）：让用户一眼看出「这个模型能不能用工具」，
        // 而不是等到 Agent 永不触发时才来猜。空列表显式说明「未声明任何能力」。
        $caps = $spec->capabilities === []
            ? $this->shell->t('ai.caps_none')
            : implode(' · ', array_map(fn(string $c): string => $this->capLabel($c), $spec->capabilities));
        return [
            $spec->label . ' / ' . $spec->model,
            $this->shell->t('ai.caps') . ': ' . $caps,
            $this->shell->t('ai.clear_hint'),
        ];
    }

    /**
     * 能力标识 → 展示文案：已知能力走 i18n（`cap.tools` 等），配置里写的新能力名按原文显示
     * （`Translator::t()` 缺 key 时原样返回 key，正好用来判断）。
     */
    private function capLabel(string $cap): string
    {
        $key = 'cap.' . $cap;
        $txt = $this->shell->t($key);
        return $txt === $key ? $cap : $txt;
    }

    // ── 事件 ────────────────────────────────────────

    /** 输入框聚焦时的按键：回车发送、退格删除、可打印字符入 buffer。 */
    public function onChar(CharKeyEvent $e): bool
    {
        $ctrl = ($e->modifiers & KeyModifiers::CONTROL) !== 0;

        // Agent 工具确认态：y 放行 / n 拒绝；其它键一律吞掉（此时不该往输入框打字）
        if ($this->chat()->hasPendingApproval()) {
            $ch = strtolower($e->char);
            if ($ch === 'y') {
                $this->chat()->approve();
                return true;
            }
            if ($ch === 'n') {
                $this->chat()->deny();
                return true;
            }
            return true;
        }

        // Ctrl+L 清空对话（与终端清屏同键）
        if ($ctrl && strtolower($e->char) === 'l') {
            $this->clearConversation();
            return true;
        }
        // Ctrl+P 切 Provider / Ctrl+N 切模型（Ctrl+M 不能用 —— 它就是回车 0x0D）
        if ($ctrl && strtolower($e->char) === 'p') {
            $this->chat()->cycleProvider();
            return true;
        }
        if ($ctrl && strtolower($e->char) === 'n') {
            $this->chat()->cycleModel();
            return true;
        }
        // Ctrl+R 循环切换模型策略（在 AI 面板内，所以不会抢终端捕获态的 ^R 反向搜索）
        if ($ctrl && strtolower($e->char) === 'r') {
            $this->chat()->cycleStrategy();
            return true;
        }

        if ($e->char === "\r" || $e->char === "\n") {
            $this->send();
            return true;
        }
        if ($e->char === "\x7f" || $e->char === "\x08") {
            $this->backspace();
            return true;
        }
        if (KeyInput::isPrintable($e->char) && !$ctrl) {
            $this->input .= $e->char;
            return true;
        }
        return false;
    }

    /**
     * 导航/编辑键（AI 焦点时由 App 分发过来）。
     * 返回 false 表示不消费，交给 App 的全局逻辑（Esc 退出等）。
     */
    public function onKey(CodedKeyEvent $e, array $a): bool
    {
        switch ($e->code) {
            case KeyCode::Enter:
                // ⚠️ 真实终端里回车是 **CodedKeyEvent(Enter)**，不是 CharKeyEvent("\r")。
                // 只在 onChar 里挂 "\r" 的话，headless 单测全绿但 pty 下按回车没反应
                // （M2 的终端面板踩过一模一样的坑，见 docs/BUGFIXES.md 的 A2）。
                // 两条路径都要能发送。
                $this->send();
                return true;
            case KeyCode::Backspace:
                $this->backspace();
                return true;
            case KeyCode::Up:
                $this->recallHistory(1);
                return true;
            case KeyCode::Down:
                $this->recallHistory(-1);
                return true;
            case KeyCode::PageUp:
                $this->scrollBy(-max(1, $this->viewportH($a) - 1));
                return true;
            case KeyCode::PageDown:
                $this->scrollBy(max(1, $this->viewportH($a) - 1));
                return true;
            case KeyCode::Esc:
                // 确认态下 Esc = 取消确认（不继续 Agent loop，也不触发全局退出）
                if ($this->chat()->hasPendingApproval()) {
                    $this->chat()->dismissApproval();
                    return true;
                }
                // 生成中 → 停止；否则不消费，让 App 走全局「Esc 退出」
                if ($this->chat()->isStreaming()) {
                    $this->chat()->cancel();
                    return true;
                }
                return false;
        }
        return false;
    }

    /** 删除输入框末字符。退格有两条路径（CharKeyEvent "\x7f" 与 CodedKeyEvent Backspace），故单独成方法。 */
    public function backspace(): void
    {
        $this->input = mb_substr($this->input, 0, -1);
    }

    /** 粘贴文本到 AI 输入框（追加到末尾；该输入框无独立光标位，追加即粘贴位置）。 */
    public function insertText(string $text): void
    {
        if ($text === '') {
            return;
        }
        $this->input .= $text;
        $this->histIdx = -1;
    }

    /**
     * 缩进（Tab）：末尾追加 `CompletionState::INDENT_SPACES` 个空格。
     *
     * 这个输入框**没有光标位**（只能末尾编辑），所以"在光标处缩进"就是"在末尾追加"。
     * 手打多行 prompt 或粘贴代码后用得上。
     */
    public function insertIndent(): void
    {
        $this->input .= str_repeat(' ', CompletionState::INDENT_SPACES);
        $this->histIdx = -1;
    }

    /**
     * 反向缩进（Shift+Tab）：末尾最多删 `INDENT_SPACES` 个**连续空格**。
     *
     * 只在末尾确实是一段空格时才动作 —— 否则会把用户刚打进去的字吃掉。
     * @return bool 是否真的改了（没改则上层不必重绘、也不吞这个键）
     */
    public function outdentTail(): bool
    {
        $len = mb_strlen($this->input);
        $n = 0;
        while ($n < CompletionState::INDENT_SPACES && $n < $len
            && mb_substr($this->input, $len - $n - 1, 1) === ' ') {
            $n++;
        }
        if ($n === 0) {
            return false;
        }
        $this->input = mb_substr($this->input, 0, $len - $n);
        $this->histIdx = -1;
        return true;
    }

    /** 补全接受：把末尾从 $start 起的那段替换成 $insert（无光标位，故只可能替换到末尾）。 */
    public function replaceTail(int $start, string $insert): void
    {
        $this->input = mb_substr($this->input, 0, max(0, $start)) . $insert;
        $this->histIdx = -1;
    }

    /** 纵向滚动（滚轮）；滚回底部自动恢复 follow */
    public function onScroll(MouseEventKind $kind): void
    {
        $step = $kind === MouseEventKind::ScrollDown ? 3 : -3;
        $this->scrollBy($step);
    }

    public function scrollBy(int $delta): void
    {
        $this->scroll = max(0, $this->scroll + $delta);
        // 只有「滚到底」才恢复跟随；往上滚一律脱离（否则新 token 会把用户拽回底部）
        $this->follow = $this->scroll >= max(0, $this->lineCount - $this->lastHeight);
    }

    public function onScrollH(int $delta): void
    {
        $this->hScroll = max(0, $this->hScroll + $delta);
    }

    private function resetScroll(): void
    {
        $this->scroll = 0;
        $this->follow = true;
    }

    /** 从 App::areas() 取 AI 消息流的可视高度（拿不到时给个安全值） */
    private function viewportH(array $a): int
    {
        $area = $a['ai_stream'] ?? null;
        if ($area === null) {
            return 1;
        }
        return max(1, $area->height - 2); // 减去上下边框
    }

    // ── 发送 ────────────────────────────────────────

    /**
     * 发送输入框内容（Enter 与快捷动作都走这里）。
     *
     * @param string|null $kind 快捷动作传入的任务类型；null = 由输入文本自己决定
     *                          （`/前缀` 解析，见 resolveKind）
     */
    private function send(?string $kind = null): void
    {
        $text = trim($this->input);
        if ($text === '') {
            return;
        }
        // 指令前缀（`/plan 把这块重构一下`）：kind 只在最前面出现一次，**不发给模型**
        [$kind, $text] = $this->resolveKind($text, $kind);
        if ($text === '') {
            return;
        }
        $warnings = [];
        $expanded = $this->expandAtRefs($text, $warnings);
        $this->history[] = $text;
        $this->histIdx = -1;
        $this->draft = '';
        $this->input = '';
        $this->resetScroll(); // 新对话从顶部开始看
        $this->chat()->send($expanded, $kind);
        // ⚠️ 缺失引用提示要在 send 之后汇总覆盖：send 可能再设提示（缺 key/生成中），
        // 先设的会被覆盖，用户就看不到「哪个 @ 引用被丢了」（实测踩过）
        if ($warnings !== []) {
            $this->shell->setMessage(implode('；', $warnings));
        }
    }

    /**
     * 解析输入开头的**指令前缀**，得出本条的 kind。
     *
     * 规则（刻意不猜）：
     *  - `$kind` 已由调用方给出（快捷动作）→ 直接用，不看文本；
     *  - 文本以 `/<已知类型>` 开头 → 该类型，并把前缀从文本里去掉（**前缀不发给模型**）；
     *  - 文本以 `/<未知词>` 开头 → **拒绝发送**并列出已知类型。用户在输入框打斜杠显然是想下指令，
     *    把它当普通消息发出去（还带着斜杠）是更差的结果；
     *  - 想发字面量开头的斜杠：写两个（`//plan`）→ 还原成一个（与命令行转义同理）。
     *
     * @return array{0:string|null,1:string} [kind, 去前缀后的文本]
     */
    private function resolveKind(string $text, ?string $kind): array
    {
        if ($kind !== null) {
            return [$kind, $text];
        }
        if (!str_starts_with($text, '/')) {
            return [null, $text];
        }
        if (str_starts_with($text, '//')) {
            return [null, substr($text, 1)];      // 转义：`//plan` → 字面量 `/plan`
        }
        if (!preg_match('#^/([A-Za-z0-9_-]+)\s*(.*)$#s', $text, $m)) {
            return [null, $text];                 // 光一个 `/`：当普通消息
        }
        $name = strtolower($m[1]);
        $rest = $m[2];
        $known = $this->chat()->knownKinds();
        if (in_array($name, $known, true)) {
            return [$name, $rest];
        }
        // 未知类型：拒绝发送 + 说清已知哪些（不静默降级成普通消息）
        $this->shell->setMessage($this->shell->t('ai.kind_unknown', [
            'kind'  => $name,
            'known' => implode('/', $known),
        ]));
        return [null, ''];
    }

    /**
     * 展开输入里的 @文件 引用（V2 代码上下文）。
     *
     * @token 保留在正文里（用户消息所见即所得），每个引用的文件内容以围栏代码块
     * 追加到消息末尾——markdown 渲染后自带高亮，模型拿到的是纯文本代码。
     * 路径走 AiTools::readCore 同一套安全校验（realpath + 项目根前缀，../ / 绝对路径 /
     * symlink 出根 / 二进制全部拒绝），per-file 上限 = vicerc ai.attachMaxBytes。
     * 引用失败不阻断发送：状态栏提示并丢弃该引用。
     */
    private function expandAtRefs(string $text, array &$warnings): string
    {
        if (!str_contains($text, '@')) {
            return $text;
        }
        if (!preg_match_all('/(?:^|\s)@([^\s@]+)/u', $text, $ms, PREG_SET_ORDER)) {
            return $text;
        }
        $notes = '';
        $seen = [];
        foreach ($ms as $m) {
            $ref = rtrim($m[1], '.,;:!?）)】」"\''); // 尾部标点不算路径（@src/Foo.php, 很常见）
            if ($ref === '' || isset($seen[$ref])) {
                continue;
            }
            $seen[$ref] = true;
            $core = $this->tools()->readCore($ref, \App\Core\ConfigStore::aiAttachMaxBytes());
            if (!is_array($core)) {
                $warnings[] = $this->shell->t('ai.attach_missing', ['name' => $ref]);
                continue;
            }
            $lang = \App\Editor\Highlighter::langFor($ref) ?? '';
            $content = $core['content'];
            if ($core['truncated']) {
                $content .= "\n（已截断到 {$core['limit']} 字节）";
            }
            $notes .= "\n\n（引用文件 {$ref}：）\n```{$lang}\n" . rtrim($content, "\n") . "\n```";
        }
        return $notes === '' ? $text : rtrim($text) . $notes;
    }

    /** AiTools 惰性构造（root=项目根；与 ChatModel 的工具同一套路径安全语义） */
    private function tools(): \App\Ai\AiTools
    {
        return $this->tools ??= new \App\Ai\AiTools();
    }

    /** 清空对话（Ctrl+L 与菜单共用）：模型清空 + markdown 缓存清 + 滚动复位 */
    public function clearConversation(): void
    {
        $this->chat()->clear();
        $this->mdCache = [];
        $this->resetScroll();
    }

    /** 快捷动作发送前的输入区复位（清草稿/历史游标，滚动贴底准备看新回复） */
    public function resetForPrompt(): void
    {
        $this->input = '';
        $this->draft = '';
        $this->histIdx = -1;
        $this->resetScroll();
    }

    /** 外部直接发送（AI 快捷动作入口）：走与手动发送同一套 @展开/历史流程 */
    /**
     * 直接发送一段文本（快捷动作走这里）：**带 kind**，让"按任务类型自动选档"能用上。
     */
    public function sendPrompt(string $text, ?string $kind = null): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }
        $this->input = $text;
        $this->send($kind);
    }

    /**
     * ↑/↓ 调取 prompt 历史。
     * dir=1 往上（更旧），dir=-1 往下（更新）；到边界回到草稿/空输入。
     */
    private function recallHistory(int $dir): void
    {
        if ($this->history === []) {
            return;
        }
        if ($this->histIdx === -1) {
            if ($dir === -1) {
                return; // 已经在编辑新输入了，往下没有更早的内容
            }
            $this->draft = $this->input; // 先把当前草稿存起来
            $this->histIdx = count($this->history) - 1;
        } else {
            $next = $this->histIdx - $dir; // ↑ 让索引变小（更旧）
            if ($next < 0) {
                // 翻过了最旧的一条：回到顶部（保持在最旧那条）
                return;
            }
            if ($next >= count($this->history)) {
                // 翻回底部：恢复草稿
                $this->histIdx = -1;
                $this->input = $this->draft;
                return;
            }
            $this->histIdx = $next;
        }
        $this->input = $this->history[$this->histIdx] ?? '';
    }
}

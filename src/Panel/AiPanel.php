<?php
declare(strict_types=1);

namespace App\Panel;

use App\Ai\ChatModel;
use App\App;
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
        $wrapped = DisplayWidth::mbWrapDisp('> ' . $this->input, $W);
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
     * @return array{lines: Line[], map: list<int>}
     */
    private function buildLinesWithMap(int $W): array
    {
        $lines = [];
        $map = [];
        $chat = $this->chat();

        if ($chat->messages() === []) {
            // 空态给操作提示：Provider/模型怎么切、怎么停。没有这段用户无从发现 Ctrl+P。
            foreach ($this->helpLines() as $t) {
                $lines[] = Line::fromSpans(Span::styled(
                    DisplayWidth::mbSubDisp($t, $this->hScroll, $W),
                    $this->shell->theme->style('aiDim'),
                ));
                $map[] = -1;
            }
            return ['lines' => $lines, 'map' => $map];
        }

        foreach ($chat->messages() as $i => $m) {
            $isUser = $m['role'] === 'user';
            $prefix = $isUser ? 'You: ' : 'AI: ';
            $style = $isUser
                ? $this->shell->theme->style('aiUser')
                : Style::default();

            $body = $m['content'];
            // 最后一条 assistant 消息且正在生成 → 末尾追加光标，让人看出还在出字
            $streaming = !$isUser && $chat->isStreaming() && $i === count($chat->messages()) - 1;
            if ($streaming) {
                $body .= '▌';
            }

            $indent = str_repeat(' ', mb_strlen($prefix));
            $first = true;
            foreach (DisplayWidth::mbWrapDisp($prefix . $body, max(1, $W)) as $wl) {
                // 续行要缩进对齐首行的正文起点，否则换行后看起来像新的一条消息
                $text = $first ? $wl : $indent . $wl;
                $first = false;
                $lines[] = Line::fromSpans(Span::styled(
                    DisplayWidth::mbSubDisp($text, $this->hScroll, $W),
                    $style,
                ));
                $map[] = $i;
            }
        }

        if ($chat->error() !== null) {
            foreach (DisplayWidth::mbWrapDisp('! ' . $chat->error(), max(1, $W)) as $wl) {
                $lines[] = Line::fromSpans(Span::styled(
                    DisplayWidth::mbSubDisp($wl, $this->hScroll, $W),
                    $this->shell->theme->style('aiErr'),
                ));
                $map[] = -1;
            }
        }

        return ['lines' => $lines, 'map' => $map];
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
        $text = $msgs[$idx]['content'] ?? '';
        $this->shell->clipboardCopy($text);
        $this->shell->setMessage($this->shell->t('status.copied_msg'));
        return true;
    }

    /** @return string[] */
    private function helpLines(): array
    {
        $spec = $this->chat()->spec();
        return [
            $spec === null ? $this->shell->t('ai.no_provider') : $spec->label . ' / ' . $spec->model,
            $this->shell->t('ai.clear_hint'),
        ];
    }

    // ── 事件 ────────────────────────────────────────

    /** 输入框聚焦时的按键：回车发送、退格删除、可打印字符入 buffer。 */
    public function onChar(CharKeyEvent $e): bool
    {
        $ctrl = ($e->modifiers & KeyModifiers::CONTROL) !== 0;

        // Ctrl+L 清空对话（与终端清屏同键）
        if ($ctrl && strtolower($e->char) === 'l') {
            $this->chat()->clear();
            $this->resetScroll();
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
                // （M2 的终端面板踩过一模一样的坑，见 project_php_tui_facts.md）。
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

    private function send(): void
    {
        $text = trim($this->input);
        if ($text === '') {
            return;
        }
        $this->history[] = $text;
        $this->histIdx = -1;
        $this->draft = '';
        $this->input = '';
        $this->resetScroll(); // 新对话从顶部开始看
        $this->chat()->send($text);
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

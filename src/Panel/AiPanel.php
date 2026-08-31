<?php
declare(strict_types=1);

namespace App\Panel;

use App\Ai\ChatModel;
use App\App;
use App\Core\KeyInput;
use App\Text\DisplayWidth;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\KeyModifiers;
use PhpTui\Term\MouseEventKind;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Span;
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

    /** 输入框（ai_input 区域的内容，末位是光标块） */
    public function inputContent(): Widget
    {
        $cursor = '▌';
        $text = '> ' . $this->input . $cursor;
        return ParagraphWidget::fromString($text);
    }

    /**
     * 构造软换行后的「显示行」列表（含样式）。
     *
     * @return Line[]
     */
    private function buildLines(int $W): array
    {
        $lines = [];
        $chat = $this->chat();

        if ($chat->messages() === []) {
            // 空态给操作提示：Provider/模型怎么切、怎么停。没有这段用户无从发现 Ctrl+P。
            foreach ($this->helpLines() as $t) {
                $lines[] = Line::fromSpans(Span::styled(
                    DisplayWidth::mbSubDisp($t, $this->hScroll, $W),
                    Style::default()->fg(\PhpTui\Tui\Color\AnsiColor::DarkGray),
                ));
            }
            return $lines;
        }

        foreach ($chat->messages() as $i => $m) {
            $isUser = $m['role'] === 'user';
            $prefix = $isUser ? 'You: ' : 'AI: ';
            $style = $isUser
                ? Style::default()->fg(\PhpTui\Tui\Color\AnsiColor::Cyan)
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
            }
        }

        if ($chat->error() !== null) {
            foreach (DisplayWidth::mbWrapDisp('! ' . $chat->error(), max(1, $W)) as $wl) {
                $lines[] = Line::fromSpans(Span::styled(
                    DisplayWidth::mbSubDisp($wl, $this->hScroll, $W),
                    Style::default()->fg(\PhpTui\Tui\Color\AnsiColor::Red),
                ));
            }
        }

        return $lines;
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

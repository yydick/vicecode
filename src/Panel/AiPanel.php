<?php
declare(strict_types=1);

namespace App\Panel;

use App\App;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\KeyModifiers;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Widget\Widget;

/**
 * AI 对话面板：右上消息流 + 右下输入框（M0 占位，M5 接真实 LLM 流式）。
 *
 * 与其它面板不同，AI 在布局里占**两个**区域（`ai_stream` 与 `ai_input`），
 * 故不套用单一的 content()，而是分别提供 streamContent() / inputContent()；
 * 外层的 Block（边框、标题、聚焦着色）仍由 App::build() 统一加。
 *
 * 面板契约（本项目无 interface，靠下面这组签名保持一致）：
 *   onChar() / onKey() / onClick() / onScroll() 返回 bool 表示是否已消费该事件。
 * 状态归面板自己持有，App 只负责把事件分发给当前聚焦面板。
 */
final class AiPanel
{
    /** @var string[] */
    private array $messages = [];

    private string $input = '';

    public function __construct(private App $shell)
    {
        $this->messages = ['AI: ' . $shell->t('app.title') . '（M0 占位，M5 接真实 LLM）。'];
    }

    // ── 状态读取（供 App 渲染与测试断言）──────────────

    /** @return string[] */
    public function messages(): array
    {
        return $this->messages;
    }

    public function input(): string
    {
        return $this->input;
    }

    // ── 内容 Widget ──────────────────────────────────

    /** 消息流（ai_stream 区域的内容） */
    public function streamContent(): Widget
    {
        return ParagraphWidget::fromString(implode("\n", $this->messages));
    }

    /** 输入框（ai_input 区域的内容，末位是光标块） */
    public function inputContent(): Widget
    {
        return ParagraphWidget::fromString('> ' . $this->input . '▌');
    }

    // ── 事件 ────────────────────────────────────────

    /** 输入框聚焦时的按键：回车发送、退格删除、可打印字符入 buffer。 */
    public function onChar(CharKeyEvent $e): bool
    {
        if ($e->char === "\r" || $e->char === "\n") {
            $this->send();
            return true;
        }
        if ($e->char === "\x7f" || $e->char === "\x08") {
            $this->backspace();
            return true;
        }
        if (strlen($e->char) === 1 && ord($e->char) >= 32 && !($e->modifiers & KeyModifiers::CONTROL)) {
            $this->input .= $e->char;
            return true;
        }
        return false;
    }

    /** 删除输入框末字符。退格有两条路径（CharKeyEvent "\x7f" 与 CodedKeyEvent Backspace），故单独成方法。 */
    public function backspace(): void
    {
        $this->input = substr($this->input, 0, -1);
    }

    /** 发送：消息入流，清空输入框。M5 会在这里换成真正的 Provider 调用。 */
    private function send(): void
    {
        $text = trim($this->input);
        if ($text === '') {
            return;
        }
        $this->messages[] = 'You: ' . $text;
        $this->messages[] = 'AI: (' . $this->shell->t('panel.ai_chat') . ' M5)';
        $this->input = '';
    }
}

<?php
declare(strict_types=1);

namespace App\Panel;

use App\App;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Widget\Widget;

/**
 * 底部状态栏：焦点 / 当前 tab / 文件与 dirty 标记 / 语言 / 瞬时消息 / 退出热键提示。
 *
 * 未保存确认进行中时整条改为确认提示（由 Lifecycle 驱动）。
 * M6 要补「当前 Provider + 模型」「编辑模式」等信息时，加在这里即可。
 */
final class StatusBarPanel
{
    public function __construct(private App $shell)
    {
    }

    public function content(): Widget
    {
        return ParagraphWidget::fromString($this->text());
    }

    private function text(): string
    {
        // 未保存确认进行中：整条状态栏改为确认提示
        if ($this->shell->confirm !== null) {
            $kind = $this->shell->confirm['kind'] ?? 'quit';
            if ($kind === 'discard') {
                return ' ' . $this->shell->t('confirm.discard', ['path' => $this->shell->confirm['path'] ?? '']);
            }
            return ' ' . ($kind === 'close'
                ? $this->shell->t('confirm.close_dirty')
                : $this->shell->t('confirm.quit_dirty'));
        }

        $buf = $this->shell->buffer;
        $file = $buf !== null ? basename((string) $buf->path) : '—';
        $dirty = $buf !== null && $buf->dirty ? ' ' . $this->shell->t('status.dirty') : '';

        return ' ' . $this->shell->t('app.title')
            . ' · ' . $this->shell->t('status.focus') . '=' . strtoupper($this->shell->focusPanel())
            . ' · ' . $this->shell->t('status.tab') . '=' . $this->shell->sidebar->tabLabel()
            . ' · ' . $this->shell->t('status.branch') . '=' . $this->shell->git->branch
            . ' · ' . $this->shell->t('status.file') . '=' . $file . $dirty
            . ' · ' . $this->shell->t('status.locale') . '=' . $this->shell->locale()
            . ' · ' . $this->shell->message
            . ' · ' . $this->shell->t('status.quit');
    }
}

<?php
declare(strict_types=1);

namespace App\Core;

use App\App;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\KeyModifiers;

/**
 * 退出与未保存确认状态机（R8）。
 *
 * 负责：退出前的未保存确认、关闭 dirty buffer 前的确认、以及真正的收尾退出。
 * 确认状态存在 App::$confirm（测试会直接读它），本类只负责推进状态机。
 *
 * 「退出时停掉还在跑的子进程」用构造注入的 $onShutdown 回调完成，
 * 这样本类不必知道终端面板的存在，也避免构造期的循环依赖。
 */
final class Lifecycle
{
    public function __construct(
        private App $shell,
        private ?\Closure $onShutdown = null,
    ) {
    }

    /** 请求退出：有未保存改动先弹确认 */
    public function requestQuit(): void
    {
        if ($this->anyDirty()) {
            $this->shell->confirm = ['kind' => 'quit'];
            return;
        }
        $this->quit();
    }

    /** 请求关闭某个 buffer：dirty 时先弹确认 */
    public function requestClose(string $path): void
    {
        if (!$this->shell->editor->hasBuffer($path)) {
            return;
        }
        if ($this->shell->editor->buffers()[$path]->dirty) {
            $this->shell->confirm = ['kind' => 'close', 'path' => $path];
            return;
        }
        $this->close($path);
    }

    /** 请求丢弃某文件工作区改动（不可逆）：先弹 y/n 确认 */
    public function requestDiscard(string $path): void
    {
        $this->shell->confirm = ['kind' => 'discard', 'path' => $path];
    }

    /**
     * 确认进行中：只响应 y / n / Esc（Ctrl+Q 视为确认，与退出热键一致）。
     * 其余输入一律吞掉，避免在弹确认时误操作到下层面板。
     */
    public function handleEvent(object $event): void
    {
        if ($event instanceof CharKeyEvent) {
            $ctrl = ($event->modifiers & KeyModifiers::CONTROL)
                && strtolower($event->char) === 'q';
            $ch = strtolower($event->char);
            if ($ctrl || $ch === 'y') {
                $this->confirmProceed();
                return;
            }
            if ($ch === 'n') {
                $this->shell->confirm = null;
            }
            return;
        }
        if ($event instanceof CodedKeyEvent && $event->code === KeyCode::Esc) {
            $this->shell->confirm = null;
        }
    }

    private function confirmProceed(): void
    {
        $kind = $this->shell->confirm['kind'] ?? 'quit';
        $path = $this->shell->confirm['path'] ?? null;
        $this->shell->confirm = null;
        if ($kind === 'discard' && $path !== null) {
            $this->shell->git->discard($path);
            return;
        }
        if ($kind === 'close' && $path !== null) {
            $this->close($path);
        } else {
            $this->quit();
        }
    }

    private function close(string $path): void
    {
        $this->shell->editor->removeBuffer($path);
        if ($this->shell->buffer === null || $this->shell->buffer->path === $path) {
            $remaining = array_values($this->shell->editor->buffers());
            $this->shell->buffer = $remaining !== [] ? $remaining[0] : null;
        }
    }

    private function anyDirty(): bool
    {
        foreach ($this->shell->editor->buffers() as $b) {
            if ($b->dirty) {
                return true;
            }
        }
        return false;
    }

    /** 收尾退出：先停掉还在跑的命令，避免留下孤儿子进程 */
    private function quit(): void
    {
        if ($this->onShutdown !== null) {
            ($this->onShutdown)();
        }
        $this->shell->quit = true;
    }
}

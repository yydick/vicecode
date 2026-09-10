<?php
declare(strict_types=1);

namespace App\Core;

use PhpTui\Term\Actions;
use PhpTui\Term\Terminal as PhpTermTerminal;
use PhpTui\Tui\Bridge\PhpTerm\PhpTermBackend;
use PhpTui\Tui\Display\Display;
use PhpTui\Tui\DisplayBuilder;
use App\App;

/**
 * M0 事件循环：Swoole 事件循环驱动 php-tui。
 *
 *  - 进入 alternate screen + raw mode（仅真实终端）+ 鼠标捕获，退出时反向恢复；
 *  - 输入：用 Swoole\Event 在非阻塞 STDIN 上注册可读回调，读到字节即解析为事件派发给 App；
 *  - 渲染：Swoole Timer 定时调用 Display::draw 逐帧重绘（布局随窗口尺寸自动重算）；
 *  - 终端恢复：restore() 幂等，由 quit() / SIGINT / 进程 shutdown 三处触发，确保任何退出
 *    路径都能把终端还原（raw mode 关闭、回显恢复、离开 alternate screen）。
 */
final class EventLoop
{
    private PhpTermTerminal $terminal;
    private Display $display;
    private InputParser $input;
    private App $app;
    private ?int $timer = null;
    private bool $quitting = false;
    private bool $restored = false;
    private bool $rawEnabled = false;
    private bool $isTty = false;

    public static function run(): void
    {
        \Swoole\Coroutine\run(function (): void {
            (new self())->start();
        });
    }

    private function start(): void
    {
        $this->terminal = PhpTermTerminal::new();
        $this->isTty = stream_isatty(STDIN) && stream_isatty(STDOUT);
        if ($this->isTty) {
            // 真实终端才启用 raw mode（stty 在非 tty 环境会失败）
            try {
                $this->terminal->enableRawMode();
                $this->rawEnabled = true;
            } catch (\Throwable $e) {
                // 忽略：降级为不开启 raw mode，仍可运行
            }
        }
        $this->terminal->queue(
            Actions::alternateScreenEnable(),
            Actions::enableMouseCapture(),
            Actions::cursorHide(),
            Actions::setTitle('ViceCode')
        );
        $this->terminal->flush();

        $backend = PhpTermBackend::new($this->terminal);
        // 同 bin/vicecode.php：注册菜单下拉透明覆盖层渲染器（DropdownOverlay 只画面板子区域、不清屏）
        $this->display = DisplayBuilder::default($backend)
            ->fullscreen()
            ->addWidgetRenderer(\App\Widget\DropdownOverlay::renderer())
            ->build();
        $this->app = new App();
        $this->input = new InputParser();
        // OSC 52 剪贴板读取：终端把系统剪贴板内容经 stdin 回传，InputParser 旁路捕获后回调 App
        $this->input->setClipboardHandler(function (string $text): void {
            $this->app->onClipboardRead($text);
        });

        // 关闭 STDOUT 缓冲并清一次屏，避免启动瞬间的残留/错位
        stream_set_write_buffer(STDOUT, 0);
        $this->display->clear();

        // 任何退出路径都恢复终端：正常 quit、信号、以及进程 shutdown（崩溃兜底）
        register_shutdown_function(function (): void {
            $this->restore();
        });
        \Swoole\Process::signal(SIGINT, function (): void {
            $this->quit();
        });

        // 输入：协程内用 waitEvent 等 STDIN 可读（对真实 pty 字符设备可靠，
        // 而 Swoole\Event::add 在 pty 上无法正常触发），再读字节解析派发。
        stream_set_blocking(STDIN, false);
        \go(function (): void {
            while (true) {
                if ($this->quitting) {
                    return;
                }
                $ready = \Swoole\Coroutine\System::waitEvent(STDIN, SWOOLE_EVENT_READ, 0.2);
                if ($ready === false) {
                    // 空闲超时：冲刷解析器。EventParser 会把暂存的孤立 ESC（\x1b）定稿为
                    // Esc 键，否则真实 pty 下「单独按 Esc」永远挂起、浮层无法关闭。
                    foreach ($this->input->flush() as $ev) {
                        $this->app->handle($ev, $this->display->viewportArea());
                        if ($this->app->quit) {
                            $this->quit();
                            return;
                        }
                    }
                    continue; // 超时，继续轮询（同时检查 quitting）
                }
                $chunk = fread(STDIN, 8192);
                if ($chunk === '' || $chunk === false) {
                    $this->quit();
                    return;
                }
                foreach ($this->input->feed($chunk) as $ev) {
                    $this->app->handle($ev, $this->display->viewportArea());
                    if ($this->app->quit) {
                        $this->quit();
                        return;
                    }
                }
            }
        });

        // 渲染循环：约 30fps；单帧异常时安全退出，避免卡在损坏的终端状态
        $this->timer = \Swoole\Timer::tick(33, function (): void {
            try {
                $area = $this->display->viewportArea();
                if ($area->width < 2 || $area->height < 2) {
                    return;
                }
                $this->display->draw($this->app->render());
            } catch (\Throwable $e) {
                @file_put_contents('/tmp/tui-error.log', (string) $e . "\n", FILE_APPEND);
                $this->quit();
            }
        });

        // 主协程到此返回；Swoole reactor 因 Timer / STDIN 监听持续运行，
        // 直到 quit() 清理监听后自然退出。
    }

    public function quit(): void
    {
        if ($this->quitting) {
            return;
        }
        $this->quitting = true;
        $this->restore();
    }

    /**
     * 幂等恢复终端。无论因何退出都应调用：关闭 raw mode（恢复回显）、
     * 离开 alternate screen、关闭鼠标捕获、显示光标。
     */
    private function restore(): void
    {
        if ($this->restored) {
            return;
        }
        $this->restored = true;

        if ($this->timer !== null) {
            \Swoole\Timer::clear($this->timer);
            $this->timer = null;
        }

        $this->terminal->queue(
            Actions::alternateScreenDisable(),
            Actions::disableMouseCapture(),
            Actions::cursorShow()
        );
        $this->terminal->flush();

        if ($this->rawEnabled) {
            try {
                $this->terminal->disableRawMode();
            } catch (\Throwable $e) {
                // 主恢复失败时用 stty sane 兜底，确保回显与规范模式恢复
                if ($this->isTty) {
                    @exec('stty sane 2>/dev/null');
                }
            }
        }
    }
}

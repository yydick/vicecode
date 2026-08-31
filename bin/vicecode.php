<?php
declare(strict_types=1);

/**
 * M1 入口：资源管理器 + 编辑器工作台。
 *
 * 底层运行时（M1.5）：
 *  - 默认走 Swoole 协程底座（TUI_USE_SWOOLE=1）：读键在协程内用原生
 *    fread(STDIN)（SWOOLE_HOOK_STDIO hook）读取，经 php-tui/term 的 EventParser
 *    解析成事件推入 Channel，主循环 select 驱动 handle/draw；终端 I/O（alternate
 *    screen / raw mode / mouse capture / 还原）仍交给 php-tui/term。
 *  - TUI_USE_SWOOLE=0（或未装 swoole）回退到纯 php-tui/term 阻塞读（M0 方案）。
 *
 * 运行：php bin/vicecode.php   （真实 pty 下；验收用 tests/pty_run.php / pty_drive.php）
 */

require __DIR__ . '/../vendor/autoload.php';

use App\App;
use PhpTui\Term\Terminal;
use PhpTui\Term\Actions;
use PhpTui\Term\Event;
use PhpTui\Term\EventProvider;
use PhpTui\Term\EventParser;
use PhpTui\Tui\Bridge\PhpTerm\PhpTermBackend;
use PhpTui\Tui\DisplayBuilder;

/**
 * 阻塞式事件源（回退分支用）：stream_select 等到 STDIN 可读再 fread，避免“无输入即返回 null”。
 */
final class BlockingTtyEventProvider implements EventProvider
{
    private EventParser $parser;
    /** @var Event[] */
    private array $buffer = [];

    public function __construct(private $stream = STDIN)
    {
        $this->parser = EventParser::new();
        stream_set_blocking($this->stream, true);
    }

    public function next(): ?Event
    {
        while ($e = array_shift($this->buffer)) {
            return $e;
        }
        $r = [$this->stream];
        $w = $e = [];
        $n = @stream_select($r, $w, $e, null); // null = 永久阻塞直到有输入
        if ($n === false || $n < 1) {
            return null;
        }
        $bytes = fread($this->stream, 4096);
        if ($bytes === '' || $bytes === false) {
            return null; // EOF（终端关闭）
        }
        $this->parser->advance($bytes, false);
        foreach ($this->parser->drain() as $ev) {
            $this->buffer[] = $ev;
        }
        return $this->next();
    }

    /**
     * 带超时的版本：等到可读或超时，返回本轮解析出的全部事件（无输入则空数组）。
     *
     * 无 Swoole 的回退分支必须用这个而不是 next()：next() 永久阻塞，命令运行期间
     * 主循环卡在等键上，命令输出就没法被排空，界面会僵住直到命令结束。
     *
     * @return Event[]
     */
    public function drainTimeout(int $timeoutUs): array
    {
        $r = [$this->stream];
        $w = $e = [];
        $n = @stream_select($r, $w, $e, 0, $timeoutUs);
        if ($n !== false && $n >= 1) {
            $bytes = fread($this->stream, 4096);
            if ($bytes !== '' && $bytes !== false) {
                $this->parser->advance($bytes, false);
                foreach ($this->parser->drain() as $ev) {
                    $this->buffer[] = $ev;
                }
            }
        }
        $out = $this->buffer;
        $this->buffer = [];
        return $out;
    }
}

/** 干净还原终端（无花屏、回显恢复）。 */
function restoreTerminal(Terminal $term): void
{
    $term->queue(
        Actions::alternateScreenDisable(),
        Actions::disableMouseCapture(),
        Actions::cursorShow()
    );
    $term->flush();
    $term->disableRawMode();
}

/**
 * @param bool $sw 是否使用 Swoole 协程底座
 */
function start(bool $sw): void
{
    $app = new App();

    // ── 进入终端（终端动作仍走 php-tui/term）──
    $term = Terminal::new();
    $term->enableRawMode();
    $term->queue(
        Actions::alternateScreenEnable(),
        Actions::enableMouseCapture(),
        Actions::cursorHide(),
        Actions::setTitle('ViceCode')
    );
    $term->flush();

    $backend = PhpTermBackend::new($term);
    $display = DisplayBuilder::default($backend)->fullscreen()->build();

    if ($sw) {
        // ── Swoole 协程底座 ──
        Swoole\Coroutine\defer(static fn() => restoreTerminal($term)); // 任何路径退出都还原

        $ch = new Swoole\Coroutine\Channel(64);   // 键鼠事件
        $redraw = new Swoole\Coroutine\Channel(8); // R3：后台协程完成信号
        $parser = EventParser::new();

        // 读键协程：用 Coroutine::waitEvent 等 STDIN 可读（对 TTY/pty 生效、协程让出调度器），
        // 确认可读后再整块 fread（避免单字节误判 ESC 序列）。解析后推 Channel。
        go(static function () use ($ch, $parser, $app): void {
            while (!$app->quit) {
                $readable = \Swoole\Coroutine::waitEvent(STDIN, SWOOLE_EVENT_READ, 1.0);
                if ($readable === false) {
                    continue; // 超时，继续等（也顺带让出调度器）
                }
                $bytes = fread(STDIN, 4096);
                if ($bytes === '' || $bytes === false) {
                    break; // EOF（终端关闭）
                }
                $parser->advance($bytes, false);
                foreach ($parser->drain() as $ev) {
                    $ch->push($ev);
                }
            }
        });

        // R3 demo（可选）：后台协程跑耗时 I/O，不阻塞主循环，完成后触发重绘
        if (getenv('TUI_DEMO') === '1') {
            go(static function () use ($app, $redraw): void {
                Swoole\Coroutine::sleep(2);
                $app->message = '后台协程完成（渲染未卡）';
                $redraw->push(1);
            });
        }

        // 主循环：pop 事件通道。$ch 里只 push Event 对象，pop 返回 false 即超时（详见
        // docs/swoole_study.md §4.2），不要用 `!== null` 判据。超时再非阻塞查后台重绘信号。
        //
        // M2：每轮都调 pollTerminal() 排空命令管道——子进程的输出随时会到，不能只在
        // 有键事件时才读。命令运行中把 pop 超时压到 10ms，让输出跟手。
        while (!$app->quit) {
            $busy = $app->termRunning() || $app->searchRunning();
            $ev = $ch->pop($busy ? 0.01 : 0.05);
            $gotOutput = $app->pollTerminal();
            $gotSearch = $app->pollSearch();
            if ($ev === false) {
                // 非阻塞查 R3 后台重绘信号：绝不能用 pop(0)（0 超时在 Swoole 中是永久阻塞！），
                // 先 isEmpty() 判空再 pop 一个极小超时。
                if ($gotOutput || $gotSearch || !$redraw->isEmpty()) {
                    if (!$redraw->isEmpty()) {
                        $redraw->pop(0.001);
                    }
                    $display->draw($app->render($display->viewportArea()));
                }
                continue;
            }
            $app->handle($ev, $display->viewportArea());
            $display->draw($app->render($display->viewportArea()));
        }
        return;
    }

    // ── 回退分支：纯 php-tui/term（无 Swoole，M0 方案，保留可用）──
    // 用 drainTimeout() 而不是 next()：后者永久阻塞在等键上，命令运行期间 UI 会僵住。
    $events = new BlockingTtyEventProvider(STDIN);
    $display->draw($app->render($display->viewportArea()));
    while (!$app->quit) {
        $handled = false;
        foreach ($events->drainTimeout(($app->termRunning() || $app->searchRunning()) ? 10000 : 50000) as $event) {
            $app->handle($event, $display->viewportArea());
            $handled = true;
        }
        $gotOutput = $app->pollTerminal();
        $gotSearch = $app->pollSearch();
        if ($handled || $gotOutput || $gotSearch) {
            $display->draw($app->render($display->viewportArea()));
        }
    }
    restoreTerminal($term);
}

$useSwoole = (getenv('TUI_USE_SWOOLE') ?: '1') === '1'
    && extension_loaded('swoole')
    && PHP_SAPI === 'cli';

// M2 起再关掉两个 HOOK（实测见 tests/m2_probe_runner.php）：
//  - SWOOLE_HOOK_STDIO：对真实 TTY/pty 的 fread 不会协程化，会退化成阻塞读并卡死
//    整个调度器。读键改用 Coroutine::waitEvent（走 EventLoop），对 TTY 也生效。
//    详见 docs/swoole_study.md §2.1 / §3.1。
//  - SWOOLE_HOOK_FILE：会接管命令管道 fd，proc_close 后延迟释放再次 close，往
//    stdout 吐 `WARNING network::socket_free_defer()`——直接打进 alternate screen 花屏。
//  - SWOOLE_HOOK_PROC：接管后 proc_close() 返回值被改写（exit 42 → 0），且 proc_open
//    只能在协程内调用，headless 测试驱动不了 runner。关掉即恢复原生语义。
// 命令执行本就靠「子进程 + 非阻塞管道轮询」，不需要这两个 HOOK。
if ($useSwoole) {
    Swoole\Runtime::enableCoroutine(
        SWOOLE_HOOK_ALL & ~SWOOLE_HOOK_STDIO & ~SWOOLE_HOOK_FILE & ~SWOOLE_HOOK_PROC
    );
    Swoole\Coroutine\run(static fn() => start(true));
} else {
    start(false);
}

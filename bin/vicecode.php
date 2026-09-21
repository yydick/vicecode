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
 * 运行：php bin/vicecode.php [dir]   （真实 pty 下；验收用 tests/pty_run.php / pty_drive.php）
 *   dir 省略或为 '.' → 当前目录；dir 为目录路径 → 以其为资源管理器根；dir 为文件 → 直接打开。
 */

require __DIR__ . '/../vendor/autoload.php';

use App\App;
use App\Core\ConfigStore;
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
 * 致命错误的统一出口：翻译成人类可读的一行（终端还原已由 start() 的 finally 保证），
 * 而不是把一堆 PHP 堆栈喷在用户的屏幕上。
 */
function reportFatal(Throwable $e): void
{
    fwrite(STDERR, "\nViceCode 异常退出：" . $e->getMessage() . "\n");
    fwrite(STDERR, '  ' . $e->getFile() . ':' . $e->getLine() . "\n");
    exit(1);
}

/**
 * 退出收尾：**正常退出、未捕获异常、致命错误**三条路径共用同一件事，且只做一次。
 *
 * ⚠️ 为什么不能只靠 `start()` 的 finally：PHP 的**致命错误**（`E_ERROR` /
 * `E_CORE_ERROR` / `E_COMPILE_ERROR` / `E_USER_ERROR` / 内存耗尽）**不会执行 finally**
 * —— 实测（两行脚本即可复现）：内存耗尽与未捕获 Error 下 finally 都不跑，
 * 而 `register_shutdown_function` 都跑。
 *
 * 少了这层兜底，进程一死于致命错误，终端就留在 raw mode + alternate screen
 * + **鼠标上报开着**：用户回到 shell 后每动一下鼠标，终端就往 tty 灌
 * `ESC[<b;x;yM`（`ESC [ <` 被终端当控制序列吃掉，只剩数字）
 * → 显示成 `-bash: 35: command not found`。这是用户实测报上来的现象。
 *
 * `run()` 幂等：第二次调用直接返回。**这条必须守住**——当年 `Coroutine\defer` 与
 * finally 各还原一次，还原序列发两遍，才把 defer 撤掉的（见 startMain 的注释）。
 */
final class Shutdown
{
    /** @var null|\Closure():void 由 start() 注册：落盘偏好 + 回收资源 + 还原终端 */
    private static ?\Closure $cleanup = null;

    private static bool $ran = false;

    public static function register(\Closure $cleanup): void
    {
        self::$cleanup = $cleanup;
    }

    public static function run(): void
    {
        if (self::$ran) {
            return;
        }
        self::$ran = true;   // 先置位：cleanup 自身若再抛错也不会重入
        if (self::$cleanup !== null) {
            (self::$cleanup)();
        }
    }

    /** 致命错误日志路径：与配置同目录（`VICECODE_CONFIG` 的 dirname），测试隔离时自动落进临时目录 */
    public static function fatalLogPath(): string
    {
        return dirname(ConfigStore::path()) . '/.vicecode_fatal.log';
    }

    /** 往致命日志追加一行。用户不主动看它，但它是「为什么死的」唯一线索来源。 */
    public static function log(string $line): void
    {
        @file_put_contents(self::fatalLogPath(), $line, FILE_APPEND);
    }

    /**
     * 「本次会话还活着」标记的路径。
     *
     * 它要捕捉的是 shutdown function 与信号 handler **都够不到**的那一类死亡
     * （SIGKILL、段错误、宿主进程整块消失）：进程没有任何机会执行收尾代码，
     * 既不会发还原序列、也不会写日志。只有靠「启动时写、**正常退出**时删」的标记，
     * 下次启动才发现「上一次是异常结束，而且连死因都没留下」。
     */
    public static function alivePath(): string
    {
        return dirname(ConfigStore::path()) . '/.vicecode_alive';
    }

    /** 启动时写下存活标记（带启动时间与 pid，事后能判断是哪一次会话） */
    public static function markAlive(): void
    {
        @file_put_contents(self::alivePath(), json_encode([
            'since' => date('c'),
            'pid'   => getmypid(),
        ], JSON_UNESCAPED_SLASHES) . "\n");
    }

    /** **只有正常退出**才调用：清掉标记。异常路径一律不清，下次启动才会提示。 */
    public static function clearAlive(): void
    {
        @unlink(self::alivePath());
    }

    /**
     * 读取残留标记；没有残留返回 null。
     *
     * ⚠️ pid 存活检查不能省：同时开两个 ViceCode 时，后启动的会覆盖标记 ——
     * 若不检查，先启动那个实例的（正常）退出会被后启动的实例在下次启动时
     * 误报成「上次异常结束」。
     */
    public static function staleAlive(): ?array
    {
        $path = self::alivePath();
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($path), true);
        if (!is_array($data)) {
            return [];   // 标记内容坏掉：仍按「有残留」处理（宁可多提示一次，不可漏报）
        }
        $pid = (int) ($data['pid'] ?? 0);
        if ($pid > 0 && $pid !== getmypid() && self::pidAlive($pid)) {
            return null;   // 那是**另一个**正在运行的实例
        }
        return $data;
    }

    private static function pidAlive(int $pid): bool
    {
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }
        return is_dir('/proc') && file_exists('/proc/' . $pid);
    }

    /**
     * 装信号处理：SIGTERM / SIGHUP / SIGINT。
     *
     * ⚠️ 为什么必须装（实测见 `tests/pty_signals.php`）：没有 handler 时，信号走
     * **默认动作 = 进程立即终止** —— PHP 既不执行 `register_shutdown_function`、
     * 也不走 `finally`。于是终端被留在 raw + 备用屏 + 鼠标上报，而
     * `error_get_last()` 是 null（信号不是 error）→ **连日志都不写**。
     * 这类死法在用户眼里与崩溃一模一样，却是唯一「终端乱掉且查不到原因」的一种。
     *
     * handler 里**必须 exit**：否则默认动作被吞掉，进程会变得杀不死。
     * 用 pcntl 而非 `Swoole\Process::signal`：pcntl + async signals 在两种底座下
     * 行为一致，且信号一到就能分发，不依赖事件循环正在跑。
     */
    public static function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;   // 没有 pcntl 的构建：只能接受这一类死亡无覆盖
        }
        $handler = static function (int $signo): void {
            self::run();   // 幂等：还原终端 + 落盘偏好 + 回收资源
            $name = self::signalName($signo);
            self::log(sprintf("[%s] 被信号终止：%s（终端已还原）\n", date('c'), $name));
            fwrite(STDERR, "\nViceCode 被信号终止（{$name}），终端已还原。\n");
            fwrite(STDERR, '  日志：' . self::fatalLogPath() . "\n");
            // ⚠️ 这里**不能**写 exit(128 + $signo)：
            //  - 协程内 Swoole 会把 exit() 转成 `Swoole\ExitException`，被顶层 catch
            //    接住后走 reportFatal → 退出码变成 1，还多打一句「异常退出：swoole exit」；
            //  - 非协程内它只是「正常退出、码为 128+n」，而非真正被信号终止。
            // 改成「恢复默认处置 + 重发信号」：handler 执行期间该信号被内核屏蔽，
            // 重发的那个会在 handler **返回后立刻**投递，进程遂按默认处置终止
            // （实测两种底座都得到 `Terminated`；pty 用例断言 termsig=15/1/2）。
            // 不重发则默认动作被吞掉，进程会变得杀不死。
            if (function_exists('posix_kill')) {
                pcntl_signal($signo, SIG_DFL);
                posix_kill(getmypid(), $signo);
                return;
            }
            exit(128 + $signo);   // 退路：没有 posix 扩展的构建
        };
        foreach ([SIGTERM, SIGHUP, SIGINT] as $sig) {
            pcntl_signal($sig, $handler);
        }
        pcntl_async_signals(true);
    }

    private static function signalName(int $signo): string
    {
        return match ($signo) {
            SIGTERM => 'SIGTERM',
            SIGHUP  => 'SIGHUP',
            SIGINT  => 'SIGINT',
            default => 'signal ' . $signo,
        };
    }
}

/**
 * 解析启动参数（纯函数，便于单测）。返回 [openFile, chdirTo]：
 *  - 首个非选项位置参数：目录 → chdirTo=realpath；可读文件 → openFile；'.' → 两者皆 null（当前目录）。
 *  - 无匹配参数 → 两者皆 null。
 * @return array{openFile:?string, chdirTo:?string}
 */
function resolveStartArg(array $argv): array
{
    $openFile = null;
    $chdirTo = null;
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '' || $arg[0] === '-') {
            continue;
        }
        if ($arg === '.') {
            break; // 默认当前目录，无需切换
        }
        if (is_dir($arg)) {
            $real = realpath($arg);
            if ($real !== false && $real !== '') {
                $chdirTo = $real;
            }
            break;
        }
        if (is_file($arg) && is_readable($arg)) {
            $openFile = $arg;
            break;
        }
        // 既非目录也非可读文件：忽略，继续看下一个参数
    }
    return ['openFile' => $openFile, 'chdirTo' => $chdirTo];
}

/**
 * @param bool $sw 是否使用 Swoole 协程底座
 * @param list<string> $argv 命令行参数：
 *   - 首个位置参数（不以 '-' 开头）为目录 → chdir 进该目录，资源管理器/终端/搜索/git 随之跟随；
 *   - 为可读文件 → 作为初始打开文件（沿用 M1 行为）；
 *   - 无参数或 '.' → 默认当前目录（不切换）。
 */
function start(bool $sw, array $argv): void
{
    // 解析首个位置参数：目录 → chdir 进它；文件 → 记录待打开文件；'-' 开头视为选项跳过。
    // 在 new App() 之前切换 cwd，使资源管理器根目录 / 终端 cwd / 搜索 / git 全部自然跟随。
    $arg = resolveStartArg($argv);
    if ($arg['chdirTo'] !== null) {
        chdir($arg['chdirTo']);
    }

    // 存活标记：启动时写、**正常退出**时删（见 start() 末尾）。残留 = 上次异常结束。
    // 「上一次」的提示要等 new App() 之后才能发（那时才有 setMessage），故先读出来存着。
    $stale = Shutdown::staleAlive();
    Shutdown::markAlive();

    $app = new App();

    if ($stale !== null) {
        // 上次没走正常路径。它可能连致命错误都没留下（SIGKILL / 段错误），所以这条
        // 日志往往是唯一线索 —— 没有它，那种死法永远查不出「到底发生过没有」。
        Shutdown::log(sprintf(
            "[%s] WARN: 上次会话未正常结束（启动于 %s, pid %s），且没有致命错误记录"
            . " —— 可能是被 SIGKILL / 段错误等无法拦截的方式终止。\n",
            date('c'),
            (string) ($stale['since'] ?? '未知'),
            (string) ($stale['pid'] ?? '?')
        ));
        // ⚠️ 走 stderr 而**不是**状态栏消息：状态栏的消息段是为**瞬时事件**设计的，
        // 且优先级最高，会把常显的「Ctrl+Q 退出」这类面包屑挤掉（实测：`pty_hotkey`
        // 因为上一次被 SIGKILL 留下标记，下一次启动的提示把退出键提示顶没了）。
        // 这是一条**持续状态**，写在进入备用屏之前 —— 备用屏弹栈后它仍在普通屏上可见。
        fwrite(STDERR, "\n{$app->t('app.stale_exit')}\n");
    }

    // 命令行首个可读文件作为初始打开文件（验收 / 日常 `vicecode <file>` 都可用）。
    if ($arg['openFile'] !== null) {
        $app->editor->openFile($arg['openFile']);
    }

    // ── 进入终端（终端动作仍走 php-tui/term）──
    $term = Terminal::new();

    // ⚠️ 收尾闭包必须在**终端被改动之前**就注册好，否则「raw mode 已生效、收尾还没挂上」
    // 那个窗口里的致命错误依然会把终端留在 raw mode（`register_shutdown_function` 是在
    // 顶层注册的，但它跑的时候只能调用这里注册进来的闭包）。
    //
    // `$terminalTouched` 用来精确界定"有没有东西要还原"：raw mode 一生效就置位，
    // 因为在它之前退出的话根本无需还原（disableRawMode 还原的是一份尚未被改动的 stty 设置）。
    $terminalTouched = false;
    Shutdown::register(static function () use ($app, $term, &$terminalTouched): void {
        $app->saveConfig();       // R7：退出前把偏好落盘（~/.vicerc），内部容错不抛
        // 资源回收兜底（与终端还原同理，必须覆盖**所有**退出路径）：正常退出由 Lifecycle 的
        // 关闭闭包做，但未捕获异常/致命错误等路径只走到这里——不在这里收，pty/shell 子进程就只靠
        // 内核在 pty 主端关闭时发 SIGHUP 兜底，bash `--rcfile` 的临时文件也不会被删。幂等。
        $app->shutdownResources();
        if ($terminalTouched) {
            restoreTerminal($term);
        }
    });

    $term->enableRawMode();
    $terminalTouched = true;   // 从这里起，终端有任何改动都需要还原
    $term->queue(
        Actions::alternateScreenEnable(),
        Actions::enableMouseCapture(),
        Actions::cursorHide(),
        Actions::setTitle('ViceCode')
    );
    $term->flush();

    // ⚠️ 从这里到函数结束**任何**退出路径都要还原终端：
    // 少了 finally 的话，一个未捕获异常就会把终端留在 raw mode + alternate screen
    // （无回显、无光标，用户的 shell 直接废掉）—— 这正是 M0 早期"花屏"的根因。
    //
    // 但 finally **覆盖不到致命错误**（PHP 的 E_ERROR / 内存耗尽不走 finally），
    // 所以同一份收尾同时注册给 Shutdown（由顶层 register_shutdown_function 兜底）。
    // 两边共用同一个闭包 + Shutdown::run() 的幂等标志，保证**只做一次**。

    try {
        startMain($app, $term, $sw);
    } catch (Throwable $e) {
        // 异常退出时也要**让读键协程停下来**：Swoole\Coroutine\run() 必须等容器内所有子协程
        // 结束才会返回 —— 协程还挂在 `while (!$app->quit)` 里的话，异常就永远报告不出去。
        // 实测（tests/pty_crash.php 场景 B：交互 shell 活着时崩溃）表现为进程挂住、只能被
        // SIGKILL 收掉。置位后照常上抛，由顶层 reportFatal 统一报告；回收交给下面的 finally。
        $app->quit = true;
        throw $e;
    } finally {
        // 与致命错误路径共用（幂等）：正常/异常退出在这里收尾，
        // 致命错误则由 register_shutdown_function 再兜一次。
        Shutdown::run();
    }

    // 只有走到这里才是**正常退出**（异常路径在上面的 catch 里已上抛，finally 之后不会执行到）。
    // 清掉存活标记，下次启动就不会误报「上次异常结束」。
    Shutdown::clearAlive();
}

function startMain(App $app, Terminal $term, bool $sw): void
{
    $backend = PhpTermBackend::new($term);
    // 注册菜单下拉透明覆盖层渲染器（DropdownOverlay 只画面板子区域、不清屏，
    // 修复早期「全视口 Grid + 空 spacer」把底层 UI 整块抹成空白的遮罩 bug）。
    $display = DisplayBuilder::default($backend)
        ->fullscreen()
        ->addWidgetRenderer(\App\Widget\DropdownOverlay::renderer())
        ->build();

    if ($sw) {
        // ── Swoole 协程底座 ──
        // 终端还原不再靠 Coroutine\defer：start() 的 try/finally 已覆盖正常返回与异常
        // 两条路径，再挂 defer 会让还原序列在每次退出时发两遍（实测输出里
        // `ESC[?1049l ... ESC[?25h` 连续出现两次）。
        $ch = new Swoole\Coroutine\Channel(64);   // 键鼠事件
        $redraw = new Swoole\Coroutine\Channel(8); // R3：后台协程完成信号
        $parser = EventParser::new();

        // 读键协程：用 Coroutine::waitEvent 等 STDIN 可读（对 TTY/pty 生效、协程让出调度器），
        // 确认可读后再整块 fread（避免单字节误判 ESC 序列）。解析后推 Channel。
        go(static function () use ($ch, $parser, $app): void {
            while (!$app->quit) {
                // 空闲超时设短（150ms）：既让出调度器，也定期冲刷解析器里「还在等后续字节」
                // 的残局（典型如孤立的 ESC）。若不主动 flush，孤立 Esc 要等下个键才发得出去，
                // 表现为「单独按 Esc 关不了菜单/帮助页」且有明显延迟。
                $readable = \Swoole\Coroutine::waitEvent(STDIN, SWOOLE_EVENT_READ, 0.15);
                if ($readable === false) {
                    // 超时无输入：告诉 parser「没有更多字节了」($more=false)，
                    // 让缓冲区里孤立的 ESC（单独 \x1b）冲刷成 Esc 事件——否则 parser 会
                    // 一直把它当「转义序列开头」等待后续，孤立 Esc 永远发不出去（菜单关不掉）。
                    // 该冲刷路径由 vendor EventParser::advance('', false) 的空行分支实现
                    // （见 vendor/php-tui/term/src/EventParser.php —— ViceCode 修复）。
                    $parser->advance('', false);
                    foreach ($parser->drain() as $ev) {
                        $ch->push($ev);
                    }
                    continue; // 也顺带让出调度器
                }
                $bytes = fread(STDIN, 4096);
                if ($bytes === '' || $bytes === false) {
                    break; // EOF（终端关闭）
                }
                // more=true：pty 下 fread 常把转义序列（如 \e[5~、\e[A）拆成多段返回，
                // 必须让 parser 在内部 buffer 里暂存不完整的序列，等后续字节拼齐再解析；
                // 否则孤 \e 被立刻当成 Esc 冲掉、后面的字节退化成字符键——方向键 /
                // PageUp/Down / Home/End 全失灵。已完整结束的序列（~/$/字母终结符）无论
                // more 如何都会立即吐出，仅孤 \e 会等下个字节或超时冲刷（见上方超时分支）。
                $parser->advance($bytes, true);
                $evs = $parser->drain();
                foreach ($evs as $ev) {
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

        // ⚠️ 先画一帧初始界面：否则主循环只在「有事件/有输出」时才 draw，
        // 真实 pty 下首屏会空白到用户按第一个键才出现（回退分支在循环前有同样的初始 draw）。
        $display->draw($app->render($display->viewportArea()));

        // 有待周期重绘的插件（如时钟）时，idle 超时设为最小 tick 间隔，使其持续走动；
        // 否则保持原 50ms 低耗轮询。
        $tickSec = $app->minTickInterval();
        $idleTimeout = $tickSec !== null ? max(0.1, (float) $tickSec) : 0.05;
        while (!$app->quit) {
            $busy = $app->termRunning() || $app->searchRunning() || $app->aiStreaming();
            $ev = $ch->pop($busy ? 0.01 : $idleTimeout);
            $gotOutput = $app->pollTerminal();
            $gotSearch = $app->pollSearch();
            // M5：AI 流式 token 同样每轮排空。返回 true 表示有新 token，必须重绘，
            // 否则界面会卡住不动、直到回复结束时一次性出现。
            $gotAi = $app->pollAi();
            if ($ev === false) {
                // 非阻塞查 R3 后台重绘信号：绝不能用 pop(0)（0 超时在 Swoole 中是永久阻塞！），
                // 先 isEmpty() 判空再 pop 一个极小超时。
                if ($gotOutput || $gotSearch || $gotAi || !$redraw->isEmpty()) {
                    if (!$redraw->isEmpty()) {
                        $redraw->pop(0.001);
                    }
                    $display->draw($app->render($display->viewportArea()));
                } elseif ($tickSec !== null) {
                    // 无事件/输出但有待 tick 插件：idle 超时到达即重绘，驱动时钟等插件更新
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
    $tickSec = $app->minTickInterval();
    $idleUs = $tickSec !== null ? (int) max(200000, (float) $tickSec * 1000000) : 50000;
    $events = new BlockingTtyEventProvider(STDIN);
    $display->draw($app->render($display->viewportArea()));
    while (!$app->quit) {
        $handled = false;
        $busy = $app->termRunning() || $app->searchRunning() || $app->aiStreaming();
        foreach ($events->drainTimeout($busy ? 10000 : $idleUs) as $event) {
            $app->handle($event, $display->viewportArea());
            $handled = true;
        }
        $gotOutput = $app->pollTerminal();
        $gotSearch = $app->pollSearch();
        $gotAi = $app->pollAi();
        // 有待 tick 插件时即便无输入/输出也重绘（$idleUs 已按 tick 放大），驱动时钟走动
        if ($handled || $gotOutput || $gotSearch || $gotAi || $tickSec !== null) {
            $display->draw($app->render($display->viewportArea()));
        }
    }
    // 还原由 start() 的 finally 统一负责
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
// R3：顶层兜底。终端还原由 start() 的 finally 保证；这里只负责把致命错误
// 翻译成人类可读的一行，而不是把栈追踪喷在用户刚恢复的屏幕上。
// 仅当本文件被直接执行（而非被测试 require）时才启动 TUI；
// 守卫让 tests/cli_dir.php 能 require 本文件调用 resolveStartArg 而不误进界面。
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    // 致命错误兜底：必须在**进入终端之前**注册 —— 终端一旦被置成 raw/备用屏/鼠标捕获，
    // 任何一条不经过 finally 的死亡（E_ERROR、E_USER_ERROR、内存耗尽…）都会把它留在那儿。
    // 详见 Shutdown 的类注释与 tests/pty_crash.php 场景 C。
    register_shutdown_function(static function (): void {
        $e = error_get_last();
        $isFatal = $e !== null && in_array(
            $e['type'],
            [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR],
            true
        );
        // 先收尾（还原终端），再谈日志：日志要落在已经还原好的屏幕上。
        Shutdown::run();
        if (!$isFatal) {
            return;
        }
        // 致命错误信息原本会打在 alternate screen 上，还原后会被冲掉看不清 —— 所以另外落盘。
        // 这也是「用户只看到终端乱掉、却不知道原因」时唯一的线索来源。
        $line = sprintf(
            "[%s] %s: %s @ %s:%d\n",
            date('c'),
            $e['type'] === E_USER_ERROR ? 'E_USER_ERROR' : 'FATAL',
            $e['message'],
            $e['file'],
            $e['line']
        );
        $log = Shutdown::fatalLogPath();
        Shutdown::log($line);
        fwrite(STDERR, "\nViceCode 致命错误（终端已尝试还原）：{$e['message']}\n");
        fwrite(STDERR, "  {$e['file']}:{$e['line']}\n");
        fwrite(STDERR, "  日志：{$log}\n");
        fwrite(STDERR, "  若终端仍不正常（鼠标乱码 / 无回显）：reset 或 stty sane\n");
    });

    // 信号兜底：SIGTERM / SIGHUP / SIGINT 不装 handler 时走「默认动作 = 立即终止」，
    // 既不跑 shutdown function、也不走 finally，且 error_get_last() 为 null → 终端乱掉且无日志。
    // 装在终端被改动之前，保证「刚进 raw mode 就被 kill」也覆盖得到。
    Shutdown::installSignalHandlers();

    // 交互式终端前置检查：非 tty 的 stdin（重定向 / 管道 / `</dev/null`）下，php-tui 取
    // raw mode 必然失败 —— vendor SttyRawMode::enable() 靠 `stty -g` 探测，非 tty 时直接抛
    // RuntimeException('Could not get stty settings')。在这里、**协程之外**快速失败，用户
    // 看到的是一句人话，而不是「PHP Fatal error + 堆栈 + exit 255」。
    if (!stream_isatty(STDIN)) {
        reportFatal(new RuntimeException(
            '需要交互式终端：stdin 不是 TTY（请勿重定向或管道输入 stdin）'
        ));
    }

    if ($useSwoole) {
        Swoole\Runtime::enableCoroutine(
            SWOOLE_HOOK_ALL & ~SWOOLE_HOOK_STDIO & ~SWOOLE_HOOK_FILE & ~SWOOLE_HOOK_PROC
        );
        // ⚠️ try/catch 必须放在**协程闭包内部**：Swoole\Coroutine\run() 内部抛出的异常
        // 不会传播给调用者 —— 实测把 catch 挂在 run() 外面根本接不住，异常直接变成
        // 「PHP Fatal error: Uncaught ...」并 exit 255（顺带绕过 reportFatal 的友好提示）。
        // 故在协程内捕获、把异常带出来，再由协程外统一报告。
        $fatal = null;
        Swoole\Coroutine\run(static function () use ($argv, &$fatal): void {
            try {
                start(true, $argv);
            } catch (Throwable $e) {
                $fatal = $e;
            }
        });
        if ($fatal !== null) {
            reportFatal($fatal);
        }
    } else {
        try {
            start(false, $argv);
        } catch (Throwable $e) {
            reportFatal($e);
        }
    }
}

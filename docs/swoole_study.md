# Swoole 协程学习手册（本项目专用）

> 版本基准：**Swoole 6.2.1 / PHP 8.3.20**（本机实测 `swoole_version()`）。
> 来源：官方文档 `https://wiki.swoole.com/zh-cn/`（`github.com/swoole/docs` 的 `public/zh-cn/*.md` 原始 markdown）。
> 目的：在写 `bin/tui.php` 的协程分支前，先把 Swoole 的真实 API 与范式吃透，避免"试 API 报错就换一个"的盲猜。

---

## 0. 索引（官方文档页面 → 内容）

| 页面（原始 markdown 路径） | 覆盖内容 |
| --- | --- |
| `runtime.md`（一键协程化） | `Swoole\Runtime::enableCoroutine($flags)`、`SWOOLE_HOOK_*` 各标志含义、哪些函数/流可被 hook、哪些**不支持协程化** |
| `coroutine/coroutine.md`（核心 API） | `go()` / `Co\run()` / `Coroutine::create` / `defer` / 协程容器 |
| `coroutine/scheduler.md`（协程容器） | `Coroutine\run` 容器语义、被 hook 的函数必须在容器内调用 |
| `coroutine/system.md`（系统 API） | `Coroutine::waitEvent`、`Coroutine::sleep`、`waitSignal`、`readFile` 等；注意 `System::fread` **已在 6.0 移除** |
| `coroutine/channel.md`（Channel） | `push`/`pop` 语义、超时返回值、容量、`errCode` |
| `event.md`（事件循环） | `Swoole\Event::add/del/set/write/wait`——reactor 级回调（非协程上下文） |
| `coroutine/notice.md`（编程须知） | 协程内禁全局变量/引用、`try/catch` 必须在本协程内、魔术方法不得切换协程、`exit`→`ExitException` |

> 文档是 docsify SPA，直接抓 `.md` 会 404；原始源在 `https://raw.githubusercontent.com/swoole/docs/main/public/zh-cn/<path>.md`。

---

## 1. 协程调度模型（先建立心智模型）

- 一次 `Swoole\Coroutine\run($fn)`（或 `Co\run`）创建一个**协程容器**，在容器内用 `go($fn)` / `Coroutine::create($fn)` 派生协程。
- 所有协程在**单个 OS 线程**内协作调度：遇到 IO/等待（`sleep`、`waitEvent`、被 hook 的 stream 读等）就 **yield**，调度器切到别的协程；IO 就绪或超时再 **resume**。
- 底层是 **EventLoop（epoll/kqueue）**。被 `SWOOLE_HOOK_*` hook 的原生 PHP 函数（如 `fread` 对某些流、`stream_select`、`sleep`、`file_get_contents`）会在阻塞点自动 yield。
- **关键前提**：被 hook 的函数必须在协程容器内调用；`enableCoroutine()` 应只调一次（重复调用会被覆盖）。

---

## 2. `SWOOLE_HOOK_*` 标志速查

`Swoole\Runtime::enableCoroutine($flags)`，`$flags` 可用 `|` 叠加。本机实测常量值：

| 常量 | 值 | 作用 | 备注 |
| --- | --- | --- | --- |
| `SWOOLE_HOOK_TCP` | 2 | TCP stream（Redis/PDO/Mysqli 等） | |
| `SWOOLE_HOOK_UNIX` | 8 | Unix stream socket | |
| `SWOOLE_HOOK_UDP` | 4 | UDP stream | |
| `SWOOLE_HOOK_STREAM_FUNCTION` | 128 | `stream_select()` | |
| `SWOOLE_HOOK_FILE` | 256 | `fopen/fread/file_get_contents` 等**磁盘文件** | 注意会 hook autoload；可用 `SWOOLE_HOOK_ALL & ~SWOOLE_HOOK_FILE` 关掉 |
| `SWOOLE_HOOK_SLEEP` | 512 | `sleep/usleep` | |
| `SWOOLE_HOOK_PROC` | 1024 | `proc_open/close` 等 | |
| `SWOOLE_HOOK_STDIO` | **32768** | `STDIO`（含 `stdin/stdout` 流、`flock`） | **见下方 2.1 大坑** |
| `SWOOLE_HOOK_SOCKETS` | 16384 | `sockets` 扩展 | |
| `SWOOLE_HOOK_CURL` / `NATIVE_CURL` | 2048/4096 | CURL | 互斥 |
| `SWOOLE_HOOK_ALL` | 2143285247 | 上述全部（不含 CURL） | |

### 2.1 `SWOOLE_HOOK_STDIO` 的大坑（本项目直接踩中）

官方 `SWOOLE_HOOK_STDIO` 示例读 `fread(STDIN)` 时，STDIN 来自 **`Swoole\Process` 的管道（`SOCK_STREAM`）**，不是 TTY：

```php
$proc = new Process(function ($p) {
    Co\run(function () use($p) {
        go(function() { co::sleep(0.05); echo "sleep\n"; });
        echo fread(STDIN, 1024);   // 这里 STDIN 是 Process 的管道
    });
}, true, SOCK_STREAM);
```

**结论**：`SWOOLE_HOOK_STDIO` 对**普通管道 / socket stream** 的 `fread` 能正确协程化；但对**真实终端（TTY/pty）的 STDIN，hook 可能不生效，`fread` 退化成真正的阻塞读**。此时读键协程会卡死整个调度器——`go()` 之后的主循环根本不会运行（本项目实测：加日志后主循环心跳从不打印，读键协程的 `fread` 永不返回）。

> 判别方法：在 `go()` 后、主循环前若主循环从未被调度（无心跳日志），即说明读键协程的 `fread(STDIN)` 没 yield。

---

## 3. 在协程里读 TTY / STDIN（本项目正确范式）

### 3.1 ✅ 推荐：`Coroutine::waitEvent` + 非阻塞 `fread`

`Swoole\Coroutine::waitEvent($fd, $events = SWOOLE_EVENT_READ, $timeout = -1)`（v4.5+，6.x 存在）：
- 把 fd 交给 EventLoop 等待可读事件，**协程让出**，fd 可读（或超时）后 resume。
- 参数 `$fd` 接受"任何可转化为 fd 的类型（Socket 对象、资源、int）"——**STDIN 这种 TTY 资源也行**（epoll 能 poll pty master）。
- 文档原话："同步阻塞的代码通过该 API 即可变为协程非阻塞"。

```php
// 读键协程（TTY 安全）
go(static function () use ($ch, $parser): void {
    while (true) {
        // 等 STDIN 可读；对 TTY/pty 也生效，协程让出调度器
        $ev = \Swoole\Coroutine::waitEvent(STDIN, SWOOLE_EVENT_READ, 1.0);
        if ($ev === false) {
            continue;                         // 超时，继续等
        }
        $bytes = fread(STDIN, 4096);          // 已知可读，不会阻塞
        if ($bytes === '' || $bytes === false) {
            break;                            // EOF（终端关闭）
        }
        $parser->advance($bytes, false);
        foreach ($parser->drain() as $e) {
            $ch->push($e);
        }
    }
});
```

要点：
- `waitEvent` 的 `$timeout` 既给读键"让出调度器"的机会，也兜底避免永久卡在 wait 上（EOF 后不再 resume）。
- 因为只在"确实可读"后才 `fread`，所以**不需要**依赖 `SWOOLE_HOOK_STDIO` 来把 `fread` 协程化——建议干脆**不要**开 `SWOOLE_HOOK_STDIO`，避免 2.1 的退化阻塞坑。开 `SWOOLE_HOOK_ALL` 时会顺带开 STDIO，需显式 `& ~SWOOLE_HOOK_STDIO`。

### 3.2 ❌ 不要用的旧写法

- `Swoole\Coroutine\System::fread(STDIN, ...)`：**6.0 已移除**（文档确认：v5.0 废弃、v6.0 移除）。
- 裸 `fread(STDIN)` + `SWOOLE_HOOK_STDIO`（本项目原写法）：TTY 上会退化阻塞，已验证卡死。

### 3.3 备选：`Swoole\Event::add(STDIN, $cb)`（reactor 回调，非协程上下文）

`Event::add` 能把任意 fd（含 STDIN/TTY）加入 reactor，可读时调 `$cb`。但回调运行在 **reactor 上下文、不在协程里**，在内$callback 里 `Channel::push` 一个 Event 对象虽可，但读键解析、状态管理都要绕开协程语义，复杂且易错（M0 曾走这条路失败）。**本项目优先用 3.1 的 `waitEvent`**。

### 3.4 备选：`Swoole\Coroutine\Socket::import(STDIN)` 后 `$socket->recv()`

`Socket` 可包任意 fd，`recv()` 是协程感知的。但 TTY 是否被 `Socket` 完全支持需实测，通用性与 3.1 相当或更弱，暂不作为首选。

---

## 4. Channel（协程间通信，必须用 Channel 而非全局变量）

`new Swoole\Coroutine\Channel($capacity)`，`$capacity >= 1`。

- `push(mixed $data, float $timeout = -1): bool`：满则 yield；成功 `true`，超时/已关闭 `false`。
- `pop(float $timeout = -1): mixed`：**空/超时/已关闭都返回 `false`（不是 `null`）**。`$timeout=-1` 永不超时。
- **重要警告（文档原文）："为避免产生歧义，请勿向通道中写入 `null` 和 `false`。"**
- 判超时看 `$channel->errCode`：`SWOOLE_CHANNEL_OK(0)` / `SWOOLE_CHANNEL_TIMEOUT(-1)` / `SWOOLE_CHANNEL_CLOSED(-2)`。

### 4.1 ⚠️ 本项目踩中的两个 Channel 大坑（实测）

**(a) `Channel::pop(0)` 的 `0` 超时 = 永久阻塞，不是非阻塞！**
本机实测：主循环里用 `$redraw->pop(0)` 想"非阻塞查一下后台重绘信号"，结果**主循环第一次 pop 超时后直接卡死在 `pop(0)` 上**，再也不回到 `$ch->pop()`，导致读键事件永远不被消费、`quit` 永不设置、进程 hang。
- Swoole 中 `pop()` 的 `$timeout`：`>0` 为等待秒数；`-1`（默认）为永不超时；**`0` 实测表现为永久阻塞（等价于一直等 push）**，并非"立即返回"。
- 要非阻塞地查信号：先 `$ch->isEmpty()` 判空，非空再 `$ch->pop(0.001)`（极小超时）消费。或读 `$ch->length()`。

**(b) `pop` 返回 `false`=超时，判据别用 `!== null`**
原代码 `if ($ev !== null)` 会把超时（`false`）误判成"收到事件"。正确：`if ($ev === false) { /* 超时 */ } else { /* 真实事件（Event 对象） */ }`。

### 4.2 本项目主循环的正确写法

```php
while (!$app->quit) {
    $ev = $ch->pop(0.05);
    if ($ev === false) {
        // 非阻塞查 R3 后台重绘信号：先 isEmpty 再极小超时 pop，绝不用 pop(0)
        if (!$redraw->isEmpty()) {
            $redraw->pop(0.001);
            $display->draw($app->render($display->viewportArea()));
        }
        continue;
    }
    $app->handle($ev, $display->viewportArea());
    $display->draw($app->render($display->viewportArea()));
}
```

---

## 5. 协程容器 / `go` / `defer` / `Coroutine\run` 的退出语义（本项目踩中）

- `Swoole\Coroutine\run($fn)`：进入容器、运行 `$fn`（顶层协程），**并等到容器内所有协程都结束才返回**。
- ⚠️ 因此：若你派生了"读键协程"之类的常驻子协程，它**必须是能退出的**（如 `while (!$app->quit)`）。否则即使主循环设了 `quit` 并退出，顶层协程因读键协程还在死循环而永远不返回 → 进程 hang。
- 本项目修正：读键协程用 `while (!$app->quit) { waitEvent(...); if 可读则读 }`，主循环退出设 `quit` 后，读键协程在下一个 waitEvent 超时（≤1s）后检查 `quit` 并 `break`，`Coroutine\run` 才能返回、进程干净退出。
- `Swoole\Coroutine\defer($cb)`：当前协程退出前执行（用于还原终端）。注册在协程容器内有效。

---

## 6. `Coroutine::waitEvent` 读 STDIN/TTY 的实测注意

- `waitEvent(STDIN, SWOOLE_EVENT_READ, $timeout)` 在本项目真实 pty 下**可用**：第一次有输入时返回 `512`（可读），后续无输入时超时返回 `false`（`errCode=SWOOLE_ERROR_CO_TIMEDOUT`）。
- 前提：STDIN 须处于**非阻塞**模式。本项目由 `Terminal::enableRawMode()`（php-tui）设置；若脱离 php-tui 直接用 `waitEvent(STDIN)`，需先 `stream_set_blocking(STDIN, false)`，否则 `waitEvent` 可能退化成阻塞、卡死调度器（隔离测试已复现：未设非阻塞时 `go(读键)` 直接阻塞、顶层协程的后续代码永不执行）。
- 不要依赖 `SWOOLE_HOOK_STDIO` 来协程化 TTY 上的 `fread`（见 §2.1）。

---

## 7. 协程编程须知（坑清单）

- `Swoole\Coroutine\run($fn)`：进入容器并执行 `$fn`（顶层协程）。
- `go($fn)` / `Coroutine::create($fn)`：派生子协程，立即运行到第一个 yield。
- `Swoole\Coroutine\defer($cb)`：当前协程退出前执行（用于还原终端、关 Channel）。注册在协程容器内有效。
- 退出的干净方式：设 `$app->quit` 让主循环自然退出，`defer` 自动还原终端；不要在协程里裸 `exit`（低版本 coredump，现版本抛 `Swoole\ExitException` 可被捕获）。

---

## 6. 协程编程须知（坑清单）

- 协程内**禁止用全局变量 / 静态变量存上下文**（并发错乱）；协程间通信只用 Channel。
- `try/catch` **必须在本协程内**捕获，不能跨协程。
- 魔术方法（`__get/__set`）内不得产生协程切换（PHP 内核 guard 位导致循环调用判断异常）。
- 多个协程**不要共用一个连接/资源**并交替读写。

---

## 7. 与本项目 `bin/tui.php` 的映射

| 需求 | 正确做法（基于本文档） |
| --- | --- |
| 读真实 pty 的键鼠 | `Coroutine::waitEvent(STDIN, SWOOLE_EVENT_READ, 1.0)` 后 `fread`；**不开** `SWOOLE_HOOK_STDIO` |
| 主循环驱动重绘 | `$ch->pop(0.05)`，`false` 即超时；后台完成信号用另一个 Channel `$redraw->pop(0)` 非阻塞查 |
| 退出时还原终端 | `Swoole\Coroutine\defer(fn() => restoreTerminal($term))` |
| 后台耗时任务（R3 demo） | `go()` 起子协程，`sleep` 后 `push` 到 `$redraw` |
| 回退分支（无 swoole） | 纯 `php-tui/term` 的 `BlockingTtyEventProvider`（`stream_select` 阻塞读）保持不变 |

> 验证手段：真实 pty 必须用 `tests/pty_run.php` / `tests/pty_drive.php` + 外层 `timeout` 兜底，**禁止裸跑** `bin/tui.php`（曾 `kill -9`）。

---

## 8. 子进程 / 命令执行的实测结论（M2，2026-08-28）

所有结论由 `tests/m2_probe_runner.php` 实测得出，修改前请重跑该探针。

### 8.1 `Swoole\Process` 不能在协程内 fork

`Swoole\Process->start()` 在 `Coroutine\run()` 内直接 Fatal：

```
Swoole\Error: must be forked outside the coroutine
```

协程外 `start()` 正常，`Process::wait(true)` 返回 `{"pid":..,"code":3,"signal":0}`。
本项目主循环跑在 `Swoole\Coroutine\run(fn() => start(true))` 里，**`start()` 整个函数体都在协程内**，
所以「预 fork 常驻 worker」的方案要把 fork 提到 `bin/tui.php` 顶层，代价（IPC 帧协议 + worker 生命周期
+ 僵尸回收）远超收益 —— M2 最终未采用，改用 `proc_open` + 非阻塞轮询。

### 8.2 `proc_close()` 的退出码会被 HOOK 改写 → 一律用 `proc_get_status()['exitcode']`

| 环境 | `exit 42` 后 `proc_close()` 返回 |
| --- | --- |
| 无 HOOK（原生） | `42` ✅ |
| `SWOOLE_HOOK_ALL & ~STDIO`（含 `HOOK_PROC`） | `0` ❌（连 42 都变 0；另一次实测为 `1792`=42<<8） |

`proc_get_status()['exitcode']` 在两种环境下都正确。中断场景 `['termsig']` 给信号号（SIGKILL→9）。

### 8.3 `SWOOLE_HOOK_FILE` 会污染终端画面（花屏）

`HOOK_FILE` 接管命令管道 fd 并注册进 reactor，`proc_close()` 后延迟释放再次 close，往 **stdout**
（不是 stderr）吐：

```
WARNING network::socket_free_defer(): close(8) failed, Error: Bad file descriptor[9]
```

在 TUI 里会直接打进 alternate screen 造成花屏。关掉 `HOOK_FILE` 后噪音 2 行 → 0。
关 `HOOK_PROC` 无效（噪音来自 FILE 而非 PROC）。

### 8.4 M2 最终 flags 与命令执行范式

```php
SWOOLE_HOOK_ALL & ~SWOOLE_HOOK_STDIO & ~SWOOLE_HOOK_FILE & ~SWOOLE_HOOK_PROC
```

- 关 `STDIO`：对真实 TTY 的 `fread` 不协程化（§2.1）。
- 关 `FILE`：消除 §8.3 的花屏噪音。
- 关 `PROC`：恢复 `proc_open/proc_close` 原生语义（§8.2），并解除「`proc_open` 只能在协程内调用」
  的约束（HOOK 接管时会报 `API must be called in the coroutine`），使 headless 测试能直接驱动 runner。

命令执行范式：**子进程 + 管道非阻塞 + 主循环每轮 `poll()` 排空**。
10 万行（5 万 stdout + 5 万 stderr）0.28s 收齐不死锁；主循环 stall 1.5s 只造成背压、不丢数据。
退出码一律取 `proc_get_status()['exitcode']`。

> 不需要协程读管道：子进程 + 非阻塞管道本身就提供了「多进程、不阻塞主循环」，
> 协程在这里只是换个读法，却带出 §8.1–8.3 三个坑。

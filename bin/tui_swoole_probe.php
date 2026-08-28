<?php
declare(strict_types=1);

/**
 * M1.5 探针：验证「Swoole 协程内 fread(STDIN) 在真实 pty 能读到键」。
 * 成功（3s 内读到任意字节）→ exit 0；否则 exit 1。
 *
 * 仅做最小终端初始化/还原，不渲染、不跑 App。由 tests/pty_probe.php 驱动。
 * 注意：Swoole 6.x 没有 Coroutine\System::fread，必须靠 SWOOLE_HOOK_STDIO
 *       hook 原生 fread，让协程内 fread(STDIN) 让出调度器。
 */

require __DIR__ . '/../vendor/autoload.php';

use PhpTui\Term\Terminal;
use PhpTui\Term\Actions;

if (!extension_loaded('swoole')) {
    fwrite(STDERR, "swoole extension not loaded\n");
    exit(1);
}

Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_STDIO);

$ok = Swoole\Coroutine\run(function (): bool {
    $term = Terminal::new();
    $term->enableRawMode();
    $term->queue(
        Actions::alternateScreenEnable(),
        Actions::enableMouseCapture(),
        Actions::cursorHide()
    );
    $term->flush();
    // 任何路径退出都干净还原终端
    Swoole\Coroutine\defer(static function () use ($term): void {
        $term->queue(
            Actions::alternateScreenDisable(),
            Actions::disableMouseCapture(),
            Actions::cursorShow()
        );
        $term->flush();
        $term->disableRawMode();
    });

    $done = new Swoole\Coroutine\Channel(1);
    $got = false;
    // 读键协程：整块读 STDIN（非单字节，避免 ESC 序列误判）
    go(static function () use ($done, &$got): void {
        $bytes = fread(STDIN, 4096);
        if (is_string($bytes) && $bytes !== '') {
            $got = true;
            $done->push(1);
        }
    });

    $signal = $done->pop(3.0); // 3s 内读到键即视为成功
    return $got && $signal !== false;
});

exit($ok ? 0 : 1);

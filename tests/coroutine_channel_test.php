<?php
declare(strict_types=1);

/**
 * R3 协程能力预留 headless 验证：在协程内起子协程跑「耗时 I/O」(sleep)，
 * 不阻塞主循环，并通过 Channel 把完成信号回写；主协程收到后更新 App 状态。
 * 验证 Swoole 协程通道可用，供 M2 命令执行 / M5 LLM SSE 复用同一机制。
 *
 * 运行：php tests/coroutine_channel_test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\App;

if (!extension_loaded('swoole')) {
    echo "[FAIL] swoole 扩展未装\n";
    exit(1);
}
Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_STDIO);

$ok = Swoole\Coroutine\run(function (): bool {
    $app = new App();
    $redraw = new Swoole\Coroutine\Channel(8);
    $done = false;

    // 子协程：模拟耗时 I/O（命令执行 / LLM 流式），完成后写共享态 + 发信号
    go(static function () use ($app, $redraw, &$done): void {
        Swoole\Coroutine::sleep(1);
        $app->message = '后台协程完成';
        $done = true;
        $redraw->push(1);
    });

    // 主协程：等待后台完成信号（不阻塞子协程运行）
    $signal = $redraw->pop(3.0);
    return $signal !== false && $done && $app->message === '后台协程完成';
});

echo $ok ? "[OK] R3 协程通道：子协程写共享态 + 主协程收到信号\n" : "[FAIL] R3 协程通道\n";
exit($ok ? 0 : 1);

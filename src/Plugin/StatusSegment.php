<?php
declare(strict_types=1);

namespace App\Plugin;

/**
 * 状态栏段值对象（插件扩展用）。语义对齐 StatusBarPanel 内部段：
 *  - key：稳定标识，供测试断言「丢了哪一项」；
 *  - text：渲染文本；
 *  - priority：丢弃优先级，越大越该保留；
 *  - order：显示顺序，越小越靠左。
 */
final class StatusSegment
{
    public function __construct(
        public string $key,
        public string $text,
        public int $priority = 50,
        public int $order = 100,
    ) {
    }
}

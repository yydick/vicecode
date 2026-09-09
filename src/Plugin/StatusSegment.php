<?php
declare(strict_types=1);

namespace App\Plugin;

/**
 * 状态栏段值对象（插件扩展用）。语义对齐 StatusBarPanel 内部段：
 *  - key：稳定标识，供测试断言「丢了哪一项」；
 *  - text：渲染文本；
 *  - priority：丢弃优先级，越大越该保留；
 *  - order：显示顺序，越小越靠左；
 *  - commandId：V1.1 新增，点击该段要执行的**局部**命令 id（null=不可点）。
 *
 * ⚠️ 新字段一律加在尾部并给默认值：V1 的老插件用的是位置参数
 * （如 `new StatusSegment('clock', $text, 55, 12)`），加在中间会破坏它们。
 */
final class StatusSegment
{
    public function __construct(
        public string $key,
        public string $text,
        public int $priority = 50,
        public int $order = 100,
        public ?string $commandId = null,
    ) {
    }
}

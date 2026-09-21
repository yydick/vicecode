<?php
declare(strict_types=1);

namespace App\Core;

/**
 * 一条补全候选（Tab 补全机制的原子单位）。
 *
 * `label` 是**显示**用的（可能带后缀标记，如目录的 `/`），`insert` 是**真正写回文本**的内容，
 * 两者分开的理由：显示可以带装饰、可以截断，而写回必须是干净的。接受补全时核心只替换
 * `insert`，不解析 `label` —— 这样插件/内建 provider 不必关心界面怎么画。
 */
final class CompletionItem
{
    public function __construct(
        /** 列表里显示的名字 */
        public readonly string $label,
        /** 接受后写回文本的内容（替换掉被补全的那段前缀） */
        public readonly string $insert,
        /** 次要说明（右侧灰字，可空） */
        public readonly string $detail = '',
    ) {
    }
}

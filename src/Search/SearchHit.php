<?php
declare(strict_types=1);

namespace App\Search;

/**
 * 单条搜索命中（来自 `grep -rn` 的一行：path:line:text）。
 *
 * line 是 1-based（grep 的行号语义）；跳转到编辑器时要 -1 换成 Buffer 的 0-based cursorRow。
 * text 已经过 Ansi::sanitize 清洗（去 ANSI / 制表符转空格 / 修非法 UTF-8），可直接渲染。
 */
final class SearchHit
{
    public function __construct(
        public string $path,
        public int $line,
        public string $text,
    ) {
    }
}

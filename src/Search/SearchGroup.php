<?php
declare(strict_types=1);

namespace App\Search;

/**
 * 同一文件的命中集合（VSCode 风格的分组展示单元）。
 *
 * 分组顺序按文件首次命中的先后（grep 输出顺序），不额外排序：
 * grep 的遍历顺序本身是稳定的，保留它能让用户对"结果为什么这么排"有直觉。
 */
final class SearchGroup
{
    /** @param SearchHit[] $hits */
    public function __construct(
        public string $path,
        public array $hits = [],
    ) {
    }

    public function count(): int
    {
        return count($this->hits);
    }
}

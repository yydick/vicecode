<?php
declare(strict_types=1);

namespace App\Search;

/**
 * 「可见行」：把分组结构摊平成一维列表，供渲染与点击命中反查**共用**。
 *
 * 为什么必须有这一层：结果按文件分组且可折叠，折叠后「屏幕行号」与「命中索引」
 * 不再是线性关系（第 3 行可能是第 1 个文件的 header，也可能是第 7 条命中）。
 * 若渲染和点击各自算一套映射，折叠状态一变就会点错行——这是分组列表最经典的 bug。
 * 统一由 SearchModel::buildVisibleRows() 现算现用，双方都只认这一份列表。
 */
final class SearchRow
{
    public const HEADER = 0;
    public const HIT = 1;

    public function __construct(
        public int $kind,
        public string $path,
        /** HEADER 行携带（渲染取 count()） */
        public ?SearchGroup $group = null,
        /** HIT 行携带（跳转取 line） */
        public ?SearchHit $hit = null,
    ) {
    }
}

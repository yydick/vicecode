<?php
declare(strict_types=1);

namespace App\Explorer;

/**
 * 资源管理器目录树（懒展开）。
 *
 * - 只在展开一个目录节点时才扫描其下一级（ensureChildren），大目录不会一次性阻塞。
 * - children 为 null 表示「尚未加载」；为空数组表示「空目录」。
 * - 渲染层用 visible() 做深度优先遍历，得到当前应显示的扁平节点列表。
 *
 * 注：M1 用同步 scandir（因为主循环是 php-tui/term 阻塞循环，无 Swoole 协程）。
 * 懒展开已把单次 IO 限制在「一个目录」，足以避免明显卡顿；真正的协程异步留到 M2/M3/M4。
 */
final class TreeNode
{
    public function __construct(
        public string $name,
        public string $path,
        public bool $isDir,
        public int $depth = 0,
        public bool $expanded = false,
        /** @var ?TreeNode[] */
        public ?array $children = null,
    ) {
    }

    public function ensureChildren(): void
    {
        if ($this->children !== null) {
            return;
        }
        if (!$this->isDir) {
            $this->children = [];
            return;
        }
        $this->children = self::scan($this->path, $this->depth + 1);
    }

    /** @return TreeNode[] */
    public static function scan(string $dir, int $depth): array
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            return [];
        }
        $dirs = [];
        $files = [];
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $full = $dir . DIRECTORY_SEPARATOR . $e;
            if (is_dir($full)) {
                $dirs[] = new self($e, $full, true, $depth);
            } else {
                $files[] = new self($e, $full, false, $depth);
            }
        }
        // 目录在前、按名排序；文件随后、按名排序
        usort($dirs, static fn (TreeNode $a, TreeNode $b) => strcmp($a->name, $b->name));
        usort($files, static fn (TreeNode $a, TreeNode $b) => strcmp($a->name, $b->name));
        return array_merge($dirs, $files);
    }
}

final class FileTree
{
    /** @var TreeNode[] */
    public array $roots;

    public function __construct(string $rootPath)
    {
        $this->roots = TreeNode::scan(rtrim($rootPath, DIRECTORY_SEPARATOR), 0);
    }

    /** 深度优先遍历，得到当前应展示的扁平节点（已展开目录才展开子级）。 @return TreeNode[] */
    public function visible(): array
    {
        $out = [];
        $walk = function (array $nodes) use (&$out, &$walk): void {
            foreach ($nodes as $n) {
                $out[] = $n;
                if ($n->isDir && $n->expanded && $n->children !== null) {
                    $walk($n->children);
                }
            }
        };
        $walk($this->roots);
        return $out;
    }
}

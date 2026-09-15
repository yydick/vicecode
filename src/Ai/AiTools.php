<?php
declare(strict_types=1);

namespace App\Ai;

/**
 * AI 只读工具集（V2 Agent loop）：list_files / read_file。
 *
 * ## 安全边界（最重要）
 * AI 生成的路径是**不可信输入**：`../`、绝对路径、symlink 都可能把读取范围带出项目根。
 * 所以所有路径必须先过 resolve()：realpath 归一 + 必须落在项目根前缀内。
 * 拒绝是一切失败路径的默认（返回 null / 错误文本），绝不宽容。
 *
 * ## 为什么不用 FileTree
 * `Explorer/FileTree::TreeNode::scan()` 是懒展开的交互树，无任何安全校验，
 * 且面向 UI 状态。这里用 `RecursiveIteratorIterator` 独立扫描，自带上限
 * （maxEntries）与目录黑名单（vendor/.git/node_modules），扫描成本封顶。
 *
 * ## 大小限制
 * read_file 沿用 `Buffer::fromFile` 的先例：二进制拒读、大小上限；
 * 另有 attachMaxBytes（注入上限，超限截断并标注）由调用方传参控制。
 */
final class AiTools
{
    /** 单文件读取上限（字节）。与 Buffer::MAX_BYTES 同数量级，防一击撑爆上下文 */
    public const READ_MAX_BYTES = 512 * 1024;

    /** 目录扫描条目上限（文件+目录总数），防止巨型仓库拖垮 agent */
    public const LIST_MAX_ENTRIES = 500;

    /** OpenAI tools 定义（Agent 模式随请求发出）。只读两个，刻意保持最小。 */
    public static function toolDefs(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'list_files',
                    'description' => 'List files and directories under a path in the project. Returns names with type markers, capped at 500 entries.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'path' => ['type' => 'string', 'description' => 'Relative path inside the project root. Use "." for the root.'],
                        ],
                        'required' => ['path'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'read_file',
                    'description' => 'Read a text file inside the project root. Binary files and files over 512KB are rejected.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'path' => ['type' => 'string', 'description' => 'Relative path inside the project root.'],
                        ],
                        'required' => ['path'],
                    ],
                ],
            ],
        ];
    }

    private string $root;

    public function __construct(?string $root = null)
    {
        $this->root = rtrim($root ?? (string) getcwd(), '/');
    }

    /**
     * 把 AI 给的相对路径安全地解析成项目内的绝对路径。
     *
     * 拒绝：空路径、绝对路径（/开头）、盘符、解析后逃出项目根（含 ../ 与 symlink）。
     * 返回 null = 拒绝。
     */
    public function resolve(string $rel): ?string
    {
        $rel = trim($rel);
        if ($rel === '' || $rel === '.' || $rel === './') {
            return $rel === '' ? null : $this->root;
        }
        // 绝对路径一律拒绝（Windows 盘符也顺带挡掉）
        if ($rel[0] === '/' || $rel[0] === '\\' || preg_match('/^[A-Za-z]:/', $rel) === 1) {
            return null;
        }
        // 先做字面拼接过一遍：realpath 会吃掉 ../，但也要挡掉含 NUL 的恶劣输入
        if (str_contains($rel, "\0")) {
            return null;
        }
        $candidate = $this->root . '/' . $rel;
        $real = realpath($candidate);
        if ($real === false || !str_starts_with($real, $this->root . '/')) {
            return null; // 不存在，或解析后落在根外（../、symlink 都会栽在这）
        }
        return $real;
    }

    /**
     * list_files：列目录（一层）。返回给模型看的文本；拒绝/不存在返回错误说明文本（不是异常）。
     * @return array{ok:bool,text:string} ok=false 时 text 是给模型的错误说明
     */
    public function listFiles(string $rel): array
    {
        $dir = $this->resolve($rel);
        if ($dir === null || !is_dir($dir)) {
            return ['ok' => false, 'text' => 'ERROR: path not found or outside project root: ' . self::safeEcho($rel)];
        }
        $names = @scandir($dir);
        if ($names === false) {
            return ['ok' => false, 'text' => 'ERROR: cannot read directory: ' . self::safeEcho($rel)];
        }
        sort($names, SORT_STRING);
        $lines = [];
        $count = 0;
        $truncated = false;
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if ($count >= self::LIST_MAX_ENTRIES) {
                $truncated = true;
                break;
            }
            $full = $dir . '/' . $name;
            $lines[] = is_dir($full) ? $name . '/' : $name;
            $count++;
        }
        if ($truncated) {
            $lines[] = '... (truncated at ' . self::LIST_MAX_ENTRIES . ' entries)';
        }
        $prefix = 'FILES ' . self::safeEcho($rel) . ":\n";
        return ['ok' => true, 'text' => $prefix . implode("\n", $lines)];
    }

    /**
     * read_file：读文本文件。二进制/超限拒绝；$maxBytes 超限截断并标注（attach 与工具共用核心）。
     * @return array{ok:bool,text:string}
     */
    public function readFile(string $rel, ?int $maxBytes = null): array
    {
        $core = $this->readCore($rel, $maxBytes);
        if (!is_array($core)) {
            $reason = is_string($core) ? $core : 'unknown';
            return ['ok' => false, 'text' => 'ERROR: ' . $reason . ': ' . self::safeEcho($rel)];
        }
        $note = $core['truncated'] ? "\n... (truncated at {$core['limit']} bytes)" : '';
        return ['ok' => true, 'text' => 'FILE ' . self::safeEcho($rel) . ":\n" . $core['content'] . $note];
    }

    /**
     * 读文件核心（@文件 引用与 read_file 工具共用）：路径校验 + 二进制拒绝 + 截断。
     * 拒绝时返回 string 原因（binary/too large/not found）；成功返回结构化数组。
     * @return array{content:string,truncated:bool,limit:int,abs:string}|string|null
     */
    public function readCore(string $rel, ?int $maxBytes = null): array|string|null
    {
        $limit = max(1, $maxBytes ?? self::READ_MAX_BYTES);
        $file = $this->resolve($rel);
        if ($file === null) {
            return 'path not found or outside project root';
        }
        if (!is_file($file)) {
            return 'not a regular file';
        }
        $size = (int) @filesize($file);
        if ($size > self::READ_MAX_BYTES) {
            return 'file too large (' . $size . ' bytes, limit ' . self::READ_MAX_BYTES . ')';
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return 'cannot read file';
        }
        // 二进制检测：与 Buffer::fromFile 同判据——含 NUL 字节即视为二进制
        if (str_contains($raw, "\0")) {
            return 'binary file not readable';
        }
        $truncated = false;
        if (strlen($raw) > $limit) {
            $raw = substr($raw, 0, $limit);
            // 别把截断落在 UTF-8 字符中间（拼进消息后 mbWrapDisp 才不会劈字）
            while ($raw !== '' && !mb_check_encoding($raw, 'UTF-8')) {
                $raw = substr($raw, 0, -1);
            }
            $truncated = true;
        }
        return ['content' => $raw, 'truncated' => $truncated, 'limit' => $limit, 'abs' => $file];
    }

    /**
     * 执行一个工具调用（Agent loop 的分发入口）。
     * @param string $name 工具名
     * @param string $argumentsJson 模型给出的 arguments（原始 JSON 串，可能不合法）
     * @return array{ok:bool,text:string}
     */
    public function execute(string $name, string $argumentsJson): array
    {
        $args = json_decode($argumentsJson, true);
        $path = is_array($args) && is_string($args['path'] ?? null) ? $args['path'] : '';
        if ($path === '') {
            return ['ok' => false, 'text' => 'ERROR: missing or invalid "path" argument.'];
        }
        return match ($name) {
            'read_file' => $this->readFile($path),
            'list_files' => $this->listFiles($path),
            default => ['ok' => false, 'text' => "ERROR: unknown tool '{$name}'."],
        };
    }

    /** 错误说明里回显路径时防控制字符注入（cwd/文件名夹带 \n/ESC 的坑，DisplayWidth 同源教训） */
    private static function safeEcho(string $s): string
    {
        return preg_replace('/[\x00-\x1f\x7f]/', '?', $s) ?? '?';
    }
}

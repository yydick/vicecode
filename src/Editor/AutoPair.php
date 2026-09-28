<?php
declare(strict_types=1);

namespace App\Editor;

/**
 * 自动配对的**纯决策**（不碰 Buffer，因此可以直接单测每一条规则）。
 *
 * 输入一个字符时只有三种结局，用 {@see self::plan()} 一次算清：
 *  - `SKIP` — 右边已经是同一个右符号 → 光标右移一格（`()` 中间再打 `)` 不会变成 `())`）；
 *  - `PAIR` — 打左符号 → 补上右符号并把光标夹在中间；
 *  - `PLAIN` — 什么都不做，交给普通插入。
 *
 * 配置来自 `.vicerc` 的 `editor.autoPairs`（每项两个字符），见 `ConfigStore::editorAutoPairs()`。
 */
final class AutoPair
{
    public const SKIP = 'skip';
    public const PAIR = 'pair';
    public const PLAIN = 'plain';

    /**
     * @param list<string> $pairs 每项两个字符：左 + 右（如 `'()'`、`'""'`）
     */
    public function __construct(private array $pairs)
    {
    }

    public function isEmpty(): bool
    {
        return $this->pairs === [];
    }

    /** @return list<string> */
    public function pairs(): array
    {
        return $this->pairs;
    }

    /** `$ch` 作为**左**符号时对应的整对；不是左符号返回 null */
    public function openPair(string $ch): ?string
    {
        foreach ($this->pairs as $p) {
            if (mb_substr($p, 0, 1) === $ch) {
                return $p;
            }
        }
        return null;
    }

    /** 引号类：左右是同一个字符（`""` / `''`），需要额外的"别跟在词后面"规则 */
    public static function isQuotePair(string $pair): bool
    {
        $a = mb_substr($pair, 0, 1);
        $b = mb_substr($pair, 1, 1);
        return $a !== '' && $a === $b;
    }

    /**
     * 输入 `$ch` 时的处置。
     *
     * @param string $before 光标前一个字符（行首传 ''）
     * @param string $after  光标后一个字符（行尾传 ''）
     */
    public function plan(string $ch, string $before, string $after): string
    {
        if ($this->pairs === []) {
            return self::PLAIN;
        }
        // ① 跳过：右边已经是同一个字符，且它是某个对的**右**符号。
        //    只认右符号 —— 否则打 `(` 时右边恰好也是 `(` 会被误判成"跳过"。
        if ($after === $ch && $this->isCloseChar($ch)) {
            return self::SKIP;
        }
        // ② 打左补右
        $pair = $this->openPair($ch);
        if ($pair === null) {
            return self::PLAIN;
        }
        // 引号紧跟在字母/数字/下划线之后不配对：don't / it's / 变量名' 这类场景
        if (self::isQuotePair($pair) && self::isWordChar($before)) {
            return self::PLAIN;
        }
        return self::PAIR;
    }

    /** 光标是否正好夹在一个**空对**中间（退格成对删用，两个字符合起来就是配置里的一对） */
    public function emptyPairAt(string $before, string $after): bool
    {
        return $before !== '' && $after !== '' && in_array($before . $after, $this->pairs, true);
    }

    private function isCloseChar(string $ch): bool
    {
        foreach ($this->pairs as $p) {
            if (mb_substr($p, 1, 1) === $ch) {
                return true;
            }
        }
        return false;
    }

    /** 词字符：字母 / 数字 / 下划线（判定"引号是否紧跟在一个词后面"） */
    private static function isWordChar(string $ch): bool
    {
        return $ch !== '' && preg_match('/^[\p{L}\p{N}_]$/u', $ch) === 1;
    }
}

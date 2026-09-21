<?php
declare(strict_types=1);

namespace App\Editor;

/**
 * 编辑器文本缓冲（行数组模型），纯逻辑、无 I/O 依赖，便于单元测试。
 *
 * - 光标以「字符」为单位（mb_* 处理多字节），渲染时再换算成显示列。
 * - 视口滚动：scrollTop（首可见行）、scrollLeft（首可见字符列），由渲染层按需修正。
 * - fromFile() 带超大文件 / 二进制保护；readOnly 时禁止编辑与保存。
 * - dirty 标记未保存改动；save() 用 file_put_contents 落盘（同步即可，M5 之前无协程需求）。
 */
final class Buffer
{
    /** @var string[] */
    public array $lines = [''];

    public int $cursorRow = 0;
    public int $cursorCol = 0;      // 字符单位
    public int $scrollTop = 0;
    public int $scrollLeft = 0;

    public bool $dirty = false;
    public bool $readOnly = false;
    public ?string $path = null;

    public int $maxLineNoWidth = 1;

    /**
     * **额外的编辑光标**（多光标）；主光标仍是上面那两个标量。
     *
     * 不变量（由 {@see self::setExtraCursors()} 维持）：
     *  ① **每行最多一个**光标 —— 刻意简化：同行多光标会让 Enter/退格在**同一行内**分裂出
     *     复杂的行列位移（一行里两个光标之间插换行要同时调整两边的行与列），
     *     而本功能的用途是"在多行上同时改"，按行一一对应正合适；
     *  ② 不含主光标所在的位置/行；③ 始终按行升序。
     *
     * 空数组 = 单光标（此时所有编辑路径与改造前**完全一致**）。
     * @var list<array{0:int,1:int}> [行, 字符列]
     */
    private array $extraCursors = [];

    /** 不可编辑时的提示：i18n key + 参数（由上层翻译） */
    public ?string $noticeKey = null;
    /** @var array<string,mixed> */
    public array $noticeParams = [];

    /** 高亮缓存：内容修订号（每次编辑/载入自增），用于让 App 侧按需重算高亮 */
    public int $rev = 0;
    /** @var ?array<int,?array<int,array{0:string,1:\PhpTui\Tui\Style\Style}>> 逐行高亮 Span（null=未高亮/不支持） */
    public ?array $hlLines = null;
    public ?int $hlRev = null;

    private const MAX_BYTES = 5_000_000;

    public static function empty(string $name = 'untitled'): self
    {
        $b = new self();
        $b->lines = [''];
        $b->path = $name;
        $b->recompute();
        return $b;
    }

    /** 从字符串内容建 Buffer（用于查看 git diff 等虚拟文档，readOnly） */
    public static function fromString(string $path, string $content, bool $readOnly = true): self
    {
        $b = new self();
        $b->path = $path;
        $b->readOnly = $readOnly;
        $c = str_replace("\r\n", "\n", $content);
        $c = rtrim($c, "\n");
        $b->lines = $c === '' ? [''] : explode("\n", $c);
        $b->recompute();
        return $b;
    }

    public static function fromFile(string $path): self
    {
        $b = new self();
        $b->path = $path;

        // ⚠️ 所有失败路径都必须**先判状态再读**，不能靠 @ 压警告：
        // TUI 跑在 alternate screen 里，PHP Warning 会直接写进屏幕把画面打花，
        // 而且把它压掉也解决不了「用户看到空文件却不知道为什么」。
        if (!is_file($path)) {
            $b->readOnly = true;
            $b->noticeKey = 'editor.missing';
            $b->noticeParams = ['path' => $path];
            $b->lines = [''];
            $b->recompute();
            return $b;
        }

        if (!is_readable($path)) {
            $b->readOnly = true;
            $b->noticeKey = 'editor.unreadable';
            $b->noticeParams = ['path' => $path];
            $b->lines = [''];
            $b->recompute();
            return $b;
        }

        // 能读但不可写（如 0444）：直接标只读。否则状态栏显示「编辑」，
        // 用户改半天按 Ctrl+S 才撞到权限错误——R1 要求编辑模式如实反映。
        if (!is_writable($path)) {
            $b->readOnly = true;
        }

        $size = filesize($path);
        if ($size > self::MAX_BYTES) {
            $b->readOnly = true;
            $b->noticeKey = 'editor.too_large';
            $b->noticeParams = ['size' => $size, 'limit' => self::MAX_BYTES];
            $b->lines = [''];
            $b->recompute();
            return $b;
        }

        if (self::isBinary($path)) {
            $b->readOnly = true;
            $b->noticeKey = 'editor.binary';
            $b->lines = [''];
            $b->recompute();
            return $b;
        }

        // 上面已确认 is_file + is_readable，这里理论上不会失败；
        // 但仍用「读前判状态 + 失败转提示」而不是裸调用，避免权限在两者之间被改掉时喷警告。
        $raw = is_readable($path) ? file_get_contents($path) : false;
        if ($raw === false) {
            $b->readOnly = true;
            $b->noticeKey = 'editor.unreadable';
            $b->noticeParams = ['path' => $path];
            $b->lines = [''];
            $b->recompute();
            return $b;
        }
        $content = str_replace("\r\n", "\n", $raw);
        $content = rtrim($content, "\n");
        $b->lines = $content === '' ? [''] : explode("\n", $content);
        $b->recompute();
        return $b;
    }

    public function save(): bool
    {
        if ($this->readOnly || $this->path === null) {
            return false;
        }
        $ok = @file_put_contents($this->path, implode("\n", $this->lines));
        if ($ok !== false) {
            $this->dirty = false;
            return true;
        }
        return false;
    }

    // ── 编辑操作（多字节安全） ──────────────────────────────

    public function insertChar(string $ch): void
    {
        if ($this->readOnly || $ch === '') {
            return;
        }
        $line = $this->currentLine();
        $before = mb_substr($line, 0, $this->cursorCol);
        $after = mb_substr($line, $this->cursorCol);
        $this->lines[$this->cursorRow] = $before . $ch . $after;
        $this->cursorCol += mb_strlen($ch);
        $this->dirty = true;
        $this->recompute();
    }

    public function insertNewline(): void
    {
        if ($this->readOnly) {
            return;
        }
        $line = $this->currentLine();
        $before = mb_substr($line, 0, $this->cursorCol);
        $after = mb_substr($line, $this->cursorCol);
        $this->lines[$this->cursorRow] = $before;
        array_splice($this->lines, $this->cursorRow + 1, 0, [$after]);
        $this->cursorRow++;
        $this->cursorCol = 0;
        $this->dirty = true;
        $this->recompute();
    }

    /**
     * 粘贴整段文本到光标处（多字节安全）。
     * 按 \r\n / \r / \n 拆行：第 0 段插在当前行光标处（光标随之后移），
     * 之后的每段先插入换行（到新行行首）再接上；空文本是 no-op。
     */
    public function insertText(string $text): void
    {
        if ($this->readOnly || $text === '') {
            return;
        }
        $segs = explode("\n", str_replace("\r\n", "\n", str_replace("\r", "\n", $text)));
        foreach ($segs as $i => $seg) {
            if ($i > 0) {
                $this->insertNewline();
            }
            $line = $this->currentLine();
            $before = mb_substr($line, 0, $this->cursorCol);
            $after = mb_substr($line, $this->cursorCol);
            $this->lines[$this->cursorRow] = $before . $seg . $after;
            $this->cursorCol += mb_strlen($seg);
        }
        $this->dirty = true;
        $this->recompute();
    }

    public function backspace(): void
    {
        if ($this->readOnly) {
            return;
        }
        if ($this->cursorCol > 0) {
            $line = $this->currentLine();
            $before = mb_substr($line, 0, $this->cursorCol - 1);
            $after = mb_substr($line, $this->cursorCol);
            $this->lines[$this->cursorRow] = $before . $after;
            $this->cursorCol--;
        } elseif ($this->cursorRow > 0) {
            $prevLen = mb_strlen($this->lines[$this->cursorRow - 1]);
            $this->lines[$this->cursorRow - 1] .= $this->currentLine();
            array_splice($this->lines, $this->cursorRow, 1);
            $this->cursorRow--;
            $this->cursorCol = $prevLen;
        } else {
            return;
        }
        $this->dirty = true;
        $this->recompute();
    }

    public function delete(): void
    {
        if ($this->readOnly) {
            return;
        }
        $line = $this->currentLine();
        if ($this->cursorCol < mb_strlen($line)) {
            $before = mb_substr($line, 0, $this->cursorCol);
            $after = mb_substr($line, $this->cursorCol + 1);
            $this->lines[$this->cursorRow] = $before . $after;
        } elseif ($this->cursorRow < count($this->lines) - 1) {
            $this->lines[$this->cursorRow] .= $this->lines[$this->cursorRow + 1];
            array_splice($this->lines, $this->cursorRow + 1, 1);
        } else {
            return;
        }
        $this->dirty = true;
        $this->recompute();
    }

    /**
     * 把当前行 `[start, end)` 这段字符替换成 `$text`（**同一行内**，不跨行），
     * 光标落在插入内容末尾。补全接受就是靠它：把 `@前缀` 换成 `@完整路径`。
     *
     * 越界一律夹紧（end 超长按行尾算、start > end 视为空区间），不抛异常 ——
     * 调用方（Tab 补全）拿到的下标来自实时文本，边界情况宁可不做也不崩。
     */
    public function replaceRange(int $start, int $end, string $text): void
    {
        if ($this->readOnly) {
            return;
        }
        $line = $this->currentLine();
        $len = mb_strlen($line);
        $start = max(0, min($start, $len));
        $end = max($start, min($end, $len));
        $this->lines[$this->cursorRow] = mb_substr($line, 0, $start) . $text . mb_substr($line, $end);
        $this->cursorCol = $start + mb_strlen($text);
        $this->dirty = true;
        $this->recompute();
    }

    /**
     * 插入一对符号并把光标夹在中间（自动配对用）。
     * 与「连调两次 insertChar」等价，但只重算一次。
     */
    public function insertPair(string $open, string $close): void
    {
        if ($this->readOnly || $open === '' || $close === '') {
            return;
        }
        $line = $this->currentLine();
        $this->lines[$this->cursorRow] = mb_substr($line, 0, $this->cursorCol)
            . $open . $close . mb_substr($line, $this->cursorCol);
        $this->cursorCol += mb_strlen($open);
        $this->dirty = true;
        $this->recompute();
    }

    /**
     * 把跨行的 `[li0:ch0, li1:ch1]` 这段替换成 `$text`（可含换行），光标落在替换内容末尾。
     * 行列都是**字符**下标，越界一律夹紧（不抛异常）。
     *
     * 与 `replaceRange()` 的分工：那个只在本行内动（Tab 补全替换 token），这个跨行
     * （选中包裹要处理多行选区）。
     */
    public function replaceLineRange(int $li0, int $ch0, int $li1, int $ch1, string $text): void
    {
        if ($this->readOnly) {
            return;
        }
        $n = count($this->lines);
        $li0 = max(0, min($li0, $n - 1));
        $li1 = max($li0, min($li1, $n - 1));
        $head = mb_substr($this->lines[$li0], 0, max(0, $ch0));
        $tail = mb_substr($this->lines[$li1], max(0, $ch1));
        array_splice($this->lines, $li0, $li1 - $li0 + 1, explode("\n", $head . $text . $tail));

        // 光标落在替换内容之后（= tail 之前）。按**字符**算行内偏移：
        // 先 explode 再取最后一段，避免用字节偏移配 mb_substr（多字节下会错位）。
        $parts = explode("\n", $head . $text);
        $this->cursorRow = $li0 + count($parts) - 1;
        $this->cursorCol = mb_strlen((string) end($parts));
        $this->dirty = true;
        $this->recompute();
    }

    /**
     * 反向缩进一行（Shift+Tab）：删掉行首最多 `$width` 个空格，或一个制表符。
     * 返回是否真的改了（行首没有空白时返回 false → 调用方可以不重绘）。
     */
    public function outdentLine(int $width = 4): bool
    {
        if ($this->readOnly) {
            return false;
        }
        $line = $this->currentLine();
        if ($line === '') {
            return false;
        }
        if ($line[0] === "\t") {
            $removed = 1;
        } else {
            $removed = 0;
            while ($removed < $width && ($line[$removed] ?? '') === ' ') {
                $removed++;
            }
        }
        if ($removed === 0) {
            return false;
        }
        $this->lines[$this->cursorRow] = mb_substr($line, $removed);
        // 光标跟着左移，但不越过行首（光标原本在缩进内部时落在 0）
        $this->cursorCol = max(0, $this->cursorCol - $removed);
        $this->dirty = true;
        $this->recompute();
        return true;
    }

    // ── 光标移动 ───────────────────────────────────────────

    public function moveLeft(): void
    {
        if ($this->cursorCol > 0) {
            $this->cursorCol--;
            return;
        }
        if ($this->cursorRow > 0) {
            // 行首继续左移 → 跳到上一行末尾（标准编辑器跨行光标移动）
            $this->cursorRow--;
            $this->cursorCol = mb_strlen($this->lines[$this->cursorRow]);
        }
    }

    public function moveRight(): void
    {
        $len = mb_strlen($this->currentLine());
        if ($this->cursorCol < $len) {
            $this->cursorCol++;
            return;
        }
        if ($this->cursorRow < count($this->lines) - 1) {
            // 行尾继续右移 → 跳到下一行开头（标准编辑器跨行光标移动）
            $this->cursorRow++;
            $this->cursorCol = 0;
        }
    }

    public function moveUp(): void
    {
        if ($this->cursorRow > 0) {
            $this->cursorRow--;
            $this->clampCol();
        }
    }

    public function moveDown(): void
    {
        if ($this->cursorRow < count($this->lines) - 1) {
            $this->cursorRow++;
            $this->clampCol();
        }
    }

    public function moveHome(): void
    {
        $this->cursorCol = 0;
    }

    public function moveEnd(): void
    {
        $this->cursorCol = mb_strlen($this->currentLine());
    }

    public function pageUp(int $h): void
    {
        $this->cursorRow = max(0, $this->cursorRow - max(1, $h));
        $this->clampCol();
    }

    public function pageDown(int $h): void
    {
        $this->cursorRow = min(count($this->lines) - 1, $this->cursorRow + max(1, $h));
        $this->clampCol();
    }

    public function currentLine(): string
    {
        return $this->lines[$this->cursorRow] ?? '';
    }

    private function clampCol(): void
    {
        $len = mb_strlen($this->currentLine());
        if ($this->cursorCol > $len) {
            $this->cursorCol = $len;
        }
        if ($this->cursorCol < 0) {
            $this->cursorCol = 0;
        }
    }

    public function recompute(): void
    {
        $this->rev++;
        $this->maxLineNoWidth = max(1, strlen((string) max(1, count($this->lines))));
    }

    // ── 多光标 ────────────────────────────────────────────

    public function hasMultipleCursors(): bool
    {
        return $this->extraCursors !== [];
    }

    /** @return list<array{0:int,1:int}> */
    public function extraCursors(): array
    {
        return $this->extraCursors;
    }

    /** 全部光标（主 + 额外），按行升序；顺带把每个都夹进合法范围（防外部直写行/列后越界） */
    public function allCursors(): array
    {
        $n = max(1, count($this->lines));
        // 主光标放在**前面**：下面按行排序是稳定的，于是"同一行同时有主光标和额外光标"时
        // 主光标胜出（见 editsAtEachCursor 的去重），与"用户刚把主光标移到这行"的直觉一致。
        $out = [];
        foreach (array_merge([[$this->cursorRow, $this->cursorCol]], $this->extraCursors) as [$r, $c]) {
            $r = max(0, min($r, $n - 1));
            $out[] = [$r, min(max(0, $c), mb_strlen($this->lines[$r] ?? ''))];
        }
        usort($out, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        return $out;
    }

    /**
     * 在 (row,col) 加一个额外光标。
     *
     * 返回 false 且**什么也不做**的情形：行号越界、该行已有光标、或就是主光标那一行 ——
     * 调用方（Alt+点击）据此判断有没有真的加上（用于决定要不要重绘）。
     */
    public function addCursorAt(int $row, int $col): bool
    {
        if ($row < 0 || $row >= count($this->lines)) {
            return false;
        }
        if ($row === $this->cursorRow) {
            return false;   // 主光标就在这一行 → 不改多光标（点击由普通路径移动主光标）
        }
        foreach ($this->extraCursors as [$r, $_]) {
            if ($r === $row) {
                return false;   // 每行最多一个
            }
        }
        $this->extraCursors[] = [$row, min(max(0, $col), mb_strlen($this->lines[$row]))];
        usort($this->extraCursors, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        return true;
    }

    /**
     * Alt+↑/↓：往**当前光标集合的上界/下界**之外扩一行（列沿用那个边界光标的列）。
     *
     * 越界（到顶/到底）返回 false 且不做任何事 —— 调用方**仍然消费**该键，
     * 免得主光标被顺手挪走、跑到已有光标的行上形成"一行两个光标"的歧义态。
     * 用"边界之外"而不是"相对主光标"：连按 Alt+↓ 会一行行往下长，不会因为中间某行已被占用而卡住。
     */
    public function addCursorRelative(int $deltaRows): bool
    {
        $cursors = $this->allCursors();
        $edge = $deltaRows < 0 ? $cursors[0] : $cursors[count($cursors) - 1];
        return $this->addCursorAt($edge[0] + $deltaRows, $edge[1]);
    }

    /** 取消多光标，只留主光标（Esc） */
    public function collapseCursors(): void
    {
        $this->extraCursors = [];
    }

    /**
     * 本行需要反显的列（字符下标）—— 渲染用。主光标 + 落在本行的额外光标。
     * @return list<int>
     */
    public function cursorColsOnLine(int $line): array
    {
        $cols = [];
        if ($this->cursorRow === $line) {
            $cols[] = $this->cursorCol;
        }
        foreach ($this->extraCursors as [$r, $c]) {
            if ($r === $line) {
                $cols[] = $c;
            }
        }
        return $cols;
    }

    /**
     * 在每个光标处各执行一次 `$op`，并把光标挪到编辑后的位置 —— **多光标编辑的唯一入口**。
     *
     * `$op` 内部只管按单光标操作（读写 `$this->cursorRow/$cursorCol`），不用管别的光标。
     *
     * 处理顺序：按 (行, 列) **降序** —— 先改下面的，上面那些编辑就不会让下面的行号失效。
     * 行数变化（Enter 插行 / 退格并行 / delete 并行）统一用 **`count($lines)` 的差值**补偿：
     * 每次编辑后，把「已处理过的、行号 >= 本次行」的光标整体平移 `$delta` 行。
     *
     * ⚠️ delta 必须取**行数**变化，不能取"光标自己的行号变化"：`delete()` 在行尾会并掉下一行，
     * 但光标行号不变（delta 却是 -1）—— 拿光标行号做补偿会漏掉这种情形。
     *
     * 单光标时直接调 `$op()`，行为与改造前完全一致（不带任何额外开销）。
     */
    public function editsAtEachCursor(callable $op): void
    {
        if ($this->readOnly) {
            return;
        }
        if ($this->extraCursors === []) {
            $op();
            return;
        }
        $main = [$this->cursorRow, $this->cursorCol];
        // 按行去重后再扇出：主光标可能被**普通方向键**移到了某个额外光标所在的行，
        // 那种情况若不去重会在同一行重复编辑同一个位置（"XXdef"）。allCursors 保证同行的主光标在前。
        $all = [];
        $seen = [];
        foreach ($this->allCursors() as $cur) {
            if (isset($seen[$cur[0]])) {
                continue;
            }
            $seen[$cur[0]] = true;
            $all[] = $cur;
        }
        usort($all, static fn (array $a, array $b): int => [$b[0], $b[1]] <=> [$a[0], $a[1]]);

        /** @var list<array{0:int,1:int,2:bool}> $done 已处理光标的新位置 + 是否原来是主光标 */
        $done = [];
        foreach ($all as [$r, $c]) {
            $this->cursorRow = $r;
            $this->cursorCol = $c;
            $linesBefore = count($this->lines);
            $op();
            $delta = count($this->lines) - $linesBefore;
            if ($delta !== 0) {
                foreach ($done as &$d) {
                    if ($d[0] >= $r) {
                        $d[0] += $delta;
                    }
                }
                unset($d);
            }
            $done[] = [$this->cursorRow, $this->cursorCol, $r === $main[0] && $c === $main[1]];
        }

        $mainNew = null;
        $extras = [];
        foreach ($done as [$r, $c, $isMain]) {
            if ($isMain) {
                $mainNew = [$r, $c];
            } else {
                $extras[] = [$r, $c];
            }
        }
        // 主光标的位置可能已被别的光标的编辑挪动过 → 用"原来是主光标"的那条结果
        [$this->cursorRow, $this->cursorCol] = $mainNew ?? ($done[0] ?? $main);
        $this->setExtraCursors($extras);
    }

    /** 规整额外光标：夹进范围、按行去重（不含主光标行）、按行升序 */
    private function setExtraCursors(array $list): void
    {
        $n = max(1, count($this->lines));
        $out = [];
        $seen = [$this->cursorRow => true];
        foreach ($list as [$r, $c]) {
            $r = max(0, min($r, $n - 1));
            if (isset($seen[$r])) {
                continue;   // 每行最多一个（跨行合并后可能出现重叠）
            }
            $seen[$r] = true;
            $out[] = [$r, min(max(0, $c), mb_strlen($this->lines[$r] ?? ''))];
        }
        usort($out, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $this->extraCursors = $out;
    }

    /**
     * 是否二进制。**只应在确认可读之后调用**——打不开时返回 true 会把「权限不足」
     * 误报成「二进制文件」（用户据此去查二进制问题，方向全错），而且 fopen 失败
     * 的 Warning 会喷进 alternate screen 打花画面。
     */
    private static function isBinary(string $path): bool
    {
        if (!is_readable($path)) {
            return false; // 读不了不是二进制；调用方已用 editor.unreadable 提示
        }
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }
        $sample = fread($fh, 1024);
        fclose($fh);
        if ($sample === false || $sample === '') {
            return false;
        }
        return str_contains($sample, "\0");
    }
}

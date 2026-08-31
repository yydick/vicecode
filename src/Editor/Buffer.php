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

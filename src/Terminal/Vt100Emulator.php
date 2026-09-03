<?php
declare(strict_types=1);

namespace App\Terminal;

use App\Text\DisplayWidth;

/**
 * VT100 / ANSI 终端仿真器核心（纯逻辑，无 I/O）。
 *
 * 接收子进程（真实 pty 上的 shell / vim / top / less 等）吐出的字节流，
 * 维护一个字符网格 + 滚动回退，供 TerminalPanel 取网格渲染。
 *
 * 设计取舍（v1 已知局限，见计划文档「风险与说明」）：
 *  - 覆盖 bash / vim / top / less / git 交互绝大多数场景；不保证 100% xterm。
 *  - 宽字符（CJK 等）按显示宽度占 2 列：左格放字符、右格标记 continuation 置空，
 *    渲染时跳过 continuation，视觉上「字符 + 一格空白」，可读但不完美。
 *  - 多字节 UTF-8 在块边界被劈开时，残字节暂存 pending，下一块拼回（避免乱码）。
 */
final class Vt100Emulator
{
    /** 单格状态 */
    public const FLAG_BOLD = 1;
    public const FLAG_DIM = 2;
    public const FLAG_ITALIC = 4;
    public const FLAG_UNDERLINE = 8;
    public const FLAG_REVERSE = 16;

    /** 滚动回退上限（行） */
    public const SCROLLBACK_MAX = 2000;

    private int $cols;
    private int $rows;

    /** 当前活动屏（主屏或交替屏，指针切换） */
    private array $screen;
    /** 交替屏（1049/1047 进入时 swap） */
    private array $altScreen;
    private bool $usingAlt = false;

    /** 回退环形缓冲：list<list<Cell>>，最旧在前 */
    private array $scrollback = [];

    private int $cx = 0;
    private int $cy = 0;
    private int $savedCx = 0;
    private int $savedCy = 0;
    private int $savedFlags = 0;
    private int $savedFg = -1;
    private int $savedBg = -1;

    /** 滚动区 [top, bottom]（含端点） */
    private int $top = 0;
    private int $bottom;

    private bool $autowrap = true;
    private bool $cursorVisible = true;
    private bool $originMode = false;

    /** 当前画笔属性（新写字符继承） */
    private int $penFg = -1;
    private int $penBg = -1;
    private int $penFlags = 0;

    /** 跨块残字节缓存 */
    private string $pending = '';

    public function __construct(int $cols, int $rows)
    {
        $this->cols = max(1, $cols);
        $this->rows = max(1, $rows);
        $this->bottom = $this->rows - 1;
        $this->screen = $this->blankGrid($this->cols, $this->rows);
        $this->altScreen = $this->blankGrid($this->cols, $this->rows);
    }

    // ── 网格构造 ──────────────────────────────────────

    /** @return list<list<Cell>> */
    private function blankGrid(int $cols, int $rows): array
    {
        $grid = [];
        for ($y = 0; $y < $rows; $y++) {
            $row = [];
            for ($x = 0; $x < $cols; $x++) {
                $row[] = new Cell();
            }
            $grid[] = $row;
        }
        return $grid;
    }

    // ── 写入入口 ──────────────────────────────────────

    public function write(string $bytes): void
    {
        if ($bytes === '') {
            return;
        }
        $data = $this->pending . $bytes;
        $this->pending = '';
        $i = 0;
        $len = strlen($data);
        while ($i < $len) {
            $c = $data[$i];
            if ($c === "\x1b") {
                $i = $this->parseEscape($data, $i, $len);
                continue;
            }
            $o = ord($c);
            if ($o < 0x20) {
                $this->control($c);
                $i++;
                continue;
            }
            // 可打印（含 UTF-8 多字节）。从首字节解出「一个」完整码点（1~4 字节）。
            $clen = $this->utf8CharLen($o);
            if ($clen === 0) {
                // 非法首字节（如孤立的后续字节）：按单字节吞掉，避免死循环
                $this->putChar($c);
                $i++;
                continue;
            }
            if ($i + $clen > $len) {
                // 本块内不完整：残字节暂存，等下一块拼回
                $this->pending = substr($data, $i);
                return;
            }
            $char = substr($data, $i, $clen);
            $this->putChar($char);
            $i += $clen;
        }
    }

    // ── 控制字符 ──────────────────────────────────────

    private function control(string $c): void
    {
        switch ($c) {
            case "\r":
                $this->cx = 0;
                break;
            case "\n":
                $this->lineFeed();
                break;
            case "\t":
                $stop = intdiv($this->cx, 8) * 8 + 8;
                $this->cx = min($this->cols - 1, $stop);
                break;
            case "\b":
                $this->cx = max(0, $this->cx - 1);
                break;
            case "\x07": // BEL
            default:
                break;
        }
    }

    private function lineFeed(): void
    {
        if ($this->cy >= $this->bottom) {
            $this->scrollUp($this->top, $this->bottom);
        } elseif ($this->cy < $this->rows - 1) {
            $this->cy++;
        }
    }

    /**
     * 把字符写入当前光标格，继承画笔属性，按显示宽度推进光标（宽字符右格置空）。
     */
    private function putChar(string $ch): void
    {
        $w = DisplayWidth::dispWidth($ch);
        if ($w < 1) {
            $w = 1;
        }

        // autowrap：光标已在末列时先换行
        if ($this->autowrap && $this->cx >= $this->cols) {
            $this->cx = 0;
            $this->lineFeed();
        }
        $this->clampCursor();

        $row = &$this->activeScreen();
        $cell = $row[$this->cy][$this->cx];
        $cell->ch = $ch;
        $cell->fg = $this->penFg;
        $cell->bg = $this->penBg;
        $cell->flags = $this->penFlags;
        $cell->wide = false;

        if ($w >= 2 && $this->cx + 1 < $this->cols) {
            $next = $row[$this->cy][$this->cx + 1];
            $next->ch = '';
            $next->wide = true;
            $next->fg = $this->penFg;
            $next->bg = $this->penBg;
            $next->flags = $this->penFlags;
        }

        $this->cx += $w;
    }

    /**
     * 由 UTF-8 首字节判定该码点占用字节数（1~4）；非法首字节返回 0。
     */
    private function utf8CharLen(int $o): int
    {
        if ($o < 0x80) {
            return 1;
        }
        if ($o >= 0xC0 && $o <= 0xDF) {
            return 2;
        }
        if ($o >= 0xE0 && $o <= 0xEF) {
            return 3;
        }
        if ($o >= 0xF0 && $o <= 0xF7) {
            return 4;
        }
        return 0;
    }

    private function clampCursor(): void
    {
        if ($this->cx < 0) {
            $this->cx = 0;
        }
        if ($this->cx > $this->cols) {
            $this->cx = $this->cols;
        }
        if ($this->cy < 0) {
            $this->cy = 0;
        }
        if ($this->cy > $this->rows - 1) {
            $this->cy = $this->rows - 1;
        }
    }

    // ── 转义序列解析 ──────────────────────────────────

    /**
     * @return int 解析后新的偏移（指向转义序列之后的字节）
     */
    private function parseEscape(string $s, int $i, int $len): int
    {
        // $s[$i] === "\x1b"
        if ($i + 1 >= $len) {
            return $len; // 残留孤立 ESC，丢弃
        }
        $next = $s[$i + 1];
        if ($next === '[') {
            return $this->parseCsi($s, $i + 2, $len);
        }
        if ($next === ']') {
            return $this->parseOsc($s, $i + 2, $len);
        }
        if ($next === '(' || $next === ')') {
            return $i + 3; // 字符集选择，跳过后续一个字节
        }
        if ($next === '7') {
            $this->saveCursor();
            return $i + 2;
        }
        if ($next === '8') {
            $this->restoreCursor();
            return $i + 2;
        }
        if ($next === 'D') {
            $this->index();
            return $i + 2;
        }
        if ($next === 'M') {
            $this->reverseIndex();
            return $i + 2;
        }
        if ($next === 'E') {
            $this->cx = 0;
            $this->index();
            return $i + 2;
        }
        if ($next === 'c') {
            $this->reset();
            return $i + 2;
        }
        // 其余（= > 小键盘模式等）忽略，吃掉 ESC + 下一字节
        return $i + 2;
    }

    private function parseOsc(string $s, int $i, int $len): int
    {
        // OSC：读到 BEL(\x07) 或 ST(\x1b\\) 结束
        while ($i < $len) {
            $c = $s[$i];
            if ($c === "\x07") {
                return $i + 1;
            }
            if ($c === "\x1b" && $i + 1 < $len && $s[$i + 1] === '\\') {
                return $i + 2;
            }
            $i++;
        }
        return $len;
    }

    private function parseCsi(string $s, int $i, int $len): int
    {
        $params = '';
        while ($i < $len) {
            $o = ord($s[$i]);
            if ($o >= 0x30 && $o <= 0x3f) { // 参数字节
                $params .= $s[$i];
                $i++;
                continue;
            }
            if ($o >= 0x20 && $o <= 0x2f) { // 中间字节（忽略）
                $i++;
                continue;
            }
            if ($o >= 0x40 && $o <= 0x7e) { // final
                $this->dispatchCsi($params, $s[$i]);
                return $i + 1;
            }
            return $i; // 非法，停止
        }
        return $len; // 不完整，丢弃
    }

    private function dispatchCsi(string $params, string $final): void
    {
        $private = false;
        if (str_starts_with($params, '?')) {
            $private = true;
            $params = substr($params, 1);
        }
        $nums = $params === '' ? [] : array_map(
            static fn($p) => $p === '' ? 0 : (int) $p,
            explode(';', $params)
        );
        $n0 = $nums[0] ?? 0;
        $n1 = $nums[1] ?? 0;

        switch ($final) {
            case 'A': // CUU
                $this->cy = max(0, $this->cy - max(1, $n0));
                break;
            case 'B': // CUD
                $this->cy = min($this->bottom, $this->cy + max(1, $n0));
                break;
            case 'C': // CUF
                $this->cx = min($this->cols, $this->cx + max(1, $n0));
                break;
            case 'D': // CUB
                $this->cx = max(0, $this->cx - max(1, $n0));
                break;
            case 'E': // CNL
                $this->cx = 0;
                $this->cy = min($this->bottom, $this->cy + max(1, $n0));
                break;
            case 'F': // CPL
                $this->cx = 0;
                $this->cy = max(0, $this->cy - max(1, $n0));
                break;
            case 'G': // CHA
                $this->cx = $this->clampCol($n0 - 1);
                break;
            case 'd': // VPA
                $this->cy = $this->clampRow($n0 - 1);
                break;
            case 'H':
            case 'f': // CUP / HVP
                $row = $n0 === 0 ? 1 : $n0;
                $col = $n1 === 0 ? 1 : $n1;
                $this->cy = $this->clampRow($row - 1 + ($this->originMode ? $this->top : 0));
                $this->cx = $this->clampCol($col - 1);
                break;
            case 'J': // ED
                $this->eraseDisplay($n0);
                break;
            case 'K': // EL
                $this->eraseLine($n0);
                break;
            case 'X': // ECH
                $this->eraseChars(max(1, $n0));
                break;
            case 'L': // IL
                $this->insertLines(max(1, $n0));
                break;
            case 'M': // DL
                $this->deleteLines(max(1, $n0));
                break;
            case 'S': // SU
                $this->scrollUpRegion($this->top, $this->bottom, max(1, $n0));
                break;
            case 'T': // SD
                $this->scrollDownRegion($this->top, $this->bottom, max(1, $n0));
                break;
            case 'P': // DCH
                $this->deleteChars(max(1, $n0));
                break;
            case '@': // ICH
                $this->insertChars(max(1, $n0));
                break;
            case 'm': // SGR
                $this->applySgr($nums);
                break;
            case 'r': // DECSTBM
                if (!$private) {
                    $t = $n0 === 0 ? 0 : $n0 - 1;
                    $b = $n1 === 0 ? $this->rows - 1 : $n1 - 1;
                    if ($t < $b && $b < $this->rows) {
                        $this->top = $t;
                        $this->bottom = $b;
                        $this->cx = 0;
                        $this->cy = $this->originMode ? $t : 0;
                    }
                }
                break;
            case 'h':
            case 'l':
                if ($private) {
                    $this->applyPrivateMode($nums, $final === 'h');
                }
                break;
            case 's': // 保存光标（ANSI.SYS）
                $this->saveCursor();
                break;
            case 'u': // 恢复光标
                $this->restoreCursor();
                break;
        }
    }

    private function clampCol(int $x): int
    {
        return max(0, min($this->cols - 1, $x));
    }

    private function clampRow(int $y): int
    {
        return max(0, min($this->rows - 1, $y));
    }

    // ── SGR / 模式 ────────────────────────────────────

    /** @param list<int> $nums */
    private function applySgr(array $nums): void
    {
        if ($nums === []) {
            $nums = [0];
        }
        $i = 0;
        while ($i < count($nums)) {
            $code = $nums[$i];
            switch ($code) {
                case 0:
                    $this->penFg = -1;
                    $this->penBg = -1;
                    $this->penFlags = 0;
                    break;
                case 1: $this->penFlags |= self::FLAG_BOLD; break;
                case 2: $this->penFlags |= self::FLAG_DIM; break;
                case 3: $this->penFlags |= self::FLAG_ITALIC; break;
                case 4: $this->penFlags |= self::FLAG_UNDERLINE; break;
                case 7: $this->penFlags |= self::FLAG_REVERSE; break;
                case 22: $this->penFlags &= ~(self::FLAG_BOLD | self::FLAG_DIM); break;
                case 23: $this->penFlags &= ~self::FLAG_ITALIC; break;
                case 24: $this->penFlags &= ~self::FLAG_UNDERLINE; break;
                case 27: $this->penFlags &= ~self::FLAG_REVERSE; break;
                case 30: case 31: case 32: case 33: case 34: case 35: case 36: case 37:
                    $this->penFg = $code - 30;
                    break;
                case 38:
                    $r = $this->parseExtendedColor($nums, $i);
                    if ($r !== null) {
                        $this->penFg = $r[0];
                        $i = $r[1];
                    }
                    break;
                case 39: $this->penFg = -1; break;
                case 40: case 41: case 42: case 43: case 44: case 45: case 46: case 47:
                    $this->penBg = $code - 40;
                    break;
                case 48:
                    $r = $this->parseExtendedColor($nums, $i);
                    if ($r !== null) {
                        $this->penBg = $r[0];
                        $i = $r[1];
                    }
                    break;
                case 49: $this->penBg = -1; break;
                case 90: case 91: case 92: case 93: case 94: case 95: case 96: case 97:
                    $this->penFg = $code - 90 + 8;
                    break;
                case 100: case 101: case 102: case 103: case 104: case 105: case 106: case 107:
                    $this->penBg = $code - 100 + 8;
                    break;
            }
            $i++;
        }
    }

    /**
     * 解析 38/48 后续的扩展颜色（;5;n 256 色 或 ;2;r;g;b）。
     * @param list<int> $nums
     * @return array{0:int,1:int}|null  [colorIndex, newIndex]
     */
    private function parseExtendedColor(array $nums, int $i): ?array
    {
        if (!isset($nums[$i + 1])) {
            return null;
        }
        $mode = $nums[$i + 1];
        if ($mode === 5 && isset($nums[$i + 2])) {
            return [$nums[$i + 2], $i + 2];
        }
        if ($mode === 2 && isset($nums[$i + 4])) {
            $r = $nums[$i + 2];
            $g = $nums[$i + 3];
            $b = $nums[$i + 4];
            return [self::rgbToIndex($r, $g, $b), $i + 4];
        }
        return null;
    }

    /** 把 RGB 折叠成一个稳定的索引（仅供内部调色板区分；渲染时再解回 RGB）。 */
    private static function rgbToIndex(int $r, int $g, int $b): int
    {
        // 用 256 调色板的「保留区」之外不可达的编码：以 1000 + 24bit 表示真彩色。
        return 1000000 + (($r & 0xff) << 16) + (($g & 0xff) << 8) + ($b & 0xff);
    }

    private function applyPrivateMode(array $nums, bool $set): void
    {
        foreach ($nums as $m) {
            switch ($m) {
                case 7:
                    $this->autowrap = $set;
                    break;
                case 6:
                    $this->originMode = $set;
                    $this->cx = 0;
                    $this->cy = $set ? $this->top : 0;
                    break;
                case 25:
                    $this->cursorVisible = $set;
                    break;
                case 47:
                case 1047:
                case 1049:
                    if ($set) {
                        if ($m === 1049 || $m === 1048) {
                            $this->saveCursor();
                        }
                        $this->enterAltScreen();
                    } else {
                        $this->leaveAltScreen();
                        if ($m === 1049 || $m === 1048) {
                            $this->restoreCursor();
                        }
                    }
                    break;
                default:
                    // 1000/1002/1006 鼠标等：忽略
                    break;
            }
        }
    }

    private function enterAltScreen(): void
    {
        if ($this->usingAlt) {
            return;
        }
        $this->usingAlt = true;
        // 交替屏默认清空
        $this->altScreen = $this->blankGrid($this->cols, $this->rows);
        $this->cx = 0;
        $this->cy = 0;
    }

    private function leaveAltScreen(): void
    {
        $this->usingAlt = false;
        $this->cx = 0;
        $this->cy = 0;
    }

    private function saveCursor(): void
    {
        $this->savedCx = $this->cx;
        $this->savedCy = $this->cy;
        $this->savedFlags = $this->penFlags;
        $this->savedFg = $this->penFg;
        $this->savedBg = $this->penBg;
    }

    private function restoreCursor(): void
    {
        $this->cx = min($this->cols - 1, max(0, $this->savedCx));
        $this->cy = min($this->rows - 1, max(0, $this->savedCy));
        $this->penFlags = $this->savedFlags;
        $this->penFg = $this->savedFg;
        $this->penBg = $this->savedBg;
    }

    private function reset(): void
    {
        $this->penFg = -1;
        $this->penBg = -1;
        $this->penFlags = 0;
        $this->cx = 0;
        $this->cy = 0;
        $this->top = 0;
        $this->bottom = $this->rows - 1;
        $this->autowrap = true;
        $this->cursorVisible = true;
        $this->originMode = false;
        $this->screen = $this->blankGrid($this->cols, $this->rows);
        $this->scrollback = [];
    }

    // ── 擦除 / 滚动 ───────────────────────────────────

    private function eraseDisplay(int $mode): void
    {
        if ($mode === 2 || $mode === 3) {
            $this->screen = $this->blankGrid($this->cols, $this->rows);
            $this->scrollback = [];
            return;
        }
        $row = &$this->activeScreen();
        if ($mode === 0) {
            // 光标到屏尾
            for ($x = $this->cx; $x < $this->cols; $x++) {
                $row[$this->cy][$x] = new Cell();
            }
            for ($y = $this->cy + 1; $y < $this->rows; $y++) {
                $row[$y] = $this->blankRow();
            }
        } elseif ($mode === 1) {
            for ($y = 0; $y < $this->cy; $y++) {
                $row[$y] = $this->blankRow();
            }
            for ($x = 0; $x <= $this->cx; $x++) {
                $row[$this->cy][$x] = new Cell();
            }
        }
    }

    private function eraseLine(int $mode): void
    {
        $row = &$this->activeScreen();
        if ($mode === 2) {
            $row[$this->cy] = $this->blankRow();
            return;
        }
        if ($mode === 1) {
            for ($x = 0; $x <= $this->cx; $x++) {
                $row[$this->cy][$x] = new Cell();
            }
            return;
        }
        for ($x = $this->cx; $x < $this->cols; $x++) {
            $row[$this->cy][$x] = new Cell();
        }
    }

    private function eraseChars(int $n): void
    {
        $row = &$this->activeScreen();
        for ($x = $this->cx; $x < min($this->cols, $this->cx + $n); $x++) {
            $row[$this->cy][$x] = new Cell();
        }
    }

    private function deleteChars(int $n): void
    {
        $row = &$this->activeScreen();
        $r = &$row[$this->cy];
        $rest = array_slice($r, $this->cx + $n);
        $keep = array_slice($r, 0, $this->cx);
        $this->replaceRow($r, array_merge($keep, $rest, $this->blankCells($n)));
    }

    private function insertChars(int $n): void
    {
        $row = &$this->activeScreen();
        $r = &$row[$this->cy];
        $keep = array_slice($r, 0, $this->cx);
        $rest = array_slice($r, $this->cx);
        $this->replaceRow($r, array_merge($keep, $this->blankCells($n), $rest));
    }

    private function insertLines(int $n): void
    {
        if ($this->cy < $this->top || $this->cy > $this->bottom) {
            return;
        }
        $scr = &$this->activeScreen();
        $seg = array_slice($scr, $this->top, $this->bottom - $this->top + 1);
        $idx = $this->cy - $this->top;
        $before = array_slice($seg, 0, $idx);
        $after = array_slice($seg, $idx);
        $blanks = [];
        for ($k = 0; $k < $n; $k++) {
            $blanks[] = $this->blankRow();
        }
        $newSeg = array_slice(array_merge($before, $blanks, $after), 0, count($seg));
        array_splice($scr, $this->top, count($seg), $newSeg);
    }

    private function deleteLines(int $n): void
    {
        if ($this->cy < $this->top || $this->cy > $this->bottom) {
            return;
        }
        $scr = &$this->activeScreen();
        $seg = array_slice($scr, $this->top, $this->bottom - $this->top + 1);
        $idx = $this->cy - $this->top;
        $before = array_slice($seg, 0, $idx);
        $after = array_slice($seg, $idx + $n);
        $blanks = [];
        for ($k = 0; $k < $n; $k++) {
            $blanks[] = $this->blankRow();
        }
        $newSeg = array_slice(array_merge($before, $after, $blanks), 0, count($seg));
        array_splice($scr, $this->top, count($seg), $newSeg);
    }

    private function scrollUp(int $top, int $bottom): void
    {
        $this->scrollUpRegion($top, $bottom, 1);
    }

    private function scrollUpRegion(int $top, int $bottom, int $n): void
    {
        $scr = &$this->activeScreen();
        for ($k = 0; $k < $n; $k++) {
            $scrolled = $scr[$top];
            array_splice($scr, $top, 1);
            array_splice($scr, $bottom, 0, [$this->blankRow()]);
            if ($top === 0) {
                $this->pushScrollback($scrolled);
            }
        }
    }

    private function scrollDownRegion(int $top, int $bottom, int $n): void
    {
        $scr = &$this->activeScreen();
        for ($k = 0; $k < $n; $k++) {
            array_splice($scr, $bottom, 0, [$this->blankRow()]);
            array_splice($scr, $top, 1);
        }
    }

    private function index(): void
    {
        if ($this->cy >= $this->bottom) {
            $this->scrollUp($this->top, $this->bottom);
        } elseif ($this->cy < $this->rows - 1) {
            $this->cy++;
        }
    }

    private function reverseIndex(): void
    {
        if ($this->cy <= $this->top) {
            $this->scrollDownRegion($this->top, $this->bottom, 1);
        } elseif ($this->cy > 0) {
            $this->cy--;
        }
    }

    private function pushScrollback(array $row): void
    {
        $this->scrollback[] = $row;
        $over = count($this->scrollback) - self::SCROLLBACK_MAX;
        if ($over > 0) {
            array_splice($this->scrollback, 0, $over);
        }
    }

    // ── 网格行 / 格工具 ───────────────────────────────

    private function &activeScreen(): array
    {
        if ($this->usingAlt) {
            return $this->altScreen;
        }
        return $this->screen;
    }

    /** @return list<Cell> */
    private function blankRow(): array
    {
        $row = [];
        for ($x = 0; $x < $this->cols; $x++) {
            $row[] = new Cell();
        }
        return $row;
    }

    /** @return list<Cell> */
    private function blankCells(int $n): array
    {
        $row = [];
        for ($x = 0; $x < $n; $x++) {
            $row[] = new Cell();
        }
        return $row;
    }

    /**
     * 用新行替换当前行（保持引用）。
     * @param list<Cell> $r
     * @param list<Cell> $new
     */
    private function replaceRow(array &$r, array $new): void
    {
        // 直接重建引用内容
        $r = $new;
    }

    // ── 尺寸变化 ──────────────────────────────────────

    /**
     * 重建屏（保留左上角内容尽量迁移），重置光标不越界。
     */
    public function resize(int $cols, int $rows): void
    {
        $cols = max(1, $cols);
        $rows = max(1, $rows);
        if ($cols === $this->cols && $rows === $this->rows) {
            return;
        }
        $old = $this->screen;
        $this->screen = $this->blankGrid($cols, $rows);
        foreach ($old as $y => $row) {
            if ($y >= $rows) {
                break;
            }
            foreach ($row as $x => $cell) {
                if ($x >= $cols) {
                    break;
                }
                $this->screen[$y][$x] = clone $cell;
            }
        }
        $oldAlt = $this->altScreen;
        $this->altScreen = $this->blankGrid($cols, $rows);
        foreach ($oldAlt as $y => $row) {
            if ($y >= $rows) {
                break;
            }
            foreach ($row as $x => $cell) {
                if ($x >= $cols) {
                    break;
                }
                $this->altScreen[$y][$x] = clone $cell;
            }
        }
        $this->cols = $cols;
        $this->rows = $rows;
        $this->bottom = $rows - 1;
        $this->top = min($this->top, $this->bottom);
        $this->clampCursor();
    }

    // ── 取渲染网格 ────────────────────────────────────

    /**
     * 返回当前应显示的行（屏或回退片段）+ 光标位置 + 可见性。
     *
     * @return array{
     *     lines: list<list<Cell>>,
     *     cursor: ?array{x:int,y:int},
     *     cursorVisible: bool
     * }
     */
    public function gridForRender(int $rows, int $scrollbackOffset): array
    {
        $rows = max(1, $rows);
        $active = $this->activeScreen();
        if ($this->usingAlt) {
            return [
                'lines' => $this->sliceRows($active, 0, $rows),
                'cursor' => $this->cursorVisible
                    ? ['x' => min($this->cx, $this->cols - 1), 'y' => min($this->cy, $rows - 1)]
                    : null,
                'cursorVisible' => $this->cursorVisible,
            ];
        }

        $combined = array_merge($this->scrollback, $active);
        $total = count($combined);
        $start = max(0, $total - $rows - max(0, $scrollbackOffset));
        $lines = [];
        for ($i = $start; $i < min($total, $start + $rows); $i++) {
            $lines[] = $combined[$i];
        }
        while (count($lines) < $rows) {
            $lines[] = $this->blankRow();
        }

        $cursor = null;
        $absCursor = count($this->scrollback) + $this->cy;
        $onScreen = $absCursor >= $start && $absCursor < $start + $rows;
        if ($this->cursorVisible && $onScreen && $scrollbackOffset <= 0) {
            $cursor = [
                'x' => min($this->cx, $this->cols - 1),
                'y' => $absCursor - $start,
            ];
        }
        return [
            'lines' => $lines,
            'cursor' => $cursor,
            'cursorVisible' => $this->cursorVisible && $scrollbackOffset <= 0,
        ];
    }

    /** 回退可用行数（供上层钳制滚动偏移） */
    public function scrollbackSize(): int
    {
        return count($this->scrollback);
    }

    // ── 会话持久化：导出 / 导入纯文本 ───────────────────

    /**
     * 导出当前会话的纯文本快照（滚动历史 + 主屏尾 N 行），用于退出时落盘。
     *
     * 设计取舍（见计划文档「风险与说明」）：
     *  - 始终取**主屏** `$screen`，即使当前在交替屏（vim 等全屏 UI）也忽略交替屏——
     *    交替屏是瞬态 UI，持久化它会变成冻结垃圾；主屏退出那一刻的快照才值得保留。
     *  - 跳过宽字符右占位格（wide===true），行尾空白 rtrim，行以 `\n` 连接。
     *  - 不含颜色（v1 纯文本）。
     *
     * @param int $maxLines 最多保留的尾行数（受 SCROLLBACK_MAX 约束，无上限传 0）
     */
    public function exportText(int $maxLines = self::SCROLLBACK_MAX): string
    {
        $combined = array_merge($this->scrollback, $this->screen);
        if ($maxLines > 0 && count($combined) > $maxLines) {
            $combined = array_slice($combined, -$maxLines);
        }
        $lines = [];
        foreach ($combined as $row) {
            $lines[] = $this->rowToText($row);
        }
        return implode("\n", $lines);
    }

    /**
     * 从纯文本快照重建会话内容（恢复时灌入）。
     *
     * 过程：先清空（保留主屏语义、丢交替屏与残字节、光标归零），再逐行
     * `$this->write($line . "\r\n")`（复用仿真器自带滚动/回退逻辑，超屏高自动入 scrollback）。
     * 写入前先剥离文本中的转义序列，避免把快照里的残留控制字节当指令解析。
     * 光标自然落到末行，等待后续新 shell 提示符接在快照之后。
     */
    public function importText(string $text): void
    {
        // 清空：保留主屏语义，丢弃交替屏与残字节，光标归零
        $this->scrollback = [];
        $this->screen = $this->blankGrid($this->cols, $this->rows);
        $this->usingAlt = false;
        $this->pending = '';
        $this->cx = 0;
        $this->cy = 0;

        // 剥掉可能残留的转义序列与裸控制字符（保留 \n \r \t），
        // 避免把快照当成指令解析；最后兜底清掉任何孤立 ESC。
        // 用 # 作定界符，避免字符类里的 / 与 / 定界符冲突。
        $clean = preg_replace(
            '#\x1b(?:\[[0-9;?]*[ -/]*[@-~]|\([AB0]|\)[AB0]|[=>])|[\x00-\x08\x0b\x0c\x0e-\x1f]#',
            '',
            $text
        );
        $clean = is_string($clean) ? str_replace("\x1b", '', $clean) : '';

        $lines = explode("\n", $clean);
        foreach ($lines as $line) {
            $this->write($line . "\r\n");
        }
    }

    /** 单行网格 → 纯文本（跳过宽字符右占位格、行尾去白） */
    private function rowToText(array $row): string
    {
        $s = '';
        foreach ($row as $cell) {
            if ($cell->wide === true) {
                continue; // 宽字符右占位格
            }
            $s .= $cell->ch;
        }
        return rtrim($s);
    }

    /** @param list<list<Cell>> $grid */
    private function sliceRows(array $grid, int $start, int $rows): array
    {
        $out = [];
        for ($i = $start; $i < min(count($grid), $start + $rows); $i++) {
            $out[] = $grid[$i];
        }
        while (count($out) < $rows) {
            $out[] = $this->blankRow();
        }
        return $out;
    }
}

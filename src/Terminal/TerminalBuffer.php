<?php
declare(strict_types=1);

namespace App\Terminal;

/**
 * 终端输出缓冲：把命令输出的任意字节块切成带 stderr 标记的行，供渲染层取视口。
 *
 * - 块边界不保证落在换行上，故用 $tail 攒未终止的半行；跨流时先强制断行，
 *   避免把 stderr 半行和 stdout 半行拼成一行（那样着色会错）。
 * - 上限 MAX_LINES 行，超出丢弃最旧的（长输出刷屏时不涨内存）。
 * - sanitize() 处理命令输出里的脏字节：CR/CRLF、制表符、C0 控制符、非法 UTF-8。
 *   ANSI 转义序列（\033[...m）属于 C0 范畴，一并剔除：我们不解析颜色，
 *   留在行里会让 php-tui 的显示宽度算错。
 */
final class TerminalBuffer
{
    /** @var list<array{text:string, err:bool}> */
    private array $lines = [];

    private string $tail = '';

    private bool $tailErr = false;

    public const MAX_LINES = 2000;

    /**
     * 追加一块输出。
     */
    public function append(string $bytes, bool $err): void
    {
        if ($bytes === '') {
            return;
        }
        // 半行还没结束就换流：先把旧半行按原流结算，避免串色
        if ($this->tail !== '' && $this->tailErr !== $err) {
            $this->push($this->tail, $this->tailErr);
            $this->tail = '';
        }
        $this->tailErr = $err;

        $text = $this->tail . $this->sanitize($bytes);
        $parts = explode("\n", $text);
        $this->tail = (string) array_pop($parts);   // 最后一段可能还没换行
        foreach ($parts as $line) {
            $this->push($line, $err);
        }
    }

    /**
     * 把未终止的半行结算成一行（命令结束时调用，否则最后一行永远不显示）。
     */
    public function flushTail(): void
    {
        if ($this->tail !== '') {
            $this->push($this->tail, $this->tailErr);
            $this->tail = '';
        }
    }

    public function clear(): void
    {
        $this->lines = [];
        $this->tail = '';
    }

    public function count(): int
    {
        return count($this->lines);
    }

    /**
     * 取一屏可见行。
     *
     * @return list<array{text:string, err:bool}>
     */
    public function slice(int $offset, int $height): array
    {
        if ($height <= 0 || $offset < 0) {
            return [];
        }
        return array_slice($this->lines, $offset, $height);
    }

    /**
     * @return list<array{text:string, err:bool}>
     */
    public function all(): array
    {
        return $this->lines;
    }

    private function push(string $text, bool $err): void
    {
        $this->lines[] = ['text' => $text, 'err' => $err];
        $over = count($this->lines) - self::MAX_LINES;
        if ($over > 0) {
            array_splice($this->lines, 0, $over);
        }
    }

    private function sanitize(string $s): string
    {
        // 先整段移除 ANSI 序列再剔控制符，否则 `ls --color` 会留下 "[31mfoo[0m" 这种残渣
        $s = (string) preg_replace('/\x1B\[[0-9;?]*[ -\/]*[@-~]/', '', $s);          // CSI（颜色/光标）
        $s = (string) preg_replace('/\x1B\][^\x07\x1B]*(?:\x07|\x1B\\\\)/', '', $s); // OSC（窗口标题等）
        $s = (string) preg_replace('/\x1B[@-Z\\\\-_]/', '', $s);                     // 其余两字符转义
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        $s = str_replace("\t", '    ', $s);
        // 剔除除 \n 外的 C0 控制符与 DEL
        $s = (string) preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/', '', $s);
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        }
        return $s;
    }
}

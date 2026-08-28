<?php
declare(strict_types=1);

/**
 * 共享：阻塞式事件源（examples 共用）。
 *
 * php-tui/term 默认事件源是非阻塞的——StreamReader::tty() 里设置了
 * stream_set_blocking(false)，没有按键时 next() 立刻返回 null，
 * 主循环会“画一帧就退出”（闪一下就回来，完全无法交互）。
 *
 * 修复：注入一个用 stream_select 阻塞等到按键的 EventProvider。
 * 用法（见各 example）：
 *   require __DIR__ . '/lib.php';
 *   $term = Terminal::new(eventProvider: new BlockingTtyEventProvider());
 * 之后 $term->events()->next() 就会真正阻塞等待输入。
 */

use PhpTui\Term\Event;
use PhpTui\Term\EventProvider;
use PhpTui\Term\EventParser;

final class BlockingTtyEventProvider implements EventProvider
{
    private EventParser $parser;

    /** @var Event[] */
    private array $buffer = [];

    public function __construct(private $stream = STDIN)
    {
        $this->parser = EventParser::new();
        stream_set_blocking($this->stream, true); // 确保阻塞读取
    }

    public function next(): ?Event
    {
        while ($e = array_shift($this->buffer)) {
            return $e;
        }
        $r = [$this->stream];
        $w = $e = [];
        $n = @stream_select($r, $w, $e, null); // null = 永久阻塞直到有输入
        if ($n === false || $n < 1) {
            return null;
        }
        $bytes = fread($this->stream, 4096);
        if ($bytes === '' || $bytes === false) {
            return null; // EOF（终端关闭）
        }
        $this->parser->advance($bytes, false);
        foreach ($this->parser->drain() as $ev) {
            $this->buffer[] = $ev;
        }
        return $this->next();
    }
}

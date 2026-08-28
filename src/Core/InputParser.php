<?php
declare(strict_types=1);

namespace App\Core;

use PhpTui\Term\Event;
use PhpTui\Term\EventParser;

/**
 * 把 Swoole 协程读到的原始字节喂给 php-tui/term 的 EventParser，
 * 解析出键鼠事件。EventParser 内部已支持方向键、功能键、字符键以及
 * SGR 鼠标转义序列（\e[<...M / \e[<...m）。
 */
final class InputParser
{
    private EventParser $parser;

    public function __construct()
    {
        $this->parser = EventParser::new();
    }

    /**
     * @return Event[]
     */
    public function feed(string $bytes): array
    {
        // more=false：交互 stdin 每次 fread 的字节通常已经是完整转义序列；
        // 即便被拆分，EventParser 也会在内部 buffer 中暂存不完整的序列。
        $this->parser->advance($bytes, false);

        return $this->parser->drain();
    }
}

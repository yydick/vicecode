<?php
declare(strict_types=1);

namespace App\Editor;

use Highlight\Highlighter as Scivo;
use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Style\Modifier;
use PhpTui\Tui\Style\Style;

/**
 * 语法高亮适配器：委托给三方库 scrivo/highlight.php（highlight.js 的 PHP 移植，支持 185 种语言）。
 *
 * 设计：
 * - 高亮以「整文件」为单位（scrivo 需要完整上下文，跨行字符串/块注释/heredoc 才能正确分词），
 *   因此 highlight() 接收全部代码，输出 HTML；本适配器把 HTML 解析成扁平 token 流，
 *   再按 `\n` 切成「每行一组 [text, Style]」。App 侧按 Buffer 修订号缓存，编辑后才重算。
 * - 多字节安全：scrivo 输出为实体编码（&lt; 等），解析时用 html_entity_decode 还原；
 *   渲染侧（App）按字素推进，CJK/emoji 不被劈开。
 * - 不支持的语言（langFor 返回 null）或解析失败 → 返回 null，渲染走默认纯文本。
 */
final class Highlighter
{
    /** 扩展名 → scrivo 语言 id */
    private const MAP = [
        'php' => 'php',
        'json' => 'json',
        'md' => 'markdown',
        'markdown' => 'markdown',
        'js' => 'javascript',
        'ts' => 'typescript',
        'jsx' => 'xml',
        'html' => 'xml',
        'htm' => 'xml',
        'css' => 'css',
        'xml' => 'xml',
        'sql' => 'sql',
        'sh' => 'bash',
        'bash' => 'bash',
        'yml' => 'yaml',
        'yaml' => 'yaml',
    ];

    private static ?Scivo $engine = null;

    public static function langFor(string $path): ?string
    {
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        return self::MAP[$ext] ?? null;
    }

    /**
     * 高亮整文件代码，返回按行分组的 Span 列表；失败/不支持返回 null。
     * @param string[] $lines
     * @return array<int,?array<int,array{0:string,1:Style}>>|null
     */
    public static function highlightLines(array $lines, ?string $lang): ?array
    {
        if ($lang === null) {
            return null;
        }
        $code = implode("\n", $lines);
        try {
            $html = (self::engine())->highlight($lang, $code)->value;
        } catch (\Throwable $e) {
            return null;
        }
        $flat = self::parseHtml($html);   // 扁平 [text, class|null]
        return self::splitByLines($flat);
    }

    private static function engine(): Scivo
    {
        if (self::$engine === null) {
            self::$engine = new Scivo(); // 构造时注册全部语言
        }
        return self::$engine;
    }

    /**
     * 字节级解析 scrivo 的 HTML（标签为 ASCII，文本可能含实体），返回扁平 token 流。
     * @return array<int,array{0:string,1:?string}>
     */
    private static function parseHtml(string $html): array
    {
        $out = [];
        $stack = [];          // 当前 class 栈（span 可嵌套）
        $buf = '';
        $n = strlen($html);
        $i = 0;
        $flush = function () use (&$out, &$buf, &$stack): void {
            if ($buf !== '') {
                $out[] = [html_entity_decode($buf, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $stack ? end($stack) : null];
                $buf = '';
            }
        };
        while ($i < $n) {
            if ($html[$i] === '<') {
                if (strncmp($html, '<span class="hljs-', 18) === 0) {
                    $m = [];
                    if (preg_match('/<span class="hljs-([^"]+)">/', $html, $m, 0, $i)) {
                        $flush();
                        $stack[] = $m[1];
                        $i += strlen($m[0]);
                        continue;
                    }
                } elseif (substr($html, $i, 7) === '</span>') {
                    $flush();
                    if ($stack !== []) {
                        array_pop($stack);
                    }
                    $i += 7;
                    continue;
                }
                // 其它标签（理论不存在）→ 当文本跳过 '<'
                $buf .= '<';
                $i++;
                continue;
            }
            $buf .= $html[$i];
            $i++;
        }
        $flush();
        return $out;
    }

    /**
     * 扁平 token 流按 `\n` 切成每行分组；跨行 token 的同 class 延续到下一行。
     * @param array<int,array{0:string,1:?string}> $flat
     * @return array<int,array<int,array{0:string,1:Style}>>
     */
    private static function splitByLines(array $flat): array
    {
        $lines = [[]];
        $cur = 0;
        foreach ($flat as [$text, $class]) {
            $style = self::styleFor($class);
            $parts = explode("\n", $text);
            foreach ($parts as $j => $seg) {
                if ($j > 0) {
                    $cur++;
                    $lines[$cur] = [];
                }
                if ($seg !== '') {
                    $lines[$cur][] = [$seg, $style];
                }
            }
        }
        return $lines;
    }

    /** hljs class → 颜色（默认白）；命中关键词加粗。 */
    private static function styleFor(?string $class): Style
    {
        static $map = [
            'comment' => [AnsiColor::DarkGray, false],
            'meta' => [AnsiColor::Gray, false],
            'keyword' => [AnsiColor::Yellow, true],
            'built_in' => [AnsiColor::LightBlue, false],
            'type' => [AnsiColor::LightBlue, false],
            'class' => [AnsiColor::LightBlue, false],
            'title' => [AnsiColor::LightBlue, false],
            'title.function_' => [AnsiColor::LightBlue, false],
            'function' => [AnsiColor::LightBlue, false],
            'params' => [AnsiColor::Magenta, false],
            'variable' => [AnsiColor::Magenta, false],
            'attribute' => [AnsiColor::Magenta, false],
            'property' => [AnsiColor::Magenta, false],
            'symbol' => [AnsiColor::Magenta, false],
            'string' => [AnsiColor::Green, false],
            'attr' => [AnsiColor::Green, false],
            'meta-string' => [AnsiColor::Green, false],
            'number' => [AnsiColor::Cyan, false],
            'literal' => [AnsiColor::Magenta, false],
            'tag' => [AnsiColor::Red, false],
            'name' => [AnsiColor::Red, false],
            'link' => [AnsiColor::Cyan, false],
            'emphasis' => [AnsiColor::Yellow, true],
            'strong' => [AnsiColor::Yellow, true],
            'section' => [AnsiColor::Blue, true],
        ];
        if ($class === null || !isset($map[$class])) {
            return Style::default()->fg(AnsiColor::White);
        }
        [$color, $bold] = $map[$class];
        $s = Style::default()->fg($color);
        if ($bold) {
            $s = $s->addModifier(Modifier::BOLD);
        }
        return $s;
    }
}

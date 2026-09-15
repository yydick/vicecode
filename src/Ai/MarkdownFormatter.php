<?php
declare(strict_types=1);

namespace App\Ai;

use App\Core\Theme;
use App\Editor\Highlighter;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\AbstractInline;
use League\CommonMark\Node\Inline\Emphasis;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Strong;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Parser\MarkdownParser;
use PhpTui\Tui\Style\Style;

/**
 * AI 回复/用户消息的 Markdown → TUI span 渲染（V2）。
 *
 * ## 选型：league/commonmark 解析 + 自写 TUI 渲染
 * 通用能力优先三方库（项目偏好）：commonmark 是 PHP 生态最成熟的 Markdown 解析器。
 * 它只负责**解析成 AST**；渲染成终端 span 是我们自己的事（HTML 渲染器对 TUI 无意义），
 * 样式取自 Theme（mdHeading/mdCode/mdQuote/mdLink），围栏代码块复用编辑器的 Highlighter。
 *
 * ## 输出形状：逻辑行（每行 = span 数组，行内不含 \n）
 * 软换行产出**独立逻辑行**（不折成空格）：终端聊天里 AI/用户常靠单换行排列表格和代码，
 * 折成空格会毁掉排版（ai_unit 的 60 行滚动用例正是这么发现的）。块级元素各占一段。
 * 软换行交给 `DisplayWidth::spanWrapDisp`（拼接不变量），本类只产出逻辑行。
 *
 * ## 命名空间坑（v2）
 * Heading/FencedCode/BlockQuote/ListBlock/ListItem/ThematicBreak/IndentedCode/Code 都在
 * `Extension\CommonMark\Node\*` 下，`Node\Block` 下只有 Paragraph 等核心节点——
 * import 错了 instanceof 永远不中，节点静默掉进兜底分支（实际踩过，输出全乱）。
 *
 * ## 性能契约
 * 本类**每次调用都全量解析**（无内部缓存）。流式中的消息禁止走这里——每个 token 全量
 * parse 会卡帧（AiPanel 只在消息定稿后调用并按 hash 缓存，见 AiPanel::buildLinesWithMap）。
 */
final class MarkdownFormatter
{
    private MarkdownParser $parser;

    public function __construct(private Theme $theme)
    {
        $env = new Environment([]);
        $env->addExtension(new CommonMarkCoreExtension());
        $env->addExtension(new AutolinkExtension());
        $this->parser = new MarkdownParser($env);
    }

    /**
     * Markdown → 逻辑行。解析失败降级为「整段原文一行」（不让坏输入炸掉 UI）。
     * @return list<list<array{0:string,1:Style}>>
     */
    public function format(string $markdown): array
    {
        try {
            $doc = $this->parser->parse($markdown);
        } catch (\Throwable) {
            return [[[$markdown, Style::default()]]];
        }
        $out = [];
        foreach ($doc->children() as $block) {
            $this->renderBlock($block, $out, 0);
        }
        if ($out === []) {
            $out[] = [];
        }
        return $out;
    }

    // ── 块级 ─────────────────────────────────────────

    /** @param list<list<array{0:string,1:Style}>> $out */
    private function renderBlock(object $node, array &$out, int $depth): void
    {
        if ($node instanceof FencedCode || $node instanceof IndentedCode) {
            $lang = null;
            if ($node instanceof FencedCode) {
                // info 串可能是 "php title=..."，语言取第一个词
                $first = trim((string) $node->getInfo());
                $lang = $first === '' ? null : (explode(' ', $first)[0] ?: null);
            }
            $codeLines = explode("\n", rtrim($node->getLiteral(), "\n"));
            if ($codeLines === ['']) {
                $codeLines = [];
            }
            $hl = Highlighter::highlightLines($codeLines, $lang, $this->theme);
            foreach ($codeLines as $k => $cl) {
                $spans = $hl[$k] ?? [[$cl, Style::default()]];
                $out[] = $this->indentSpans($spans, $depth + 1);
            }
            return;
        }

        if ($node instanceof Heading) {
            $level = max(1, min(3, $node->getLevel()));
            $style = $this->theme->style('mdHeading')->bold();
            $lines = [[]];
            $this->renderInlines($node->children(), $lines, $style);
            foreach ($lines as $k => $spans) {
                $out[] = $k === 0
                    ? [[$this->indentText($depth) . str_repeat('#', $level) . ' ', $style], ...$spans]
                    : $spans;
            }
            return;
        }

        if ($node instanceof BlockQuote) {
            $style = $this->theme->style('mdQuote');
            $pre = $this->indentText($depth) . '│ ';
            // ⚠️ BlockQuote 的子节点是**块级**（Paragraph 之外还可能是代码块 / 嵌套列表 /
            // 标题）。只按行内展开（renderInlines($child->children())）会让非 Paragraph 的
            // 子节点内容**静默消失**——它们的 children() 为空（代码在 getLiteral()，列表在
            // ListItem 里）。实测 `> ```php …` 与 `> - a\n> - b` 都整段丢失。
            foreach ($node->children() as $child) {
                if ($child instanceof Paragraph) {
                    // 段落走行内展开，正文沿用引用色（既有语义，别改）
                    $lines = [[]];
                    $this->renderInlines($child->children(), $lines, $style);
                    foreach ($lines as $spans) {
                        $out[] = [[$pre, $style], ...$spans];
                    }
                    continue;
                }
                // 其余块级子节点：递归渲染后逐行挂引用前缀，不丢内容
                $tmp = [];
                $this->renderBlock($child, $tmp, $depth);
                foreach ($tmp as $spans) {
                    $out[] = [[$pre, $style], ...$spans];
                }
            }
            return;
        }

        if ($node instanceof ListBlock) {
            $data = $node->getListData();
            // delimiter 非空 = 有序列表（'.' / ')'）；bulletChar = 无序
            $ordered = $data->delimiter !== null;
            $num = $ordered ? max(1, $data->start ?? 1) : null;
            foreach ($node->children() as $item) {
                if ($item instanceof ListItem) {
                    $this->renderListItem($item, $out, $depth, $num);
                    if ($num !== null) {
                        $num++;
                    }
                }
            }
            return;
        }

        if ($node instanceof ThematicBreak) {
            $out[] = [[$this->indentText($depth) . '────────────', Style::default()]];
            return;
        }

        if ($node instanceof Paragraph) {
            $lines = [[]];
            $this->renderInlines($node->children(), $lines, Style::default());
            foreach ($lines as $spans) {
                $out[] = $this->indentSpans($spans, $depth);
            }
            return;
        }

        // 未知块级（HtmlBlock 等）：取文本子树按 dim 文本渲染，不丢内容
        $lines = [[]];
        $this->renderInlines($node->children(), $lines, $this->theme->style('aiDim'));
        foreach ($lines as $spans) {
            if ($spans !== []) {
                $out[] = $this->indentSpans($spans, $depth);
            }
        }
    }

    /** @param list<list<array{0:string,1:Style}>> $out */
    private function renderListItem(ListItem $item, array &$out, int $depth, ?int $num = null): void
    {
        $indent = $this->indentText($depth);
        $marker = $num === null ? '• ' : ($num . '. ');
        $lead = $indent . $marker;                             // 首行前缀
        $cont = $indent . str_repeat(' ', mb_strwidth($marker)); // 续行与首行文字对齐
        $everLed = false;
        foreach ($item->children() as $child) {
            if ($child instanceof ListBlock) {
                $this->renderBlock($child, $out, $depth + 1); // 嵌套列表（自带更深缩进）
                $everLed = true;
                continue;
            }
            // ⚠️ 列表项的子节点也是**块级**：除 Paragraph 外还可能是代码块 / 引用 / 标题。
            // 原实现直接 renderInlines($child->children())，对代码块（内容在 getLiteral()、
            // children() 为空）与引用（子节点是块）会**静默丢内容**；实测
            // `- 步骤\n\n  ```php…` 的代码块整块消失。改为统一走 renderBlock（depth=0，
            // 缩进由本方法用 lead/cont 给），并保留「首行带标记、续行对齐」的既有排版。
            $tmp = [];
            $this->renderBlock($child, $tmp, 0);
            foreach ($tmp as $spans) {
                $out[] = [[$everLed ? $cont : $lead, Style::default()], ...$spans];
                $everLed = true;
            }
        }
        if (!$everLed) {
            // 空列表项（`- ` 之后什么都没有）：只留标记，别让它在屏幕上消失
            $out[] = [[$lead, Style::default()]];
        }
    }

    // ── 行内 ─────────────────────────────────────────

    /**
     * 行内节点流渲染进 $lines（多条逻辑行）：遇到 Newline（软/硬换行）就**断行**。
     * @param iterable<object> $nodes
     * @param list<list<array{0:string,1:Style}>> $lines
     */
    private function renderInlines(iterable $nodes, array &$lines, Style $base): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof Text) {
                $lines[count($lines) - 1][] = [$node->getLiteral(), $base];
                continue;
            }
            if ($node instanceof Code) {
                $lines[count($lines) - 1][] = [$node->getLiteral(), $this->theme->style('mdCode')];
                continue;
            }
            if ($node instanceof Strong) {
                $this->renderInlines($node->children(), $lines, $base->bold());
                continue;
            }
            if ($node instanceof Emphasis) {
                $this->renderInlines($node->children(), $lines, $base->italic());
                continue;
            }
            if ($node instanceof Link) {
                // 链接：文字 + (url)，色用 mdLink——终端里链接不可点，露出全文更实用
                $linkStyle = $this->theme->style('mdLink');
                $text = '';
                foreach ($node->children() as $c) {
                    if ($c instanceof Text) {
                        $text .= $c->getLiteral();
                    }
                }
                if ($text === '' || $text === $node->getUrl()) {
                    $lines[count($lines) - 1][] = [$node->getUrl(), $linkStyle];
                } else {
                    $lines[count($lines) - 1][] = [$text, $linkStyle];
                    $lines[count($lines) - 1][] = [' (' . $node->getUrl() . ')', $base];
                }
                continue;
            }
            if ($node instanceof Newline) {
                // 软/硬换行 → 断行（不折成空格：终端聊天的排版靠它）
                $lines[] = [];
                continue;
            }
            if ($node instanceof AbstractInline) {
                // 其它行内（Strikethrough 等）：透传子内容，不丢文本
                $this->renderInlines($node->children(), $lines, $base);
            }
        }
    }

    // ── 小工具 ───────────────────────────────────────

    /**
     * 逻辑行前挂 $depth 层缩进 span（代码块整体缩进，保持内部对齐）。
     * @param list<array{0:string,1:Style}> $spans
     * @return list<array{0:string,1:Style}>
     */
    private function indentSpans(array $spans, int $depth): array
    {
        if ($depth <= 0) {
            return $spans;
        }
        return [[$this->indentText($depth), Style::default()], ...$spans];
    }

    private function indentText(int $depth): string
    {
        return str_repeat('  ', $depth);
    }
}

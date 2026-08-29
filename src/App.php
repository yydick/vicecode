<?php
declare(strict_types=1);

namespace App;

use App\Editor\Buffer;
use App\Editor\Highlighter;
use App\Explorer\FileTree;
use App\Explorer\TreeNode;
use App\Core\Config;
use App\Core\LayoutFactory;
use App\I18n\Translator;
use App\Panel\AiPanel;
use App\Terminal\CommandRunner;
use App\Terminal\TerminalBuffer;
use App\Text\DisplayWidth;
use App\Text\SpanClip;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event\MouseEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\KeyModifiers;
use PhpTui\Term\MouseEventKind;
use PhpTui\Term\MouseButton;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Position\Position;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Widget\Widget;
use PhpTui\Tui\Widget\Margin;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Style\Modifier;
use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Span;

/**
 * M1 —— 资源管理器 + 编辑器（纯 php-tui/term 驱动，无 Swoole 终端驱动）。
 *
 * 在 M0 的骨架（六面板 + 键鼠焦点 + AI 输入框）上接入：
 *   - 资源管理器目录树（懒展开 / 折叠 / 选中 / 高亮）
 *   - 打开文件载入 Buffer、行号 + 光标渲染、滚动与光标移动
 *   - 基础编辑（插入 / 退格 / 回车 / 删除）与 Ctrl+S 保存（* 未保存标记）
 *   - 界面文案全部走 i18n（config/locales/{zh_CN,en}.php，APP_LOCALE 切换）
 *
 * Swoole 不进入终端驱动；目录读取用懒展开限制在单次 IO，避免大目录卡顿。
 */
class App
{
    public bool $quit = false;

    /** 可聚焦面板顺序（Tab 循环用） */
    public const PANELS = ['sidebar', 'editor', 'terminal', 'ai_stream', 'ai_input'];

    public int $focusIndex = 0;
    public int $sidebarTabIndex = 0;          // 0=Explorer 1=GIT 2=Search
    private const SIDEBAR_TABS = ['explorer', 'git', 'search'];

    private Translator $i18n;
    private FileTree $tree;
    /** 图标配置（config/icons.php），可定制；缺省回退到空串（只用文字标签） */
    private array $icons = [];
    public string $selectedPath = '';
    public int $treeOffset = 0;

    /**
     * 双击判定（终端没有双击事件：MouseEventKind 只有 Down/Up/Drag/Moved/Scroll*，
     * SGR 协议里双击就是两次独立的按下，得自己按「同一条目 + 时间间隔」合成）。
     * 用途：对齐 VSCode 习惯——双击目录=展开/折叠；单击目录只选中（避免误触折叠）。
     */
    private const DOUBLE_CLICK_MS = 400;
    private ?float $lastTreeClickAtMs = null;
    private ?string $lastTreeClickPath = null;
    private ?int $lastTreeClickRow = null;

    /** @var array<string,Buffer> */
    private array $buffers = [];
    public ?Buffer $buffer = null;

    /** 瞬时状态栏消息（如「已保存」/「保存失败」） */
    public string $message = '';

    /** 未保存确认状态机：null=无；['kind'=>'quit'|'close','path'=>?string] */
    public ?array $confirm = null;

    /** 编辑器 tab 栏各标签的命中矩形（点击切换用），渲染时填充 */
    private array $editorTabRects = [];

    /** AI 面板（消息流 + 输入框，M5 接真实 LLM） */
    public AiPanel $ai;

    // ── Terminal（M2 命令运行器） ──
    /** 命令在独立子进程跑，主循环每轮 pollTerminal() 排空管道，故不阻塞渲染 */
    private CommandRunner $termRunner;
    private TerminalBuffer $termBuf;
    public string $termInput = '';
    /** 输入光标，字符单位（mb_* 处理多字节） */
    public int $termPos = 0;
    public int $termScroll = 0;
    /** true=视口贴住输出末尾（新输出自动滚到可见） */
    public bool $termFollow = true;
    /** @var string[] 命令历史 */
    public array $termHist = [];
    /** -1=正在编辑新行，否则为 termHist 下标 */
    public int $termHistIdx = -1;
    /** 命令的工作目录（启动时固定，避免 cd 影响后续命令） */
    private string $termCwd;

    public function __construct()
    {
        $this->i18n = Translator::fromEnv(__DIR__ . '/../config/locales');
        $this->icons = Config::loadPhp(__DIR__ . '/../config/icons.php');
        $this->tree = new FileTree(getcwd() ?: '.');
        $this->ai = new AiPanel($this);
        $this->termRunner = new CommandRunner();
        $this->termBuf = new TerminalBuffer();
        $this->termCwd = getcwd() ?: '.';
        if (!empty($this->tree->roots)) {
            $this->selectedPath = $this->tree->roots[0]->path;
        }
    }

    /** 取图标；未配置返回空串（调用方据此回退到纯文字标签） */
    public function icon(string $key): string
    {
        return $this->icons[$key] ?? '';
    }

    public function focusPanel(): string
    {
        return self::PANELS[$this->focusIndex];
    }

    public function locale(): string
    {
        return $this->i18n->locale();
    }

    /** 取翻译文案（供各面板使用，避免面板各自持有 Translator） */
    public function t(string $key, array $params = []): string
    {
        return $this->i18n->t($key, $params);
    }

    /** 六个面板的矩形（命中测试与渲染共用），约束的唯一真身在 LayoutFactory。 */
    public function areas(Area $vp): array
    {
        return LayoutFactory::split($vp);
    }

    private function borderStyle(bool $focused): Style
    {
        return Style::default()->fg($focused ? AnsiColor::LightGreen : AnsiColor::Gray);
    }

    public function render(Area $vp): Widget
    {
        return $this->build($this->areas($vp));
    }

    public function build(array $a): Widget
    {
        $focus = $this->focusPanel();

        // ── Sidebar ──
        $sidebarInner = $this->sidebarContent($a['sidebar'], $focus === 'sidebar');
        $sidebar = BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderStyle($this->borderStyle($focus === 'sidebar'))
            ->titles(Title::fromString(' ' . $this->i18n->t('panel.sidebar') . ' '))
            ->widget($sidebarInner);

        // ── Editor ──
        $editorTitle = ' ' . $this->i18n->t('panel.editor') . ' ';
        if ($this->buffer !== null) {
            $name = basename((string) $this->buffer->path);
            $flag = $this->buffer->dirty ? ' ' . $this->i18n->t('status.dirty') : '';
            $editorTitle = ' ' . $this->i18n->t('panel.editor') . ': ' . $name . $flag . ' ';
        }
        $editor = BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderStyle($this->borderStyle($focus === 'editor'))
            ->titles(Title::fromString($editorTitle))
            ->widget($this->editorContent($a['editor'], $focus === 'editor'));

        // ── Terminal（M2 命令运行器） ──
        $termTitle = ' ' . $this->i18n->t('panel.terminal') . ' ';
        if ($this->termRunner->isRunning()) {
            $termTitle = ' ' . $this->i18n->t('panel.terminal') . ' · ' . $this->i18n->t('term.running') . ' ';
        }
        $terminal = BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderStyle($this->borderStyle($focus === 'terminal'))
            ->titles(Title::fromString($termTitle))
            ->widget($this->terminalContent($a['terminal'], $focus === 'terminal'));

        $center = GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(...LayoutFactory::centerConstraints())
            ->widgets($editor, $terminal);

        // ── AI Stream ──
        $aiStream = BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderStyle($this->borderStyle($focus === 'ai_stream'))
            ->titles(Title::fromString(' ' . $this->i18n->t('panel.ai_chat') . ' '))
            ->widget($this->ai->streamContent());

        // ── AI Input ──
        $aiInput = BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderStyle($this->borderStyle($focus === 'ai_input'))
            ->titles(Title::fromString(' ' . $this->i18n->t('panel.ai_input') . ' ' . $this->i18n->t('ai.input_hint') . ' '))
            ->widget($this->ai->inputContent());

        $ai = GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(...LayoutFactory::aiConstraints())
            ->widgets($aiStream, $aiInput);

        // ── 主区 ──
        $main = GridWidget::default()
            ->direction(Direction::Horizontal)
            ->constraints(...LayoutFactory::mainConstraints())
            ->widgets($sidebar, $center, $ai);

        // ── StatusBar ──
        $file = $this->buffer !== null ? basename((string) $this->buffer->path) : '—';
        $dirty = $this->buffer !== null && $this->buffer->dirty ? ' ' . $this->i18n->t('status.dirty') : '';
        $tabLabel = $this->i18n->t('sidebar.' . self::SIDEBAR_TABS[$this->sidebarTabIndex]);
        $statusText = ' ' . $this->i18n->t('app.title')
            . ' · ' . $this->i18n->t('status.focus') . '=' . strtoupper($focus)
            . ' · ' . $this->i18n->t('status.tab') . '=' . $tabLabel
            . ' · ' . $this->i18n->t('status.file') . '=' . $file . $dirty
            . ' · ' . $this->i18n->t('status.locale') . '=' . $this->locale()
            . ' · ' . $this->message
            . ' · ' . $this->i18n->t('status.quit');
        // 未保存确认进行中：状态栏改为确认提示
        if ($this->confirm !== null) {
            $statusText = ' ' . ($this->confirm['kind'] === 'close'
                ? $this->i18n->t('confirm.close_dirty')
                : $this->i18n->t('confirm.quit_dirty'));
        }

        $status = BlockWidget::default()
            ->borders(Borders::NONE)
            ->widget(ParagraphWidget::fromString($statusText));

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(...LayoutFactory::rootConstraints())
            ->widgets($main, $status);
    }

    // ── 终端面板（M2 命令运行器） ──────────────────────

    /**
     * 主循环每轮调用：排空命令管道。返回是否有新内容（供主循环决定是否重绘）。
     */
    public function pollTerminal(): bool
    {
        if (!$this->termRunner->isRunning()) {
            return false;
        }
        $got = $this->termRunner->poll(function (string $bytes, bool $isErr): void {
            $this->termBuf->append($bytes, $isErr);
        });
        if (!$this->termRunner->isRunning()) {
            // 命令结束：结算未终止的半行，并把视口拉回底部看结果
            $this->termBuf->flushTail();
            $this->termFollow = true;
            $got = true;
        }
        return $got;
    }

    public function termRunning(): bool
    {
        return $this->termRunner->isRunning();
    }

    /** 终端面板内容：末行固定为命令输入行，其上是输出视口 */
    private function terminalContent(Area $terminal, bool $focused): Widget
    {
        $inner = $terminal->inner(new Margin(1, 1));
        $W = max(0, $inner->width);
        $H = max(0, $inner->height);
        $outH = max(0, $H - 1);     // 末行留给输入行

        $rows = $this->termBuf->all();
        $status = $this->termStatusRow();
        if ($status !== null) {
            $rows[] = $status;
        }
        if ($rows === []) {
            $rows[] = ['text' => $this->i18n->t('term.empty'), 'err' => false, 'kind' => 'hint'];
        }

        // 视口：follow 时贴住末尾，否则用 termScroll（并回写钳制后的值）
        $maxOff = max(0, count($rows) - $outH);
        $off = $this->termFollow ? $maxOff : min(max(0, $this->termScroll), $maxOff);
        $this->termScroll = $off;

        $lines = [];
        foreach (array_slice($rows, $off, $outH) as $row) {
            $kind = $row['kind'] ?? ($row['err'] ? 'err' : 'out');
            $style = match ($kind) {
                'err' => Style::default()->fg(AnsiColor::Red),
                'killed' => Style::default()->fg(AnsiColor::Yellow),
                'hint' => Style::default()->fg(AnsiColor::DarkGray),
                default => Style::default(),
            };
            $lines[] = Line::fromSpans(Span::styled(DisplayWidth::mbCutDisp($row['text'], $W), $style));
        }
        // 输出不足一屏时补空行，把输入行顶到面板底部
        while (count($lines) < $outH) {
            $lines[] = Line::fromSpans(Span::styled('', Style::default()));
        }
        $lines[] = $this->termInputLine($W, $focused);

        return ParagraphWidget::fromLines(...$lines);
    }

    /** 命令结束后的状态行（退出码 / 被中断）；无已结束命令返回 null */
    private function termStatusRow(): ?array
    {
        if ($this->termRunner->isRunning()) {
            return null;
        }
        $code = $this->termRunner->exitCode();
        if ($code === null) {
            return null;
        }
        if ($this->termRunner->termSig() !== 0) {
            return ['text' => $this->i18n->t('term.killed'), 'err' => false, 'kind' => 'killed'];
        }
        if ($code !== 0) {
            return ['text' => $this->i18n->t('term.exit', ['code' => (string) $code]), 'err' => false, 'kind' => 'err'];
        }
        return null;
    }

    /** 输入行：提示符 + 输入文本 + 光标反显（水平滚动保证光标可见） */
    private function termInputLine(int $W, bool $focused): Line
    {
        $running = $this->termRunner->isRunning();
        $prompt = $running ? '● ' : '$ ';
        $promptStyle = $running
            ? Style::default()->fg(AnsiColor::Green)
            : Style::default()->fg(AnsiColor::Cyan);
        $textW = max(0, $W - DisplayWidth::dispWidth($prompt));

        $spans = [];
        foreach (mb_str_split($this->termInput) as $g) {
            $spans[] = [$g, Style::default()];
        }
        // 把光标显示列滚进窗口（+1 是给光标本身留一格）
        $cursorDisp = DisplayWidth::dispWidth(mb_substr($this->termInput, 0, $this->termPos));
        $scrollLeft = max(0, $cursorDisp - $textW + 1);

        return Line::fromSpans(
            Span::styled($prompt, $promptStyle),
            ...SpanClip::clip($spans, $focused, $this->termPos, $scrollLeft, $textW)
        );
    }

    private function submitTerm(): void
    {
        $cmd = trim($this->termInput);
        $this->termInput = '';
        $this->termPos = 0;
        if ($cmd === '') {
            return;
        }
        if (end($this->termHist) !== $cmd) {
            $this->termHist[] = $cmd;
            if (count($this->termHist) > 200) {
                array_shift($this->termHist);
            }
        }
        $this->termHistIdx = -1;

        if ($this->termRunner->isRunning()) {
            // 必须带 \n：不带会被当成未终止的半行攒在 tail 里，渲染不出来
            $this->termBuf->append($this->i18n->t('term.busy') . "\n", true);
            return;
        }
        $this->termBuf->append('$ ' . $cmd . "\n", false);   // 回显命令
        $this->termFollow = true;
        $this->termScroll = 0;
        if (!$this->termRunner->start($cmd, $this->termCwd)) {
            $this->termBuf->append($this->i18n->t('term.spawn_failed') . "\n", true);
        }
    }

    /** R7：中断正在跑的命令 */
    private function termCancel(): void
    {
        if (!$this->termRunner->isRunning()) {
            return;
        }
        $this->termRunner->cancel();
        $this->termFollow = true;
        $this->message = $this->i18n->t('term.killed');
    }

    private function termScrollBy(int $delta): void
    {
        $this->termFollow = false;
        $this->termScroll = max(0, $this->termScroll + $delta);
    }

    private function termClear(): void
    {
        $this->termBuf->clear();
        $this->termScroll = 0;
        $this->termFollow = true;
    }

    private function termHistoryPrev(): void
    {
        if ($this->termHist === []) {
            return;
        }
        $this->termHistIdx = $this->termHistIdx === -1
            ? count($this->termHist) - 1
            : max(0, $this->termHistIdx - 1);
        $this->termInput = $this->termHist[$this->termHistIdx];
        $this->termPos = mb_strlen($this->termInput);
    }

    private function termHistoryNext(): void
    {
        if ($this->termHistIdx === -1) {
            return;
        }
        if ($this->termHistIdx >= count($this->termHist) - 1) {
            $this->termHistIdx = -1;
            $this->termInput = '';
            $this->termPos = 0;
            return;
        }
        $this->termHistIdx++;
        $this->termInput = $this->termHist[$this->termHistIdx];
        $this->termPos = mb_strlen($this->termInput);
    }

    private function handleTermChar(CharKeyEvent $e): void
    {
        $ctrl = ($e->modifiers & KeyModifiers::CONTROL) !== 0;
        if ($ctrl && strtolower($e->char) === 'l') {
            $this->termClear();
            return;
        }
        if ($e->char === "\r" || $e->char === "\n") {
            $this->submitTerm();
            return;
        }
        if ($e->char === "\x7f" || $e->char === "\x08") {
            if ($this->termPos > 0) {
                $this->termInput = mb_substr($this->termInput, 0, $this->termPos - 1)
                    . mb_substr($this->termInput, $this->termPos);
                $this->termPos--;
                $this->termHistIdx = -1;
            }
            return;
        }
        if (strlen($e->char) === 1 && ord($e->char) >= 32 && !$ctrl) {
            $this->termInput = mb_substr($this->termInput, 0, $this->termPos) . $e->char
                . mb_substr($this->termInput, $this->termPos);
            $this->termPos++;
            $this->termHistIdx = -1;
        }
    }

    // ── 侧栏内容（tab 行 + Explorer 树 / GIT / Search） ──
    private function sidebarContent(Area $sidebar, bool $focused): Widget
    {
        $lines = [];

        // tab 行（配置了图标就只显示图标、不显示文字，省空间；选中用 [ ] 包裹）。
        // 按「显示列宽」预算分段，否则中文标签占 2 列会把段宽撑爆导致 ] 换行（M1 反馈的 bug）。
        $innerW = max(0, $sidebar->width - 2);
        $seg = max(1, intdiv(max(1, $innerW), 3)); // 每段可用显示列宽
        $tabLine = '';
        foreach (self::SIDEBAR_TABS as $i => $key) {
            $icon = $this->icon($key);
            $label = $this->i18n->t('sidebar.' . $key);
            // 有图标则只显示图标（不占文字位置）；无图标退化成纯文字标签以保持可用性。
            $core = $icon !== '' ? $icon : ' ' . $label . ' ';
            if ($i === $this->sidebarTabIndex) {
                $core = '[' . $core . ']';
            }
            // 段内超宽则按显示列宽截断（保留字素边界，不劈开 CJK）
            if (DisplayWidth::dispWidth($core) > $seg) {
                $core = DisplayWidth::mbCutDisp($core, $seg);
            }
            $tabLine .= DisplayWidth::mbPadDisp($core, $seg);
        }
        if ($innerW > 0) {
            $lines[] = Line::fromSpans(Span::styled(DisplayWidth::mbCutDisp($tabLine, $innerW), Style::default()->fg(AnsiColor::Yellow)));
            $lines[] = Line::fromSpans(Span::styled(str_repeat('─', $innerW), Style::default()->fg(AnsiColor::Gray)));
        }

        if ($this->sidebarTabIndex === 0) {
            $visible = $this->tree->visible();
            $rowsH = max(0, $sidebar->height - 2 - 2); // 边框 + tab行 + 分隔
            if ($rowsH > 0 && !empty($visible)) {
                $idx = $this->explorerSelectedIndex($visible);
                if ($idx < $this->treeOffset) {
                    $this->treeOffset = $idx;
                } elseif ($idx >= $this->treeOffset + $rowsH) {
                    $this->treeOffset = $idx - $rowsH + 1;
                }
                if ($this->treeOffset < 0) {
                    $this->treeOffset = 0;
                }
                for ($i = $this->treeOffset; $i < min($this->treeOffset + $rowsH, count($visible)); $i++) {
                    $node = $visible[$i];
                    $indent = str_repeat('  ', $node->depth);
                    $prefix = $node->isDir ? ($node->expanded ? '▼ ' : '▶ ') : '  ';
                    $marker = $node->path === $this->selectedPath ? '» ' : '  ';
                    $suffix = $node->isDir ? '/' : '';
                    $text = DisplayWidth::mbCutDisp($marker . $indent . $prefix . $node->name . $suffix, $innerW);
                    $style = $node->path === $this->selectedPath
                        ? Style::default()->addModifier(Modifier::REVERSED)
                        : Style::default();
                    $lines[] = Line::fromSpans(Span::styled($text, $style));
                }
            } elseif (empty($visible)) {
                $lines[] = Line::fromSpans(Span::styled('(empty)', Style::default()->fg(AnsiColor::DarkGray)));
            }
        } elseif ($this->sidebarTabIndex === 1) {
            $lines[] = Line::fromSpans(Span::styled($this->i18n->t('git.placeholder'), Style::default()->fg(AnsiColor::DarkGray)));
            $lines[] = Line::fromSpans(Span::styled($this->i18n->t('git.sub'), Style::default()->fg(AnsiColor::DarkGray)));
        } else {
            $lines[] = Line::fromSpans(Span::styled($this->i18n->t('search.placeholder'), Style::default()->fg(AnsiColor::DarkGray)));
            $lines[] = Line::fromSpans(Span::styled($this->i18n->t('search.prompt'), Style::default()->fg(AnsiColor::DarkGray)));
        }

        if ($lines === []) {
            return ParagraphWidget::fromString('');
        }
        return ParagraphWidget::fromLines(...$lines);
    }

    // ── 编辑器内容（行号 + 语法高亮 + 光标反显） ──
    private function editorContent(Area $editor, bool $focused): Widget
    {
        $inner = $editor->inner(new Margin(1, 1));
        $W = max(0, $inner->width);
        $H = max(0, $inner->height);

        if ($this->buffer === null) {
            return ParagraphWidget::fromString($this->i18n->t('editor.no_file'));
        }
        if ($this->buffer->noticeKey !== null) {
            return ParagraphWidget::fromString($this->i18n->t($this->buffer->noticeKey, $this->buffer->noticeParams));
        }

        $buf = $this->buffer;
        $gutterW = min($W, $buf->maxLineNoWidth + 1);
        $textW = max(0, $W - $gutterW);
        $hasTabs = $this->hasTabs();
        $tabH = $hasTabs ? 1 : 0;
        $visibleRows = max(0, $H - $tabH);

        // 垂直滚动：保证光标行可见
        if ($buf->cursorRow < $buf->scrollTop) {
            $buf->scrollTop = $buf->cursorRow;
        }
        if ($buf->cursorRow >= $buf->scrollTop + $visibleRows) {
            $buf->scrollTop = $buf->cursorRow - $visibleRows + 1;
        }
        if ($buf->scrollTop < 0) {
            $buf->scrollTop = 0;
        }
        // 水平滚动：保证光标列可见
        if ($buf->cursorCol < $buf->scrollLeft) {
            $buf->scrollLeft = $buf->cursorCol;
        }
        if ($buf->cursorCol >= $buf->scrollLeft + $textW) {
            $buf->scrollLeft = $buf->cursorCol - $textW + 1;
        }
        if ($buf->scrollLeft < 0) {
            $buf->scrollLeft = 0;
        }

        // 高亮缓存（按 Buffer 修订号；整文件高亮一次，scrivo 需完整上下文）
        $lang = Highlighter::langFor((string) $buf->path);
        if ($buf->hlRev !== $buf->rev) {
            $buf->hlLines = Highlighter::highlightLines($buf->lines, $lang);
            $buf->hlRev = $buf->rev;
        }
        $hl = $buf->hlLines;

        $lines = [];
        if ($hasTabs) {
            $lines[] = $this->editorTabLine($editor, $W);
        }
        $total = count($buf->lines);
        for ($i = 0; $i < $visibleRows; $i++) {
            $li = $buf->scrollTop + $i;
            $lineNo = (string) ($li + 1);
            $gutter = DisplayWidth::mbPad($lineNo, $gutterW - 1) . ' ';
            $gutterStyle = $li === $buf->cursorRow
                ? Style::default()->fg(AnsiColor::Yellow)
                : Style::default()->fg(AnsiColor::DarkGray);

            if ($li >= $total) {
                // 缓冲区之后：暗色 ~ 占位
                $lines[] = Line::fromSpans(
                    Span::styled($gutter, $gutterStyle),
                    Span::styled('~', Style::default()->fg(AnsiColor::DarkGray)),
                );
                continue;
            }

            if ($hl !== null && isset($hl[$li]) && $hl[$li] !== null) {
                $lineSpans = $hl[$li];
            } else {
                $lineSpans = [[$buf->lines[$li], Style::default()]];
            }
            $contentSpans = SpanClip::clip(
                $lineSpans,
                $li === $buf->cursorRow && $focused,
                $buf->cursorCol,
                $buf->scrollLeft,
                $textW
            );
            $lines[] = Line::fromSpans(
                Span::styled($gutter, $gutterStyle),
                ...$contentSpans
            );
        }
        return ParagraphWidget::fromLines(...$lines);
    }

    // ── 多 Buffer 标签（R7） ──────────────────────────────
    public function hasTabs(): bool
    {
        return count($this->buffers) > 1;
    }

    /** 指定路径的文件是否仍被打开（用于未保存确认等断言，避免外部直接访问 private $buffers）。 */
    public function hasBuffer(string $path): bool
    {
        return isset($this->buffers[$path]);
    }

    /** 编辑器顶部标签栏（一行）：已开文件 + dirty*，当前 REVERSED；同时记录命中矩形供点击。 */
    private function editorTabLine(Area $editor, int $W): Line
    {
        $absX0 = $editor->position->x + 1;
        $maxX = $absX0 + $W;
        $this->editorTabRects = [];
        $spans = [];
        $cx = $absX0;
        foreach ($this->buffers as $path => $b) {
            $name = basename($path);
            $mark = $b->dirty ? $this->i18n->t('status.dirty') : '';
            $seg = ' ' . $name . $mark . ' ';
            $w = DisplayWidth::dispWidth($seg);
            if ($cx + $w > $maxX) {
                break;
            }
            $st = ($b === $this->buffer)
                ? Style::default()->addModifier(Modifier::REVERSED)
                : Style::default()->fg(AnsiColor::Gray);
            $spans[] = Span::styled($seg, $st);
            $this->editorTabRects[$cx] = [$cx, $cx + $w - 1, $path];
            $cx += $w;
        }
        return Line::fromSpans(...$spans);
    }

    public function switchBuffer(string $path): void
    {
        if (isset($this->buffers[$path])) {
            $this->buffer = $this->buffers[$path];
        }
    }

    private function cycleBuffer(): void
    {
        $paths = array_keys($this->buffers);
        if (count($paths) < 2 || $this->buffer === null) {
            return;
        }
        $idx = array_search($this->buffer->path, $paths, true);
        if ($idx === false) {
            return;
        }
        $next = $paths[($idx + 1) % count($paths)];
        $this->buffer = $this->buffers[$next];
    }

    // ── 未保存确认状态机（R8） ───────────────────────────
    private function anyDirty(): bool
    {
        foreach ($this->buffers as $b) {
            if ($b->dirty) {
                return true;
            }
        }
        return false;
    }

    private function requestQuit(): void
    {
        if ($this->anyDirty()) {
            $this->confirm = ['kind' => 'quit'];
            return;
        }
        $this->finishQuit();
    }

    /** 收尾退出：先停掉还在跑的命令，避免留下孤儿子进程 */
    private function finishQuit(): void
    {
        $this->termRunner->shutdown();
        $this->quit = true;
    }

    private function requestClose(string $path): void
    {
        if (!isset($this->buffers[$path])) {
            return;
        }
        if ($this->buffers[$path]->dirty) {
            $this->confirm = ['kind' => 'close', 'path' => $path];
            return;
        }
        $this->reallyClose($path);
    }

    private function confirmProceed(): void
    {
        $kind = $this->confirm['kind'] ?? 'quit';
        $path = $this->confirm['path'] ?? null;
        $this->confirm = null;
        if ($kind === 'close' && $path !== null) {
            $this->reallyClose($path);
        } else {
            $this->finishQuit();
        }
    }

    private function reallyClose(string $path): void
    {
        unset($this->buffers[$path]);
        if ($this->buffer === null || $this->buffer->path === $path) {
            $remaining = array_values($this->buffers);
            $this->buffer = $remaining !== [] ? $remaining[0] : null;
        }
    }

    private function handleConfirm($event): void
    {
        if ($event instanceof CharKeyEvent) {
            $ctrl = ($event->modifiers & KeyModifiers::CONTROL)
                && strtolower($event->char) === 'q';
            $ch = strtolower($event->char);
            if ($ctrl || $ch === 'y') {
                $this->confirmProceed();
                return;
            }
            if ($ch === 'n') {
                $this->confirm = null;
                return;
            }
            return;
        }
        if ($event instanceof CodedKeyEvent && $event->code === KeyCode::Esc) {
            $this->confirm = null;
        }
    }

    // ── 事件分发 ──
    public function handle($event, Area $vp): void
    {
        // 未保存确认进行中：拦截所有输入，只响应 y/n/Esc（及 Ctrl+Q 视为确认）
        if ($this->confirm !== null) {
            $this->handleConfirm($event);
            return;
        }

        $a = $this->areas($vp);

        if ($event instanceof MouseEvent) {
            $this->handleMouse($event, $a);
            return;
        }

        if ($event instanceof CharKeyEvent) {
            // 终端有命令在跑时，Ctrl+C 先中断子进程（R7），而不是退出应用
            if ($this->focusPanel() === 'terminal'
                && $this->termRunner->isRunning()
                && ($event->modifiers & KeyModifiers::CONTROL)
                // 有些终端/解析器给的是原始字节 \x03 而不是带 CONTROL 修饰的 'c'
                && (strtolower($event->char) === 'c' || $event->char === "\x03")) {
                $this->termCancel();
                return;
            }
            // 全局：Ctrl+Q 退出（若有未保存改动先弹确认）。
            // 退出热键原本是 Ctrl+C，但与「复制」冲突（习惯上 Ctrl+C 是复制，误按就退出了），
            // 故换成 Ctrl+Q；Ctrl+C 只保留「中断终端里正在跑的命令」这个终端固有语义。
            // 实测 php-tui/term 0.3.4 会把 0x11 解析成 CharKeyEvent(char:'q', modifiers:ctl)，
            // 故无需另兜底原始字节——若写上 `\x11` 分支反而是死代码（它排在 CONTROL 判定之后）。
            if (($event->modifiers & KeyModifiers::CONTROL) && strtolower($event->char) === 'q') {
                $this->requestQuit();
                return;
            }
            // AI 输入框
            if ($this->focusPanel() === 'ai_input') {
                $this->ai->onChar($event);
                return;
            }
            // 编辑器：键入即编辑
            if ($this->focusPanel() === 'editor') {
                $this->handleEditorChar($event);
                return;
            }
            // 终端：可打印字符进命令输入行（q 也进输入行，不再直接退出）
            if ($this->focusPanel() === 'terminal') {
                $this->handleTermChar($event);
                return;
            }
            // 其它面板：Enter 在侧栏展开/打开；q 退出（非输入态）
            if ($event->char === "\r" || $event->char === "\n") {
                if ($this->focusPanel() === 'sidebar') {
                    $this->handleSidebarEnter();
                }
                return;
            }
            if (strtolower($event->char) === 'q') {
                $this->requestQuit();
            }
            return;
        }

        if ($event instanceof CodedKeyEvent) {
            $this->handleCoded($event, $a);
        }
    }

    private function handleMouse(MouseEvent $e, array $a): void
    {
        if ($e->kind === MouseEventKind::ScrollDown) {
            if ($this->focusPanel() === 'editor') {
                $this->buffer?->pageDown(3);
            } elseif ($this->focusPanel() === 'sidebar') {
                $this->moveTreeSelection(1);
            } elseif ($this->focusPanel() === 'terminal') {
                $this->termScrollBy(3);
            }
            return;
        }
        if ($e->kind === MouseEventKind::ScrollUp) {
            if ($this->focusPanel() === 'editor') {
                $this->buffer?->pageUp(3);
            } elseif ($this->focusPanel() === 'sidebar') {
                $this->moveTreeSelection(-1);
            } elseif ($this->focusPanel() === 'terminal') {
                $this->termScrollBy(-3);
            }
            return;
        }
        if ($e->kind === MouseEventKind::Down) {
            $this->handleClick($e, $a);
        }
    }

    private function handleEditorChar(CharKeyEvent $e): void
    {
        // Ctrl+W 关闭当前 buffer（dirty 时弹确认）
        if (($e->modifiers & KeyModifiers::CONTROL) && strtolower($e->char) === 'w') {
            if ($this->buffer !== null) {
                $this->requestClose((string) $this->buffer->path);
            }
            return;
        }
        // Ctrl+S 保存
        if (($e->modifiers & KeyModifiers::CONTROL) && (strtolower($e->char) === 's' || $e->char === "\x13")) {
            $this->saveBuffer();
            return;
        }
        if ($e->char === "\r" || $e->char === "\n") {
            $this->buffer?->insertNewline();
        } elseif ($e->char === "\x7f" || $e->char === "\x08") {
            $this->buffer?->backspace();
        } elseif (strlen($e->char) === 1 && ord($e->char) >= 32 && !($e->modifiers & KeyModifiers::CONTROL)) {
            $this->buffer?->insertChar($e->char);
        }
    }

    private function handleCoded(CodedKeyEvent $e, array $a): void
    {
        $focus = $this->focusPanel();
        switch ($e->code) {
            case KeyCode::Esc:
                // 终端：运行中→中断命令；有输入→清空输入；否则才退出
                if ($focus === 'terminal' && $this->termRunner->isRunning()) {
                    $this->termCancel();
                } elseif ($focus === 'terminal' && $this->termInput !== '') {
                    $this->termInput = '';
                    $this->termPos = 0;
                } else {
                    $this->requestQuit();
                }
                break;
            case KeyCode::Tab:
                if (($e->modifiers & KeyModifiers::CONTROL) && $focus === 'editor') {
                    $this->cycleBuffer();
                } else {
                    $this->focusIndex = ($this->focusIndex + 1) % count(self::PANELS);
                }
                break;
            case KeyCode::Enter:
                if ($focus === 'sidebar') {
                    $this->handleSidebarEnter();
                } elseif ($focus === 'terminal') {
                    // 真实终端里回车是 CodedKeyEvent(Enter)，不是 CharKeyEvent("\r")——
                    // 只挂在 CharKeyEvent 上的话，pty 下提交不了命令（headless 测试会漏掉）。
                    $this->submitTerm();
                }
                break;
            case KeyCode::Up:
                if ($focus === 'editor') {
                    $this->buffer?->moveUp();
                } elseif ($focus === 'sidebar') {
                    $this->moveTreeSelection(-1);
                } elseif ($focus === 'terminal') {
                    $this->termHistoryPrev();
                }
                break;
            case KeyCode::Down:
                if ($focus === 'editor') {
                    $this->buffer?->moveDown();
                } elseif ($focus === 'sidebar') {
                    $this->moveTreeSelection(1);
                } elseif ($focus === 'terminal') {
                    $this->termHistoryNext();
                }
                break;
            case KeyCode::Left:
                if ($focus === 'editor') {
                    $this->buffer?->moveLeft();
                } elseif ($focus === 'terminal' && $this->termPos > 0) {
                    $this->termPos--;
                }
                break;
            case KeyCode::Right:
                if ($focus === 'editor') {
                    $this->buffer?->moveRight();
                } elseif ($focus === 'terminal' && $this->termPos < mb_strlen($this->termInput)) {
                    $this->termPos++;
                }
                break;
            case KeyCode::Home:
                if ($focus === 'editor') {
                    $this->buffer?->moveHome();
                } elseif ($focus === 'terminal') {
                    $this->termPos = 0;
                }
                break;
            case KeyCode::End:
                if ($focus === 'editor') {
                    $this->buffer?->moveEnd();
                } elseif ($focus === 'terminal') {
                    $this->termPos = mb_strlen($this->termInput);
                }
                break;
            case KeyCode::PageUp:
                if ($focus === 'editor') {
                    $this->buffer?->pageUp(max(1, $a['editor']->height - 4));
                } elseif ($focus === 'terminal') {
                    $this->termScrollBy(-max(1, $a['terminal']->height - 3));
                }
                break;
            case KeyCode::PageDown:
                if ($focus === 'editor') {
                    $this->buffer?->pageDown(max(1, $a['editor']->height - 4));
                } elseif ($focus === 'terminal') {
                    $this->termScrollBy(max(1, $a['terminal']->height - 3));
                }
                break;
            case KeyCode::Backspace:
                if ($focus === 'ai_input') {
                    $this->ai->backspace();
                } elseif ($focus === 'editor') {
                    $this->buffer?->backspace();
                } elseif ($focus === 'terminal' && $this->termPos > 0) {
                    $this->termInput = mb_substr($this->termInput, 0, $this->termPos - 1)
                        . mb_substr($this->termInput, $this->termPos);
                    $this->termPos--;
                }
                break;
            case KeyCode::Delete:
                if ($focus === 'editor') {
                    $this->buffer?->delete();
                } elseif ($focus === 'terminal' && $this->termPos < mb_strlen($this->termInput)) {
                    $this->termInput = mb_substr($this->termInput, 0, $this->termPos)
                        . mb_substr($this->termInput, $this->termPos + 1);
                }
                break;
        }
    }

    // ── 点击：命中测试 + 侧栏 tab + 树条目 ──
    private function handleClick(MouseEvent $e, array $a): void
    {
        $col = $e->column;
        $row = $e->row;
        $pos = new Position($col, $row);

        $sb = $a['sidebar'];
        // 侧栏 tab 行（inner 第 0 行）
        if ($pos->y === $sb->position->y + 1 && $col >= $sb->position->x && $col < $sb->position->x + $sb->width) {
            $inner = $col - ($sb->position->x + 1);
            $seg = max(1, intdiv(max(1, $sb->width - 2), 3));
            $this->sidebarTabIndex = min(2, intdiv($inner, $seg));
            $this->focusIndex = array_search('sidebar', self::PANELS);
            return;
        }

        // 侧栏树条目（inner 第 2 行起）
        if ($this->sidebarTabIndex === 0 && $sb->containsPosition($pos) && $pos->y >= $sb->position->y + 3) {
            $visible = $this->tree->visible();
            $idx = ($pos->y - ($sb->position->y + 3)) + $this->treeOffset;
            if (isset($visible[$idx])) {
                $node = $visible[$idx];

                // 点行首三角（▶/▼）= 展开/折叠（VSCode 习惯）。
                // 命中区取「三角 + 其后空格」2 列：只判三角那 1 列太窄，很难点中。
                if ($node->isDir && self::hitTreeArrow($pos, $sb, $node->depth)) {
                    $this->resetTreeDoubleClick();
                    $this->selectedPath = $node->path;
                    $this->focusIndex = array_search('sidebar', self::PANELS);
                    $node->expanded = !$node->expanded;
                    if ($node->expanded) {
                        $node->ensureChildren();
                    }
                    return;
                }

                $isDouble = $this->consumeTreeDoubleClick($node->path, $pos->y);

                $this->selectedPath = $node->path;
                $this->focusIndex = array_search('sidebar', self::PANELS);

                if ($node->isDir) {
                    // 双击目录 = 展开/折叠（VSCode 习惯）；单击条目名只选中，保持原样
                    if ($isDouble) {
                        $node->expanded = !$node->expanded;
                        if ($node->expanded) {
                            $node->ensureChildren();
                        }
                    }
                    return;
                }
                $this->openFile($node->path);
                return;
            }
        }

        // 命中测试：点哪个面板就聚焦哪个；点编辑器则按坐标定位光标（R6）
        foreach (self::PANELS as $key) {
            if ($a[$key]->containsPosition($pos)) {
                if ($key === 'editor') {
                    // 点 tab 栏（编辑器内第 1 行）→ 切换 buffer
                    if ($this->hasTabs() && $pos->y === $a['editor']->position->y + 1) {
                        foreach ($this->editorTabRects as [$x0, $x1, $path]) {
                            if ($pos->x >= $x0 && $pos->x <= $x1) {
                                $this->switchBuffer($path);
                                $this->focusIndex = array_search('editor', self::PANELS);
                                return;
                            }
                        }
                        $this->focusIndex = array_search('editor', self::PANELS);
                        return;
                    }
                    $this->positionCursorAtClick($pos, $a['editor']);
                }
                $this->focusIndex = array_search($key, self::PANELS);
                return;
            }
        }
    }

    /**
     * 命中侧栏行首三角（▶/▼）？
     * 行结构（屏幕列）：边框 | marker「» 」2 列 | 缩进 2*depth 列 | 三角 1 列 + 其后空格 1 列 | 名称。
     * 实测（120x40，depth=0）：`│» ▶ .docs/` → 三角在 x=3。
     */
    private static function hitTreeArrow(Position $pos, Area $sb, int $depth): bool
    {
        $arrowX = $sb->position->x + 1 + 2 + $depth * 2;  // inner 左界（margin 1）+ marker 2 列 + 缩进
        return $pos->x >= $arrowX && $pos->x <= $arrowX + 1;
    }

    /**
     * 判定这次点击是否构成双击（同一条目 + 同一屏幕行 + 阈值内），并更新记时。
     * 判定成立即清空记录：否则第三击会再被判成一次双击，把目录 toggle 回原状。
     */
    private function consumeTreeDoubleClick(string $path, int $row): bool
    {
        $now = microtime(true) * 1000.0;
        $isDouble = $this->lastTreeClickPath === $path
            && $this->lastTreeClickRow === $row
            && $this->lastTreeClickAtMs !== null
            && ($now - $this->lastTreeClickAtMs) <= self::DOUBLE_CLICK_MS;

        if ($isDouble) {
            $this->lastTreeClickAtMs = null;
            $this->lastTreeClickPath = null;
            $this->lastTreeClickRow = null;
            return true;
        }

        $this->lastTreeClickAtMs = $now;
        $this->lastTreeClickPath = $path;
        $this->lastTreeClickRow = $row;
        return false;
    }

    /** 清空双击记时（点了三角这类独立动作后调用，避免与后续点击误合成双击） */
    private function resetTreeDoubleClick(): void
    {
        $this->lastTreeClickAtMs = null;
        $this->lastTreeClickPath = null;
        $this->lastTreeClickRow = null;
    }

    /** 点击编辑器内某格 → 映射回 Buffer 的 (row,col) 并定位光标（R6 鼠标精细交互） */
    private function positionCursorAtClick(Position $pos, Area $editor): void
    {
        if ($this->buffer === null || $this->buffer->readOnly) {
            return;
        }
        $inner = $editor->inner(new Margin(1, 1));
        $W = max(0, $inner->width);
        $gutterW = min($W, $this->buffer->maxLineNoWidth + 1);

        $row = ($pos->y - $inner->position->y - ($this->hasTabs() ? 1 : 0)) + $this->buffer->scrollTop;
        $total = count($this->buffer->lines);
        if ($row < 0 || $row >= $total) {
            // 点在可视行之外：夹到最近的有效行
            $row = max(0, min($total - 1, $row));
        }
        $this->buffer->cursorRow = $row;

        $col = ($pos->x - $inner->position->x - $gutterW) + $this->buffer->scrollLeft;
        if ($col < 0) {
            $col = 0;
        }
        $lineLen = mb_strlen($this->buffer->lines[$row]);
        if ($col > $lineLen) {
            $col = $lineLen;
        }
        $this->buffer->cursorCol = $col;
    }

    // ── 资源管理器操作 ──
    /** @param TreeNode[] $visible */
    private function explorerSelectedIndex(array $visible): int
    {
        foreach ($visible as $i => $n) {
            if ($n->path === $this->selectedPath) {
                return $i;
            }
        }
        return 0;
    }

    private function moveTreeSelection(int $delta): void
    {
        $visible = $this->tree->visible();
        if (empty($visible)) {
            return;
        }
        $idx = $this->explorerSelectedIndex($visible) + $delta;
        $idx = max(0, min(count($visible) - 1, $idx));
        $this->selectedPath = $visible[$idx]->path;
    }

    private function handleSidebarEnter(): void
    {
        $visible = $this->tree->visible();
        if (empty($visible)) {
            return;
        }
        $node = $visible[$this->explorerSelectedIndex($visible)];
        if ($node->isDir) {
            $node->expanded = !$node->expanded;
            if ($node->expanded) {
                $node->ensureChildren();
            }
        } else {
            $this->openFile($node->path);
        }
    }

    public function openFile(string $path): void
    {
        if (is_dir($path)) {
            return;
        }
        if (!isset($this->buffers[$path])) {
            $this->buffers[$path] = Buffer::fromFile($path);
        }
        $this->buffer = $this->buffers[$path];
        $this->focusIndex = array_search('editor', self::PANELS);
    }

    private function saveBuffer(): void
    {
        if ($this->buffer === null) {
            return;
        }
        if ($this->buffer->readOnly) {
            $this->message = $this->i18n->t('editor.readonly');
            return;
        }
        $path = $this->buffer->path;
        if ($path === null || (!is_writable($path) && !is_writable(dirname($path)))) {
            $this->message = $this->i18n->t('editor.save_failed', ['msg' => '权限不足或路径不可写']);
            return;
        }
        $ok = $this->buffer->save();
        $this->message = $ok
            ? $this->i18n->t('editor.saved')
            : $this->i18n->t('editor.save_failed', ['msg' => (error_get_last()['message'] ?? 'unknown')]);
    }
}

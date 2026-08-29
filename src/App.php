<?php
declare(strict_types=1);

namespace App;

use App\Core\Config;
use App\Core\Lifecycle;
use App\Core\LayoutFactory;
use App\Editor\Buffer;
use App\Git\GitModel;
use App\I18n\Translator;
use App\Panel\AiPanel;
use App\Panel\EditorPanel;
use App\Panel\SidebarPanel;
use App\Panel\StatusBarPanel;
use App\Panel\TerminalPanel;
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
    /** 侧栏面板（tab 行 + Explorer 树 / GIT / Search） */
    public SidebarPanel $sidebar;

    /** 布局矩形缓存（见 areas()）：key 为视口 x:y:w:h */
    private array $areaCache = [];
    private string $areaKey = '';

    private Translator $i18n;
    /** 图标配置（config/icons.php），可定制；缺省回退到空串（只用文字标签） */
    private array $icons = [];
    public string $selectedPath = '';

    /**
     * 双击判定（终端没有双击事件：MouseEventKind 只有 Down/Up/Drag/Moved/Scroll*，
     * SGR 协议里双击就是两次独立的按下，得自己按「同一条目 + 时间间隔」合成）。
     * 用途：对齐 VSCode 习惯——双击目录=展开/折叠；单击目录只选中（避免误触折叠）。
     */
    private const DOUBLE_CLICK_MS = 400;

    /** @var array<string,Buffer> */
    private array $buffers = [];
    public ?Buffer $buffer = null;

    /** 瞬时状态栏消息（如「已保存」/「保存失败」） */
    public string $message = '';

    /** 未保存确认状态机：null=无；['kind'=>'quit'|'close','path'=>?string] */
    public ?array $confirm = null;

    /** 编辑器面板（行号 + 高亮 + 光标 + 多 Buffer 标签） */
    public EditorPanel $editor;

    /** AI 面板（消息流 + 输入框，M5 接真实 LLM） */
    public AiPanel $ai;

    /** 终端面板（M2 命令运行器） */
    public TerminalPanel $terminal;

    /** GIT 面板状态（M3）：status / log / 分支，供 Sidebar 的 GIT tab 与 StatusBar 读取 */
    public GitModel $git;

    /** 底部状态栏 */
    public StatusBarPanel $statusBar;

    /** 退出与未保存确认状态机（R8） */
    public Lifecycle $lifecycle;

    public function __construct()
    {
        $this->i18n = Translator::fromEnv(__DIR__ . '/../config/locales');
        $this->icons = Config::loadPhp(__DIR__ . '/../config/icons.php');
        $this->sidebar = new SidebarPanel($this);
        $this->editor = new EditorPanel($this);
        $this->ai = new AiPanel($this);
        $this->terminal = new TerminalPanel($this);
        $this->statusBar = new StatusBarPanel($this);
        $this->git = new GitModel($this);
        // 注意不能用 static fn：静态闭包不绑定 $this，回调里取不到 terminal
        $this->lifecycle = new Lifecycle($this, fn() => $this->terminal->shutdown());
        $roots = $this->sidebar->tree()->roots;
        if (!empty($roots)) {
            $this->selectedPath = $roots[0]->path;
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

    /** 切换焦点面板（供面板回写，如侧栏点中条目后聚焦自己） */
    public function focus(string $panel): void
    {
        $this->focusIndex = array_search($panel, self::PANELS);
    }

    public function hasTabs(): bool
    {
        return $this->editor->hasTabs();
    }

    /** 指定路径的文件是否仍被打开（测试与未保存确认断言用） */
    public function hasBuffer(string $path): bool
    {
        return $this->editor->hasBuffer($path);
    }

    public function switchBuffer(string $path): void
    {
        $this->editor->switchBuffer($path);
    }

    /** 打开文件进编辑器（侧栏点文件 / Enter 都走这里） */
    public function openFile(string $path): void
    {
        $this->editor->openFile($path);
    }

    /** 请求退出（有未保存改动先弹确认） */
    public function requestQuit(): void
    {
        $this->lifecycle->requestQuit();
    }

    /** 请求关闭某个 buffer（dirty 时先弹确认；编辑器 Ctrl+W 走这里） */
    public function requestClose(string $path): void
    {
        $this->lifecycle->requestClose($path);
    }

    /** 设置状态栏瞬时消息（供面板回写，如终端中断命令后提示「已中断」） */
    public function setMessage(string $msg): void
    {
        $this->message = $msg;
    }

    /** 取翻译文案（供各面板使用，避免面板各自持有 Translator） */
    public function t(string $key, array $params = []): string
    {
        return $this->i18n->t($key, $params);
    }

    /**
     * 六个面板的矩形（命中测试与渲染共用），约束的唯一真身在 LayoutFactory。
     *
     * 同一帧内 handle() 与 render() 会各调一次，原来算两遍；这里按视口尺寸缓存，
     * 窗口 resize 时 key 自然失效、重新计算，对 bin/tui.php 零改动。
     *
     * 注意：返回的是缓存的那批 Area，调用方只读不写（php-tui 的 Area 属性并非 readonly，
     * 改了会污染后续帧）。
     */
    public function areas(Area $vp): array
    {
        $key = sprintf(
            '%d:%d:%d:%d',
            $vp->position->x,
            $vp->position->y,
            $vp->width,
            $vp->height
        );
        if ($key !== $this->areaKey) {
            $this->areaKey = $key;
            $this->areaCache = LayoutFactory::split($vp);
        }
        return $this->areaCache;
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
        $sidebarInner = $this->sidebar->content($a['sidebar'], $focus === 'sidebar');
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
            ->widget($this->editor->content($a['editor'], $focus === 'editor'));

        // ── Terminal（M2 命令运行器） ──
        $termTitle = ' ' . $this->i18n->t('panel.terminal') . ' ';
        if ($this->terminal->isRunning()) {
            $termTitle = ' ' . $this->i18n->t('panel.terminal') . ' · ' . $this->i18n->t('term.running') . ' ';
        }
        $terminal = BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderStyle($this->borderStyle($focus === 'terminal'))
            ->titles(Title::fromString($termTitle))
            ->widget($this->terminal->content($a['terminal'], $focus === 'terminal'));

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
        $status = BlockWidget::default()
            ->borders(Borders::NONE)
            ->widget($this->statusBar->content());

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(...LayoutFactory::rootConstraints())
            ->widgets($main, $status);
    }

    // ── 终端面板（M2 命令运行器） ──────────────────────
    /** 主循环每轮调用：排空命令管道（bin/tui.php 依赖此签名） */
    public function pollTerminal(): bool
    {
        return $this->terminal->poll();
    }

    /** 是否有命令在跑（bin/tui.php 依赖此签名） */
    public function termRunning(): bool
    {
        return $this->terminal->isRunning();
    }

    // ── 事件分发 ──
    public function handle($event, Area $vp): void
    {
        // 未保存确认进行中：拦截所有输入，只响应 y/n/Esc（及 Ctrl+Q 视为确认）
        if ($this->confirm !== null) {
            $this->lifecycle->handleEvent($event);
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
                && $this->terminal->isRunning()
                && ($event->modifiers & KeyModifiers::CONTROL)
                // 有些终端/解析器给的是原始字节 \x03 而不是带 CONTROL 修饰的 'c'
                && (strtolower($event->char) === 'c' || $event->char === "\x03")) {
                $this->terminal->cancel();
                return;
            }
            // 全局：Ctrl+Q 退出（若有未保存改动先弹确认）。
            // 退出热键原本是 Ctrl+C，但与「复制」冲突（习惯上 Ctrl+C 是复制，误按就退出了），
            // 故换成 Ctrl+Q；Ctrl+C 只保留「中断终端里正在跑的命令」这个终端固有语义。
            // 实测 php-tui/term 0.3.4 会把 0x11 解析成 CharKeyEvent(char:'q', modifiers:ctl)，
            // 故无需另兜底原始字节——若写上 `\x11` 分支反而是死代码（它排在 CONTROL 判定之后）。
            if (($event->modifiers & KeyModifiers::CONTROL) && strtolower($event->char) === 'q') {
                $this->lifecycle->requestQuit();
                return;
            }
            // AI 输入框
            if ($this->focusPanel() === 'ai_input') {
                $this->ai->onChar($event);
                return;
            }
            // 编辑器：键入即编辑
            if ($this->focusPanel() === 'editor') {
                $this->editor->onChar($event);
                return;
            }
            // 终端：可打印字符进命令输入行（q 也进输入行，不再直接退出）
            if ($this->focusPanel() === 'terminal') {
                $this->terminal->onChar($event);
                return;
            }
            // 其它面板：Enter 在侧栏展开/打开；q 退出（非输入态）
            if ($event->char === "\r" || $event->char === "\n") {
                if ($this->focusPanel() === 'sidebar') {
                    $this->sidebar->activate();
                }
                return;
            }
            // GIT tab 专用字符键（focus=sidebar 且当前在 GIT tab）
            if ($this->focusPanel() === 'sidebar' && $this->sidebar->tabIndex === 1) {
                $ch = strtolower($event->char);
                if ($ch === 'l') {
                    $this->git->toggleSubView();
                    return;
                }
                if ($ch === 'r') {
                    $this->git->refresh();
                    return;
                }
            }
            if (strtolower($event->char) === 'q') {
                $this->lifecycle->requestQuit();
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
                $this->sidebar->onScroll(MouseEventKind::ScrollDown);
            } elseif ($this->focusPanel() === 'terminal') {
                $this->terminal->scrollBy(3);
            }
            return;
        }
        if ($e->kind === MouseEventKind::ScrollUp) {
            if ($this->focusPanel() === 'editor') {
                $this->buffer?->pageUp(3);
            } elseif ($this->focusPanel() === 'sidebar') {
                $this->sidebar->onScroll(MouseEventKind::ScrollUp);
            } elseif ($this->focusPanel() === 'terminal') {
                $this->terminal->scrollBy(-3);
            }
            return;
        }
        if ($e->kind === MouseEventKind::Down) {
            $this->handleClick($e, $a);
        }
    }

    private function handleCoded(CodedKeyEvent $e, array $a): void
    {
        $focus = $this->focusPanel();

        // 焦点面板优先消费自己的按键；返回 false 的键（全局键 Esc/Tab、以及非本面板键）
        // 继续走下面的全局逻辑。各面板的 onKey 已按焦点分发出去，故这里不再判 $focus。
        if ($focus === 'editor'   && $this->editor->onKey($e, $a))   return;
        if ($focus === 'terminal' && $this->terminal->onKey($e, $a)) return;
        if ($focus === 'sidebar'  && $this->sidebar->onKey($e, $a))   return;

        switch ($e->code) {
            case KeyCode::Esc:
                // 终端：运行中→中断命令；有输入→清空输入；否则才退出
                // （Esc 不归任何面板的 onKey，故走到这里统一处理）
                if ($focus === 'terminal' && $this->terminal->isRunning()) {
                    $this->terminal->cancel();
                } elseif ($focus === 'terminal' && $this->terminal->input !== '') {
                    $this->terminal->clearInput();
                } else {
                    $this->lifecycle->requestQuit();
                }
                break;
            case KeyCode::Tab:
                // Ctrl+Tab 切 buffer 已由 EditorPanel::onKey 处理，这里只剩全局切焦点
                $this->focusIndex = ($this->focusIndex + 1) % count(self::PANELS);
                break;
            case KeyCode::Backspace:
                // AI 输入框退格（AiPanel 无 onKey，故留全局；终端退格已下沉到 TerminalPanel::onKey）
                if ($focus === 'ai_input') {
                    $this->ai->backspace();
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

        // 侧栏：tab 行 + 树条目（行首三角展开/折叠、双击展开、单击文件打开都由面板自己处理）
        if ($a['sidebar']->containsPosition($pos) && $this->sidebar->onClick($pos, $a)) {
            return;
        }

        // 命中测试：点哪个面板就聚焦哪个；点编辑器则按坐标定位光标（R6）
        foreach (self::PANELS as $key) {
            if ($a[$key]->containsPosition($pos)) {
                if ($key === 'editor') {
                    // 点 tab 栏切 buffer / 按坐标定位光标，都由编辑器面板处理（它会自己聚焦）
                    $this->editor->onClick($pos, $a);
                    return;
                }
                $this->focusIndex = array_search($key, self::PANELS);
                return;
            }
        }
    }

}

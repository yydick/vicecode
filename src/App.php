<?php
declare(strict_types=1);

namespace App;

use App\Core\Config;
use App\Core\KeyBindings;
use App\Core\KeyInput;
use App\Core\Lifecycle;
use App\Core\LayoutFactory;
use App\Core\LayoutConfig;
use App\Core\ConfigStore;
use App\Core\Theme;
use App\Editor\Buffer;
use App\Ai\ChatModel;
use App\Git\GitModel;
use App\I18n\Translator;
use App\Panel\AiPanel;
use App\Panel\EditorPanel;
use App\Panel\HelpPanel;
use App\Panel\MenuBarPanel;
use App\Panel\SidebarPanel;
use App\Panel\StatusBarPanel;
use App\Panel\TerminalPanel;
use App\Search\SearchModel;
use App\Text\DisplayWidth;
use App\Text\SpanClip;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event\FunctionKeyEvent;
use PhpTui\Term\Event\MouseEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\KeyModifiers;
use PhpTui\Term\MouseEventKind;
use PhpTui\Term\MouseButton;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Position\Position;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\CompositeWidget;
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

    /** 分隔条命中容差（列/行）：鼠标落在边界 ±1 内即算抓住分隔条，太窄不好点 */
    private const DRAG_TOL = 1;

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

    /** SEARCH 面板状态（M4 R1–R3）：输入框 / 异步 grep / 结果列表，供 Sidebar 的 SEARCH tab 读取 */
    public SearchModel $search;

    /** 当前配色主题（M6 R4）。面板一律通过 $this->theme->style('role') 取色，不硬编码颜色。 */
    public Theme $theme;

    /**
     * 可变布局配置（R5 拖拽分隔条）。侧栏宽 / AI 宽 / 编辑器比例 / AI 输入框高都从这里读，
     * 拖拽分隔条时整体替换本引用。仅本次会话有效，持久化留给 R7。
     */
    public LayoutConfig $layout;

    /**
     * 拖拽分隔条进行中的状态：null=未拖拽；否则 ['which'=>分隔条名,'horizontal'=>是否水平分隔条]。
     * which ∈ {'sidebar','ai','center','ai_input'}。由 handleMouse 的 Down/Drag/Up 维护。
     */
    private ?array $drag = null;

    /** 帮助页覆盖层（M6 R2）：全屏居中，打开期间独占键盘 */
    public HelpPanel $help;

    /** 顶部菜单栏（Backlog 接入）：F10 或点击激活，不进入焦点循环 */
    public MenuBarPanel $menuBar;

    /**
     * AI 对话状态（M5）：消息历史 / 流式生成 / Provider 选择。
     * 面板（AiPanel）只读它，不持有内容——与 GitModel / SearchModel 同构。
     */
    public ChatModel $chat;

    /** 底部状态栏 */
    public StatusBarPanel $statusBar;

    /** 退出与未保存确认状态机（R8） */
    public Lifecycle $lifecycle;

    public function __construct()
    {
        // R7：从 ~/.vicerc 加载持久化偏好。优先级 env > 配置文件 > 默认；
        // 配置文件缺失/损坏时 ConfigStore::load 返回空数组，下面全部走默认分支。
        $locDir = __DIR__ . '/../config/locales';
        $cfg = ConfigStore::load();
        $themeId = getenv('APP_THEME');
        if ($themeId === false || $themeId === '') {
            $themeId = $cfg['theme'] ?? null;
        }
        $this->theme = is_string($themeId) && $themeId !== '' ? Theme::byId($themeId) : Theme::default();
        $loc = getenv('APP_LOCALE');
        if ($loc === false || $loc === '') {
            $loc = $cfg['locale'] ?? null;
        }
        $this->i18n = is_string($loc) && $loc !== ''
            ? new Translator($loc, $locDir)
            : Translator::fromEnv($locDir);
        $this->layout = LayoutConfig::fromArray($cfg);

        $this->icons = Config::loadPhp(__DIR__ . '/../config/icons.php');
        $this->sidebar = new SidebarPanel($this);
        $this->editor = new EditorPanel($this);
        $this->ai = new AiPanel($this);
        $this->terminal = new TerminalPanel($this);
        $this->statusBar = new StatusBarPanel($this);
        $this->git = new GitModel($this);
        $this->search = new SearchModel($this);
        $this->chat = new ChatModel($this);
        $this->help = new HelpPanel($this);
        $this->menuBar = new MenuBarPanel($this);
        // 注意不能用 static fn：静态闭包不绑定 $this，回调里取不到 terminal
        $this->lifecycle = new Lifecycle($this, function () {
            $this->chat->shutdown();
            $this->search->shutdown();
            $this->terminal->shutdown();
        });
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

    /** 请求丢弃某文件工作区改动（不可逆，先弹 y/n 确认；GIT 面板 discard 图标走这里） */
    public function requestDiscard(string $path): void
    {
        $this->lifecycle->requestDiscard($path);
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
     * 窗口 resize 时 key 自然失效、重新计算，对 bin/vicecode.php 零改动。
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
            $this->areaCache = LayoutFactory::split($vp, $this->layout);
        }
        return $this->areaCache;
    }

    private function borderStyle(bool $focused): Style
    {
        return $this->theme->style($focused ? 'borderFocus' : 'border');
    }

    public function render(Area $vp): Widget
    {
        $base = $this->build($this->areas($vp));
        $overlays = [];
        // 菜单下拉是覆盖层：底层 UI 先画，下拉浮在主区之上（与帮助页同机制）。
        // 菜单与帮助同一时刻只有一个打开，故叠加顺序无所谓。
        if ($this->menuBar->isOpen()) {
            $overlays[] = $this->menuBar->dropdownWidget($vp->width, $vp->height);
        }
        if ($this->help->isOpen()) {
            $overlays[] = $this->help->widget($vp->width, $vp->height);
        }
        if ($this->help->isAboutOpen()) {
            $overlays[] = $this->help->aboutWidget($vp->width, $vp->height);
        }
        if ($overlays === []) {
            return $base;
        }
        // 覆盖层：CompositeWidget 把多个 widget 渲染到**同一个 area**
        // （php-tui 注释原话是 "useful for showing dialogues"），底层 UI 先画、
        // 覆盖层再浮在上面。这样底下仍看得见自己在哪个面板。
        return CompositeWidget::fromWidgets($base, ...$overlays);
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
            ->constraints(...LayoutFactory::centerConstraints($this->layout))
            ->widgets($editor, $terminal);

        // ── AI Stream ──
        // 标题带上当前 Provider/模型：切换后要能立刻看见生效的是谁
        // （输入面板只有 1 行可用，放不下独立的状态行）
        $spec = $this->chat->spec();
        $aiTitle = ' ' . $this->i18n->t('panel.ai_chat')
            . ($spec !== null ? ' · ' . $spec->label . '/' . $spec->model : '')
            . ($this->chat->isStreaming() ? ' …' : '') . ' ';
        $aiStream = BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderStyle($this->borderStyle($focus === 'ai_stream'))
            ->titles(Title::fromString($aiTitle))
            ->widget($this->ai->streamContent(
                max(0, ($a['ai_stream']->width ?? 0) - 2),
                max(0, ($a['ai_stream']->height ?? 0) - 2),
            ));

        // ── AI Input ──
        $aiInput = BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderStyle($this->borderStyle($focus === 'ai_input'))
            ->titles(Title::fromString(' ' . $this->i18n->t('panel.ai_input') . ' ' . $this->i18n->t('ai.input_hint') . ' '))
            ->widget($this->ai->inputContent());

        $ai = GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(...LayoutFactory::aiConstraints($this->layout))
            ->widgets($aiStream, $aiInput);

        // ── 主区 ──
        $main = GridWidget::default()
            ->direction(Direction::Horizontal)
            ->constraints(...LayoutFactory::mainConstraints($this->layout))
            ->widgets($sidebar, $center, $ai);

        // ── StatusBar ──
        // 传入可视宽度：状态栏要在放不下时**按优先级丢弃**低优先级段，
        // 而不是让 php-tui 从尾部硬切（那样窄屏下文件名和瞬时消息会整个消失）。
        $status = BlockWidget::default()
            ->borders(Borders::NONE)
            ->widget($this->statusBar->content(max(0, ($a['status']->width ?? 0))));

        // 根 Grid 的段数必须与 split() 切出的矩形数量一致：矮视口不画菜单栏时
        // split() 只返回 main+status 两段，这里也必须只放两个 widget，否则 php-tui
        // 会用多余约束去切不存在的 area（抛 OutOfBoundsException）。
        $withMenu = isset($a['menu']);
        if ($withMenu) {
            $menu = BlockWidget::default()
                ->borders(Borders::NONE)
                ->widget($this->menuBar->content($a['menu']));
            // 有菜单栏时 rootConstraints() 默认按高视口返回 3 段（menu/main/status），与 3 个 widget 对应
            return GridWidget::default()
                ->direction(Direction::Vertical)
                ->constraints(...LayoutFactory::rootConstraints())
                ->widgets($menu, $main, $status);
        }
        // 矮视口不画菜单栏：rootConstraints(0) 返回 2 段（main/status），与 2 个 widget 对应
        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(...LayoutFactory::rootConstraints(0))
            ->widgets($main, $status);
    }

    // ── 终端面板（M2 命令运行器） ──────────────────────
    /** 主循环每轮调用：排空命令管道（bin/vicecode.php 依赖此签名） */
    public function pollTerminal(): bool
    {
        return $this->terminal->poll();
    }

    /** 是否有命令在跑（bin/vicecode.php 依赖此签名） */
    public function termRunning(): bool
    {
        return $this->terminal->isRunning();
    }

    /** 是否有搜索在跑（bin/vicecode.php 依赖此签名） */
    public function searchRunning(): bool
    {
        return $this->search->running;
    }

    /** 排空搜索管道；返回本帧是否有「搜索完成」事件需要重绘（M4 R2） */
    public function pollSearch(): bool
    {
        return $this->search->poll();
    }

    /** 是否有 AI 回复在生成（bin/vicecode.php 依赖此签名） */
    public function aiStreaming(): bool
    {
        return $this->chat->isStreaming();
    }

    /** 排空 AI 流式管道；返回本帧是否产生了新 token（M5 R2） */
    public function pollAi(): bool
    {
        return $this->chat->poll();
    }

    // ── 事件分发 ──
    // ── 主题（M6 R4）─────────────────────────────────

    /**
     * 环形切换到下一个主题。
     *
     * ⚠️ 必须让语法高亮缓存失效：hlLines 里存的是**已带 Style 的实例**，
     * 不重置的话换主题后代码区域仍是旧配色（看起来像"主题只换了一半"）。
     * 缓存键是 Buffer::$hlRev，把它推到与 rev 不同即可触发下一帧重算。
     */
    public function cycleTheme(): void
    {
        $ids = Theme::ids();
        $cur = array_search($this->theme->id, $ids, true);
        $next = $ids[(($cur === false ? 0 : $cur) + 1) % count($ids)];
        $this->theme = Theme::byId($next);
        foreach ($this->editor->buffers() as $buf) {
            $buf->hlRev = -1; // 强制下一帧重算高亮
        }
        $this->setMessage($this->t('status.theme') . '=' . $this->theme->label);
    }

    // ── 语言（顶部菜单「视图 → 语言」）────────────────────

    /** 在可用语言间环形切换；setMessage 提示当前语言。 */
    public function toggleLocale(): void
    {
        $langs = $this->i18n->available();
        $cur = array_search($this->i18n->locale(), $langs, true);
        $next = $langs[(($cur === false ? 0 : $cur) + 1) % count($langs)];
        $this->i18n->setLocale($next);
        $this->setMessage($this->t('status.lang') . '=' . $next);
    }

    // ── 菜单动作（顶部菜单栏每项都对应这里一个真实方法）──

    /** 执行菜单项 action（见 MenuBarPanel::definitions 的 action 字段）。 */
    public function menuAction(string $id): void
    {
        switch ($id) {
            case 'file.open':
                $this->focus('sidebar');
                break;
            case 'file.save':
                $this->editor->save();
                break;
            case 'file.close':
                if ($this->buffer !== null) {
                    $this->requestClose((string) $this->buffer->path);
                }
                break;
            case 'file.quit':
                $this->lifecycle->requestQuit();
                break;
            case 'view.theme':
                $this->cycleTheme();
                break;
            case 'view.focus.editor':
                $this->focus('editor');
                break;
            case 'view.focus.terminal':
                $this->focus('terminal');
                break;
            case 'view.focus.explorer':
                $this->focus('sidebar');
                break;
            case 'view.focus.ai':
                $this->focus('ai_stream');
                break;
            case 'view.lang':
                $this->toggleLocale();
                break;
            case 'term.cancel':
                $this->terminal->cancel();
                break;
            case 'term.clear':
                $this->terminal->clear();
                break;
            case 'help.shortcuts':
                $this->help->open();
                break;
            case 'help.about':
                $this->help->openAbout();
                break;
        }
    }

    public function handle($event, Area $vp): void
    {
        // 未保存确认进行中：拦截所有输入，只响应 y/n/Esc（及 Ctrl+Q 视为确认）
        if ($this->confirm !== null) {
            $this->lifecycle->handleEvent($event);
            return;
        }

        $a = $this->areas($vp);

        // F10 激活/收起菜单栏：真实 pty 下是 **FunctionKeyEvent**（独立类，带 number 属性），
        // 不是 CodedKeyEvent——php-tui 把 F1..F12 都归到 FunctionKeyEvent(number=N)，
        // 而 KeyCode 枚举没有 F1..F12 成员。故这里必须用 FunctionKeyEvent 判定，
        // 放在菜单独占拦截之前，确保「打开时再按 F10 收起」也能命中。
        // （之前误写成 CodedKeyEvent + $event->number，而 CodedKeyEvent 根本没有 number，
        //  导致 F10 在真实 pty 下完全不生效——只有 headless 单测喂 FunctionKeyEvent 才暴露。）
        if ($event instanceof FunctionKeyEvent && $event->number === 10) {
            $this->menuBar->toggle();
            return;
        }

        // 菜单栏打开期间**独占键盘**（模态）：不往下层面板分发，否则在菜单里按方向键
        // 会顺带移动编辑器光标、按 Enter 会插进文档。Esc/方向/Enter 全归菜单自己。
        if ($this->menuBar->isOpen()) {
            if ($event instanceof MouseEvent) {
                $this->handleMouse($event, $a, $vp); // 点击命中由 handleMouse 内部判定
                return;
            }
            if ($event instanceof CodedKeyEvent && $this->menuBar->onKey($event)) {
                return;
            }
            // 孤立 ESC 在真实 pty 下常被解析成 CharKeyEvent(char="\x1b") 而非 CodedKeyEvent
            // （解析器要等一会儿才区分「独立 Esc」与「转义序列开头」），故补一路：
            // 把「ESC 字符」当作 Esc 键关掉菜单，否则菜单关不掉。
            if ($event instanceof CharKeyEvent && $event->char === "\x1b") {
                $this->menuBar->close();
                return;
            }
            return; // 其余字符键一律吞掉
        }

        // 帮助页/关于页打开期间**独占键盘**：不往下层面板分发，否则在帮助页里按 q
        // 会顺带把应用退了（q 是全局"非输入态退出"）。滚轮交给帮助页翻页。
        if ($this->help->isOpen() || $this->help->isAboutOpen()) {
            $viewH = $this->help->viewHeightFor($vp->width, $vp->height);
            if ($event instanceof MouseEvent) {
                if ($this->help->isOpen()) {
                    if ($event->kind === MouseEventKind::ScrollUp) {
                        $this->help->scrollBy(-3);
                    } elseif ($event->kind === MouseEventKind::ScrollDown) {
                        $this->help->scrollBy(3);
                    }
                }
                return;
            }
            // 关于页：Esc/?/q 关闭；其它键吞掉
            if ($this->help->isAboutOpen()) {
                if ($event instanceof CharKeyEvent && (KeyBindings::HELP_KEY === $event->char || strtolower($event->char) === 'q')) {
                    $this->help->closeAbout();
                } elseif ($event instanceof CodedKeyEvent && $event->code === \PhpTui\Term\KeyCode::Esc) {
                    $this->help->closeAbout();
                }
                return;
            }
            if ($event instanceof CharKeyEvent && $this->help->onChar($event)) {
                return;
            }
            if ($event instanceof CodedKeyEvent && $this->help->onKey($event, $viewH)) {
                return;
            }
            return; // 其余键一律吞掉：帮助页是模态的
        }

        if ($event instanceof MouseEvent) {
            $this->handleMouse($event, $a, $vp);
            return;
        }

        if ($event instanceof CharKeyEvent) {
            // 「?」唤出帮助页。必须在任何面板拿到字符之前拦截——
            // 否则在编辑器里按 ? 会把它插进文档、在 AI 输入框会把它打进消息。
            if ($event->char === KeyBindings::HELP_KEY) {
                $this->help->open();
                return;
            }
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
            // Ctrl+T 切主题（M6 R4）。必须在面板拿到字符之前处理，
            // 否则在编辑器/输入框里按它会把字符打进去。
            if (($event->modifiers & KeyModifiers::CONTROL) && strtolower($event->char) === 't') {
                $this->cycleTheme();
                return;
            }
            // AI 输入/消息流：键入进输入框；Ctrl+P 切 Provider、Ctrl+N 切模型、
            // Ctrl+L 清空 —— 消息流聚焦时也要能用（不要求在输入框才能切）。
            $f = $this->focusPanel();
            if ($f === 'ai_input' || $f === 'ai_stream') {
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
            // GIT tab 交互（focus=sidebar 且当前在 GIT tab）：可视化图标 + 输入框 + 按钮，
            // 不再用字母快捷键。默认输入进提交信息框；+/- 暂存/取消暂存选中；Enter 提交。
            if ($this->focusPanel() === 'sidebar' && $this->sidebar->tabIndex === 1) {
                if ($this->git->branchDropdownOpen) {
                    return; // 分支下拉打开时，字符键不进提交框、也不做 +/- 操作
                }
                if ($event instanceof CodedKeyEvent) {
                    return; // GIT 的 Coded 键（Backspace/Enter/Esc/方向）由下面统一分支处理
                }
                $ch = $event->char;
                if ($ch === '+' || $ch === '=') {   // = 常为 Shift++，统一当 +
                    $this->git->stageSelected();
                    return;
                }
                if ($ch === '-') {
                    $this->git->unstageSelected();
                    return;
                }
                // 其余可打印字符进提交信息输入框
                if (KeyInput::isPrintable($ch) && !($event->modifiers & KeyModifiers::CONTROL)) {
                    $this->git->commitMsg .= $ch;
                }
                return;
            }
            // SEARCH tab 交互（focus=sidebar 且当前在 SEARCH tab）：可打印字符进搜索框，
            // 随时重新进入编辑态；Coded 键（Backspace/Enter/方向）由 onKey/searchKey 处理。
            if ($this->focusPanel() === 'sidebar' && $this->sidebar->tabIndex === 2) {
                if ($event instanceof CodedKeyEvent) {
                    return; // 交给 onKey → searchKey 统一处理
                }
                $ch = $event->char;
                if (KeyInput::isPrintable($ch) && !($event->modifiers & KeyModifiers::CONTROL)) {
                    $this->search->query .= $ch;
                    $this->search->editingQuery = true;
                }
                return;
            }
            if (strtolower($event->char) === 'q') {
                $this->lifecycle->requestQuit();
            }
            return;
        }

        if ($event instanceof CodedKeyEvent) {
            // ⚠️ F10 的激活/收起已在上方作为 FunctionKeyEvent 单独处理（php-tui 把功能键
            // 归到 FunctionKeyEvent，而非 CodedKeyEvent，见 App::handle 顶部的注释）。
            $this->handleCoded($event, $a);
        }
    }

    private function handleMouse(MouseEvent $e, array $a, Area $vp): void
    {
        // ── 拖拽分隔条（R5）：按住左键移动 = Drag，松开 = Up ──
        // 这两类在 Down 之前拦截：Drag 时若正处于拖拽则更新布局；Up 一律结束拖拽。
        // 没按按钮的 Moved 悬停不触发拖拽（避免误拖）。
        if ($e->kind === MouseEventKind::Drag) {
            if ($this->drag !== null) {
                $this->updateDrag($e, $a, $vp);
            }
            return;
        }
        if ($e->kind === MouseEventKind::Up) {
            $this->drag = null;
            return;
        }
        if ($e->kind === MouseEventKind::Moved) {
            return;
        }

        if ($e->kind === MouseEventKind::ScrollDown) {
            if ($this->focusPanel() === 'editor') {
                $this->buffer?->pageDown(3);
            } elseif ($this->focusPanel() === 'sidebar') {
                $this->sidebar->onScroll(MouseEventKind::ScrollDown);
            } elseif ($this->focusPanel() === 'terminal') {
                $this->terminal->scrollBy(3);
            } elseif ($this->focusPanel() === 'ai_stream' || $this->focusPanel() === 'ai_input') {
                $this->ai->onScroll(MouseEventKind::ScrollDown);
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
            } elseif ($this->focusPanel() === 'ai_stream' || $this->focusPanel() === 'ai_input') {
                $this->ai->onScroll(MouseEventKind::ScrollUp);
            }
            return;
        }
        if ($e->kind === MouseEventKind::ScrollLeft || $e->kind === MouseEventKind::ScrollRight) {
            $step = $e->kind === MouseEventKind::ScrollRight ? 4 : -4;
            if ($this->focusPanel() === 'editor') {
                $this->editor->onScrollH($step);
            } elseif ($this->focusPanel() === 'sidebar') {
                $this->sidebar->onScrollH($step);
            } elseif ($this->focusPanel() === 'terminal') {
                $this->terminal->onScrollH($step);
            } elseif ($this->focusPanel() === 'ai_stream' || $this->focusPanel() === 'ai_input') {
                $this->ai->onScrollH($step);
            }
            return;
        }
        if ($e->kind === MouseEventKind::Down) {
            // 菜单栏点击：菜单打开时点下拉条目/空白关闭；关闭时点菜单标签激活。
            // 菜单栏在第 0 行，与 PANELS 各面板不重叠（split 已把它切到独立区域）。
            if ($this->menuBar->isOpen()) {
                $this->menuBar->clickDropdown($e->column, $e->row);
                return;
            }
            if (isset($a['menu']) && $e->row === $a['menu']->position->y) {
                if ($this->menuBar->clickBar($e->column)) {
                    return;
                }
            }
            // 分隔条拖拽优先于普通点击：命中任一条分隔条就进入拖拽，不触发聚焦/打开。
            if ($this->tryStartDrag($e, $a)) {
                return;
            }
            $this->handleClick($e, $a);
        }
    }

    /**
     * 判定鼠标按下是否命中某条分隔条。命中则记下拖拽目标并返回 true（调用方据此不再走点击逻辑）。
     * 四条可拖边界：侧栏右、AI 左（竖直）；编辑器下、AI 输入框上（水平）。
     * 命中窗口取边界 ±DRAG_TOL，且限制在对应面板的范围内，避免误抓相邻面板内部。
     * @param array<string,Area> $a
     */
    private function tryStartDrag(MouseEvent $e, array $a): bool
    {
        $tol = self::DRAG_TOL;
        $mainTop = $a['sidebar']->position->y;
        $mainBottom = $a['status']->position->y; // 状态栏起始 = 主区底部

        // 竖分隔条①：侧栏右边界（x = sidebar.x + sidebar.width）
        $sidebarEdge = $a['sidebar']->position->x + $a['sidebar']->width;
        if (abs($e->column - $sidebarEdge) <= $tol
            && $e->row >= $mainTop && $e->row < $mainBottom) {
            $this->drag = ['which' => 'sidebar', 'horizontal' => false];
            return true;
        }

        // 竖分隔条②：AI 列左边界（x = ai_stream.x）
        $aiEdge = $a['ai_stream']->position->x;
        if (abs($e->column - $aiEdge) <= $tol
            && $e->row >= $mainTop && $e->row < $mainBottom) {
            $this->drag = ['which' => 'ai', 'horizontal' => false];
            return true;
        }

        // 横分隔条①：编辑器下边界（y = editor.y + editor.height），且仅在中间列水平范围内
        $centerX = $a['editor']->position->x;
        $centerW = $a['editor']->width;
        $editorEdge = $a['editor']->position->y + $a['editor']->height;
        if (abs($e->row - $editorEdge) <= $tol
            && $e->column >= $centerX && $e->column < $centerX + $centerW) {
            $this->drag = ['which' => 'center', 'horizontal' => true];
            return true;
        }

        // 横分隔条②：AI 消息流下边界（y = ai_stream.y + ai_stream.height），且在 AI 列水平范围内
        $aiX = $a['ai_stream']->position->x;
        $aiW = $a['ai_stream']->width;
        $aiEdgeY = $a['ai_stream']->position->y + $a['ai_stream']->height;
        if (abs($e->row - $aiEdgeY) <= $tol
            && $e->column >= $aiX && $e->column < $aiX + $aiW) {
            $this->drag = ['which' => 'ai_input', 'horizontal' => true];
            return true;
        }

        return false;
    }

    /**
     * 拖拽进行中：按当前鼠标坐标更新 LayoutConfig。任何交叉约束（侧栏+AI 不能挤没中间列、
     * AI 输入框不能吞掉消息流）都在这里用视口/相邻面板几何算好上下界，再喂进 with*。
     * 末尾使布局矩形缓存失效，下一帧 render 即按新尺寸重算。
     * @param array<string,Area> $a
     */
    private function updateDrag(MouseEvent $e, array $a, Area $vp): void
    {
        switch ($this->drag['which']) {
            case 'sidebar':
                // 新宽度 = 鼠标列 - 侧栏起点；上界受「视口 - AI 宽 - 中间列最小宽」限制
                $maxW = $vp->width - $this->layout->aiWidth - LayoutConfig::MIN_CENTER;
                $w = max(LayoutConfig::MIN_SIDEBAR, min($e->column - $a['sidebar']->position->x, $maxW));
                $this->layout = $this->layout->withSidebarWidth($w);
                break;
            case 'ai':
                // 新宽度 = 视口右沿 - 鼠标列
                $maxW = $vp->width - $this->layout->sidebarWidth - LayoutConfig::MIN_CENTER;
                $w = max(LayoutConfig::MIN_AI, min(($vp->position->x + $vp->width) - $e->column, $maxW));
                $this->layout = $this->layout->withAiWidth($w);
                break;
            case 'center':
                // 比例 = 鼠标行到编辑器顶 / 中间列总高
                $centerH = $a['editor']->height + $a['terminal']->height;
                $ratio = $centerH > 0 ? ($e->row - $a['editor']->position->y) / $centerH : 0.5;
                $this->layout = $this->layout->withEditorRatio($ratio);
                break;
            case 'ai_input':
                // 新高度 = AI 列底沿 - 鼠标行；上界受「AI 列总高 - 消息流最小行」限制
                $aiH = $a['ai_stream']->height + $a['ai_input']->height;
                $maxH = $aiH - LayoutConfig::MIN_AI_STREAM;
                $h = max(LayoutConfig::MIN_AI_INPUT, min(($a['ai_stream']->position->y + $aiH) - $e->row, $maxH));
                $this->layout = $this->layout->withAiInputHeight($h);
                break;
        }
        // 视口未变但布局变了：清缓存，下一帧 areas() 用新 layout 重算矩形
        $this->areaKey = '';
    }

    /** 是否正在拖拽分隔条（供状态栏决定是否显示尺寸） */
    public function isDragging(): bool
    {
        return $this->drag !== null;
    }

    /** 拖拽时状态栏显示的布局摘要（如「侧栏30 AI45 编辑60% 输入3」），走 i18n */
    public function layoutSummary(): string
    {
        $c = $this->layout;
        return sprintf(
            '%s%d %s%d %s%d%% %s%d',
            $this->t('status.l_sidebar'),
            $c->sidebarWidth,
            $this->t('status.l_ai'),
            $c->aiWidth,
            $this->t('status.l_editor'),
            (int) round($c->editorRatio * 100),
            $this->t('status.l_input'),
            $c->aiInputHeight,
        );
    }

    /**
     * 退出时把当前偏好写入 ~/.vicerc（R7）。由 bin/vicecode.php 的退出 finally 调用，
     * 因此「拖拽改布局 / Ctrl+T 切主题 / 切语言」这些运行时变更都能在下次启动恢复。
     * 失败静默吞掉——配置没存成只是不恢复，绝不该妨碍退出或终端还原。
     * @return bool 是否写入成功（测试用）
     */
    public function saveConfig(): bool
    {
        try {
            return ConfigStore::save([
                'layout' => [
                    'sidebarWidth' => $this->layout->sidebarWidth,
                    'aiWidth' => $this->layout->aiWidth,
                    'editorRatio' => $this->layout->editorRatio,
                    'aiInputHeight' => $this->layout->aiInputHeight,
                ],
                'theme' => $this->theme->id,
                'locale' => $this->i18n->locale(),
            ]);
        } catch (\Throwable $e) {
            return false;
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
        // AI：↑/↓ 历史、PgUp/PgDn 滚动、Esc 停止生成（不生成时返回 false → 走全局退出）
        if (($focus === 'ai_input' || $focus === 'ai_stream') && $this->ai->onKey($e, $a)) return;

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
                // AI 输入框退格（AiPanel::onKey 已处理，这里是历史遗留的空分支，保留以防
                // 将来改焦点分发时漏掉；终端退格已下沉到 TerminalPanel::onKey）
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

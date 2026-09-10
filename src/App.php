<?php
declare(strict_types=1);

namespace App;

use Throwable;
use App\Core\Config;
use App\Core\KeyBindings;
use App\Core\KeyInput;
use App\Core\Lifecycle;
use App\Core\LayoutFactory;
use App\Core\LayoutConfig;
use App\Core\Clipboard;
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
use App\Panel\PluginsPanel;
use App\Panel\CommandPalettePanel;
use App\Panel\PluginPanelHost;
use App\Panel\SidebarPanel;
use App\Panel\StatusBarPanel;
use App\Panel\TerminalPanel;
use App\Plugin\PluginCommand;
use App\Plugin\PluginEvent;
use App\Plugin\PluginInterface;
use App\Plugin\PluginLoader;
use App\Search\SearchModel;
use App\Terminal\KeyToPty;
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
use PhpTui\Tui\Widget\HorizontalAlignment;
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

    /**
     * 分隔条命中容差（列/行）。
     * 0 = 仅精确命中分隔条所在的那一列/行才进入拖拽；面板内部（距边界 1 格内的
     * 末行/末列，常是光标或文本所在处）的点击交给 handleClick 做聚焦/按钮，
     * 不再被拖拽窗口吞掉。早期取 1（±1 容差）是为了"分隔条太窄不好点"，
     * 但代价是面板边缘内的点击会被误判为拖拽——收敛到 0 优先保证边缘内部点击可用。
     */
    private const DRAG_TOL = 0;

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

    /**
     * 文本选择（鼠标拖拽）进行中的状态：null=无选择。
     * 结构：['panel'=>'editor'|'terminal','aRow','aCol','bRow','bCol']，坐标均为视口绝对行列。
     * a=锚点（Down 时记），b=头点（Drag 时更新）。Up 时若 a≠b 则按矩形取字写入剪贴板，
     * 并保留高亮直到下次 Down 清掉。与 $drag（分隔条拖拽）互斥：tryStartDrag 先 return。
     */
    private ?array $select = null;

    /** 剪贴板写入服务（复制：OSC 52 或降级内存） */
    private Clipboard $clip;

    /** 粘贴目标面板（tty 异步读取时暂存，响应回来即插入该面板）；null=无进行中的粘贴 */
    private ?string $pasteTarget = null;

    /** 帮助页覆盖层（M6 R2）：全屏居中，打开期间独占键盘 */
    public HelpPanel $help;

    /** 顶部菜单栏（Backlog 接入）：F10 或点击激活，不进入焦点循环 */
    public MenuBarPanel $menuBar;

    /** 插件管理浮层（V1.1 入口）：菜单「文件 → 已安装插件」唤出，列出插件与配置文件路径 */
    public PluginsPanel $pluginsPanel;

    /** 命令面板（F1 唤出）：汇聚系统命令与插件命令，模糊过滤 + 键盘选择，走 menuAction 分发 */
    public CommandPalettePanel $palette;

    /** 自定义面板浮层宿主（V1.1）：汇聚所有插件的 PluginPanel，内部 tab 切换 */
    public PluginPanelHost $panelHost;

    /** 用户插件配置原始覆盖（来自 ~/.vicecode.plugins.json 的 <id> 段，供 PluginsPanel 展示有效配置） */
    public array $userPluginConfig = [];

    /**
     * AI 对话状态（M5）：消息历史 / 流式生成 / Provider 选择。
     * 面板（AiPanel）只读它，不持有内容——与 GitModel / SearchModel 同构。
     */
    public ChatModel $chat;

    /** 底部状态栏 */
    public StatusBarPanel $statusBar;

    /**
     * 已加载的插件（V1：运行时动态加载，目录扫描 plugins 下各子目录的 plugin.json + 运行时 require）。
     * 由 PluginLoader 在构造末尾填充；插件文件不在 composer autoload 内，
     * 产品版由内嵌 Zend 运行时解释（见 project_plugin.md）。
     * @var list<\App\Plugin\PluginInterface>
     */
    public array $plugins = [];

    /** 退出与未保存确认状态机（R8） */
    public Lifecycle $lifecycle;

    // ── V1.1 插件交互（命令 / 快捷键 / 事件）─────────────────────
    // 注册发生在 App 构造末尾（装载期一次性），之后每帧只读这些表：
    // 菜单项形状恒定是 MenuBarPanel 索引稳定性的前提（见 MenuBarPanel::definitions）。

    /** 完全限定命令 id（"<插件id>.<局部id>"） => ['p'=>插件, 'c'=>PluginCommand] */
    private array $pluginCommands = [];

    /** 归一化快捷键 => 完全限定命令 id（只有绑定成功的才入表） */
    private array $pluginShortcuts = [];

    /** 完全限定命令 id => ['reason'=>…, 'owner'=>…]（供插件页给出可见提示） */
    private array $pluginConflicts = [];

    /** 装载期冻结的菜单项快照（每帧只读，形状不变） */
    private array $pluginMenuSnapshot = [];

    /** 装载期汇聚的插件面板（list<array{plugin:string, panel:\App\Plugin\PluginPanel}>），供浮层宿主读取 */
    private array $pluginPanels = [];

    /** 事件重入保护：插件在回调里又触发事件时丢弃内层 */
    private bool $inPluginEvent = false;

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
        $this->pluginsPanel = new PluginsPanel($this);
        $this->palette = new CommandPalettePanel($this);
        $this->panelHost = new PluginPanelHost($this);
        $this->clip = new Clipboard();        // 注意不能用 static fn：静态闭包不绑定 $this，回调里取不到 terminal
        $this->lifecycle = new Lifecycle($this, function (): void {
            $this->chat->shutdown();
            $this->search->shutdown();
            $this->terminal->shutdown();
        });
        // 退出时若开启持久化，shutdown 闭包会经 terminal->saveSession() 存盘；
        // 这里在构造末尾尝试恢复上次的 pty 会话（开启且存在快照时）。
        $this->terminal->maybeRestore();
        $roots = $this->sidebar->tree()->roots;
        if (!empty($roots)) {
            $this->selectedPath = $roots[0]->path;
        }
        // V1 插件：运行时动态加载（目录扫描 + 运行时 require，避开 composer autoload 的
        // Closure::bind 以兼容 AOT 产品版）。单个插件失败已在 Loader 内跳过，不影响启动。
        // VICECODE_PLUGINS_DIR 可把插件根目录指到别处（测试用；仿 ConfigStore::pluginsPath()
        // 的 env 覆盖惯例）—— 这样测试能在 tmp 下造命令型插件而不污染 plugins/。
        $pluginBase = getenv('VICECODE_PLUGINS_DIR');
        $this->plugins = PluginLoader::load(
            (is_string($pluginBase) && $pluginBase !== '') ? $pluginBase : __DIR__ . '/..'
        );
        // 插件配置注入：VSCode 式「插件声明默认（configDefaults）+ 用户配置覆盖」。
        // 可选能力——插件若实现 configure()/configDefaults() 则注入合并后的配置，否则跳过
        // （不影响未实现它们的插件）。用户配置来自插件专用文件 ConfigStore::loadPlugins()。
        $userPlugins = ConfigStore::loadPlugins();
        $this->userPluginConfig = $userPlugins;
        foreach ($this->plugins as $plugin) {
            $defaults = method_exists($plugin, 'configDefaults')
                ? $plugin->configDefaults()
                : [];
            $user = $userPlugins[$plugin->id()] ?? [];
            if (is_array($user) && method_exists($plugin, 'configure')) {
                $plugin->configure(array_merge($defaults, $user));
            }
        }
        // 命令在**装载期**注册一次即可（不重新 require 插件，重注册只会产生 duplicate 冲突）
        $this->registerPluginCommands();
        // 面板同样在装载期汇聚一次（与命令同机制：冻结快照，之后每帧只读）
        $this->collectPluginPanels();
        $this->emitPluginEvent('app.ready');
    }

    /**
     * 计算单个插件的「有效配置」= 默认(configDefaults) ∩ 用户 ~/.vicerc 覆盖。
     * 与构造期注入用的是同一套合并逻辑，供 PluginsPanel / SidebarPanel 展示当前生效值。
     * @return array<string,mixed>
     */
    public function pluginEffectiveConfig(\App\Plugin\PluginInterface $p): array
    {
        $defaults = method_exists($p, 'configDefaults') ? $p->configDefaults() : [];
        $user = $this->userPluginConfig[$p->id()] ?? [];
        return is_array($user) ? array_merge($defaults, $user) : $defaults;
    }

    /**
     * 在 ViceCode 自己的编辑器里打开**插件专用配置文件**（~/.vicecode.plugins.json，
     * 与 ~/.vicerc 分离）供编辑（VSCode「打开设置(JSON)」同款，而非甩给外部编辑器）。
     * 文件不存在时，先落一份含当前生效插件配置的专用文件，避免打开空文件把配置冲掉。
     */
    public function openPluginConfig(): void
    {
        $path = ConfigStore::pluginsPath();
        if (!is_file($path)) {
            $data = [];
            foreach ($this->plugins as $p) {
                $cfg = $this->pluginEffectiveConfig($p);
                if ($cfg !== []) {
                    $data[$p->id()] = $cfg;
                }
            }
            ConfigStore::savePlugins($data);
        }
        $this->editor->openFile($path);
    }

    /**
     * 重新加载插件配置（VSCode「重载窗口」的平替，但无需退出进程）。
     * 重读专用插件配置文件（与 ~/.vicerc 分离），按「默认 ∩ 用户覆盖」重新注入每个插件。
     */
    public function reloadPluginConfig(): void
    {
        $userPlugins = ConfigStore::loadPlugins();
        $this->userPluginConfig = $userPlugins;
        foreach ($this->plugins as $plugin) {
            $defaults = method_exists($plugin, 'configDefaults') ? $plugin->configDefaults() : [];
            $user = $userPlugins[$plugin->id()] ?? [];
            if (is_array($user) && method_exists($plugin, 'configure')) {
                $plugin->configure(array_merge($defaults, $user));
            }
        }
        $this->emitPluginEvent('config.reloaded');
    }

    // ── V1.1：插件命令注册与执行 ──────────────────────────────

    /**
     * 装载期一次性注册插件命令，并冻结菜单快照。
     *
     * 容错哲学与 PluginLoader 一致：任一插件出错都只影响它自己（记进冲突表并在插件页提示），
     * 不让它带崩启动或影响别的插件。命令 id 一律加插件 id 前缀，跨插件天然不撞。
     *
     * ⚠️ 只在构造末尾调用一次：热重载不重新 require 插件，重注册只会产生 duplicate 冲突。
     */
    private function registerPluginCommands(): void
    {
        foreach ($this->plugins as $p) {
            if (!method_exists($p, 'commands')) {
                continue;                       // V1 老插件（如 clock）零负担
            }
            if (!method_exists($p, 'executeCommand')) {
                // 声明了命令却没有执行体 → 全部不可用，但要在插件页说清楚原因
                $this->pluginConflicts[$p->id() . '.*'] = ['reason' => 'no_executor', 'owner' => ''];
                continue;
            }
            try {
                $cmds = $p->commands();
            } catch (Throwable $e) {
                $this->pluginConflicts[$p->id() . '.*'] = ['reason' => 'commands_threw', 'owner' => ''];
                continue;
            }
            if (!is_array($cmds)) {
                continue;
            }
            foreach ($cmds as $c) {
                if (!$c instanceof PluginCommand) {
                    continue;                   // 类型防御：插件返回了别的东西
                }
                $local = trim($c->id);
                if ($local === '') {
                    continue;
                }
                $fq = $p->id() . '.' . $local;
                if (isset($this->pluginCommands[$fq])) {
                    $this->pluginConflicts[$fq] = ['reason' => 'duplicate', 'owner' => ''];
                    continue;
                }
                $this->pluginCommands[$fq] = ['p' => $p, 'c' => $c];
                $this->bindShortcut($fq, $c);
            }
        }
        $this->rebuildPluginMenuSnapshot();
    }

    /** 按 order 升序重建菜单快照（插件装载顺序由 glob 的目录名字典序决定） */
    private function rebuildPluginMenuSnapshot(): void
    {
        $rows = [];
        foreach ($this->pluginCommands as $fq => $e) {
            $c = $e['c'];
            $p = $e['p'];
            $name = method_exists($p, 'name') ? (string) $p->name() : $p->id();
            $rows[] = [
                'label' => $name . ': ' . $c->title,   // 插件文案，不过 t()
                'action' => 'plugin:' . $fq,
                // 只有真正绑定成功的快捷键才显示：菜单不承诺一个按了没反应的组合
                'shortcut' => $this->shortcutOf($fq) ?? '',
                'order' => $c->order,
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);
        $this->pluginMenuSnapshot = array_map(static fn (array $r): array => [
            'label' => $r['label'],
            'action' => $r['action'],
            'shortcut' => $r['shortcut'],
        ], $rows);
    }

    /**
     * 装载期一次性汇聚插件面板（V1.1 自定义面板）。
     *
     * 容错哲学与 registerPluginCommands 一致：任一插件出错只影响它自己（跳过），
     * 不让它带崩启动或影响别的插件。面板 id 由核心加插件 id 前缀完全限定，跨插件天然不撞。
     * 只在构造末尾调用一次（与命令同机制，冻结快照后每帧只读）。
     */
    private function collectPluginPanels(): void
    {
        foreach ($this->plugins as $p) {
            if (!method_exists($p, 'panels')) {
                continue;                       // V1 老插件（如 clock）零负担
            }
            try {
                $list = $p->panels();
            } catch (Throwable $e) {
                continue;                       // panels() 抛异常：跳过该插件，不连坐
            }
            if (!is_array($list)) {
                continue;
            }
            foreach ($list as $pp) {
                if (!$pp instanceof \App\Plugin\PluginPanel) {
                    continue;                   // 类型防御：插件返回了别的东西
                }
                $this->pluginPanels[] = ['plugin' => $p->id(), 'panel' => $pp];
            }
        }
    }

    /** 已汇聚的插件面板列表（供 PluginPanelHost 读取）。@return array<int,array{plugin:string,panel:\App\Plugin\PluginPanel}> */
    public function pluginPanels(): array
    {
        return $this->pluginPanels;
    }

    /** 该命令绑定成功的快捷键（归一化串），未绑定返回 null */
    private function shortcutOf(string $fq): ?string
    {
        $k = array_search($fq, $this->pluginShortcuts, true);
        return $k === false ? null : (string) $k;
    }

    /**
     * 归一化插件声明的快捷键：只接受 `Ctrl+字母` 与 `F1`–`F12`，其余返回 null。
     *
     * 为什么不支持 Alt/Shift：真实 pty 下 `Alt+字母` 被解析成**不带修饰**的 CharKeyEvent，
     * 与直接按该字母不可区分（MenuBarPanel 顶部注释有实测结论）；Shift+字母同理。
     * @return string|null 归一化串（'Ctrl+K' / 'F3'），null = 语法不支持
     */
    private static function normalizeShortcut(string $s): ?string
    {
        $s = trim($s);
        if (preg_match('/^ctrl\+([a-z])$/i', $s, $m) === 1) {
            return 'Ctrl+' . strtoupper($m[1]);
        }
        if (preg_match('/^f(\d{1,2})$/i', $s, $m) === 1) {
            $n = (int) $m[1];
            return ($n >= 1 && $n <= 12) ? 'F' . $n : null;
        }
        return null;
    }

    /**
     * 系统保留键：由帮助页登记表派生（自动同步，不写字面量），再加 F2/F10。
     * 保留面刻意放宽到面板级键（Ctrl+S/W/P/N/L/C）——插件抢走任何一个，
     * 用户都会觉得「这个编辑器坏了」，宁可保守。
     * @return list<string>
     */
    private static function reservedShortcutKeys(): array
    {
        $out = ['F2', 'F10'];   // F2=交互式 PTY 捕获，F10=菜单栏（见 handle() 顶部）
        foreach (KeyBindings::documentedCtrlKeys() as $letter) {
            $out[] = 'Ctrl+' . strtoupper((string) $letter);
        }
        return array_values(array_unique($out));
    }

    /** 把事件映射成归一化快捷键串；不是可绑定键型返回 null */
    private function pluginShortcutKeyFor(\PhpTui\Term\Event $event): ?string
    {
        if ($event instanceof CharKeyEvent) {
            $ch = $event->char;
            // php-tui/term 的 EventParser 已把控制字节（\x0b=Ctrl+K …）规范成对应字母 + CONTROL 修饰，
            // 这里直接读规范后的 char 即可：转大写后用 ctype_alpha 判断，不逐个字母比字符，
            // 否则会被 tests/m6_unit.php 的「快捷键漂移扫描」当成未登记的 Ctrl 绑定误报。
            $up = strtoupper($ch);
            if (strlen($up) === 1 && ctype_alpha($up) && ($event->modifiers & KeyModifiers::CONTROL)) {
                return 'Ctrl+' . $up;
            }
            return null;
        }
        if ($event instanceof FunctionKeyEvent) {
            return ($event->number >= 1 && $event->number <= 12) ? 'F' . $event->number : null;
        }
        return null;
    }

    /**
     * 绑定一条命令声明的快捷键。失败只降级快捷键（命令仍可从菜单触发），并记冲突原因。
     */
    private function bindShortcut(string $fq, PluginCommand $c): void
    {
        if ($c->shortcut === null || trim($c->shortcut) === '') {
            return;
        }
        $norm = self::normalizeShortcut($c->shortcut);
        if ($norm === null) {
            $this->pluginConflicts[$fq] = ['reason' => 'unsupported', 'owner' => $c->shortcut];
            return;
        }
        if (in_array($norm, self::reservedShortcutKeys(), true)) {
            $this->pluginConflicts[$fq] = ['reason' => 'reserved', 'owner' => $norm];
            return;
        }
        if (isset($this->pluginShortcuts[$norm])) {
            // 先到先得：装载顺序 = glob() 的目录名字典序
            $this->pluginConflicts[$fq] = ['reason' => 'taken', 'owner' => $this->pluginShortcuts[$norm]];
            return;
        }
        $this->pluginShortcuts[$norm] = $fq;
    }

    /** 菜单项快照（每帧只读，形状恒定）；无插件命令时为空数组 */
    public function pluginMenuItems(): array
    {
        return $this->pluginMenuSnapshot;
    }

    /**
     * 命令面板用的全量命令清单：直接扁平化 MenuBarPanel::definitions() 的全部 items
     * （含 V1.1 插件命令组——其 action 已是 `plugin:<fq>`、label 已是 `Name: Title`）。
     * 与菜单同源，故选中后直接走 App::menuAction() 分发，无需另立路径。
     * @return list<array{id:string,title:string,shortcut:string}>
     */
    public function commandPaletteEntries(): array
    {
        $out = [];
        foreach ($this->menuBar->definitions() as $menu) {
            foreach ($menu['items'] as $it) {
                $out[] = [
                    'id' => $it['action'],
                    'title' => $it['label'],
                    'shortcut' => $it['shortcut'],
                ];
            }
        }
        return $out;
    }

    /** 某插件注册成功的命令：局部 id => PluginCommand */
    public function pluginCommandsOf(string $pluginId): array
    {
        $out = [];
        foreach ($this->pluginCommands as $fq => $e) {
            if ($e['p']->id() === $pluginId) {
                $out[substr($fq, strlen($pluginId) + 1)] = $e['c'];
            }
        }
        return $out;
    }

    /** 某命令绑定成功的快捷键（归一化串），未绑定返回 null */
    public function pluginShortcutOf(string $fq): ?string
    {
        return $this->shortcutOf($fq);
    }

    /** 某插件的冲突信息：完全限定 id => ['reason','owner'] */
    public function pluginConflictsOf(string $pluginId): array
    {
        $out = [];
        foreach ($this->pluginConflicts as $fq => $info) {
            if (str_starts_with($fq, $pluginId . '.')) {
                $out[$fq] = $info;
            }
        }
        return $out;
    }

    /**
     * 执行一条插件命令（菜单 / 快捷键 / 状态栏点击三条路径的公共终点）。
     * @return bool 是否命中已注册的命令
     */
    public function runPluginCommand(string $fq): bool
    {
        $entry = $this->pluginCommands[$fq] ?? null;
        if ($entry === null) {
            return false;
        }
        $p = $entry['p'];
        $local = substr($fq, strlen($p->id()) + 1) ?: '';
        try {
            $p->executeCommand($local, $this);
        } catch (Throwable $e) {
            // 静默失败是最差的 UX：至少让用户知道这次点击/按键没有效果
            $this->setMessage($this->t('plugins.command_failed', [
                'plugin' => $p->id(),
                'cmd' => $local,
            ]));
        }
        return true;
    }

    /**
     * 聚合所有插件本帧的状态栏段，统一交给 StatusBarPanel 与系统段一起裁剪/摆放。
     * cmd 为该段声明且**确实注册成功**的完全限定命令 id（V1.1 状态栏点击用；失败则 null）。
     * @return array<int,array{k:string,p:int,o:int,t:string,cmd:?string}>
     */
    public function pluginSegments(): array
    {
        $out = [];
        foreach ($this->plugins as $plugin) {
            foreach ($plugin->statusSegments($this) as $seg) {
                $cmd = null;
                if ($seg->commandId !== null) {
                    $fq = $plugin->id() . '.' . $seg->commandId;
                    if (isset($this->pluginCommands[$fq])) {
                        $cmd = $fq;
                    }
                }
                $out[] = [
                    'k' => $seg->key,
                    'p' => $seg->priority,
                    'o' => $seg->order,
                    't' => $seg->text,
                    'cmd' => $cmd,
                ];
            }
        }
        return $out;
    }

    /**
     * 聚合插件要求的最小周期重绘间隔（秒），null=无需周期重绘。
     * 主循环据此在 idle 时按最小间隔触发重绘，使时钟等插件持续更新。
     */
    public function minTickInterval(): ?int
    {
        $min = null;
        foreach ($this->plugins as $plugin) {
            $tick = $plugin->tickInterval();
            if ($tick !== null && $tick > 0) {
                $min = $min === null ? $tick : min($min, $tick);
            }
        }
        return $min;
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
        $i = array_search($panel, self::PANELS, true);
        if ($i === false) {
            // 面板名不在常量里：静默忽略（老实现会把 false 赋给 int 属性，埋着 TypeError）
            return;
        }
        $from = self::PANELS[$this->focusIndex] ?? $panel;
        $this->focusIndex = $i;
        if ($from !== $panel) {
            $this->emitPluginEvent('focus.changed', ['from' => $from, 'to' => $panel]);
        }
    }

    /**
     * V1.1：向所有实现了可选方法 `onEvent(PluginEvent $e): void` 的插件广播应用事件。
     *
     * - 单个插件抛异常不影响其它插件与主流程（与 PluginLoader 的容错同套哲学）；
     * - 事件回调里再触发事件会被丢弃（防重入），避免插件"打开文件→又触发事件"递归爆栈；
     * - 这是**唯一**的出口：所有埋点都调它，插件只认事件名。
     * @param array<string,mixed> $payload
     */
    public function emitPluginEvent(string $name, array $payload = []): void
    {
        if ($this->inPluginEvent) {
            return;
        }
        $this->inPluginEvent = true;
        try {
            $ev = new PluginEvent($name, $payload, $this);
            foreach ($this->plugins as $p) {
                if (!method_exists($p, 'onEvent')) {
                    continue;
                }
                try {
                    $p->onEvent($ev);
                } catch (Throwable $e) {
                    // 单个插件出错只影响它自己；不让它带崩主流程
                }
            }
        } finally {
            $this->inPluginEvent = false;
        }
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
        if ($this->pluginsPanel->isOpen()) {
            $overlays[] = $this->pluginsPanel->widget($vp->width, $vp->height);
        }
        if ($this->palette->isOpen()) {
            $overlays[] = $this->palette->widget($vp->width, $vp->height);
        }
        if ($this->panelHost->isOpen()) {
            $overlays[] = $this->panelHost->widget($vp->width, $vp->height);
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

        // 渲染前把文本选择矩形注入编辑/终端面板（content() 内部做反显高亮）。
        // 归一化矩形 [r0,c0,r1,c1]；无选择时传 null 清掉上一帧高亮。
        if ($this->select !== null && $this->select['panel'] === 'editor') {
            $this->editor->setSelection($this->normalizeRect($this->select));
        } else {
            $this->editor->setSelection(null);
        }
        if ($this->select !== null && $this->select['panel'] === 'terminal') {
            $this->terminal->setSelection($this->normalizeRect($this->select));
        } else {
            $this->terminal->setSelection(null);
        }

        // ── Sidebar ──
        $sidebarInner = $this->sidebar->content($a['sidebar'], $focus === 'sidebar');
        $sidebar = BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderStyle($this->borderStyle($focus === 'sidebar'))
            ->titles(Title::fromString(' ' . $this->i18n->t('panel.sidebar') . ' '))
            ->widget($sidebarInner);

        // ── Editor ──
        // 先算 content()（内部每帧更新 hLeft/hRight 横向滚动边界指示），再据此拼标题，
        // 避免标题指示比正文晚一帧。
        $editorWidget = $this->editor->content($a['editor'], $focus === 'editor');
        $editorTitle = ' ' . $this->i18n->t('panel.editor') . ' ';
        if ($this->buffer !== null) {
            $name = basename((string) $this->buffer->path);
            $flag = $this->buffer->dirty ? ' ' . $this->i18n->t('status.dirty') : '';
            $hint = '';
            if ($this->editor->hLeft) {
                $hint .= '‹';   // ‹ 左侧还有隐藏内容（已横滚）
            }
            if ($this->editor->hRight) {
                $hint .= '›';   // › 右侧还有隐藏内容
            }
            $editorTitle = ' ' . $this->i18n->t('panel.editor') . ': ' . $name . $flag
                . ($hint !== '' ? ' ' . $hint : '') . ' ';
        }
        $editor = BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderStyle($this->borderStyle($focus === 'editor'))
            ->titles(Title::fromString($editorTitle))
            ->widget($editorWidget);

        // ── Terminal（M2 命令运行器 / 交互式 PTY）──
        $termTitle = ' ' . $this->i18n->t('panel.terminal') . ' ';
        if ($this->terminal->mode === 'pty') {
            $termTitle = ' ' . $this->i18n->t('term.interactive') . ' ';
            if ($this->terminal->isCaptured()) {
                $termTitle = ' ' . $this->i18n->t('term.interactive') . ' · ' . $this->i18n->t('term.captured') . ' ';
            }
        } elseif ($this->terminal->isRunning()) {
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
        // 输入框内部 = 框高 - 上下边框(2)，恒为输入内容（默认 3 行）。
        // 工具栏（发送/换行/清空）用图标放在顶边框右对齐，不占用输入行。
        $aiInput = BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderStyle($this->borderStyle($focus === 'ai_input'))
            ->titles(
                Title::fromString(' ' . $this->i18n->t('panel.ai_input') . ' '),
                Title::fromString($this->ai->toolbarTitleString())->horizontalAlignment(HorizontalAlignment::Right),
            )
            ->widget($this->ai->inputContent(
                max(0, ($a['ai_input']->width ?? 0) - 2),
                max(0, ($a['ai_input']->height ?? 0) - 2),
            ));

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
        $this->setMessage($this->t('status.theme') . '=' . $this->t($this->theme->label));
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
                // 保存的若是插件专用配置文件，则重新注入插件配置（在 ViceCode 内改完即生效，无需重启）
                if ($this->buffer !== null && $this->buffer->path === ConfigStore::pluginsPath()) {
                    $this->reloadPluginConfig();
                    $this->setMessage($this->t('plugins.reloaded'));
                }
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
            case 'plugins.open':
                $this->pluginsPanel->open();
                break;
            case 'palette.open':
                $this->palette->open();
                break;
            case 'panel.host.open':
                $this->panelHost->toggle();
                break;
            default:
                // V1.1：插件命令（`plugin:<插件id>.<局部id>`）
                if (str_starts_with($id, 'plugin:')) {
                    $this->runPluginCommand(substr($id, 7));
                }
                break;
        }
    }

    public function handle(\PhpTui\Term\Event $event, Area $vp): void
    {
        // 未保存确认进行中：拦截所有输入，只响应 y/n/Esc（及 Ctrl+Q 视为确认）
        if ($this->confirm !== null) {
            $this->lifecycle->handleEvent($event);
            return;
        }

        // 全局退出键 Ctrl+Q：放在所有浮层模态分支之前，确保菜单/帮助/插件面板/命令面板
        // 任意一个打开时都能直接退出（否则那些模态分支会把 Ctrl+Q 吞掉 → 应用卡死）。
        // 唯一例外是交互式 PTY 捕获态：此时 Ctrl+Q 应原样喂给 shell（见下方捕获分支），
        // 故这里排除，让捕获分支先于退出处理它。
        if ($event instanceof CharKeyEvent
            && ($event->modifiers & KeyModifiers::CONTROL)
            && strtolower($event->char) === 'q'
            && !($this->focusPanel() === 'terminal' && $this->terminal->isCaptured())) {
            $this->lifecycle->requestQuit();
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

        // F1：唤出/收起命令面板（见 CommandPalettePanel 顶部注释：用 F1 而非 Ctrl+Shift+P
        // 的原因——真实 pty 下 Shift 修饰不可区分）。放在模态独占分支之前，故面板打开时
        // 再按 F1 也能收起。
        if ($event instanceof FunctionKeyEvent && $event->number === 1) {
            $this->palette->toggle();
            return;
        }

        // F2：终端面板聚焦时进入/退出交互式 PTY 捕获（其余面板忽略）。
        // 已捕获时按 F2 → toggleInteractive 退出捕获；未捕获（pty 模式但没在捕获）时再按进入。
        if ($event instanceof FunctionKeyEvent && $event->number === 2) {
            if ($this->focusPanel() === 'terminal') {
                $this->terminal->toggleInteractive();
            }
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

        // 插件管理浮层打开期间**独占键盘**（与帮助页同机制）：Esc/q 关闭，上下/翻页滚动，
        // 不往下层面板分发，否则在浮层里按 q 会顺带把应用退了。
        if ($this->pluginsPanel->isOpen()) {
            $viewH = $this->pluginsPanel->viewHeightFor($vp->width, $vp->height);
            if ($event instanceof MouseEvent) {
                if ($event->kind === MouseEventKind::ScrollUp) {
                    $this->pluginsPanel->scrollBy(-3);
                } elseif ($event->kind === MouseEventKind::ScrollDown) {
                    $this->pluginsPanel->scrollBy(3);
                }
                return;
            }
            if ($event instanceof CharKeyEvent && $this->pluginsPanel->onChar($event)) {
                return;
            }
            if ($event instanceof CodedKeyEvent && $this->pluginsPanel->onKey($event, $viewH)) {
                return;
            }
            return; // 其余键一律吞掉：浮层是模态的
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

        // 命令面板打开期间**独占键盘**（与帮助页/插件浮层同机制）：过滤框吃字符键、
        // 上下/Enter/Esc/Backspace 交给面板自己，其余键一律吞掉，避免穿透到下层面板。
        if ($this->palette->isOpen()) {
            if ($event instanceof MouseEvent) {
                return; // 暂不处理点击：吞掉，避免穿透
            }
            if ($event instanceof CharKeyEvent && $this->palette->onChar($event)) {
                return;
            }
            if ($event instanceof CodedKeyEvent && $this->palette->onKey($event)) {
                return;
            }
            return; // 其余键一律吞掉：命令面板是模态的
        }

        // 自定义面板浮层打开期间**独占键盘**（与命令面板/插件浮层同机制）：
        // Tab/方向键切 tab、Esc 关闭，若当前面板提供了输入回调则交给它处理，其余键吞掉。
        if ($this->panelHost->isOpen()) {
            if ($event instanceof MouseEvent) {
                return; // 浮层模态：吞掉鼠标，避免穿透到下层面板
            }
            if ($event instanceof CharKeyEvent && $this->panelHost->onChar($event)) {
                return;
            }
            if ($event instanceof CodedKeyEvent && $this->panelHost->onKey($event)) {
                return;
            }
            return; // 其余键一律吞掉：浮层是模态的
        }

        // 交互式 PTY 捕获态：终端面板聚焦且已捕获时，除 F2/Esc 退出键外，
        // 所有按键经 KeyToPty 编码后转发给 PTY（由真实 shell / 全屏程序解释），
        // 不再走下面的面板导航分发。
        if ($this->focusPanel() === 'terminal' && $this->terminal->isCaptured()) {
            $exit = ($event instanceof FunctionKeyEvent && $event->number === 2)
                || ($event instanceof CodedKeyEvent && $event->code === KeyCode::Esc);
            if ($exit) {
                $this->terminal->exitCapture();
                return;
            }
            // 孤立 ESC 字符（解析器未识别成 Esc）也退出捕获
            if ($event instanceof CharKeyEvent && $event->char === "\x1b") {
                $this->terminal->exitCapture();
                return;
            }
            $bytes = KeyToPty::encode($event);
            if ($bytes !== null) {
                $this->terminal->sendToPty($bytes);
            }
            return;
        }

        // V1.1 插件快捷键钩子。位置是刻意选的：
        //  - 在菜单/插件浮层/帮助页独占之后 → 模态打开时不抢键；
        //  - 在交互式 PTY 捕获之后 → 捕获态按键仍原样喂给子进程；
        //  - 在 Ctrl+Q/T/V 等全局键之前能进到这里是因为它们已列入保留表，永远绑不到插件。
        $pluginKey = $this->pluginShortcutKeyFor($event);
        if ($pluginKey !== null && isset($this->pluginShortcuts[$pluginKey])) {
            $this->runPluginCommand($this->pluginShortcuts[$pluginKey]);
            return;
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
            // Ctrl+T 切主题（M6 R4）。必须在面板拿到字符之前处理，
            // 否则在编辑器/输入框里按它会把字符打进去。
            if (($event->modifiers & KeyModifiers::CONTROL) && strtolower($event->char) === 't') {
                $this->cycleTheme();
                return;
            }
            // Ctrl+V 粘贴（读剪贴板）：编辑区/终端/AI 输入框按当前焦点插入。
            // 必须在面板拿到字符之前处理，否则会把字符打进文档。
            if (($event->modifiers & KeyModifiers::CONTROL) && strtolower($event->char) === 'v') {
                $this->requestPaste();
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
            } elseif ($this->select !== null) {
                $this->updateSelect($e, $a);
            }
            return;
        }
        if ($e->kind === MouseEventKind::Up) {
            if ($this->select !== null) {
                $this->finishSelect($a);
            }
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
                $this->terminal->wheel(3);
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
                $this->terminal->wheel(-3);
            } elseif ($this->focusPanel() === 'ai_stream' || $this->focusPanel() === 'ai_input') {
                $this->ai->onScroll(MouseEventKind::ScrollUp);
            }
            return;
        }
        if ($e->kind === MouseEventKind::ScrollLeft || $e->kind === MouseEventKind::ScrollRight) {
            $this->hScrollByFocus($e->kind === MouseEventKind::ScrollRight ? 4 : -4);
            return;
        }
        if ($e->kind === MouseEventKind::Down) {
            // 任何新点击先清掉上一次文本选择高亮（选区在 Up 后保留显示，直到下次点击）。
            $this->select = null;
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
            // V1.1 状态栏插件段点击。**放在 tryStartDrag 之前**做双重保险：
            // 状态栏行本就不在拖拽区间（mainTop..status.y-1），先判就彻底不会和拖拽争。
            // 命中判据是 StatusBarPanel 最后一帧的 placed，与取舍同源（被丢弃的段不可点）。
            if (isset($a['status']) && $e->row === $a['status']->position->y) {
                $fq = $this->statusBar->clickSegment($e->column - $a['status']->position->x);
                if ($fq !== null && $this->runPluginCommand($fq)) {
                    return;
                }
            }
            // 分隔条拖拽优先于普通点击：命中任一条分隔条就进入拖拽，不触发聚焦/打开。
            if ($this->tryStartDrag($e, $a)) {
                return;
            }
            // 文本选择锚点：在编辑器内容区 / 终端区内按下左键即记锚点；随后若发生 Drag
            // 则拉出选区，Up 时复制。纯点击（无 Drag）在 finishSelect 里清掉，不影响聚焦。
            $pos = new Position($e->column, $e->row);
            if (isset($a['editor']) && $this->editor->isSelectableAt($pos, $a['editor'])) {
                $this->select = ['panel' => 'editor', 'aRow' => $e->row, 'aCol' => $e->column, 'bRow' => $e->row, 'bCol' => $e->column];
            } elseif (isset($a['terminal']) && $a['terminal']->containsPosition($pos)) {
                $this->select = ['panel' => 'terminal', 'aRow' => $e->row, 'aCol' => $e->column, 'bRow' => $e->row, 'bCol' => $e->column];
            }
            $this->handleClick($e, $a);
        }
    }

    /**
     * 判定鼠标按下是否命中某条分隔条。命中则记下拖拽目标并返回 true（调用方据此不再走点击逻辑）。
     * 四条可拖边界：侧栏右、AI 左（竖直）；编辑器下、AI 输入框上（水平）。
     * 命中窗口取边界 ±DRAG_TOL（当前为 0 = 精确命中分隔条所在行列），且限制在对应面板范围内，避免误抓相邻面板内部。
     * @param array<string,Area> $a
     */
    private function tryStartDrag(MouseEvent $e, array $a): bool
    {
        $tol = self::DRAG_TOL;
        // 面板在拖拽轴上的最小可点击尺寸：小于它的面板，其拖拽容差带会吞掉整个内部，
        // 导致"点中间反而拖不动、无法聚焦"。此时把点击交给焦点逻辑；从相邻的大面板一侧仍可拖动调整。
        $min = 2 * $tol + 1;
        $mainTop = $a['sidebar']->position->y;
        $mainBottom = $a['status']->position->y; // 状态栏起始 = 主区底部

        // 竖分隔条①：侧栏右边界（x = sidebar.x + sidebar.width）
        $sidebarEdge = $a['sidebar']->position->x + $a['sidebar']->width;
        if (abs($e->column - $sidebarEdge) <= $tol
            && $e->row >= $mainTop && $e->row < $mainBottom) {
            $key = $e->column <= $sidebarEdge ? 'sidebar' : 'editor';
            if ($a[$key]->width > $min) {
                $this->drag = ['which' => 'sidebar', 'horizontal' => false];
                return true;
            }
        }

        // 竖分隔条②：AI 列左边界（x = ai_stream.x）
        $aiEdge = $a['ai_stream']->position->x;
        if (abs($e->column - $aiEdge) <= $tol
            && $e->row >= $mainTop && $e->row < $mainBottom) {
            $key = $e->column < $aiEdge ? 'editor' : 'ai_stream';
            if ($a[$key]->width > $min) {
                $this->drag = ['which' => 'ai', 'horizontal' => false];
                return true;
            }
        }

        // 横分隔条①：编辑器下边界（y = editor.y + editor.height），且仅在中间列水平范围内
        $centerX = $a['editor']->position->x;
        $centerW = $a['editor']->width;
        $editorEdge = $a['editor']->position->y + $a['editor']->height;
        if (abs($e->row - $editorEdge) <= $tol
            && $e->column >= $centerX && $e->column < $centerX + $centerW) {
            $key = $e->row < $editorEdge ? 'editor' : 'terminal';
            if ($a[$key]->height > $min) {
                $this->drag = ['which' => 'center', 'horizontal' => true];
                return true;
            }
        }

        // 横分隔条②：AI 消息流下边界（y = ai_stream.y + ai_stream.height），且在 AI 列水平范围内
        $aiX = $a['ai_stream']->position->x;
        $aiW = $a['ai_stream']->width;
        $aiEdgeY = $a['ai_stream']->position->y + $a['ai_stream']->height;
        if (abs($e->row - $aiEdgeY) <= $tol
            && $e->column >= $aiX && $e->column < $aiX + $aiW) {
            // 工具栏图标画在 ai_input 顶边框（== aiEdgeY），点图标区应触发按钮而非拖拽，
            // 否则 tryStartDrag 会抢先返回 true，handleClick 永远收不到 → 按钮无反应。
            if ($this->ai->isToolbarBorderHit($e->column, $e->row, $a['ai_input'])) {
                return false;
            }
            $key = $e->row < $aiEdgeY ? 'ai_stream' : 'ai_input';
            if ($a[$key]->height > $min) {
                $this->drag = ['which' => 'ai_input', 'horizontal' => true];
                return true;
            }
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

    // ── 文本选择（鼠标拖拽 + 写入剪贴板）─────────────────

    /** 拖拽中：把头点夹到所属面板内，避免选区越出面板边界 */
    private function updateSelect(MouseEvent $e, array $a): void
    {
        $panel = $this->select['panel'];
        $area = $a[$panel];
        $this->select['bRow'] = max($area->position->y, min($e->row, $area->position->y + $area->height - 1));
        $this->select['bCol'] = max($area->position->x, min($e->column, $area->position->x + $area->width - 1));
    }

    /** 松手：a≠b 则取矩形文字写入剪贴板并状态栏提示；a==b 视为纯点击，清掉选区 */
    private function finishSelect(array $a): void
    {
        $s = $this->select;
        if ($s['aRow'] === $s['bRow'] && $s['aCol'] === $s['bCol']) {
            $this->select = null;
            return;
        }
        [$r0, $c0, $r1, $c1] = $this->normalizeRect($s);
        $panel = $s['panel'];
        if ($panel === 'editor') {
            $text = $this->editor->getTextRect($a['editor'], $r0, $c0, $r1, $c1);
        } else {
            $text = $this->terminal->getTextRect($a['terminal'], $r0, $c0, $r1, $c1);
        }
        $this->clip->copy($text);
        $n = mb_strlen($text);
        $this->message = $this->t('status.copied', ['n' => (string) $n]);
        // 保留 $this->select（归一化矩形）以便持续高亮，下次 Down 清掉。
    }

    /** 把 [aRow,aCol,bRow,bCol] 归一化成 [r0,c0,r1,c1]（小行/列在前） */
    private function normalizeRect(array $s): array
    {
        return [
            min($s['aRow'], $s['bRow']),
            min($s['aCol'], $s['bCol']),
            max($s['aRow'], $s['bRow']),
            max($s['aCol'], $s['bCol']),
        ];
    }

    /** 取内存剪贴板内容（非 tty 降级路径；主要给单测断言用） */
    public function clipboardPeek(): string
    {
        return $this->clip->peek();
    }

    /** 写入系统剪贴板（复制）：tty 走 OSC 52，非 tty 降级内存。供各面板调用。 */
    public function clipboardCopy(string $text): void
    {
        $this->clip->copy($text);
    }

    /**
     * 请求粘贴（读剪贴板）：按当前焦点定插入目标，再读剪贴板内容插入。
     * - editor  → 编辑器缓冲区光标处
     * - terminal → runner 输入行光标处 / pty 捕获态转发给 shell；pty 非捕获态忽略
     * - ai_input / ai_stream → AI 输入框
     * - 其余（sidebar 等）→ 无目标，忽略
     * tty 环境走 OSC 52 异步读取（暂存 pasteTarget，响应回来再插入）；
     * 非 tty 降级为粘贴内存剪贴板（即时插入）。
     */
    public function requestPaste(): void
    {
        $f = $this->focusPanel();
        $target = match ($f) {
            'editor' => 'editor',
            'terminal' => ($this->terminal->mode === 'pty' && !$this->terminal->captured) ? null : 'terminal',
            'ai_input', 'ai_stream' => 'ai_input',
            default => null,
        };
        if ($target === null) {
            return; // 当前焦点无粘贴目标，静默忽略
        }

        if (!stream_isatty(STDOUT)) {
            // 非 tty：没有系统剪贴板，粘贴进程内内存剪贴板（copy 写入的）
            $text = $this->clip->peek();
            if ($text !== '') {
                $this->applyPaste($target, $text);
            }
            return;
        }

        // tty：发 OSC 52 查询，响应异步经 stdin 回传 → onClipboardRead
        $this->pasteTarget = $target;
        $this->clip->requestRead();
        $this->setMessage($this->t('status.paste_pending'));
    }

    /** OSC 52 剪贴板响应回调：把内容插入到请求时记录的面板。 */
    public function onClipboardRead(string $text): void
    {
        if ($this->pasteTarget === null) {
            return;
        }
        $target = $this->pasteTarget;
        $this->pasteTarget = null;
        $this->applyPaste($target, $text);
    }

    /** 把剪贴板文本插入到指定目标面板，并更新状态栏。 */
    private function applyPaste(string $target, string $text): void
    {
        switch ($target) {
            case 'editor':
                $this->buffer?->insertText($text);
                break;
            case 'terminal':
                $this->terminal->pasteText($text);
                break;
            case 'ai_input':
                $this->ai->insertText($text);
                break;
        }
        $this->setMessage($this->t('status.pasted', ['n' => (string) mb_strlen($text)]));
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
            // 合并已有配置（而非整体覆盖）：保留用户手改的其它键（如 persistSession），
            // 否则每次干净退出都会把 .vicerc 重写掉、丢掉用户设置。
            $existing = ConfigStore::load();
            if (!is_array($existing)) {
                $existing = [];
            }
            $data = array_merge($existing, [
                'layout' => [
                    'sidebarWidth' => $this->layout->sidebarWidth,
                    'aiWidth' => $this->layout->aiWidth,
                    'editorRatio' => $this->layout->editorRatio,
                    'aiInputHeight' => $this->layout->aiInputHeight,
                ],
                'theme' => $this->theme->id,
                'locale' => $this->i18n->locale(),
            ]);
            return ConfigStore::save($data);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function handleCoded(CodedKeyEvent $e, array $a): void
    {
        // Shift+Ins（及 Ins）粘贴：与 Ctrl+V 同源，按当前焦点插入剪贴板内容。
        if ($e->code === KeyCode::Insert) {
            $this->requestPaste();
            return;
        }

        // 键盘横向滚动（Shift+←/→）：与鼠标 ScrollLeft/ScrollRight 同源，按焦点分发到 onScrollH(±4)。
        // 必须在各面板 onKey 之前拦截——编辑器的 ←/→ 已被光标移动占用，Shift+方向键单独用作横滚；
        // 菜单打开时由上方菜单独占分支吞掉，不会落到这里。
        if (($e->modifiers & KeyModifiers::SHIFT)
            && ($e->code === KeyCode::Left || $e->code === KeyCode::Right)) {
            $this->hScrollByFocus($e->code === KeyCode::Right ? 4 : -4);
            return;
        }

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
                // 走 focus() 而不是直接改下标：这样焦点变化才会广播 focus.changed 事件
                $this->focus(self::PANELS[($this->focusIndex + 1) % count(self::PANELS)]);
                break;
            case KeyCode::Backspace:
                // AI 输入框退格（AiPanel::onKey 已处理，这里是历史遗留的空分支，保留以防
                // 将来改焦点分发时漏掉；终端退格已下沉到 TerminalPanel::onKey）
                break;
        }
    }

    /**
     * 按当前焦点面板分发横向滚动。键盘 Shift+←/→ 与鼠标横滚共用，步进一致（±4 列）。
     */
    private function hScrollByFocus(int $step): void
    {
        $f = $this->focusPanel();
        if ($f === 'editor') {
            $this->editor->onScrollH($step);
        } elseif ($f === 'sidebar') {
            $this->sidebar->onScrollH($step);
        } elseif ($f === 'terminal') {
            $this->terminal->onScrollH($step);
        } elseif ($f === 'ai_stream' || $f === 'ai_input') {
            $this->ai->onScrollH($step);
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
                if ($key === 'ai_input') {
                    // 点顶边框（图标工具栏）命中按钮；否则聚焦输入
                    $topRow = $a['ai_input']->position->y;
                    if ($row === $topRow
                        && $this->ai->onToolbarBorderClick($col, $a['ai_input'])) {
                        $this->focus('ai_input');
                        return;
                    }
                    $this->focus('ai_input');
                    return;
                }
                if ($key === 'ai_stream') {
                    // 点击 AI 消息流：命中某条消息则整条复制到剪贴板（不拖拽选区），随后照常聚焦。
                    $this->ai->copyMessageAtRow($row, $a['ai_stream']);
                    $this->focus('ai_stream');
                    return;
                }
                $this->focus($key);
                return;
            }
        }
    }

}

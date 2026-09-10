# 插件开发文档（ViceCode 插件系统 V1）

> 适用范围：**V1**。当前版本插件可扩展**底部状态栏**——向状态栏注入自定义段。
> 后续版本可扩展命令、面板、事件钩子等（见文末「局限与后续」）。

本文档面向插件作者，讲解如何编写、加载、调试一个 ViceCode 插件。
阅读前文建议先了解项目整体结构（`README`），但编写插件**不需要**读懂核心源码。

---

## 0. 设计原则（先建立心智模型）

1. **运行时动态加载**：插件不是编译期静态编入核心的，而是应用在启动时**扫描目录 + 运行时 `require`** 进来的。
   插件可独立放置、随时增删，无需重新构建应用。
2. **普通 PHP 即可**：插件文件写普通 PHP 即可，无需遵循核心源码的任何特殊编码规范。
3. **单插件失败不影响整体**：某个插件 JSON 损坏、类缺失、不实现接口，都会被跳过，应用照常启动。
4. **与系统段统一管理**：插件注入的状态栏段，与内置段走同一套「按优先级丢弃 + 按 order 摆放」逻辑，
   不另搞一套裁剪规则，避免位置错位。

---

## 1. 目录结构

每个插件是一个独立目录，位于项目根目录下的 `plugins/`：

```
plugins/
└── <plugin-id>/
    ├── plugin.json      # 插件清单（必填）
    └── <EntryFile>.php  # 入口文件（类名与 plugin.json 的 class 对应）
```

示例（内置 `clock` 插件）：

```
plugins/
└── clock/
    ├── plugin.json
    └── ClockPlugin.php
```

> 注意：文档中所有路径形如 `plugins/<id>/plugin.json` 都是示意；**不要在 PHP 注释里写 `plugins/*/plugin.json`**，
> 其中的 `*/` 会提前闭合块注释（`/* ... */`），导致后续代码被当成 PHP 而报语法错误。这是本项目已踩过的真实坑。

---

## 2. 快速开始：最小可用插件

下面这个插件在状态栏右侧显示固定的 `Hello`。

### 2.1 创建 `plugins/hello/plugin.json`

```json
{
    "id": "hello",
    "name": "Hello",
    "entry": "HelloPlugin.php",
    "class": "HelloPlugin",
    "description": "在状态栏显示 Hello"
}
```

字段说明：

| 字段 | 必填 | 说明 |
| --- | --- | --- |
| `id` | 是 | 稳定唯一标识，建议与目录名一致 |
| `name` | 否 | 展示名（目前用于可读性与未来扩展） |
| `entry` | 否 | 入口文件名；缺省默认取 `class` 同名 `.php` |
| `class` | 是 | 入口文件内定义的类名（全局类或带命名空间均可） |
| `description` | 否 | 一句话描述 |

### 2.2 创建 `plugins/hello/HelloPlugin.php`

```php
<?php
declare(strict_types=1);

// 插件写普通 PHP 即可。这里用全局类；如果你用命名空间，class 字段写全限定名即可。
class HelloPlugin implements \App\Plugin\PluginInterface
{
    public function id(): string
    {
        return 'hello';
    }

    // 返回要注入状态栏的段；空数组表示当前无内容。
    public function statusSegments(\App\App $app): array
    {
        return [
            new \App\Plugin\StatusSegment(
                key:     'hello',
                text:     'Hello',
                priority: 55,   // 丢弃优先级：越大越优先保留
                order:    12,   // 显示顺序：越小越靠左
            ),
        ];
    }

    // 内容静态、无需周期刷新，返回 null。
    public function tickInterval(): ?int
    {
        return null;
    }
}
```

### 2.3 运行

直接启动 ViceCode 即可，无需重新编译或清缓存：

```bash
php bin/vicecode.php
```

启动后底部状态栏右侧会出现 `· Hello`（与系统段用 `·` 分隔）。

---

## 3. 插件接口 `PluginInterface`

所有插件必须实现 `App\Plugin\PluginInterface`（`src/Plugin/PluginInterface.php`）：

```php
interface PluginInterface
{
    /** 稳定唯一 id（应与 plugin.json 的 id 对应） */
    public function id(): string;

    /** 返回本帧要注入状态栏的段；空数组表示当前无内容。 */
    public function statusSegments(App $app): array;  // list<StatusSegment>

    /** 是否需要周期重绘以更新自身内容（秒），null=不需要。 */
    public function tickInterval(): ?int;
}
```

此外还有几个**可选**能力（VSCode 式「插件声明默认 + 用户配置覆盖」）。核心一律用 `method_exists` 探测，未实现则完全跳过，不影响插件其它功能：

```php
// 可选：返回本插件默认配置（键 => 默认值）。不实现则无默认。
public function configDefaults(): array;

// 可选：接收「默认 ∩ 用户覆盖」后的最终配置。
public function configure(array $config): void;

// ── V1.1 新增（均为可选）──

// 可选：声明本插件提供的命令（出现在菜单「插件」组，可绑定快捷键）。
public function commands(): array;          // list<PluginCommand>

// 可选：执行某个命令（局部 id）。命令由菜单项 / 快捷键 / 状态栏点击触发。
public function executeCommand(string $id, App $app): void;

// 可选：接收应用生命周期事件（app.ready / config.reloaded / focus.changed / ...）。
public function onEvent(PluginEvent $event): void;
```

用户配置写在插件专用文件 `~/.vicecode.plugins.json` 的 `<id>` 段（详见 §3.4）。`configDefaults()` 与用户配置会做 `array_merge`（用户覆盖默认），再传给 `configure()`。

> **向后兼容**：V1 已有的插件（如 `clock`）不实现 `commands` / `executeCommand` / `onEvent` 也能继续工作——这些能力是「有就启用、没有就忽略」的可选扩展，不是 `PluginInterface` 的必选方法。

### 3.1 `statusSegments(App $app)`

每次界面重绘都会调用一次，返回一个 `StatusSegment` 列表。
你可以访问 `$app` 上的各种运行时状态来动态生成文本，例如：

- `$app->buffer`：当前打开的文件 buffer（文件名、是否只读、是否脏）
- `$app->git->branch`：当前 git 分支
- `$app->terminal->cwd()`：终端当前工作目录（需开启实时 cwd 捕获）
- `$app->chat->spec()`：AI Provider / 模型
- `$app->locale()`：当前语言

> `$app` 上的属性均为公开成员，可在 `src/App.php` 中查看完整可用字段。

### 3.2 `StatusSegment` 字段语义

`src/Plugin/StatusSegment.php`：

```php
final class StatusSegment
{
    public function __construct(
        public string $key,       // 稳定标识，供测试断言「丢了哪一项」
        public string $text,      // 渲染文本
        public int    $priority,  // 丢弃优先级，越大越该保留
        public int    $order,     // 显示顺序，越小越靠左
    ) {}
}
```

- **key**：稳定唯一字符串。状态栏按优先级裁剪时，若某段被丢弃，测试可通过 key 断言是哪一段丢了。
- **text**：要显示的文本。**注意显示宽度**：CJK（中文等）字符宽度按 2 计，emoji 等可能更宽；
  宽度超出状态栏可视宽度时会被统一裁剪，请自行控制长度。
- **priority**：丢弃优先级。状态栏只有一行、信息项很多，放不下时**优先丢弃低优先级项**。
  详见下方「优先级取值表」。
- **order**：显示顺序。与优先级解耦——优先级决定「挤不下时谁先走」，order 决定「留下的谁在左谁在右」。

### 3.3 优先级 / 顺序取值表

插件段与以下**系统段**共享同一套裁剪逻辑（`src/Panel/StatusBarPanel.php`）：

| 段 | key | priority | order | 说明 |
| --- | --- | --- | --- | --- |
| 瞬时消息 | message | 100 | 8 | 最高优先级，确认弹窗时独占整条 |
| 拖拽尺寸 | layout | 95 | 11 | 仅拖拽分隔条时出现 |
| 文件+dirty | file | 90 | 1 | |
| 编辑模式 | mode | 85 | 2 | |
| AI Provider/模型 | ai | 80 | 4 | |
| 侧栏 tab | tab | 75 | 6 | |
| 分支 | branch | 70 | 3 | |
| 退出提示 | quit | 65 | 9 | |
| 终端 cwd | cwd | 60 | 10 | 仅聚焦终端时非空 |
| **（插件）时钟** | clock | 55 | 12 | 内置示例插件的取值，供参考 |
| 应用名 | app | 50 | 0 | |
| 语言 | locale | 40 | 7 | |
| 焦点面板 | focus | 35 | 5 | |

**实用建议**：
- 想让段「轻易不被挤掉」→ priority 取较大值（如 70–95）。
- 想让段「靠左显示」→ order 取较小值（如 0–5）；「靠右」→ order 取较大值（如 11–15）。
- 不要与系统段抢最高优先级（message/file 等），它们没有第二处显示位置，丢了用户会困惑。

### 3.4 配置插件（可选能力）

VSCode 的插件都是可配置的：扩展在 `package.json` 里声明默认设置，用户在 `settings.json` 里覆盖。ViceCode 沿用同一模型：

- **插件声明默认**：在插件类里实现 `configDefaults(): array`，返回 `键 => 默认值`。
- **用户覆盖**：编辑**插件专用配置文件** `~/.vicecode.plugins.json`，在 `<id>` 段写入要覆盖的键（推荐直接在 ViceCode 内打开编辑，见 §3.5）。
- **合并与注入**：核心在启动时做 `array_merge($defaults, $userConfig)`，把结果传给 `configure(array $config)`。
- 未实现 `configDefaults()` / `configure()` 的插件**完全不受影响**——配置是可选能力，核心用 `method_exists` 探测。

> **插件配置与 ViceCode 自身配置是两个文件**：插件专用 `~/.vicecode.plugins.json`（整份即「插件 id => 配置」映射，**无 `plugins` 包裹层**），
> 应用自身（布局/主题/语言）用 `~/.vicerc`，由 `ConfigStore` 分别读写、互不干扰。
> 测试或沙箱环境可用环境变量 `VICECODE_PLUGINS_CONFIG` 指定插件配置文件路径，避免污染真实家目录。

配置写入示例（`~/.vicecode.plugins.json`）：

```json
{
    "clock": {
        "timezone": "Asia/Shanghai",
        "format": "H:i:s"
    }
}
```

插件侧读取：

```php
class ClockPlugin implements \App\Plugin\PluginInterface
{
    private string $tz = 'Asia/Shanghai';
    private string $fmt = 'H:i:s';

    public function configDefaults(): array
    {
        return ['timezone' => 'Asia/Shanghai', 'format' => 'H:i:s'];
    }

    public function configure(array $c): void
    {
        $tz = $c['timezone'] ?? 'Asia/Shanghai';
        if ($tz === 'local') {
            $tz = date_default_timezone_get();
        }
        $this->tz = is_string($tz) && $tz !== '' ? $tz : 'Asia/Shanghai';
        $this->fmt = is_string($c['format'] ?? null) ? $c['format'] : 'H:i:s';
    }

    public function statusSegments(\App\App $app): array
    {
        $dt = new \DateTime('now', new \DateTimeZone($this->tz));
        return [new \App\Plugin\StatusSegment('clock', $dt->format($this->fmt), 55, 12)];
    }
    // id() / tickInterval() 略
}
```

### 3.5 配置入口（UI）

配置**就在 ViceCode 自己的编辑器里改**——我们本身就是 IDE，不会甩给你外部编辑器。两个入口都能到达：

- **菜单栏「文件 → 已安装插件...」**：打开一个浮层，列出所有已加载插件及其当前**有效配置**（默认 ∩ 用户覆盖）。
- **侧栏「扩展」tab（🧩 图标）**：同样列出已加载插件，选中后 `↑/↓` 移动、`Enter` 或点击行打开配置。

在浮层里按 **`Enter`**（或在侧栏扩展 tab 里 `Enter`/点击）即会**用 ViceCode 自带的编辑器打开插件专用配置文件 `~/.vicecode.plugins.json`**——这就是 VSCode「打开设置(JSON)」的同款体验。`~/.vicecode.plugins.json` 与 ViceCode 自身配置 `~/.vicerc`（存布局/主题/语言）**完全分离**，互不干扰。文件内容即「插件 id => 配置」映射，例如：

```json
{
    "clock": { "timezone": "Asia/Shanghai", "format": "H:i:s" }
}
```

直接改对应插件的段，`Ctrl+S` 保存**立即重新加载配置生效**，无需退出进程（相当于 VSCode 的「重载窗口」，但更顺滑）。

> 浮层是模态的：打开期间 `Esc` / `q` 关闭，方向键 / 翻页滚动，`Enter` 在 ViceCode 编辑器内打开**插件专用**配置文件。
> 测试或沙箱可用环境变量 `VICECODE_PLUGINS_CONFIG` 指定插件配置文件路径，避免污染真实家目录。
> 兼容说明：旧版写在 `~/.vicerc` 的 `plugins` 段仍会被读取（一次性回退），用户首次在 ViceCode 内编辑后会写入专用文件。

### 3.6 命令钩子（V1.1）

让插件从「只能显示」变成「能做事」：声明命令，由**菜单项**或**快捷键**触发。

```php
use App\Plugin\PluginCommand;

public function commands(): array
{
    return [
        // id, 标题, 可选快捷键（'Ctrl+K' / 'F3'），可选优先级（同组内的显示顺序）
        new PluginCommand('sync', '同步到远端', 'Ctrl+K', 10),
        new PluginCommand('lint', '运行 Linter'),
    ];
}

public function executeCommand(string $id, \App\App $app): void
{
    // $id 是局部命令 id（如 'sync' / 'lint'），不是完全限定 id
    match ($id) {
        'sync' => $app->setMessage('已同步'),
        'lint' => $app->setMessage('lint 完成'),
    };
}
```

- **菜单入口**：所有插件的命令汇总到菜单栏「插件（🔌）」组，每组一项；进入该组即可用键盘选择执行。
- **快捷键**：`PluginCommand` 第三个参数为 `Ctrl+字母` 或 `F1`–`F12`；声明了才绑定，否则只能从菜单触发。
- **快捷键冲突**：与系统保留键（`Ctrl+S/W/P/N/L/C`、功能键 `F2/F10`）或其它插件撞键时，**该命令的快捷键自动降级为不可用**，命令仍可从菜单触发，并在「已安装插件」浮层给出原因（`taken`=被占用、`reserved`=系统保留、`unsupported`=语法不支持）。先到先得：装载顺序按插件目录名字典序。
- 命令 id 在内部自动加插件 id 前缀（`v11.k` 形式），跨插件天然不撞。

### 3.7 状态栏段点击（V1.1）

让状态栏段**可点击**：点击即触发该段绑定的命令。

```php
public function statusSegments(\App\App $app): array
{
    return [
        // 第 5 个参数 = 该段绑定的命令局部 id；省略或绑不到时该段不可点击
        new \App\Plugin\StatusSegment('last', 'M:' . self::$last, 95, 200, 'sync'),
    ];
}
```

- 只有**确实注册成功**的命令才会让段可点（声明了但快捷键被冲突降级、或命令未实现 `executeCommand` 的段不可点）。
- 状态栏宽度不足被裁剪丢弃的段**不可点**（点击会落空）。点击命中的原理：渲染时记录每个段的 `[起始列, 宽度]`，点击的 x 落在某段区间内即触发其命令。

### 3.8 生命周期事件（V1.1）

插件可接收应用运行期事件，用于做 linter、git 提示、埋点等。`onEvent(PluginEvent $e)` 的 `$e->name` 为事件名，`$e->payload` 为关联数组。

应用派发的全部事件（名称与 payload 以核心实现为准，照抄以免拼写不匹配）：

- `app.ready`：启动完成（无 payload）。
- `config.reloaded`：插件配置被保存并重载（无 payload）。
- `focus.changed`：焦点切换，`payload['from']` / `payload['to']` 为面板 id。
- `file.opened`：打开文件，`payload['path']` 为路径，`payload['virtual']` 为是否虚拟文件。
- `file.saved`：保存文件，`payload['path']` 为路径，`payload['ok']` 为是否成功。
- `file.closed`：关闭文件（缓冲区移除），`payload['path']` 为路径。
- `buffer.switched`：当前编辑缓冲区切换，`payload['path']` 为路径。
- `terminal.output`：终端收到原始字节，`payload['bytes']` 为原始字节（含 ANSI 与 OSC 7 的 cwd 上报）。

```php
public function onEvent(\App\Plugin\PluginEvent $e): void
{
    match ($e->name) {
        'app.ready'       => /* 启动完成 */,
        'config.reloaded' => /* 插件配置被保存重载 */,
        'focus.changed'   => /* 焦点切到 $e->payload['to'] */,
        'file.opened'     => /* 打开 $e->payload['path'] */,
        'file.saved'      => /* 保存了 $e->payload['path'] */,
        'file.closed'     => /* 关闭 $e->payload['path'] */,
        'buffer.switched' => /* 当前 buffer 切到 $e->payload['path'] */,
        'terminal.output' => /* 终端收到原始字节 $e->payload['bytes']（含 ANSI）*/,
        default => null,
    };
}
```

> 事件在**真实原始形态**下派发给插件：例如 `terminal.output` 给的是未经净化的原始字节（可能含 ANSI 转义与 OSC 7 的 cwd 上报），插件需自行解析。事件派发做了**防重入**（插件内再触发事件不会无限递归）与**单插件容错**（某个插件抛异常不影响其它插件)。

### 3.9 命令面板（F1，V1.1）

命令面板（Command Palette）让用户**用键盘快速检索并执行任意命令**，无需在菜单里逐层翻找。

**唤起键：F1。** 你可能在别处见过 `Ctrl+Shift+P`（VSCode 的默认键），但 ViceCode 跑在**真实伪终端**上，而真实 pty 下 `Shift` 这类修饰键**无法被区分**（`Ctrl+Shift+P` 会被终端「吃掉」修饰，退化成裸 `Ctrl+P`，而 `Ctrl+P` 已被 AI 面板用作切换 Provider）。因此本项目统一用 **F1** 唤出命令面板。也可从菜单「视图 → 命令面板 (F1)」进入。

**命令来源（自动汇聚）**：面板在打开时扁平化 `MenuBarPanel::definitions()` 的全部菜单项——既包含系统命令（如「视图 → 聚焦终端」「文件 → 保存」），也包含 §3.6 插件命令（已进入「插件」菜单组）。每条命令携带 `id`（即菜单项的 `action`，如 `view.focus.terminal`、`plugin:<fq>`）、`title`（菜单项 `label`，已是 `Name: Title` 形式）、以及原菜单里登记的 `shortcut`。**插件无需做任何额外工作**，声明了 `commands()` 的命令自动出现在面板里。

**交互**：

- 顶部是过滤框 `> `（占位提示 `输入以筛选命令…`），在此打字即**对标题 + action id 做不区分大小写的子串模糊过滤**；按 `Backspace` 删字，`Esc` 直接关闭面板。
- `↑ / ↓` 在过滤后的结果里移动高亮项（选中行反白显示），`Enter` 执行当前项。
- 选中执行时复用既有 `App::menuAction($id)` 分发——**与从菜单点击触发的是同一条执行路径**，所以命令面板不是「又一套命令系统」，只是菜单项的统一检索入口。执行后面板自动关闭。
- 面板是模态浮层：打开期间独占键盘，鼠标点击被忽略，仅响应上述按键。

**对插件作者意味着什么**：你只要按 §3.6 声明命令，它就**自动**同时获得「菜单项 + 可绑定快捷键 + 状态栏段点击 + 命令面板检索」四条触发路径，无需为命令面板单独适配。

## 4. 周期刷新：`tickInterval()` 与主循环

`statusSegments()` 在**每次重绘**时被调用。但默认主循环只在「有键鼠事件 / 命令有输出 / AI 在流式 / 后台重绘信号」时才重绘。
如果一个插件要显示**会随时间变化**的内容（如时钟），它必须告诉主循环「我需要周期性重绘」。

做法：让 `tickInterval()` 返回一个正整数（秒）：

```php
public function tickInterval(): ?int
{
    return 1; // 每秒触发一次重绘，使内容持续更新
}
```

核心 `bin/vicecode.php` 的主循环会：
- 聚合所有插件的 `tickInterval()`，取**最小间隔**作为 idle 重绘周期；
- 当有待 tick 的插件且 idle（无键鼠/输出）时，按该周期强制重绘，从而驱动 `statusSegments()` 重新取数。

> 多个插件声明不同 tick 时，取最小值（最频繁者决定节奏），避免各自轮子打架。

---

## 5. 内置示例：`clock` 插件剖析

`plugins/clock/` 是最小「动态内容」示范，同时展示**插件配置**能力（见 §3.4）。

要点：
- 用 `DateTime` + **显式时区**，独立于 php.ini 的 `date.timezone`——这样无论本机 php.ini 怎么设，时钟都按配置的时区显示。**这直接修复了「php.ini 设成 UTC 时时钟慢 8 小时」这类问题**：默认 `Asia/Shanghai`（UTC+8），想改成 UTC 或纽约时间，改 `~/.vicecode.plugins.json`（见 §3.5 的编辑器入口）即可，无需动 php.ini。
- `tickInterval()=1` 让主循环每秒重绘一次，时钟持续走动。
- 文本用纯 ASCII `HH:MM:SS`，显示宽度固定 8 列，避免在窄状态栏里被意外折行。

完整实现见 `plugins/clock/ClockPlugin.php`。核心片段（配置 + 时区）：

```php
public function configDefaults(): array
{
    return ['timezone' => 'Asia/Shanghai', 'format' => 'H:i:s'];
}

public function configure(array $c): void
{
    $tz = $c['timezone'] ?? 'Asia/Shanghai';
    if ($tz === 'local') {
        $tz = date_default_timezone_get();
    }
    $this->tz = is_string($tz) && $tz !== '' ? $tz : 'Asia/Shanghai';
    $this->fmt = is_string($c['format'] ?? null) ? $c['format'] : 'H:i:s';
}

public function statusSegments(\App\App $app): array
{
    $dt = new \DateTime('now', new \DateTimeZone($this->tz));
    return [new \App\Plugin\StatusSegment('clock', $dt->format($this->fmt), 55, 12)];
}
```

> 若只想要「跟随本机 php.ini 时区」的原始行为，把 `~/.vicecode.plugins.json` 里 clock 的 `timezone` 设为 `"local"` 即可。

---

## 6. 加载机制与容错

加载由核心的 `src/Plugin/PluginLoader.php` 负责，流程：

1. 扫描 `<项目根>/plugins/` 下每个子目录；
2. 读取该目录的 `plugin.json`；
3. 解析出 `class` / `entry`，**运行时 `require` 入口文件**（方法体内 `require_once`，非文件级）；
4. `new $class()` 实例化，检查是否实现 `PluginInterface`；
5. 通过则收入插件列表，失败（JSON 损坏 / 类不存在 / 实例化抛错 / 不实现接口）仅跳过该插件。

**为什么核心用运行时 `require` 而不是 composer autoload？**
核心通过目录扫描后在运行时 `require` 入口文件并实例化插件，因此：

- **不要把插件写进 `composer.json` 的 autoload**；
- 插件里引用核心类型时直接用全限定名（如 `\App\Plugin\StatusSegment`）即可，核心类已由应用自身加载。

---

## 7. 测试与验收

插件写完后建议跑两套测试：

```bash
# 1) headless 单测（无需终端）：加载、坏插件跳过、段聚合、窄屏裁剪
php tests/plugin_unit.php

# 2) 真实 pty 验收（需支持伪终端的环境）：验证状态栏真正渲染出插件内容
php tests/pty_plugin.php
```

`tests/pty_plugin.php` 会：用伪终端启动应用 → 读屏（先剥 ANSI/OSC 转义）→ 断言状态栏出现 `HH:MM:SS` 时间串 → 退出并校验 `exit=0` 且无 Fatal/Warning。

### 7.1 验收坑：差分渲染

php-tui 采用**差分渲染**——只在重绘时发送相对上一帧**变化的部分**。
例如时钟从 `01:36:39` 变为 `01:36:40`，只会重发变化的末两位字节，pty 字节流里**不再有完整的 `HH:MM:SS`**。
因此用「完整时间串正则」去匹配 pty 流会失败，这是**预期行为**，不是 bug。

要验证「周期刷新真的生效」，可靠做法是临时在 `statusSegments()` 内用静态计数器写文件，
跑几秒后看计数是否随重绘增长（实测 4 秒约调用 9 次即证明主循环在周期性重绘）。验证后删除该调试代码即可。

---

## 8. 调试技巧

- **插件没出现**：先确认目录名、`plugin.json` 的 `id`/`class`/`entry` 拼写一致；用 `php tests/plugin_unit.php` 看 `PluginLoader` 是否扫描到了。
- **段被挤掉**：窄屏下低 priority 段会被丢弃，调高 `priority` 或拉宽终端窗口验证。
- **内容不刷新**：动态内容务必实现 `tickInterval()` 返回正整数，否则只在用户有操作时才重绘。
- **致命错误**：核心加载失败会静默跳过插件；若怀疑是插件自身运行期错误，临时在 `statusSegments()` 里加 `error_log()` 或写文件定位（Swoole 协程分支下调试代码请用简单表达式，避免 `nullsafe ??` 混写抛未捕获异常）。

---

## 9. 局限与后续

**V1 局限（已突破）**：早期版本插件只能扩展**状态栏段**，`statusSegments()` 是唯一扩展点；不能触发动作、修改菜单或接收事件。

**V1.1 已支持**：
- 命令钩子：`commands()` / `executeCommand()` 让插件从「只能显示」变成「能做事」，命令出现在菜单「插件」组，可绑定快捷键（§3.6）。
- 状态栏段点击：`StatusSegment` 第 5 参数绑定命令局部 id，点击即触发（§3.7）。
- 生命周期事件：`onEvent(PluginEvent)` 接收启动完成、配置重载、焦点切换、文件打开/保存/关闭、缓冲区切换、终端输出等事件（§3.8）。
- 配置：`configDefaults()` / `configure()` 声明并接收配置，用户在 `~/.vicecode.plugins.json` 的 `<id>` 段覆盖（§3.4）。

**仍未支持（设计取舍）**：
- **自定义面板**：`App::PANELS` 与布局（六面板）是写死的，新增面板要改焦点枚举、Tab 循环、鼠标命中、build 分支与 `LayoutFactory`，成本很高且会牵动大量既有测试，V1.1 不做。
- 命令面板（`Ctrl+Shift+P`）、插件的启用/禁用开关（`~/.vicecode.plugins.json` 的 `<id>.enabled` 约定由核心加载时读取）等留待后续版本。

路线图以产品需求为准；任何扩展都会保持「运行时动态加载、单插件失败不影响整体」的设计原则。

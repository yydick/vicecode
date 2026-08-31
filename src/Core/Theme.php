<?php
declare(strict_types=1);

namespace App\Core;

use App\Git\GitClient;
use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Style\Style;

/**
 * 配色主题（M6 R4）：把散落各处的颜色收拢成一份「语义角色 → 颜色」的映射。
 *
 * ## 为什么是语义角色而不是直接给颜色
 * 面板里写 `Style::default()->fg(AnsiColor::Green)` 时，没人知道这个 Green 是
 * "git 已暂存"还是"终端提示符"。主题一多就改不动。改成 `$theme->style('gitStaged')`
 * 之后，换主题就是换一张表，业务代码不用动。
 *
 * ## ★ Style 是可变对象的坑（本项目踩过）
 * `Style::fg()/bg()/addModifier()` 都是**原地修改并返回 $this**。所以本类的
 * `style()` / `gitStyle()` / `syntaxStyle()` 每次都必须 `Style::default()` 起新实例；
 * 若缓存一个 Style 复用，某处给它 addModifier 会污染全界面（M1 曾因此让整行反显）。
 * 故这里**不做任何 Style 缓存**——每次新建，代价可忽略。
 *
 * ## 加新角色的步骤
 * 1. 在两个主题的 $ui 里都加上同一个 key（漏一个会走 default 兜底，看起来像没生效）；
 * 2. 业务代码改用 `$theme->style('key')`；
 * 3. tests/m6_unit.php 有断言检查两个主题的 key 集合一致。
 */
final class Theme
{
    /** @param array<string,AnsiColor> $ui @param array<string,array{0:AnsiColor,1:bool}> $syntax */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly array $ui,
        public readonly array $syntax,
    ) {
    }

    /** @return array<string,self> */
    public static function builtin(): array
    {
        return [
            'dark' => self::dark(),
            'midnight' => self::midnight(),
        ];
    }

    public static function byId(string $id): self
    {
        $all = self::builtin();
        return $all[$id] ?? self::dark();
    }

    /** @return string[] */
    public static function ids(): array
    {
        return array_keys(self::builtin());
    }

    public static function default(): self
    {
        return self::dark();
    }

    // ── 取值 ────────────────────────────────────────

    /** 语义角色取色；角色缺失时用 Gray 兜底，避免整个面板炸掉 */
    public function color(string $role): AnsiColor
    {
        return $this->ui[$role] ?? AnsiColor::Gray;
    }

    /** 每次返回**新** Style 实例（见类注释：Style 可变，不能共享缓存） */
    public function style(string $role): Style
    {
        return Style::default()->fg($this->color($role));
    }

    /**
     * git 状态 → Style。与 GitClient::STATUS_* 常量对应。
     * 单独成方法是因为它是一张"状态码 → 角色"的表，放在调用方会散落多处。
     */
    public function gitStyle(string $status): Style
    {
        $role = match ($status) {
            GitClient::STATUS_STAGED => 'gitStaged',
            GitClient::STATUS_MODIFIED => 'gitModified',
            GitClient::STATUS_UNTRACKED => 'gitUntracked',
            GitClient::STATUS_RENAMED => 'gitRenamed',
            GitClient::STATUS_DELETED => 'gitDeleted',
            GitClient::STATUS_CONFLICT => 'gitConflict',
            GitClient::STATUS_IGNORED => 'gitIgnored',
            default => 'gitDefault',
        };
        return $this->style($role);
    }

    /** 语法高亮：hljs token 名 → Style（第二个元素表示是否加粗） */
    public function syntaxStyle(string $token): Style
    {
        [$color, $bold] = $this->syntax[$token] ?? [AnsiColor::White, false];
        $st = Style::default()->fg($color);
        return $bold ? $st->addModifier(\PhpTui\Tui\Style\Modifier::BOLD) : $st;
    }

    // ── 两套内置配色 ─────────────────────────────────

    public static function dark(): self
    {
        return new self(
            id: 'dark',
            label: '深色',
            ui: [
                // 通用
                'border' => AnsiColor::Gray,
                'borderFocus' => AnsiColor::LightGreen,
                'dim' => AnsiColor::DarkGray,
                'muted' => AnsiColor::Gray,
                'accent' => AnsiColor::Cyan,
                'ok' => AnsiColor::Green,
                'warn' => AnsiColor::Yellow,
                'err' => AnsiColor::Red,
                'info' => AnsiColor::LightBlue,
                'special' => AnsiColor::Magenta,

                // 编辑器
                'editorLineNo' => AnsiColor::DarkGray,
                'editorLineNoActive' => AnsiColor::Yellow,
                'editorTilde' => AnsiColor::DarkGray,
                'editorTab' => AnsiColor::Gray,

                // 终端
                'termErr' => AnsiColor::Red,
                'termKilled' => AnsiColor::Yellow,
                'termHint' => AnsiColor::DarkGray,
                'termPromptIdle' => AnsiColor::Green,
                'termPromptBusy' => AnsiColor::Cyan,

                // AI
                'aiUser' => AnsiColor::Cyan,
                'aiDim' => AnsiColor::DarkGray,
                'aiErr' => AnsiColor::Red,

                // 帮助页
                'helpGroup' => AnsiColor::Yellow,

                // GIT
                'gitStaged' => AnsiColor::Green,
                'gitModified' => AnsiColor::Yellow,
                'gitUntracked' => AnsiColor::Red,
                'gitRenamed' => AnsiColor::Magenta,
                'gitDeleted' => AnsiColor::Red,
                'gitConflict' => AnsiColor::LightRed,
                'gitIgnored' => AnsiColor::DarkGray,
                'gitDefault' => AnsiColor::Gray,
                'gitBranch' => AnsiColor::Cyan,
                'gitHead' => AnsiColor::Yellow,
                'gitPlaceholder' => AnsiColor::DarkGray,
                'gitSelMark' => AnsiColor::Green,

                // 搜索
                'searchPlaceholder' => AnsiColor::DarkGray,
                'searchStateRunning' => AnsiColor::Cyan,
                'searchStateIdle' => AnsiColor::Gray,
                'searchGroup' => AnsiColor::Yellow,
            ],
            syntax: [
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
            ],
        );
    }

    /** 第二套：整体偏冷，聚焦边框用亮青，关键字换成品红 */
    public static function midnight(): self
    {
        return new self(
            id: 'midnight',
            label: '午夜蓝',
            ui: [
                'border' => AnsiColor::Blue,
                'borderFocus' => AnsiColor::LightCyan,
                'dim' => AnsiColor::DarkGray,
                'muted' => AnsiColor::Blue,
                'accent' => AnsiColor::LightMagenta,
                'ok' => AnsiColor::LightGreen,
                'warn' => AnsiColor::LightYellow,
                'err' => AnsiColor::LightRed,
                'info' => AnsiColor::LightCyan,
                'special' => AnsiColor::LightMagenta,

                'editorLineNo' => AnsiColor::Blue,
                'editorLineNoActive' => AnsiColor::LightCyan,
                'editorTilde' => AnsiColor::Blue,
                'editorTab' => AnsiColor::Blue,

                'termErr' => AnsiColor::LightRed,
                'termKilled' => AnsiColor::LightYellow,
                'termHint' => AnsiColor::Blue,
                'termPromptIdle' => AnsiColor::LightCyan,
                'termPromptBusy' => AnsiColor::LightMagenta,

                'aiUser' => AnsiColor::LightCyan,
                'aiDim' => AnsiColor::Blue,
                'aiErr' => AnsiColor::LightRed,

                'helpGroup' => AnsiColor::LightYellow,

                'gitStaged' => AnsiColor::LightGreen,
                'gitModified' => AnsiColor::LightYellow,
                'gitUntracked' => AnsiColor::LightRed,
                'gitRenamed' => AnsiColor::LightMagenta,
                'gitDeleted' => AnsiColor::LightRed,
                'gitConflict' => AnsiColor::Red,
                'gitIgnored' => AnsiColor::Blue,
                'gitDefault' => AnsiColor::Gray,
                'gitBranch' => AnsiColor::LightCyan,
                'gitHead' => AnsiColor::LightYellow,
                'gitPlaceholder' => AnsiColor::Blue,
                'gitSelMark' => AnsiColor::LightCyan,

                'searchPlaceholder' => AnsiColor::Blue,
                'searchStateRunning' => AnsiColor::LightCyan,
                'searchStateIdle' => AnsiColor::Gray,
                'searchGroup' => AnsiColor::LightYellow,
            ],
            syntax: [
                'comment' => [AnsiColor::Blue, false],
                'meta' => [AnsiColor::Gray, false],
                'keyword' => [AnsiColor::LightMagenta, true],
                'built_in' => [AnsiColor::LightCyan, false],
                'type' => [AnsiColor::LightCyan, false],
                'class' => [AnsiColor::LightCyan, false],
                'title' => [AnsiColor::LightCyan, false],
                'title.function_' => [AnsiColor::LightCyan, false],
                'function' => [AnsiColor::LightCyan, false],
                'params' => [AnsiColor::LightBlue, false],
                'variable' => [AnsiColor::LightBlue, false],
                'attribute' => [AnsiColor::LightBlue, false],
                'property' => [AnsiColor::LightBlue, false],
                'symbol' => [AnsiColor::LightBlue, false],
                'string' => [AnsiColor::LightGreen, false],
                'attr' => [AnsiColor::LightGreen, false],
                'meta-string' => [AnsiColor::LightGreen, false],
                'number' => [AnsiColor::LightCyan, false],
                'literal' => [AnsiColor::LightMagenta, false],
                'tag' => [AnsiColor::LightRed, false],
                'name' => [AnsiColor::LightRed, false],
                'link' => [AnsiColor::LightCyan, false],
                'emphasis' => [AnsiColor::LightYellow, true],
                'strong' => [AnsiColor::LightYellow, true],
                'section' => [AnsiColor::LightBlue, true],
            ],
        );
    }
}

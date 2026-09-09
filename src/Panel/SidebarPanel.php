<?php
declare(strict_types=1);

namespace App\Panel;

use App\App;
use App\Explorer\FileTree;
use App\Explorer\TreeNode;
use App\Git\GitClient;
use App\Git\GitModel;
use App\Search\SearchRow;
use App\Text\DisplayWidth;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\MouseEventKind;
use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Position\Position;
use PhpTui\Tui\Style\Modifier;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Widget\Widget;

/**
 * 侧栏面板：顶部 tab 行（Explorer / GIT / Search）+ 当前 tab 的内容。
 *
 * 目前只有 Explorer（懒展开目录树）是实内容，GIT / Search 是占位（M3 / M4 会补）。
 *
 * 面板契约（本项目无 interface，靠签名约定保持一致）：
 *   content(Area, bool $focused): Widget —— 生成面板内容 Widget（外层 Block 由 App 加）；
 *   onClick() / onKey() / onScroll() 返回 bool 表示是否已消费事件；
 *   状态归面板自己，App 只负责把事件分发给当前聚焦面板。
 *
 * ⚠️ content() 不是纯函数：它会按当前选中项修正 $offset（保证选中行可见）。
 * 这是既有渲染管线约定，保持原样搬移，不要在重构时「提纯」。
 *
 * 鼠标交互对齐 VSCode 习惯：单击文件=打开进编辑器；单击目录名=只选中；
 * 单击行首三角=展开/折叠；双击目录=展开/折叠。
 * 终端协议没有双击事件（SGR 里双击就是两次独立按下），故自己按时间+位置合成。
 */
final class SidebarPanel
{
    /** 0=Explorer 1=GIT 2=Search 3=Extensions(插件) */
    public const TABS = ['explorer', 'git', 'search', 'plugins'];

    private const DOUBLE_CLICK_MS = 400;

    // GIT tab 内部分区行（inner 行号，0-based，相对 gitContent 起始）
    private const GIT_INPUT_ROW = 2;   // 提交信息输入框
    private const GIT_COMMIT_ROW = 3;  // Commit ▾ 按钮
    private const GIT_HEADER_ROW = 4;  // 变更/日志 标题行（带 +/- 或 ⟳ 图标）
    private const GIT_FIRST_ROW = 5;   // 首条变更/日志
    // gitContent 之前的固定两行：tab 行 + 分隔线（SidebarPanel::content 总是先渲染），
    // 故 gitContent 的实际屏幕行 = sidebar 顶 + 1(上边框) + 此偏移 + 行常量。
    private const GIT_CONTENT_OFFSET = 2;

    // Search tab 内部分区行（inner 行号，0-based，相对 searchContent 起始）
    private const SEARCH_INPUT_ROW = 0;   // 查询输入框
    private const SEARCH_STATUS_ROW = 1;  // 状态行（搜索中 / N 个匹配 / 无结果 / 出错）
    private const SEARCH_FIRST_ROW = 2;   // 首条结果（可见行）
    private const SEARCH_CONTENT_OFFSET = 2; // 同 GIT：tab 行 + 分隔线

    private FileTree $tree;

    /** 树可视区首行的索引（保证选中项可见） */
    public int $offset = 0;

    /** GIT 列表可视区首行索引（status / log 共用） */
    public int $gitOffset = 0;

    /** 分支切换下拉列表可视区首行索引 */
    public int $branchOffset = 0;

    /** Search 结果列表可视区首行索引（单位是「可见行」，含分组标题） */
    public int $searchOffset = 0;

    /** 列表横向滚动偏移（显示列）：鼠标横向滚轮 / 触控板两指横滑调整，树/GIT/Search 列表共用 */
    public int $hScroll = 0;

    /** 当前帧列表最宽行的显示列（在渲染时统计，用于钳制 hScroll 上界） */
    public int $maxHScroll = 0;

    public int $tabIndex = 0;

    /** 扩展(插件) tab 列表选中项索引 */
    public int $pluginSel = 0;

    /** 扩展(插件) tab 列表可视区首行索引 */
    public int $pluginOffset = 0;

    private ?float $lastClickAtMs = null;
    private ?string $lastClickPath = null;
    private ?int $lastClickRow = null;

    public function __construct(private App $shell)
    {
        $this->tree = new FileTree(getcwd() ?: '.');
    }

    /** 供测试与渲染断言访问树（句柄本身仍是私有） */
    public function tree(): FileTree
    {
        return $this->tree;
    }

    public function tabLabel(): string
    {
        return $this->shell->t('sidebar.' . self::TABS[$this->tabIndex]);
    }

    // ── 渲染 ──────────────────────────────────────────

    public function content(Area $sidebar, bool $focused): Widget
    {
        $lines = [];

        // tab 行（配置了图标就只显示图标、不显示文字，省空间；选中用 [ ] 包裹）。
        // 按「显示列宽」预算分段，否则中文标签占 2 列会把段宽撑爆导致 ] 换行（M1 反馈的 bug）。
        $innerW = max(0, $sidebar->width - 2);
        // 先按本帧列表内容算出最宽行的显示列宽（渲染各列表时也会累加，这里用预计算值
        // 在「渲染之前」就把 hScroll 钳到合法上界，避免本帧用越界 hScroll 把行切成空串）。
        $this->maxHScroll = $this->computeMaxHScroll($sidebar);
        $this->hScroll = max(0, min($this->hScroll, max(0, $this->maxHScroll - $innerW)));
        $seg = max(1, intdiv(max(1, $innerW), count(self::TABS))); // 每段可用显示列宽
        $tabLine = '';
        foreach (self::TABS as $i => $key) {
            $icon = $this->shell->icon($key);
            $label = $this->shell->t('sidebar.' . $key);
            // 有图标则只显示图标（不占文字位置）；无图标退化成纯文字标签以保持可用性。
            $core = $icon !== '' ? $icon : ' ' . $label . ' ';
            if ($i === $this->tabIndex) {
                $core = '[' . $core . ']';
            }
            // 段内超宽则按显示列宽截断（保留字素边界，不劈开 CJK）
            if (DisplayWidth::dispWidth($core) > $seg) {
                $core = DisplayWidth::mbCutDisp($core, $seg);
            }
            $tabLine .= DisplayWidth::mbPadDisp($core, $seg);
        }
        if ($innerW > 0) {
            $lines[] = Line::fromSpans(Span::styled(DisplayWidth::mbCutDisp($tabLine, $innerW), $this->shell->theme->style('gitHead')));
            $lines[] = Line::fromSpans(Span::styled(str_repeat('─', $innerW), $this->shell->theme->style('muted')));
        }

        if ($this->tabIndex === 0) {
            $visible = $this->tree->visible();
            $rowsH = max(0, $sidebar->height - 2 - 2); // 边框 + tab行 + 分隔
            if ($rowsH > 0 && !empty($visible)) {
                $idx = $this->selectedIndex($visible);
                if ($idx < $this->offset) {
                    $this->offset = $idx;
                } elseif ($idx >= $this->offset + $rowsH) {
                    $this->offset = $idx - $rowsH + 1;
                }
                if ($this->offset < 0) {
                    $this->offset = 0;
                }
                for ($i = $this->offset; $i < min($this->offset + $rowsH, count($visible)); $i++) {
                    $node = $visible[$i];
                    $indent = str_repeat('  ', $node->depth);
                    $prefix = $node->isDir ? ($node->expanded ? '▼ ' : '▶ ') : '  ';
                    $marker = $node->path === $this->shell->selectedPath ? '» ' : '  ';
                    $suffix = $node->isDir ? '/' : '';
                    // ⚠️ 这里**不能**先 mbCutDisp 到 innerW 再 mbSubDisp(hScroll)：
                    // 文本一旦被截到 innerW 列，横滚就只能在已截断的这 innerW 列里滑动，
                    // 超出部分永远看不到，hScroll 超过 innerW 后整行变空白（深目录/长名必踩）。
                    // 正确做法是只对完整文本做「跳过 hScroll 列再取 innerW 列」。
                    $text = $marker . $indent . $prefix . $node->name . $suffix;
                    $style = $node->path === $this->shell->selectedPath
                        ? Style::default()->addModifier(Modifier::REVERSED)
                        : Style::default();
                    $lines[] = Line::fromSpans(Span::styled(
                        DisplayWidth::mbSubDisp($text, $this->hScroll, $innerW), $style));
                    $this->maxHScroll = max($this->maxHScroll, DisplayWidth::dispWidth($text));
                }
            } elseif (empty($visible)) {
                $lines[] = Line::fromSpans(Span::styled('(empty)', $this->shell->theme->style('dim')));
            }
        } elseif ($this->tabIndex === 1) {
            $this->gitContent($sidebar, $lines);
        } elseif ($this->tabIndex === 2) {
            $this->searchContent($sidebar, $lines);
        } else {
            $this->pluginsContent($sidebar, $lines);
        }

        // 横向滚动上界钳制：仅当最宽行超出视口时才允许右移，避免滚出空白
        $this->hScroll = max(0, min($this->hScroll, max(0, $this->maxHScroll - $innerW)));

        if ($lines === []) {
            return ParagraphWidget::fromString('');
        }
        return ParagraphWidget::fromLines(...$lines);
    }

    /**
     * 预计算本帧各列表「最宽行的显示列宽」，供 content() 在渲染前钳制 hScroll 上界。
     * 必须与实际渲染行文本同构（前缀图标/缩进/着色不可见，不影响列宽），否则钳制会偏松/偏紧。
     */
    private function computeMaxHScroll(Area $sidebar): int
    {
        $innerW = max(0, $sidebar->width - 2);
        $max = 0;
        $w = fn(string $s): int => DisplayWidth::dispWidth($s);

        if ($this->tabIndex === 0) {
            foreach ($this->tree->visible() as $node) {
                $marker = $node->path === $this->shell->selectedPath ? '» ' : '  ';
                $prefix = $node->isDir ? ($node->expanded ? '▼ ' : '▶ ') : '  ';
                $suffix = $node->isDir ? '/' : '';
                $text = $marker . str_repeat('  ', $node->depth) . $prefix . $node->name . $suffix;
                if ($w($text) > $max) {
                    $max = $w($text);
                }
            }
        } elseif ($this->tabIndex === 1) {
            $git = $this->shell->git;
            if ($git->branchDropdownOpen) {
                foreach ($git->branches as $i => $b) {
                    $text = ($b === $git->branch ? '● ' : '  ') . $b;
                    if ($w($text) > $max) {
                        $max = $w($text);
                    }
                }
            } elseif ($git->dropdownOpen) {
                foreach (GitModel::DROPDOWN as $i => $labelKey) {
                    $text = ($i === 0 ? '● ' : '  ') . $this->shell->t($labelKey);
                    if ($w($text) > $max) {
                        $max = $w($text);
                    }
                }
            } elseif ($git->subView === 0) {
                foreach ($git->status as $f) {
                    $text = '▦ + - ✕ ' . ' ' . $f->badge() . ' ' . $f->displayPath();
                    if ($w($text) > $max) {
                        $max = $w($text);
                    }
                }
            } else {
                foreach ($git->log as $c) {
                    $text = $c->hash . ' ' . $c->subject;
                    if ($w($text) > $max) {
                        $max = $w($text);
                    }
                }
            }
        } elseif ($this->tabIndex === 2) {
            foreach ($this->shell->search->buildVisibleRows() as $row) {
                if ($row->kind === SearchRow::HEADER) {
                    $mark = isset($this->shell->search->collapsed[$row->path]) ? '▶ ' : '▼ ';
                    $text = $mark . $row->path . ' (' . ($row->group?->count() ?? 0) . ')';
                } else {
                    $text = '  ' . ($row->hit?->line ?? 0) . ': ' . ltrim($row->hit?->text ?? '');
                }
                if ($w($text) > $max) {
                    $max = $w($text);
                }
            }
        } else {
            foreach ($this->shell->plugins as $p) {
                $cfg = $this->shell->pluginEffectiveConfig($p);
                $cfgStr = $cfg === [] ? $this->shell->t('plugins.no_config')
                    : implode(' ', array_map(static fn($k, $v): string => $k . '=' . $v, array_keys($cfg), $cfg));
                $text = '» ' . $p->id() . ' [' . $this->shell->t('plugins.enabled') . '] ' . $cfgStr;
                if ($w($text) > $max) {
                    $max = $w($text);
                }
            }
        }
        // 视口本身也参与：至少保证 hScroll 上界不为负（不影响主逻辑，防御性）
        return max($max, $innerW);
    }

    /**
     * 分支切换下拉（R6）：覆盖 GIT 内容区，列出本地分支。
     * 行0 标题（⎇ 切换分支 (Esc)）· 行1 分隔 · 行2+ 分支列表（当前 ● / 选中 REVERSED）。
     * 列表可视区按 branchSelIdx 自动滚动（与 status/log 列表同约定）。
     * @param list<Line> $lines
     */
    private function gitBranchPicker(Area $sidebar, array &$lines): void
    {
        $git = $this->shell->git;
        $innerW = max(0, $sidebar->width - 2);

        $lines[] = Line::fromSpans(Span::styled(
            DisplayWidth::mbCutDisp('⎇ ' . $this->shell->t('git.branch_pick') . ' (Esc)', $innerW),
            $this->shell->theme->style('gitBranch')));
        $lines[] = Line::fromSpans(Span::styled(str_repeat('─', $innerW), $this->shell->theme->style('muted')));

        $innerH = max(0, $sidebar->height - 2);
        $itemRows = max(0, $innerH - 2); // 标题 + 分隔
        if ($itemRows <= 0 || $git->branches === []) {
            $lines[] = Line::fromSpans(Span::styled(
                DisplayWidth::mbCutDisp($this->shell->t('git.no_branch'), $innerW),
                $this->shell->theme->style('dim')));
            return;
        }

        $this->clampBranchOffset(count($git->branches), $itemRows);
        for ($i = $this->branchOffset; $i < min($this->branchOffset + $itemRows, count($git->branches)); $i++) {
            $b = $git->branches[$i];
            $cur = $b === $git->branch;
            $sel = $i === $git->branchSelIdx;
            $marker = $cur ? '● ' : '  ';
            $text = $marker . $b;
            $style = $sel
                ? Style::default()->addModifier(Modifier::REVERSED)
                : ($cur ? $this->shell->theme->style('ok') : Style::default());
            $lines[] = Line::fromSpans(Span::styled(DisplayWidth::mbSubDisp($text, $this->hScroll, $innerW), $style));
            $this->maxHScroll = max($this->maxHScroll, DisplayWidth::dispWidth($text));
        }
    }

    /**
     * GIT tab 内容（M3/R5，VSCode 风格可视化）：
     *   行0 分支头 · 行1 分隔 · 行2 提交信息输入框 · 行3 Commit▾ 按钮 · 行4 变更/日志标题(+/- 或 ⟳)
     *   · 行5+ 变更/日志列表（每行带 [+][-] 图标）。
     * 下拉展开时（行3 的 ▾）行4 起被菜单覆盖。
     * 选中行 REVERSED；列表可视区按 selIdx 自动滚动（content() 非纯函数，与 Explorer 同约定）。
     * @param list<Line> $lines
     */
    private function gitContent(Area $sidebar, array &$lines): void
    {
        $git = $this->shell->git;
        $innerW = max(0, $sidebar->width - 2);

        if (!$git->inRepo()) {
            $lines[] = Line::fromSpans(Span::styled(
                DisplayWidth::mbCutDisp($this->shell->t('git.not_repo'), $innerW),
                $this->shell->theme->style('dim')));
            return;
        }

        // 分支切换下拉展开：覆盖整个 GIT 内容区，列出本地分支（当前 ● / 选中 REVERSED）
        if ($git->branchDropdownOpen) {
            $this->gitBranchPicker($sidebar, $lines);
            return;
        }

        // 行0：分支 + 子视图标题（分支名可点击，▾ 提示可展开切换下拉）
        $viewTitle = $git->subView === 0
            ? $this->shell->t('git.view_status', ['n' => count($git->status)])
            : $this->shell->t('git.view_log', ['n' => count($git->log)]);
        $branchText = $git->branchDropdownOpen
            ? '⎇ ' . $this->shell->t('git.branch_pick') . ' (Esc)'
            : '⎇ ' . $git->branch . ' ▾  ' . $viewTitle;
        $lines[] = Line::fromSpans(Span::styled(
            DisplayWidth::mbCutDisp($branchText, $innerW),
            $this->shell->theme->style('gitBranch')));
        $lines[] = Line::fromSpans(Span::styled(str_repeat('─', $innerW), $this->shell->theme->style('muted')));

        // 行2：提交信息输入框（默认聚焦，键入即进 commitMsg；空时显占位）
        $msg = $git->commitMsg;
        $inputText = $msg === '' ? $this->shell->t('git.msg_placeholder') : $msg;
        $inputStyle = $msg === '' ? $this->shell->theme->style('gitPlaceholder') : Style::default();
        $lines[] = Line::fromSpans(Span::styled(
            DisplayWidth::mbCutDisp('> ' . $inputText . '▏', $innerW), $inputStyle));

        // 行3：Commit ▾ 按钮（REVERSED 表示可点击；▾ 展开下拉）
        $arrow = $git->dropdownOpen ? '▴' : '▾';
        $btn = DisplayWidth::mbPadDisp(' Commit ' . $arrow, $innerW);
        $lines[] = Line::fromSpans(Span::styled($btn, Style::default()->addModifier(Modifier::REVERSED)));

        // 下拉菜单：覆盖从标题行起，点击项触发对应动作
        if ($git->dropdownOpen) {
            $i = 0;
            foreach (GitModel::DROPDOWN as $labelKey) {
                $text = ($i === 0 ? '● ' : '  ') . $this->shell->t($labelKey);
                $lines[] = Line::fromSpans(Span::styled(
                    DisplayWidth::mbSubDisp($text, $this->hScroll, $innerW),
                    $i === 0 ? $this->shell->theme->style('gitSelMark') : Style::default()));
                $this->maxHScroll = max($this->maxHScroll, DisplayWidth::dispWidth($text));
                $i++;
            }
            return;
        }

        // 行4：变更/日志 标题（带 +/- 或 ⟳ 图标）
        if ($git->subView === 0) {
            $title = $this->shell->t('git.changes', ['n' => count($git->status)]);
            $head = DisplayWidth::mbCutDisp($title, max(0, $innerW - 5))
                . '  ' . str_repeat(' ', max(0, $innerW - mb_strwidth($title) - 5)) . '+ -';
            $lines[] = Line::fromSpans(Span::styled($head, $this->shell->theme->style('gitHead')));
        } else {
            $title = $this->shell->t('git.log_title', ['n' => count($git->log)]);
            $head = DisplayWidth::mbCutDisp($title, max(0, $innerW - 4))
                . '  ' . str_repeat(' ', max(0, $innerW - mb_strwidth($title) - 4)) . '⟳';
            $lines[] = Line::fromSpans(Span::styled($head, $this->shell->theme->style('gitHead')));
        }

        // 行5+：变更/日志列表
        $innerH = max(0, $sidebar->height - 2);
        $itemRows = max(0, $innerH - self::GIT_FIRST_ROW);
        if ($itemRows <= 0) {
            return;
        }

        if ($git->subView === 0) {
            $items = $git->status;
            if ($items === []) {
                $lines[] = Line::fromSpans(Span::styled(
                    DisplayWidth::mbCutDisp($this->shell->t('git.clean'), $innerW),
                    $this->shell->theme->style('ok')));
                return;
            }
            $this->clampGitOffset(count($items), $itemRows);
            for ($i = $this->gitOffset; $i < min($this->gitOffset + $itemRows, count($items)); $i++) {
                $f = $items[$i];
                $sel = $i === $git->selIdx;
                $text = ' ' . $f->badge() . ' ' . $f->displayPath();
                $style = $sel
                    ? Style::default()->addModifier(Modifier::REVERSED)
                    : Style::default()->fg($this->gitColor($f->category()));
                // 行首图标：▦=打开文件，+ =暂存，- =取消暂存，✕ =丢弃工作区改动（不可逆）；点文件名=开 diff
                $line = '▦ + - ✕ ' . $text;
                $lines[] = Line::fromSpans(Span::styled(DisplayWidth::mbSubDisp($line, $this->hScroll, $innerW), $style));
                $this->maxHScroll = max($this->maxHScroll, DisplayWidth::dispWidth($line));
            }
        } else {
            $items = $git->log;
            if ($items === []) {
                $lines[] = Line::fromSpans(Span::styled(
                    DisplayWidth::mbCutDisp($this->shell->t('git.no_log'), $innerW),
                    $this->shell->theme->style('dim')));
                return;
            }
            $this->clampGitOffset(count($items), $itemRows);
            for ($i = $this->gitOffset; $i < min($this->gitOffset + $itemRows, count($items)); $i++) {
                $c = $items[$i];
                $sel = $i === $git->selIdx;
                $text = $c->hash . ' ' . $c->subject;
                $style = $sel
                    ? Style::default()->addModifier(Modifier::REVERSED)
                    : $this->shell->theme->style('muted');
                $lines[] = Line::fromSpans(Span::styled(DisplayWidth::mbSubDisp($text, $this->hScroll, $innerW), $style));
                $this->maxHScroll = max($this->maxHScroll, DisplayWidth::dispWidth($text));
            }
        }
    }

    /**
     * Search tab 内容（M4/R1–R3）：
     *   行0 查询输入框 · 行1 状态行 · 行2+ 按文件分组的结果（分组标题可折叠）。
     *
     * 结果行来自 SearchModel::buildVisibleRows()——渲染与点击命中共用这一份摊平列表，
     * 折叠后「屏幕行号」与「命中下标」不再线性对应，各算一套必然点错行。
     * 选中行 REVERSED；可视区按 selIdx 自动滚动（content() 非纯，与 Explorer/GIT 同约定）。
     * @param list<Line> $lines
     */
    private function searchContent(Area $sidebar, array &$lines): void
    {
        $s = $this->shell->search;
        $innerW = max(0, $sidebar->width - 2);

        // 行0：查询输入框（默认聚焦，键入即进 query；空时显占位）
        $q = $s->query;
        $inputText = $q === '' ? $this->shell->t('search.placeholder') : $q;
        $inputStyle = $q === '' ? $this->shell->theme->style('searchPlaceholder') : Style::default();
        $lines[] = Line::fromSpans(Span::styled(
            DisplayWidth::mbCutDisp('> ' . $inputText . '▏', $innerW), $inputStyle));

        // 行1：状态行（搜索中 / N 个匹配 / 无结果 / 出错）
        $lines[] = Line::fromSpans(Span::styled(
            DisplayWidth::mbCutDisp($this->searchStatusText(), $innerW),
            $this->shell->theme->style($s->running ? 'searchStateRunning' : 'searchStateIdle')));

        // 行2+：结果列表
        $innerH = max(0, $sidebar->height - 2);
        $itemRows = max(0, $innerH - self::SEARCH_FIRST_ROW);
        if ($itemRows <= 0) {
            return;
        }
        $visible = $s->buildVisibleRows();
        if ($visible === []) {
            return; // 状态行已经说明了「无结果 / 还没搜」，这里不再重复占一行
        }
        $this->clampSearchOffset(count($visible), $itemRows);
        for ($i = $this->searchOffset; $i < min($this->searchOffset + $itemRows, count($visible)); $i++) {
            $row = $visible[$i];
            $sel = $i === $s->selIdx;
            if ($row->kind === SearchRow::HEADER) {
                $mark = isset($s->collapsed[$row->path]) ? '▶ ' : '▼ ';
                $text = $mark . $row->path . ' (' . ($row->group?->count() ?? 0) . ')';
                $style = $sel
                    ? Style::default()->addModifier(Modifier::REVERSED)
                    : $this->shell->theme->style('searchGroup');
            } else {
                $text = '  ' . ($row->hit?->line ?? 0) . ': ' . ltrim($row->hit?->text ?? '');
                $style = $sel
                    ? Style::default()->addModifier(Modifier::REVERSED)
                    : Style::default();
            }
            $lines[] = Line::fromSpans(Span::styled(DisplayWidth::mbSubDisp($text, $this->hScroll, $innerW), $style));
            $this->maxHScroll = max($this->maxHScroll, DisplayWidth::dispWidth($text));
        }
    }

    /** Search 状态行文案（搜索中优先，其次错误，再是无结果，最后是命中统计） */
    private function searchStatusText(): string
    {
        $s = $this->shell->search;
        if ($s->running) {
            return $this->shell->t('search.status_searching');
        }
        if ($s->error !== null) {
            return $this->shell->t('search.status_error', ['msg' => $s->error]);
        }
        if ($s->totalMatches === 0) {
            // query 空着 = 还没搜过，状态行留空（输入框占位已说明用途），不报「无匹配」
            if ($s->query === '') {
                return '';
            }
            // query 非空但还在输入态 = 还没搜，提示「按回车搜索」而非「无匹配结果」（误导）
            if ($s->editingQuery) {
                return $this->shell->t('search.hint');
            }
            // 否则是「搜过了、确实没命中」
            return $this->shell->t('search.status_no_results');
        }
        return $this->shell->t('search.results', [
            'n' => (string) $s->totalMatches,
            'files' => (string) $s->totalFiles,
        ]);
    }

    /** 按选中行夹紧 Search 结果可视区首行（保证选中行可见） */
    private function clampSearchOffset(int $n, int $rowsH): void
    {
        $idx = $this->shell->search->selIdx;
        if ($idx < $this->searchOffset) {
            $this->searchOffset = $idx;
        } elseif ($idx >= $this->searchOffset + $rowsH) {
            $this->searchOffset = $idx - $rowsH + 1;
        }
        if ($this->searchOffset < 0) {
            $this->searchOffset = 0;
        }
        if ($this->searchOffset > max(0, $n - $rowsH)) {
            $this->searchOffset = max(0, $n - $rowsH);
        }
    }

    /** Search tab 各可点击元素的屏幕坐标（渲染与命中测试共用，保证一致） */
    private function searchRects(Area $sb): array
    {
        $innerX = $sb->position->x + 1;
        $innerW = max(0, $sb->width - 2);
        $y = static fn (int $row): int => $sb->position->y + 1 + self::SEARCH_CONTENT_OFFSET + $row;
        return [
            'innerX' => $innerX,
            'innerW' => $innerW,
            'inputY' => $y(self::SEARCH_INPUT_ROW),
            'statusY' => $y(self::SEARCH_STATUS_ROW),
            'listY0' => $y(self::SEARCH_FIRST_ROW),
        ];
    }

    /**
     * Search tab 鼠标点击分发（M4/R1–R3）：
     *   - 输入框行：聚焦并转回「编辑查询」语义（回车=重新搜索）；
     *   - 状态行：无动作（吞掉，避免落到列表上）；
     *   - 结果行：分组标题 = 折叠/展开，命中行 = 打开文件并定位到行。
     * @param array<string,Area> $areas
     */
    private function searchClick(Position $pos, array $areas): bool
    {
        $s = $this->shell->search;
        $r = $this->searchRects($areas['sidebar']);
        $row = $pos->y;

        if ($row === $r['inputY']) {
            $s->editingQuery = true;
            return true;
        }
        if ($row === $r['statusY']) {
            return true;
        }
        if ($row >= $r['listY0']) {
            $idx = ($row - $r['listY0']) + $this->searchOffset;
            $visible = $s->buildVisibleRows();
            if (!isset($visible[$idx])) {
                return true;
            }
            $s->selIdx = $idx;
            $s->editingQuery = false;
            $target = $visible[$idx];
            if ($target->kind === SearchRow::HEADER) {
                $s->toggleGroup($target->path);
            } elseif ($target->hit !== null) {
                $s->openHit($target->hit->path, $target->hit->line);
            }
            return true;
        }
        return true;
    }

    /**
     * Search tab 按键（M4）：↑/↓ 在结果间移动、Enter 搜索或打开、Backspace 删查询字。
     * 可打印字符进 query 的分支在 App::handle 里（与 GIT 提交框同款，因共用 sidebar 焦点）。
     */
    private function searchKey(CodedKeyEvent $e): bool
    {
        $s = $this->shell->search;
        switch ($e->code) {
            case KeyCode::Up:
                $s->moveSelection(-1);
                return true;
            case KeyCode::Down:
                $s->moveSelection(1);
                return true;
            case KeyCode::Enter:
                $s->triggerOrActivate();
                return true;
            case KeyCode::Backspace:
                $s->query = mb_substr($s->query, 0, max(0, mb_strlen($s->query) - 1));
                $s->editingQuery = true; // 一改查询词就回到「回车=重新搜索」语义
                return true;
            default:
                return false; // Esc 等交全局处理
        }
    }

    /** 扩展(插件) tab 按键：↑/↓ 移动、Enter 打开配置浮层 */
    private function pluginsKey(CodedKeyEvent $e): bool
    {
        switch ($e->code) {
            case KeyCode::Up:
                $this->movePluginSelection(-1);
                return true;
            case KeyCode::Down:
                $this->movePluginSelection(1);
                return true;
            case KeyCode::Enter:
                $plugins = $this->shell->plugins;
                if (isset($plugins[$this->pluginSel])) {
                    $this->shell->pluginsPanel->open();
                }
                return true;
            default:
                return false;
        }
    }

    /** 扩展(插件) tab 内容：列出已加载插件及其有效配置；Enter/点击打开配置浮层。 */
    private function pluginsContent(Area $sidebar, array &$lines): void
    {
        $innerW = max(0, $sidebar->width - 2);
        $plugins = $this->shell->plugins;
        if ($plugins === []) {
            $lines[] = Line::fromSpans(Span::styled(
                $this->shell->t('plugins.no_plugins'),
                $this->shell->theme->style('dim')
            ));
            return;
        }
        $this->pluginSel = max(0, min($this->pluginSel, count($plugins) - 1));
        $rowsH = max(0, $sidebar->height - 2 - 2); // 边框 + tab行 + 分隔
        if ($rowsH <= 0) {
            return;
        }
        if ($this->pluginSel < $this->pluginOffset) {
            $this->pluginOffset = $this->pluginSel;
        } elseif ($this->pluginSel >= $this->pluginOffset + $rowsH) {
            $this->pluginOffset = $this->pluginSel - $rowsH + 1;
        }
        if ($this->pluginOffset < 0) {
            $this->pluginOffset = 0;
        }
        for ($i = $this->pluginOffset; $i < min($this->pluginOffset + $rowsH, count($plugins)); $i++) {
            $p = $plugins[$i];
            $cfg = $this->shell->pluginEffectiveConfig($p);
            $cfgStr = $cfg === []
                ? $this->shell->t('plugins.no_config')
                : implode(' ', array_map(static fn($k, $v): string => $k . '=' . $v, array_keys($cfg), $cfg));
            $tick = method_exists($p, 'tickInterval') ? $p->tickInterval() : null;
            $tickStr = $tick !== null ? "tick={$tick}s" : 'tick=none';
            $sel = $i === $this->pluginSel;
            $marker = $sel ? '» ' : '  ';
            $text = $marker . $p->id() . '  [' . $this->shell->t('plugins.enabled') . ' · ' . $tickStr . ']  ' . $cfgStr;
            $style = $sel
                ? Style::default()->addModifier(Modifier::REVERSED)
                : Style::default();
            $lines[] = Line::fromSpans(Span::styled(DisplayWidth::mbSubDisp($text, $this->hScroll, $innerW), $style));
            $this->maxHScroll = max($this->maxHScroll, DisplayWidth::dispWidth($text));
        }
    }

    /** GIT 状态类别 → 颜色（与 VSCode gutter 着色近似） */
    private function gitColor(string $cat): AnsiColor
    {
        return match ($cat) {
            GitClient::STATUS_STAGED => $this->shell->theme->color('gitStaged'),
            GitClient::STATUS_MODIFIED => $this->shell->theme->color('gitModified'),
            GitClient::STATUS_UNTRACKED => $this->shell->theme->color('gitUntracked'),
            GitClient::STATUS_RENAMED => $this->shell->theme->color('gitRenamed'),
            GitClient::STATUS_DELETED => $this->shell->theme->color('gitDeleted'),
            GitClient::STATUS_CONFLICT => $this->shell->theme->color('gitConflict'),
            GitClient::STATUS_IGNORED => $this->shell->theme->color('gitIgnored'),
            default => $this->shell->theme->color('gitDefault'),
        };
    }

    /** 按选中行夹紧 git 列表可视区首行（保证选中行可见） */
    private function clampGitOffset(int $n, int $rowsH): void
    {
        $idx = $this->shell->git->selIdx;
        if ($idx < $this->gitOffset) {
            $this->gitOffset = $idx;
        } elseif ($idx >= $this->gitOffset + $rowsH) {
            $this->gitOffset = $idx - $rowsH + 1;
        }
        if ($this->gitOffset < 0) {
            $this->gitOffset = 0;
        }
        if ($this->gitOffset > max(0, $n - $rowsH)) {
            $this->gitOffset = max(0, $n - $rowsH);
        }
    }

    /** 按选中行夹紧分支列表可视区首行（保证选中行可见） */
    private function clampBranchOffset(int $n, int $rowsH): void
    {
        $idx = $this->shell->git->branchSelIdx;
        if ($idx < $this->branchOffset) {
            $this->branchOffset = $idx;
        } elseif ($idx >= $this->branchOffset + $rowsH) {
            $this->branchOffset = $idx - $rowsH + 1;
        }
        if ($this->branchOffset < 0) {
            $this->branchOffset = 0;
        }
        if ($this->branchOffset > max(0, $n - $rowsH)) {
            $this->branchOffset = max(0, $n - $rowsH);
        }
    }

    /** GIT tab 各可点击元素的屏幕坐标（渲染与命中测试共用，保证一致） */
    private function gitRects(Area $sb): array
    {
        $innerX = $sb->position->x + 1;
        $innerW = max(0, $sb->width - 2);
        $y = static fn (int $row): int => $sb->position->y + 1 + self::GIT_CONTENT_OFFSET + $row;
        return [
            'innerX' => $innerX,
            'innerW' => $innerW,
            'inputY' => $y(self::GIT_INPUT_ROW),
            'commitY' => $y(self::GIT_COMMIT_ROW),
            'commitArrowX' => $innerX + max(0, $innerW - 1),
            'headerY' => $y(self::GIT_HEADER_ROW),
            'headerPlusX' => $innerX + max(0, $innerW - 3),   // 标题行 '+' 在倒数第 3 列
            'headerMinusX' => $innerX + max(0, $innerW - 1),  // 标题行 '-'/'⟳' 在末列
            'firstY' => $y(self::GIT_FIRST_ROW),
            'itemOpenX' => $innerX + 0,                      // ▦（打开文件）在**文本列** 0
            'itemPlusX' => $innerX + 2,                      // +（暂存）在文本列 2
            'itemMinusX' => $innerX + 4,                     // -（取消暂存）在文本列 4
            'itemDiscardX' => $innerX + 6,                   // ✕（丢弃工作区改动）在文本列 6
            'itemNameX' => $innerX + 8,                     // 文件名起始文本列（点此=开 diff）
            'menuY0' => $y(self::GIT_HEADER_ROW),           // 下拉覆盖从标题行起
            'branchRowY' => $y(0),                          // 分支行（GIT 内容行 0）：点击打开切换下拉
            'branchListY0' => $y(2),                        // 分支下拉列表起始行（标题 + 分隔之后）
        ];
    }

    /**
     * GIT tab 鼠标点击分发（R5 可视化）：
     *   - 下拉展开时：点菜单项触发动作，点别处关闭；
     *   - 输入框行：默认聚焦，无需处理；
     *   - Commit 行：点 ▾ 切换下拉，点主区 = 提交；
     *   - 标题行：changes 的 +/- = 全部暂存/取消全部暂存，日志的 ⟳ = 刷新，点标题文字切子视图；
     *   - 列表行：+/- = 暂存/取消暂存该文件，点文件名 = 打开变更(diff)。
     * @param array<string,Area> $areas
     */
    private function gitClick(Position $pos, array $areas): bool
    {
        $git = $this->shell->git;
        $r = $this->gitRects($areas['sidebar']);
        $col = $pos->x;
        $row = $pos->y;

        // 分支切换下拉展开：点列表项即切换；点标题/别处关闭
        if ($git->branchDropdownOpen) {
            if ($row >= $r['branchListY0']) {
                $idx = ($row - $r['branchListY0']) + $this->branchOffset;
                if (isset($git->branches[$idx])) {
                    $git->switchBranch($git->branches[$idx]);
                    return true;
                }
            }
            $git->branchDropdownOpen = false;
            return true;
        }

        // 点分支行（左侧 ⎇ 区域）：打开分支切换下拉
        if ($row === $r['branchRowY'] && !$git->branchDropdownOpen) {
            $git->openBranchDropdown();
            return true;
        }

        // 下拉展开：菜单项命中或点别处关闭
        if ($git->dropdownOpen) {
            if ($row >= $r['menuY0'] && $row < $r['menuY0'] + count(GitModel::DROPDOWN)) {
                $keys = array_keys(GitModel::DROPDOWN);
                $git->runDropdown($keys[$row - $r['menuY0']]);
            } else {
                $git->dropdownOpen = false;
            }
            return true;
        }

        if ($row === $r['inputY']) {
            return true; // 输入框默认聚焦，点击即聚焦
        }

        if ($row === $r['commitY']) {
            if ($col >= $r['commitArrowX'] - 1) {
                $git->dropdownOpen = true;
            } else {
                $git->commit($git->commitMsg);
            }
            return true;
        }

        if ($row === $r['headerY']) {
            if ($git->subView === 0) {
                if ($col >= $r['headerMinusX'] - 1) {
                    $git->unstageAll();
                } elseif ($col >= $r['headerPlusX'] - 1) {
                    $git->stageAll();
                } else {
                    $git->toggleSubView();
                }
            } else {
                if ($col >= $r['headerMinusX'] - 1) {
                    $git->refresh();
                } else {
                    $git->toggleSubView();
                }
            }
            return true;
        }

        if ($row >= $r['firstY']) {
            $idx = ($row - $r['firstY']) + $this->gitOffset;
            $items = $git->subView === 0 ? $git->status : $git->log;
            if (!isset($items[$idx])) {
                return true;
            }
            $git->selIdx = $idx;
            if ($git->subView === 0) {
                // 行首图标：▦ 打开文件 / + 暂存 / - 取消暂存 / ✕ 丢弃（不可逆，弹确认）；其余=开 diff
                // ⚠️ 列表行是按 mbSubDisp(完整文本, hScroll, innerW) 渲染的：
                // **屏幕列 = 文本列 - hScroll**，而下面这些图标位置是「文本列」。
                // 必须换算回文本列再判定，否则横滚后点路径文本会误触发行首图标
                // （其中 ✕ = 丢弃工作区改动，不可逆 —— 深路径横滚后必踩）。
                $tc = ($col - $r['innerX']) + $this->hScroll;
                if ($tc <= 1) {
                    $git->openFileSelected();          // ▦ 在文本列 0~1
                } elseif ($tc >= 2 && $tc <= 3) {
                    $git->stageSelected();             // + 在文本列 2~3
                } elseif ($tc >= 4 && $tc <= 5) {
                    $git->unstageSelected();           // - 在文本列 4~5
                } elseif ($tc >= 6 && $tc <= 7) {
                    $git->requestDiscardSelected();    // ✕ 在文本列 6~7（弹确认）
                } else {
                    $git->openDiff();                  // 文件名 = 打开变更(diff)
                }
            }
            return true;
        }

        return true;
    }

    // ── 事件 ────────────────────────────────────────

    /**
     * 点击侧栏：先判 tab 行，再判树条目。
     * @param array<string,Area> $areas
     */
    public function onClick(Position $pos, array $areas): bool
    {
        $sb = $areas['sidebar'];

        // tab 行（inner 第 0 行）
        if ($pos->y === $sb->position->y + 1
            && $pos->x >= $sb->position->x && $pos->x < $sb->position->x + $sb->width) {
            $inner = $pos->x - ($sb->position->x + 1);
            $seg = max(1, intdiv(max(1, $sb->width - 2), count(self::TABS)));
            $this->tabIndex = min(count(self::TABS) - 1, intdiv($inner, $seg));
            $this->shell->focus('sidebar');
            // 切到 GIT tab 即异步刷新 status/log/branch（M3 R1/R2/R3）
            if ($this->tabIndex === 1) {
                $this->shell->git->refresh();
            }
            return true;
        }

        // GIT tab：交给 gitClick 处理（输入框/Commit 按钮/下拉/标题 +/-/列表项）
        if ($this->tabIndex === 1) {
            return $this->gitClick($pos, $areas);
        }

        // SEARCH tab：交给 searchClick 处理（输入框/状态/结果列表）
        if ($this->tabIndex === 2) {
            return $this->searchClick($pos, $areas);
        }

        // 扩展(插件) tab：点击插件行 → 打开配置浮层（与「文件 → 已安装插件」同一浮层）
        if ($this->tabIndex === 3) {
            if ($pos->y >= $sb->position->y + 3) {
                $idx = ($pos->y - ($sb->position->y + 3)) + $this->pluginOffset;
                $plugins = $this->shell->plugins;
                if (isset($plugins[$idx])) {
                    $this->pluginSel = $idx;
                    $this->shell->pluginsPanel->open();
                }
            }
            return true;
        }

        // 树条目（inner 第 2 行起）
        if ($this->tabIndex !== 0 || !$sb->containsPosition($pos) || $pos->y < $sb->position->y + 3) {
            return false;
        }
        $visible = $this->tree->visible();
        $idx = ($pos->y - ($sb->position->y + 3)) + $this->offset;
        if (!isset($visible[$idx])) {
            return false;
        }
        $node = $visible[$idx];

        // 点行首三角（▶/▼）= 展开/折叠（VSCode 习惯）。
        // 命中区取「三角 + 其后空格」2 列：只判三角那 1 列太窄，很难点中。
        if ($node->isDir && $this->hitArrow($pos, $sb, $node->depth)) {
            $this->resetDoubleClick();
            $this->shell->selectedPath = $node->path;
            $this->shell->focus('sidebar');
            $this->toggle($node);
            return true;
        }

        $isDouble = $this->consumeDoubleClick($node->path, $pos->y);

        $this->shell->selectedPath = $node->path;
        $this->shell->focus('sidebar');

        if ($node->isDir) {
            // 双击目录 = 展开/折叠（VSCode 习惯）；单击条目名只选中，保持原样
            if ($isDouble) {
                $this->toggle($node);
            }
            return true;
        }
        $this->shell->openFile($node->path);
        return true;
    }

    /** Enter：目录展开/折叠，文件打开进编辑器 */
    public function onKey(CodedKeyEvent $e, array $areas): bool
    {
        // GIT tab 用自己的导航语义
        if ($this->tabIndex === 1) {
            return $this->gitKey($e);
        }
        // SEARCH tab 用自己的导航语义（↑/↓/Enter/Backspace）
        if ($this->tabIndex === 2) {
            return $this->searchKey($e);
        }
        // 扩展(插件) tab：↑/↓ 移动、Enter 打开配置浮层
        if ($this->tabIndex === 3) {
            return $this->pluginsKey($e);
        }
        switch ($e->code) {
            case KeyCode::Enter:
                $this->activate();
                return true;
            case KeyCode::Up:
                $this->moveSelection(-1);
                return true;
            case KeyCode::Down:
                $this->moveSelection(1);
                return true;
            // ←/→ 走 VSCode 的树导航语义：→ 展开（已展开则进子项），← 折叠（否则回父项）
            case KeyCode::Right:
                $this->keyRight();
                return true;
            case KeyCode::Left:
                $this->keyLeft();
                return true;
            default:
                return false;
        }
    }

    /**
     * →：目录未展开 → 展开；已展开 → 选中其第一个子项（VSCode 习惯）。
     * 文件上按 → 不做任何事（没有"下一级"可言）。
     */
    private function keyRight(): void
    {
        $visible = $this->tree->visible();
        if ($visible === []) {
            return;
        }
        $node = $visible[$this->selectedIndex($visible)] ?? null;
        if ($node === null || !$node->isDir) {
            return;
        }
        if (!$node->expanded) {
            $node->ensureChildren();
            $node->expanded = true;
            return;
        }
        $first = $node->children[0] ?? null;   // 空目录：原地不动
        if ($first !== null) {
            $this->shell->selectedPath = $first->path;
        }
    }

    /**
     * ←：目录已展开 → 折叠；否则（已折叠的目录 / 文件）→ 选中父节点（VSCode 习惯）。
     * 父节点不在当前可见列表里（例如祖先是折叠的）时不动，避免选中一个看不见的项。
     */
    private function keyLeft(): void
    {
        $visible = $this->tree->visible();
        if ($visible === []) {
            return;
        }
        $node = $visible[$this->selectedIndex($visible)] ?? null;
        if ($node === null) {
            return;
        }
        if ($node->isDir && $node->expanded) {
            $node->expanded = false;
            return;
        }
        $parent = dirname($node->path);
        if ($parent === '' || $parent === $node->path) {
            return;
        }
        foreach ($visible as $n) {
            if ($n->path === $parent) {
                $this->shell->selectedPath = $parent;
                return;
            }
        }
    }

    /** GIT tab 按键（M3/R5/R6）：↑/↓ 移动、Enter 提交/切换分支、Backspace 删字、Esc 关下拉 */
    private function gitKey(CodedKeyEvent $e): bool
    {
        $git = $this->shell->git;

        // 分支切换下拉：专属导航，吞掉其它键（不污染提交信息框）
        if ($git->branchDropdownOpen) {
            switch ($e->code) {
                case KeyCode::Up:
                    $git->moveBranchSelection(-1);
                    return true;
                case KeyCode::Down:
                    $git->moveBranchSelection(1);
                    return true;
                case KeyCode::Enter:
                    $b = $git->branches[$git->branchSelIdx] ?? null;
                    if ($b !== null) {
                        $git->switchBranch($b);
                    }
                    return true;
                case KeyCode::Esc:
                    $git->branchDropdownOpen = false;
                    return true;
                default:
                    return true;
            }
        }

        switch ($e->code) {
            case KeyCode::Up:
                $git->moveSelection(-1);
                return true;
            case KeyCode::Down:
                $git->moveSelection(1);
                return true;
            case KeyCode::Enter:
                $git->commit($git->commitMsg);   // 提交（空 message 会提示）
                return true;
            case KeyCode::Backspace:
                $git->commitMsg = mb_substr($git->commitMsg, 0, max(0, mb_strlen($git->commitMsg) - 1));
                return true;
            case KeyCode::Esc:
                if ($git->dropdownOpen) {
                    $git->dropdownOpen = false;
                    return true;
                }
                return false;
            default:
                return false;
        }
    }

    /**
     * Enter 的语义：目录展开/折叠，文件打开进编辑器。
     * 单独成方法是因为回车有两条路径（CodedKeyEvent::Enter 与 CharKeyEvent "\r"）。
     */
    public function activate(): void
    {
        // SEARCH tab：Enter 触发搜索 / 激活当前行
        if ($this->tabIndex === 2) {
            $this->shell->search->triggerOrActivate();
            return;
        }
        // 顺手修既有 bug：GIT tab 收到 \r 会误跑 Explorer 逻辑
        if ($this->tabIndex !== 0) {
            return;
        }

        $visible = $this->tree->visible();
        if (empty($visible)) {
            return;
        }
        $node = $visible[$this->selectedIndex($visible)];
        if ($node->isDir) {
            $this->toggle($node);
        } else {
            $this->shell->openFile($node->path);
        }
    }

    /** 滚轮：按当前 tab 上下移动对应列表的选中项（Explorer 树 / GIT 列表 / Search 结果） */
    public function onScroll(MouseEventKind $kind): bool
    {
        $delta = $kind === MouseEventKind::ScrollDown ? 1 : ($kind === MouseEventKind::ScrollUp ? -1 : 0);
        if ($delta === 0) {
            return false;
        }
        if ($this->tabIndex === 2) {
            $this->shell->search->moveSelection($delta);
        } elseif ($this->tabIndex === 1) {
            $this->shell->git->moveSelection($delta);
        } elseif ($this->tabIndex === 3) {
            $this->movePluginSelection($delta);
        } else {
            $this->moveSelection($delta);
        }
        return true;
    }

    /** 横向滚动（触控板两指横滑 / Shift+滚轮）：delta<0 左移、>0 右移 */
    public function onScrollH(int $delta): void
    {
        $this->hScroll = max(0, $this->hScroll + $delta);
    }

    /** 扩展(插件) tab 列表选择移动（钳到合法范围；无插件时不越界） */
    private function movePluginSelection(int $delta): void
    {
        $n = count($this->shell->plugins);
        if ($n === 0) {
            $this->pluginSel = 0;
            return;
        }
        $this->pluginSel = max(0, min($n - 1, $this->pluginSel + $delta));
    }

    // ── 内部 ────────────────────────────────────────

    private function toggle(TreeNode $node): void
    {
        $node->expanded = !$node->expanded;
        if ($node->expanded) {
            $node->ensureChildren();
        }
    }

    /**
     * @param TreeNode[] $visible
     * @return TreeNode[]
     */
    private function selectedIndex(array $visible): int
    {
        foreach ($visible as $i => $n) {
            if ($n->path === $this->shell->selectedPath) {
                return $i;
            }
        }
        return 0;
    }

    public function moveSelection(int $delta): void
    {
        $visible = $this->tree->visible();
        if (empty($visible)) {
            return;
        }
        $idx = $this->selectedIndex($visible) + $delta;
        $idx = max(0, min(count($visible) - 1, $idx));
        $this->shell->selectedPath = $visible[$idx]->path;
    }

    /**
     * 命中侧栏行首三角（▶/▼）？
     * 行结构（文本列）：marker「» 」2 列 | 缩进 2*depth 列 | 三角 1 列 + 其后空格 1 列 | 名称。
     * 实测（120x40，depth=0，hScroll=0）：`│» ▶ .docs/` → 三角在 x=3。
     *
     * ⚠️ 必须减 $this->hScroll：行是按 mbSubDisp(text, hScroll, innerW) 渲染的，
     * 屏幕列 = 文本列 - hScroll。漏减会让横滚后命中区整体右偏 hScroll 列
     * （点在三角上没反应，点在名称上却 toggle）。
     */
    private function hitArrow(Position $pos, Area $sb, int $depth): bool
    {
        $innerLeft = $sb->position->x + 1;              // 左边框占 1 列
        $innerRight = $sb->position->x + $sb->width - 2; // 右边框占 1 列
        $arrowX = $innerLeft + 2 + $depth * 2 - $this->hScroll;
        // 三角被滚出可视区（左溢出，或深到还没滚进来）→ 点不到，也别命中
        if ($arrowX < $innerLeft || $arrowX > $innerRight) {
            return false;
        }
        return $pos->x >= $arrowX && $pos->x <= min($arrowX + 1, $innerRight);
    }

    /**
     * 判定这次点击是否构成双击（同一条目 + 同一屏幕行 + 阈值内），并更新记时。
     * 判定成立即清空记录：否则第三击会再被判成一次双击，把目录 toggle 回原状。
     */
    private function consumeDoubleClick(string $path, int $row): bool
    {
        $now = microtime(true) * 1000.0;
        $isDouble = $this->lastClickPath === $path
            && $this->lastClickRow === $row
            && $this->lastClickAtMs !== null
            && ($now - $this->lastClickAtMs) <= self::DOUBLE_CLICK_MS;

        if ($isDouble) {
            $this->lastClickAtMs = null;
            $this->lastClickPath = null;
            $this->lastClickRow = null;
            return true;
        }

        $this->lastClickAtMs = $now;
        $this->lastClickPath = $path;
        $this->lastClickRow = $row;
        return false;
    }

    /** 清空双击记时（点了三角这类独立动作后调用，避免与后续点击误合成双击） */
    private function resetDoubleClick(): void
    {
        $this->lastClickAtMs = null;
        $this->lastClickPath = null;
        $this->lastClickRow = null;
    }
}

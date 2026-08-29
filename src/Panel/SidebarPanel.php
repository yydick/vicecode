<?php
declare(strict_types=1);

namespace App\Panel;

use App\App;
use App\Explorer\FileTree;
use App\Explorer\TreeNode;
use App\Git\GitClient;
use App\Git\GitModel;
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
    /** 0=Explorer 1=GIT 2=Search */
    public const TABS = ['explorer', 'git', 'search'];

    private const DOUBLE_CLICK_MS = 400;

    // GIT tab 内部分区行（inner 行号，0-based，相对 gitContent 起始）
    private const GIT_INPUT_ROW = 2;   // 提交信息输入框
    private const GIT_COMMIT_ROW = 3;  // Commit ▾ 按钮
    private const GIT_HEADER_ROW = 4;  // 变更/日志 标题行（带 +/- 或 ⟳ 图标）
    private const GIT_FIRST_ROW = 5;   // 首条变更/日志
    // gitContent 之前的固定两行：tab 行 + 分隔线（SidebarPanel::content 总是先渲染），
    // 故 gitContent 的实际屏幕行 = sidebar 顶 + 1(上边框) + 此偏移 + 行常量。
    private const GIT_CONTENT_OFFSET = 2;

    private FileTree $tree;

    /** 树可视区首行的索引（保证选中项可见） */
    public int $offset = 0;

    /** GIT 列表可视区首行索引（status / log 共用） */
    public int $gitOffset = 0;

    public int $tabIndex = 0;

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
        $seg = max(1, intdiv(max(1, $innerW), 3)); // 每段可用显示列宽
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
            $lines[] = Line::fromSpans(Span::styled(DisplayWidth::mbCutDisp($tabLine, $innerW), Style::default()->fg(AnsiColor::Yellow)));
            $lines[] = Line::fromSpans(Span::styled(str_repeat('─', $innerW), Style::default()->fg(AnsiColor::Gray)));
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
                    $text = DisplayWidth::mbCutDisp($marker . $indent . $prefix . $node->name . $suffix, $innerW);
                    $style = $node->path === $this->shell->selectedPath
                        ? Style::default()->addModifier(Modifier::REVERSED)
                        : Style::default();
                    $lines[] = Line::fromSpans(Span::styled($text, $style));
                }
            } elseif (empty($visible)) {
                $lines[] = Line::fromSpans(Span::styled('(empty)', Style::default()->fg(AnsiColor::DarkGray)));
            }
        } elseif ($this->tabIndex === 1) {
            $this->gitContent($sidebar, $lines);
        } else {
            $lines[] = Line::fromSpans(Span::styled($this->shell->t('search.placeholder'), Style::default()->fg(AnsiColor::DarkGray)));
            $lines[] = Line::fromSpans(Span::styled($this->shell->t('search.prompt'), Style::default()->fg(AnsiColor::DarkGray)));
        }

        if ($lines === []) {
            return ParagraphWidget::fromString('');
        }
        return ParagraphWidget::fromLines(...$lines);
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
                Style::default()->fg(AnsiColor::DarkGray)));
            return;
        }

        // 行0：分支 + 子视图标题
        $viewTitle = $git->subView === 0
            ? $this->shell->t('git.view_status', ['n' => count($git->status)])
            : $this->shell->t('git.view_log', ['n' => count($git->log)]);
        $lines[] = Line::fromSpans(Span::styled(
            DisplayWidth::mbCutDisp('⎇ ' . $git->branch . '  ' . $viewTitle, $innerW),
            Style::default()->fg(AnsiColor::Cyan)));
        $lines[] = Line::fromSpans(Span::styled(str_repeat('─', $innerW), Style::default()->fg(AnsiColor::Gray)));

        // 行2：提交信息输入框（默认聚焦，键入即进 commitMsg；空时显占位）
        $msg = $git->commitMsg;
        $inputText = $msg === '' ? $this->shell->t('git.msg_placeholder') : $msg;
        $inputStyle = $msg === '' ? Style::default()->fg(AnsiColor::DarkGray) : Style::default();
        $lines[] = Line::fromSpans(Span::styled(
            DisplayWidth::mbCutDisp('> ' . $inputText . '▏', $innerW), $inputStyle));

        // 行3：Commit ▾ 按钮（REVERSED 表示可点击；▾ 展开下拉）
        $arrow = $git->dropdownOpen ? '▴' : '▾';
        $btn = DisplayWidth::mbPadDisp(' Commit ' . $arrow, $innerW);
        $lines[] = Line::fromSpans(Span::styled($btn, Style::default()->addModifier(Modifier::REVERSED)));

        // 下拉菜单：覆盖从标题行起，点击项触发对应动作
        if ($git->dropdownOpen) {
            $i = 0;
            foreach (GitModel::DROPDOWN as $label) {
                $text = ($i === 0 ? '● ' : '  ') . $label;
                $lines[] = Line::fromSpans(Span::styled(
                    DisplayWidth::mbCutDisp($text, $innerW),
                    $i === 0 ? Style::default()->fg(AnsiColor::Green) : Style::default()));
                $i++;
            }
            return;
        }

        // 行4：变更/日志 标题（带 +/- 或 ⟳ 图标）
        if ($git->subView === 0) {
            $title = $this->shell->t('git.changes', ['n' => count($git->status)]);
            $head = DisplayWidth::mbCutDisp($title, max(0, $innerW - 5))
                . '  ' . str_repeat(' ', max(0, $innerW - mb_strwidth($title) - 5)) . '+ -';
            $lines[] = Line::fromSpans(Span::styled($head, Style::default()->fg(AnsiColor::Yellow)));
        } else {
            $title = $this->shell->t('git.log_title', ['n' => count($git->log)]);
            $head = DisplayWidth::mbCutDisp($title, max(0, $innerW - 4))
                . '  ' . str_repeat(' ', max(0, $innerW - mb_strwidth($title) - 4)) . '⟳';
            $lines[] = Line::fromSpans(Span::styled($head, Style::default()->fg(AnsiColor::Yellow)));
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
                    Style::default()->fg(AnsiColor::Green)));
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
                $lines[] = Line::fromSpans(Span::styled(DisplayWidth::mbCutDisp($line, $innerW), $style));
            }
        } else {
            $items = $git->log;
            if ($items === []) {
                $lines[] = Line::fromSpans(Span::styled(
                    DisplayWidth::mbCutDisp($this->shell->t('git.no_log'), $innerW),
                    Style::default()->fg(AnsiColor::DarkGray)));
                return;
            }
            $this->clampGitOffset(count($items), $itemRows);
            for ($i = $this->gitOffset; $i < min($this->gitOffset + $itemRows, count($items)); $i++) {
                $c = $items[$i];
                $sel = $i === $git->selIdx;
                $text = $c->hash . ' ' . $c->subject;
                $style = $sel
                    ? Style::default()->addModifier(Modifier::REVERSED)
                    : Style::default()->fg(AnsiColor::Gray);
                $lines[] = Line::fromSpans(Span::styled(DisplayWidth::mbCutDisp($text, $innerW), $style));
            }
        }
    }

    /** GIT 状态类别 → 颜色（与 VSCode gutter 着色近似） */
    private function gitColor(string $cat): AnsiColor
    {
        return match ($cat) {
            GitClient::STATUS_STAGED => AnsiColor::Green,
            GitClient::STATUS_MODIFIED => AnsiColor::Yellow,
            GitClient::STATUS_UNTRACKED => AnsiColor::Red,
            GitClient::STATUS_RENAMED => AnsiColor::Magenta,
            GitClient::STATUS_DELETED => AnsiColor::Red,
            GitClient::STATUS_CONFLICT => AnsiColor::LightRed,
            GitClient::STATUS_IGNORED => AnsiColor::DarkGray,
            default => AnsiColor::Gray,
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
            'itemOpenX' => $innerX + 0,                      // 列表行 ▦ 在 inner 列 0（打开文件）
            'itemPlusX' => $innerX + 2,                      // 列表行 + 在 inner 列 2（暂存）
            'itemMinusX' => $innerX + 4,                     // 列表行 - 在 inner 列 4（取消暂存）
            'itemDiscardX' => $innerX + 6,                   // 列表行 ✕ 在 inner 列 6（丢弃工作区改动）
            'itemNameX' => $innerX + 8,                     // 列表行 文件名起始列（点此=开 diff）
            'menuY0' => $y(self::GIT_HEADER_ROW),           // 下拉覆盖从标题行起
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
                $rc = $col - $r['innerX'];
                if ($rc <= 1) {
                    $git->openFileSelected();          // ▦ 在列 0~1
                } elseif ($rc >= 2 && $rc <= 3) {
                    $git->stageSelected();             // + 在列 2~3
                } elseif ($rc >= 4 && $rc <= 5) {
                    $git->unstageSelected();           // - 在列 4~5
                } elseif ($rc >= 6 && $rc <= 7) {
                    $git->requestDiscardSelected();    // ✕ 在列 6~7（弹确认）
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
            $seg = max(1, intdiv(max(1, $sb->width - 2), 3));
            $this->tabIndex = min(2, intdiv($inner, $seg));
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
        if ($node->isDir && self::hitArrow($pos, $sb, $node->depth)) {
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
            default:
                return false;
        }
    }

    /** GIT tab 按键（M3/R5）：↑/↓ 移动、Enter 提交、Backspace 删字、Esc 关下拉 */
    private function gitKey(CodedKeyEvent $e): bool
    {
        $git = $this->shell->git;
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

    /** 滚轮：上下移动选中项 */
    public function onScroll(MouseEventKind $kind): bool
    {
        if ($kind === MouseEventKind::ScrollDown) {
            $this->moveSelection(1);
            return true;
        }
        if ($kind === MouseEventKind::ScrollUp) {
            $this->moveSelection(-1);
            return true;
        }
        return false;
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
     * 行结构（屏幕列）：边框 | marker「» 」2 列 | 缩进 2*depth 列 | 三角 1 列 + 其后空格 1 列 | 名称。
     * 实测（120x40，depth=0）：`│» ▶ .docs/` → 三角在 x=3。
     */
    private static function hitArrow(Position $pos, Area $sb, int $depth): bool
    {
        $arrowX = $sb->position->x + 1 + 2 + $depth * 2;  // inner 左界（margin 1）+ marker 2 列 + 缩进
        return $pos->x >= $arrowX && $pos->x <= $arrowX + 1;
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

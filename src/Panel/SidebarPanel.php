<?php
declare(strict_types=1);

namespace App\Panel;

use App\App;
use App\Explorer\FileTree;
use App\Explorer\TreeNode;
use App\Git\GitClient;
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
     * GIT tab 内容（M3）：status 子视图（文件 + 状态着色）/ log 子视图（提交列表）。
     * 窄侧栏放不下两者，用 [L] 切换子视图；选中行 REVERSED。
     * 列表可视区按 selIdx 自动滚动（与 Explorer 同约定，content() 非纯函数）。
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

        // 头部：分支 + 子视图标题
        $viewTitle = $git->subView === 0
            ? $this->shell->t('git.view_status', ['n' => count($git->status)])
            : $this->shell->t('git.view_log', ['n' => count($git->log)]);
        $header = '⎇ ' . $git->branch . '  ' . $viewTitle;
        $lines[] = Line::fromSpans(Span::styled(
            DisplayWidth::mbCutDisp($header, $innerW),
            Style::default()->fg(AnsiColor::Cyan)));
        $lines[] = Line::fromSpans(Span::styled(str_repeat('─', $innerW), Style::default()->fg(AnsiColor::Gray)));

        $rowsH = max(0, $sidebar->height - 4);
        if ($rowsH <= 0) {
            return;
        }

        if ($git->loading && $git->subView === 0 && $git->status === []) {
            $lines[] = Line::fromSpans(Span::styled(
                DisplayWidth::mbCutDisp($this->shell->t('git.loading'), $innerW),
                Style::default()->fg(AnsiColor::DarkGray)));
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
            $this->clampGitOffset(count($items), $rowsH);
            for ($i = $this->gitOffset; $i < min($this->gitOffset + $rowsH, count($items)); $i++) {
                $f = $items[$i];
                $sel = $i === $git->selIdx;
                $text = ' ' . $f->badge() . ' ' . $f->displayPath();
                $style = $sel
                    ? Style::default()->addModifier(Modifier::REVERSED)
                    : Style::default()->fg($this->gitColor($f->category()));
                $lines[] = Line::fromSpans(Span::styled(DisplayWidth::mbCutDisp($text, $innerW), $style));
            }
        } else {
            $items = $git->log;
            if ($items === []) {
                $lines[] = Line::fromSpans(Span::styled(
                    DisplayWidth::mbCutDisp($this->shell->t('git.no_log'), $innerW),
                    Style::default()->fg(AnsiColor::DarkGray)));
                return;
            }
            $this->clampGitOffset(count($items), $rowsH);
            for ($i = $this->gitOffset; $i < min($this->gitOffset + $rowsH, count($items)); $i++) {
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

    /** GIT tab 按键（M3）：↑/↓ 移动、Enter 看 diff、L 切 status/log、R 刷新 */
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
                $git->openDiff();
                return true;
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

<?php
declare(strict_types=1);

namespace App\Panel;

use App\App;
use App\Terminal\TerminalInstance;
use App\Text\DisplayWidth;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Style\Modifier;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Widget\Widget;

/**
 * 终端面板（容器）：持有多个 TerminalInstance（每个标签一个真正独立的 shell），
 * 本身只负责：渲染顶部的「终端实例标签条」+ 底部的「面板切换条」（Terminal/Problems/…），
 * 并把所有 pty 控制方法转发给当前活动实例。
 *
 * 默认只持有 1 个实例，行为与重构前完全一致（App 调用点几乎不动：本类转发到 active 实例）。
 *
 * 面板契约：content(Area, bool $focused): Widget（外层 Block 由 App 加）；
 * 事件分发给当前聚焦面板（App 把事件路由到 TerminalPanel，再转发 active 实例）。
 */
final class TerminalPanel
{
    /** 终端实例数组（每个标签一个独立 shell）；下标即标签序号 */
    private array $instances = [];

    /** 当前活动实例下标 */
    private int $active = 0;

    /** 标签命中矩形（渲染时重建）：[x0, x1, index, action]（绝对列） */
    private array $tabRects = [];

    public function __construct(public App $shell)
    {
        // 默认 1 个实例，兼容现有 new App() + 单终端行为
        $this->instances[0] = new TerminalInstance($this);
    }

    /**
     * 未搬到实例的公开属性（mode/captured/input/pos/scroll/hScroll/follow/hist/histIdx/scrollback）
     * 转发到活动实例，使 App 直接点访问 `$this->terminal->mode` 等仍可用。
     */
    public function __get(string $name): mixed
    {
        return $this->instances[$this->active]->$name;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->instances[$this->active]->$name = $value;
    }

    public function __isset(string $name): bool
    {
        return isset($this->instances[$this->active]->$name);
    }

    // ── 几何服务（实例调用）──────────────────────────

    /**
     * 计算面板内「输出视口」几何：返回 [$W, $outH, $gutter, $cw]。
     * $W=内宽，$outH=去掉提示行后的输出行数，$gutter=是否画右缘滚动条，$cw=实际仿真列数。
     */
    public function ptyViewportGeometry(Area $terminal): array
    {
        $inner = $terminal->inner(new \PhpTui\Tui\Widget\Margin(1, 1));
        $W = max(0, $inner->width);
        $H = max(0, $inner->height);
        $outH = max(0, $H - 1);
        $gutter = $W >= 3;
        $cw = $gutter ? $W - 1 : $W;
        return [$W, $outH, $gutter, $cw];
    }

    // ── 容器操作 ────────────────────────────────────

    /** 当前活动实例下标（0 起） */
    public function activeIndex(): int
    {
        return $this->active;
    }

    public function newInstance(): void
    {
        $this->instances[] = new TerminalInstance($this);
        $this->active = count($this->instances) - 1;
    }

    public function selectInstance(int $i): void
    {
        if ($i >= 0 && $i < count($this->instances)) {
            $this->active = $i;
        }
    }

    public function closeInstance(int $i): void
    {
        if (count($this->instances) <= 1) {
            return; // 至少留 1 个
        }
        $inst = $this->instances[$i] ?? null;
        if ($inst !== null) {
            $inst->disposeWithoutSave();
        }
        unset($this->instances[$i]);
        $this->instances = array_values($this->instances);
        if ($this->active >= count($this->instances)) {
            $this->active = count($this->instances) - 1;
        }
    }

    public function closeActive(): void
    {
        $this->closeInstance($this->active);
    }

    public function next(): void
    {
        $this->active = ($this->active + 1) % count($this->instances);
    }

    public function prev(): void
    {
        $n = count($this->instances);
        $this->active = ($this->active - 1 + $n) % $n;
    }

    /** 标签标题（如 ['1: bash', '2: bash']），测试断言用 */
    public function tabTitles(): array
    {
        $out = [];
        foreach (array_keys($this->instances) as $i) {
            $out[] = ($i + 1) . ': bash';
        }
        return $out;
    }

    /** 鼠标命中终端实例标签条：返回 ['action'=>'switch'|'close'|'new', 'index'=>?] 或 null */
    public function hitInstanceTab(Area $terminal, int $col, int $row): ?array
    {
        // $terminal 是 Block **外框**区域（App::handleMouse 传入的是 $a['terminal']），
        // 实例标签条渲染在外框内区第一行 = y+1（BlockRenderer 把子部件放在 inner）。
        if ($row !== $terminal->position->y + 1) {
            return null; // 仅顶行
        }
        if ($this->shell->bottomView !== 'terminal') {
            return null;
        }
        foreach ($this->tabRects as $r) {
            if ($col >= $r['x0'] && $col <= $r['x1']) {
                if ($r['action'] === 'new') {
                    return ['action' => 'new'];
                }
                return ['action' => $r['action'], 'index' => $r['index']];
            }
        }
        return null;
    }

    // ── 渲染 ──────────────────────────────────────────

    /** 面板内容：顶部实例标签条（仅 terminal view）+ 中间内容 + 底部面板切换条 */
    public function content(Area $terminal, bool $focused): Widget
    {
        // ⚠️ $terminal 是 Block **内区**（BlockRenderer::render 会把外框 inner 后再传进来）。
        // 而.getTextRect 等直接从 App 拿到的是**外框** —— 两条链的几何必须同构：
        // 切段统一走 splitInner()，midAreaOf() 负责「外框 → 内区」模拟 Block 的那一层。
        $isTerm = $this->shell->bottomView === 'terminal';
        $h = $terminal->height;
        // 每帧先清命中矩形：非 terminal 视图不画实例标签条（topH=0），若不清，
        // 上一帧 terminal 的矩形残留 → 点占位面板顶行会误切终端标签。
        $this->tabRects = [];

        // 退化：太小则只画活动实例 / 占位，不画标签条
        if ($h < 2) {
            return $isTerm
                ? $this->instances[$this->active]->content($terminal, $focused)
                : PlaceholderPanel::render($terminal, $this->shell->t('panel.' . $this->shell->bottomView));
        }

        [$midArea, $botArea, $topH] = $this->splitInner($terminal);

        $widgets = [];
        $constraints = [];
        if ($topH > 0) {
            $topArea = Area::fromScalars($terminal->position->x, $terminal->position->y, $terminal->width, 1);
            $topLine = $this->instanceTabLine($topArea, $focused);
            $widgets[] = ParagraphWidget::fromLines($topLine);
            $constraints[] = Constraint::length(1);
        }

        $midWidget = $isTerm
            ? $this->instances[$this->active]->content($midArea, $focused)
            : PlaceholderPanel::render($midArea, $this->shell->t('panel.' . $this->shell->bottomView));
        $widgets[] = $midWidget;
        $constraints[] = Constraint::min(0);

        $widgets[] = BottomTabs::render($botArea, $this->shell->bottomView, $this->shell->theme);
        $constraints[] = Constraint::length(1);

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(...$constraints)
            ->widgets(...$widgets);
    }

    /** 画终端实例标签条（仿 EditorPanel::tabLine 风格）：选中 REVERSED、未选 editorTab */
    private function instanceTabLine(Area $line, bool $focused): Line
    {
        $x = $line->position->x + 1; // 左缩进 1 列，仿编辑器 tab
        $this->tabRects = [];
        $spans = [];
        foreach (array_keys($this->instances) as $i) {
            $st = $i === $this->active
                ? Style::default()->addModifier(Modifier::REVERSED)
                : $this->shell->theme->style('editorTab');
            $nameLabel = ' ' . ($i + 1) . ': bash ';
            $wName = DisplayWidth::dispWidth($nameLabel);
            $spans[] = Span::styled($nameLabel, $st);
            $this->tabRects[] = ['x0' => $x, 'x1' => $x + $wName - 1, 'index' => $i, 'action' => 'switch'];
            $x += $wName;

            $closeLabel = '✕ ';
            $wClose = DisplayWidth::dispWidth($closeLabel);
            $spans[] = Span::styled($closeLabel, $st);
            $this->tabRects[] = ['x0' => $x, 'x1' => $x + $wClose - 1, 'index' => $i, 'action' => 'close'];
            $x += $wClose;
        }
        $newLabel = '⊕ ';
        $wNew = DisplayWidth::dispWidth($newLabel);
        $spans[] = Span::styled($newLabel, $this->shell->theme->style('editorTab'));
        $this->tabRects[] = ['x0' => $x, 'x1' => $x + $wNew - 1, 'index' => -1, 'action' => 'new'];

        return Line::fromSpans(...$spans);
    }

    // ── 转发到活动实例（保持 App 调用点几乎不变）────────

    public function buffer(): \App\Terminal\TerminalBuffer
    {
        return $this->instances[$this->active]->buffer();
    }

    public function cwd(): string
    {
        return $this->instances[$this->active]->cwd();
    }

    public function isRunning(): bool
    {
        return $this->instances[$this->active]->isRunning();
    }

    public function poll(): bool
    {
        return $this->instances[$this->active]->poll();
    }

    public function ptyAlive(): bool
    {
        return $this->instances[$this->active]->ptyAlive();
    }

    public function resizeToViewport(int $w, int $h): void
    {
        $this->instances[$this->active]->resizeToViewport($w, $h);
    }

    public function resizeToPanel(Area $terminal): void
    {
        $this->instances[$this->active]->resizeToPanel($terminal);
    }

    public function takeoverPump(): void
    {
        $this->instances[$this->active]->takeoverPump();
    }

    public function toggleInteractive(): void
    {
        $this->instances[$this->active]->toggleInteractive();
    }

    public function sendToPty(string $bytes): void
    {
        $this->instances[$this->active]->sendToPty($bytes);
    }

    public function exitCapture(): void
    {
        $this->instances[$this->active]->exitCapture();
    }

    public function enterCapture(): void
    {
        $this->instances[$this->active]->enterCapture();
    }

    public function ensurePtyStarted(Area $terminal): void
    {
        $this->instances[$this->active]->ensurePtyStarted($terminal);
    }

    public function isCaptured(): bool
    {
        return $this->instances[$this->active]->isCaptured();
    }

    public function syncShellState(): void
    {
        $this->instances[$this->active]->syncShellState();
    }

    public function scrollPty(int $delta): void
    {
        $this->instances[$this->active]->scrollPty($delta);
    }

    public function scrollbackSize(): int
    {
        return $this->instances[$this->active]->scrollbackSize();
    }

    public function wheel(int $delta): void
    {
        $this->instances[$this->active]->wheel($delta);
    }

    public function setSelection(?array $r): void
    {
        $this->instances[$this->active]->setSelection($r);
    }

    public function getTextRect(Area $terminal, int $r0, int $c0, int $r1, int $c1): string
    {
        // $terminal 是 Block **外框**。渲染链里实例内容画在
        // 「外框 − 边框 − 实例标签条(topH)」的 midArea 再 inner(Margin(1,1)) 的网格上，
        // 取字必须走**同一条几何**，否则行列错位（鼠标选区抓字偏移）。
        // 故这里推导 midArea 传给实例（实例内部照旧 inner(Margin(1,1))）。
        return $this->instances[$this->active]->getTextRect($this->midAreaOf($terminal), $r0, $c0, $r1, $c1);
    }

    /**
     * 切段公式（唯一权威）：内区 → [midArea, botArea, topH]。
     * topH = 实例标签条（仅 terminal 视图），botH = 面板切换条。content() 渲染与
     * midAreaOf() 取字共用本方法 —— 渲染与命中/取字绝不允许各算一套几何（见 BUGFIXES E4/E5）。
     * @return array{0:Area,1:Area,2:int}
     */
    private function splitInner(Area $inner): array
    {
        $topH = $this->shell->bottomView === 'terminal' ? 1 : 0;
        $botH = 1;
        $midH = max(0, $inner->height - $topH - $botH);
        $pos = $inner->position;
        $mid = Area::fromScalars($pos->x, $pos->y + $topH, $inner->width, $midH);
        $bot = Area::fromScalars($pos->x, $pos->y + $topH + $midH, $inner->width, $botH);
        return [$mid, $bot, $topH];
    }

    /**
     * 由 Block **外框**推导实例内容区（midArea）——供 getTextRect 等从 App 拿到外框的
     * 调用方使用：先模拟 BlockRenderer 的 inner 层，再走与 content() 相同的 splitInner()。
     */
    private function midAreaOf(Area $outer): Area
    {
        $inner = $outer->inner(new \PhpTui\Tui\Widget\Margin(1, 1));
        [$mid] = $this->splitInner($inner);
        return $mid;
    }

    public function onChar(CharKeyEvent $e): bool
    {
        return $this->instances[$this->active]->onChar($e);
    }

    public function onKey(CodedKeyEvent $e, array $areas): bool
    {
        return $this->instances[$this->active]->onKey($e, $areas);
    }

    public function submit(): void
    {
        $this->instances[$this->active]->submit();
    }

    public function cancel(): void
    {
        $this->instances[$this->active]->cancel();
    }

    public function scrollBy(int $delta): void
    {
        $this->instances[$this->active]->scrollBy($delta);
    }

    public function onScrollH(int $delta): void
    {
        $this->instances[$this->active]->onScrollH($delta);
    }

    public function clear(): void
    {
        $this->instances[$this->active]->clear();
    }

    public function historyPrev(): void
    {
        $this->instances[$this->active]->historyPrev();
    }

    public function historyNext(): void
    {
        $this->instances[$this->active]->historyNext();
    }

    public function moveCursor(int $delta): void
    {
        $this->instances[$this->active]->moveCursor($delta);
    }

    public function moveCursorHome(): void
    {
        $this->instances[$this->active]->moveCursorHome();
    }

    public function moveCursorEnd(): void
    {
        $this->instances[$this->active]->moveCursorEnd();
    }

    public function deleteBackward(): void
    {
        $this->instances[$this->active]->deleteBackward();
    }

    public function deleteForward(): void
    {
        $this->instances[$this->active]->deleteForward();
    }

    public function clearInput(): void
    {
        $this->instances[$this->active]->clearInput();
    }

    public function pasteText(string $text): void
    {
        $this->instances[$this->active]->pasteText($text);
    }

    public function shutdown(): void
    {
        foreach ($this->instances as $i => $inst) {
            if ($i === $this->active) {
                $inst->shutdown(); // 含存盘（v1 仅活动实例落盘）
            } else {
                $inst->disposeWithoutSave();
            }
        }
    }

    public function saveSession(): void
    {
        $this->instances[$this->active]->saveSession();
    }

    public function maybeRestore(): void
    {
        if (isset($this->instances[0])) {
            $this->instances[0]->maybeRestore();
        }
    }
}

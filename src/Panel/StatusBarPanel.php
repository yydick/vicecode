<?php
declare(strict_types=1);

namespace App\Panel;

use App\App;
use App\Text\DisplayWidth;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Widget\Widget;

/**
 * 底部状态栏：焦点 / 当前 tab / 文件与 dirty 标记 / 编辑模式 / 分支 /
 * Provider+模型 / 语言 / 瞬时消息 / 退出热键提示。
 *
 * 未保存确认进行中时整条改为确认提示（由 Lifecycle 驱动）。
 *
 * ## 为什么自己算宽度、按优先级裁剪
 * 状态栏只有 1 行，信息却有 9 项。交给 php-tui 的话超宽会**从尾部硬切**——
 * 实测 80 列下文件名、瞬时消息、退出提示全被切掉，只留下开头几项最没用的。
 * 用户看到的症状是「我刚才那条提示怎么没了」，而实际上是被截了。
 *
 * 故这里的策略：
 *  1. 每项带优先级；
 *  2. 放不下时**优先丢弃低优先级项**，保住高优先级项；
 *  3. 最后兜底硬截断（带省略号），保证绝不超过可视宽度。
 *
 * 优先级（高 → 低）：
 *  message(100) > 文件+dirty(90) > 编辑模式(85) > Provider/模型(80) > 分支(70)
 *  > 退出提示(65) > app(50) > 语言(40) > 焦点(35) > 标签(30)
 *  文件与消息排最前，因为只有状态栏这一处显示；焦点与标签排最后，因为界面上
 *  已有视觉表达（边框高亮 / 侧栏 tab 高亮），窄屏时先牺牲它们。
 */
final class StatusBarPanel
{
    /** 段之间的分隔符 */
    private const SEP = ' · ';

    /** 兜底截断用的省略号（1 列） */
    private const ELLIPSIS = '…';

    public function __construct(private App $shell)
    {
    }

    public function content(int $width = 0): Widget
    {
        return ParagraphWidget::fromString($this->text($width));
    }

    /**
     * 组装状态栏文本，保证显示宽度 <= $width。
     *
     * @return array{text:string,dropped:string[]} dropped 供测试断言「丢了哪些」
     */
    public function assemble(int $width): array
    {
        // 确认态独占整条：用户在做"要不要丢改动"这种决定，必须完整可读
        if ($this->shell->confirm !== null) {
            return ['text' => $this->hardTruncate($this->confirmText(), max(0, $width)), 'dropped' => []];
        }

        $segs = $this->segments();
        // 丢弃顺序按优先级降序（p 越大越该留下）
        usort($segs, static fn(array $a, array $b): int => $b['p'] <=> $a['p']);

        $kept = [];
        $dropped = [];
        foreach ($segs as $seg) {
            $candidate = array_merge($kept, [$seg]);
            if ($this->joinWidth($candidate) <= $width) {
                $kept[] = $seg;
            } else {
                $dropped[] = $seg['k'];
            }
        }

        // 显示顺序与优先级解耦：否则"消息优先级最高"会让它跑到最左边，很难读。
        // 丢弃看 p，摆放看 o。
        usort($kept, static fn(array $a, array $b): int => $a['o'] <=> $b['o']);

        $text = $this->join($kept);
        if (DisplayWidth::dispWidth($text) > $width) {
            $text = $this->hardTruncate($text, $width);
        }
        return ['text' => $text, 'dropped' => $dropped];
    }

    public function text(int $width = 0): string
    {
        return $this->assemble($width)['text'];
    }

    /**
     * 各信息段。k 是稳定标识（供测试断言"丢了哪一项"），p 是优先级。
     *
     * @return array<int,array{k:string,p:int,t:string}>
     */
    private function segments(): array
    {
        $t = fn(string $k, array $params = []): string => $this->shell->t($k, $params);

        $buf = $this->shell->buffer;
        $file = $buf !== null ? basename((string) $buf->path) : '—';
        $dirty = $buf !== null && $buf->dirty ? ' ' . $t('status.dirty') : '';
        // 编辑模式（R1 四项之一）：只读必须显式标出来，
        // 否则用户改半天发现保存不了，会以为是 bug。
        $mode = $buf === null ? '—' : ($buf->readOnly ? $t('status.readonly') : $t('status.mode_edit'));

        // M5：Provider/模型。生成中带省略号（AI 面板标题只有一个点，容易忽略）
        $spec = $this->shell->chat->spec();
        $ai = $spec === null
            ? '—'
            : $spec->label . '/' . $spec->model . ($this->shell->chat->isStreaming() ? ' …' : '');

        // R5 拖拽分隔条时：把当前各面板尺寸显示在状态栏（高优先级，确保可见）。
        // 非拖拽时 t 为空，join() 会跳过，不占空间。
        $layout = $this->shell->isDragging() ? $this->shell->layoutSummary() : '';

        return [
            // k=标识, p=丢弃优先级(大者留), o=显示顺序(小者靠左)
            //
            // 焦点/标签排得低是刻意的：它们**在界面上已经有视觉表达**（聚焦面板边框高亮、
            // 侧栏当前 tab 高亮），状态栏里再写一遍是纯冗余，占掉的 28 列不如让给
            // 「文件 / 消息 / 退出提示」这些没有第二处显示的信息。
            ['k' => 'message', 'p' => 100, 'o' => 8, 't' => $this->shell->message],
            ['k' => 'file',    'p' => 90,  'o' => 1, 't' => $t('status.file') . '=' . $file . $dirty],
            ['k' => 'mode',    'p' => 85,  'o' => 2, 't' => $t('status.mode') . '=' . $mode],
            ['k' => 'ai',      'p' => 80,  'o' => 4, 't' => $t('status.provider') . '=' . $ai],
            ['k' => 'branch',  'p' => 70,  'o' => 3, 't' => $t('status.branch') . '=' . $this->shell->git->branch],
            ['k' => 'quit',    'p' => 65,  'o' => 9, 't' => $t('status.quit')],
            ['k' => 'app',     'p' => 50,  'o' => 0, 't' => $t('app.title')],
            ['k' => 'locale',  'p' => 40,  'o' => 7, 't' => $t('status.locale') . '=' . $this->shell->locale()],
            ['k' => 'focus',   'p' => 35,  'o' => 5, 't' => $t('status.focus') . '=' . strtoupper($this->shell->focusPanel())],
            // ⚠️ tab 不能排太低：侧栏 tab **只显示图标不显示文字**，状态栏这行是它
            // 唯一的文字标识，丢了用户就分不清当前在哪个 tab。
            ['k' => 'tab',     'p' => 75,  'o' => 6, 't' => $t('status.tab') . '=' . $this->shell->sidebar->tabLabel()],
            // 拖拽尺寸段：排最右、优先级最高，拖拽时必定显示，松手即消失。
            ['k' => 'layout',  'p' => 95,  'o' => 11, 't' => $layout],
        ];
    }

    private function confirmText(): string
    {
        $kind = $this->shell->confirm['kind'] ?? 'quit';
        if ($kind === 'discard') {
            return ' ' . $this->shell->t('confirm.discard', ['path' => $this->shell->confirm['path'] ?? '']);
        }
        return ' ' . ($kind === 'close'
            ? $this->shell->t('confirm.close_dirty')
            : $this->shell->t('confirm.quit_dirty'));
    }

    /** @param array<int,array{k:string,p:int,t:string}> $segs */
    private function join(array $segs): string
    {
        $parts = [];
        foreach ($segs as $s) {
            if ($s['t'] !== '') {
                $parts[] = $s['t'];
            }
        }
        return $parts === [] ? '' : ' ' . implode(self::SEP, $parts);
    }

    /** @param array<int,array{k:string,p:int,t:string}> $segs */
    private function joinWidth(array $segs): int
    {
        return DisplayWidth::dispWidth($this->join($segs));
    }

    private function hardTruncate(string $text, int $width): string
    {
        if ($width <= 0) {
            return '';
        }
        if (DisplayWidth::dispWidth($text) <= $width) {
            return $text;
        }
        // 留 1 列给省略号；宽度为 1 时只放省略号
        $keep = $width - 1;
        if ($keep <= 0) {
            return DisplayWidth::mbCutDisp(self::ELLIPSIS, $width);
        }
        return DisplayWidth::mbCutDisp($text, $keep) . self::ELLIPSIS;
    }
}

<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Tab 补全的**状态 + 候选来源注册表**（核心独占按键，provider 只提供数据）。
 *
 * 设计要点（为什么是"provider 只给数据"）：
 *  - Tab/Shift+Tab 的语义由核心统一实现：**有候选 → 接受补全；无候选 → 缩进**。
 *    插件（未来接 AI 自动补全）只声明"在这个上下文、这个前缀下我有哪些候选"，
 *    不碰按键 —— 否则插件可以把 Tab 玩坏，而 Tab 是全局键。
 *  - 与既有插件模式一致（声明纯数据 + 核心渲染），新增能力不会污染按键分发。
 *
 * 上下文标识（由 App 按焦点算出来，provider 据此决定要不要给候选）：
 *  - `ai_input`：AI 输入框（本项目唯一有 `@文件` 补全的地方）
 *  - `editor`  ：编辑器
 *  - `search`  ：侧栏 SEARCH tab 的查询框（单行）
 *  - `commit`  ：侧栏 GIT tab 的提交信息框（单行）
 */
final class CompletionState
{
    /** 单次最多展示多少条候选（再多也翻不过来，且 provider 可能来自慢查询） */
    public const MAX_ITEMS = 20;

    /**
     * Tab 缩进的宽度（**口径：统一 4 空格，刻意不做配置项**）。
     *
     * 放在这里是为了让「编辑器」与「AI 输入框」共用**同一个**数字 —— 两处各写一个 4
     * 迟早会漂移。不做 `tabSize` / `insertSpaces` 配置的理由：Tab 到底是 4 列还是 8 列
     * 没有共识，多一个配置项就多一处校验、一份文档、一组测试，而收益极小。
     */
    public const INDENT_SPACES = 4;

    private bool $active = false;

    /** @var list<CompletionItem> */
    private array $items = [];

    private int $sel = 0;

    /** 被补全的那段前缀在**字符**下标上的起点（含 `@` 等触发符） */
    private int $prefixStart = 0;

    private string $context = '';

    /** @var list<callable(string,string,int,string):list<CompletionItem>> */
    private array $providers = [];

    /**
     * 上一次重算用的「输入指纹」（context + 前缀 + 光标位）。
     *
     * ⚠️ 为什么需要它：`App` 在**每次按键之后**都会调 `refresh()` 重算候选，而 Tab/Shift+Tab
     * 本身也是按键 —— 重算会把 `sel` 重置回 0，于是 Shift+Tab 刚选到第 3 条就被打回第 1 条。
     * 输入没变（指纹相同）时直接返回、**保留选中项**。
     */
    private string $key = '';

    /**
     * 注册一个候选来源。签名固定为
     * `fn(string $context, string $text, int $cursor, string $prefix): list<CompletionItem>`。
     */
    public function addProvider(callable $provider): void
    {
        $this->providers[] = $provider;
    }

    /** 清空 provider（插件热重载/启用切换时与其它插件注册表一起重建） */
    public function resetProviders(): void
    {
        $this->providers = [];
    }

    public function providerCount(): int
    {
        return count($this->providers);
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /** @return list<CompletionItem> */
    public function items(): array
    {
        return $this->items;
    }

    public function selected(): int
    {
        return $this->sel;
    }

    public function current(): ?CompletionItem
    {
        return $this->items[$this->sel] ?? null;
    }

    public function context(): string
    {
        return $this->context;
    }

    public function prefixStart(): int
    {
        return $this->prefixStart;
    }

    /** 关掉候选（前缀不再匹配、焦点离开、模态打开、接受补全之后都要关） */
    public function close(): void
    {
        $this->active = false;
        $this->items = [];
        $this->sel = 0;
        $this->prefixStart = 0;
        $this->context = '';
        $this->key = '';
    }

    /**
     * 按当前输入重算候选。
     *
     * @param string $context 上下文标识；空串 = 当前焦点没有补全语义（直接关闭）
     * @param string $text    当前输入的完整文本
     * @param int    $cursor  光标位置的**字符**下标（无光标的输入框传 mb_strlen($text)）
     * @param string $prefix  光标前正在被补全的那段 token（空串 = 没有可补全的 token）
     * @return bool 是否处于活跃态（有候选）
     */
    public function refresh(string $context, string $text, int $cursor, string $prefix): bool
    {
        // 输入没变（Tab/Shift+Tab 这类按键）：保留当前选中项，别把它打回第一条
        $key = $context . "\x1f" . $prefix . "\x1f" . $cursor;
        if ($this->active && $key === $this->key) {
            return true;
        }

        $this->close();
        if ($context === '' || $prefix === '' || $this->providers === []) {
            return false;
        }
        $items = [];
        foreach ($this->providers as $p) {
            foreach ($p($context, $text, $cursor, $prefix) as $it) {
                if ($it instanceof CompletionItem) {
                    $items[] = $it;
                }
                if (count($items) >= self::MAX_ITEMS) {
                    break 2;
                }
            }
        }
        if ($items === []) {
            return false;
        }
        $this->items = $items;
        $this->sel = 0;
        $this->prefixStart = max(0, $cursor - mb_strlen($prefix));
        $this->context = $context;
        $this->active = true;
        $this->key = $key;
        return true;
    }

    /** 上下移动选中项（循环，候选通常不多，循环比钳制少一次"到底了没反应"的困惑） */
    public function move(int $delta): void
    {
        $n = count($this->items);
        if ($n === 0) {
            return;
        }
        $this->sel = (($this->sel + $delta) % $n + $n) % $n;
    }
}

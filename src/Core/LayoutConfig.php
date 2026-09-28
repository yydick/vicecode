<?php
declare(strict_types=1);

namespace App\Core;

/**
 * 可变布局配置（R5 拖拽分隔条）。
 *
 * 把原本散落在 LayoutFactory 里的硬编码约束（侧栏 30 / AI 45 / 编辑器 60% / AI 输入框 6）
 * 抽成一份数据，拖拽分隔条时改这里，渲染与命中测试共用同一份，杜绝「两处约束不同步」。
 *
 * 设计为**不可变值对象**：每次拖拽更新都返回一个新实例（with*），App 持有的引用整体替换。
 * 好处是状态可预测、便于将来 R7 直接序列化落盘（写 ~/.vicerc）。
 *
 * 单维边界（如侧栏 12~60 列）用本类常量；**交叉边界**（侧栏+AI 不能把中间列挤没）
 * 由调用方（App 拖拽逻辑）在更新前用视口宽高算出来再喂进 with*——本类不持有视口信息。
 */
final class LayoutConfig
{
    public const DEFAULT_SIDEBAR = 30;
    public const DEFAULT_AI = 45;
    public const DEFAULT_EDITOR_RATIO = 0.60;
    public const DEFAULT_AI_INPUT = 5;

    /** 侧栏宽度（列） */
    public const MIN_SIDEBAR = 12;
    public const MAX_SIDEBAR = 60;

    /** AI 列宽度（列） */
    public const MIN_AI = 20;
    public const MAX_AI = 80;

    /** 主区中间自适应列的最小宽度（列）—— 侧栏+AI 再宽也不能把它挤成 0 */
    public const MIN_CENTER = 10;

    /** 编辑器/终端垂直分割比例（0~1） */
    public const MIN_EDITOR_RATIO = 0.2;
    public const MAX_EDITOR_RATIO = 0.8;

    /** AI 输入框框高（行）= 上下边框 + 输入内容行。
     *  工具栏（发送/换行/清空）用图标放在**顶边框**（右对齐），不占输入行。
     *  默认 5 = 上下边框(2) + 3 行输入内容（输入区恒 ≥3 行，长 prompt 不被截断）。
     *  最小 5 = 上下边框(2) + 至少 3 行输入（用户要求输入区最少 3 行，故框高下限即 5）。 */
    public const MIN_AI_INPUT = 5;
    public const MAX_AI_INPUT = 30;
    /** AI 消息流最少留几行，避免输入框把消息流完全吞掉 */
    public const MIN_AI_STREAM = 3;

    public function __construct(
        public readonly int $sidebarWidth = self::DEFAULT_SIDEBAR,
        public readonly int $aiWidth = self::DEFAULT_AI,
        public readonly float $editorRatio = self::DEFAULT_EDITOR_RATIO,
        public readonly int $aiInputHeight = self::DEFAULT_AI_INPUT,
        // ── 面板显隐与终端最大化（B16）──
        // 三个可见性默认全 true（= 与加这些字段之前完全一致：默认布局一字不变），
        // 最大化默认 false。它们不参与 clamp，只决定 LayoutFactory 切不切这块矩形。
        public readonly bool $sidebarVisible = true,
        public readonly bool $aiVisible = true,
        public readonly bool $terminalVisible = true,
        /** 终端最大化：中列只留终端（编辑器让位），左右两列保留 */
        public readonly bool $terminalMaximized = false,
    ) {
    }

    public function withSidebarWidth(int $w): self
    {
        return new self(
            (int) self::clamp($w, self::MIN_SIDEBAR, self::MAX_SIDEBAR),
            $this->aiWidth,
            $this->editorRatio,
            $this->aiInputHeight,
            $this->sidebarVisible,
            $this->aiVisible,
            $this->terminalVisible,
            $this->terminalMaximized,
        );
    }

    public function withAiWidth(int $w): self
    {
        return new self(
            $this->sidebarWidth,
            (int) self::clamp($w, self::MIN_AI, self::MAX_AI),
            $this->editorRatio,
            $this->aiInputHeight,
            $this->sidebarVisible,
            $this->aiVisible,
            $this->terminalVisible,
            $this->terminalMaximized,
        );
    }

    public function withEditorRatio(float $r): self
    {
        return new self(
            $this->sidebarWidth,
            $this->aiWidth,
            self::clamp($r, self::MIN_EDITOR_RATIO, self::MAX_EDITOR_RATIO),
            $this->aiInputHeight,
            $this->sidebarVisible,
            $this->aiVisible,
            $this->terminalVisible,
            $this->terminalMaximized,
        );
    }

    public function withAiInputHeight(int $h): self
    {
        return new self(
            $this->sidebarWidth,
            $this->aiWidth,
            $this->editorRatio,
            (int) self::clamp($h, self::MIN_AI_INPUT, self::MAX_AI_INPUT),
            $this->sidebarVisible,
            $this->aiVisible,
            $this->terminalVisible,
            $this->terminalMaximized,
        );
    }

    // ── 面板显隐 / 终端最大化 ──────────────────────────
    // 注意：这四个 with* 也必须把**其余字段**原样带上。前四个 with* 同理 ——
    // 漏一个字段就会「拖一下分隔条，隐藏状态被悄悄重置回默认」。

    public function withSidebarVisible(bool $v): self
    {
        return $this->copyWith(['sidebarVisible' => $v]);
    }

    public function withAiVisible(bool $v): self
    {
        return $this->copyWith(['aiVisible' => $v]);
    }

    public function withTerminalVisible(bool $v): self
    {
        return $this->copyWith(['terminalVisible' => $v]);
    }

    public function withTerminalMaximized(bool $v): self
    {
        return $this->copyWith(['terminalMaximized' => $v]);
    }

    /**
     * 复制并覆盖若干字段（只给布尔显隐字段用：它们不需要 clamp）。
     * @param array<string,bool> $overrides
     */
    private function copyWith(array $overrides): self
    {
        return new self(
            $this->sidebarWidth,
            $this->aiWidth,
            $this->editorRatio,
            $this->aiInputHeight,
            $overrides['sidebarVisible'] ?? $this->sidebarVisible,
            $overrides['aiVisible'] ?? $this->aiVisible,
            $overrides['terminalVisible'] ?? $this->terminalVisible,
            $overrides['terminalMaximized'] ?? $this->terminalMaximized,
        );
    }

    /** 把 $v 收束到 [$lo, $hi] 闭区间 */
    private static function clamp(float|int $v, float|int $lo, float|int $hi): float|int
    {
        return max($lo, min($v, $hi));
    }

    /**
     * 从配置数组恢复（R7 读 ~/.vicerc 用）。缺字段走默认，并经 with* 链 clamp，
     * 坏数据（越界/类型错）不会污染布局——保证切出的矩形永远合法。
     * @param array<string,mixed> $a
     */
    public static function fromArray(array $a): self
    {
        $base = new self();
        $layout = $a['layout'] ?? [];
        if (!is_array($layout)) {
            $layout = [];
        }
        return $base
            ->withSidebarWidth((int) ($layout['sidebarWidth'] ?? self::DEFAULT_SIDEBAR))
            ->withAiWidth((int) ($layout['aiWidth'] ?? self::DEFAULT_AI))
            ->withEditorRatio((float) ($layout['editorRatio'] ?? self::DEFAULT_EDITOR_RATIO))
            ->withAiInputHeight((int) ($layout['aiInputHeight'] ?? self::DEFAULT_AI_INPUT))
            ->withSidebarVisible(self::boolOr($layout['sidebarVisible'] ?? null, true))
            ->withAiVisible(self::boolOr($layout['aiVisible'] ?? null, true))
            ->withTerminalVisible(self::boolOr($layout['terminalVisible'] ?? null, true))
            ->withTerminalMaximized(self::boolOr($layout['terminalMaximized'] ?? null, false));
    }

    /**
     * 宽松地读布尔字段：**不能用 `(bool) $v`** —— 手写配置里写 `"false"` / `0` / `"0"` 时，
     * `(bool) "false"` 是 true（非空字符串），会把「关掉的面板」读成「开着」。
     * 缺失（null）走默认值。
     */
    private static function boolOr(mixed $v, bool $default): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v) || is_float($v)) {
            return $v !== 0;
        }
        if (is_string($v)) {
            $s = strtolower(trim($v));
            if (in_array($s, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($s, ['0', 'false', 'no', 'off', ''], true)) {
                return false;
            }
        }
        return $default;
    }

}

<?php
declare(strict_types=1);

namespace App\Core;

/**
 * 可变布局配置（R5 拖拽分隔条）。
 *
 * 把原本散落在 LayoutFactory 里的硬编码约束（侧栏 30 / AI 45 / 编辑器 60% / AI 输入框 3）
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
    public const DEFAULT_AI_INPUT = 3;

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

    /** AI 输入框高度（行） */
    public const MIN_AI_INPUT = 1;
    public const MAX_AI_INPUT = 30;
    /** AI 消息流最少留几行，避免输入框把消息流完全吞掉 */
    public const MIN_AI_STREAM = 3;

    public function __construct(
        public readonly int $sidebarWidth = self::DEFAULT_SIDEBAR,
        public readonly int $aiWidth = self::DEFAULT_AI,
        public readonly float $editorRatio = self::DEFAULT_EDITOR_RATIO,
        public readonly int $aiInputHeight = self::DEFAULT_AI_INPUT,
    ) {
    }

    public function withSidebarWidth(int $w): self
    {
        return new self(
            (int) self::clamp($w, self::MIN_SIDEBAR, self::MAX_SIDEBAR),
            $this->aiWidth,
            $this->editorRatio,
            $this->aiInputHeight,
        );
    }

    public function withAiWidth(int $w): self
    {
        return new self(
            $this->sidebarWidth,
            (int) self::clamp($w, self::MIN_AI, self::MAX_AI),
            $this->editorRatio,
            $this->aiInputHeight,
        );
    }

    public function withEditorRatio(float $r): self
    {
        return new self(
            $this->sidebarWidth,
            $this->aiWidth,
            self::clamp($r, self::MIN_EDITOR_RATIO, self::MAX_EDITOR_RATIO),
            $this->aiInputHeight,
        );
    }

    public function withAiInputHeight(int $h): self
    {
        return new self(
            $this->sidebarWidth,
            $this->aiWidth,
            $this->editorRatio,
            (int) self::clamp($h, self::MIN_AI_INPUT, self::MAX_AI_INPUT),
        );
    }

    /** 把 $v 收束到 [$lo, $hi] 闭区间 */
    private static function clamp(float|int $v, float|int $lo, float|int $hi): float|int
    {
        return max($lo, min($v, $hi));
    }

}

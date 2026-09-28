<?php
declare(strict_types=1);

namespace App\Core;

/**
 * 状态栏「可点段」弹出的选项列表（语言 / 主题）。
 *
 * ## 为什么单独成一个类
 * 「点段 → 弹列表 → 选一项」这件事有两处要用同一份东西：渲染浮层要看选项、当前值与高亮项，
 * 按键/鼠标要用同一套「移动选中 + 取选中项」逻辑。散在 App 里会变成三份各自为政的状态。
 *
 * ## 存在即展开
 * `App::$picker === null` 表示浮层关着；有实例就是开着。少一个 bool 就少一处不一致。
 *
 * ## id 只用于「这是哪个段的东西」
 * 目前是 `locale` / `theme`，与状态栏段 key 同名（命中判定靠它对应）。
 * 将来要加新的可点段，只需补一个静态构造 + 在 App 里补一个 apply 分支。
 */
final class StatusPicker
{
    /**
     * 语言显示名。
     *
     * ⚠️ 刻意**不进 i18n 包**：语言名是专名，应当用它自己的语言写
     * （切到 en 后列表里也该是「中文」而不是「Chinese」），否则用户在
     * 看不懂当前界面语言时反而找不到自己的语言。
     */
    private const LOCALE_LABELS = [
        'zh_CN' => '中文 (zh_CN)',
        'en'    => 'English (en)',
    ];

    /** 当前高亮项下标（会随按键移动） */
    public int $sel;

    /**
     * @param list<array{value:string,label:string}> $options
     * @param int $currentIndex 当前**生效值**在 options 里的下标（渲染时标 ●）
     * @param int|null $sel 初始高亮项；不给则落在当前生效值上（不是第一项）
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly array $options,
        public readonly int $currentIndex,
        ?int $sel = null,
    ) {
        $this->sel = $sel ?? $currentIndex;
    }

    /**
     * 语言列表。数据源是 `Translator::available()`。
     *
     * @param list<string> $codes
     */
    public static function locales(array $codes, string $current, string $title): self
    {
        $options = [];
        foreach ($codes as $code) {
            $options[] = ['value' => $code, 'label' => self::LOCALE_LABELS[$code] ?? $code];
        }
        return self::withCurrent('locale', $title, $options, $current);
    }

    /**
     * 主题列表。
     *
     * @param list<string> $ids
     * @param callable(string):string $label 主题 id → 显示名（theme.* 的 i18n）
     */
    public static function themes(array $ids, string $current, string $title, callable $label): self
    {
        $options = [];
        foreach ($ids as $id) {
            $options[] = ['value' => $id, 'label' => $label($id)];
        }
        return self::withCurrent('theme', $title, $options, $current);
    }

    /** @param list<array{value:string,label:string}> $options */
    private static function withCurrent(string $id, string $title, array $options, string $current): self
    {
        $idx = 0;
        foreach ($options as $i => $o) {
            if ($o['value'] === $current) {
                $idx = $i;
                break;
            }
        }
        return new self($id, $title, $options, $idx);
    }

    /** 环形移动高亮（项数很少，不做翻页） */
    public function move(int $delta): void
    {
        $n = count($this->options);
        if ($n === 0) {
            return;
        }
        $sel = ($this->sel + $delta) % $n;
        $this->sel = $sel < 0 ? $sel + $n : $sel;
    }

    /** @return array{value:string,label:string}|null */
    public function selected(): ?array
    {
        return $this->options[$this->sel] ?? null;
    }

    /** 第 $i 项是否就是当前生效值（渲染时标 ●） */
    public function isCurrent(int $i): bool
    {
        return $i === $this->currentIndex;
    }
}

<?php
declare(strict_types=1);

/**
 * 示例插件：状态栏时钟。
 * 纯普通 PHP（不在 composer autoload 内），由 PluginLoader 运行时 require 进来。
 *
 * 可配置（~/.vicerc 的 plugins.clock 段，覆盖下方 configDefaults 的默认值）：
 *   timezone: PHP 时区名，如 "Asia/Shanghai"（默认，UTC+8）/ "UTC" / "America/New_York"
 *             特殊值 "local" = 跟随 PHP 当前 date.timezone（即本机 php.ini 设置）
 *   format:   date() 格式串，默认 "H:i:s"（纯 ASCII，显示宽度恒为 8，不触发 CJK 折行）
 */
class ClockPlugin implements \App\Plugin\PluginInterface
{
    private string $tz = 'Asia/Shanghai';
    private string $fmt = 'H:i:s';

    public function id(): string
    {
        return 'clock';
    }

    /** 插件默认配置（用户 ~/.vicerc 的 plugins.clock 段可覆盖） */
    public function configDefaults(): array
    {
        return [
            'timezone' => 'Asia/Shanghai',
            'format'   => 'H:i:s',
        ];
    }

    /** 注入合并后的配置（默认 ∩ 用户覆盖） */
    public function configure(array $c): void
    {
        $tz = $c['timezone'] ?? 'Asia/Shanghai';
        if ($tz === 'local') {
            $tz = date_default_timezone_get();
        }
        $this->tz = is_string($tz) && $tz !== '' ? $tz : 'Asia/Shanghai';
        $this->fmt = is_string($c['format'] ?? null) && $c['format'] !== '' ? $c['format'] : 'H:i:s';
    }

    public function statusSegments(\App\App $app): array
    {
        // 用显式时区的 DateTime，独立于 php.ini 的 date.timezone——
        // 因此无论本机 php.ini 如何设置，都能按配置的时区显示（默认 Asia/Shanghai = UTC+8）。
        try {
            $dt = new \DateTime('now', new \DateTimeZone($this->tz));
            $text = $dt->format($this->fmt);
        } catch (\Throwable $e) {
            $text = date($this->fmt); // 时区名非法时降级到 PHP 当前时区，不致命
        }
        return [new \App\Plugin\StatusSegment('clock', $text, 55, 12)];
    }

    /** 每秒重绘一次，使时钟持续走动 */
    public function tickInterval(): ?int
    {
        return 1;
    }
}

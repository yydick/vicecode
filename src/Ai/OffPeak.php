<?php
declare(strict_types=1);

namespace App\Ai;

use DateTimeImmutable;
use DateTimeZone;

/**
 * 服务商**折扣时段**（off-peak window）的纯函数解析与判定。
 *
 * 为什么是独立的一个类、而不是塞进 ProviderRegistry 或 ChatModel：时段判定是**纯计算**
 * （输入一组窗口 + 一个时刻，输出"是否打折"），把它和"配置从哪读""什么时候该切档"分开，
 * 才能用固定时刻直接单测——否则只能靠 sleep 到某个钟点，测不动也测不稳。
 *
 * ── 窗口语法（刻意窄，别扩成 cron）────────────────────────────────────────
 *
 *   ['days' => ['sat','sun'], 'from' => '00:00', 'to' => '24:00']
 *   ['days' => ['*'], 'from' => '23:30', 'to' => '08:30', 'tz' => '+08:00']
 *
 *  · `days`    三字母英文缩写 `mon`…`sun`（大小写不敏感）或 `'*'`；**省略 = `'*'`**。
 *  · `from`/`to`  `HH:MM`，`to` 允许 `'24:00'`。三种关系各有含义：
 *      - `from < to`：同日窗口；
 *      - `from > to`：**跨天**窗口（晚上起、次日早上止）；
 *      - `from === to`：**全天**。
 *  · `tz`      `±HH:MM` 偏移（如 `'+08:00'`）；省略 = 用当前时刻自带的时区。
 *              **故意不支持命名时区**（`Asia/Shanghai`）：偏移在任何机器上都无需时区库，
 *              且"折扣是相对某地钟点"这件事用偏移表达已经足够，少一层依赖少一处坑。
 *
 * ⚠️ **`days` 指的是窗口「开始」那一天**。`days: ['sat'], from 23:30, to 08:30` 是
 *    「周六 23:30 → 周日 08:30」，不是「凡是周六/周日的 23:30–08:30」。这是市场时段/
 *    闲时电价那类规则的通行读法；跨天窗口的**次日凌晨那一段**要拿**前一天**的星期去匹配。
 *
 * ── 宽容解析 ──────────────────────────────────────────────────────────
 *
 * 与 `ProviderRegistry::parseCaps()` 同风格：**坏条目只丢自己，不影响其它条**
 * （整条非数组 / 时间格式非法 ⇒ 丢弃）。配置写错时最坏结果是"折扣不生效"，
 * 而不是应用起不来或把用户锁在某个档位上。
 */
final class OffPeak
{
    /** 星期缩写 → ISO-8601 星期号（1=周一 … 7=周日，与 DateTimeImmutable::format('N') 一致） */
    private const DAY_NUM = [
        'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7,
    ];

    /** 一天的分钟数（`to => '24:00'` 会用到） */
    private const DAY_MINUTES = 1440;

    /**
     * 规范化一组窗口。返回的每一项形状固定，后续判定不必再处理用户输入的多样性：
     *
     *   ['days' => int[]|null, 'from' => int, 'to' => int, 'tz' => string|null]
     *
     * `days === null` 表示任意星期（写 `'*'` 或省略）。
     *
     * @param mixed $raw 配置里的 `off_peak` 原值（可以是单条数组、多条数组、垃圾）
     * @return array<int,array{days:?array<int,int>,from:int,to:int,tz:?string}>
     */
    public static function parseWindows(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        // 允许只写一条（['from'=>…,'to'=>…] 而非 [ [...], [...] ]）：方便手写单条配置。
        // 判据是"有没有 from/to 键"，而不是"是不是列表"——避免把关联数组当成多条。
        if (array_key_exists('from', $raw) || array_key_exists('to', $raw)) {
            $raw = [$raw];
        }
        $out = [];
        foreach ($raw as $entry) {
            $w = self::parseOne($entry);
            if ($w !== null) {
                $out[] = $w;
            }
        }
        return $out;
    }

    /** 单个窗口的规范化；形状不对/时间非法返回 null（调用方丢弃） */
    private static function parseOne(mixed $entry): ?array
    {
        if (!is_array($entry)) {
            return null;
        }
        $from = self::parseTime($entry['from'] ?? null);
        $to = self::parseTime($entry['to'] ?? null);
        if ($from === null || $to === null) {
            return null;
        }
        $days = self::parseDays($entry['days'] ?? null);
        if ($days === false) {
            return null;                        // days 里一个已知星期都没有 → 该条无意义
        }
        $tz = $entry['tz'] ?? null;
        $tz = is_string($tz) && self::isValidTz(trim($tz)) ? trim($tz) : null;

        return ['days' => $days, 'from' => $from, 'to' => $to, 'tz' => $tz];
    }

    /**
     * `HH:MM` → 分钟数。`to` 写 `24:00` = 一天末尾（1440），用于表达"到午夜为止"。
     * 非法（格式不对 / 时>24 / 分>59 / 24 点带非零分）→ null。
     */
    private static function parseTime(mixed $v): ?int
    {
        if (!is_string($v) || !preg_match('/^(\d{1,2}):(\d{2})$/', trim($v), $m)) {
            return null;
        }
        $h = (int) $m[1];
        $min = (int) $m[2];
        if ($h > 24 || $min > 59) {
            return null;
        }
        if ($h === 24 && $min !== 0) {
            return null;                        // 24:30 这种不存在
        }
        return $h * 60 + $min;
    }

    /**
     * `days` → 星期号数组；`'*'`/省略 → null（任意星期）；全是不认识的值 → false（该条丢弃）。
     *
     * @return int[]|null|false
     */
    private static function parseDays(mixed $raw): array|null|false
    {
        if ($raw === null || $raw === '*' || (is_string($raw) && trim($raw) === '*')) {
            return null;
        }
        $list = is_array($raw) ? $raw : [$raw];
        $out = [];
        foreach ($list as $v) {
            if (!is_string($v)) {
                continue;
            }
            $key = strtolower(trim($v));
            if ($key === '*') {
                return null;                    // '*' 与具体星期混写：整体视为任意星期
            }
            $n = self::DAY_NUM[$key] ?? null;
            if ($n !== null) {
                $out[$n] = true;
            }
        }
        if ($out === []) {
            return false;
        }
        $nums = array_keys($out);
        sort($nums);
        return $nums;
    }

    /** `±HH:MM` 偏移是否合法（交给 DateTimeZone 自己判，避免手写一套正则边界） */
    private static function isValidTz(string $tz): bool
    {
        if ($tz === '') {
            return false;
        }
        try {
            new DateTimeZone($tz);
            return true;
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * 此刻是否落在任一窗口内（任一命中即打折）。
     *
     * @param array<int,array{days:?array<int,int>,from:int,to:int,tz:?string}> $windows parseWindows 的结果
     */
    public static function isActive(array $windows, DateTimeImmutable $now): bool
    {
        foreach ($windows as $w) {
            if (self::windowState($w, $now) !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * 当前折扣的**结束钟点**（`HH:MM`），供提示"折扣到 XX:XX 为止"；不在折扣中返回 null。
     *
     * 多条窗口同时命中时取**最早结束**的那条（用户关心的是"这波什么时候结束"）。
     *   · 全天窗口（`from === to`）没有有意义的结束时刻 → 只被它覆盖时返回 null。
     */
    public static function activeUntil(array $windows, DateTimeImmutable $now): ?string
    {
        $bestLeft = null;                       // 剩余分钟数最小者
        $label = null;
        foreach ($windows as $w) {
            $left = self::windowState($w, $now);
            if ($left === null || $left['fullDay']) {
                continue;
            }
            if ($bestLeft === null || $left['remaining'] < $bestLeft) {
                $bestLeft = $left['remaining'];
                $end = $w['to'] % self::DAY_MINUTES;    // 24:00 → 00:00（午夜）
                $label = sprintf('%02d:%02d', intdiv($end, 60), $end % 60);
            }
        }
        return $label;
    }

    /**
     * 窗口相对某时刻的状态：null = 不在窗口内；否则给出"还剩多久"与"是否全天"。
     *
     * @param array{days:?array<int,int>,from:int,to:int,tz:?string} $w
     * @return array{remaining:int,fullDay:bool}|null
     */
    private static function windowState(array $w, DateTimeImmutable $now): ?array
    {
        $local = $w['tz'] !== null ? $now->setTimezone(new DateTimeZone($w['tz'])) : $now;
        $minutes = ((int) $local->format('G')) * 60 + (int) $local->format('i');
        $dow = (int) $local->format('N');
        $days = $w['days'];

        $from = $w['from'];
        $to = $w['to'];

        // 全天：只看星期（同一天）
        if ($from === $to) {
            if ($days === null || in_array($dow, $days, true)) {
                return ['remaining' => self::DAY_MINUTES - $minutes, 'fullDay' => true];
            }
            return null;
        }

        if ($from < $to) {
            // 同日窗口：星期按**当天**匹配
            if ($minutes >= $from && $minutes < $to
                && ($days === null || in_array($dow, $days, true))) {
                return ['remaining' => $to - $minutes, 'fullDay' => false];
            }
            return null;
        }

        // 跨天窗口（from > to）：两段，星期都按**窗口开始那天**匹配
        if ($minutes >= $from) {
            // 晚上那一段：窗口今天开始
            if ($days === null || in_array($dow, $days, true)) {
                return ['remaining' => self::DAY_MINUTES - $minutes + $to, 'fullDay' => false];
            }
            return null;
        }
        if ($minutes < $to) {
            // 次日凌晨那一段：窗口**昨天**开始 —— 星期要取前一天，否则
            // `days: ['sat'], 23:30–08:30` 会把周日凌晨也算成周六的窗口。
            $startDow = $dow === 1 ? 7 : $dow - 1;
            if ($days === null || in_array($startDow, $days, true)) {
                return ['remaining' => $to - $minutes, 'fullDay' => false];
            }
        }
        return null;
    }
}

<?php
declare(strict_types=1);

/**
 * 服务商折扣时段（off-peak）—— headless 纯函数单测。
 *
 * 被测对象是 `App\Ai\OffPeak`：**纯计算**（一组窗口 + 一个时刻 → 是否打折），
 * 所以全部断言都靠**固定时刻**，没有任何 sleep / 依赖系统钟点。
 *
 * 覆盖的边界（都是手写这段逻辑时最容易写错的地方）：
 *  · 同日窗口的 `from`/`to` 开闭区间（`from` 含、`to` 不含）
 *  · **跨天窗口**：`days` 指窗口**开始**那一天 → 次日凌晨那段要拿**前一天**的星期去匹配
 *  · `from === to` = 全天；`to` 写 `24:00` 合法、`24:30` 非法
 *  · `tz` 偏移：同一 UTC 时刻在两个偏移下结论可以相反
 *  · 宽容解析：坏条目只丢自己，不影响同列表里的好条目
 *
 * 运行：php tests/offpeak_unit.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/isolation.php';

use App\Ai\OffPeak;

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

/** 造一个"某个本地时刻"（明确带时区，避免依赖本机 TZ） */
function at(string $local, string $tz = '+00:00'): DateTimeImmutable
{
    return new DateTimeImmutable($local, new DateTimeZone($tz));
}

// 基准日历（固定，不随运行日期变）：2026-09-19 = 周六，09-20 = 周日，09-18 = 周五
$sat = at('2026-09-19 10:00:00');
check($sat->format('N') === '6' && $sat->format('D') === 'Sat', '基准日历自检：2026-09-19 是周六');

// ═══════════ 1) parseWindows 形状与宽容 ═══════════
echo "== 解析：形状 / 宽容 ==\n";

check(OffPeak::parseWindows(null) === [], '非数组（null）→ 空列表');
check(OffPeak::parseWindows('08:00') === [], '非数组（裸串）→ 空列表');
check(OffPeak::parseWindows([]) === [], '空数组 → 空列表');

// 单条关联数组：允许直接写 ['from'=>…,'to'=>…]，不必套一层
$one = OffPeak::parseWindows(['from' => '09:00', 'to' => '17:00']);
check(count($one) === 1, '单条关联数组（直接写 from/to）被接受');
check($one[0]['days'] === null && $one[0]['from'] === 540 && $one[0]['to'] === 1020 && $one[0]['tz'] === null,
    '规范化结果：days=null（任意星期）/ from,to 转成分钟 / tz=null（用本机时区）');

$bad = OffPeak::parseWindows([
    ['from' => '09:00', 'to' => '17:00'],          // 好的
    'not-an-array',                                 // 坏：整条不是数组
    ['from' => '9am', 'to' => '17:00'],             // 坏：时间格式
    ['from' => '09:00'],                            // 坏：缺 to
    ['from' => '09:00', 'to' => '24:30'],           // 坏：24 点带非零分
    ['from' => '09:00', 'to' => '17:00', 'days' => ['xyz']],   // 坏：没一个认识的星期
]);
check(count($bad) === 1, '坏条目只丢自己，好的那条仍在（实际保留 ' . count($bad) . ' 条）');

check(OffPeak::parseWindows([['from' => '09:00', 'to' => '17:00', 'tz' => 'Mars/Olympus']])[0]['tz'] === null,
    '非法 tz 退化为 null（用本机时区），不让整条作废');
check(OffPeak::parseWindows([['from' => '09:00', 'to' => '17:00', 'days' => ['MON', 'Sat']]])[0]['days'] === [1, 6],
    'days 大小写不敏感，结果按星期号排序');
check(OffPeak::parseWindows([['from' => '09:00', 'to' => '17:00', 'days' => ['mon', '*']]])[0]['days'] === null,
    "days 里混写 '*' → 整体视为任意星期");
check(OffPeak::parseWindows([['from' => '09:00', 'to' => '17:00']])[0]['days'] === null,
    'days 省略 = 任意星期');
check(OffPeak::parseWindows([['from' => '24:00', 'to' => '24:00']])[0]['to'] === 1440,
    "to 写 '24:00' 合法（= 一天末尾 1440）");

// ═══════════ 2) 同日窗口 ═══════════
echo "== 同日窗口（from < to）==\n";

$work = OffPeak::parseWindows([['from' => '09:00', 'to' => '17:00']]);
check(OffPeak::isActive($work, at('2026-09-19 09:00:00')) === true,  'from 边界含（09:00 为真）');
check(OffPeak::isActive($work, at('2026-09-19 16:59:00')) === true,  'to 之前一刻为真');
check(OffPeak::isActive($work, at('2026-09-19 17:00:00')) === false, 'to 边界不含（17:00 为假）');
check(OffPeak::isActive($work, at('2026-09-19 08:59:00')) === false, 'from 之前一刻为假');
check(OffPeak::activeUntil($work, at('2026-09-19 10:30:00')) === '17:00', 'activeUntil 给出结束钟点');

// ═══════════ 3) 跨天窗口 ═══════════
echo "== 跨天窗口（from > to）==\n";

$night = OffPeak::parseWindows([['from' => '23:30', 'to' => '08:30']]);
check(OffPeak::isActive($night, at('2026-09-19 01:00:00')) === true,  '凌晨 01:00 在前一晚起的窗口内');
check(OffPeak::isActive($night, at('2026-09-19 08:29:00')) === true,  'to 之前一刻为真');
check(OffPeak::isActive($night, at('2026-09-19 08:30:00')) === false, 'to 边界不含（08:30 为假）');
check(OffPeak::isActive($night, at('2026-09-19 12:00:00')) === false, '中午不在窗口内');
check(OffPeak::isActive($night, at('2026-09-19 23:29:00')) === false, 'from 之前一刻为假');
check(OffPeak::isActive($night, at('2026-09-19 23:30:00')) === true,  'from 边界含（23:30 为真）');
check(OffPeak::activeUntil($night, at('2026-09-19 01:00:00')) === '08:30', '跨天时 activeUntil 给的是**次日**的结束钟点');
check(OffPeak::activeUntil($night, at('2026-09-19 23:30:00')) === '08:30', '窗口开始时的 activeUntil 同样是 08:30');

// ═══════════ 4) days 与跨天的组合（最易写错的一处）═══════════
echo "== days × 跨天：days 指窗口『开始』那一天 ==\n";

// 周六 23:30 → 周日 08:30
$satNight = OffPeak::parseWindows([['days' => ['sat'], 'from' => '23:30', 'to' => '08:30']]);
check(OffPeak::isActive($satNight, at('2026-09-19 23:30:00')) === true,  '周六 23:30 在窗口内（窗口今天开始）');
check(OffPeak::isActive($satNight, at('2026-09-20 01:00:00')) === true,  '周日 01:00 仍在窗口内（窗口**昨天**开始 → 拿前一天的星期匹配）');
check(OffPeak::isActive($satNight, at('2026-09-20 08:29:00')) === true,  '周日 08:29 仍在窗口内');
check(OffPeak::isActive($satNight, at('2026-09-20 08:30:00')) === false, '周日 08:30 出窗口');
check(OffPeak::isActive($satNight, at('2026-09-20 23:30:00')) === false, '周日 23:30 **不在**窗口内（那天是周日，不是窗口开始日）');
check(OffPeak::isActive($satNight, at('2026-09-21 01:00:00')) === false, '周一 01:00 不在窗口内（前一天是周日）');
// 反向：窗口只在周日晚上开 → 周一凌晨才在窗口内；周五晚上开 → 周六凌晨在窗口内
$sunNight = OffPeak::parseWindows([['days' => ['sun'], 'from' => '23:30', 'to' => '08:30']]);
check(OffPeak::isActive($sunNight, at('2026-09-21 01:00:00')) === true, '周日晚上起的窗口覆盖到周一凌晨（跨周界：前一天的星期号要回绕）');

$weekend = OffPeak::parseWindows([['days' => ['sat', 'sun'], 'from' => '00:00', 'to' => '24:00']]);
check(OffPeak::isActive($weekend, at('2026-09-19 13:00:00')) === true,  '周六全天窗口命中');
check(OffPeak::isActive($weekend, at('2026-09-20 23:59:00')) === true,  '周日全天窗口命中（to=24:00 覆盖末刻）');
check(OffPeak::isActive($weekend, at('2026-09-18 13:00:00')) === false, '周五不在周末全天窗口内');
check(OffPeak::activeUntil($weekend, at('2026-09-19 13:00:00')) === '00:00', "全天窗口的 activeUntil 归一成 '00:00'（24:00 → 午夜）");

// ═══════════ 5) 全天（from === to）═══════════
echo "== 全天窗口（from === to）==\n";
$allday = OffPeak::parseWindows([['days' => ['sat'], 'from' => '00:00', 'to' => '00:00']]);
check(OffPeak::isActive($allday, at('2026-09-19 00:00:00')) === true,  '当天起点即命中');
check(OffPeak::isActive($allday, at('2026-09-19 23:59:00')) === true,  '当天末刻仍命中');
check(OffPeak::isActive($allday, at('2026-09-20 00:00:00')) === false, '次日不再命中');
check(OffPeak::activeUntil($allday, at('2026-09-19 12:00:00')) === null,
    '全天窗口没有有意义的结束时刻 → activeUntil 返回 null（调用方用「全天」文案兜底）');

// ═══════════ 6) tz 偏移 ═══════════
echo "== tz 偏移 ==\n";

// 同一 UTC 时刻：UTC 01:00 = +08:00 的 09:00
$utcNow = at('2026-09-19 01:00:00', 'UTC');
$withTz = OffPeak::parseWindows([['from' => '00:00', 'to' => '08:00', 'tz' => '+08:00']]);
$noTz   = OffPeak::parseWindows([['from' => '00:00', 'to' => '08:00']]);
check(OffPeak::isActive($withTz, $utcNow) === false,
    '带 tz=+08:00：UTC 01:00 换算成本地 09:00 → 已出 00:00–08:00 窗口');
check(OffPeak::isActive($noTz, $utcNow) === true,
    '不带 tz：按 $now 自带时区（UTC）判定 → 01:00 在窗口内');
check(OffPeak::isActive($withTz, at('2026-09-18 17:00:00', 'UTC')) === true,
    '带 tz=+08:00：UTC 前一天 17:00 = 次日 01:00 → 在窗口内（体现的是"相对该地钟点"）');

// 折扣窗口按 +08:00 定义，时刻用 +09:00 表达（同一瞬间）
$nine = at('2026-09-19 02:00:00', '+09:00');   // = UTC 17:00 前一天 = +08:00 的 01:00
check(OffPeak::isActive($withTz, $nine) === true, '窗口 tz 与时刻 tz 不同时，按窗口 tz 换算（同一瞬间结论一致）');

// days 也在窗口 tz 下判定：UTC 周六 20:00 = +08:00 周日 04:00
$crossTz = OffPeak::parseWindows([['days' => ['sun'], 'from' => '00:00', 'to' => '08:00', 'tz' => '+08:00']]);
check(OffPeak::isActive($crossTz, at('2026-09-19 20:00:00', 'UTC')) === true,
    'days 按**窗口 tz** 的星期判定（UTC 周六 20:00 = 北京周日 04:00 → 命中 sun）');
check(OffPeak::isActive($crossTz, at('2026-09-19 20:00:00', '+08:00')) === false,
    '同一钟点若按 +08:00 解读则是周六 20:00 → 不命中（方向相反，说明 tz 真的参与了 days 判定）');

// ═══════════ 7) 多窗口 / 空列表 ═══════════
echo "== 多窗口与空列表 ==\n";
check(OffPeak::isActive([], $sat) === false, '空窗口列表恒为 false（没配 = 不打折）');
$multi = OffPeak::parseWindows([
    ['days' => ['mon'], 'from' => '01:00', 'to' => '02:00'],           // 周一凌晨
    ['days' => ['sat'], 'from' => '09:00', 'to' => '18:00'],           // 周六白天
]);
check(OffPeak::isActive($multi, at('2026-09-19 10:00:00')) === true, '多窗口：任一命中即打折');
check(OffPeak::isActive($multi, at('2026-09-19 20:00:00')) === false, '多窗口都不命中 → false');
check(OffPeak::activeUntil($multi, at('2026-09-19 10:00:00')) === '18:00', '多窗口命中时 activeUntil = 该窗口的结束点');

// 两条同时命中 → 取**最早结束**的那条（用户关心"这波什么时候完"）
$overlap = OffPeak::parseWindows([
    ['from' => '09:00', 'to' => '23:00'],
    ['from' => '10:00', 'to' => '12:00'],
]);
check(OffPeak::activeUntil($overlap, at('2026-09-19 11:00:00')) === '12:00',
    '多条同时命中 → activeUntil 取**最早结束**（12:00 而非 23:00）');

echo $failed ? "\nRESULT: FAIL\n" : "\nRESULT: PASS\n";
exit($failed ? 1 : 0);

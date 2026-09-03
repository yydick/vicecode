<?php
declare(strict_types=1);

/**
 * 两语言包 key 集合奇偶校验（自动化，替代原先手测）。
 *
 * 设计约束（见 memory project_tui_status / R8）：zh_CN 与 en 必须拥有完全相同的
 * key 集合——zh_CN 是 en 的完整镜像（en 为兜底，缺失 key 自动回退）。一旦某次改
 * 文案只在其中一个包加了 key，就会静默出现「切到该语言才显示 key 字面量」的 bug
 * （R8 之前正是这么踩的）。本测试把「两包 key 一致」钉死，杜绝漂移。
 *
 * 同时校验：en 包（兜底基准）每个 value 都非空；两包均无非字符串 value。
 *
 * 运行：php tests/i18n_parity.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;

$dir = __DIR__ . '/../config/locales';
$zh = Config::loadPhp($dir . '/zh_CN.php');
$en = Config::loadPhp($dir . '/en.php');

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) { $failed = true; }
}

echo "== 语言包 key 集合奇偶校验 ==\n";
check($zh !== [] && $en !== [], '两个语言包均非空加载成功');

$zk = array_keys($zh);
$ek = array_keys($en);
$onlyZh = array_diff($zk, $ek);
$onlyEn = array_diff($ek, $zk);

check($onlyZh === [], 'zh_CN 没有 en 缺失的 key' . ($onlyZh ? '（多出：' . implode(',', $onlyZh) . '）' : ''));
check($onlyEn === [], 'en 没有 zh_CN 缺失的 key' . ($onlyEn ? '（多出：' . implode(',', $onlyEn) . '）' : ''));
check($zk === [] || $ek === [] || (count($zk) === count($ek) && $onlyZh === [] && $onlyEn === []),
    '两包 key 集合完全一致（zh=' . count($zk) . ' / en=' . count($ek) . '）');

// en 为兜底基准：每个 value 必须非空且为字符串
$emptyEn = [];
$nonStr = [];
foreach ($en as $k => $v) {
    if (!is_string($v)) { $nonStr[] = $k; }
    elseif ($v === '') { $emptyEn[] = $k; }
}
check($nonStr === [], 'en 包所有 value 均为字符串' . ($nonStr ? '（非字符串：' . implode(',', $nonStr) . '）' : ''));
check($emptyEn === [], 'en 包无空字符串 value' . ($emptyEn ? '（空值：' . implode(',', $emptyEn) . '）' : ''));

// zh_CN 也不得含空字符串（空串会让界面显示空白而非回退，易误以为回退生效）
$emptyZh = [];
foreach ($zh as $k => $v) {
    if (is_string($v) && $v === '') { $emptyZh[] = $k; }
}
check($emptyZh === [], 'zh_CN 包无空字符串 value' . ($emptyZh ? '（空值：' . implode(',', $emptyZh) . '）' : ''));

echo $failed ? "\n语言包奇偶校验 FAIL\n" : "\n语言包奇偶校验全部 PASS\n";
exit($failed ? 1 : 0);

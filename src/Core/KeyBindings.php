<?php
declare(strict_types=1);

namespace App\Core;

/**
 * 快捷键注册表（M6 R2）——帮助页的唯一数据源。
 *
 * ## 它是什么
 * 一份**声明式描述**：哪些键、在哪个面板、干什么。帮助页照它渲染，用户照它查。
 *
 * ## 它不是什么（重要，别误解成"改这里就能改行为"）
 * 它**不参与事件分发**——真正的绑定散落在 App::handle 与各面板的 onChar/onKey 里。
 * 把分发也改成读表需要重写全部面板的事件处理，风险与收益不成比例。
 *
 * 所以这里存在"描述与实际漂移"的风险。为对冲这个风险，`tests/m6_unit.php` 有一条
 * **漂移检测**：扫描 src/ 里所有 `Ctrl+X` 形式的绑定，断言每一个都在这张表里
 * 登记过。新增快捷键却忘了登记，测试会红。普通按键（Enter/↑/Esc 之类）无法自动
 * 扫描，靠人肉维护。
 *
 * ## 为什么用 i18n key 而不是直接写文案
 * 帮助页要跟随语言包；文案放这里会导致中英文案各写一遍还容易漏。
 */
final class KeyBindings
{
    /** 帮助页唤出/关闭键。用 '?' 同时兼容 Shift+/ 与直接按 ? */
    public const HELP_KEY = '?';

    /**
     * 全部分组。
     *
     * @return array<int,array{group:string,items:array<int,array{keys:string,desc:string}>}>
     */
    public static function all(): array
    {
        return [
            self::group('help.group_global', [
                ['Ctrl+Q', 'help.g_quit'],
                ['Tab', 'help.g_focus'],
                ['Esc', 'help.g_esc'],
                ['q', 'help.g_q'],
                [self::HELP_KEY, 'help.g_help'],
                ['Ctrl+T', 'help.g_theme'],
                ['F10', 'help.g_menu'],
                ['keys.wheel', 'help.g_wheel'],
                ['keys.hwheel', 'help.g_wheel_h'],
                ['keys.click', 'help.g_click'],
            ]),
            self::group('help.group_editor', [
                ['↑ ↓ ← →', 'help.e_move'],
                ['Home / End', 'help.e_home_end'],
                ['PageUp / PageDown', 'help.e_page'],
                ['Enter', 'help.e_newline'],
                ['Backspace / Delete', 'help.e_del'],
                ['Ctrl+S', 'help.e_save'],
                ['Ctrl+W', 'help.e_close'],
                ['Ctrl+Tab', 'help.e_switch'],
                ['Ctrl+V', 'help.e_paste'],
                ['keys.printable', 'help.e_type'],
            ]),
            self::group('help.group_terminal', [
                ['Enter', 'help.t_run'],
                ['↑ / ↓', 'help.t_history'],
                ['← / →', 'help.t_move'],
                ['Home / End', 'help.t_home_end'],
                ['Backspace / Delete', 'help.t_del'],
                ['PageUp / PageDown', 'help.t_page'],
                ['Ctrl+L', 'help.t_clear'],
                ['Ctrl+C', 'help.t_cancel'],
                ['Ctrl+V', 'help.e_paste'],
                ['Shift+Ins', 'help.e_paste'],
                ['F2', 'help.t_interactive'],
            ]),
            self::group('help.group_explorer', [
                ['↑ / ↓', 'help.x_move'],
                ['← / →', 'help.x_nav'],
                ['Enter', 'help.x_enter'],
                ['keys.click_file', 'help.x_open'],
                ['keys.click_triangle', 'help.x_arrow'],
                ['keys.dblclick_dir', 'help.x_dblclick'],
            ]),
            self::group('help.group_git', [
                ['keys.printable', 'help.gi_type'],
                ['Enter', 'help.gi_commit'],
                ['Backspace', 'help.gi_backspace'],
                ['↑ / ↓', 'help.gi_move'],
                ['+ / =', 'help.gi_stage'],
                ['-', 'help.gi_unstage'],
                ['Esc', 'help.gi_esc'],
                ['keys.click_icon', 'help.gi_icons'],
            ]),
            self::group('help.group_search', [
                ['keys.printable', 'help.s_type'],
                ['Enter', 'help.s_enter'],
                ['Backspace', 'help.s_backspace'],
                ['↑ / ↓', 'help.s_move'],
            ]),
            self::group('help.group_ai', [
                ['keys.printable', 'help.a_type'],
                ['Enter', 'help.a_send'],
                ['Ctrl+P', 'help.a_provider'],
                ['Ctrl+N', 'help.a_model'],
                ['Ctrl+L', 'help.a_clear'],
                ['Ctrl+V', 'help.e_paste'],
                ['↑ / ↓', 'help.a_history'],
                ['PageUp / PageDown', 'help.a_page'],
                ['Esc', 'help.a_stop'],
            ]),
        ];
    }

    /**
     * 帮助页需要的行数（分组标题 + 分隔 + 各项）。供覆盖层算滚动上界。
     */
    public static function lineCount(bool $withTitle = true): int
    {
        $n = $withTitle ? 2 : 0; // 标题行 + 空行
        foreach (self::all() as $g) {
            $n += 1;                    // 分组标题
            $n += count($g['items']);   // 各条目
            $n += 1;                    // 组间空行
        }
        return $n;
    }

    /** @param array<int,array{0:string,1:string}> $items */
    private static function group(string $groupKey, array $items): array
    {
        $out = [];
        foreach ($items as [$keys, $desc]) {
            $out[] = ['keys' => $keys, 'desc' => $desc];
        }
        return ['group' => $groupKey, 'items' => $out];
    }

    /**
     * 登记表里出现过的所有 Ctrl 组合（供漂移检测对比代码里的真实绑定）。
     *
     * @return string[] 形如 ['Q','S','W','P','N','L','C']
     */
    public static function documentedCtrlKeys(): array
    {
        $out = [];
        foreach (self::all() as $g) {
            foreach ($g['items'] as $it) {
                if (preg_match_all('/Ctrl\+([A-Za-z])/', $it['keys'], $m) > 0) {
                    foreach ($m[1] as $c) {
                        $out[] = strtoupper($c);
                    }
                }
            }
        }
        return array_values(array_unique($out));
    }
}

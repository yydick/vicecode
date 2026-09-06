<?php
declare(strict_types=1);

/**
 * headless 验收：交互式 PTY 会话持久化（不涉及真实 pty 子进程）。
 *
 * 三块独立验证：
 *   1. Vt100Emulator exportText/importText 纯文本往返
 *      —— 含 CJK 宽字（占位格跳过）、超屏高制造 scrollback、无残留 ESC。
 *   2. SessionStore 存 / 取 / 清 往返（VICECODE_CONFIG 隔离）。
 *   3. App 层接线：开启 persistSession + 预置快照 → 新 App 构造后「自动进入 pty 恢复态」
 *      （mode=pty, captured=true，且快照被消费清除）；本测试不调用 content()，故不起真实 pty。
 *   4. 反向：persistSession=false 时即便存在快照也不恢复（默认关闭，隐私优先）。
 *
 * 运行：php tests/session_unit.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\App;
use App\Core\ConfigStore;
use App\Terminal\SessionStore;
use App\Terminal\TerminalBuffer;
use App\Terminal\Vt100Emulator;

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

// 隔离配置：默认开启持久化
$cfg = tempnam(sys_get_temp_dir(), 'vc_sscfg');
file_put_contents($cfg, (string) json_encode(['persistSession' => true]));
putenv('VICECODE_CONFIG=' . $cfg);

echo "== 1. Vt100Emulator 导出/导入纯文本往返 ==\n";
$emu = new Vt100Emulator(80, 24);
$emu->write("hello world\r\n");
$emu->write("中文宽字测试 line\r\n");
$emu->write("echo MARK_A\r\n");
for ($i = 0; $i < 30; $i++) {            // 超 24 行，制造 scrollback
    $emu->write("scroll line $i\r\n");
}
$text = $emu->exportText();
check(str_contains($text, 'hello world'), 'exportText 含普通行');
check(str_contains($text, '中文宽字测试 line'), 'exportText 含 CJK 宽字行（占位格已跳过）');
check(str_contains($text, 'MARK_A'), 'exportText 含命令回声');
check(!str_contains($text, "\x1b"), 'exportText 无 ESC 残留');

$emu2 = new Vt100Emulator(80, 24);
$emu2->importText($text);
$text2 = $emu2->exportText();
check(str_contains($text2, 'hello world'), 'import→export 往返：普通行仍在');
check(str_contains($text2, '中文宽字测试 line'), 'import→export 往返：CJK 行仍在');
check(str_contains($text2, 'MARK_A'), 'import→export 往返：命令回声仍在');
check(!str_contains($text2, "\x1b"), 'import→export 往返：仍无 ESC');
check(str_contains($emu2->exportText(), 'scroll line 29'), 'import→export 往返：scrollback 行保留');

echo "\n== 2. SessionStore 存 / 取 / 清 往返 ==\n";
SessionStore::clear();
$ok = SessionStore::save(['cwd' => '/tmp/work', 'text' => $text, 'savedAt' => 123]);
check($ok, 'SessionStore::save 成功');
$snap = SessionStore::load();
check($snap !== null, 'SessionStore::load 非 null');
check(($snap['cwd'] ?? null) === '/tmp/work', 'load 还原 cwd');
check(($snap['text'] ?? null) === $text, 'load 还原 text 完全一致');
check(SessionStore::clear(), 'SessionStore::clear 成功');
check(SessionStore::load() === null, 'clear 后 load 返回 null');

echo "\n== 3. App 层恢复接线（不起真实 pty）==\n";
SessionStore::clear();
SessionStore::save(['cwd' => '/tmp/restore_cwd', 'text' => "RESTORE_MARK_X\r\necho done\r\n", 'savedAt' => time()]);
$app = new App();   // 构造末尾 maybeRestore() 应加载快照、进入 pty 恢复态
check($app->terminal->mode === 'pty', 'App 构造后：开启且存在快照 → mode=pty');
check($app->terminal->captured === true, 'App 构造后：进入捕获恢复态 captured=true');
check(SessionStore::load() === null, '恢复后快照已「消费」（文件清除），不会重复恢复');

echo "\n== 4. 默认关闭：persistSession=false 不恢复 ==\n";
$cfg2 = tempnam(sys_get_temp_dir(), 'vc_sscfg2');
file_put_contents($cfg2, (string) json_encode(['persistSession' => false]));
putenv('VICECODE_CONFIG=' . $cfg2);
// 在 cfg2 名下预置一份快照
SessionStore::save(['cwd' => '/tmp/x', 'text' => 'Y', 'savedAt' => 1]);
$app2 = new App();
check($app2->terminal->mode === 'runner', 'persistSession=false：即使存在快照也不恢复，mode 仍为 runner');

echo "\n== 5. 彩色网格单元格序列化（v2）==\n";
$ce = new Vt100Emulator(20, 3);
$ce->write("\x1b[1;31mRED\x1b[0m \x1b[4;32mGRN\x1b[0m\r\n");
$ce->write("中文宽字\r\n");
$cells = $ce->exportCells();
check(isset($cells['scrollback'], $cells['screen']), 'exportCells 返回 scrollback + screen 两段');
$row0 = $cells['screen'][0];
check($row0[0][0] === 'R' && $row0[0][2] === 1, '第0行首格 RED：字符 + 前景红(索引1)');
check(($row0[0][4] & Vt100Emulator::FLAG_BOLD) !== 0, '首格 bold 修饰标志保留');
check($row0[4][0] === 'G' && $row0[4][2] === 2, '空格后 GRN：字符 + 前景绿(索引2)');
check(($row0[4][4] & Vt100Emulator::FLAG_UNDERLINE) !== 0, 'GRN 下划线修饰标志保留');
$row1 = $cells['screen'][1];
check($row1[0][0] === '中' && $row1[0][1] == 0, 'CJK 左格为「中」');
check($row1[1][1] == 1, 'CJK 右占位格 wide=1');

$ce2 = new Vt100Emulator(20, 3);
$ce2->importCells($cells);
$back = $ce2->exportCells();
$b0 = $back['screen'][0];
check($b0[0][0] === 'R' && $b0[0][2] === 1 && ($b0[0][4] & Vt100Emulator::FLAG_BOLD) !== 0,
    'import→export 往返：RED 字符 + 红 + 粗体一致');
check($b0[4][0] === 'G' && $b0[4][2] === 2 && ($b0[4][4] & Vt100Emulator::FLAG_UNDERLINE) !== 0,
    'import→export 往返：GRN 字符 + 绿 + 下划线一致');
$b1 = $back['screen'][1];
check($b1[0][0] === '中' && $b1[1][1] == 1, 'import→export 往返：CJK 宽字占位格一致');

SessionStore::clear();
$okc = SessionStore::save(['cwd' => '/tmp/color', 'cells' => $cells, 'savedAt' => 456]);
check($okc, 'SessionStore::save 含 cells 成功');
$snc = SessionStore::load();
check($snc !== null && isset($snc['cells']), 'load 还原含 cells');
check(isset($snc['cells']['screen'][0][0]) && $snc['cells']['screen'][0][0] === $cells['screen'][0][0], 'load 还原的 cells 首格一致');
check(($snc['cwd'] ?? null) === '/tmp/color', 'load 还原 cwd');
SessionStore::clear();

echo "\n== 6. runner 模式 scrollback 持久化（viewport 无关）==\n";
// 回到 persistSession=true 的隔离配置（section 4 切去了 cfg2）
putenv('VICECODE_CONFIG=' . $cfg);
SessionStore::clear();

// 6a. TerminalBuffer exportLines / loadLines 往返：text + err 标记都须保留
$src = new TerminalBuffer();
$src->append("normal stdout line\n", false);
$src->append("error message\n", true);
$exp = $src->exportLines();
check($exp[0][0] === 'normal stdout line' && $exp[0][1] === 0, 'exportLines[0]：stdout 文本 + err=0');
check($exp[1][0] === 'error message' && $exp[1][1] === 1, 'exportLines[1]：stderr 文本 + err=1');

$back = new TerminalBuffer();
$back->loadLines($exp);
$all = $back->all();
check(count($all) === 2, 'loadLines 后行数一致（无丢失 / 无重复）');
check($all[0]['text'] === 'normal stdout line' && $all[0]['err'] === false, 'loadLines[0] stdout 文本一致');
check($all[1]['text'] === 'error message' && $all[1]['err'] === true, 'loadLines[1] stderr 标记一致（串色防护保留）');

// 6b. SessionStore runner 模式存 / 取：mode + lines
SessionStore::clear();
$okr = SessionStore::save([
    'cwd' => '/tmp/runner_cwd',
    'mode' => 'runner',
    'lines' => $exp,
    'savedAt' => 789,
]);
check($okr, 'SessionStore::save 含 runner 模式 lines 成功');
$snr = SessionStore::load();
check($snr !== null && ($snr['mode'] ?? null) === 'runner', 'load 还原 mode=runner');
check(isset($snr['lines']) && $snr['lines'] === $exp, 'load 还原 lines 完全一致');
check(($snr['cwd'] ?? null) === '/tmp/runner_cwd', 'load 还原 cwd');

// 6c. App 层恢复接线：预置 runner 快照 → 新 App 构造后缓冲被灌入、mode 仍为 runner
SessionStore::clear();
SessionStore::save([
    'cwd' => '/tmp/runner_restore',
    'mode' => 'runner',
    'lines' => [['hello from runner', 0], ['boom', 1]],
    'savedAt' => time(),
]);
$appR = new App();   // 构造末尾 maybeRestore() 应直接灌入缓冲（viewport 无关，无首帧延迟）
check($appR->terminal->mode === 'runner', 'App 构造后：runner 快照恢复 → mode 仍为 runner（不误入 pty）');
$restored = $appR->terminal->buffer()->all();
check(count($restored) === 2, 'App 构造后：runner 缓冲被灌入 2 行');
check($restored[0]['text'] === 'hello from runner' && $restored[0]['err'] === false, 'runner 缓冲[0] stdout 一致');
check($restored[1]['text'] === 'boom' && $restored[1]['err'] === true, 'runner 缓冲[1] stderr 一致');
check($appR->terminal->cwd() === '/tmp/runner_restore', 'App 构造后：runner 恢复 cwd 一致');
check(SessionStore::load() === null, 'runner 恢复后快照已「消费」清除（不会重复恢复）');
SessionStore::clear();

// 6d. 恢复 runner 快照后若本次会话未真正跑命令就退出，不应把「自动回落横幅」误存
//     （对应 pty_session 运行 B：从 pty 退出回落 runner 后干净退出，快照仍应视作已消费）
SessionStore::clear();
SessionStore::save([
    'cwd' => '/tmp/runner_gate',
    'mode' => 'runner',
    'lines' => [['restored line', 0]],
    'savedAt' => time(),
]);
$appG = new App();           // 构造时消费快照、灌入缓冲（ranInRunner=false）
$appG->terminal->shutdown(); // 等同干净退出；runner 模式但本次未跑命令 → 不落盘
check(!is_file(SessionStore::path()), '恢复 runner 快照后未跑命令即退出：不误存（破坏「已消费」不变量的回归钉）');
SessionStore::clear();

// 清理隔离文件（含各自目录下的快照）
@unlink(dirname($cfg) . '/' . SessionStore::FILE_NAME);
@unlink(dirname($cfg2) . '/' . SessionStore::FILE_NAME);
@unlink($cfg);
@unlink($cfg2);

echo $failed ? "\nRESULT: FAIL\n" : "\nRESULT: PASS\n";
exit($failed ? 1 : 0);

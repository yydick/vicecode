<?php
declare(strict_types=1);

namespace App\Git;

use App\App;

/**
 * GIT 面板状态 + 异步刷新。
 *
 * 状态归这个模型自己；SidebarPanel 的 GIT tab 与 StatusBarPanel 只读取它。
 * refresh() 在协程里跑 git（status / log / branch），跑完回写——
 * 主渲染循环不阻塞，列表在命令返回后自然刷新（对齐 M1 协程非阻塞的纪律）。
 *
 * 子视图：0=status 列表，1=log 列表（侧栏窄，无法同屏，用按键切换）。
 */
final class GitModel
{
    /** 0=status 1=log */
    public int $subView = 0;

    /** @var GitFileStatus[] */
    public array $status = [];

    /** @var GitCommit[] */
    public array $log = [];

    public string $branch = '';
    public bool $loading = false;
    public ?string $error = null;

    /** 当前子视图内的选中行 */
    public int $selIdx = 0;

    /** 本地分支列表（分支切换下拉用） */
    public array $branches = [];

    /** 分支切换下拉是否展开 */
    public bool $branchDropdownOpen = false;

    /** 分支列表内选中行 */
    public int $branchSelIdx = 0;

    /** 提交信息输入框内容（顶部输入框 + Commit 按钮方案，非模态提示） */
    public string $commitMsg = '';

    /** Commit 按钮下拉菜单是否展开（提交/提交变更/提交和推送/提交和同步） */
    public bool $dropdownOpen = false;

    private bool $inRepo = true;

    /** 供测试注入「是否在仓库内」状态（渲染占位用） */
    public function setInRepo(bool $v): void
    {
        $this->inRepo = $v;
    }

    public function __construct(private App $shell)
    {
    }

    /** 当前是否处于 git 仓库内（用于占位提示） */
    public function inRepo(): bool
    {
        return $this->inRepo;
    }

    /** 当前仓库根目录（git 命令的工作目录） */
    private function cwd(): string
    {
        return getcwd() ?: '.';
    }

    /**
     * 触发一次异步刷新：status + log + branch 三并发（各自 \go，互不影响）。
     * 已 loading 时不重复起，避免 rapid Tab 切换刷爆。
     */
    public function refresh(): void
    {
        if ($this->loading) {
            return;
        }
        $this->loading = true;
        $this->error = null;

        \go(function (): void {
            $cwd = $this->cwd();
            $branchR = GitClient::exec($cwd, ['rev-parse', '--abbrev-ref', 'HEAD']);
            if ($branchR['code'] !== 0) {
                // 不在仓库内：标记占位，停止后续刷新
                $this->inRepo = false;
                $this->loading = false;
                return;
            }
            $this->inRepo = true;
            $this->branch = GitClient::parseBranch($branchR['output']);

            $statusR = GitClient::exec($cwd, ['status', '--porcelain']);
            if ($statusR['code'] === 0) {
                $this->status = GitClient::parseStatusPorcelain($statusR['output']);
            }

            $logR = GitClient::exec($cwd, ['log', '--oneline', '-n', '50',
                '--pretty=format:%h|%an|%ar|%s']);
            if ($logR['code'] === 0) {
                $this->log = GitClient::parseLog($logR['output']);
            }

            // 本地分支列表（分支切换下拉用），随主刷新一起拉取
            $brR = GitClient::exec($cwd, ['branch', '--format=%(refname:short)']);
            if ($brR['code'] === 0) {
                $this->branches = GitClient::parseBranches($brR['output']);
            }

            $this->loading = false;
        });
    }

    /** 仅刷新分支名（分支切换后由操作回调调用） */
    public function refreshBranch(): void
    {
        \go(function (): void {
            $r = GitClient::exec($this->cwd(), ['rev-parse', '--abbrev-ref', 'HEAD']);
            if ($r['code'] === 0) {
                $this->branch = GitClient::parseBranch($r['output']);
            }
        });
    }

    // ── R6 分支切换：列出本地分支 + 切换 ──

    /** 拉取本地分支列表（分支切换下拉打开时调用） */
    public function refreshBranches(): void
    {
        \go(function (): void {
            $r = GitClient::exec($this->cwd(), ['branch', '--format=%(refname:short)']);
            if ($r['code'] === 0) {
                $this->branches = GitClient::parseBranches($r['output']);
                // 选中行默认定位到当前分支
                foreach ($this->branches as $i => $b) {
                    if ($b === $this->branch) {
                        $this->branchSelIdx = $i;
                        break;
                    }
                }
            }
        });
    }

    /** 打开分支切换下拉（与提交下拉互斥）。分支列表已由 refresh() 随主刷新拉取。 */
    public function openBranchDropdown(): void
    {
        $this->dropdownOpen = false;
        $this->branchDropdownOpen = true;
        $this->branchSelIdx = 0;
        foreach ($this->branches as $i => $b) {
            if ($b === $this->branch) {
                $this->branchSelIdx = $i;
                break;
            }
        }
    }

    /** 分支列表内移动选中行 */
    public function moveBranchSelection(int $delta): void
    {
        $n = count($this->branches);
        if ($n === 0) {
            return;
        }
        $this->branchSelIdx = max(0, min($n - 1, $this->branchSelIdx + $delta));
    }

    /**
     * 切换到指定本地分支（git switch）。
     * 与当前分支相同、或为空则直接关闭下拉；失败提示错误（如未提交改动阻挡切换）。
     * 成功后刷新分支名 + status + 分支列表，并关闭下拉。
     */
    public function switchBranch(string $name): void
    {
        if ($name === '' || $name === $this->branch) {
            $this->branchDropdownOpen = false;
            return;
        }
        \go(function () use ($name): void {
            $r = GitClient::exec($this->cwd(), ['switch', $name]);
            if ($r['code'] !== 0) {
                $this->shell->setMessage($this->shell->t('git.switch_fail', ['msg' => $r['output'] ?: 'code ' . $r['code']]));
                return;
            }
            $this->shell->setMessage($this->shell->t('git.switch_done', ['branch' => $name]));
            $this->branchDropdownOpen = false;
            $this->refreshBranch();
            $this->refresh();
            $this->refreshBranches();
        });
    }

    public function toggleSubView(): void
    {
        $this->subView = $this->subView === 0 ? 1 : 0;
        $this->selIdx = 0;
    }

    public function moveSelection(int $delta): void
    {
        $n = $this->subView === 0 ? count($this->status) : count($this->log);
        if ($n === 0) {
            return;
        }
        $this->selIdx = max(0, min($n - 1, $this->selIdx + $delta));
    }

    /** 当前选中的 status 文件（status 子视图下） */
    public function selectedStatus(): ?GitFileStatus
    {
        return $this->status[$this->selIdx] ?? null;
    }

    /**
     * 把选中文件载入编辑器查看 diff（R4）。
     * 暂存区有改动看 --cached，否则看工作区；复用编辑器 Buffer（只读）。
     * 返回是否成功打开。
     */
    public function openDiff(): bool
    {
        $f = $this->selectedStatus();
        if ($f === null) {
            return false;
        }
        $staged = $f->indexStatus !== '' && $f->indexStatus !== '?';
        $args = $staged
            ? ['diff', '--cached', '--', $f->path]
            : ['diff', '--', $f->path];
        $r = GitClient::exec($this->cwd(), $args);
        if ($r['code'] !== 0 && $r['output'] === '') {
            $this->shell->setMessage($this->shell->t('git.no_diff'));
            return false;
        }
        $title = 'git:diff:' . $f->path;
        $this->shell->editor->openVirtual($title, $r['output'], true);
        return true;
    }

    // ── R5 操作：stage / unstage / commit(+push/sync)，可视化图标 + 输入框 + 按钮 ──

    /** 全部暂存（git add -A），完成后刷新 */
    public function stageAll(): void
    {
        \go(function (): void {
            $r = GitClient::exec($this->cwd(), ['add', '-A']);
            if ($r['code'] === 0) {
                $this->shell->setMessage($this->shell->t('git.stage_all'));
                $this->refresh();
            } else {
                $this->shell->setMessage($this->shell->t('git.op_fail', ['msg' => $r['output'] ?: 'code ' . $r['code']]));
            }
        });
    }

    /** 暂存选中文件（git add -- <path>） */
    public function stageSelected(): void
    {
        $f = $this->selectedStatus();
        if ($f === null) {
            return;
        }
        \go(function () use ($f): void {
            $r = GitClient::exec($this->cwd(), ['add', '--', $f->path]);
            if ($r['code'] === 0) {
                $this->shell->setMessage($this->shell->t('git.stage_file', ['path' => $f->path]));
                $this->refresh();
            } else {
                $this->shell->setMessage($this->shell->t('git.op_fail', ['msg' => $r['output'] ?: 'code ' . $r['code']]));
            }
        });
    }

    /**
     * 取消暂存选中文件（git restore --staged，保留工作区改动，安全）。
     * 对应「取消变更」：撤销该文件的暂存，不影响你的编辑。
     */
    public function unstageSelected(): void
    {
        $f = $this->selectedStatus();
        if ($f === null) {
            return;
        }
        \go(function () use ($f): void {
            $r = GitClient::exec($this->cwd(), ['restore', '--staged', '--', $f->path]);
            if ($r['code'] === 0) {
                $this->shell->setMessage($this->shell->t('git.unstage_file', ['path' => $f->path]));
                $this->refresh();
            } else {
                $this->shell->setMessage($this->shell->t('git.op_fail', ['msg' => $r['output'] ?: 'code ' . $r['code']]));
            }
        });
    }

    /**
     * 取消全部暂存（git restore --staged .，保留工作区改动，安全）。
     * 对应「clear all changed」：清空暂存区，改动仍在工作区。
     */
    public function unstageAll(): void
    {
        \go(function (): void {
            $r = GitClient::exec($this->cwd(), ['restore', '--staged', '.']);
            if ($r['code'] === 0) {
                $this->shell->setMessage($this->shell->t('git.unstage_all'));
                $this->refresh();
            } else {
                $this->shell->setMessage($this->shell->t('git.op_fail', ['msg' => $r['output'] ?: 'code ' . $r['code']]));
            }
        });
    }

    /** 打开选中文件（在编辑器里正常打开，非 diff）。对应行首「打开文件」图标。 */
    public function openFileSelected(): void
    {
        $f = $this->selectedStatus();
        if ($f === null) {
            return;
        }
        $this->shell->openFile($f->path);
    }

    /**
     * 请求丢弃选中文件的工作区改动（不可逆）：弹 y/n 确认框，确认后才真执行。
     * 对应「discard changes」——VSCode 默认语义就是丢弃未提交修改、不可恢复，
     * 故必须二次确认，避免误触把没提交的工作弄丢。
     */
    public function requestDiscardSelected(): void
    {
        $f = $this->selectedStatus();
        if ($f === null) {
            return;
        }
        $this->shell->requestDiscard($f->path);
    }

    /**
     * 丢弃某文件的工作区改动（确认后由 Lifecycle 调用）。
     *  - 未跟踪文件(?): git clean -f -- <path>（直接删除该文件，更不可逆）；
     *  - 其余: git restore -- <path>（工作区恢复到 HEAD/暂存版本，丢弃未保存编辑）。
     * 完成后刷新状态并提示。
     */
    public function discard(string $path): void
    {
        $f = null;
        foreach ($this->status as $s) {
            if ($s->path === $path) {
                $f = $s;
                break;
            }
        }
        $untracked = $f !== null && $f->worktree === '?' && $f->index === '?';
        $args = $untracked
            ? ['clean', '-f', '--', $path]
            : ['restore', '--', $path];
        $r = GitClient::exec($this->cwd(), $args);
        if ($r['code'] !== 0) {
            $this->shell->setMessage($this->shell->t('git.op_fail', ['msg' => $r['output'] ?: 'code ' . $r['code']]));
            return;
        }
        $this->shell->setMessage($this->shell->t('git.discard_file', ['path' => $path]));
        $this->refresh();
    }

    // 下拉菜单项 → 动作映射（顺序即渲染顺序）
    public const DROPDOWN = [
        'commit'        => '提交',
        'commitAll'     => '提交变更',
        'commitAndPush' => '提交和推送',
        'commitAndSync' => '提交和同步',
    ];

    /** 下拉菜单某项被点击：按 key 派发 */
    public function runDropdown(string $key): void
    {
        $msg = trim($this->commitMsg);
        if ($key === 'commit') {
            $this->commit($msg);
        } elseif ($key === 'commitAll') {
            $this->commitAll($msg);
        } elseif ($key === 'commitAndPush') {
            $this->commitAndPush($msg);
        } elseif ($key === 'commitAndSync') {
            $this->commitAndSync($msg);
        }
        $this->dropdownOpen = false;
    }

    private function doCommit(string $msg, bool $stageAll, ?callable $after): void
    {
        if ($msg === '') {
            $this->shell->setMessage($this->shell->t('git.commit_empty'));
            return;
        }
        \go(function () use ($msg, $stageAll, $after): void {
            $cwd = $this->cwd();
            if ($stageAll) {
                GitClient::exec($cwd, ['add', '-A']);
            }
            $r = GitClient::exec($cwd, ['commit', '-m', $msg]);
            if ($r['code'] !== 0) {
                $this->shell->setMessage($this->shell->t('git.op_fail', ['msg' => $r['output'] ?: 'code ' . $r['code']]));
                return;
            }
            $this->shell->setMessage($this->shell->t('git.committed'));
            $this->commitMsg = '';
            $this->refresh();
            if ($after !== null) {
                $after();
            }
        });
    }

    /** 提交（仅已暂存） */
    public function commit(string $msg): void
    {
        $this->doCommit($msg, false, null);
    }

    /** 提交变更（先 git add -A 再提交） */
    public function commitAll(string $msg): void
    {
        $this->doCommit($msg, true, null);
    }

    /** 提交并推送 */
    public function commitAndPush(string $msg): void
    {
        $this->doCommit($msg, false, fn () => $this->pushNow());
    }

    /** 提交并同步（pull --rebase 后 push） */
    public function commitAndSync(string $msg): void
    {
        $this->doCommit($msg, false, fn () => $this->syncNow());
    }

    private function pushNow(): void
    {
        $r = GitClient::exec($this->cwd(), ['push']);
        if ($r['code'] === 0) {
            $this->shell->setMessage($this->shell->t('git.push_done'));
        } else {
            $this->shell->setMessage($this->shell->t('git.push_fail', ['msg' => $r['output'] ?: 'code ' . $r['code']]));
        }
        $this->refreshBranch();
    }

    private function syncNow(): void
    {
        GitClient::exec($this->cwd(), ['pull', '--rebase']);
        $this->pushNow();
    }
}

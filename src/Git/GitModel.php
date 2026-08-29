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
}

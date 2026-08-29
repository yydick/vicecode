<?php
declare(strict_types=1);

namespace App\Git;

/**
 * 单个文件的 git 状态（来自 porcelain 一行）。
 *
 * indexStatus / workStatus 是原始单字符（' ' 表示无状态）。
 * category() 归并为一种主类别，供着色与排序用。
 */
final class GitFileStatus
{
    public function __construct(
        public string $path,
        public string $indexStatus,
        public string $workStatus,
        public ?string $oldPath = null,
    ) {
    }

    public function category(): string
    {
        // 未合并优先（U）
        if ($this->indexStatus === 'U' || $this->workStatus === 'U'
            || $this->indexStatus === 'A' && $this->workStatus === 'A'
            || $this->indexStatus === 'D' && $this->workStatus === 'D') {
            return GitClient::STATUS_CONFLICT;
        }
        // 未跟踪
        if ($this->indexStatus === '?' && $this->workStatus === '?') {
            return GitClient::STATUS_UNTRACKED;
        }
        // 已暂存（index 有状态且非空格、非未跟踪）
        if ($this->indexStatus !== '' && $this->indexStatus !== '?') {
            if ($this->indexStatus === 'R') {
                return GitClient::STATUS_RENAMED;
            }
            if ($this->indexStatus === 'D') {
                return GitClient::STATUS_DELETED;
            }
            return GitClient::STATUS_STAGED;
        }
        // 工作区改动
        if ($this->workStatus !== '') {
            if ($this->workStatus === 'D') {
                return GitClient::STATUS_DELETED;
            }
            return GitClient::STATUS_MODIFIED;
        }
        return GitClient::STATUS_MODIFIED;
    }

    /** 用于列表前缀的状态字母（取更「显著」的那个） */
    public function badge(): string
    {
        if ($this->indexStatus !== '' && $this->indexStatus !== '?') {
            return $this->indexStatus;
        }
        if ($this->workStatus !== '') {
            return $this->workStatus;
        }
        return ' ';
    }

    /** 显示用路径（重命名显示 old -> new） */
    public function displayPath(): string
    {
        if ($this->oldPath !== null) {
            return $this->oldPath . ' -> ' . $this->path;
        }
        return $this->path;
    }
}

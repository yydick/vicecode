<?php
declare(strict_types=1);

namespace App\Git;

/** 一条提交（来自 `git log --pretty=format:%h|%an|%ar|%s`）。 */
final class GitCommit
{
    public function __construct(
        public string $hash,
        public string $author,
        public string $relDate,
        public string $subject,
    ) {
    }
}

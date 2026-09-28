<?php
declare(strict_types=1);

namespace App\Ai;

use App\Core\CompletionItem;

/**
 * 内建的 `@文件` 路径补全 provider（AI 输入框）。
 *
 * 这是**补全框架的第一个真实用户**：它自己就是一个普通 provider，与将来插件提供的
 * AI 补全走同一条路（核心只认 CompletionItem，不关心候选从哪来）。
 *
 * 语义与 `AiPanel::expandAtRefs()` 对齐：`@` 后面跟的是**项目根的相对路径**，
 * 候选也只列项目根内的条目 —— 路径安全完全复用 `AiTools::resolve()`
 * （realpath + 根前缀校验），因此 `../` / symlink 出根一律不会出现在候选里。
 */
final class FileCompletion
{
    /** 单目录最多扫出多少条候选（状态层还会再截到 CompletionState::MAX_ITEMS） */
    private const SCAN_LIMIT = 50;

    private AiTools $tools;

    public function __construct(?AiTools $tools = null)
    {
        $this->tools = $tools ?? new AiTools();
    }

    /**
     * provider 回调：`fn(context, text, cursor, prefix): list<CompletionItem>`
     *
     * @return list<CompletionItem>
     */
    public function provide(string $context, string $text, int $cursor, string $prefix): array
    {
        if ($context !== 'ai_input' || !str_starts_with($prefix, '@')) {
            return [];
        }
        // 去掉 '@' 后的部分；再拆成「目录部分 + 正在输入的名字部分」
        $partial = mb_substr($prefix, 1);
        if (str_ends_with($partial, '/')) {
            $dir = rtrim($partial, '/');
            $base = '';
        } else {
            $pos = mb_strrpos($partial, '/');
            if ($pos === false) {
                $dir = '';
                $base = $partial;
            } else {
                $dir = mb_substr($partial, 0, $pos);
                $base = mb_substr($partial, $pos + 1);
            }
        }

        $abs = $this->tools->resolve($dir === '' ? '.' : $dir);
        if ($abs === null || !is_dir($abs)) {
            return [];
        }
        $names = @scandir($abs);
        if ($names === false) {
            return [];
        }
        sort($names, SORT_STRING);

        $dirs = [];
        $files = [];
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            // 正在输入的名字部分做前缀过滤（大小写不敏感，与命令面板的过滤手感一致）
            if ($base !== '' && mb_stripos($name, $base) !== 0) {
                continue;
            }
            $isDir = is_dir($abs . '/' . $name);
            $prefixPath = $dir === '' ? '' : $dir . '/';
            $item = new CompletionItem(
                label: $name . ($isDir ? '/' : ''),
                insert: '@' . $prefixPath . $name . ($isDir ? '/' : ''),
            );
            // 目录排在文件前（与资源管理器一致），各自按名排序（scandir 已排）
            if ($isDir) {
                $dirs[] = $item;
            } else {
                $files[] = $item;
            }
            if (count($dirs) + count($files) >= self::SCAN_LIMIT) {
                break;
            }
        }

        return array_merge($dirs, $files);
    }
}

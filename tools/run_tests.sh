#!/usr/bin/env bash
#
# ViceCode 全量测试跑批。
#
# ★ 判定依据：一律以每个测试自己的**退出码**为准，不解析输出文案。
#   两个原因（都真踩过，详见 docs/BUGFIXES.md P9）：
#   1. 测试名 `[OK] 无 Fatal / Uncaught / Warning` 里含 "Uncaught"/"Fatal" 字样，
#      用 grep 关键字粗筛会把通过**误判为失败**（一次误报 6 个 pty 测试）。
#   2. 各测试的通过文案不统一（"RESULT: PASS" / "全部通过" / "结论：…正常" 等），
#      无法统一解析；约一半的测试连 PASS 标记都没有。
#   而退出码是全体一致的约定：失败一律非 0（已逐个注入强制失败验证过）。
#
# 用法：
#   tools/run_tests.sh              # 全量
#   tools/run_tests.sh pty          # 只跑文件名含 pty 的
#   TIMEOUT=300 tools/run_tests.sh  # 调大单测超时（默认 120s）
#
# 退出码：全部通过 0；有失败 1。
set -uo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

TIMEOUT="${TIMEOUT:-120}"
FILTER="${1:-}"

pass=0
fail=0
failed_files=()
ran=0
started=$(date +%s)

for f in tests/*.php; do
    name=$(basename "$f")
    # 带 probe 的是探索性探针（m2_probe_runner / sse_probe*），不是测试，不参与验收
    case "$name" in *probe*) continue ;; esac
    if [ -n "$FILTER" ]; then
        case "$name" in *"$FILTER"*) ;; *) continue ;; esac
    fi

    log=$(mktemp)
    t0=$(date +%s)
    timeout "$TIMEOUT" php "$f" >"$log" 2>&1
    code=$?
    dur=$(( $(date +%s) - t0 ))
    ran=$((ran + 1))

    if [ "$code" -eq 0 ]; then
        pass=$((pass + 1))
        printf '  \033[32mPASS\033[0m %-30s %3ds\n' "$name" "$dur"
    else
        fail=$((fail + 1))
        failed_files+=("$name")
        if [ "$code" -eq 124 ]; then
            printf '  \033[31mFAIL\033[0m %-30s %3ds  (超时 %ss)\n' "$name" "$dur" "$TIMEOUT"
        else
            printf '  \033[31mFAIL\033[0m %-30s %3ds  (exit=%d)\n' "$name" "$dur" "$code"
        fi
        # 失败时回显末几行，省得再单独重跑一遍
        tail -15 "$log" | sed 's/^/         │ /'
    fi
    rm -f "$log"
done

total=$(( $(date +%s) - started ))
echo "────────────────────────────────────────────"
printf '共 %d 项：\033[32m通过 %d\033[0m，\033[31m失败 %d\033[0m（耗时 %ds）\n' "$ran" "$pass" "$fail" "$total"

if [ "$fail" -gt 0 ]; then
    echo "失败项：${failed_files[*]}"
    exit 1
fi
echo "全部通过"

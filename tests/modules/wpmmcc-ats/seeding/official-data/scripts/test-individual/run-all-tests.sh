#!/bin/bash
#
# 运行所有官方数据测试
#
# 按计划分组测试每个有官方数据的插件
#
# Usage: bash run-all-tests.sh [plan]
#   plan: A, B, C, D 或 all (默认)
#

set -e

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[0;33m'
BLUE='\033[0;34m'
NC='\033[0m'

SCRIPT_DIR=$(dirname "$0")
PLAN=${1:-all}

echo "=========================================="
echo "   官方数据单独测试"
echo "=========================================="
echo ""
echo "测试计划: $PLAN"
echo ""

# 结果统计
PASSED=0
FAILED=0
MANUAL=0

run_test() {
    local name=$1
    local script=$2
    local plan=$3

    echo ""
    echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${BLUE}测试: $name (Plan $plan)${NC}"
    echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"

    if [ -f "$SCRIPT_DIR/$script" ]; then
        if bash "$SCRIPT_DIR/$script"; then
            PASSED=$((PASSED + 1))
        else
            FAILED=$((FAILED + 1))
        fi
    else
        echo -e "${RED}脚本不存在: $script${NC}"
        FAILED=$((FAILED + 1))
    fi
}

# Plan A 测试
if [ "$PLAN" = "A" ] || [ "$PLAN" = "all" ]; then
    echo ""
    echo "=========================================="
    echo "   Plan A 官方数据测试"
    echo "=========================================="

    run_test "WooCommerce" "test-woocommerce.sh" "A"
    run_test "bbPress" "test-bbpress.sh" "A"
    run_test "Easy Digital Downloads" "test-edd.sh" "A"

    echo ""
    echo -e "${YELLOW}Plan A 手动导入插件:${NC}"
    echo "  - Academy LMS (academy-starter-templates)"
    echo "  - MasterStudy LMS (内置 Demo Import)"
    echo "  - BuddyPress (bp-default-data 插件)"
    MANUAL=$((MANUAL + 3))
fi

# Plan B 测试
if [ "$PLAN" = "B" ] || [ "$PLAN" = "all" ]; then
    echo ""
    echo "=========================================="
    echo "   Plan B 官方数据测试"
    echo "=========================================="

    run_test "Tutor LMS" "test-tutor.sh" "B"
fi

# Plan C 测试
if [ "$PLAN" = "C" ] || [ "$PLAN" = "all" ]; then
    echo ""
    echo "=========================================="
    echo "   Plan C 官方数据测试"
    echo "=========================================="

    run_test "Sensei LMS" "test-sensei.sh" "C"

    echo ""
    echo -e "${YELLOW}Plan C 说明:${NC}"
    echo "  - Sensei LMS 需要通过后台 CSV 导入"
    MANUAL=$((MANUAL + 1))
fi

# Plan D 测试
if [ "$PLAN" = "D" ] || [ "$PLAN" = "all" ]; then
    echo ""
    echo "=========================================="
    echo "   Plan D 官方数据测试"
    echo "=========================================="

    run_test "LearnPress" "test-learnpress.sh" "D"
    run_test "LifterLMS" "test-lifterlms.sh" "D"
fi

# 汇总
echo ""
echo "=========================================="
echo "   测试汇总"
echo "=========================================="
echo ""
echo -e "${GREEN}通过: $PASSED${NC}"
echo -e "${RED}失败: $FAILED${NC}"
echo -e "${YELLOW}需手动: $MANUAL${NC}"
echo ""

if [ "$FAILED" -eq 0 ]; then
    echo -e "${GREEN}✅ 所有自动测试通过${NC}"
else
    echo -e "${RED}❌ 有 $FAILED 个测试失败${NC}"
    exit 1
fi

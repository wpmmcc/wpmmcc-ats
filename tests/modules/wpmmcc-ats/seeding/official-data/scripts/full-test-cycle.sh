#!/bin/bash
#
# 完整测试周期：依次测试 Plan A → B → C → D
#
# 此脚本用于测试内容插件的数据填充，与 WPTSALL 翻译插件无关。
#
# Usage: bash full-test-cycle.sh [--quick]
#
# 选项:
#   --quick    快速模式（插件不卸载重装，只激活/停用）
#
# 示例:
#   bash full-test-cycle.sh          # 完整测试（卸载重装）
#   bash full-test-cycle.sh --quick  # 快速测试（仅切换激活）
#

set -e

# 颜色定义
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[0;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# 检查是否为快速模式
QUICK_MODE=false
if [ "$1" == "--quick" ]; then
    QUICK_MODE=true
    echo -e "${BLUE}快速模式: 插件不卸载重装，仅切换激活${NC}"
fi

# 路径定义
DEV=/Users/zhangxiao/wptsall-dev
WP=/usr/local/var/www
SCRIPT_DIR="$DEV/dev-tools/seeding/official-data/scripts"
SEEDING_DIR="$DEV/dev-tools/seeding"

cd $WP

echo "=========================================="
echo "   内容插件数据填充测试周期"
echo "=========================================="
echo ""
echo "测试计划: A → B → C → D"
echo "模式: $([ "$QUICK_MODE" = true ] && echo "快速切换" || echo "完整测试")"
echo ""
echo -e "${YELLOW}预计时间: $([ "$QUICK_MODE" = true ] && echo "约 12 分钟" || echo "约 40 分钟")${NC}"
echo ""

# 记录开始时间
START_TIME=$(date +%s)

# ==========================================
# 阶段 0: 环境准备（仅执行一次）
# ==========================================

echo ""
echo "=========================================="
echo "   阶段 0: 环境准备"
echo "=========================================="
echo ""

# 检查 WordPress Core 数据
POST_COUNT=$(wp post list --post_type=post,page --format=count 2>/dev/null)

if [ "$POST_COUNT" -lt 20 ]; then
    echo "填充 WordPress Core 数据..."
    wp import "$DEV/dev-tools/seeding/official-data/wordpress-core/themeunittestdata.wordpress.xml" \
        --authors=create
    echo -e "${GREEN}✅ WordPress Core 数据填充完成${NC}"
else
    echo -e "${GREEN}✅ WordPress Core 数据已存在 ($POST_COUNT posts)${NC}"
fi

# ==========================================
# 函数: 测试单个计划
# ==========================================

test_plan() {
    local PLAN=$1
    local IS_FIRST=${2:-false}

    echo ""
    echo "=========================================="
    echo "   测试 Plan $PLAN"
    echo "=========================================="
    echo ""

    if [ "$QUICK_MODE" = true ] && [ "$IS_FIRST" = false ]; then
        # 快速模式：只激活插件
        echo "Step 1/5: 激活 Plan $PLAN 插件（快速模式）..."
        bash "$SCRIPT_DIR/activate-plan.sh" "$PLAN"
    else
        # 完整模式：卸载并重装
        if [ "$IS_FIRST" = false ]; then
            echo "Step 1/5: 卸载 Plan $PLAN 插件..."
            bash "$SCRIPT_DIR/uninstall-plan.sh" "$PLAN"
        fi

        echo "Step 2/5: 安装 Plan $PLAN 插件..."
        bash "$SCRIPT_DIR/install-plan.sh" "$PLAN"
    fi

    # Step 3: 导入官方数据
    echo "Step 3/5: 导入 Plan $PLAN 官方数据..."
    bash "$SCRIPT_DIR/import-by-plan.sh" "$PLAN"

    # Step 4: 填充自定义数据
    echo "Step 4/5: 填充 Plan $PLAN 自定义数据..."
    if [ -f "$SEEDING_DIR/seed-content.php" ]; then
        wp eval-file "$SEEDING_DIR/seed-content.php" "$PLAN" 2>/dev/null || echo -e "${YELLOW}⚠️  自定义数据填充跳过${NC}"
    else
        echo -e "${YELLOW}⚠️  seed-content.php 不存在，跳过${NC}"
    fi

    # Step 5: 运行验证测试
    echo "Step 5/5: 运行验证测试..."
    if [ -f "$SEEDING_DIR/run-plan.php" ]; then
        wp eval-file "$SEEDING_DIR/run-plan.php" "$PLAN" verify 2>/dev/null || echo -e "${YELLOW}⚠️  验证脚本执行失败${NC}"
    else
        echo -e "${YELLOW}⚠️  run-plan.php 不存在，跳过验证${NC}"
    fi

    echo ""
    echo -e "${GREEN}✅ Plan $PLAN 测试完成${NC}"
}

# ==========================================
# 函数: 切换计划
# ==========================================

switch_plan() {
    local OLD_PLAN=$1
    local NEW_PLAN=$2

    echo ""
    echo "=========================================="
    echo "   切换: Plan $OLD_PLAN → Plan $NEW_PLAN"
    echo "=========================================="
    echo ""

    # Step 1: 停用旧计划
    echo "停用 Plan $OLD_PLAN..."
    bash "$SCRIPT_DIR/deactivate-plan.sh" "$OLD_PLAN"

    # Step 2: 测试新计划
    test_plan "$NEW_PLAN" false
}

# ==========================================
# 执行测试周期
# ==========================================

# 测试 Plan A（首个计划）
test_plan "A" true

# 切换到 Plan B
switch_plan "A" "B"

# 切换到 Plan C
switch_plan "B" "C"

# 切换到 Plan D
switch_plan "C" "D"

# ==========================================
# 完成
# ==========================================

END_TIME=$(date +%s)
DURATION=$((END_TIME - START_TIME))
MINUTES=$((DURATION / 60))
SECONDS=$((DURATION % 60))

echo ""
echo "=========================================="
echo "   完整测试周期完成"
echo "=========================================="
echo ""
echo -e "${GREEN}✅ 所有计划测试完成${NC}"
echo ""
echo "统计信息:"
echo "  • 测试计划: 4 个 (A, B, C, D)"
echo "  • 总耗时: ${MINUTES}分${SECONDS}秒"
echo "  • 模式: $([ "$QUICK_MODE" = true ] && echo "快速切换" || echo "完整测试")"
echo ""
echo "查看测试结果:"
echo "  cat $SEEDING_DIR/plans/A/results/verify-result.json"
echo "  cat $SEEDING_DIR/plans/B/results/verify-result.json"
echo "  cat $SEEDING_DIR/plans/C/results/verify-result.json"
echo "  cat $SEEDING_DIR/plans/D/results/verify-result.json"

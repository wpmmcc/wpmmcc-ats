#!/bin/bash
#
# 激活指定计划的所有插件（插件已安装，快速激活）
#
# Usage: bash activate-plan.sh <A|B|C|D>
#
# 示例:
#   bash activate-plan.sh A    # 激活 Plan A 的 20 个插件
#   bash activate-plan.sh B    # 激活 Plan B 的 18 个插件
#
# 注意: 此脚本假设插件已经安装，仅激活。如果插件未安装，请使用 install-plan.sh
#

set -e

# 颜色定义
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[0;33m'
NC='\033[0m'

# 检查参数
if [ $# -eq 0 ]; then
    echo -e "${RED}错误: 缺少计划参数${NC}"
    echo "用法: bash activate-plan.sh <A|B|C|D>"
    exit 1
fi

PLAN=$1

# 验证计划参数
if [[ ! "$PLAN" =~ ^[A-D]$ ]]; then
    echo -e "${RED}错误: 无效的计划 '$PLAN'${NC}"
    echo "有效值: A, B, C, D"
    exit 1
fi

echo "=========================================="
echo "   激活 Plan $PLAN 插件"
echo "=========================================="
echo ""

cd /usr/local/var/www

# 定义各计划的插件列表（兼容 macOS 默认 bash 3.x）
PLAN_A_PLUGINS="woocommerce bbpress academy masterstudy-lms-learning-management-system \
easy-digital-downloads classified-listing easy-property-listings cooked \
envira-gallery-lite site-reviews tablepress testimonial-free \
wordpress-seo advanced-custom-fields contact-form-7 buddypress \
easy-appointments simply-schedule-appointments powerpress redirection"

PLAN_B_PLUGINS="ecwid-shopping-cart directorist the-events-calendar wp-job-manager tutor \
essential-real-estate asgaros-forum delicious-recipes foogallery reviews-feed \
portfolio-post-type all-in-one-seo-pack meta-box ninja-forms \
paid-member-subscriptions booking fluent-booking seriously-simple-podcasting"

PLAN_C_PLUGINS="wp-easycart wpforo sensei-lms estatik wp-recipe-maker \
simple-job-board ultimate-faqs wp-customer-reviews seo-by-rank-math pods \
restrict-content bookly-responsive-appointment-booking-tool podcast-player"

PLAN_D_PLUGINS="storeengine learnpress lifterlms hivepress geodirectory forumwp \
propertyhive events-manager wp-job-openings give simple-membership \
ameliabooking podlove-podcasting-plugin-for-wordpress"

# 获取当前计划的插件列表
case $PLAN in
    A) PLUGINS="$PLAN_A_PLUGINS" ;;
    B) PLUGINS="$PLAN_B_PLUGINS" ;;
    C) PLUGINS="$PLAN_C_PLUGINS" ;;
    D) PLUGINS="$PLAN_D_PLUGINS" ;;
    *) PLUGINS="" ;;
esac

if [ -z "$PLUGINS" ]; then
    echo -e "${RED}错误: Plan $PLAN 没有定义插件列表${NC}"
    exit 1
fi

# 统计
TOTAL=$(echo $PLUGINS | wc -w | tr -d ' ')
SUCCESS=0
ALREADY_ACTIVE=0
NOT_INSTALLED=0
FAILED=0

echo "计划激活 $TOTAL 个插件"
echo ""

# 激活插件
for plugin in $PLUGINS; do
    echo -n "激活 $plugin ... "

    # 检查插件是否已安装
    if ! wp plugin is-installed $plugin 2>/dev/null; then
        echo -e "${RED}✗ 未安装${NC}"
        NOT_INSTALLED=$((NOT_INSTALLED + 1))
        continue
    fi

    # 检查是否已激活
    if wp plugin is-active $plugin 2>/dev/null; then
        echo -e "${YELLOW}已激活${NC}"
        ALREADY_ACTIVE=$((ALREADY_ACTIVE + 1))
        continue
    fi

    # 激活插件
    if wp plugin activate $plugin --quiet 2>/dev/null; then
        echo -e "${GREEN}✓ 成功${NC}"
        SUCCESS=$((SUCCESS + 1))
    else
        echo -e "${RED}✗ 失败${NC}"
        FAILED=$((FAILED + 1))
    fi
done

echo ""
echo "=========================================="
echo "   激活完成"
echo "=========================================="
echo "总计: $TOTAL"
echo -e "${GREEN}成功: $SUCCESS${NC}"
echo -e "${YELLOW}已激活: $ALREADY_ACTIVE${NC}"
echo -e "${RED}未安装: $NOT_INSTALLED${NC}"
echo -e "${RED}失败: $FAILED${NC}"
echo ""

if [ $NOT_INSTALLED -gt 0 ]; then
    echo -e "${YELLOW}⚠️  有 $NOT_INSTALLED 个插件未安装，请先运行 install-plan.sh $PLAN${NC}"
    exit 1
fi

if [ $FAILED -gt 0 ]; then
    echo -e "${YELLOW}⚠️  部分插件激活失败${NC}"
    exit 1
fi

echo -e "${GREEN}✅ Plan $PLAN 的所有插件已激活${NC}"

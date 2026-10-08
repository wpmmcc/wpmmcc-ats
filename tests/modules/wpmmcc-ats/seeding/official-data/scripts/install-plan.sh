#!/bin/bash
#
# 安装并激活指定计划的所有插件
#
# Usage: bash install-plan.sh <A|B|C|D>
#
# 示例:
#   bash install-plan.sh A    # 安装 Plan A 的 20 个插件
#   bash install-plan.sh B    # 安装 Plan B 的 18 个插件
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
    echo "用法: bash install-plan.sh <A|B|C|D>"
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
echo "   安装 Plan $PLAN 插件"
echo "=========================================="
echo ""

cd /usr/local/var/www

# 定义各计划的插件列表
declare -A PLAN_PLUGINS

PLAN_PLUGINS[A]="woocommerce bbpress academy masterstudy-lms-learning-management-system \
easy-digital-downloads classified-listing easy-property-listings cooked \
envira-gallery-lite site-reviews tablepress testimonial-free \
wordpress-seo advanced-custom-fields contact-form-7 buddypress \
easy-appointments simply-schedule-appointments powerpress redirection"

PLAN_PLUGINS[B]="ecwid-shopping-cart directorist the-events-calendar wp-job-manager tutor \
essential-real-estate asgaros-forum delicious-recipes foogallery reviews-feed \
portfolio-post-type all-in-one-seo-pack meta-box ninja-forms \
paid-member-subscriptions booking fluent-booking seriously-simple-podcasting"

PLAN_PLUGINS[C]="wp-easycart wpforo sensei-lms estatik wp-recipe-maker \
simple-job-board ultimate-faqs wp-customer-reviews seo-by-rank-math pods \
restrict-content bookly-responsive-appointment-booking-tool podcast-player"

PLAN_PLUGINS[D]="storeengine learnpress lifterlms hivepress geodirectory forumwp \
propertyhive events-manager wp-job-openings give simple-membership \
ameliabooking podlove-podcasting-plugin-for-wordpress"

# 获取当前计划的插件列表
PLUGINS="${PLAN_PLUGINS[$PLAN]}"

if [ -z "$PLUGINS" ]; then
    echo -e "${RED}错误: Plan $PLAN 没有定义插件列表${NC}"
    exit 1
fi

# 统计
TOTAL=$(echo $PLUGINS | wc -w | tr -d ' ')
SUCCESS=0
FAILED=0
ALREADY_INSTALLED=0

echo "计划安装 $TOTAL 个插件"
echo ""

# 安装并激活插件
for plugin in $PLUGINS; do
    echo -n "安装 $plugin ... "

    # 检查插件是否已安装
    if wp plugin is-installed $plugin 2>/dev/null; then
        echo -e "${YELLOW}已安装${NC}"
        ALREADY_INSTALLED=$((ALREADY_INSTALLED + 1))

        # 如果已安装但未激活，激活它
        if ! wp plugin is-active $plugin 2>/dev/null; then
            wp plugin activate $plugin --quiet 2>/dev/null && echo -e "${GREEN}✓ 已激活${NC}" || echo -e "${RED}✗ 激活失败${NC}"
        fi
    else
        # 安装并激活
        if wp plugin install $plugin --activate --quiet 2>/dev/null; then
            echo -e "${GREEN}✓ 成功${NC}"
            SUCCESS=$((SUCCESS + 1))
        else
            echo -e "${RED}✗ 失败${NC}"
            FAILED=$((FAILED + 1))
        fi
    fi
done

echo ""
echo "=========================================="
echo "   安装完成"
echo "=========================================="
echo "总计: $TOTAL"
echo -e "${GREEN}成功: $SUCCESS${NC}"
echo -e "${YELLOW}已存在: $ALREADY_INSTALLED${NC}"
echo -e "${RED}失败: $FAILED${NC}"
echo ""

if [ $FAILED -gt 0 ]; then
    echo -e "${YELLOW}⚠️  部分插件安装失败，请检查网络或插件名称${NC}"
    exit 1
fi

echo -e "${GREEN}✅ Plan $PLAN 的所有插件已安装并激活${NC}"

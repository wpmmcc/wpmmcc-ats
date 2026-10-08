#!/bin/bash
#
# 卸载指定计划的所有插件（触发 uninstall.php，完全清理数据）
#
# Usage: bash uninstall-plan.sh <A|B|C|D>
#
# 示例:
#   bash uninstall-plan.sh A    # 卸载 Plan A 的 20 个插件
#   bash uninstall-plan.sh B    # 卸载 Plan B 的 18 个插件
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
    echo "用法: bash uninstall-plan.sh <A|B|C|D>"
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
echo "   卸载 Plan $PLAN 插件"
echo "=========================================="
echo ""
echo -e "${YELLOW}⚠️  警告: 此操作将触发插件的 uninstall.php，完全清理所有数据${NC}"
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
NOT_INSTALLED=0
FAILED=0

echo "计划卸载 $TOTAL 个插件"
echo ""

# 卸载插件
for plugin in $PLUGINS; do
    echo -n "卸载 $plugin ... "

    # 检查插件是否已安装
    if ! wp plugin is-installed $plugin 2>/dev/null; then
        echo -e "${YELLOW}未安装${NC}"
        NOT_INSTALLED=$((NOT_INSTALLED + 1))
        continue
    fi

    # 卸载插件（--deactivate 先停用，--skip-delete 不删除文件以便重装）
    if wp plugin uninstall $plugin --deactivate --skip-delete --quiet 2>/dev/null; then
        echo -e "${GREEN}✓ 成功${NC}"
        SUCCESS=$((SUCCESS + 1))
    else
        echo -e "${RED}✗ 失败${NC}"
        FAILED=$((FAILED + 1))
    fi
done

echo ""
echo "=========================================="
echo "   卸载完成"
echo "=========================================="
echo "总计: $TOTAL"
echo -e "${GREEN}成功: $SUCCESS${NC}"
echo -e "${YELLOW}未安装: $NOT_INSTALLED${NC}"
echo -e "${RED}失败: $FAILED${NC}"
echo ""

if [ $FAILED -gt 0 ]; then
    echo -e "${YELLOW}⚠️  部分插件卸载失败${NC}"
    exit 1
fi

echo -e "${GREEN}✅ Plan $PLAN 的所有插件已卸载（数据已清理）${NC}"

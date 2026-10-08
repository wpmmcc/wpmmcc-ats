#!/bin/bash
#
# 检查 10 个插件的冲突情况
#
# Usage: bash check-conflicts.sh
#

set -e

echo "=========================================="
echo "   官方数据插件冲突检查"
echo "=========================================="
echo ""

cd /usr/local/var/www

# 颜色定义
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[0;33m'
NC='\033[0m'

echo "1. 检查已激活的插件..."
echo "----------------------------------------"

ACTIVE_PLUGINS=$(wp plugin list --status=active --field=name 2>/dev/null)

# 检查 LMS 插件冲突
LIFTERLMS_ACTIVE=$(echo "$ACTIVE_PLUGINS" | grep -c "lifterlms" || true)
SENSEI_ACTIVE=$(echo "$ACTIVE_PLUGINS" | grep -c "sensei-lms" || true)

if [ "$LIFTERLMS_ACTIVE" -gt 0 ] && [ "$SENSEI_ACTIVE" -gt 0 ]; then
    echo -e "${RED}❌ 冲突: LifterLMS 和 Sensei LMS 同时激活${NC}"
    echo "   两者都使用 'course' 和 'lesson' post_type"
    echo "   建议: 停用其中一个"
    echo ""
    CONFLICT=1
else
    echo -e "${GREEN}✅ LMS 插件无冲突${NC}"
fi

echo ""
echo "2. 检查已注册的 post_type..."
echo "----------------------------------------"

# 检查 course post_type
COURSE_COUNT=$(wp post-type list --format=csv 2>/dev/null | grep -c "^course," || true)

if [ "$COURSE_COUNT" -gt 0 ]; then
    echo -e "${YELLOW}⚠️  'course' post_type 已注册${NC}"
    wp post-type list --format=table 2>/dev/null | grep "course"
    echo ""
fi

echo ""
echo "3. 检查用户数量..."
echo "----------------------------------------"

USER_COUNT=$(wp user list --format=count 2>/dev/null)
echo "当前用户数: $USER_COUNT"

if [ "$USER_COUNT" -gt 50 ]; then
    echo -e "${YELLOW}⚠️  用户数较多，BuddyPress 生成用户可能有邮箱冲突${NC}"
fi

echo ""
echo "4. 检查数据量..."
echo "----------------------------------------"

POST_COUNT=$(wp post list --post_type=post,page --format=count 2>/dev/null)
PRODUCT_COUNT=$(wp post list --post_type=product --format=count 2>/dev/null || echo "0")
DOWNLOAD_COUNT=$(wp post list --post_type=download --format=count 2>/dev/null || echo "0")

echo "文章/页面: $POST_COUNT"
echo "WooCommerce 产品: $PRODUCT_COUNT"
echo "EDD 下载: $DOWNLOAD_COUNT"

echo ""
echo "5. 推荐的导入策略..."
echo "----------------------------------------"

if [ -z "${CONFLICT:-}" ]; then
    echo -e "${GREEN}✅ 无严重冲突，可以继续导入${NC}"
    echo ""
    echo "推荐顺序:"
    echo "  1. WordPress Core"
    echo "  2. WooCommerce"
    echo "  3. Easy Digital Downloads"
    echo "  4. bbPress"
    echo "  5. LearnPress (或其他 LMS)"
    echo "  6. BuddyPress (最后)"
else
    echo -e "${RED}❌ 发现冲突，请先解决后再导入${NC}"
fi

echo ""
echo "=========================================="
echo "检查完成"
echo "=========================================="

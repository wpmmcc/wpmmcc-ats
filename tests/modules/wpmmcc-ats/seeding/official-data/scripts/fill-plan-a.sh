#!/bin/bash
#
# Plan A 完整数据填充脚本
#
# 填充内容:
#   - WordPress Core (一次性)
#   - 官方数据 (6个插件)
#   - 自定义数据 (6个插件)
#
# Usage: bash fill-plan-a.sh
#

set -e

# 颜色定义
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[0;33m'
BLUE='\033[0;34m'
NC='\033[0m'

DEV=/Users/zhangxiao/wptsall-dev
WP=/usr/local/var/www
OFFICIAL_DATA=$DEV/dev-tools/seeding/official-data

cd $WP

echo "=========================================="
echo "   Plan A 数据填充"
echo "=========================================="
echo ""

# ==========================================
# 阶段 1: WordPress Core（检查）
# ==========================================

echo "=========================================="
echo "   阶段 1: WordPress Core"
echo "=========================================="
echo ""

POST_COUNT=$(wp post list --post_type=post,page --format=count 2>/dev/null)

if [ "$POST_COUNT" -lt 20 ]; then
    echo "填充 WordPress Core 数据..."
    wp import $OFFICIAL_DATA/wordpress-core/themeunittestdata.wordpress.xml --authors=create
    echo -e "${GREEN}✅ WordPress Core 数据填充完成${NC}"
else
    echo -e "${GREEN}✅ WordPress Core 数据已存在 ($POST_COUNT posts)${NC}"
fi

echo ""

# ==========================================
# 阶段 2: 官方数据填充
# ==========================================

echo "=========================================="
echo "   阶段 2: 官方数据（6个插件）"
echo "=========================================="
echo ""

OFFICIAL_SUCCESS=0
OFFICIAL_SKIP=0
OFFICIAL_MANUAL=0

# 1. WooCommerce
echo -n "1/6 WooCommerce ... "
if wp plugin is-active woocommerce 2>/dev/null; then
    PRODUCT_COUNT=$(wp post list --post_type=product --format=count 2>/dev/null || echo "0")
    if [ "$PRODUCT_COUNT" -eq 0 ]; then
        if wp wc product import $OFFICIAL_DATA/woocommerce/sample_products.csv --user=1 --quiet 2>/dev/null; then
            echo -e "${GREEN}✓ 成功${NC}"
            OFFICIAL_SUCCESS=$((OFFICIAL_SUCCESS + 1))
        else
            echo -e "${RED}✗ 失败${NC}"
        fi
    else
        echo -e "${YELLOW}已有 $PRODUCT_COUNT 个产品${NC}"
        OFFICIAL_SKIP=$((OFFICIAL_SKIP + 1))
    fi
else
    echo -e "${RED}插件未激活${NC}"
fi

# 2. Easy Digital Downloads
echo -n "2/6 Easy Digital Downloads ... "
if wp plugin is-active easy-digital-downloads 2>/dev/null; then
    DOWNLOAD_COUNT=$(wp post list --post_type=download --format=count 2>/dev/null || echo "0")
    if [ "$DOWNLOAD_COUNT" -eq 0 ]; then
        if wp import $OFFICIAL_DATA/easy-digital-downloads/sample-products-import.xml --authors=create --quiet 2>/dev/null; then
            echo -e "${GREEN}✓ 成功${NC}"
            OFFICIAL_SUCCESS=$((OFFICIAL_SUCCESS + 1))
        else
            echo -e "${RED}✗ 失败${NC}"
        fi
    else
        echo -e "${YELLOW}已有 $DOWNLOAD_COUNT 个下载项${NC}"
        OFFICIAL_SKIP=$((OFFICIAL_SKIP + 1))
    fi
else
    echo -e "${RED}插件未激活${NC}"
fi

# 3. bbPress
echo -n "3/6 bbPress ... "
if wp plugin is-active bbpress 2>/dev/null; then
    FORUM_COUNT=$(wp post list --post_type=forum --format=count 2>/dev/null || echo "0")
    if [ "$FORUM_COUNT" -eq 0 ]; then
        if [ -f "$OFFICIAL_DATA/downloads/bbpress/bbpress-sample-data.xml" ]; then
            if wp import $OFFICIAL_DATA/downloads/bbpress/bbpress-sample-data.xml --authors=create --quiet 2>/dev/null; then
                echo -e "${GREEN}✓ 成功${NC}"
                OFFICIAL_SUCCESS=$((OFFICIAL_SUCCESS + 1))
            else
                echo -e "${RED}✗ 失败${NC}"
            fi
        else
            echo -e "${YELLOW}数据文件不存在（需下载）${NC}"
        fi
    else
        echo -e "${YELLOW}已有 $FORUM_COUNT 个论坛${NC}"
        OFFICIAL_SKIP=$((OFFICIAL_SKIP + 1))
    fi
else
    echo -e "${RED}插件未激活${NC}"
fi

# 4. BuddyPress
echo -n "4/6 BuddyPress ... "
if wp plugin is-active buddypress 2>/dev/null && wp plugin is-active bp-default-data 2>/dev/null; then
    echo -e "${BLUE}需手动操作: 工具 → BP Default Data → Generate${NC}"
    OFFICIAL_MANUAL=$((OFFICIAL_MANUAL + 1))
else
    echo -e "${RED}插件未激活${NC}"
fi

# 5. Academy LMS
echo -n "5/6 Academy LMS ... "
if wp plugin is-active academy 2>/dev/null; then
    COURSE_COUNT=$(wp post list --post_type=academy_courses --format=count 2>/dev/null || echo "0")
    if [ "$COURSE_COUNT" -eq 0 ]; then
        if wp plugin is-active academy-starter-templates 2>/dev/null; then
            echo -e "${BLUE}需手动操作: Academy Starter → Import Demo${NC}"
            OFFICIAL_MANUAL=$((OFFICIAL_MANUAL + 1))
        else
            echo -e "${YELLOW}需先安装 academy-starter-templates 插件${NC}"
        fi
    else
        echo -e "${YELLOW}已有 $COURSE_COUNT 个课程${NC}"
        OFFICIAL_SKIP=$((OFFICIAL_SKIP + 1))
    fi
else
    echo -e "${RED}插件未激活${NC}"
fi

# 6. MasterStudy LMS
echo -n "6/6 MasterStudy LMS ... "
if wp plugin is-active masterstudy-lms-learning-management-system 2>/dev/null; then
    COURSE_COUNT=$(wp post list --post_type=stm-courses --format=count 2>/dev/null || echo "0")
    if [ "$COURSE_COUNT" -eq 0 ]; then
        echo -e "${BLUE}需手动操作: STM LMS → Demo Import${NC}"
        OFFICIAL_MANUAL=$((OFFICIAL_MANUAL + 1))
    else
        echo -e "${YELLOW}已有 $COURSE_COUNT 个课程${NC}"
        OFFICIAL_SKIP=$((OFFICIAL_SKIP + 1))
    fi
else
    echo -e "${RED}插件未激活${NC}"
fi

echo ""
echo "官方数据统计:"
echo -e "  ${GREEN}自动导入: $OFFICIAL_SUCCESS${NC}"
echo -e "  ${YELLOW}已存在: $OFFICIAL_SKIP${NC}"
echo -e "  ${BLUE}需手动: $OFFICIAL_MANUAL${NC}"
echo ""

# ==========================================
# 阶段 3: 自定义数据填充
# ==========================================

echo "=========================================="
echo "   阶段 3: 自定义数据（6个插件）"
echo "=========================================="
echo ""

echo "运行 seed-content.php..."
wp eval-file $DEV/tests/workflow/seed-content.php A

echo ""

# ==========================================
# 完成
# ==========================================

echo "=========================================="
echo "   填充完成"
echo "=========================================="
echo ""

echo "验证结果:"
echo "  bash $OFFICIAL_DATA/scripts/verify-plan-a.sh"
echo ""

echo "查看详细报告:"
echo "  wp eval-file $DEV/tests/workflow/show-plan-result.php A"

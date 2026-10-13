#!/bin/bash
#
# 导入指定计划的官方数据
#
# Usage: bash import-by-plan.sh <A|B|C|D>
#
# 示例:
#   bash import-by-plan.sh A    # 导入 Plan A 的官方数据（6个插件）
#   bash import-by-plan.sh B    # 导入 Plan B 的官方数据（1个插件）
#

set -e

# 颜色定义
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[0;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# 检查参数
if [ $# -eq 0 ]; then
    echo -e "${RED}错误: 缺少计划参数${NC}"
    echo "用法: bash import-by-plan.sh <A|B|C|D>"
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
echo "   导入 Plan $PLAN 官方数据"
echo "=========================================="
echo ""

# 切换到 WordPress 目录
cd /usr/local/var/www

# 数据目录
DATA_DIR="/Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data"

# 定义各计划的官方数据插件
case "$PLAN" in
    A)
        echo -e "${BLUE}Plan A 有 6 个插件的官方数据：${NC}"
        echo "  1. WooCommerce"
        echo "  2. bbPress"
        echo "  3. BuddyPress"
        echo "  4. Easy Digital Downloads"
        echo "  5. Academy LMS"
        echo "  6. MasterStudy LMS"
        echo ""

        # 1. WooCommerce
        echo -n "导入 WooCommerce 数据 ... "
        if [ -f "$DATA_DIR/woocommerce/sample_products.csv" ]; then
            wp plugin is-active woocommerce 2>/dev/null && \
            wp wc product import "$DATA_DIR/woocommerce/sample_products.csv" --user=1 --quiet 2>/dev/null && \
            echo -e "${GREEN}✓${NC}" || echo -e "${YELLOW}⚠️  跳过${NC}"
        else
            echo -e "${YELLOW}⚠️  文件不存在${NC}"
        fi

        # 2. bbPress
        echo -n "导入 bbPress 数据 ... "
        if [ -f "$DATA_DIR/downloads/bbpress/bbpress-sample-data.xml" ]; then
            wp plugin is-active bbpress 2>/dev/null && \
            wp import "$DATA_DIR/downloads/bbpress/bbpress-sample-data.xml" --authors=create --quiet 2>/dev/null && \
            echo -e "${GREEN}✓${NC}" || echo -e "${YELLOW}⚠️  跳过${NC}"
        else
            echo -e "${YELLOW}⚠️  文件不存在（请先下载）${NC}"
        fi

        # 3. BuddyPress（使用 BP Default Data 插件）
        echo -n "导入 BuddyPress 数据 ... "
        if wp plugin is-active bp-default-data 2>/dev/null && wp plugin is-active buddypress 2>/dev/null; then
            echo -e "${BLUE}(需手动操作: 工具 → BP Default Data → Generate)${NC}"
        else
            echo -e "${YELLOW}⚠️  插件未激活${NC}"
        fi

        # 4. Easy Digital Downloads
        echo -n "导入 EDD 数据 ... "
        if [ -f "$DATA_DIR/easy-digital-downloads/edd-sample-data.xml" ]; then
            wp plugin is-active easy-digital-downloads 2>/dev/null && \
            wp import "$DATA_DIR/easy-digital-downloads/edd-sample-data.xml" --authors=create --quiet 2>/dev/null && \
            echo -e "${GREEN}✓${NC}" || echo -e "${YELLOW}⚠️  跳过${NC}"
        else
            echo -e "${YELLOW}⚠️  文件不存在${NC}"
        fi

        # 5. Academy LMS（需手动安装 Starter Templates）
        echo -n "Academy LMS ... "
        if wp plugin is-active academy 2>/dev/null; then
            echo -e "${BLUE}(需手动操作: 安装 academy-starter-templates 插件并导入模板)${NC}"
        else
            echo -e "${YELLOW}⚠️  插件未激活${NC}"
        fi

        # 6. MasterStudy LMS（需手动操作）
        echo -n "MasterStudy LMS ... "
        if wp plugin is-active masterstudy-lms-learning-management-system 2>/dev/null; then
            echo -e "${BLUE}(需手动操作: 后台 → STM LMS → Demo Import)${NC}"
        else
            echo -e "${YELLOW}⚠️  插件未激活${NC}"
        fi
        ;;

    B)
        echo -e "${BLUE}Plan B 有 1 个插件的官方数据：${NC}"
        echo "  1. Tutor LMS"
        echo ""

        # 1. Tutor LMS
        echo -n"导入 Tutor LMS 数据 ... "
        if [ -f "$DATA_DIR/downloads/tutor-lms/tutor-sample-data.xml" ]; then
            wp plugin is-active tutor 2>/dev/null && \
            wp import "$DATA_DIR/downloads/tutor-lms/tutor-sample-data.xml" --authors=create --quiet 2>/dev/null && \
            echo -e "${GREEN}✓${NC}" || echo -e "${YELLOW}⚠️  跳过${NC}"
        else
            echo -e "${YELLOW}⚠️  文件不存在（请先下载）${NC}"
        fi
        ;;

    C)
        echo -e "${BLUE}Plan C 有 1 个插件的官方数据：${NC}"
        echo "  1. Sensei LMS"
        echo ""

        # 1. Sensei LMS
        echo -n "导入 Sensei LMS 数据 ... "
        if [ -f "$DATA_DIR/sensei-lms/sensei-sample-data.xml" ]; then
            wp plugin is-active sensei-lms 2>/dev/null && \
            wp import "$DATA_DIR/sensei-lms/sensei-sample-data.xml" --authors=create --quiet 2>/dev/null && \
            echo -e "${GREEN}✓${NC}" || echo -e "${YELLOW}⚠️  跳过${NC}"
        else
            echo -e "${YELLOW}⚠️  文件不存在${NC}"
        fi
        ;;

    D)
        echo -e "${BLUE}Plan D 有 2 个插件的官方数据：${NC}"
        echo "  1. LearnPress"
        echo "  2. LifterLMS"
        echo ""

        # 1. LearnPress
        echo -n "导入 LearnPress 数据 ... "
        if [ -f "$DATA_DIR/learnpress/learnpress-sample-data.xml" ]; then
            wp plugin is-active learnpress 2>/dev/null && \
            wp import "$DATA_DIR/learnpress/learnpress-sample-data.xml" --authors=create --quiet 2>/dev/null && \
            echo -e "${GREEN}✓${NC}" || echo -e "${YELLOW}⚠️  跳过${NC}"
        else
            echo -e "${YELLOW}⚠️  文件不存在${NC}"
        fi

        # 2. LifterLMS
        echo -n "导入 LifterLMS 数据 ... "
        if [ -f "$DATA_DIR/lifterlms/lifterlms-sample-data.json" ]; then
            wp plugin is-active lifterlms 2>/dev/null && \
            echo -e "${BLUE}(需手动操作: LifterLMS → Settings → Import/Export → Import from JSON)${NC}"
        else
            echo -e "${YELLOW}⚠️  文件不存在${NC}"
        fi
        ;;
esac

echo ""
echo "=========================================="
echo "   导入完成"
echo "=========================================="
echo ""
echo -e "${GREEN}✅ Plan $PLAN 的官方数据已导入${NC}"
echo ""
echo -e "${YELLOW}注意事项：${NC}"
echo "  1. BuddyPress 数据需要手动在后台生成"
echo "  2. Academy LMS 需要先安装 academy-starter-templates 插件"
echo "  3. MasterStudy LMS 需要在后台手动导入 Demo"
echo "  4. LifterLMS 需要在后台手动导入 JSON 文件"
echo "  5. 部分插件数据需要先下载（见 downloads/ 目录的 README）"

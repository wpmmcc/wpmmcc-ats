#!/bin/bash
#
# 更新官方数据
# 从插件目录重新复制最新的官方示例数据
#
# Usage: bash update-official-data.sh
#

set -e

# 路径配置
PLUGIN_DIR="/usr/local/var/www/wp-content/plugins"
DATA_DIR="/Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data"
OLD_DEMO_DATA="/Users/zhangxiao/wptsall-dev/dev-tools/seeding/demo-data"

# 颜色输出
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[0;33m'
RED='\033[0;31m'
NC='\033[0m'

echo "=========================================="
echo "   更新官方数据脚本"
echo "=========================================="
echo ""

# 确保目录存在
mkdir -p "$DATA_DIR"/{wordpress-core,woocommerce,easy-digital-downloads,learnpress,lifterlms,sensei-lms,bbpress,buddypress,the-events-calendar,downloads}

# ==========================================
# 1. WordPress Core
# ==========================================
echo -e "${BLUE}[1/9] WordPress Core${NC}"
if [ -f "$OLD_DEMO_DATA/themeunittestdata.wordpress.xml" ]; then
    cp "$OLD_DEMO_DATA/themeunittestdata.wordpress.xml" "$DATA_DIR/wordpress-core/"
    echo -e "${GREEN}  ✅ 已更新${NC}"
else
    echo -e "${YELLOW}  ⚠️  数据文件不存在，跳过${NC}"
fi
echo ""

# ==========================================
# 2. WooCommerce
# ==========================================
echo -e "${BLUE}[2/9] WooCommerce${NC}"
if [ -d "$PLUGIN_DIR/woocommerce/sample-data" ]; then
    cp "$PLUGIN_DIR/woocommerce/sample-data"/* "$DATA_DIR/woocommerce/" 2>/dev/null || true
    FILE_COUNT=$(ls -1 "$DATA_DIR/woocommerce" | wc -l)
    echo -e "${GREEN}  ✅ 已更新 ($FILE_COUNT 个文件)${NC}"
else
    echo -e "${YELLOW}  ⚠️  插件未安装或无 sample-data 目录${NC}"
fi
echo ""

# ==========================================
# 3. Easy Digital Downloads
# ==========================================
echo -e "${BLUE}[3/9] Easy Digital Downloads${NC}"
if [ -f "$PLUGIN_DIR/easy-digital-downloads/assets/sample-products-import.xml" ]; then
    cp "$PLUGIN_DIR/easy-digital-downloads/assets/sample-products-import.xml" "$DATA_DIR/easy-digital-downloads/"
    echo -e "${GREEN}  ✅ 已更新${NC}"
else
    echo -e "${YELLOW}  ⚠️  插件未安装或数据文件不存在${NC}"
fi
echo ""

# ==========================================
# 4. LearnPress
# ==========================================
echo -e "${BLUE}[4/9] LearnPress${NC}"
if [ -d "$PLUGIN_DIR/learnpress/dummy-data" ]; then
    cp "$PLUGIN_DIR/learnpress/dummy-data"/*.xml "$DATA_DIR/learnpress/" 2>/dev/null || true
    cp "$PLUGIN_DIR/learnpress/dummy-data"/*.txt "$DATA_DIR/learnpress/" 2>/dev/null || true
    FILE_COUNT=$(ls -1 "$DATA_DIR/learnpress" | wc -l)
    echo -e "${GREEN}  ✅ 已更新 ($FILE_COUNT 个文件)${NC}"
else
    echo -e "${YELLOW}  ⚠️  插件未安装或无 dummy-data 目录${NC}"
fi
echo ""

# ==========================================
# 5. LifterLMS
# ==========================================
echo -e "${BLUE}[5/9] LifterLMS${NC}"
if [ -f "$PLUGIN_DIR/lifterlms/sample-data/sample-course.json" ]; then
    cp "$PLUGIN_DIR/lifterlms/sample-data/sample-course.json" "$DATA_DIR/lifterlms/"
    echo -e "${GREEN}  ✅ 已更新${NC}"
else
    echo -e "${YELLOW}  ⚠️  插件未安装或数据文件不存在${NC}"
fi
echo ""

# ==========================================
# 6. Sensei LMS
# ==========================================
echo -e "${BLUE}[6/9] Sensei LMS${NC}"
if [ -d "$PLUGIN_DIR/sensei-lms/sample-data" ]; then
    cp "$PLUGIN_DIR/sensei-lms/sample-data"/*.csv "$DATA_DIR/sensei-lms/" 2>/dev/null || true
    FILE_COUNT=$(ls -1 "$DATA_DIR/sensei-lms" | wc -l)
    echo -e "${GREEN}  ✅ 已更新 ($FILE_COUNT 个文件)${NC}"
else
    echo -e "${YELLOW}  ⚠️  插件未安装或无 sample-data 目录${NC}"
fi
echo ""

# ==========================================
# 7. bbPress
# ==========================================
echo -e "${BLUE}[7/9] bbPress${NC}"
echo -e "${YELLOW}  ℹ️  bbPress 无官方示例数据，需要手动下载${NC}"
echo "  → https://codex.bbpress.org/importing-from-older-forum-software/"
echo ""

# ==========================================
# 8. BuddyPress
# ==========================================
echo -e "${BLUE}[8/9] BuddyPress${NC}"
echo -e "${YELLOW}  ℹ️  BuddyPress 使用 bp-default-data 插件生成数据${NC}"
echo "  → 无需复制文件"
echo ""

# ==========================================
# 9. The Events Calendar
# ==========================================
echo -e "${BLUE}[9/9] The Events Calendar${NC}"
if [ -d "$PLUGIN_DIR/the-events-calendar" ]; then
    # 检查是否有示例数据
    SAMPLE_FILES=$(find "$PLUGIN_DIR/the-events-calendar" -name "*sample*" -o -name "*demo*" 2>/dev/null | wc -l)
    if [ "$SAMPLE_FILES" -gt 0 ]; then
        find "$PLUGIN_DIR/the-events-calendar" -name "*sample*" -o -name "*demo*" 2>/dev/null | while read -r file; do
            cp "$file" "$DATA_DIR/the-events-calendar/" 2>/dev/null || true
        done
        echo -e "${GREEN}  ✅ 已更新${NC}"
    else
        echo -e "${YELLOW}  ℹ️  插件无内置示例数据，需要手动下载${NC}"
        echo "  → https://theeventscalendar.com/support/"
    fi
else
    echo -e "${YELLOW}  ⚠️  插件未安装${NC}"
fi
echo ""

# ==========================================
# 汇总
# ==========================================
echo "=========================================="
echo "   更新完成 - 文件统计"
echo "=========================================="
echo ""

for dir in wordpress-core woocommerce easy-digital-downloads learnpress lifterlms sensei-lms; do
    if [ -d "$DATA_DIR/$dir" ]; then
        COUNT=$(ls -1 "$DATA_DIR/$dir" 2>/dev/null | wc -l | xargs)
        if [ "$COUNT" -gt 0 ]; then
            echo "$dir: $COUNT 个文件"
        fi
    fi
done

echo ""
echo -e "${GREEN}✅ 官方数据更新完成！${NC}"
echo ""
echo "提示："
echo "  - 查看数据: ls -lh $DATA_DIR/*/"
echo "  - 导入数据: bash $DATA_DIR/import-all.sh"

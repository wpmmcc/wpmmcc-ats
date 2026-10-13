#!/bin/bash
#
# WooCommerce 官方数据测试
# 计划: A
#
set -e

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[0;33m'
NC='\033[0m'

OFFICIAL_DATA=/Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data
DATA_FILE=$OFFICIAL_DATA/woocommerce/sample_products.csv

cd /usr/local/var/www

echo "=========================================="
echo "   WooCommerce 官方数据测试"
echo "=========================================="
echo ""

# 1. 检查数据文件
echo -n "1. 检查数据文件 ... "
if [ -f "$DATA_FILE" ]; then
    echo -e "${GREEN}存在${NC} ($(ls -lh $DATA_FILE | awk '{print $5}'))"
else
    echo -e "${RED}不存在${NC}"
    exit 1
fi

# 2. 检查插件状态
echo -n "2. 检查插件状态 ... "
if wp plugin is-active woocommerce 2>/dev/null; then
    echo -e "${GREEN}已激活${NC}"
else
    echo -e "${YELLOW}未激活，正在激活...${NC}"
    wp plugin activate woocommerce
fi

# 3. 记录导入前数量
echo -n "3. 导入前产品数量 ... "
BEFORE=$(wp post list --post_type=product --format=count 2>/dev/null || echo "0")
echo "$BEFORE"

# 4. 执行导入
echo -n "4. 执行 CSV 导入 ... "
if wp wc product import "$DATA_FILE" --user=1 2>&1; then
    echo -e "${GREEN}完成${NC}"
else
    echo -e "${RED}失败${NC}"
    exit 1
fi

# 5. 验证导入结果
echo -n "5. 导入后产品数量 ... "
AFTER=$(wp post list --post_type=product --format=count 2>/dev/null || echo "0")
echo "$AFTER"

IMPORTED=$((AFTER - BEFORE))
echo ""

if [ "$IMPORTED" -gt 0 ]; then
    echo -e "${GREEN}✅ 测试通过: 成功导入 $IMPORTED 个产品${NC}"
    echo ""
    echo "产品列表:"
    wp post list --post_type=product --fields=ID,post_title,post_status --format=table | head -15
else
    echo -e "${RED}❌ 测试失败: 未导入任何产品${NC}"
    exit 1
fi

echo ""
echo "清理命令 (可选):"
echo "  wp post delete \$(wp post list --post_type=product --format=ids) --force"

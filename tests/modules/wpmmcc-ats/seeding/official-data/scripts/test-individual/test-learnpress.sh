#!/bin/bash
#
# LearnPress 官方数据测试
# 计划: D
#
set -e

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[0;33m'
NC='\033[0m'

OFFICIAL_DATA=/Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data
DATA_FILE=$OFFICIAL_DATA/learnpress/sample-data.xml

cd /usr/local/var/www

echo "=========================================="
echo "   LearnPress 官方数据测试"
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
echo -n "2. 检查 LearnPress 插件 ... "
if wp plugin is-active learnpress 2>/dev/null; then
    echo -e "${GREEN}已激活${NC}"
else
    echo -e "${YELLOW}未激活，正在激活...${NC}"
    wp plugin activate learnpress
fi

# 3. 记录导入前数量
echo "3. 导入前数量:"
COURSE_BEFORE=$(wp post list --post_type=lp_course --format=count 2>/dev/null || echo "0")
LESSON_BEFORE=$(wp post list --post_type=lp_lesson --format=count 2>/dev/null || echo "0")
QUIZ_BEFORE=$(wp post list --post_type=lp_quiz --format=count 2>/dev/null || echo "0")
echo "   课程: $COURSE_BEFORE, 课时: $LESSON_BEFORE, 测验: $QUIZ_BEFORE"

# 4. LearnPress 使用内置导入
echo ""
echo "4. LearnPress 导入说明:"
echo -e "   ${YELLOW}LearnPress 有内置导入功能${NC}"
echo "   步骤:"
echo "   1. 后台 → LearnPress → Tools → Course"
echo "   2. 点击 'Import' 按钮"
echo "   3. 上传 $DATA_FILE"
echo ""

# 5. 尝试检查 LearnPress CLI
echo -n "5. 检查 LearnPress WP-CLI ... "
if wp learnpress --help 2>/dev/null; then
    echo -e "${GREEN}可用${NC}"
else
    echo -e "${YELLOW}不可用${NC}"
fi

# 6. 尝试标准 WordPress 导入
echo ""
echo -n "6. 尝试 WordPress Importer ... "
if wp plugin is-installed wordpress-importer 2>/dev/null; then
    wp plugin activate wordpress-importer 2>/dev/null || true
fi

# LearnPress XML 是自定义格式，可能不兼容标准 WP Importer
# 检查文件格式
if head -5 "$DATA_FILE" | grep -q "wordpress.org/export"; then
    echo "WXR 格式，尝试导入..."
    wp import "$DATA_FILE" --authors=create 2>&1 || echo -e "${YELLOW}导入失败，需手动导入${NC}"
else
    echo -e "${YELLOW}LearnPress 自定义格式，需通过后台导入${NC}"
fi

# 7. 验证
echo ""
echo "7. 当前数量:"
COURSE_AFTER=$(wp post list --post_type=lp_course --format=count 2>/dev/null || echo "0")
LESSON_AFTER=$(wp post list --post_type=lp_lesson --format=count 2>/dev/null || echo "0")
QUIZ_AFTER=$(wp post list --post_type=lp_quiz --format=count 2>/dev/null || echo "0")
echo "   课程: $COURSE_AFTER, 课时: $LESSON_AFTER, 测验: $QUIZ_AFTER"

echo ""
echo "=========================================="
echo "   测试结果"
echo "=========================================="

COURSE_IMPORTED=$((COURSE_AFTER - COURSE_BEFORE))
if [ "$COURSE_IMPORTED" -gt 0 ]; then
    echo -e "${GREEN}✅ 自动导入成功: $COURSE_IMPORTED 个课程${NC}"
else
    echo -e "${YELLOW}⚠️ 需手动通过后台导入${NC}"
    echo "   后台 → LearnPress → Tools → Import"
fi

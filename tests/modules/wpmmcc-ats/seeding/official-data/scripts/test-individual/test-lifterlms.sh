#!/bin/bash
#
# LifterLMS 官方数据测试
# 计划: D
#
set -e

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[0;33m'
NC='\033[0m'

OFFICIAL_DATA=/Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data
DATA_FILE=$OFFICIAL_DATA/lifterlms/sample-course.json

cd /usr/local/var/www

echo "=========================================="
echo "   LifterLMS 官方数据测试"
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
echo -n "2. 检查 LifterLMS 插件 ... "
if wp plugin is-active lifterlms 2>/dev/null; then
    echo -e "${GREEN}已激活${NC}"
else
    echo -e "${YELLOW}未激活，正在激活...${NC}"
    wp plugin activate lifterlms
fi

# 3. 记录导入前数量
echo "3. 导入前数量:"
COURSE_BEFORE=$(wp post list --post_type=course --format=count 2>/dev/null || echo "0")
LESSON_BEFORE=$(wp post list --post_type=lesson --format=count 2>/dev/null || echo "0")
SECTION_BEFORE=$(wp post list --post_type=section --format=count 2>/dev/null || echo "0")
echo "   课程: $COURSE_BEFORE, 章节: $SECTION_BEFORE, 课时: $LESSON_BEFORE"

# 4. LifterLMS 导入说明
echo ""
echo "4. LifterLMS 导入说明:"
echo -e "   ${YELLOW}LifterLMS 使用 JSON 格式，需通过后台导入${NC}"
echo "   步骤:"
echo "   1. 后台 → LifterLMS → Import"
echo "   2. 选择 'Import a Course'"
echo "   3. 上传 $DATA_FILE"
echo ""

# 5. 检查 LifterLMS CLI
echo -n "5. 检查 LifterLMS WP-CLI ... "
if wp llms --help 2>/dev/null; then
    echo -e "${GREEN}可用${NC}"
    # 检查是否有导入命令
    if wp llms --help 2>/dev/null | grep -q "import"; then
        echo "   尝试 CLI 导入..."
        wp llms import "$DATA_FILE" 2>&1 || echo -e "${YELLOW}CLI 导入失败${NC}"
    fi
else
    echo -e "${YELLOW}不可用${NC}"
fi

# 6. 验证
echo ""
echo "6. 当前数量:"
COURSE_AFTER=$(wp post list --post_type=course --format=count 2>/dev/null || echo "0")
LESSON_AFTER=$(wp post list --post_type=lesson --format=count 2>/dev/null || echo "0")
SECTION_AFTER=$(wp post list --post_type=section --format=count 2>/dev/null || echo "0")
echo "   课程: $COURSE_AFTER, 章节: $SECTION_AFTER, 课时: $LESSON_AFTER"

echo ""
echo "=========================================="
echo "   测试结果"
echo "=========================================="

COURSE_IMPORTED=$((COURSE_AFTER - COURSE_BEFORE))
if [ "$COURSE_IMPORTED" -gt 0 ]; then
    echo -e "${GREEN}✅ 导入成功: $COURSE_IMPORTED 个课程${NC}"
else
    echo -e "${YELLOW}⚠️ 需手动通过后台导入${NC}"
    echo "   后台 → LifterLMS → Import"
fi

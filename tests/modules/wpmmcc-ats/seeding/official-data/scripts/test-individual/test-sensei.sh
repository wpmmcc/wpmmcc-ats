#!/bin/bash
#
# Sensei LMS 官方数据测试
# 计划: C
#
set -e

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[0;33m'
NC='\033[0m'

OFFICIAL_DATA=/Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data
COURSES_FILE=$OFFICIAL_DATA/sensei-lms/courses.csv
LESSONS_FILE=$OFFICIAL_DATA/sensei-lms/lessons.csv

cd /usr/local/var/www

echo "=========================================="
echo "   Sensei LMS 官方数据测试"
echo "=========================================="
echo ""

# 1. 检查数据文件
echo "1. 检查数据文件:"
for file in "$COURSES_FILE" "$LESSONS_FILE"; do
    echo -n "   $(basename $file) ... "
    if [ -f "$file" ]; then
        echo -e "${GREEN}存在${NC} ($(ls -lh $file | awk '{print $5}'))"
    else
        echo -e "${RED}不存在${NC}"
        exit 1
    fi
done

# 2. 检查插件状态
echo -n "2. 检查 Sensei LMS 插件 ... "
if wp plugin is-active sensei-lms 2>/dev/null; then
    echo -e "${GREEN}已激活${NC}"
else
    echo -e "${YELLOW}未激活，正在激活...${NC}"
    wp plugin activate sensei-lms
fi

# 3. 记录导入前数量
echo "3. 导入前数量:"
COURSE_BEFORE=$(wp post list --post_type=course --format=count 2>/dev/null || echo "0")
LESSON_BEFORE=$(wp post list --post_type=lesson --format=count 2>/dev/null || echo "0")
echo "   课程: $COURSE_BEFORE, 课时: $LESSON_BEFORE"

# 4. 检查 Sensei 导入功能
echo "4. Sensei CSV 导入说明:"
echo -e "   ${YELLOW}Sensei LMS 需要通过后台导入 CSV${NC}"
echo "   步骤:"
echo "   1. 后台 → Sensei LMS → Tools → Import"
echo "   2. 选择 'Courses' 并上传 courses.csv"
echo "   3. 选择 'Lessons' 并上传 lessons.csv"
echo ""
echo "   数据文件路径:"
echo "   - $COURSES_FILE"
echo "   - $LESSONS_FILE"

# 5. 尝试使用 WP-CLI 导入 (如果有 sensei 命令)
echo ""
echo -n "5. 检查 Sensei WP-CLI 命令 ... "
if wp sensei --help 2>/dev/null | grep -q "import"; then
    echo -e "${GREEN}可用${NC}"
    echo "   尝试自动导入..."
    # Sensei 可能有 CLI 导入命令
else
    echo -e "${YELLOW}不可用 (需手动导入)${NC}"
fi

echo ""
echo "=========================================="
echo "   测试结果"
echo "=========================================="
echo ""
echo -e "${YELLOW}⚠️ Sensei LMS 需要手动后台导入${NC}"
echo ""
echo "验证导入后运行:"
echo "  wp post list --post_type=course --format=count"
echo "  wp post list --post_type=lesson --format=count"

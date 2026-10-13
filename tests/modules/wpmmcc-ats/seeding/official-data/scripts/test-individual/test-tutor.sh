#!/bin/bash
#
# Tutor LMS 官方数据测试
# 计划: B
#
set -e

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[0;33m'
NC='\033[0m'

OFFICIAL_DATA=/Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data
DATA_FILE=$OFFICIAL_DATA/downloads/tutor-lms/tutor-sample-course.xml

cd /usr/local/var/www

echo "=========================================="
echo "   Tutor LMS 官方数据测试"
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
echo -n "2. 检查 Tutor LMS 插件 ... "
if wp plugin is-active tutor 2>/dev/null; then
    echo -e "${GREEN}已激活${NC}"
else
    echo -e "${YELLOW}未激活，正在激活...${NC}"
    wp plugin activate tutor
fi

# 3. 检查 WordPress Importer
echo -n "3. 检查 WordPress Importer ... "
if wp plugin is-installed wordpress-importer 2>/dev/null; then
    wp plugin activate wordpress-importer 2>/dev/null || true
    echo -e "${GREEN}已安装${NC}"
else
    echo -e "${YELLOW}正在安装...${NC}"
    wp plugin install wordpress-importer --activate
fi

# 4. 记录导入前数量
echo "4. 导入前数量:"
COURSE_BEFORE=$(wp post list --post_type=courses --format=count 2>/dev/null || echo "0")
LESSON_BEFORE=$(wp post list --post_type=lesson --format=count 2>/dev/null || echo "0")
TOPIC_BEFORE=$(wp post list --post_type=topics --format=count 2>/dev/null || echo "0")
echo "   课程: $COURSE_BEFORE, 课时: $LESSON_BEFORE, 主题: $TOPIC_BEFORE"

# 5. 执行导入
echo -n "5. 执行 XML 导入 ... "
if wp import "$DATA_FILE" --authors=create 2>&1; then
    echo -e "${GREEN}完成${NC}"
else
    echo -e "${RED}失败${NC}"
    exit 1
fi

# 6. 验证导入结果
echo "6. 导入后数量:"
COURSE_AFTER=$(wp post list --post_type=courses --format=count 2>/dev/null || echo "0")
LESSON_AFTER=$(wp post list --post_type=lesson --format=count 2>/dev/null || echo "0")
TOPIC_AFTER=$(wp post list --post_type=topics --format=count 2>/dev/null || echo "0")
echo "   课程: $COURSE_AFTER, 课时: $LESSON_AFTER, 主题: $TOPIC_AFTER"

COURSE_IMPORTED=$((COURSE_AFTER - COURSE_BEFORE))
LESSON_IMPORTED=$((LESSON_AFTER - LESSON_BEFORE))

echo ""

if [ "$COURSE_IMPORTED" -gt 0 ] || [ "$LESSON_IMPORTED" -gt 0 ]; then
    echo -e "${GREEN}✅ 测试通过${NC}"
    echo "   导入: $COURSE_IMPORTED 个课程, $LESSON_IMPORTED 个课时"
    echo ""
    echo "课程列表:"
    wp post list --post_type=courses --fields=ID,post_title,post_status --format=table
else
    echo -e "${RED}❌ 测试失败: 未导入任何内容${NC}"
    exit 1
fi

echo ""
echo "清理命令 (可选):"
echo "  wp post delete \$(wp post list --post_type=courses,lesson,topics --format=ids) --force"

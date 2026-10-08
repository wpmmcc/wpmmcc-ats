#!/bin/bash
#
# Plan A 数据验证脚本
#
# 检查所有 Plan A 插件的数据填充情况
#
# Usage: bash verify-plan-a.sh
#

set -e

# 颜色定义
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[0;33m'
NC='\033[0m'

cd /usr/local/var/www

echo "=========================================="
echo "   Plan A 数据验证"
echo "=========================================="
echo ""

# 统计
TOTAL=0
HAS_DATA=0
NO_DATA=0

# ==========================================
# WordPress Core
# ==========================================

echo "WordPress Core:"
POST_COUNT=$(wp post list --post_type=post --format=count 2>/dev/null || echo "0")
PAGE_COUNT=$(wp post list --post_type=page --format=count 2>/dev/null || echo "0")
echo -n "  Posts: $POST_COUNT, Pages: $PAGE_COUNT "

if [ "$POST_COUNT" -gt 10 ] && [ "$PAGE_COUNT" -gt 5 ]; then
    echo -e "${GREEN}✓${NC}"
    HAS_DATA=$((HAS_DATA + 1))
else
    echo -e "${RED}✗ 数据不足${NC}"
    NO_DATA=$((NO_DATA + 1))
fi
TOTAL=$((TOTAL + 1))

echo ""

# ==========================================
# Content Plugins (12个)
# ==========================================

echo "Content Plugins:"
echo ""

# 1. WooCommerce
echo -n "1. WooCommerce (product): "
COUNT=$(wp post list --post_type=product --format=count 2>/dev/null || echo "0")
echo -n "$COUNT 项 "
if [ "$COUNT" -gt 0 ]; then
    echo -e "${GREEN}✓${NC}"
    HAS_DATA=$((HAS_DATA + 1))
else
    echo -e "${RED}✗${NC}"
    NO_DATA=$((NO_DATA + 1))
fi
TOTAL=$((TOTAL + 1))

# 2. bbPress
echo -n "2. bbPress (forum): "
COUNT=$(wp post list --post_type=forum --format=count 2>/dev/null || echo "0")
echo -n "$COUNT 项 "
if [ "$COUNT" -gt 0 ]; then
    echo -e "${GREEN}✓${NC}"
    HAS_DATA=$((HAS_DATA + 1))
else
    echo -e "${RED}✗${NC}"
    NO_DATA=$((NO_DATA + 1))
fi
TOTAL=$((TOTAL + 1))

# 3. Academy LMS
echo -n "3. Academy LMS (academy_courses): "
COUNT=$(wp post list --post_type=academy_courses --format=count 2>/dev/null || echo "0")
echo -n "$COUNT 项 "
if [ "$COUNT" -gt 0 ]; then
    echo -e "${GREEN}✓${NC}"
    HAS_DATA=$((HAS_DATA + 1))
else
    echo -e "${RED}✗${NC}"
    NO_DATA=$((NO_DATA + 1))
fi
TOTAL=$((TOTAL + 1))

# 4. MasterStudy LMS
echo -n "4. MasterStudy LMS (stm-courses): "
COUNT=$(wp post list --post_type=stm-courses --format=count 2>/dev/null || echo "0")
echo -n "$COUNT 项 "
if [ "$COUNT" -gt 0 ]; then
    echo -e "${GREEN}✓${NC}"
    HAS_DATA=$((HAS_DATA + 1))
else
    echo -e "${RED}✗${NC}"
    NO_DATA=$((NO_DATA + 1))
fi
TOTAL=$((TOTAL + 1))

# 5. Easy Digital Downloads
echo -n "5. Easy Digital Downloads (download): "
COUNT=$(wp post list --post_type=download --format=count 2>/dev/null || echo "0")
echo -n "$COUNT 项 "
if [ "$COUNT" -gt 0 ]; then
    echo -e "${GREEN}✓${NC}"
    HAS_DATA=$((HAS_DATA + 1))
else
    echo -e "${RED}✗${NC}"
    NO_DATA=$((NO_DATA + 1))
fi
TOTAL=$((TOTAL + 1))

# 6. Classified Listing
echo -n "6. Classified Listing (rtcl_listing): "
COUNT=$(wp post list --post_type=rtcl_listing --format=count 2>/dev/null || echo "0")
echo -n "$COUNT 项 "
if [ "$COUNT" -gt 0 ]; then
    echo -e "${GREEN}✓${NC}"
    HAS_DATA=$((HAS_DATA + 1))
else
    echo -e "${RED}✗${NC}"
    NO_DATA=$((NO_DATA + 1))
fi
TOTAL=$((TOTAL + 1))

# 7. Easy Property Listings
echo -n "7. Easy Property Listings (property): "
COUNT=$(wp post list --post_type=property --format=count 2>/dev/null || echo "0")
echo -n "$COUNT 项 "
if [ "$COUNT" -gt 0 ]; then
    echo -e "${GREEN}✓${NC}"
    HAS_DATA=$((HAS_DATA + 1))
else
    echo -e "${RED}✗${NC}"
    NO_DATA=$((NO_DATA + 1))
fi
TOTAL=$((TOTAL + 1))

# 8. Cooked
echo -n "8. Cooked (cp_recipe): "
COUNT=$(wp post list --post_type=cp_recipe --format=count 2>/dev/null || echo "0")
echo -n "$COUNT 项 "
if [ "$COUNT" -gt 0 ]; then
    echo -e "${GREEN}✓${NC}"
    HAS_DATA=$((HAS_DATA + 1))
else
    echo -e "${RED}✗${NC}"
    NO_DATA=$((NO_DATA + 1))
fi
TOTAL=$((TOTAL + 1))

# 9. Envira Gallery
echo -n "9. Envira Gallery (envira): "
COUNT=$(wp post list --post_type=envira --format=count 2>/dev/null || echo "0")
echo -n "$COUNT 项 "
if [ "$COUNT" -gt 0 ]; then
    echo -e "${GREEN}✓${NC}"
    HAS_DATA=$((HAS_DATA + 1))
else
    echo -e "${RED}✗${NC}"
    NO_DATA=$((NO_DATA + 1))
fi
TOTAL=$((TOTAL + 1))

# 10. Site Reviews
echo -n "10. Site Reviews (site-review): "
COUNT=$(wp post list --post_type=site-review --format=count 2>/dev/null || echo "0")
echo -n "$COUNT 项 "
if [ "$COUNT" -gt 0 ]; then
    echo -e "${GREEN}✓${NC}"
    HAS_DATA=$((HAS_DATA + 1))
else
    echo -e "${RED}✗${NC}"
    NO_DATA=$((NO_DATA + 1))
fi
TOTAL=$((TOTAL + 1))

# 11. TablePress (跳过，自定义表)
echo -n "11. TablePress: "
echo -e "${YELLOW}跳过（自定义表）${NC}"
TOTAL=$((TOTAL + 1))

# 12. Testimonial Free
echo -n "12. Testimonial Free (spt_testimonial): "
COUNT=$(wp post list --post_type=spt_testimonial --format=count 2>/dev/null || echo "0")
echo -n "$COUNT 项 "
if [ "$COUNT" -gt 0 ]; then
    echo -e "${GREEN}✓${NC}"
    HAS_DATA=$((HAS_DATA + 1))
else
    echo -e "${RED}✗${NC}"
    NO_DATA=$((NO_DATA + 1))
fi
TOTAL=$((TOTAL + 1))

echo ""

# ==========================================
# Utility Plugins (BuddyPress)
# ==========================================

echo "Utility Plugins:"
echo ""

echo -n "BuddyPress (users): "
USER_COUNT=$(wp user list --format=count 2>/dev/null || echo "0")
echo -n "$USER_COUNT 个用户 "
if [ "$USER_COUNT" -gt 5 ]; then
    echo -e "${GREEN}✓${NC}"
    HAS_DATA=$((HAS_DATA + 1))
else
    echo -e "${RED}✗ 数据不足${NC}"
    NO_DATA=$((NO_DATA + 1))
fi
TOTAL=$((TOTAL + 1))

echo ""

# ==========================================
# 统计
# ==========================================

echo "=========================================="
echo "   验证完成"
echo "=========================================="
echo ""
echo "总计: $TOTAL"
echo -e "${GREEN}有数据: $HAS_DATA${NC}"
echo -e "${RED}无数据: $NO_DATA${NC}"
echo ""

PERCENTAGE=$(awk "BEGIN {printf \"%.1f\", ($HAS_DATA/$TOTAL)*100}")
echo "数据覆盖率: $PERCENTAGE%"
echo ""

if [ "$HAS_DATA" -ge 10 ]; then
    echo -e "${GREEN}✅ 数据填充合格${NC}"
    exit 0
else
    echo -e "${RED}⚠️  数据填充不足，建议重新填充${NC}"
    exit 1
fi

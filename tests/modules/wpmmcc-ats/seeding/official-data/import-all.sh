#!/bin/bash
#
# 导入所有官方数据
#
# Usage: bash import-all.sh [plugins...]
#        bash import-all.sh              # 导入所有
#        bash import-all.sh woocommerce  # 仅导入 WooCommerce
#

set -e

# 切换到 WordPress 目录
cd /usr/local/var/www

# 官方数据目录
DATA_DIR="/Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data"

# 颜色输出
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[0;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

echo "=========================================="
echo "   官方数据导入脚本"
echo "=========================================="
echo ""

# 检查 WordPress Importer 插件
if ! wp plugin is-installed wordpress-importer 2>/dev/null; then
    echo -e "${YELLOW}⚠️  安装 WordPress Importer 插件...${NC}"
    wp plugin install wordpress-importer --activate
fi

# 解析参数
PLUGINS=("$@")
if [ ${#PLUGINS[@]} -eq 0 ]; then
    PLUGINS=("wordpress-core" "woocommerce" "easy-digital-downloads" "learnpress" "lifterlms" "sensei-lms")
fi

# ==========================================
# 1. WordPress Core
# ==========================================
import_wordpress_core() {
    echo -e "${BLUE}[1/6] WordPress Core${NC}"

    if [ ! -f "$DATA_DIR/wordpress-core/themeunittestdata.wordpress.xml" ]; then
        echo -e "${YELLOW}  ⚠️  数据文件不存在，跳过${NC}"
        return
    fi

    echo "  → 导入主题单元测试数据..."
    wp import "$DATA_DIR/wordpress-core/themeunittestdata.wordpress.xml" \
        --authors=create \
        --skip=attachment 2>&1 | grep -E "(Success|Finished|Failed)" || true

    COUNT=$(wp post list --post_type=post --format=count 2>/dev/null)
    echo -e "${GREEN}  ✅ 完成 (文章数: $COUNT)${NC}"
    echo ""
}

# ==========================================
# 2. WooCommerce
# ==========================================
import_woocommerce() {
    echo -e "${BLUE}[2/6] WooCommerce${NC}"

    if ! wp plugin is-active woocommerce 2>/dev/null; then
        echo -e "${YELLOW}  ⚠️  插件未激活，跳过${NC}"
        echo ""
        return
    fi

    if [ ! -f "$DATA_DIR/woocommerce/sample_products.csv" ]; then
        echo -e "${YELLOW}  ⚠️  数据文件不存在，跳过${NC}"
        echo ""
        return
    fi

    echo "  → 导入示例产品 (CSV)..."

    # 检查 WooCommerce CLI 是否可用
    if wp wc --help >/dev/null 2>&1; then
        wp wc product_csv import "$DATA_DIR/woocommerce/sample_products.csv" \
            --user=1 2>&1 | tail -5 || true
    else
        # 回退到 XML 导入
        echo "  → WC CLI 不可用，使用 XML 导入..."
        wp import "$DATA_DIR/woocommerce/sample_products.xml" \
            --authors=create 2>&1 | grep -E "(Success|Finished|Failed)" || true
    fi

    COUNT=$(wp post list --post_type=product --format=count 2>/dev/null)
    echo -e "${GREEN}  ✅ 完成 (产品数: $COUNT)${NC}"
    echo ""
}

# ==========================================
# 3. Easy Digital Downloads
# ==========================================
import_edd() {
    echo -e "${BLUE}[3/6] Easy Digital Downloads${NC}"

    if ! wp plugin is-active easy-digital-downloads 2>/dev/null; then
        echo -e "${YELLOW}  ⚠️  插件未激活，跳过${NC}"
        echo ""
        return
    fi

    if [ ! -f "$DATA_DIR/easy-digital-downloads/sample-products-import.xml" ]; then
        echo -e "${YELLOW}  ⚠️  数据文件不存在，跳过${NC}"
        echo ""
        return
    fi

    echo "  → 导入示例下载产品..."
    wp import "$DATA_DIR/easy-digital-downloads/sample-products-import.xml" \
        --authors=create 2>&1 | grep -E "(Success|Finished|Failed)" || true

    COUNT=$(wp post list --post_type=download --format=count 2>/dev/null)
    echo -e "${GREEN}  ✅ 完成 (下载数: $COUNT)${NC}"
    echo ""
}

# ==========================================
# 4. LearnPress
# ==========================================
import_learnpress() {
    echo -e "${BLUE}[4/6] LearnPress${NC}"

    if ! wp plugin is-active learnpress 2>/dev/null; then
        echo -e "${YELLOW}  ⚠️  插件未激活，跳过${NC}"
        echo ""
        return
    fi

    if [ ! -f "$DATA_DIR/learnpress/sample-data.xml" ]; then
        echo -e "${YELLOW}  ⚠️  数据文件不存在，跳过${NC}"
        echo ""
        return
    fi

    echo "  → 导入示例课程..."
    wp import "$DATA_DIR/learnpress/sample-data.xml" \
        --authors=create 2>&1 | grep -E "(Success|Finished|Failed)" || true

    COUNT=$(wp post list --post_type=lp_course --format=count 2>/dev/null)
    echo -e "${GREEN}  ✅ 完成 (课程数: $COUNT)${NC}"
    echo ""
}

# ==========================================
# 5. LifterLMS
# ==========================================
import_lifterlms() {
    echo -e "${BLUE}[5/6] LifterLMS${NC}"

    if ! wp plugin is-active lifterlms 2>/dev/null; then
        echo -e "${YELLOW}  ⚠️  插件未激活，跳过${NC}"
        echo ""
        return
    fi

    if [ ! -f "$DATA_DIR/lifterlms/sample-course.json" ]; then
        echo -e "${YELLOW}  ⚠️  数据文件不存在，跳过${NC}"
        echo ""
        return
    fi

    echo "  → LifterLMS 需要通过后台手动导入 JSON 文件"
    echo "  → 路径: $DATA_DIR/lifterlms/sample-course.json"
    echo -e "${YELLOW}  ⚠️  请手动导入${NC}"
    echo ""
}

# ==========================================
# 6. Sensei LMS
# ==========================================
import_sensei() {
    echo -e "${BLUE}[6/6] Sensei LMS${NC}"

    if ! wp plugin is-active sensei-lms 2>/dev/null; then
        echo -e "${YELLOW}  ⚠️  插件未激活，跳过${NC}"
        echo ""
        return
    fi

    if [ ! -f "$DATA_DIR/sensei-lms/courses.csv" ]; then
        echo -e "${YELLOW}  ⚠️  数据文件不存在，跳过${NC}"
        echo ""
        return
    fi

    echo "  → Sensei LMS 需要通过后台手动导入 CSV 文件"
    echo "  → 课程: $DATA_DIR/sensei-lms/courses.csv"
    echo "  → 课时: $DATA_DIR/sensei-lms/lessons.csv"
    echo -e "${YELLOW}  ⚠️  请手动导入${NC}"
    echo ""
}

# ==========================================
# 执行导入
# ==========================================
for plugin in "${PLUGINS[@]}"; do
    case "$plugin" in
        wordpress-core|core)
            import_wordpress_core
            ;;
        woocommerce|wc)
            import_woocommerce
            ;;
        easy-digital-downloads|edd)
            import_edd
            ;;
        learnpress|lp)
            import_learnpress
            ;;
        lifterlms|llms)
            import_lifterlms
            ;;
        sensei-lms|sensei)
            import_sensei
            ;;
        *)
            echo -e "${RED}未知插件: $plugin${NC}"
            ;;
    esac
done

# ==========================================
# 汇总统计
# ==========================================
echo "=========================================="
echo "   导入完成 - 数据统计"
echo "=========================================="
echo ""

wp post list --post_type=post --format=count 2>/dev/null | xargs -I {} echo "文章 (post): {} 篇"
wp post list --post_type=page --format=count 2>/dev/null | xargs -I {} echo "页面 (page): {} 篇"
wp post list --post_type=product --format=count 2>/dev/null | xargs -I {} echo "产品 (product): {} 个"
wp post list --post_type=download --format=count 2>/dev/null | xargs -I {} echo "下载 (download): {} 个"
wp post list --post_type=lp_course --format=count 2>/dev/null | xargs -I {} echo "课程 (lp_course): {} 个"
wp post list --post_type=course --format=count 2>/dev/null | xargs -I {} echo "课程 (course): {} 个"

echo ""
echo -e "${GREEN}✅ 所有导入任务完成！${NC}"

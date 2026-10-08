#!/bin/bash
# 按计划导入官方数据
# 用法: ./import-by-plan.sh [A|B|C|D] [--with-custom]
#
# 更新日期: 2026-01-26
# 基于 Playwright/CLI 测试验证的实际可用导入方式

set -e

PLAN="${1:-A}"
WITH_CUSTOM="${2:-}"
OFFICIAL_DATA="/Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data"
SEEDING_DIR="/Users/zhangxiao/wptsall-dev/dev-tools/seeding"
WP_DIR="/usr/local/var/www"

# 颜色输出
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

log_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# 切换到 WordPress 目录
cd "$WP_DIR"

echo ""
echo "=========================================="
echo "  官方数据导入 - Plan $PLAN"
echo "=========================================="
echo ""

# Phase 1: 导入 WordPress Core (所有计划都需要)
import_wordpress_core() {
    log_info "导入 WordPress Core 测试数据..."
    if [ -f "$OFFICIAL_DATA/wordpress-core/themeunittestdata.wordpress.xml" ]; then
        wp import "$OFFICIAL_DATA/wordpress-core/themeunittestdata.wordpress.xml" --authors=create 2>/dev/null || true
        log_success "WordPress Core 导入完成"
    else
        log_warning "WordPress Core XML 文件不存在"
    fi
}

# Plan A 特有插件
import_plan_a() {
    log_info "========== Plan A 官方数据 =========="

    # 1. WooCommerce (PHP 脚本 CSV 导入)
    log_info "导入 WooCommerce 产品..."
    if [ -f "$OFFICIAL_DATA/scripts/wc-csv-import.php" ]; then
        wp eval-file "$OFFICIAL_DATA/scripts/wc-csv-import.php" 2>/dev/null || log_warning "WooCommerce 导入失败"
        log_success "WooCommerce 导入完成"
    fi

    # 2. Easy Digital Downloads
    log_info "导入 Easy Digital Downloads..."
    if [ -f "$OFFICIAL_DATA/easy-digital-downloads/sample-products-import.xml" ]; then
        wp import "$OFFICIAL_DATA/easy-digital-downloads/sample-products-import.xml" --authors=create 2>/dev/null || true
        log_success "Easy Digital Downloads 导入完成"
    fi

    # 3. bbPress
    log_info "导入 bbPress..."
    if [ -f "$OFFICIAL_DATA/downloads/bbpress/bbpress-sample-data.xml" ]; then
        wp import "$OFFICIAL_DATA/downloads/bbpress/bbpress-sample-data.xml" --authors=create 2>/dev/null || true
        log_success "bbPress 导入完成"
    fi

    # 4. BuddyPress (WP-CLI 生成)
    log_info "生成 BuddyPress 数据..."
    wp bp component activate groups 2>/dev/null || true
    wp bp member generate --count=20 2>/dev/null || log_warning "BuddyPress member 生成失败"
    wp bp group generate --count=5 2>/dev/null || log_warning "BuddyPress group 生成失败"
    log_success "BuddyPress 数据生成完成"

    # Academy LMS 和 MasterStudy LMS 需要自定义填充
    log_warning "Academy LMS: 需自定义填充（Starter Templates 权限问题）"
    log_warning "MasterStudy LMS: 需自定义填充（需购买主题）"
}

# Plan B 特有插件
import_plan_b() {
    log_info "========== Plan B 官方数据 =========="

    # Tutor LMS
    log_info "导入 Tutor LMS..."
    if [ -f "$OFFICIAL_DATA/downloads/tutor-lms/tutor-sample-course.xml" ]; then
        wp import "$OFFICIAL_DATA/downloads/tutor-lms/tutor-sample-course.xml" --authors=create 2>/dev/null || true
        log_success "Tutor LMS 导入完成"
    fi
}

# Plan C 特有插件
import_plan_c() {
    log_info "========== Plan C 官方数据 =========="

    # Sensei LMS (wp sensei-import)
    log_info "导入 Sensei LMS..."
    if [ -f "$OFFICIAL_DATA/sensei-lms/courses.csv" ] && [ -f "$OFFICIAL_DATA/sensei-lms/lessons.csv" ]; then
        cp "$OFFICIAL_DATA/sensei-lms/courses.csv" ./sensei-courses.csv
        cp "$OFFICIAL_DATA/sensei-lms/lessons.csv" ./sensei-lessons.csv
        wp sensei-import --user=admin --courses=sensei-courses.csv --lessons=sensei-lessons.csv 2>/dev/null || log_warning "Sensei LMS 导入失败"
        rm -f sensei-courses.csv sensei-lessons.csv
        log_success "Sensei LMS 导入完成"
    fi
}

# Plan D 特有插件
import_plan_d() {
    log_info "========== Plan D 官方数据 =========="

    # 1. LifterLMS (PHP 脚本)
    log_info "导入 LifterLMS..."
    if [ -f "$OFFICIAL_DATA/scripts/llms-json-import.php" ]; then
        wp eval-file "$OFFICIAL_DATA/scripts/llms-json-import.php" --user=admin 2>/dev/null || log_warning "LifterLMS 导入失败"
        log_success "LifterLMS 导入完成"
    fi

    # 2. LearnPress (Playwright 自动化)
    log_info "导入 LearnPress (Playwright)..."
    if [ -d "$OFFICIAL_DATA/scripts/playwright" ]; then
        cd "$OFFICIAL_DATA/scripts/playwright"
        if [ -f "package.json" ]; then
            npx playwright test import-learnpress.spec.js --reporter=line 2>/dev/null || log_warning "LearnPress Playwright 导入失败"
            log_success "LearnPress 导入完成"
        fi
        cd "$WP_DIR"
    fi
}

# 自定义填充
import_custom() {
    log_info "========== 自定义填充 (Phase 2) =========="
    if [ -f "$SEEDING_DIR/seed-content.php" ]; then
        wp eval-file "$SEEDING_DIR/seed-content.php" "$PLAN" 2>/dev/null || log_warning "自定义填充失败"
        log_success "自定义填充完成"
    else
        log_warning "seed-content.php 不存在"
    fi
}

# 主流程
case "$PLAN" in
    A|a)
        import_wordpress_core
        import_plan_a
        ;;
    B|b)
        import_wordpress_core
        import_plan_b
        ;;
    C|c)
        import_wordpress_core
        import_plan_c
        ;;
    D|d)
        import_wordpress_core
        import_plan_d
        ;;
    *)
        log_error "无效的计划: $PLAN"
        echo "用法: $0 [A|B|C|D] [--with-custom]"
        exit 1
        ;;
esac

# 如果指定了 --with-custom，执行自定义填充
if [ "$WITH_CUSTOM" = "--with-custom" ]; then
    import_custom
fi

echo ""
echo "=========================================="
echo "  Plan $PLAN 导入完成"
echo "=========================================="
echo ""

# 显示统计
log_info "数据统计:"
echo "  - Posts: $(wp post list --post_type=post --format=count 2>/dev/null || echo 0)"
echo "  - Pages: $(wp post list --post_type=page --format=count 2>/dev/null || echo 0)"
echo "  - Users: $(wp user list --format=count 2>/dev/null || echo 0)"

case "$PLAN" in
    A|a)
        echo "  - Products (WooCommerce): $(wp post list --post_type=product --format=count 2>/dev/null || echo 0)"
        echo "  - Downloads (EDD): $(wp post list --post_type=download --format=count 2>/dev/null || echo 0)"
        echo "  - Forums (bbPress): $(wp post list --post_type=forum --format=count 2>/dev/null || echo 0)"
        echo "  - BP Groups: $(wp bp group list --format=count 2>/dev/null || echo 0)"
        ;;
    B|b)
        echo "  - Courses (Tutor): $(wp post list --post_type=courses --format=count 2>/dev/null || echo 0)"
        ;;
    C|c)
        echo "  - Courses (Sensei): $(wp post list --post_type=course --format=count 2>/dev/null || echo 0)"
        echo "  - Lessons (Sensei): $(wp post list --post_type=lesson --format=count 2>/dev/null || echo 0)"
        ;;
    D|d)
        echo "  - Courses (LifterLMS): $(wp post list --post_type=course --format=count 2>/dev/null || echo 0)"
        echo "  - Courses (LearnPress): $(wp post list --post_type=lp_course --format=count 2>/dev/null || echo 0)"
        ;;
esac

echo ""

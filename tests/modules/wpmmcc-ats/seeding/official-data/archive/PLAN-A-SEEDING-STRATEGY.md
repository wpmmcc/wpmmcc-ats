# Plan A 数据填充策略

**版本**: 1.0.0
**更新时间**: 2026-01-26
**计划**: A - 基础商业套件

---

## 📋 目录

1. [插件清单](#插件清单)
2. [填充策略](#填充策略)
3. [官方数据填充](#官方数据填充)
4. [自定义数据填充](#自定义数据填充)
5. [填充顺序](#填充顺序)
6. [完整流程](#完整流程)

---

## 插件清单

### Content Plugins (12个)

| # | 插件 | 官方数据 | 填充方式 | post_type |
|---|------|---------|---------|-----------|
| 1 | woocommerce | ✅ CSV | 官方导入 | product |
| 2 | bbpress | ✅ XML | 官方导入 | forum, topic, reply |
| 3 | academy | ✅ Starter Templates | 官方导入 | academy_courses, academy_lessons |
| 4 | masterstudy-lms-learning-management-system | ✅ Demo Import | 官方导入 | stm-courses, stm-lessons, stm-quizzes |
| 5 | easy-digital-downloads | ✅ XML | 官方导入 | download |
| 6 | classified-listing | ❌ | v4扫描+REST | rtcl_listing |
| 7 | easy-property-listings | ❌ | v4扫描+REST | property |
| 8 | cooked | ❌ | v4扫描+REST | cp_recipe |
| 9 | envira-gallery-lite | ❌ | v4扫描+REST | envira |
| 10 | site-reviews | ❌ | v4扫描+REST | site-review |
| 11 | tablepress | ⚠️ 自定义表 | 跳过或手动 | - |
| 12 | testimonial-free | ❌ | v4扫描+REST | spt_testimonial |

### Utility Plugins (8个)

| # | 插件 | 类型 | 是否填充 | 说明 |
|---|------|------|---------|------|
| 1 | wordpress-seo | SEO | ❌ | Meta 字段，跟随内容填充 |
| 2 | advanced-custom-fields | 字段扩展 | ❌ | 字段定义，不需要填充 |
| 3 | contact-form-7 | 表单 | ❌ | 表单配置，不需要填充 |
| 4 | buddypress | 社区 | ✅ | BP Default Data 插件 |
| 5 | easy-appointments | 预约 | ❌ | 预约数据，不优先填充 |
| 6 | simply-schedule-appointments | 预约 | ❌ | 预约数据，不优先填充 |
| 7 | powerpress | 播客 | ❌ | 播客设置，不需要填充 |
| 8 | redirection | 重定向 | ❌ | 重定向规则，不需要填充 |

---

## 填充策略

### 策略 A: 官方数据（6个插件）

**优先级**: ⭐⭐⭐⭐⭐（最高）

**插件**:
- WooCommerce
- bbPress
- Easy Digital Downloads
- Academy LMS
- MasterStudy LMS
- BuddyPress

**优势**:
- ✅ 数据完整（包含关联关系）
- ✅ 数据真实（官方提供）
- ✅ 无需维护（插件更新自动适配）

**劣势**:
- ⚠️ 部分需要手动操作（Academy, MasterStudy）
- ⚠️ 部分需要下载（bbPress）

---

### 策略 B: v4扫描 + REST API（6个插件）

**优先级**: ⭐⭐⭐⭐（高）

**插件**:
- classified-listing
- easy-property-listings
- cooked
- envira-gallery-lite
- site-reviews
- testimonial-free

**流程**:

1. **使用 v4 扫描工具发现字段和 URL**:
   ```bash
   cd /usr/local/var/www
   wp eval-file /Users/zhangxiao/wptsall-dev/dev-tools/scanning/v4-smart-scanner/bin/run-smart-scan-all.php
   ```

2. **输出结果**:
   ```
   output/classified-listing-rtcl_listing.json
   output/easy-property-listings-property.json
   output/cooked-cp_recipe.json
   output/envira-gallery-lite-envira.json
   output/site-reviews-site-review.json
   output/testimonial-free-spt_testimonial.json
   ```

3. **从扫描结果提取关键信息**:
   - `fields`: 需要填充的字段列表
   - `url_info.frontend_urls`: 前端 URL 模式
   - `url_info.backend_urls`: 后端编辑 URL

4. **使用 seed-content.php 填充**:
   ```bash
   wp eval-file /Users/zhangxiao/wptsall-dev/tests/workflow/seed-content.php A
   ```

**优势**:
- ✅ 自动发现字段（无需手动配置）
- ✅ 使用 REST API（标准方式）
- ✅ 可重复执行（幂等性）

**劣势**:
- ⚠️ 需要 v4 扫描结果
- ⚠️ 字段值需要合理构造

---

### 策略 C: 跳过（1个插件）

**优先级**: ⭐（最低）

**插件**:
- tablepress

**原因**:
- 使用自定义表存储（不是 post_type）
- 没有 REST API
- 官方无数据

**替代方案**:
- 手动在后台创建 1-2 个表格（用于测试）
- 或完全跳过，不影响其他插件测试

---

## 官方数据填充

### 1. WooCommerce

**数据文件**: `woocommerce/sample_products.csv`

```bash
cd /usr/local/var/www

# 导入产品（CSV 方式）
wp wc product import /Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data/woocommerce/sample_products.csv --user=1

# 验证
wp post list --post_type=product --format=count
```

**预期结果**: 9 个产品

---

### 2. bbPress

**数据文件**: `downloads/bbpress/bbpress-sample-data.xml`（需先下载）

```bash
cd /usr/local/var/www

# 方式 1: 如果已有 XML 文件
wp import /Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data/downloads/bbpress/bbpress-sample-data.xml --authors=create

# 方式 2: 使用 GitHub 集合
cd /Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data/downloads/github-collections
git clone https://github.com/maheshwaghmare/sample-data.git
wp import sample-data/bbpress/*.xml --authors=create

# 验证
wp post list --post_type=forum --format=count
wp post list --post_type=topic --format=count
wp post list --post_type=reply --format=count
```

**预期结果**: 17 个论坛 + 多个主题和回复

---

### 3. Easy Digital Downloads

**数据文件**: `easy-digital-downloads/sample-products-import.xml`

```bash
cd /usr/local/var/www

# 导入下载项
wp import /Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data/easy-digital-downloads/sample-products-import.xml --authors=create

# 验证
wp post list --post_type=download --format=count
```

**预期结果**: 多个下载项

---

### 4. Academy LMS

**方式**: Starter Templates 扩展

```bash
cd /usr/local/var/www

# 1. 安装 Starter Templates 插件
wp plugin install academy-starter-templates --activate

# 2. 手动操作（后台）
# - 登录 WordPress 后台
# - 侧边栏 → Academy Starter
# - 选择一个模板（Marketplace / University / Instructor）
# - 点击 "Import Demo"
# - 等待导入完成（约 2-5 分钟）

# 3. 验证
wp post list --post_type=academy_courses --format=count
wp post list --post_type=academy_lessons --format=count
```

**预期结果**: 多个课程和课时

---

### 5. MasterStudy LMS

**方式**: Demo Import（后台手动）

```bash
cd /usr/local/var/www

# 1. 激活插件（已激活）
wp plugin is-active masterstudy-lms-learning-management-system

# 2. 手动操作（后台）
# - 登录 WordPress 后台
# - 侧边栏 → STM LMS → Demo Import
# - 选择一个 Demo
# - 点击 "Import"
# - 等待导入完成

# 3. 验证
wp post list --post_type=stm-courses --format=count
wp post list --post_type=stm-lessons --format=count
```

**预期结果**: 多个课程、课时、测验

---

### 6. BuddyPress

**方式**: BP Default Data 插件

```bash
cd /usr/local/var/www

# 1. 激活插件（已激活）
wp plugin is-active bp-default-data

# 2. 手动操作（后台）
# - 登录 WordPress 后台
# - 工具 → BP Default Data
# - 设置数量：
#   - Users: 20
#   - Groups: 10
#   - Activities: 50
# - 点击 "Generate"
# - 等待生成完成

# 3. 验证
wp user list --format=count
echo "BuddyPress 组件需要在后台查看"
```

**预期结果**: 20 个用户 + 10 个群组 + 50 条活动

---

## 自定义数据填充

### 使用 v4 扫描结果

**步骤 1**: 运行 v4 扫描

```bash
cd /usr/local/var/www
wp eval-file /Users/zhangxiao/wptsall-dev/dev-tools/scanning/v4-smart-scanner/bin/run-smart-scan-all.php
```

**步骤 2**: 查看扫描结果

```bash
cd /Users/zhangxiao/wptsall-dev/dev-tools/scanning/v4-smart-scanner/output

# 查看 classified-listing 的字段
cat classified-listing-rtcl_listing.json | jq '.fields | keys'

# 查看 URL 模式
cat classified-listing-rtcl_listing.json | jq '.url_info.frontend_urls'
```

**步骤 3**: 使用 seed-content.php 填充

```bash
cd /usr/local/var/www
wp eval-file /Users/zhangxiao/wptsall-dev/tests/workflow/seed-content.php A
```

`seed-content.php` 会：
1. 读取 `data-templates/classifieds.json`（如果存在）
2. 或读取 `plugin-definitions/classified-listing/seed-data.json`（如果存在）
3. 使用 WordPress REST API 创建内容
4. 保存结果到 `plan-results/A/seeding-result.json`

---

### 关键字段示例

#### classified-listing (分类信息)

**post_type**: `rtcl_listing`

**关键字段**（从 v4 扫描结果）:
```json
{
  "post_title": "北京朝阳区三居室出租",
  "post_content": "精装修，家电齐全，拎包入住",
  "meta": {
    "_rtcl_listing_type": "rent",
    "_rtcl_price": "5000",
    "_rtcl_location": "北京市朝阳区",
    "_rtcl_phone": "13800138000"
  }
}
```

**填充数量**: 建议 5-10 条

---

#### easy-property-listings (房产)

**post_type**: `property`

**关键字段**:
```json
{
  "post_title": "上海浦东新区豪华公寓",
  "post_content": "高层景观房，配套设施完善",
  "meta": {
    "_epl_property_type": "apartment",
    "_epl_property_price": "8000000",
    "_epl_property_bedrooms": "3",
    "_epl_property_bathrooms": "2",
    "_epl_property_area": "120"
  }
}
```

**填充数量**: 建议 5-10 条

---

#### cooked (食谱)

**post_type**: `cp_recipe`

**关键字段**:
```json
{
  "post_title": "红烧肉",
  "post_content": "经典中式菜肴，肥而不腻",
  "meta": {
    "_cooked_cooking_time": "60",
    "_cooked_prep_time": "30",
    "_cooked_difficulty": "medium",
    "_cooked_servings": "4"
  }
}
```

**填充数量**: 建议 5-10 条

---

#### envira-gallery-lite (图库)

**post_type**: `envira`

**关键字段**:
```json
{
  "post_title": "旅行相册 - 云南行",
  "post_content": "2024年云南旅行照片集",
  "meta": {
    "_eg_gallery_data": "{...}"
  }
}
```

**填充数量**: 建议 3-5 个图库

---

#### site-reviews (评论)

**post_type**: `site-review`

**关键字段**:
```json
{
  "post_title": "非常满意的购物体验",
  "post_content": "商品质量很好，物流也很快",
  "meta": {
    "_rating": "5",
    "_reviewer_name": "张三",
    "_reviewer_email": "zhangsan@example.com"
  }
}
```

**填充数量**: 建议 10-20 条

---

#### testimonial-free (推荐语)

**post_type**: `spt_testimonial`

**关键字段**:
```json
{
  "post_title": "优秀的服务团队",
  "post_content": "非常专业，解决问题迅速",
  "meta": {
    "_spt_testimonial_author": "李四",
    "_spt_testimonial_company": "某某科技公司",
    "_spt_testimonial_rating": "5"
  }
}
```

**填充数量**: 建议 5-10 条

---

## 填充顺序

### 阶段 1: WordPress Core（一次性）

```bash
cd /usr/local/var/www
wp import /Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data/wordpress-core/themeunittestdata.wordpress.xml --authors=create
```

**结果**: 30+ posts, pages, 分类, 标签

---

### 阶段 2: 官方数据（优先）

**顺序**:
1. WooCommerce（最重要，依赖多）
2. Easy Digital Downloads
3. bbPress
4. BuddyPress（需要用户）
5. Academy LMS（手动）
6. MasterStudy LMS（手动）

**时间**: 约 5-10 分钟（不含手动操作）

---

### 阶段 3: 自定义数据

```bash
cd /usr/local/var/www
wp eval-file /Users/zhangxiao/wptsall-dev/tests/workflow/seed-content.php A
```

**填充插件**:
- classified-listing
- easy-property-listings
- cooked
- envira-gallery-lite
- site-reviews
- testimonial-free

**时间**: 约 2-3 分钟

---

## 完整流程

### 一键运行脚本

创建文件：`dev-tools/seeding/official-data/scripts/fill-plan-a.sh`

```bash
#!/bin/bash
#
# Plan A 完整数据填充脚本
#
# Usage: bash fill-plan-a.sh
#

set -e

DEV=/Users/zhangxiao/wptsall-dev
WP=/usr/local/var/www
OFFICIAL_DATA=$DEV/dev-tools/seeding/official-data

cd $WP

echo "=========================================="
echo "   Plan A 数据填充"
echo "=========================================="
echo ""

# ==========================================
# 阶段 1: WordPress Core（检查）
# ==========================================

echo "阶段 1: 检查 WordPress Core 数据..."
POST_COUNT=$(wp post list --post_type=post,page --format=count 2>/dev/null)

if [ "$POST_COUNT" -lt 20 ]; then
    echo "填充 WordPress Core 数据..."
    wp import $OFFICIAL_DATA/wordpress-core/themeunittestdata.wordpress.xml --authors=create
else
    echo "✅ WordPress Core 数据已存在 ($POST_COUNT posts)"
fi

echo ""

# ==========================================
# 阶段 2: 官方数据填充
# ==========================================

echo "阶段 2: 填充官方数据..."
echo ""

# 1. WooCommerce
echo "1/6 WooCommerce..."
if wp plugin is-active woocommerce 2>/dev/null; then
    PRODUCT_COUNT=$(wp post list --post_type=product --format=count 2>/dev/null || echo "0")
    if [ "$PRODUCT_COUNT" -eq 0 ]; then
        wp wc product import $OFFICIAL_DATA/woocommerce/sample_products.csv --user=1 --quiet 2>/dev/null && echo "  ✅ 导入成功" || echo "  ⚠️  导入失败"
    else
        echo "  ✅ 已有 $PRODUCT_COUNT 个产品"
    fi
fi

# 2. Easy Digital Downloads
echo "2/6 Easy Digital Downloads..."
if wp plugin is-active easy-digital-downloads 2>/dev/null; then
    DOWNLOAD_COUNT=$(wp post list --post_type=download --format=count 2>/dev/null || echo "0")
    if [ "$DOWNLOAD_COUNT" -eq 0 ]; then
        wp import $OFFICIAL_DATA/easy-digital-downloads/sample-products-import.xml --authors=create --quiet 2>/dev/null && echo "  ✅ 导入成功" || echo "  ⚠️  导入失败"
    else
        echo "  ✅ 已有 $DOWNLOAD_COUNT 个下载项"
    fi
fi

# 3. bbPress
echo "3/6 bbPress..."
if wp plugin is-active bbpress 2>/dev/null; then
    FORUM_COUNT=$(wp post list --post_type=forum --format=count 2>/dev/null || echo "0")
    if [ "$FORUM_COUNT" -eq 0 ]; then
        if [ -f "$OFFICIAL_DATA/downloads/bbpress/bbpress-sample-data.xml" ]; then
            wp import $OFFICIAL_DATA/downloads/bbpress/bbpress-sample-data.xml --authors=create --quiet 2>/dev/null && echo "  ✅ 导入成功" || echo "  ⚠️  导入失败"
        else
            echo "  ⚠️  数据文件不存在（需下载）"
        fi
    else
        echo "  ✅ 已有 $FORUM_COUNT 个论坛"
    fi
fi

# 4-6. 手动导入插件
echo "4/6 BuddyPress..."
echo "  ⚠️  需手动操作: 工具 → BP Default Data → Generate"

echo "5/6 Academy LMS..."
echo "  ⚠️  需手动操作: Academy Starter → Import Demo"

echo "6/6 MasterStudy LMS..."
echo "  ⚠️  需手动操作: STM LMS → Demo Import"

echo ""

# ==========================================
# 阶段 3: 自定义数据填充
# ==========================================

echo "阶段 3: 填充自定义数据..."
echo ""

wp eval-file $DEV/tests/workflow/seed-content.php A

echo ""
echo "=========================================="
echo "   填充完成"
echo "=========================================="
echo ""
echo "查看结果:"
echo "  wp eval-file $DEV/tests/workflow/show-plan-result.php A"
```

**使用**:

```bash
cd /Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data/scripts
bash fill-plan-a.sh
```

---

## 验证填充结果

```bash
cd /usr/local/var/www

# 查看各插件的内容数量
echo "WooCommerce:"
wp post list --post_type=product --format=count

echo "bbPress:"
wp post list --post_type=forum --format=count
wp post list --post_type=topic --format=count

echo "EDD:"
wp post list --post_type=download --format=count

echo "Academy LMS:"
wp post list --post_type=academy_courses --format=count

echo "MasterStudy LMS:"
wp post list --post_type=stm-courses --format=count

echo "Classified Listing:"
wp post list --post_type=rtcl_listing --format=count

echo "Property:"
wp post list --post_type=property --format=count

echo "Recipes:"
wp post list --post_type=cp_recipe --format=count

echo "Galleries:"
wp post list --post_type=envira --format=count

echo "Reviews:"
wp post list --post_type=site-review --format=count

echo "Testimonials:"
wp post list --post_type=spt_testimonial --format=count
```

---

## 相关文档

- [TESTING-WORKFLOW.md](TESTING-WORKFLOW.md) - 完整测试工作流
- [README.md](README.md) - 官方数据使用指南
- [v4-smart-scanner README](../../scanning/v4-smart-scanner/README.md) - 字段扫描工具

---

**最后更新**: 2026-01-26
**维护**: WPTSALL 开发团队

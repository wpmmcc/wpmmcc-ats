# Plan A 完整填充策略（全部 20 个插件）

**更新时间**: 2026-01-22
**目标**: 填充 Plan A 全部 20 个插件的存储数据（12 Content + 8 Utility）

## 总体原则

1. **官方数据优先**: 有官方数据的插件使用官方数据（WooCommerce, EDD, bbPress, BuddyPress, Academy, MasterStudy）
2. **REST API 填充**: 无官方数据但有 REST 端点的插件使用 REST API + 数据模板
3. **后台操作**: 无 REST 端点的插件需要通过后台手动操作或 WP-CLI

## 填充计划概览

| # | 插件 | 类型 | 存储方式 | 填充方法 | 优先级 |
|---|------|------|----------|----------|--------|
| 1 | WooCommerce | Content | post_type: product | 官方 CSV | P1 |
| 2 | bbPress | Content | post_type: forum/topic/reply | 官方 XML | P1 |
| 3 | Academy LMS | Content | post_type: academy_courses | 官方 Starter Templates | P1 |
| 4 | MasterStudy LMS | Content | post_type: stm-courses | 官方 Demo Import | P1 |
| 5 | Easy Digital Downloads | Content | post_type: download | 官方 XML | P1 |
| 6 | BuddyPress | Utility | custom tables: users/groups | 官方 BP Default Data | P1 |
| 7 | Classified Listing | Content | post_type: rtcl_listing | v4扫描 + REST API | P2 |
| 8 | Easy Property Listings | Content | post_type: property | v4扫描 + REST API | P2 |
| 9 | Cooked | Content | post_type: cp_recipe | v4扫描 + REST API | P2 |
| 10 | Envira Gallery | Content | post_type: envira | v4扫描 + REST API | P2 |
| 11 | Site Reviews | Content | post_type: site-review | v4扫描 + REST API | P2 |
| 12 | Testimonial Free | Content | post_type: spt_testimonial | v4扫描 + REST API | P2 |
| 13 | Contact Form 7 | Utility | post_type: wpcf7_contact_form | REST API + 模板 | P3 |
| 14 | Easy Appointments | Utility | post_type: easyapp | REST API + 模板 | P3 |
| 15 | Simply Schedule | Utility | post_type: ssa_appointment | REST API + 模板 | P3 |
| 16 | Yoast SEO | Utility | postmeta: _yoast_wpseo_* | 跟随内容自动添加 | P3 |
| 17 | PowerPress | Utility | postmeta: enclosure, powerpress_* | 跟随内容添加音频 meta | P3 |
| 18 | Advanced Custom Fields | Utility | post_type: acf-field-group | 后台创建字段组 | P4 |
| 19 | TablePress | Content | custom tables: wp_tablepress_* | 后台手动创建表格 | P4 |
| 20 | Redirection | Utility | custom tables: wp_redirection_* | 后台手动创建规则 | P4 |

## 优先级说明

- **P1 (6个)**: 官方数据，必须首先填充，自动化程度高
- **P2 (6个)**: v4扫描 + REST API，已有工作流支持
- **P3 (5个)**: 可通过脚本自动化或半自动化
- **P4 (3个)**: 需要后台手动操作

---

## 阶段 1: 官方数据（6个插件）

### 1.1 WooCommerce (product)

**数据来源**: `official-data/woocommerce/sample_products.csv`

```bash
wp wc product import official-data/woocommerce/sample_products.csv --user=1
```

**验证**:
```bash
wp post list --post_type=product --format=count
# 期望: ≥9 个产品
```

---

### 1.2 Easy Digital Downloads (download)

**数据来源**: `official-data/easy-digital-downloads/sample-products-import.xml`

```bash
wp import official-data/easy-digital-downloads/sample-products-import.xml --authors=create
```

**验证**:
```bash
wp post list --post_type=download --format=count
# 期望: ≥5 个下载项
```

---

### 1.3 bbPress (forum/topic/reply)

**数据来源**: `official-data/downloads/bbpress/bbpress-sample-data.xml`
**注意**: 需要先下载官方数据

```bash
# 下载官方数据（如果不存在）
curl -o official-data/downloads/bbpress/bbpress-sample-data.xml \
  https://bbpress.org/forums/topic/sample-forum-data/

# 导入
wp import official-data/downloads/bbpress/bbpress-sample-data.xml --authors=create
```

**验证**:
```bash
wp post list --post_type=forum --format=count
# 期望: ≥3 个论坛
```

---

### 1.4 BuddyPress (users/groups/activity)

**数据来源**: BP Default Data 插件

**前置条件**:
1. 激活 BuddyPress 插件
2. 激活 BP Default Data 插件

**手动操作**:
1. WordPress 后台 → 工具 → BP Default Data
2. 点击 "Generate Default Data"
3. 等待生成完成（约 1-2 分钟）

**验证**:
```bash
wp user list --format=count
# 期望: ≥10 个用户

wp eval 'echo BP_Groups_Group::get_total_group_count();'
# 期望: ≥5 个群组
```

---

### 1.5 Academy LMS (academy_courses/academy_lessons)

**数据来源**: Academy Starter Templates 插件

**前置条件**:
1. 激活 Academy 插件
2. 安装并激活 Academy Starter Templates 插件

**手动操作**:
1. WordPress 后台 → Academy → Starter Templates
2. 选择一个 Demo 模板（推荐: "Online Course Marketplace"）
3. 点击 "Import"
4. 等待导入完成

**验证**:
```bash
wp post list --post_type=academy_courses --format=count
# 期望: ≥5 个课程
```

---

### 1.6 MasterStudy LMS (stm-courses/stm-lessons/stm-quizzes)

**数据来源**: MasterStudy Demo Import 功能

**手动操作**:
1. WordPress 后台 → STM LMS → Demo Import
2. 选择 "Classic Demo" 或 "Online Course"
3. 点击 "Import Demo"
4. 等待导入完成（约 2-3 分钟）

**验证**:
```bash
wp post list --post_type=stm-courses --format=count
# 期望: ≥8 个课程
```

---

## 阶段 2: 自定义数据（6个插件）

这些插件已在 `seed-content.php` 中实现，使用 v4 扫描 + REST API 填充：

1. Classified Listing (rtcl_listing)
2. Easy Property Listings (property)
3. Cooked (cp_recipe)
4. Envira Gallery (envira)
5. Site Reviews (site-review)
6. Testimonial Free (spt_testimonial)

**执行方式**:
```bash
cd /usr/local/var/www
wp eval-file /Users/zhangxiao/wptsall-dev/tests/workflow/seed-content.php A
```

**验证**:
```bash
# 检查所有 6 个插件的 post_type
for type in rtcl_listing property cp_recipe envira site-review spt_testimonial; do
  echo -n "$type: "
  wp post list --post_type=$type --format=count 2>/dev/null || echo "0"
done
```

---

## 阶段 3: Utility 插件填充（8个插件）

### 3.1 Contact Form 7 (wpcf7_contact_form)

**方法**: 通过 REST API 创建表单

**数据模板**: `tests/workflow/data-templates/contact-form-7.json`

```json
[
  {
    "title": "Contact Form - English",
    "content": "[text* your-name placeholder \"Your Name\"]\n[email* your-email placeholder \"Email\"]\n[textarea your-message placeholder \"Message\"]\n[submit \"Send\"]"
  },
  {
    "title": "Contact Form - Spanish",
    "content": "[text* your-name placeholder \"Tu Nombre\"]\n[email* your-email placeholder \"Correo\"]\n[textarea your-message placeholder \"Mensaje\"]\n[submit \"Enviar\"]"
  }
]
```

**填充脚本**: 在 `seed-content.php` 中添加 Contact Form 7 填充逻辑

**验证**:
```bash
wp post list --post_type=wpcf7_contact_form --format=count
# 期望: ≥2 个表单
```

---

### 3.2 Easy Appointments (easyapp)

**方法**: 通过 REST API 创建预约类型

**数据模板**: `tests/workflow/data-templates/easy-appointments.json`

```json
[
  {
    "title": "Dental Checkup",
    "duration": 30,
    "price": 50
  },
  {
    "title": "Consultation",
    "duration": 60,
    "price": 100
  }
]
```

**验证**:
```bash
wp post list --post_type=easyapp --format=count
# 期望: ≥2 个预约类型
```

---

### 3.3 Simply Schedule Appointments (ssa_appointment)

**方法**: 通过 REST API 创建预约类型

**数据模板**: `tests/workflow/data-templates/simply-schedule-appointments.json`

```json
[
  {
    "title": "15-Minute Consultation",
    "duration": 15
  },
  {
    "title": "30-Minute Meeting",
    "duration": 30
  }
]
```

**验证**:
```bash
wp post list --post_type=ssa_appointment_type --format=count
# 期望: ≥2 个预约类型
```

---

### 3.4 Yoast SEO (_yoast_wpseo_*)

**方法**: 在 `seed-content.php` 中为每个创建的内容自动添加 Yoast SEO meta

**需要添加的 meta 字段**:
- `_yoast_wpseo_title`: SEO 标题
- `_yoast_wpseo_metadesc`: SEO 描述
- `_yoast_wpseo_focuskw`: 焦点关键词

**实现位置**: `seed-content.php` 的 `create_post_via_rest()` 函数

**修改示例**:
```php
// 在创建内容后添加 Yoast SEO meta
if ( $post_id && function_exists( 'wpseo_init' ) ) {
    update_post_meta( $post_id, '_yoast_wpseo_title', $item['title'] . ' - SEO' );
    update_post_meta( $post_id, '_yoast_wpseo_metadesc', substr( $item['content'], 0, 155 ) );
    update_post_meta( $post_id, '_yoast_wpseo_focuskw', 'test' );
}
```

**验证**:
```bash
# 检查任意一个 product 是否有 Yoast meta
wp post meta list $(wp post list --post_type=product --format=ids --posts_per_page=1) | grep yoast
# 期望: 看到 _yoast_wpseo_title 等字段
```

---

### 3.5 PowerPress (enclosure, powerpress_*)

**方法**: 为部分 post 添加播客 meta

**需要添加的 meta 字段**:
- `enclosure`: 音频文件 URL
- `powerpress_url`: PowerPress 音频 URL
- `powerpress_duration`: 时长

**实现位置**: `seed-content.php` 中为 10% 的 post 随机添加播客 meta

**示例**:
```php
// 为 10% 的 post 添加播客 meta
if ( $post_type === 'post' && rand( 1, 10 ) === 1 ) {
    $audio_url = 'https://example.com/podcast/episode-' . $post_id . '.mp3';
    update_post_meta( $post_id, 'enclosure', $audio_url . "\n0\naudio/mpeg" );
    update_post_meta( $post_id, 'powerpress_url', $audio_url );
    update_post_meta( $post_id, 'powerpress_duration', '15:30' );
}
```

**验证**:
```bash
# 检查有多少 post 有播客 meta
wp eval 'echo $wpdb->get_var("SELECT COUNT(DISTINCT post_id) FROM wp_postmeta WHERE meta_key = \"enclosure\"");'
# 期望: ≥3 个 post
```

---

### 3.6 Advanced Custom Fields (acf-field-group)

**方法**: 后台手动创建字段组

**手动操作**:
1. WordPress 后台 → ACF → Field Groups
2. 点击 "Add New"
3. 创建 2-3 个字段组，例如:
   - **Product Details**: 添加到 product post_type
     - 字段: `product_code` (text), `warranty` (textarea)
   - **Author Bio**: 添加到 post post_type
     - 字段: `author_twitter` (text), `author_bio` (wysiwyg)

**验证**:
```bash
wp post list --post_type=acf-field-group --format=count
# 期望: ≥2 个字段组
```

---

### 3.7 TablePress (wp_tablepress_*)

**方法**: 后台手动创建表格

**手动操作**:
1. WordPress 后台 → TablePress → Add New
2. 创建 2-3 个表格，例如:
   - **Price Comparison**: 5行 x 4列
   - **Feature Matrix**: 8行 x 3列

**验证**:
```bash
wp eval 'echo $wpdb->get_var("SELECT COUNT(*) FROM wp_tablepress_tables");'
# 期望: ≥2 个表格
```

---

### 3.8 Redirection (wp_redirection_items)

**方法**: 后台手动创建重定向规则

**手动操作**:
1. WordPress 后台 → Tools → Redirection
2. 添加 3-5 条重定向规则，例如:
   - `/old-page` → `/new-page`
   - `/blog/old-post` → `/blog/new-post`

**验证**:
```bash
wp eval 'echo $wpdb->get_var("SELECT COUNT(*) FROM wp_redirection_items");'
# 期望: ≥3 条规则
```

---

## 完整执行流程

### 自动化部分（脚本执行）

```bash
cd /usr/local/var/www
DEV=/Users/zhangxiao/wptsall-dev

# 1. 官方数据（自动化的3个）
bash $DEV/dev-tools/seeding/official-data/scripts/fill-plan-a.sh

# 2. 自定义数据（6个 Content + 3个 Utility with REST API）
wp eval-file $DEV/tests/workflow/seed-content.php A
```

### 手动操作部分（需要人工介入）

1. **BuddyPress**: 后台 → 工具 → BP Default Data → Generate
2. **Academy LMS**: 后台 → Academy → Starter Templates → Import Demo
3. **MasterStudy LMS**: 后台 → STM LMS → Demo Import → Classic Demo
4. **Advanced Custom Fields**: 后台 → ACF → 创建 2-3 个字段组
5. **TablePress**: 后台 → TablePress → 创建 2-3 个表格
6. **Redirection**: 后台 → Tools → Redirection → 添加 3-5 条规则

---

## 验证脚本

完整验证脚本: `scripts/verify-plan-a.sh`

```bash
bash /Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data/scripts/verify-plan-a.sh
```

**期望结果**:
- 总计: 20 个插件
- 有数据: ≥18 个（90% 覆盖率）
- 数据覆盖率: ≥90%

---

## 数据量预期

| 插件 | 预期数据量 | 验证命令 |
|------|-----------|----------|
| WordPress Core | 50+ posts/pages | `wp post list --post_type=post,page --format=count` |
| WooCommerce | 9+ products | `wp post list --post_type=product --format=count` |
| bbPress | 3+ forums | `wp post list --post_type=forum --format=count` |
| Academy LMS | 5+ courses | `wp post list --post_type=academy_courses --format=count` |
| MasterStudy LMS | 8+ courses | `wp post list --post_type=stm-courses --format=count` |
| Easy Digital Downloads | 5+ downloads | `wp post list --post_type=download --format=count` |
| Classified Listing | 10+ listings | `wp post list --post_type=rtcl_listing --format=count` |
| Easy Property Listings | 10+ properties | `wp post list --post_type=property --format=count` |
| Cooked | 10+ recipes | `wp post list --post_type=cp_recipe --format=count` |
| Envira Gallery | 5+ galleries | `wp post list --post_type=envira --format=count` |
| Site Reviews | 10+ reviews | `wp post list --post_type=site-review --format=count` |
| Testimonial Free | 10+ testimonials | `wp post list --post_type=spt_testimonial --format=count` |
| BuddyPress | 10+ users, 5+ groups | `wp user list --format=count` |
| Contact Form 7 | 2+ forms | `wp post list --post_type=wpcf7_contact_form --format=count` |
| Easy Appointments | 2+ appointment types | `wp post list --post_type=easyapp --format=count` |
| Simply Schedule | 2+ appointment types | `wp post list --post_type=ssa_appointment_type --format=count` |
| Yoast SEO | 所有内容都有 meta | `检查任意内容的 _yoast_wpseo_* meta` |
| PowerPress | 3+ posts 有播客 meta | `检查 enclosure meta 数量` |
| Advanced Custom Fields | 2+ field groups | `wp post list --post_type=acf-field-group --format=count` |
| TablePress | 2+ tables | `检查 wp_tablepress_tables 表` |
| Redirection | 3+ rules | `检查 wp_redirection_items 表` |

---

## 注意事项

1. **官方数据文件**: bbPress 官方数据需要先下载到 `official-data/downloads/bbpress/`
2. **插件激活**: 所有插件必须先激活才能填充数据
3. **执行顺序**: 必须先填充官方数据（P1），再填充自定义数据（P2-P4）
4. **依赖插件**: Academy Starter Templates, BP Default Data 需要额外安装
5. **手动操作**: 6 个插件需要后台手动操作（标记为"需手动操作"）
6. **时间估算**:
   - 自动化部分: 5-10 分钟
   - 手动操作: 10-15 分钟
   - 总计: 15-25 分钟

---

## 后续优化

1. **Contact Form 7, Easy Appointments, Simply Schedule**: 在 `seed-content.php` 中添加自动填充逻辑
2. **Yoast SEO, PowerPress**: 在 `seed-content.php` 中自动为内容添加 meta
3. **ACF, TablePress, Redirection**: 研究是否可以通过 WP-CLI 命令自动化

---

**文档版本**: 1.0
**最后更新**: 2026-01-22
**作者**: Claude Code

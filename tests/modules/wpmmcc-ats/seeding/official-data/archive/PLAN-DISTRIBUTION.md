# 官方数据 - 计划分配与填充方式

**整理日期**: 2026-01-26
**总插件数**: 64 个（跨 4 个计划）
**官方数据插件**: 10 个（去重后）

---

## 📊 总览统计

| 计划 | Content 插件 | Utility 插件 | 总计 | 官方数据（可用） | 自定义填充 |
|------|-------------|-------------|------|-----------------|-----------|
| **Plan A** | 12 | 8 | **20** | 5 | 15 |
| **Plan B** | 11 | 7 | **18** | 2 | 16 |
| **Plan C** | 8 | 5 | **13** | 2 | 11 |
| **Plan D** | 10 | 3 | **13** | 3 | 10 |
| **总计** | 41 | 23 | **64** | 12次使用 | 52 |

**说明**:
- WordPress Core 在 4 个计划中都使用
- 去重后的**可用**官方数据插件为 8 个（CLI 7个 + Playwright 1个）
- Academy LMS 和 MasterStudy LMS 因权限/付费问题改为自定义填充

---

## 🎯 Plan A - 基础商业套件（20个插件）

### 描述
电商、论坛、LMS、下载、分类、房产、食谱 - 最常用的商业插件组合

### Content 插件（12个）

| # | 插件 Slug | 插件名称 | Post Type | 官方数据 | 填充方式 |
|---|----------|---------|-----------|---------|---------|
| 1 | wordpress-core | WordPress 核心 | `post`, `page` | ✅ XML | wp import |
| 2 | **woocommerce** | WooCommerce | `product` | ✅ CSV/XML | wp wc / wp import |
| 3 | **bbpress** | bbPress | `forum`, `topic`, `reply` | ✅ XML | wp import（需下载） |
| 4 | academy | Academy LMS | `academy_courses`, `academy_lessons` | ❌ | 自定义填充（Starter Templates 权限问题） |
| 5 | masterstudy-lms | MasterStudy LMS | `stm-courses`, `stm-lessons` | ❌ | 自定义填充（需购买主题） |
| 6 | **easy-digital-downloads** | Easy Digital Downloads | `download` | ✅ XML | wp import |
| 7 | classified-listing | Classified Listing | `rtcl_listing` | ❌ | 自定义填充 |
| 8 | easy-property-listings | Easy Property Listings | `property` | ❌ | 自定义填充 |
| 9 | cooked | Cooked | `cp_recipe` | ❌ | 自定义填充 |
| 10 | envira-gallery-lite | Envira Gallery | `envira` | ❌ | 自定义填充 |
| 11 | site-reviews | Site Reviews | `site-review` | ❌ | 自定义填充 |
| 12 | tablepress | TablePress | (自定义表) | ❌ | 手动创建表格 |
| 13 | testimonial-free | Testimonial Free | `testimonial` | ❌ | 自定义填充 |

### Utility 插件（8个）

| # | 插件 Slug | 插件名称 | 功能类型 | 官方数据 | 填充方式 |
|---|----------|---------|---------|---------|---------|
| 14 | wordpress-seo | Yoast SEO | SEO 配置 | ❌ | 配置型，不填充 |
| 15 | advanced-custom-fields | ACF | 字段管理 | ❌ | 配置型，不填充 |
| 16 | contact-form-7 | Contact Form 7 | 表单 | ❌ | 配置型，少量表单 |
| 17 | **buddypress** | BuddyPress | 社区 | ✅ CLI | `wp bp member/group generate` |
| 18 | easy-appointments | Easy Appointments | 预约 | ❌ | 少量预约 |
| 19 | simply-schedule-appointments | Simply Schedule | 预约 | ❌ | 少量预约 |
| 20 | powerpress | PowerPress | 播客 | ❌ | 少量播客 |
| 21 | redirection | Redirection | 重定向 | ❌ | 配置型，不填充 |

### 官方数据填充顺序（Phase 1）

```bash
OFFICIAL_DATA=/Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data
cd /usr/local/var/www

# 1. WordPress Core
wp import $OFFICIAL_DATA/wordpress-core/themeunittestdata.wordpress.xml --authors=create

# 2. WooCommerce (PHP 脚本 CSV 导入)
wp eval-file $OFFICIAL_DATA/scripts/wc-csv-import.php

# 3. Easy Digital Downloads
wp import $OFFICIAL_DATA/easy-digital-downloads/sample-products-import.xml --authors=create

# 4. bbPress
wp import $OFFICIAL_DATA/downloads/bbpress/bbpress-sample-data.xml --authors=create

# 5. BuddyPress (WP-CLI 生成)
wp bp component activate groups
wp bp member generate --count=20
wp bp group generate --count=5
```

### 自定义填充（Phase 2）

```bash
# Academy LMS 和 MasterStudy LMS 无法使用官方数据，与其他 14 个插件一起自定义填充
wp eval-file /Users/zhangxiao/wptsall-dev/dev-tools/seeding/seed-content.php A
```

---

## 🎯 Plan B - 替代套件（18个插件）

### 描述
目录、活动、招聘、论坛、食谱、图库 - 提供替代选择的插件组合

### Content 插件（11个）

| # | 插件 Slug | 插件名称 | Post Type | 官方数据 | 填充方式 |
|---|----------|---------|-----------|---------|---------|
| 1 | wordpress-core | WordPress 核心 | `post`, `page` | ✅ XML | wp import |
| 2 | ecwid-shopping-cart | Ecwid | (外部托管) | ❌ | 外部配置 |
| 3 | directorist | Directorist | `at_biz_dir` | ❌ | 自定义填充 |
| 4 | the-events-calendar | The Events Calendar | `tribe_events` | ❌ | 自定义填充 |
| 5 | wp-job-manager | WP Job Manager | `job_listing` | ❌ | 自定义填充 |
| 6 | **tutor** | Tutor LMS | `courses`, `lesson` | ✅ XML | wp import（需下载） |
| 7 | essential-real-estate | Essential Real Estate | `property` | ❌ | 自定义填充 |
| 8 | asgaros-forum | Asgaros Forum | (自定义表) | ❌ | 自定义填充 |
| 9 | delicious-recipes | Delicious Recipes | `recipe` | ❌ | 自定义填充 |
| 10 | foogallery | FooGallery | `foogallery` | ❌ | 自定义填充 |
| 11 | reviews-feed | Reviews Feed | (外部源) | ❌ | 配置型 |
| 12 | portfolio-post-type | Portfolio | `portfolio` | ❌ | 自定义填充 |

### Utility 插件（7个）

| # | 插件 Slug | 插件名称 | 功能类型 | 官方数据 | 填充方式 |
|---|----------|---------|---------|---------|---------|
| 13 | all-in-one-seo-pack | All in One SEO | SEO 配置 | ❌ | 配置型，不填充 |
| 14 | meta-box | Meta Box | 字段管理 | ❌ | 配置型，不填充 |
| 15 | ninja-forms | Ninja Forms | 表单 | ❌ | 配置型，少量表单 |
| 16 | paid-member-subscriptions | PMS | 会员 | ❌ | 少量会员计划 |
| 17 | booking | Booking Calendar | 预约 | ❌ | 少量预约 |
| 18 | fluent-booking | Fluent Booking | 预约 | ❌ | 少量预约 |
| 19 | seriously-simple-podcasting | SSP | 播客 | ❌ | 少量播客 |

### 官方数据填充顺序（Phase 1）

```bash
OFFICIAL_DATA=/Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data
cd /usr/local/var/www

# 1. WordPress Core
wp import $OFFICIAL_DATA/wordpress-core/themeunittestdata.wordpress.xml --authors=create

# 2. Tutor LMS
wp import $OFFICIAL_DATA/downloads/tutor-lms/tutor-sample-course.xml --authors=create
```

### 自定义填充（Phase 2）

```bash
wp eval-file /Users/zhangxiao/wptsall-dev/dev-tools/seeding/seed-content.php B
```

---

## 🎯 Plan C - 传统小众套件（13个插件）

### 描述
Estatik 房产、WPForo 论坛、食谱、FAQ - 传统和小众插件

### Content 插件（8个）

| # | 插件 Slug | 插件名称 | Post Type | 官方数据 | 填充方式 |
|---|----------|---------|-----------|---------|---------|
| 1 | wordpress-core | WordPress 核心 | `post`, `page` | ✅ XML | wp import |
| 2 | wp-easycart | WP EasyCart | `ec_store_product` | ❌ | 自定义填充 |
| 3 | wpforo | WPForo | (自定义表) | ❌ | 自定义填充 |
| 4 | **sensei-lms** | Sensei LMS | `course`, `lesson` | ✅ CLI | `wp sensei-import` |
| 5 | estatik | Estatik | `properties` | ❌ | 自定义填充 |
| 6 | wp-recipe-maker | WP Recipe Maker | `wprm_recipe` | ❌ | 自定义填充 |
| 7 | simple-job-board | Simple Job Board | `jobpost` | ❌ | 自定义填充 |
| 8 | ultimate-faqs | Ultimate FAQs | `ufaq` | ❌ | 自定义填充 |
| 9 | wp-customer-reviews | WP Customer Reviews | (评论系统) | ❌ | 少量评论 |

### Utility 插件（5个）

| # | 插件 Slug | 插件名称 | 功能类型 | 官方数据 | 填充方式 |
|---|----------|---------|---------|---------|---------|
| 10 | seo-by-rank-math | Rank Math SEO | SEO 配置 | ❌ | 配置型，不填充 |
| 11 | pods | Pods | 字段管理 | ❌ | 配置型，不填充 |
| 12 | restrict-content | Restrict Content | 会员限制 | ❌ | 配置型 |
| 13 | bookly-responsive-appointment-booking-tool | Bookly | 预约 | ❌ | 少量预约 |
| 14 | podcast-player | Podcast Player | 播客 | ❌ | 少量播客 |

### 官方数据填充顺序（Phase 1）

```bash
OFFICIAL_DATA=/Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data
cd /usr/local/var/www

# 1. WordPress Core
wp import $OFFICIAL_DATA/wordpress-core/themeunittestdata.wordpress.xml --authors=create

# 2. Sensei LMS (WP-CLI CSV 导入)
cp $OFFICIAL_DATA/sensei-lms/courses.csv ./sensei-courses.csv
cp $OFFICIAL_DATA/sensei-lms/lessons.csv ./sensei-lessons.csv
wp sensei-import --user=admin --courses=sensei-courses.csv --lessons=sensei-lessons.csv
rm sensei-courses.csv sensei-lessons.csv
```

### 自定义填充（Phase 2）

```bash
wp eval-file /Users/zhangxiao/wptsall-dev/dev-tools/seeding/seed-content.php C
```

---

## 🎯 Plan D - 扩展套件（13个插件）

### 描述
LearnPress、HivePress、GeoDirectory、ForumWP - 扩展功能插件

### Content 插件（10个）

| # | 插件 Slug | 插件名称 | Post Type | 官方数据 | 填充方式 |
|---|----------|---------|-----------|---------|---------|
| 1 | wordpress-core | WordPress 核心 | `post`, `page` | ✅ XML | wp import |
| 2 | storeengine | StoreEngine | `se_product` | ❌ | 自定义填充 |
| 3 | **learnpress** | LearnPress | `lp_course`, `lp_lesson` | ✅ Playwright | 内置 Install Sample Data |
| 4 | **lifterlms** | LifterLMS | `course`, `lesson` | ✅ CLI | PHP 脚本 LLMS_Generator |
| 5 | hivepress | HivePress | `hp_listing` | ❌ | 自定义填充 |
| 6 | geodirectory | GeoDirectory | `gd_place` | ❌ | 自定义填充 |
| 7 | forumwp | ForumWP | `fmwp_forum`, `fmwp_topic` | ❌ | 自定义填充 |
| 8 | propertyhive | PropertyHive | `property` | ❌ | 自定义填充 |
| 9 | events-manager | Events Manager | `event` | ❌ | 自定义填充 |
| 10 | wp-job-openings | WP Job Openings | `awsm_job_openings` | ❌ | 自定义填充 |
| 11 | give | GiveWP | `give_forms` | ❌ | 少量捐赠表单 |

### Utility 插件（3个）

| # | 插件 Slug | 插件名称 | 功能类型 | 官方数据 | 填充方式 |
|---|----------|---------|---------|---------|---------|
| 12 | simple-membership | Simple Membership | 会员 | ❌ | 少量会员计划 |
| 13 | ameliabooking | Amelia | 预约 | ❌ | 少量预约 |
| 14 | podlove-podcasting-plugin-for-wordpress | Podlove | 播客 | ❌ | 少量播客 |

### 官方数据填充顺序（Phase 1）

```bash
OFFICIAL_DATA=/Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data
cd /usr/local/var/www

# 1. WordPress Core
wp import $OFFICIAL_DATA/wordpress-core/themeunittestdata.wordpress.xml --authors=create

# 2. LifterLMS (PHP 脚本 LLMS_Generator)
wp eval-file $OFFICIAL_DATA/scripts/llms-json-import.php --user=admin

# 3. LearnPress (Playwright 自动化)
cd $OFFICIAL_DATA/scripts/playwright
npx playwright test import-learnpress.spec.js
```

### 自定义填充（Phase 2）

```bash
wp eval-file /Users/zhangxiao/wptsall-dev/dev-tools/seeding/seed-content.php D
```

---

## 📋 填充方式汇总

### 官方数据插件（8个可自动导入 + 2个需自定义）

| 插件 | 所在计划 | 数据格式 | 导入方式 | 状态 |
|------|---------|---------|---------|------|
| WordPress Core | A, B, C, D | XML | `wp import` | ✅ CLI |
| WooCommerce | A | CSV | PHP 脚本 `WC_Product_CSV_Importer` | ✅ CLI |
| Easy Digital Downloads | A | XML | `wp import` | ✅ CLI |
| bbPress | A | XML | `wp import` | ✅ CLI |
| BuddyPress | A | CLI 生成 | `wp bp member/group generate` | ✅ CLI |
| Tutor LMS | B | XML | `wp import` | ✅ CLI |
| Sensei LMS | C | CSV | `wp sensei-import` | ✅ CLI |
| LifterLMS | D | JSON | PHP 脚本 `LLMS_Generator` | ✅ CLI |
| LearnPress | D | 内置 | Playwright 自动化 | ✅ Playwright |
| ~~Academy LMS~~ | A | - | ~~Starter Templates~~ | ❌ 权限问题 |
| ~~MasterStudy LMS~~ | A | - | ~~需购买主题~~ | ❌ 需付费 |

### 自定义填充插件（54个）

| 填充方式 | 插件数量 | 说明 |
|---------|---------|------|
| **REST API 填充** | ~40 | 使用 `seed-content.php` 通过 REST API 创建内容 |
| **配置型（少量/不填充）** | ~10 | SEO、字段管理、表单等配置型插件 |
| **手动创建** | ~4 | TablePress、自定义表插件等 |

---

## 🚀 统一导入脚本

### 脚本位置
```
/Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data/import-by-plan.sh
```

### 使用方法

```bash
# 导入 Plan A 的官方数据
bash import-by-plan.sh A

# 导入 Plan B 的官方数据
bash import-by-plan.sh B

# 导入所有官方数据 + 自定义填充
bash import-by-plan.sh A --with-custom
```

---

## 📊 覆盖率分析

| 维度 | 数量 | 占比 |
|------|------|------|
| **总插件数** | 64 | 100% |
| **官方数据插件（去重）** | 10 | 15.6% |
| **自定义填充插件** | 54 | 84.4% |
| **配置型（不填充内容）** | ~10 | 15.6% |
| **需要手动创建** | ~4 | 6.3% |

### 按计划覆盖率

| 计划 | 总插件 | 官方数据（可用） | 覆盖率 | 备注 |
|------|--------|-----------------|--------|------|
| Plan A | 20 | 5 | **25%** | Academy/MasterStudy 改为自定义 |
| Plan B | 18 | 2 | **11%** | - |
| Plan C | 13 | 2 | **15%** | - |
| Plan D | 13 | 3 | **23%** | 含 Playwright 自动化 |

**结论**: Plan A 仍有最多官方数据（5个），推荐作为主要测试计划。

---

## 🎯 推荐使用策略

### 场景 1: 快速演示
```bash
# 使用 Plan A（官方数据最多）
bash import-by-plan.sh A
```

### 场景 2: 完整测试
```bash
# Plan A 官方数据 + 自定义填充
bash import-by-plan.sh A --with-custom
```

### 场景 3: LMS 专项测试
```bash
# Plan D（2 个 LMS 官方数据：LearnPress + LifterLMS）
bash import-by-plan.sh D --with-custom
```

### 场景 4: 轮换测试
```bash
# 依次测试各计划
for plan in A B C D; do
    bash import-by-plan.sh $plan --with-custom
    # 运行测试...
    # 清理数据...
done
```

---

**最后更新**: 2026-01-26
**维护**: WPTSALL 开发团队

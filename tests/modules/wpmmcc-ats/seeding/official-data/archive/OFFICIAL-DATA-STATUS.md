# 官方数据可用性状态

**更新时间**: 2026-01-26
**测试状态**: 已完成全部验证（含 CLI API 测试）

## 状态说明

| 状态 | 说明 | 操作方式 |
|------|------|---------|
| ✅ CLI 自动 | 可通过 WP-CLI 命令自动导入 | 无需登录后台 |
| ⚠️ 后台手动 | 必须登录 WordPress 后台操作 | 需管理员密码 |
| ❌ 自定义 | 无官方数据，使用自定义填充脚本 | seed-content.php |

---

## 测试结果汇总

### ✅ 可 CLI 自动导入 (7个插件)

| 插件 | 计划 | 导入方式 | 测试结果 |
|------|------|---------|---------|
| **WordPress Core** | - | `wp import {xml} --authors=create` | ✅ 成功 |
| **bbPress** | A | `wp import {xml} --authors=create` | ✅ 成功 |
| **Easy Digital Downloads** | A | `wp import {xml} --authors=create` | ✅ 成功 |
| **WooCommerce** | A | PHP 脚本 `WC_Product_CSV_Importer` | ✅ 成功 (18产品) |
| **Tutor LMS** | B | `wp import {xml} --authors=create` | ✅ 成功 (11课程) |
| **Sensei LMS** | C | `wp sensei-import --user=admin` | ✅ 成功 (1课程+12课程) |
| **LifterLMS** | D | PHP 脚本 `LLMS_Generator` | ✅ 成功 (1课程+13课程) |

### ✅ 可 Playwright 自动导入 (1个插件)

| 插件 | 计划 | 导入方式 | 测试结果 |
|------|------|---------|---------|
| **LearnPress** | D | Playwright 自动化 (内置 Install Sample Data) | ✅ 成功 (2课程+72课时) |

### ✅ 可 WP-CLI 自动导入 (BuddyPress)

| 插件 | 计划 | 导入方式 | 命令 |
|------|------|---------|------|
| **BuddyPress** | A | WP-CLI `wp bp` 命令 | `wp bp member generate --count=20` + `wp bp group generate --count=5` |

### ⚠️ 需后台手动操作或使用已有数据 (2个插件)

| 插件 | 计划 | 原因 | 说明 |
|------|------|------|------|
| **Academy LMS** | A | Starter Templates 权限问题 | 多站点环境下页面无法访问，使用已有 6,215 课程 |
| **MasterStudy LMS** | A | 需购买主题授权 | Demo Import 仅主题版可用，使用已有 1,789 课程 |

---

## CLI 导入命令

### 标准 WordPress XML 导入

```bash
cd /usr/local/var/www
OFFICIAL_DATA=/Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data

# WordPress Core
wp import $OFFICIAL_DATA/wordpress-core/themeunittestdata.wordpress.xml --authors=create

# bbPress (Plan A)
wp import $OFFICIAL_DATA/downloads/bbpress/bbpress-sample-data.xml --authors=create

# Easy Digital Downloads (Plan A)
wp import $OFFICIAL_DATA/easy-digital-downloads/sample-products-import.xml --authors=create

# Tutor LMS (Plan B)
wp import $OFFICIAL_DATA/downloads/tutor-lms/tutor-sample-course.xml --authors=create
```

### WooCommerce CSV 导入

```bash
wp eval-file $OFFICIAL_DATA/scripts/wc-csv-import.php --path=/usr/local/var/www
```

### Sensei LMS CSV 导入

```bash
# 方式1: 使用 wp sensei-import 命令
cp $OFFICIAL_DATA/sensei-lms/courses.csv /usr/local/var/www/
cp $OFFICIAL_DATA/sensei-lms/lessons.csv /usr/local/var/www/
cd /usr/local/var/www && wp sensei-import --user=admin --courses=courses.csv --lessons=lessons.csv
rm /usr/local/var/www/sensei-courses.csv /usr/local/var/www/sensei-lessons.csv

# 方式2: 使用脚本
bash $OFFICIAL_DATA/scripts/sensei-csv-import.sh
```

### LifterLMS JSON 导入

```bash
wp eval-file $OFFICIAL_DATA/scripts/llms-json-import.php --user=admin --path=/usr/local/var/www
```

---

## 各计划插件状态

### Plan A (12 内容插件)

| 插件 | 状态 | 方式 |
|------|------|------|
| woocommerce | ✅ CLI | PHP 脚本 CSV 导入 |
| bbpress | ✅ CLI | wp import XML |
| easy-digital-downloads | ✅ CLI | wp import XML |
| academy | ⚠️ 后台 | Starter Templates |
| masterstudy-lms | ⚠️ 后台 | 内置 Demo Import |
| buddypress | ⚠️ 后台 | bp-default-data 插件 |
| classified-listing | ❌ 自定义 | seed-content.php |
| easy-property-listings | ❌ 自定义 | seed-content.php |
| cooked | ❌ 自定义 | seed-content.php |
| envira-gallery-lite | ❌ 自定义 | seed-content.php |
| site-reviews | ❌ 自定义 | seed-content.php |
| testimonial-free | ❌ 自定义 | seed-content.php |

### Plan B (10 内容插件)

| 插件 | 状态 | 方式 |
|------|------|------|
| tutor | ✅ CLI | wp import XML |
| directorist | ❌ 自定义 | seed-content.php |
| the-events-calendar | ❌ 自定义 | seed-content.php |
| wp-job-manager | ❌ 自定义 | seed-content.php |
| essential-real-estate | ❌ 自定义 | seed-content.php |
| asgaros-forum | ❌ 自定义 | seed-content.php |
| delicious-recipes | ❌ 自定义 | seed-content.php |
| foogallery | ❌ 自定义 | seed-content.php |
| portfolio-post-type | ❌ 自定义 | seed-content.php |
| seriously-simple-podcasting | ❌ 自定义 | seed-content.php |

### Plan C (8 内容插件)

| 插件 | 状态 | 方式 |
|------|------|------|
| sensei-lms | ✅ CLI | wp sensei-import 命令 |
| wp-easycart | ❌ 自定义 | seed-content.php |
| wpforo | ❌ 自定义 | seed-content.php |
| estatik | ❌ 自定义 | seed-content.php |
| wp-recipe-maker | ❌ 自定义 | seed-content.php |
| simple-job-board | ❌ 自定义 | seed-content.php |
| ultimate-faqs | ❌ 自定义 | seed-content.php |
| wp-customer-reviews | ❌ 自定义 | seed-content.php |

### Plan D (11 内容插件)

| 插件 | 状态 | 方式 |
|------|------|------|
| lifterlms | ✅ CLI | PHP 脚本 LLMS_Generator |
| learnpress | ✅ Playwright | 自动化浏览器导入 |
| storeengine | ❌ 自定义 | seed-content.php |
| hivepress | ❌ 自定义 | seed-content.php |
| geodirectory | ❌ 自定义 | seed-content.php |
| forumwp | ❌ 自定义 | seed-content.php |
| propertyhive | ❌ 自定义 | seed-content.php |
| events-manager | ❌ 自定义 | seed-content.php |
| wp-job-openings | ❌ 自定义 | seed-content.php |
| give | ❌ 自定义 | seed-content.php |
| podlove-podcasting-plugin-for-wordpress | ❌ 自定义 | seed-content.php |

---

## 统计汇总

| 分类 | 数量 | 百分比 |
|------|------|--------|
| ✅ CLI 自动导入 | 8 | 20% |
| ✅ Playwright 自动导入 | 1 | 2.5% |
| ⚠️ 使用已有数据 | 2 | 5% |
| ❌ 需自定义填充 | 29 | 72.5% |
| **总计** | 40 | 100% |

---

## 按计划导入（推荐方式）

使用统一导入脚本：

```bash
# 导入 Plan A 官方数据
bash /Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data/import-by-plan.sh A

# 导入 Plan A 官方数据 + 自定义填充
bash /Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data/import-by-plan.sh A --with-custom
```

---

## 手动导入命令

### Phase 1: CLI 自动导入

```bash
cd /usr/local/var/www
OFFICIAL_DATA=/Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data

# 所有计划通用 - WordPress Core
wp import $OFFICIAL_DATA/wordpress-core/themeunittestdata.wordpress.xml --authors=create

# Plan A
wp eval-file $OFFICIAL_DATA/scripts/wc-csv-import.php                    # WooCommerce
wp import $OFFICIAL_DATA/easy-digital-downloads/sample-products-import.xml --authors=create  # EDD
wp import $OFFICIAL_DATA/downloads/bbpress/bbpress-sample-data.xml --authors=create          # bbPress
wp bp component activate groups && wp bp member generate --count=20 && wp bp group generate --count=5  # BuddyPress

# Plan B
wp import $OFFICIAL_DATA/downloads/tutor-lms/tutor-sample-course.xml --authors=create  # Tutor LMS

# Plan C
cp $OFFICIAL_DATA/sensei-lms/courses.csv ./courses.csv
cp $OFFICIAL_DATA/sensei-lms/lessons.csv ./lessons.csv
wp sensei-import --user=admin --courses=courses.csv --lessons=lessons.csv && rm courses.csv lessons.csv  # Sensei LMS

# Plan D
wp eval-file $OFFICIAL_DATA/scripts/llms-json-import.php --user=admin    # LifterLMS
```

### Phase 1.5: Playwright 自动导入 (Plan D)

```bash
cd $OFFICIAL_DATA/scripts/playwright
npx playwright test import-learnpress.spec.js  # LearnPress
```

### Phase 2: 自定义填充

Academy LMS 和 MasterStudy LMS 无法使用官方数据，与其他插件一起自定义填充：

```bash
wp eval-file /Users/zhangxiao/wptsall-dev/dev-tools/seeding/seed-content.php [A|B|C|D]
```

---

## 数据文件路径

```
seeding/official-data/
├── wordpress-core/
│   └── themeunittestdata.wordpress.xml    ✅ CLI
├── woocommerce/
│   └── sample_products.csv                ✅ CLI (脚本)
├── easy-digital-downloads/
│   └── sample-products-import.xml         ✅ CLI
├── sensei-lms/
│   ├── courses.csv                        ✅ CLI (wp sensei-import)
│   └── lessons.csv                        ✅ CLI (wp sensei-import)
├── learnpress/
│   ├── sample-data.xml                    ⚠️ 后台（自定义格式）
│   └── dummy-data.xml                     ⚠️ 后台
├── lifterlms/
│   └── sample-course.json                 ✅ CLI (LLMS_Generator)
├── scripts/
│   ├── wc-csv-import.php                  ✅ WooCommerce 导入脚本
│   ├── sensei-csv-import.sh               ✅ Sensei 导入脚本
│   └── llms-json-import.php               ✅ LifterLMS 导入脚本
└── downloads/
    ├── bbpress/
    │   └── bbpress-sample-data.xml        ✅ CLI
    └── tutor-lms/
        └── tutor-sample-course.xml        ✅ CLI
```

---

## 测试结论

通过 WP-CLI 的 `--user=admin` 参数和插件内置的 CLI 命令，大部分官方数据可以自动导入：

1. **标准 WordPress XML**：使用 `wp import` 命令
2. **WooCommerce CSV**：使用 `WC_Product_CSV_Importer` PHP 类
3. **Sensei LMS CSV**：使用 `wp sensei-import` 内置命令
4. **LifterLMS JSON**：使用 `LLMS_Generator` PHP 类

仅 4 个插件需要登录后台操作（Academy、MasterStudy、BuddyPress、LearnPress），其他有官方数据的插件均可 CLI 自动导入。

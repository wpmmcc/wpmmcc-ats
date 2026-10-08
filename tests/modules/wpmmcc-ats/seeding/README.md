# 内容插件数据填充系统

**版本**: 2.5.0
**更新**: 2026-01-27
**状态**: Plan A ✅ | Plan B ✅ | Plan C ✅ | Plan D ✅
**审计报告**: [AUDIT-SUMMARY.md](./AUDIT-SUMMARY.md)

> **重要说明**：本工作流用于填充**内容插件**（WooCommerce、bbPress、LMS 等）的测试数据，**与 WPTSALL 翻译插件无关**。WPTSALL 作为公共插件保持启用即可，不参与数据填充流程。

## 当前状态总览

| 计划 | 名称 | 官方数据 | 自定义填充 | 工具插件 | 验证脚本 | 通过率 |
|------|------|----------|------------|----------|----------|--------|
| A | 基础商业套件 | 4 | 8 | 8 | 12 | 100% |
| B | 替代套件 | 1 | 9 | 6 | 11 | 100% |
| C | 传统小众套件 | 3 | 5 | 5 | 8 | 100% |
| D | 扩展套件 | 2 | 9 | 2 | 12 | 100% |
| **合计** | - | **10** | **31** | **21** | **43** | **100%** |

### 工具插件分布

| 计划 | 工具插件类型 |
|------|-------------|
| A | seo:1, custom_fields:1, forms:1, tables:1, booking:2, podcast:1, redirect:1 |
| B | seo:1, custom_fields:1, forms:1, membership:1, booking:2 |
| C | seo:1, custom_fields:1, membership:1, booking:1, podcast:1 |
| D | membership:1, booking:1 |

> 详细审计报告见 [AUDIT-SUMMARY.md](./AUDIT-SUMMARY.md)，包含 v4 扫描器对比结果。

---

## dev-tools 填充功能概览

`dev-tools/` 目录下有多种数据填充方案，各有不同用途：

```
dev-tools/
├── seeding/              # ★ 主方案：计划式数据填充 (Plan A/B/C/D)
│                         #    - 支持官方数据导入 + 自定义 JSON 填充
│                         #    - 三阶段工作流（Phase 1/2/3）
│                         #    - 工具插件关联（SEO、ACF、表单等）
│                         #    - 验证脚本自动检测
│
├── quick-demo/           # 方案 B：快速演示填充
│                         #    - 使用 FakerPress + BP Default Data
│                         #    - 1-2 天快速部署，覆盖率 70%
│                         #    - 适合快速搭建演示环境
│
├── complete-fields/      # 参考文档：字段定义
│                         #    - 33 个业务对象，564 个字段
│                         #    - 各插件完整字段结构
│                         #    - storage_type 分类标准
│
├── scanning/             # 工具：字段扫描器
│   └── v4-smart-scanner/ #    - v4 智能扫描器（推荐）
│       ├── core/         #    - 核心扫描引擎
│       ├── bin/          #    - 可执行脚本
│       ├── output/       #    - ★ 扫描结果输出 (JSON)
│       └── docs/         #    - 设计文档
│
└── lib/                  # 公共库：检测与对比
    ├── class-field-detector.php
    └── class-detection-comparator.php
```

### 方案对比

| 方案 | 目录 | 适用场景 | 时间 | 覆盖率 |
|------|------|---------|------|--------|
| **计划式填充** | `seeding/` | 完整测试、字段验证、多计划管理 | 2-4 小时/计划 | 95%+ |
| **快速演示** | `quick-demo/` | 快速搭建演示、初步测试 | 1-2 天 | 70% |
| **字段参考** | `complete-fields/` | 查询字段定义、编写 seed data | - | - |
| **字段扫描** | `scanning/` | 发现插件字段、生成配置 | 5-10 分钟 | - |

### 推荐使用顺序

```
1. 首次接触        → quick-demo/ (快速了解插件数据结构)
                         ↓
2. 深入开发        → scanning/ (扫描字段，了解结构)
                         ↓
3. 编写配置        → complete-fields/ (参考字段定义)
                         ↓
4. 正式填充        → seeding/ (使用计划式填充)
```

---

## seeding 目录结构

```
seeding/
├── README.md                   # ★ 本文件
├── seeding-config.json         # ★ 计划配置（定义 A/B/C/D 的插件列表）
├── run-plan.php                # ★ 一键运行入口
├── seed-content.php            # ★ 填充脚本 (分阶段执行)
│
├── plans/                      # ★ 按计划分离的工作目录
│   ├── A/
│   │   ├── utility/            #    工具插件配置（由 analyze 生成）
│   │   └── results/            #    执行结果
│   ├── B/
│   ├── C/
│   └── D/
│
├── seed-data/                  # ★ 自定义填充数据 (JSON)
│   ├── {plugin}.json           #    各内容插件的填充数据
│   ├── utility-examples/       #    工具插件配置示例
│   └── README.md               #    JSON 格式说明
│
├── official-data/              # ★ 官方数据资源
│   ├── woocommerce/            #    WooCommerce 官方 CSV
│   ├── wordpress-core/         #    Theme Unit Test XML
│   ├── scripts/                #    导入/激活/验证脚本
│   │   ├── pre-setup/          #    前置配置脚本
│   │   ├── post-setup/         #    后置配置脚本
│   │   ├── verify/             #    验证脚本
│   │   ├── playwright/         #    UI 自动化脚本
│   │   └── activate-plan.sh    #    计划插件激活
│   └── archive/                #    归档资源
│
├── lib/                        # ★ 模块化填充代码
│   ├── seed-helpers.php        #    公共函数
│   ├── phase1/                 #    Phase 1: 官方数据导入
│   │   └── dispatcher.php
│   ├── phase2/                 #    Phase 2: JSON 填充
│   │   └── json-seeder.php
│   └── phase3/                 #    Phase 3: 工具关联
│       ├── analyze.php         #    分析 + 生成配置
│       ├── dispatcher.php
│       ├── fill-seo.php
│       ├── fill-acf.php
│       ├── fill-forms.php
│       ├── fill-tables.php
│       ├── fill-booking.php
│       ├── fill-membership.php
│       └── fill-podcast.php
│
├── data-templates/             # 数据模板（兼容旧版）
│   ├── lms.json
│   ├── woocommerce.json
│   └── ...
│
├── demo-data/                  # 演示数据
│   ├── sample_products.csv     #    WooCommerce 产品 CSV
│   ├── sample_products.xml     #    WooCommerce 产品 XML
│   └── themeunittestdata.xml   #    WordPress Theme Unit Test
│
├── media/                      # 测试媒体资源
│   └── images/                 #    测试图片
│
└── auto-seeding/               # (规划中) 自动化填充
    ├── generators/
    ├── interactions/
    └── seeders/
```

---

## 快速开始

### 一键运行 Plan A 完整测试

```bash
cd /usr/local/var/www
wp eval-file /Users/zhangxiao/wptsall-dev/dev-tools/seeding/run-plan.php A full
```

该命令依次执行：Pre-Setup → Seed → Post-Setup → Verify

### 分阶段运行

```bash
wp eval-file .../run-plan.php A pre      # 前置配置
wp eval-file .../run-plan.php A seed     # 数据填充
wp eval-file .../run-plan.php A post     # 后置配置
wp eval-file .../run-plan.php A verify   # 验证测试
```

---

## 最终目标

填充真实可用的测试数据，使插件**像真实用户使用一样**：

- 前台页面正常显示、可交互
- 后台管理界面可操作
- 业务流程可执行（购买、报名、预约等）

---

## 开发流程（按计划执行）

每个计划（A/B/C/D）按以下流程处理，完成一个再进行下一个：

```
┌─────────────────────────────────────────────────────────────┐
│                    Plan X 开发流程                           │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  Step 1: 环境准备                                           │
│  ├── 公共插件网络激活                                        │
│  ├── 停用其他计划插件                                        │
│  ├── 激活当前计划插件                                        │
│  ├── 确认插件正常运行                                        │
│  └── 手动创建 1-2 条测试数据                                 │
│           ↓                                                 │
│  Step 2: 字段扫描                                           │
│  ├── 运行 v4 扫描器                                         │
│  ├── 或查询数据库获取 meta_key                               │
│  └── 分析字段用途和数据类型                                   │
│           ↓                                                 │
│  Step 3: JSON 编写                                          │
│  ├── 创建/更新 seed-data/{plugin}.json                      │
│  ├── 填写符合字段意图的内容                                   │
│  └── 内容长度/格式与真实使用接近                              │
│           ↓                                                 │
│  Step 4: 填充执行                                           │
│  ├── Phase 1: 官方数据导入                                   │
│  ├── Phase 2: 自定义数据填充                                 │
│  └── Phase 3: 工具插件关联                                   │
│           ↓                                                 │
│  Step 5: 验证测试                                           │
│  ├── 前台访问验证                                           │
│  ├── 后台管理验证                                           │
│  └── 业务流程验证                                           │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

---

## Step 1: 环境准备

### 1.1 公共插件网络激活

```bash
cd /usr/local/var/www

# 公共插件（所有计划都需要）
wp plugin activate wpmmcc-ats classic-editor loco-translate --network
```

### 1.2 激活计划插件

```bash
# 查看计划插件列表
cat /Users/zhangxiao/wptsall-dev/dev-tools/seeding/seeding-config.json | jq '.plans.A.content_plugins'

# 激活插件
wp plugin activate {plugin-slug}
```

### 1.3 手动创建测试数据

**为什么**：扫描器需要数据库中有数据才能发现字段

**操作**：
1. 进入插件后台管理页面
2. 手动创建 1-2 条完整数据
3. 填写所有可见字段

---

## Step 2: 字段扫描

### 2.1 使用 v4 智能扫描器

v4 扫描器是推荐的字段发现工具，支持批量扫描和 URL 验证。

**完整文档**: `/Users/zhangxiao/wptsall-dev/dev-tools/scanning/v4-smart-scanner/README.md`

#### 批量扫描所有激活插件

```bash
cd /usr/local/var/www
wp eval-file /Users/zhangxiao/wptsall-dev/dev-tools/scanning/v4-smart-scanner/bin/run-smart-scan-all.php
```

#### 扫描单个 post_type

```bash
wp eval-file /Users/zhangxiao/wptsall-dev/dev-tools/scanning/v4-smart-scanner/bin/run-smart-scan.php {post_type}
```

#### 输出位置（★ 重要）

```
/Users/zhangxiao/wptsall-dev/dev-tools/scanning/v4-smart-scanner/output/
├── {plugin}-{post_type}.json      # 单个 post_type 扫描结果
├── unknown-{post_type}.json       # 来源未知的 post_type
└── wordpress-{post_type}.json     # WordPress 核心类型
```

**查看扫描结果**:
```bash
# 列出所有扫描结果
ls /Users/zhangxiao/wptsall-dev/dev-tools/scanning/v4-smart-scanner/output/

# 查看特定插件扫描结果
cat /Users/zhangxiao/wptsall-dev/dev-tools/scanning/v4-smart-scanner/output/academy-academy_courses.json | jq '.fields'
```

#### 扫描结果示例

```json
{
  "post_type": "academy_courses",
  "source_plugin": "academy",
  "fields": {
    "academy_course_duration_hours": {
      "count": 5,
      "sample_values": ["40", "35", "25"],
      "suggested_type": "number"
    },
    "academy_course_level": {
      "count": 5,
      "sample_values": ["beginner", "intermediate", "advanced"],
      "suggested_type": "string"
    }
  }
}
```

### 2.2 扫描结果与 Seed Data 对比

> **重要**：v4 扫描结果只需与 **custom_seeding** 插件的 seed data 对比，**official_data** 插件使用官方导入方式，不需要自定义 seed data。

#### 对比流程

```
v4 扫描结果                           Seed Data (custom_seeding 插件)
    │                                        │
    ▼                                        ▼
academy-academy_courses.json    ←→    seed-data/academy.json
classified-listing-rtcl_listing.json ←→ seed-data/classified-listing.json
tribe-tribe_events.json         ←→    seed-data/the-events-calendar.json
```

#### 对比检查项

| 检查项 | 说明 |
|--------|------|
| 字段名一致 | seed data 中的 meta 字段名与扫描结果一致 |
| 字段值合理 | 字段值类型与 suggested_type 匹配 |
| 必要字段覆盖 | 核心业务字段在 seed data 中有值 |

#### 不需要对比的插件

| 插件类型 | 原因 | 示例 |
|----------|------|------|
| official_data | 使用官方数据导入 | woocommerce, bbpress, buddypress |
| utility_plugins | 工具插件，配置由 analyze 生成 | wordpress-seo, acf |

### 2.3 直接查询数据库

当 v4 扫描器无法识别字段来源时，可直接查询数据库：

```bash
# 获取某 post_type 的所有 meta_key
wp db query "SELECT DISTINCT pm.meta_key
FROM wp_postmeta pm
JOIN wp_posts p ON pm.post_id = p.ID
WHERE p.post_type = '{post_type}'
AND pm.meta_key LIKE '\_%'
ORDER BY pm.meta_key"
```

### 2.4 字段分析

| 字段特征 | 数据类型 | 填充策略 |
|----------|----------|----------|
| `_price`, `_regular_price` | 数字 | 合理价格范围 |
| `_duration`, `_hours` | 数字 | 合理时长 |
| `_description`, `_excerpt` | 文本 | 2-3 句描述 |
| `_thumbnail_id` | ID 引用 | `{{attachment:0}}` |
| `_author_id`, `_user_id` | ID 引用 | `{{user:0}}` |
| `_gallery`, `_images` | 序列化 | 跳过或特殊处理 |

---

## Step 3: JSON 编写

### 3.1 文件位置

```
seed-data/{plugin-slug}.json
```

### 3.2 内容原则

| 原则 | 说明 | 示例 |
|------|------|------|
| **符合意图** | 字段内容匹配字段用途 | `_price: "299"` 而非 `"abc"` |
| **合理长度** | 标题 5-15 字，描述 2-5 句 | 接近真实用户输入 |
| **业务场景** | 模拟真实业务数据 | 课程、产品、房产信息 |
| **最小完整** | 必要字段都填，非必要跳过 | 不追求字段覆盖率 |

### 3.3 JSON 配置文件标准

#### `_meta` 字段规范（必需）

每个 seed data 文件必须包含 `_meta` 字段，记录配置来源和版本信息：

| 字段 | 必需 | 说明 | 示例 |
|------|------|------|------|
| `plugin_slug` | ✅ | 插件目录名 | `"academy"` |
| `plugin_name` | ✅ | 插件显示名 | `"Academy LMS"` |
| `plan` | ✅ | 所属计划 | `"A"`, `"B"`, `"C"`, `"D"` |
| `version` | ✅ | 配置版本 | `"1.0.0"` |
| `description` | ✅ | 配置说明 | `"Academy LMS seed data for courses"` |
| `fields_source` | ✅ | 字段来源 | 见下表 |
| `fields_verified` | ✅ | 验证日期 | `"2026-01-27"` |
| `note` | ❌ | 特殊说明 | `"Requires images uploaded separately"` |

#### `fields_source` 值格式

| 来源类型 | 格式 | 示例 |
|----------|------|------|
| v4 扫描器 | `v4-scanner ({json文件名})` | `"v4-scanner (academy-academy_courses.json)"` |
| 数据库查询 | `database query ({说明})` | `"database query (property meta_keys, property_* prefix)"` |
| 自定义表 | `custom tables ({表名列表})` | `"custom tables (asgaros_forum_forums, asgaros_forum_topics)"` |
| 混合来源 | 逗号分隔 | `"v4-scanner (xxx.json), database query"` |

#### 完整 `_meta` 示例

```json
{
  "_meta": {
    "plugin_slug": "academy",
    "plugin_name": "Academy LMS",
    "plan": "A",
    "version": "1.0.0",
    "description": "Academy LMS seed data for courses, lessons, and quizzes",
    "fields_source": "v4-scanner (academy-academy_courses.json, academy-academy_lessons.json)",
    "fields_verified": "2026-01-27"
  }
}
```

### 3.4 JSON 结构示例

```json
{
  "_meta": {
    "plugin_slug": "academy",
    "plugin_name": "Academy LMS",
    "plan": "A",
    "version": "1.0.0",
    "description": "Academy LMS seed data for courses",
    "fields_source": "v4-scanner (academy-academy_courses.json)",
    "fields_verified": "2026-01-27"
  },
  "taxonomies": {
    "academy_courses_category": [
      {"name": "Programming", "slug": "programming"}
    ]
  },
  "posts": {
    "academy_courses": [
      {
        "post_title": "Python Programming Basics",
        "post_content": "<p>Learn Python from scratch...</p>",
        "post_status": "publish",
        "meta": {
          "academy_course_duration_hours": "40",
          "academy_course_level": "beginner"
        },
        "taxonomy": {
          "academy_courses_category": ["programming"]
        }
      }
    ]
  }
}
```

---

## Step 4: 填充执行

### 工作流程

```
┌─────────────────────────────────────────────────────────────────┐
│                      内容填充 + 工具关联工作流                      │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  Step 4.1: 填充内容                                              │
│  ├── Phase 1: 官方数据导入 (WooCommerce, bbPress 等)            │
│  └── Phase 2: 自定义 JSON 填充 (Academy, Cooked 等)             │
│          │                                                      │
│          ▼ 输出 seeding-result.json                            │
│                                                                 │
│  Step 4.2: 分析 + 生成配置 (★ analyze 命令)                     │
│  ├── 读取 seeding-result.json                                  │
│  ├── 根据内容类型分析所需工具插件配置                             │
│  └── 生成 seed-data/utility/*.json                             │
│          │                                                      │
│          ▼ 人工检查生成的配置                                    │
│                                                                 │
│  Step 4.3: 执行工具关联                                          │
│  └── Phase 3: 读取配置，创建实体，关联内容                        │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### 命令说明

```bash
cd /usr/local/var/www

# === 内容填充 ===
# 执行所有阶段（Phase 1 + 2 + 3）
wp eval-file /Users/zhangxiao/wptsall-dev/dev-tools/seeding/seed-content.php A

# 只执行 Phase 1 (官方数据导入)
wp eval-file /Users/zhangxiao/wptsall-dev/dev-tools/seeding/seed-content.php A phase 1

# 只执行 Phase 2 (自定义 JSON 填充)
wp eval-file /Users/zhangxiao/wptsall-dev/dev-tools/seeding/seed-content.php A phase 2

# === 分析 + 生成配置 (★ 新增) ===
# 分析已填充内容，自动生成工具插件配置
wp eval-file /Users/zhangxiao/wptsall-dev/dev-tools/seeding/seed-content.php A analyze

# === 工具关联 ===
# 只执行 Phase 3 (工具插件关联)
wp eval-file /Users/zhangxiao/wptsall-dev/dev-tools/seeding/seed-content.php A phase 3

# === 辅助命令 ===
# 检查文件状态（不执行）
wp eval-file /Users/zhangxiao/wptsall-dev/dev-tools/seeding/seed-content.php A check
```

> **注意**：WP-CLI 会消耗 `--` 前缀的参数，因此使用不带前缀的格式（如 `check` 而非 `--check`）

### Phase 1: 官方数据导入

针对有官方数据的插件，根据 `seeding-config.json` 中的配置自动选择导入方式：

| 导入方式 | 说明 | 插件示例 |
|----------|------|----------|
| `wp_import` | WordPress Importer (XML) | bbPress, EDD, Tutor LMS |
| `php_script` | PHP 脚本执行 | WooCommerce, LifterLMS |
| `wp_cli` | WP-CLI 命令生成 | BuddyPress |
| `playwright` | 后台 UI 自动化 | LearnPress |

### Phase 2: 自定义数据填充

读取 `seed-data/*.json` 文件：

1. 创建分类法术语
2. 创建文章
3. 写入 meta 字段
4. 关联分类法

### Analyze: 分析 + 生成配置 (★ 关键步骤)

内容填充完成后，**必须先运行 analyze 命令**生成工具插件配置：

```bash
wp eval-file seed-content.php A analyze
```

**分析过程**：
1. 读取 `results/plan-a/seeding-result.json` 中的填充记录
2. 统计各 post_type 的内容数量和特征
3. 根据计划配置的 `utility_plugins` 列表
4. 为每个激活的工具插件生成合适的 JSON 配置

**生成的配置**：
- SEO 插件 → 根据 post_type 生成 SEO 标题/描述模板
- ACF → 根据 post_type 生成扩展字段组
- CF7 → 根据业务类型生成相关表单
- TablePress → 根据内容类型生成数据表格
- SSA → 根据业务类型生成预约类型

**配置文件位置**：`seed-data/utility/`

**人工检查**：生成后请检查配置是否合理，必要时手动调整

### Phase 3: 工具插件关联

读取 `seed-data/utility/*.json` 配置文件，为内容添加工具插件数据：

```
Phase 3: 工具插件关联
├── 读取 seed-data/utility/{plugin}.json 配置
├── 创建工具插件实体（表单、表格、字段组等）
└── 为内容添加关联数据（SEO meta、字段值、嵌入短代码）
```

| 工具类型 | 配置文件 | 操作 |
|----------|----------|------|
| SEO | `wordpress-seo.json` | 为 post_type 添加 SEO meta |
| ACF | `advanced-custom-fields.json` | 创建字段组，填充字段值 |
| 表单 | `contact-form-7.json` | 创建 CF7 表单，嵌入页面 |
| 表格 | `tablepress.json` | 创建 TablePress 表格，嵌入页面/内容 |
| 预约 | `simply-schedule-appointments.json` | 创建预约页面 |

**配置文件来源**：由 `analyze` 命令生成（非预置）

---

## Step 5: 验证测试

### 5.1 前台验证

```bash
# 检查归档页
curl -I http://localhost/courses/
curl -I http://localhost/listings/

# 检查单页
curl -I http://localhost/courses/python-basics/
```

### 5.2 后台验证

- 后台列表页显示正常
- 编辑页可打开
- 可创建新内容

### 5.3 业务流程验证

| 插件类型 | 验证操作 |
|----------|----------|
| 电商 | 加入购物车、结账页面 |
| LMS | 课程报名、课程访问 |
| 论坛 | 发帖、回复 |
| 预约 | 查看日历、提交预约 |

---

## 当前进度

### Plan A（已完成 ✅）

**验证结果**: 60/60 测试通过 (100%)

| 插件 | 类型 | 填充 | 验证 | 测试数 |
|------|------|------|------|--------|
| WooCommerce | 官方 | ✅ | ✅ 100% | 12/12 |
| bbPress | 官方 | ✅ | ✅ 100% | 7/7 |
| Easy Digital Downloads | 官方 | ✅ | ✅ 100% | 5/5 |
| Academy LMS | 自定义 | ✅ | ✅ 100% | 5/5 |
| MasterStudy LMS | 自定义 | ✅ | ✅ 100% | 8/8 |
| Classified Listing | 自定义 | ✅ | ✅ 100% | 6/6 |
| Easy Property Listings | 自定义 | ✅ | ✅ 100% | 4/4 |
| Cooked | 自定义 | ✅ | ✅ 100% | 7/7 |
| Envira Gallery | 自定义 | ✅ | ✅ 100% | 3/3 |
| Site Reviews | 自定义 | ✅ | ✅ 100% | 3/3 |

**结果文件**:
- `plans/A/results/verify-result.json` - 验证详情
- `plans/A/results/workflow-result.json` - 工作流状态

### Plan B（已完成 ✅）

**验证结果**: 60/60 测试通过 (100%)

| 插件 | 类型 | 填充 | 验证 | 测试数 |
|------|------|------|------|--------|
| Tutor LMS | 官方 | ✅ | ✅ 100% | 10/10 |
| Directorist | 自定义 | ✅ | ✅ 100% | 5/5 |
| The Events Calendar | 自定义 | ✅ | ✅ 100% | 7/7 |
| WP Job Manager | 自定义 | ✅ | ✅ 100% | 7/7 |
| Essential Real Estate | 自定义 | ✅ | ✅ 100% | 4/4 |
| Asgaros Forum | 自定义 | ✅ | ✅ 100% | 6/6 |
| Delicious Recipes | 自定义 | ✅ | ✅ 100% | 8/8 |
| FooGallery | 自定义 | ✅ | ✅ 100% | 3/3 |
| Portfolio Post Type | 自定义 | ✅ | ✅ 100% | 5/5 |
| Seriously Simple Podcasting | 自定义 | ✅ | ✅ 100% | 5/5 |

**结果文件**:
- `plans/B/results/verify-result.json` - 验证详情
- `plans/B/results/workflow-result.json` - 工作流状态

### Plan C（已完成 ✅）

**验证结果**: 36/36 测试通过 (100%)

| 插件 | 类型 | 填充 | 验证 | 测试数 |
|------|------|------|------|--------|
| Sensei LMS | 官方 | ✅ | ✅ 100% | 6/6 |
| WP EasyCart | 自定义 | ✅ | ✅ 100% | 2/2 |
| WPForo | 官方 | ✅ | ✅ 100% | 4/4 |
| Estatik | 自定义 | ✅ | ✅ 100% | 4/4 |
| WP Recipe Maker | 自定义 | ✅ | ✅ 100% | 5/5 |
| Simple Job Board | 自定义 | ✅ | ✅ 100% | 7/7 |
| Ultimate FAQs | 自定义 | ✅ | ✅ 100% | 5/5 |
| WP Customer Reviews | 自定义 | ✅ | ✅ 100% | 3/3 |

**结果文件**:
- `plans/C/results/verify-result.json` - 验证详情
- `plans/C/results/workflow-result.json` - 工作流状态

**工具插件支持**:
- SEO: seo-by-rank-math ✅
- Custom Fields: pods ✅
- Booking: bookly ✅
- Membership: restrict-content ✅ (配置生成已实现)
- Podcast: podcast-player ✅ (配置生成已实现)

---

### Plan D

**已完成 ✅** - 验证结果：65/65 (100%)

**执行**: 2026-01-27

**包含插件**:
- 官方数据 (2): LearnPress, LifterLMS
- 自定义填充 (9): StoreEngine, HivePress, GeoDirectory, ForumWP, PropertyHive, Events Manager, WP Job Openings, GiveWP, Podlove
- 工具插件 (2): Simple Membership (会员), Amelia (预约)

**各插件验证结果**:
- ✅ learnpress: 8/8 (100%)
- ✅ lifterlms: 8/8 (100%)
- ✅ storeengine: 4/4 (100%)
- ✅ hivepress: 6/6 (100%)
- ✅ geodirectory: 5/5 (100%)
- ✅ forumwp: 7/7 (100%)
- ✅ propertyhive: 5/5 (100%)
- ✅ events-manager: 6/6 (100%)
- ✅ wp-job-openings: 6/6 (100%)
- ✅ give: 6/6 (100%)
- ✅ podlove: 4/4 (100%)

**注意事项**:
- LearnPress 课时通过课程页面访问
- ForumWP/Events Manager 需要配置主页面才能前台访问
- StoreEngine post type 为 `storeengine_product`（非 `se_product`）

---

## 目录结构

```
seeding/
├── README.md                   # ★ 本文件
├── seeding-config.json         # ★ 计划配置（定义 A/B/C/D）
├── seed-content.php            # ★ 主入口脚本 (v2.1.0)
│
├── lib/                        # ★ 模块化代码
│   ├── seed-helpers.php        # 公共函数
│   ├── phase1/                 # Phase 1: 官方数据导入
│   │   ├── dispatcher.php      # 调度器
│   │   ├── method-wp-import.php
│   │   ├── method-php-script.php
│   │   ├── method-wp-cli.php
│   │   └── method-playwright.php
│   ├── phase2/                 # Phase 2: 自定义填充
│   │   └── json-seeder.php
│   └── phase3/                 # Phase 3: 工具关联
│       ├── analyze.php         # ★ 分析内容 + 生成配置
│       ├── dispatcher.php      # 调度器
│       ├── fill-seo.php        # SEO 数据填充
│       ├── fill-acf.php        # ACF 字段填充
│       ├── fill-forms.php      # CF7 表单填充
│       ├── fill-tables.php     # TablePress 表格填充
│       └── fill-booking.php    # 预约系统填充
│
├── seed-data/                  # 内容插件 JSON 数据
│   ├── README.md               # JSON 格式说明
│   ├── {plugin}.json           # 内容插件数据（共用）
│   └── utility-examples/       # 工具插件配置示例（参考用）
│
├── plans/                      # ★ 按计划分离的工作目录
│   ├── A/
│   │   ├── utility/            # A 计划的工具插件配置（由 analyze 生成）
│   │   │   ├── tablepress.json
│   │   │   └── simply-schedule-appointments.json
│   │   └── results/            # A 计划的执行结果
│   │       ├── seeding-result.json
│   │       └── seed.log
│   ├── B/
│   │   ├── utility/
│   │   └── results/
│   ├── C/
│   │   ├── utility/
│   │   └── results/
│   └── D/
│       ├── utility/
│       └── results/
│
├── official-data/              # 官方数据资源
│   ├── wordpress-core/
│   ├── woocommerce/
│   ├── scripts/
│   └── archive/
│
└── data-templates/             # 旧模板（兼容）
```

---

## 插件分类

### 三层结构

```
Layer 1: Common（公共插件）
    └── 网络激活，无需填充（WPTSALL, Classic Editor）

Layer 2: Content（内容插件）
    ├── official_data: 有官方数据（WooCommerce, bbPress）
    └── custom_seeding: 无官方数据（Academy, Cooked）

Layer 3: Utility（工具插件）
    └── 为 Content 添加附加数据（Yoast, ACF, CF7）
```

### Content 插件数据来源详解

| 类型 | 说明 | 数据来源 | 是否需要 seed data |
|------|------|----------|-------------------|
| **official_data** | 有官方示例数据 | 导入 XML/CSV/API | ❌ 不需要 |
| **custom_seeding** | 无官方数据 | seed-data/*.json | ✅ 需要编写 |

#### official_data 插件

这些插件有官方提供的示例数据，使用标准导入方式：

| 插件 | 导入方式 | 数据来源 |
|------|----------|----------|
| woocommerce | php_script | WC CSV Import |
| bbpress | wp_import | bbPress Sample Data XML |
| easy-digital-downloads | wp_import | EDD Sample Products XML |
| buddypress | wp_cli | BP Generate Commands |
| tutor | wp_import | Tutor Sample Course XML |
| sensei-lms | wp_cli | Sensei Import CSV |
| learnpress | playwright | UI Automation |
| lifterlms | php_script | LifterLMS JSON Import |

#### custom_seeding 插件

这些插件需要我们编写 seed data JSON 文件：

| 计划 | 插件列表 |
|------|----------|
| A | academy, masterstudy-lms, classified-listing, easy-property-listings, cooked, envira-gallery-lite, site-reviews, testimonial-free |
| B | directorist, the-events-calendar, wp-job-manager, essential-real-estate, asgaros-forum, delicious-recipes, foogallery, portfolio-post-type, seriously-simple-podcasting |
| C | wp-easycart, wpforo, estatik, wp-recipe-maker, simple-job-board, ultimate-faqs, wp-customer-reviews |
| D | storeengine, hivepress, geodirectory, forumwp, propertyhive, events-manager, wp-job-openings, give, podlove |

> **v4 扫描器对比**：只对比 custom_seeding 插件的 seed data 与扫描结果

### Content + Utility 组合示例

```
Academy LMS (Content)
    ├── Yoast SEO → 课程 SEO 标题/描述
    ├── ACF → 课程扩展字段
    └── SSA → 课程咨询预约

WooCommerce (Content)
    ├── Yoast SEO → 产品 SEO
    ├── ACF → 产品规格字段
    └── TablePress → 规格表
```

---

## 注意事项

1. **一次一个计划** - 完成 Plan A 再做 B/C/D
2. **字段必须验证** - 基于 v4 扫描器/数据库查询，禁止猜测
3. **内容要真实** - 模拟真实业务场景
4. **与 WPTSALL 无关** - 本工作流只填充内容插件数据，不处理 WPTSALL 翻译插件
5. **v4 对比范围** - 只对比 custom_seeding 插件，official_data 插件使用官方导入
6. **fields_source 必需** - 所有 seed data JSON 必须包含 `_meta.fields_source` 记录字段来源

---

## 已知问题与解决方案

### 1. Academy LMS 的 `update_option` 问题

**问题**: 调用 `update_option('academy_settings', ...)` 会触发 Academy LMS 的钩子导致脚本挂起

**解决方案**: `lms-url-conflict-resolver.php` 已修改为使用直接 SQL 更新，绕过钩子：

```php
// 直接 SQL 更新，绕过 update_option 的钩子
$wpdb->query(
    $wpdb->prepare(
        "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s",
        $new_value,
        'academy_settings'
    )
);
```

### 2. 其他 LMS 插件可能有类似问题

**建议**: 如果新插件的 `update_option` 导致脚本挂起，参考 Academy LMS 的处理方式，使用直接 SQL 更新。

### 3. BuddyPress 数据导入

**问题**: BuddyPress 需要 BP Default Data 插件才能导入测试数据

**解决方案**: 暂时跳过，标记为"插件未激活"

---

## 开发后续计划的规范

### 新增插件的开发流程

1. **添加到计划配置** - 更新 `seeding-config.json`
2. **创建 JSON 数据** - `seed-data/{plugin}.json`
3. **Pre-Setup 脚本** - 如有 URL 冲突或配置需求
4. **Post-Setup 脚本** - 如需页面配置
5. **Verify 脚本** - 验证脚本
6. **更新 dispatcher** - 将脚本添加到 dispatcher

### 脚本编写规范

| 规范 | 说明 |
|------|------|
| 幂等性 | 脚本可重复执行，不会重复创建 |
| 错误处理 | 检查插件是否激活再执行 |
| 输出格式 | 使用统一的框线格式 |
| 返回值 | 返回 `array('success' => bool, ...)` |

### 测试验证规范

| 规范 | 说明 |
|------|------|
| 通过标准 | 总体通过率 >= 95% |
| 测试覆盖 | 页面配置 + 内容数量 + 前台访问 + 后台访问 |
| 结果保存 | JSON 结果保存到 `plans/{plan}/results/` |

---

---

## 附录：常用检查脚本

### v4 扫描器相关

**v4 扫描器位置**: `/Users/zhangxiao/wptsall-dev/dev-tools/scanning/v4-smart-scanner/`

**扫描结果目录**: `/Users/zhangxiao/wptsall-dev/dev-tools/scanning/v4-smart-scanner/output/`

```bash
# 运行完整扫描
cd /usr/local/var/www
wp eval-file /Users/zhangxiao/wptsall-dev/dev-tools/scanning/v4-smart-scanner/bin/run-smart-scan-all.php

# 测试插件发现（快速，<1秒）
wp eval-file /Users/zhangxiao/wptsall-dev/dev-tools/scanning/v4-smart-scanner/tests/test-discovery.php

# 列出所有扫描结果
ls -la /Users/zhangxiao/wptsall-dev/dev-tools/scanning/v4-smart-scanner/output/*.json | wc -l
```

### 对比 Seed Data 与 v4 扫描结果

手动对比流程：

1. 查看 v4 扫描结果：
   ```bash
   cat /Users/zhangxiao/wptsall-dev/dev-tools/scanning/v4-smart-scanner/output/{plugin}-{post_type}.json | jq '.fields'
   ```
2. 查看 seed data：
   ```bash
   cat /Users/zhangxiao/wptsall-dev/dev-tools/seeding/seed-data/{plugin}.json | jq '.posts'
   ```
3. 对比字段列表是否一致

### 验证 fields_source 配置

```bash
# 检查所有 seed data 的 _meta.fields_source
for f in seed-data/*.json; do
  echo "=== $f ==="
  cat "$f" | jq '._meta.fields_source // "MISSING!"'
done
```

---

---

## 相关工具与文档

### dev-tools 目录结构

```
/Users/zhangxiao/wptsall-dev/dev-tools/
├── seeding/                              # ★ 当前目录：数据填充系统
│   ├── seed-data/                        #    自定义填充数据 JSON
│   ├── official-data/                    #    官方数据 + 脚本
│   └── plans/                            #    计划工作目录 (A/B/C/D)
│
├── scanning/                             # 字段扫描工具
│   └── v4-smart-scanner/                 # ★ v4 智能扫描器
│       ├── README.md                     #    扫描器文档（必读）
│       ├── core/                         #    核心扫描引擎
│       ├── bin/                          #    可执行脚本
│       ├── output/                       #    ★ 扫描结果输出
│       ├── tests/                        #    测试脚本
│       ├── tools/                        #    分析工具
│       └── docs/                         #    设计文档
│
├── complete-fields/                      # 字段定义参考
│   └── definitions/                      #    33 个业务对象定义
│
├── quick-demo/                           # 快速演示填充
│   └── README.md                         #    快速开始指南
│
└── lib/                                  # 公共库
    ├── class-field-detector.php
    └── class-detection-comparator.php
```

### 关键路径速查

| 用途 | 路径 |
|------|------|
| **Seeding 配置** | `/Users/zhangxiao/wptsall-dev/dev-tools/seeding/seeding-config.json` |
| **Seed Data 目录** | `/Users/zhangxiao/wptsall-dev/dev-tools/seeding/seed-data/` |
| **v4 扫描器** | `/Users/zhangxiao/wptsall-dev/dev-tools/scanning/v4-smart-scanner/` |
| **v4 扫描结果** | `/Users/zhangxiao/wptsall-dev/dev-tools/scanning/v4-smart-scanner/output/` |
| **验证脚本** | `/Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data/scripts/verify/` |
| **计划结果** | `/Users/zhangxiao/wptsall-dev/dev-tools/seeding/plans/{A,B,C,D}/results/` |

### 相关文档

| 文档 | 路径 | 说明 |
|------|------|------|
| **v4 扫描器 README** | `scanning/v4-smart-scanner/README.md` | 扫描器使用指南 |
| **v4 设计文档** | `scanning/v4-smart-scanner/docs/V4-DESIGN-ANALYSIS.md` | 扫描器设计理念 |
| **v4 流程分析** | `scanning/v4-smart-scanner/docs/FLOW-ANALYSIS.md` | 完整执行流程 |
| **Seed Data 格式** | `seeding/seed-data/README.md` | JSON 格式说明 |

---

**维护**: WPTSALL 开发团队
**最后更新**: 2026-01-27

# WPTSALL 完整测试工作流

**版本**: 1.0.0
**更新时间**: 2026-01-26
**目的**: 为 WPTSALL 翻译插件提供标准化的测试流程

---

## 📋 目录

1. [测试架构](#测试架构)
2. [插件分类](#插件分类)
3. [测试阶段](#测试阶段)
4. [脚本使用](#脚本使用)
5. [完整示例](#完整示例)
6. [常见问题](#常见问题)

---

## 测试架构

### 核心原则

```
WordPress Core (基础层)
    ├── 一次性填充
    ├── 所有计划共享
    └── 永不清理
         │
         ▼
Plan A/B/C/D (测试层)
    ├── 按计划卸载/重装
    ├── 填充官方数据 + 自定义数据
    └── 验证翻译准确性
         │
         ▼
WPTSALL 翻译数据 (中间层)
    ├── 每次切换计划前清理
    └── 重新生成模型和任务
         │
         ▼
Tool Plugins (工具层)
    ├── 始终保持激活
    └── 不参与测试流程
```

### 数据分层

| 层级 | 内容 | 填充策略 | 清理策略 |
|------|------|----------|----------|
| **Layer 1: WordPress Core** | 文章、页面、用户 | ✅ 一次性填充 | ❌ 不清理 |
| **Layer 2: Content Plugins** | 插件业务数据 | ✅ 每个计划重新填充 | ✅ 卸载时自动清理 |
| **Layer 3: WPTSALL Data** | 模型、任务、翻译 | 🔄 自动生成 | ✅ 切换计划前清理 |
| **Layer 4: Tool Plugins** | 工具插件 | ⏭️ 不需要填充 | ❌ 不清理 |

---

## 插件分类

### 总插件数统计

```
总计：87 个插件
├── 64 个计划内插件 (分布在 A/B/C/D 四个计划)
├── 5 个 Always Active 插件 (始终激活)
├── 9 个 Ignored 插件 (不参与测试)
├── 1 个 Core 插件 (wptsall)
├── 3 个数据生成工具 (bp-default-data, fakerpress, wordpress-importer)
└── ~5 个其他插件
```

### Plan A (20个插件)

**Content 插件 (12个)**:
- woocommerce ✅
- bbpress ✅
- academy
- masterstudy-lms-learning-management-system
- easy-digital-downloads ✅
- classified-listing
- easy-property-listings
- cooked
- envira-gallery-lite
- site-reviews
- tablepress
- testimonial-free

**Utility 插件 (8个)**:
- wordpress-seo
- advanced-custom-fields
- contact-form-7
- buddypress ✅
- easy-appointments
- simply-schedule-appointments
- powerpress
- redirection

**官方数据**: 6个 (WooCommerce, bbPress, BuddyPress, EDD, Academy, MasterStudy)
**数据覆盖率**: 30%

### Plan B (18个插件)

**Content 插件 (11个)**:
- ecwid-shopping-cart
- directorist
- the-events-calendar
- wp-job-manager
- tutor ✅
- essential-real-estate
- asgaros-forum
- delicious-recipes
- foogallery
- reviews-feed
- portfolio-post-type

**Utility 插件 (7个)**:
- all-in-one-seo-pack
- meta-box
- ninja-forms
- paid-member-subscriptions
- booking
- fluent-booking
- seriously-simple-podcasting

**官方数据**: 1个 (Tutor LMS)
**数据覆盖率**: 6%

### Plan C (13个插件)

**Content 插件 (8个)**:
- wp-easycart
- wpforo
- sensei-lms ✅
- estatik
- wp-recipe-maker
- simple-job-board
- ultimate-faqs
- wp-customer-reviews

**Utility 插件 (5个)**:
- seo-by-rank-math
- pods
- restrict-content
- bookly-responsive-appointment-booking-tool
- podcast-player

**官方数据**: 1个 (Sensei LMS)
**数据覆盖率**: 8%

### Plan D (13个插件)

**Content 插件 (10个)**:
- storeengine
- learnpress ✅
- lifterlms ✅
- hivepress
- geodirectory
- forumwp
- propertyhive
- events-manager
- wp-job-openings
- give

**Utility 插件 (3个)**:
- simple-membership
- ameliabooking
- podlove-podcasting-plugin-for-wordpress

**官方数据**: 2个 (LearnPress, LifterLMS)
**数据覆盖率**: 15%

### Tool Plugins（不参与测试）

**Always Active (5个)** - 网络激活，始终可用:
- classic-editor
- loco-translate
- duplicate-post
- custom-post-type-ui
- akismet

**Ignored (9个)** - 网络激活，不参与测试:
- action-scheduler
- hello
- plugin-check
- simple-custom-css
- updraftplus
- wp-super-cache
- wordfence
- insert-headers-and-footers
- easy-accordion-free

**数据生成工具 (3个)** - 用于数据填充:
- bp-default-data
- fakerpress
- wordpress-importer

---

## 测试阶段

### 阶段 0: 环境准备（仅执行一次）

**目的**: 建立基础测试环境

```bash
#!/bin/bash
DEV=/Users/zhangxiao/wptsall-dev
WP=/usr/local/var/www
cd $WP

# 1. 确保 WordPress Core 数据已填充
echo "检查 WordPress Core 数据..."
POST_COUNT=$(wp post list --post_type=post,page --format=count 2>/dev/null)

if [ "$POST_COUNT" -lt 20 ]; then
    echo "填充 WordPress Core 测试数据..."
    wp import $DEV/dev-tools/seeding/official-data/wordpress-core/themeunittestdata.wordpress.xml \
        --authors=create
    echo "✅ WordPress Core 数据填充完成"
else
    echo "✅ WordPress Core 数据已存在 ($POST_COUNT posts)"
fi

# 2. 确保数据生成工具已激活
echo "激活数据生成工具..."
wp plugin activate bp-default-data fakerpress wordpress-importer --quiet 2>/dev/null || true

# 3. 确保 Always Active 插件已网络激活
echo "网络激活工具插件..."
for plugin in classic-editor loco-translate duplicate-post custom-post-type-ui akismet; do
    wp plugin activate $plugin --network --quiet 2>/dev/null || true
done

# 4. 确保 Ignored 插件已网络激活
echo "网络激活 Ignored 插件..."
for plugin in action-scheduler hello plugin-check simple-custom-css \
              updraftplus wp-super-cache wordfence insert-headers-and-footers \
              easy-accordion-free; do
    wp plugin activate $plugin --network --quiet 2>/dev/null || true
done

echo "✅ 环境准备完成"
```

### 阶段 1: 测试单个计划（首次测试）

**目的**: 验证数据填充和翻译的准确性

```bash
#!/bin/bash
# 测试 Plan A 示例
DEV=/Users/zhangxiao/wptsall-dev
WP=/usr/local/var/www
PLAN=A

cd $WP

echo "========================================
测试 Plan $PLAN - 首次测试
========================================

# Step 1: 清理 WPTSALL 数据
echo "Step 1: 清理 WPTSALL 翻译数据..."
bash $DEV/dev-tools/seeding/official-data/scripts/cleanup-wptsall.sh

# Step 2: 卸载 Plan 插件（如果之前安装过）
echo "Step 2: 卸载 Plan $PLAN 插件..."
bash $DEV/dev-tools/seeding/official-data/scripts/uninstall-plan.sh $PLAN

# Step 3: 安装并激活 Plan 插件
echo "Step 3: 安装 Plan $PLAN 插件..."
bash $DEV/dev-tools/seeding/official-data/scripts/install-plan.sh $PLAN

# Step 4: 填充官方数据
echo "Step 4: 填充 Plan $PLAN 官方数据..."
bash $DEV/dev-tools/seeding/official-data/scripts/import-by-plan.sh $PLAN

# Step 5: 填充自定义数据
echo "Step 5: 填充 Plan $PLAN 自定义数据..."
wp eval-file $DEV/tests/workflow/seed-content.php $PLAN

# Step 6: 运行 WPTSALL 扫描和测试
echo "Step 6: 运行 WPTSALL 测试..."
wp eval-file $DEV/tests/workflow/run-scan-with-autofix.php $PLAN
wp eval-file $DEV/tests/workflow/phase4-execute-tasks.php $PLAN
wp eval-file $DEV/tests/workflow/phase5-verify.php $PLAN

echo "✅ Plan $PLAN 测试完成"
```

### 阶段 2: 切换到下一个计划

**目的**: 清理上一计划，切换到新计划

```bash
#!/bin/bash
# 从 Plan A 切换到 Plan B 示例
DEV=/Users/zhangxiao/wptsall-dev
WP=/usr/local/var/www
OLD_PLAN=A
NEW_PLAN=B

cd $WP

echo "========================================
从 Plan $OLD_PLAN 切换到 Plan $NEW_PLAN
========================================

# Step 1: 停用旧计划插件（不卸载，节省时间）
echo "Step 1: 停用 Plan $OLD_PLAN 插件..."
bash $DEV/dev-tools/seeding/official-data/scripts/deactivate-plan.sh $OLD_PLAN

# Step 2: 清理 WPTSALL 翻译数据
echo "Step 2: 清理 WPTSALL 数据..."
bash $DEV/dev-tools/seeding/official-data/scripts/cleanup-wptsall.sh

# Step 3: 卸载新计划插件（确保干净安装）
echo "Step 3: 卸载 Plan $NEW_PLAN 插件..."
bash $DEV/dev-tools/seeding/official-data/scripts/uninstall-plan.sh $NEW_PLAN

# Step 4: 安装新计划插件
echo "Step 4: 安装 Plan $NEW_PLAN 插件..."
bash $DEV/dev-tools/seeding/official-data/scripts/install-plan.sh $NEW_PLAN

# Step 5: 填充新计划数据
echo "Step 5: 填充 Plan $NEW_PLAN 数据..."
bash $DEV/dev-tools/seeding/official-data/scripts/import-by-plan.sh $NEW_PLAN
wp eval-file $DEV/tests/workflow/seed-content.php $NEW_PLAN

# Step 6: 运行测试
echo "Step 6: 运行 Plan $NEW_PLAN 测试..."
wp eval-file $DEV/tests/workflow/run-scan-with-autofix.php $NEW_PLAN
wp eval-file $DEV/tests/workflow/phase4-execute-tasks.php $NEW_PLAN
wp eval-file $DEV/tests/workflow/phase5-verify.php $NEW_PLAN

echo "✅ 已切换到 Plan $NEW_PLAN"
```

### 阶段 3: 验证后的快速切换（不重装）

**目的**: 所有计划验证正确后，后续只需激活不同计划

```bash
#!/bin/bash
# 快速切换示例（不卸载重装）
DEV=/Users/zhangxiao/wptsall-dev
WP=/usr/local/var/www
OLD_PLAN=A
NEW_PLAN=B

cd $WP

echo "========================================
快速切换: Plan $OLD_PLAN → Plan $NEW_PLAN
========================================

# Step 1: 停用旧计划
echo "Step 1: 停用 Plan $OLD_PLAN..."
bash $DEV/dev-tools/seeding/official-data/scripts/deactivate-plan.sh $OLD_PLAN

# Step 2: 清理 WPTSALL 数据
echo "Step 2: 清理 WPTSALL 数据..."
bash $DEV/dev-tools/seeding/official-data/scripts/cleanup-wptsall.sh

# Step 3: 激活新计划（插件已安装，直接激活）
echo "Step 3: 激活 Plan $NEW_PLAN..."
bash $DEV/dev-tools/seeding/official-data/scripts/activate-plan.sh $NEW_PLAN

# Step 4: 运行测试
echo "Step 4: 运行 Plan $NEW_PLAN 测试..."
wp eval-file $DEV/tests/workflow/run-scan-with-autofix.php $NEW_PLAN
wp eval-file $DEV/tests/workflow/phase4-execute-tasks.php $NEW_PLAN

echo "✅ 已快速切换到 Plan $NEW_PLAN"
```

---

## 脚本使用

### 可用脚本

| 脚本 | 用途 | 示例 |
|------|------|------|
| `install-plan.sh` | 安装并激活指定计划的所有插件 | `bash install-plan.sh A` |
| `uninstall-plan.sh` | 卸载指定计划的所有插件（触发 uninstall.php） | `bash uninstall-plan.sh A` |
| `activate-plan.sh` | 激活指定计划的插件（插件已安装） | `bash activate-plan.sh A` |
| `deactivate-plan.sh` | 停用指定计划的所有插件 | `bash deactivate-plan.sh A` |
| `import-by-plan.sh` | 导入指定计划的官方数据 | `bash import-by-plan.sh A` |
| `cleanup-wptsall.sh` | 清理 WPTSALL 翻译数据 | `bash cleanup-wptsall.sh` |
| `full-test-cycle.sh` | 完整测试周期（A → B → C → D） | `bash full-test-cycle.sh` |

### 脚本位置

```
/Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data/scripts/
├── install-plan.sh
├── uninstall-plan.sh
├── activate-plan.sh
├── deactivate-plan.sh
├── import-by-plan.sh
├── cleanup-wptsall.sh
└── full-test-cycle.sh
```

---

## 完整示例

### 场景 1: 从头开始测试所有计划

```bash
#!/bin/bash
DEV=/Users/zhangxiao/wptsall-dev
WP=/usr/local/var/www
cd $WP

# 0. 环境准备（仅第一次）
echo "===== 环境准备 ====="
wp import $DEV/dev-tools/seeding/official-data/wordpress-core/themeunittestdata.wordpress.xml \
    --authors=create

# 1. 测试 Plan A
echo "===== 测试 Plan A ====="
bash $DEV/dev-tools/seeding/official-data/scripts/cleanup-wptsall.sh
bash $DEV/dev-tools/seeding/official-data/scripts/uninstall-plan.sh A
bash $DEV/dev-tools/seeding/official-data/scripts/install-plan.sh A
bash $DEV/dev-tools/seeding/official-data/scripts/import-by-plan.sh A
wp eval-file $DEV/tests/workflow/seed-content.php A
wp eval-file $DEV/tests/workflow/run-plan.php A

# 2. 切换到 Plan B
echo "===== 切换到 Plan B ====="
bash $DEV/dev-tools/seeding/official-data/scripts/deactivate-plan.sh A
bash $DEV/dev-tools/seeding/official-data/scripts/cleanup-wptsall.sh
bash $DEV/dev-tools/seeding/official-data/scripts/uninstall-plan.sh B
bash $DEV/dev-tools/seeding/official-data/scripts/install-plan.sh B
bash $DEV/dev-tools/seeding/official-data/scripts/import-by-plan.sh B
wp eval-file $DEV/tests/workflow/seed-content.php B
wp eval-file $DEV/tests/workflow/run-plan.php B

# 3. 切换到 Plan C
echo "===== 切换到 Plan C ====="
bash $DEV/dev-tools/seeding/official-data/scripts/deactivate-plan.sh B
bash $DEV/dev-tools/seeding/official-data/scripts/cleanup-wptsall.sh
bash $DEV/dev-tools/seeding/official-data/scripts/uninstall-plan.sh C
bash $DEV/dev-tools/seeding/official-data/scripts/install-plan.sh C
bash $DEV/dev-tools/seeding/official-data/scripts/import-by-plan.sh C
wp eval-file $DEV/tests/workflow/seed-content.php C
wp eval-file $DEV/tests/workflow/run-plan.php C

# 4. 切换到 Plan D
echo "===== 切换到 Plan D ====="
bash $DEV/dev-tools/seeding/official-data/scripts/deactivate-plan.sh C
bash $DEV/dev-tools/seeding/official-data/scripts/cleanup-wptsall.sh
bash $DEV/dev-tools/seeding/official-data/scripts/uninstall-plan.sh D
bash $DEV/dev-tools/seeding/official-data/scripts/install-plan.sh D
bash $DEV/dev-tools/seeding/official-data/scripts/import-by-plan.sh D
wp eval-file $DEV/tests/workflow/seed-content.php D
wp eval-file $DEV/tests/workflow/run-plan.php D

echo "✅ 所有计划测试完成"
```

### 场景 2: 验证后的快速重测

```bash
#!/bin/bash
# 假设所有计划的插件都已安装并验证过，只需要快速切换
DEV=/Users/zhangxiao/wptsall-dev
WP=/usr/local/var/www
cd $WP

# 快速测试 Plan A
bash $DEV/dev-tools/seeding/official-data/scripts/cleanup-wptsall.sh
bash $DEV/dev-tools/seeding/official-data/scripts/activate-plan.sh A
wp eval-file $DEV/tests/workflow/run-plan.php A

# 快速切换到 Plan B
bash $DEV/dev-tools/seeding/official-data/scripts/deactivate-plan.sh A
bash $DEV/dev-tools/seeding/official-data/scripts/cleanup-wptsall.sh
bash $DEV/dev-tools/seeding/official-data/scripts/activate-plan.sh B
wp eval-file $DEV/tests/workflow/run-plan.php B
```

---

## 常见问题

### Q1: WordPress Core 数据需要清理吗？

**A**: ❌ 不需要。WordPress Core 数据（文章、页面、用户）只填充一次，所有计划共享。这样可以：
- 节省时间（不用每次重新导入）
- 提供一致的背景数据
- 不影响插件数据测试

### Q2: 为什么要卸载重装插件？

**A**: ✅ 卸载重装可以触发插件的 `uninstall.php`，确保：
- 删除所有自定义表（如 `wp_woocommerce_*`）
- 清理所有 postmeta 数据
- 清理所有 options 配置
- 避免之前测试的翻译残留干扰新测试

### Q3: 什么时候可以只激活而不重装？

**A**: ✅ 当所有计划都验证正确后，后续重测可以：
- 跳过 uninstall/install 步骤
- 直接 deactivate 旧计划 + activate 新计划
- 节省约 80% 的时间

### Q4: WPTSALL 数据为什么要清理？

**A**: ✅ WPTSALL 数据包含：
- 模型定义（models 表）
- 翻译规则（translation_rules 表）
- 站点关系（site_relations 表）
- 任务记录（tasks 表）

切换计划时需要清理，否则会有上一计划的残留数据。

### Q5: Tool Plugins 会被清理吗？

**A**: ❌ 不会。以下插件始终保持激活，不参与清理：
- Always Active (5个)
- Ignored (9个)
- 数据生成工具 (3个)

### Q6: 测试一个计划需要多久？

**A**: 时间估算：
- 首次测试（卸载重装）：~8-10 分钟/计划
- 快速切换（只激活）：~2-3 分钟/计划

### Q7: 如何验证测试结果？

**A**: 使用以下脚本：
```bash
# 查看填充结果
wp eval-file $DEV/tests/workflow/show-plan-result.php A

# 查看任务执行情况
wp eval-file $DEV/tests/workflow/phase5-verify.php A

# 检查日志
tail -100 /usr/local/var/www/wp-content/uploads/wptsall-logs/*-tasks*.log
```

---

## 时间估算

| 操作 | 首次测试 | 快速切换 |
|------|----------|----------|
| WordPress Core 填充 | 30 秒 | - |
| 卸载插件 | 2 分钟 | - |
| 安装插件 | 2 分钟 | - |
| 激活插件 | - | 10 秒 |
| 停用插件 | 10 秒 | 10 秒 |
| 导入官方数据 | 3 分钟 | - |
| 填充自定义数据 | 2 分钟 | 2 分钟 |
| WPTSALL 清理 | 5 秒 | 5 秒 |
| 运行测试 | 1 分钟 | 1 分钟 |
| **单计划总计** | **~10 分钟** | **~3 分钟** |
| **4 计划总计** | **~40 分钟** | **~12 分钟** |

---

## 维护说明

### 更新此文档的时机

- 添加新插件到计划时
- 修改测试流程时
- 发现新的清理需求时
- 脚本路径变更时

### 相关文档

| 文档 | 说明 |
|------|------|
| [PLAN-DISTRIBUTION.md](PLAN-DISTRIBUTION.md) | 完整的计划分布和插件列表 |
| [CLEANUP-STRATEGY.md](CLEANUP-STRATEGY.md) | 清理策略详解 |
| [SUMMARY.md](SUMMARY.md) | 官方数据源汇总 |
| [README.md](README.md) | 官方数据使用指南 |

---

**最后更新**: 2026-01-26
**维护**: WPTSALL 开发团队

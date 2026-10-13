# 测试环境清理策略

**目的**: 为 WPTSALL 翻译插件测试提供干净的数据环境
**原则**: 彻底清理插件数据，保留 WordPress 核心数据

---

## 🎯 清理策略对比

### 方案 A: 卸载重装（推荐）✅

**操作方式**:
```bash
wp plugin uninstall <plugin> --deactivate --skip-delete
wp plugin install <plugin> --activate
```

**优点**:
- ✅ **100% 清理** - 触发插件的 `uninstall.php`，彻底清理
- ✅ **清理隐藏数据** - 插件自定义表、文件、缓存全部删除
- ✅ **重置配置** - 插件恢复默认设置
- ✅ **避免脏数据** - 不会有之前测试的残留数据干扰翻译测试

**缺点**:
- ⚠️ 需要重新配置插件设置
- ⚠️ 依赖本地插件包（不需要网络下载）

**适用场景**: ✅ **翻译插件测试**（你的场景）

---

### 方案 B: 数据清理（不推荐）

**操作方式**:
```bash
wp post delete $(wp post list --post_type=product --format=ids) --force
wp db query "TRUNCATE TABLE wp_custom_table"
```

**优点**:
- ✅ 保留插件配置
- ✅ 速度快

**缺点**:
- ❌ 可能遗漏数据（自定义表、meta、缓存）
- ❌ 可能留下脏数据（影响翻译测试）
- ❌ 不够彻底

**适用场景**: ❌ 不适合需要干净环境的翻译测试

---

## ✅ 推荐方案：分层清理

### 层级 1: WordPress 核心数据（保留）

**不清理的内容**:
```
- WordPress 核心文件
- wp-config.php
- wp_users 表（管理员账户）
- wp_options 表中的核心配置
- 主题和必要插件
```

**只填充一次**:
```bash
# WordPress Core 数据只导入一次，所有计划共享
wp import wordpress-core/themeunittestdata.wordpress.xml --authors=create
```

---

### 层级 2: WPTSALL 翻译数据（测试后清理）

**清理内容**:
```sql
-- WPTSALL 表（测试后清理，不影响插件代码）
TRUNCATE TABLE wp_wptsall_site_relations;
TRUNCATE TABLE wp_wptsall_relation_models;
TRUNCATE TABLE wp_wptsall_virtual_sites;
TRUNCATE TABLE wp_wptsall_virtual_site_content;
TRUNCATE TABLE wp_wptsall_models;
TRUNCATE TABLE wp_wptsall_translation_rules;
TRUNCATE TABLE wp_wptsall_templates;
TRUNCATE TABLE wp_wptsall_template_entries;
TRUNCATE TABLE wp_wptsall_tasks;
TRUNCATE TABLE wp_wptsall_task_items;
```

**时机**:
- 切换计划前清理
- 测试完成后清理

---

### 层级 3: 内容插件（卸载重装）✅

**64 个内容插件 - 按计划分批处理**

#### Plan A 插件（20个）

**Content 插件**:
```bash
# 1. 卸载
for plugin in woocommerce bbpress academy masterstudy-lms-learning-management-system \
    easy-digital-downloads classified-listing easy-property-listings cooked \
    envira-gallery-lite site-reviews tablepress testimonial-free; do
    wp plugin uninstall $plugin --deactivate --skip-delete 2>/dev/null || true
done

# 2. 重装
for plugin in woocommerce bbpress academy masterstudy-lms-learning-management-system \
    easy-digital-downloads classified-listing easy-property-listings cooked \
    envira-gallery-lite site-reviews tablepress testimonial-free; do
    wp plugin install $plugin --activate 2>/dev/null || true
done

# 3. 填充官方数据
bash /path/to/import-by-plan.sh A

# 4. 填充自定义数据
wp eval-file /path/to/seed-content.php A
```

**Utility 插件**:
```bash
# 这些插件通常不需要卸载重装，只需要清理配置
# wordpress-seo, advanced-custom-fields, contact-form-7, buddypress,
# easy-appointments, simply-schedule-appointments, powerpress, redirection
```

---

#### Plan B/C/D 插件（类似操作）

---

### 层级 4: 工具插件（不清理）

**Always Active（5个）** - 保持激活，不清理
- classic-editor
- loco-translate
- duplicate-post
- custom-post-type-ui
- akismet

**Ignored（9个）** - 保持激活，不清理
- action-scheduler, hello, plugin-check, etc.

**数据填充工具（3个）** - 保持激活，不清理
- bp-default-data
- fakerpress
- wordpress-importer

---

## 🚀 完整清理与填充流程

### 场景：测试 Plan A，然后切换到 Plan B

```bash
#!/bin/bash
# 完整的清理和填充流程

DEV=/Users/zhangxiao/wptsall-dev
WP=/usr/local/var/www

# ========================================
# Part 1: 准备工作（只执行一次）
# ========================================

cd $WP

# 1.1 填充 WordPress 核心数据（只执行一次）
echo "填充 WordPress 核心数据..."
wp import $DEV/dev-tools/seeding/official-data/wordpress-core/themeunittestdata.wordpress.xml \
    --authors=create

# ========================================
# Part 2: 测试 Plan A
# ========================================

echo "========================================
测试 Plan A
========================================"

# 2.1 清理 WPTSALL 翻译数据
echo "清理 WPTSALL 数据..."
bash $DEV/dev-tools/seeding/official-data/scripts/cleanup-wptsall.sh

# 2.2 卸载 Plan A 插件
echo "卸载 Plan A 插件..."
bash $DEV/dev-tools/seeding/official-data/scripts/uninstall-plan.sh A

# 2.3 重装 Plan A 插件
echo "重装 Plan A 插件..."
bash $DEV/dev-tools/seeding/official-data/scripts/install-plan.sh A

# 2.4 填充 Plan A 数据
echo "填充 Plan A 官方数据..."
bash $DEV/dev-tools/seeding/official-data/scripts/import-by-plan.sh A

echo "填充 Plan A 自定义数据..."
wp eval-file $DEV/tests/workflow/seed-content.php A

# 2.5 运行测试
echo "运行 WPTSALL 翻译测试..."
wp eval-file $DEV/tests/workflow/run-plan.php A

# ========================================
# Part 3: 切换到 Plan B
# ========================================

echo "========================================
切换到 Plan B
========================================"

# 3.1 停用 Plan A 插件（不卸载）
bash $DEV/dev-tools/seeding/official-data/scripts/deactivate-plan.sh A

# 3.2 清理 WPTSALL 翻译数据
bash $DEV/dev-tools/seeding/official-data/scripts/cleanup-wptsall.sh

# 3.3 卸载 Plan B 插件（如果之前安装过）
bash $DEV/dev-tools/seeding/official-data/scripts/uninstall-plan.sh B

# 3.4 安装并激活 Plan B 插件
bash $DEV/dev-tools/seeding/official-data/scripts/install-plan.sh B

# 3.5 填充 Plan B 数据
bash $DEV/dev-tools/seeding/official-data/scripts/import-by-plan.sh B
wp eval-file $DEV/tests/workflow/seed-content.php B

# 3.6 运行测试
wp eval-file $DEV/tests/workflow/run-plan.php B

echo "完成！"
```

---

## 📋 关键决策

### ✅ 采用的策略

| 层级 | 内容 | 策略 |
|------|------|------|
| WordPress Core | 核心数据 | **保留** - 只填充一次 |
| WPTSALL | 翻译数据 | **清理** - 每次切换计划前清理 |
| Content 插件 | 业务数据 | **卸载重装** - 彻底清理后重填充 |
| Utility 插件 | 配置型 | **保留** - 不卸载，少量清理 |
| 工具插件 | Always/Ignored | **保留** - 不动 |

---

## 🎯 为什么这样设计

### 1. WordPress Core 只填充一次

**原因**:
- ✅ 节省时间（30+ posts 导入需要时间）
- ✅ 提供基础内容（post, page）
- ✅ 所有计划共享，测试一致性更好

**WordPress Core 包含**:
- 基础文章和页面
- 分类和标签
- 评论
- 用户（authors）

**不影响翻译测试**，因为：
- 每个计划有自己的插件数据
- WPTSALL 翻译的是插件数据，不是 Core 数据
- Core 数据可以作为"背景数据"

---

### 2. 内容插件卸载重装

**原因**:
- ✅ 测试翻译插件需要干净数据
- ✅ 避免之前测试的翻译残留
- ✅ 避免错误的字段映射影响测试
- ✅ 每次测试都是全新环境

**示例场景**:
```
Plan A 测试 WooCommerce 翻译
  → 产品数据被翻译
  → postmeta 中有翻译标记

切换到 Plan B
  → 如果不卸载，WooCommerce 数据还在
  → 可能干扰新的测试

卸载 WooCommerce
  → 清理所有 product 数据
  → 清理 wp_woocommerce_* 表
  → 清理 postmeta 中的 WooCommerce 字段

重装后填充
  → 全新的产品数据
  → 没有任何翻译残留
```

---

### 3. WPTSALL 数据按需清理

**清理时机**:
- 切换计划前
- 测试完成后
- 需要重新测试时

**保留时机**:
- 同一计划的多次测试
- 需要查看测试结果时

---

## 🔧 实现脚本

**脚本位置**: `scripts/`

已创建以下脚本：

1. ✅ `scripts/uninstall-plan.sh <plan>` - 卸载指定计划的插件
2. ✅ `scripts/install-plan.sh <plan>` - 安装指定计划的插件
3. ✅ `scripts/activate-plan.sh <plan>` - 激活指定计划的插件（快速模式）
4. ✅ `scripts/deactivate-plan.sh <plan>` - 停用指定计划的插件
5. ✅ `scripts/import-by-plan.sh <plan>` - 导入指定计划的官方数据
6. ✅ `scripts/cleanup-wptsall.sh` - 清理 WPTSALL 翻译数据
7. ✅ `scripts/full-test-cycle.sh [--quick]` - 完整的测试周期

### 使用示例

```bash
# 单个计划测试（首次）
bash scripts/cleanup-wptsall.sh
bash scripts/uninstall-plan.sh A
bash scripts/install-plan.sh A
bash scripts/import-by-plan.sh A

# 切换计划（完整模式）
bash scripts/deactivate-plan.sh A
bash scripts/cleanup-wptsall.sh
bash scripts/uninstall-plan.sh B
bash scripts/install-plan.sh B
bash scripts/import-by-plan.sh B

# 切换计划（快速模式 - 插件已验证）
bash scripts/deactivate-plan.sh A
bash scripts/cleanup-wptsall.sh
bash scripts/activate-plan.sh B

# 完整测试周期
bash scripts/full-test-cycle.sh          # 完整测试（约 40 分钟）
bash scripts/full-test-cycle.sh --quick  # 快速测试（约 12 分钟）
```

---

## 📊 时间估算

| 操作 | 时间 | 频率 |
|------|------|------|
| WordPress Core 填充 | ~30 秒 | 一次 |
| Plan A 卸载重装 | ~2 分钟 | 每次切换 |
| Plan A 数据填充 | ~3 分钟 | 每次切换 |
| WPTSALL 清理 | ~5 秒 | 每次切换 |
| **总计（切换一次）** | **~5-6 分钟** | - |

---

**最后更新**: 2026-01-26
**维护**: WPTSALL 开发团队

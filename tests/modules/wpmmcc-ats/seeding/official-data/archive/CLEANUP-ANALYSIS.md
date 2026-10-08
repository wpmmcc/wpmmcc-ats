# 数据清理方案分析

**分析日期**: 2026-01-26
**目标**: 为 seeding 目录设计统一的、可靠的数据清理方案

---

## 🎯 清理需求

### 场景 1: 重新填充数据
- **频率**: 经常
- **需求**: 快速清理，保留插件配置
- **目标**: 只删除填充的内容，不影响插件设置

### 场景 2: 切换测试计划
- **频率**: 偶尔
- **需求**: 完全清理，切换到不同的插件组合
- **目标**: 清理所有相关插件数据

### 场景 3: 重置测试环境
- **频率**: 很少
- **需求**: 完全重置，恢复到初始状态
- **目标**: 所有数据清零，重新开始

---

## 📊 清理方案对比

### 方案 1: 卸载插件再安装

**操作方式**:
```bash
wp plugin uninstall {plugin} --deactivate
wp plugin install {plugin} --activate
```

**评估**:

| 维度 | 评分 | 说明 |
|------|------|------|
| **完整性** | ⭐⭐⭐⭐⭐ | 使用插件自身卸载逻辑，100% 清理 |
| **速度** | ⭐ | 需要下载、安装，87 个插件耗时 30+ 分钟 |
| **可靠性** | ⭐⭐⭐ | 依赖网络，可能下载失败 |
| **配置保留** | ❌ | 丢失所有插件配置，需要重新设置 |
| **操作复杂度** | ⭐⭐ | 需要逐个处理 87 个插件 |

**适用场景**: ❌ 不推荐（耗时长、丢失配置）

---

### 方案 2: WP-CLI 删除内容（推荐）

**操作方式**:
```bash
# 删除指定 post_type 的所有内容
wp post delete $(wp post list --post_type=product --format=ids) --force

# 或者批量删除
wp post list --post_type=product --format=ids | xargs -n 100 wp post delete --force
```

**评估**:

| 维度 | 评分 | 说明 |
|------|------|------|
| **完整性** | ⭐⭐⭐⭐ | wp_delete_post() 触发钩子，清理关联数据 |
| **速度** | ⭐⭐⭐⭐⭐ | 批量删除，1000 条/分钟 |
| **可靠性** | ⭐⭐⭐⭐⭐ | 离线可用，不依赖网络 |
| **配置保留** | ✅ | 保留插件配置和设置 |
| **操作复杂度** | ⭐⭐⭐⭐ | 脚本化，一键执行 |

**适用场景**: ✅ **日常重新填充**（推荐）

**清理覆盖**:
- ✅ wp_posts 表数据（post_title, post_content 等）
- ✅ wp_postmeta 表关联数据（自动删除）
- ✅ wp_term_relationships 表关联（自动删除）
- ✅ wp_comments 表关联评论（自动删除）
- ⚠️ 自定义表数据（需要额外处理）

---

### 方案 3: 直接 SQL 删除

**操作方式**:
```sql
DELETE FROM wp_posts WHERE post_type = 'product';
DELETE FROM wp_postmeta WHERE post_id NOT IN (SELECT ID FROM wp_posts);
DELETE FROM wp_term_relationships WHERE object_id NOT IN (SELECT ID FROM wp_posts);
```

**评估**:

| 维度 | 评分 | 说明 |
|------|------|------|
| **完整性** | ⭐⭐ | 不触发钩子，需要手动清理关联数据 |
| **速度** | ⭐⭐⭐⭐⭐ | 直接数据库操作，最快 |
| **可靠性** | ⭐⭐⭐ | 容易遗漏关联数据，导致孤立记录 |
| **配置保留** | ✅ | 保留插件配置 |
| **操作复杂度** | ⭐⭐ | 需要编写复杂 SQL，容易出错 |

**适用场景**: ⚠️ 仅用于特殊情况（如自定义表清理）

**风险**:
- ❌ 不触发 `before_delete_post`, `delete_post` 钩子
- ❌ 插件无法执行清理逻辑（如删除上传文件）
- ❌ 可能留下孤立的 meta 数据
- ❌ 可能破坏外键约束

---

### 方案 4: 混合方案（最佳实践）✅

**操作方式**:
```bash
# 1. WP-CLI 删除标准 post_type 内容（90% 插件）
wp post delete $(wp post list --post_type=product --format=ids) --force

# 2. SQL TRUNCATE 自定义表（10% 插件）
wp db query "TRUNCATE TABLE wp_tablepress_tables"

# 3. 清理孤立数据
wp db query "DELETE FROM wp_postmeta WHERE post_id NOT IN (SELECT ID FROM wp_posts)"
```

**评估**:

| 维度 | 评分 | 说明 |
|------|------|------|
| **完整性** | ⭐⭐⭐⭐⭐ | 覆盖所有情况，无遗漏 |
| **速度** | ⭐⭐⭐⭐ | WP-CLI 批量 + SQL 快速 |
| **可靠性** | ⭐⭐⭐⭐⭐ | 结合两者优点 |
| **配置保留** | ✅ | 保留插件配置 |
| **操作复杂度** | ⭐⭐⭐⭐ | 脚本化，自动处理 |

**适用场景**: ✅ **所有场景**（推荐作为默认方案）

---

## 🔍 插件分类分析

### 类型 1: 标准 CPT 插件（90%）

**特点**: 使用 `register_post_type()` 注册自定义文章类型

**插件示例**:
- WooCommerce (product)
- BBPress (forum, topic, reply)
- Academy LMS (academy_courses, academy_lessons)
- LearnPress (lp_course, lp_lesson, lp_quiz)
- Easy Digital Downloads (download)

**清理方式**: ✅ WP-CLI `wp post delete`

**原因**:
- 所有数据存储在 wp_posts 和 wp_postmeta
- wp_delete_post() 会自动清理关联数据
- 触发插件钩子，执行额外清理逻辑

---

### 类型 2: 自定义表插件（5%）

**特点**: 使用独立的数据库表存储数据

**插件示例**:
- TablePress (`wp_tablepress_tables`)
- BuddyPress (`wp_bp_activity`, `wp_bp_groups` 等)
- Events Manager (`wp_em_events`, `wp_em_locations`)

**清理方式**: ⚠️ SQL TRUNCATE 或插件提供的清理 API

**示例**:
```bash
# TablePress
wp db query "TRUNCATE TABLE wp_tablepress_tables"
wp db query "TRUNCATE TABLE wp_tablepress_options"

# BuddyPress
wp eval 'global $bp; $bp->activity->truncate(); $bp->groups->truncate();'
```

---

### 类型 3: 混合存储插件（5%）

**特点**: 同时使用 CPT + 自定义表

**插件示例**:
- WooCommerce (product CPT + `wp_woocommerce_*` 表)
- Give (give_forms CPT + `wp_give_*` 表)

**清理方式**: ✅ WP-CLI + SQL TRUNCATE

**示例**:
```bash
# 删除 CPT 内容
wp post delete $(wp post list --post_type=product --format=ids) --force

# 清理自定义表
wp db query "TRUNCATE TABLE wp_woocommerce_order_items"
wp db query "TRUNCATE TABLE wp_woocommerce_order_itemmeta"
```

---

## 📋 推荐的清理流程

### Step 1: 备份数据库

```bash
BACKUP_FILE="backup-$(date +%Y%m%d-%H%M%S).sql"
wp db export "$BACKUP_FILE"
echo "备份保存在: $BACKUP_FILE"
```

**重要性**: ⭐⭐⭐⭐⭐
**耗时**: ~10 秒（100MB 数据库）

---

### Step 2: 清理 WPTSALL 数据（可选）

```bash
# 使用 smart-cleanup.php
wp eval-file tests/workflow/smart-cleanup.php level0

# 或手动清理
wp db query "TRUNCATE TABLE wp_wptsall_virtual_site_content"
wp db query "TRUNCATE TABLE wp_wptsall_template_entries"
```

**目的**: 清理虚拟站点内容、翻译缓存
**耗时**: < 1 秒

---

### Step 3: 清理标准 CPT 内容

```bash
# 方式 1: 逐个删除（触发钩子）
for post_type in product forum topic reply lp_course; do
    echo "Cleaning $post_type..."
    wp post delete $(wp post list --post_type=$post_type --format=ids) --force 2>/dev/null || true
done

# 方式 2: 批量删除（推荐）
cat <<'EOF' | wp shell
$post_types = ['product', 'forum', 'topic', 'reply', 'lp_course'];
foreach ($post_types as $pt) {
    $posts = get_posts(['post_type' => $pt, 'posts_per_page' => -1, 'fields' => 'ids']);
    foreach ($posts as $id) {
        wp_delete_post($id, true);
    }
    echo "Cleaned: $pt (" . count($posts) . ")\n";
}
EOF
```

**耗时**: ~10-30 秒（1000 条数据）

---

### Step 4: 清理自定义表（针对特殊插件）

```bash
# TablePress
wp db query "TRUNCATE TABLE wp_tablepress_tables" 2>/dev/null || true

# BuddyPress
if wp plugin is-active buddypress; then
    wp db query "TRUNCATE TABLE wp_bp_activity" 2>/dev/null || true
    wp db query "TRUNCATE TABLE wp_bp_groups" 2>/dev/null || true
fi

# WooCommerce 订单表（可选）
if wp plugin is-active woocommerce; then
    wp db query "TRUNCATE TABLE wp_woocommerce_order_items" 2>/dev/null || true
fi
```

**耗时**: < 1 秒

---

### Step 5: 清理孤立数据

```bash
# 清理孤立的 postmeta
wp db query "DELETE FROM wp_postmeta WHERE post_id NOT IN (SELECT ID FROM wp_posts)"

# 清理孤立的 term_relationships
wp db query "DELETE FROM wp_term_relationships WHERE object_id NOT IN (SELECT ID FROM wp_posts)"

# 清理孤立的 comments
wp db query "DELETE FROM wp_comments WHERE comment_post_ID NOT IN (SELECT ID FROM wp_posts)"
```

**耗时**: ~1-5 秒

---

### Step 6: 重置自增 ID（可选）

```bash
# 重置 wp_posts 自增 ID（从 1 开始）
wp db query "ALTER TABLE wp_posts AUTO_INCREMENT = 1"

# 重置 wp_postmeta 自增 ID
wp db query "ALTER TABLE wp_postmeta AUTO_INCREMENT = 1"
```

**目的**: 使新填充的数据从 ID=1 开始
**注意**: ⚠️ 如果保留了 WordPress 核心内容（Hello World 等），不要重置

---

## 🎯 按计划清理（Plan-Based Cleanup）

### Plan A 清理示例

```bash
# Plan A 插件列表
PLAN_A_POST_TYPES=(
    "post"              # WordPress Core
    "page"              # WordPress Core
    "product"           # WooCommerce
    "forum"             # BBPress
    "topic"             # BBPress
    "reply"             # BBPress
    "academy_courses"   # Academy LMS
    "academy_lessons"   # Academy LMS
)

# 批量清理
for pt in "${PLAN_A_POST_TYPES[@]}"; do
    echo "Cleaning $pt..."
    wp post delete $(wp post list --post_type=$pt --format=ids 2>/dev/null) --force 2>/dev/null || true
done
```

### Plan-Aware 清理配置

```json
{
  "plans": {
    "A": {
      "post_types": ["post", "page", "product", "forum", "topic", "reply"],
      "custom_tables": [],
      "plugins": ["wordpress-blog", "woocommerce", "bbpress", "academy"]
    },
    "B": {
      "post_types": ["post", "page", "rtcl_listing", "event"],
      "custom_tables": ["wp_em_events"],
      "plugins": ["wordpress-blog", "classified-listing", "events-manager"]
    }
  }
}
```

---

## ⚠️ 注意事项

### 1. 不要清理 WordPress 核心内容（除非确认）

保留的内容:
- ✅ Post ID=1 (Hello World)
- ✅ Page ID=2 (Sample Page)
- ✅ User ID=1 (管理员)
- ✅ Comment ID=1 (默认评论)

```bash
# 清理时排除核心内容
wp post delete $(wp post list --post_type=post --post__not_in=1 --format=ids) --force
wp post delete $(wp post list --post_type=page --post__not_in=2 --format=ids) --force
```

---

### 2. 多站点环境特殊处理

```bash
# 清理所有子站点的同步内容
for site_id in 2 3 4; do
    wp --url=$(wp site url $site_id) post delete \
        $(wp --url=$(wp site url $site_id) post list --format=ids) --force
done
```

---

### 3. 清理前确认

```bash
# 统计将要删除的数据量
echo "Will delete:"
wp post list --post_type=product --format=count
wp post list --post_type=forum --format=count
```

---

## 🚀 最终推荐方案

### 方案名称: **Smart Cleanup with Plan Support**

**特点**:
1. ✅ 基于 WP-CLI 的批量删除（触发钩子）
2. ✅ 支持按计划清理（Plan A/B/C/D）
3. ✅ 自动检测自定义表并清理
4. ✅ 保留插件配置和设置
5. ✅ 自动备份数据库
6. ✅ 清理孤立数据
7. ✅ 支持预览模式（dry-run）

**脚本位置**: `/Users/zhangxiao/wptsall-dev/dev-tools/seeding/cleanup-data.php`

**使用方法**:
```bash
# 预览清理（不实际删除）
wp eval-file dev-tools/seeding/cleanup-data.php A --dry-run

# 执行清理
wp eval-file dev-tools/seeding/cleanup-data.php A

# 完全清理（包括 WPTSALL 数据）
wp eval-file dev-tools/seeding/cleanup-data.php A --full
```

---

## 📊 性能对比

| 方案 | 1000 条数据 | 10000 条数据 | 备注 |
|------|------------|-------------|------|
| 卸载重装 | 30 分钟+ | 30 分钟+ | 与数据量无关，取决于插件数 |
| WP-CLI 删除 | 10-15 秒 | 60-90 秒 | 触发钩子，逐条删除 |
| SQL 删除 | 1-2 秒 | 5-10 秒 | 最快，但不触发钩子 |
| 混合方案 | 12-17 秒 | 65-100 秒 | 结合安全性和速度 |

**结论**: 混合方案在速度和安全性之间取得最佳平衡

---

**维护**: WPTSALL 开发团队
**最后更新**: 2026-01-26

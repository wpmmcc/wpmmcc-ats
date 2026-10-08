# 数据填充前置配置脚本

本目录包含在填充数据**之前**需要执行的配置脚本，用于解决插件冲突和预配置问题。

## 为什么需要前置配置

很多 WordPress 插件在默认配置下存在冲突：

- **URL 冲突**：多个 LMS 插件都使用 `/courses/` 作为 URL 前缀
- **数据库冲突**：某些插件的表名/选项名冲突
- **功能冲突**：同类型插件的功能相互覆盖

这些问题需要在**激活插件后、填充数据前**解决。

## 执行时机

```
激活插件
    ↓
══════════════════════
  执行 Pre-Setup 脚本  ← 这里
══════════════════════
    ↓
填充数据 (Phase 1/2/3)
    ↓
执行 Post-Setup 脚本
    ↓
验证
```

## 脚本列表

| 脚本 | 说明 | 影响的插件 |
|------|------|-----------|
| `lms-url-conflict-resolver.php` | 解决多个 LMS 插件的 URL 冲突 | Academy, MasterStudy, LearnPress, LifterLMS, Tutor, Sensei |
| `dispatcher.php` | 调度器，根据计划执行对应脚本 | - |

## 使用方式

### 通过调度器执行（推荐）

```bash
cd /usr/local/var/www

# 执行 Plan A 的所有前置配置
wp eval-file /Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data/scripts/pre-setup/dispatcher.php A
```

### 单独执行

```bash
cd /usr/local/var/www

# 解决 LMS URL 冲突
wp eval-file /Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data/scripts/pre-setup/lms-url-conflict-resolver.php
```

## LMS URL 冲突解决方案

默认情况下多个 LMS 插件的 URL 冲突：

| 插件 | 默认 Single URL | 默认 Archive URL |
|------|-----------------|------------------|
| Academy LMS | `/course/xxx/` | `/courses/` |
| MasterStudy | `/courses/xxx/` | `/courses/` |
| LearnPress | `/courses/xxx/` | `/courses/` |
| LifterLMS | `/course/xxx/` | `/courses/` |
| Tutor LMS | `/courses/xxx/` | `/courses/` |
| Sensei LMS | `/course/xxx/` | `/courses/` |

解决方案 - 为每个 LMS 配置唯一的 URL 前缀：

| 插件 | 修改后 Single URL | 修改后 Archive URL |
|------|-------------------|-------------------|
| Academy LMS | `/academy-course/xxx/` | `/academy-courses/` |
| MasterStudy | `/stm-course/xxx/` | `/stm-courses/` |
| LearnPress | `/lp-course/xxx/` | `/lp-courses/` |
| LifterLMS | `/llms-course/xxx/` | `/llms-courses/` |
| Tutor LMS | `/tutor-course/xxx/` | `/tutor-courses/` |
| Sensei LMS | `/sensei-course/xxx/` | `/sensei-courses/` |

## 编写新的前置配置脚本

### 脚本模板

```php
<?php
/**
 * {问题描述} 解决脚本（填充前执行）
 *
 * 执行时机: 激活插件后、填充数据前
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  {脚本名称}                                                  ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

$changes = array();

// 1. 检查插件是否激活
if ( ! post_type_exists( '{post_type}' ) ) {
    echo "  插件未激活，跳过\n";
    return;
}

// 2. 检查当前配置
$current_value = get_option( 'xxx', 'default' );

// 3. 如果需要修改，执行修改
if ( $current_value === 'conflict_value' ) {
    update_option( 'xxx', 'new_value' );
    echo "  ✓ 修改 xxx: conflict_value -> new_value\n";
    $changes[] = 'xxx';
}

// 4. 刷新永久链接（如果修改了 URL 相关配置）
if ( ! empty( $changes ) ) {
    flush_rewrite_rules( true );
    echo "  ✓ 永久链接已刷新\n";
}

return array( 'changes' => $changes, 'success' => true );
```

### 命名规范

- 文件名：描述性名称，如 `{problem}-resolver.php`
- 应该是幂等的（可重复执行）
- 只在需要时修改，已正确配置则跳过

## 与工作流的集成

Pre-setup 脚本应该集成到 seed-content.php 的工作流中：

```php
// 在 Phase 1 之前执行 pre-setup
if ( $phase === null || $phase === 0 ) {
    include SCRIPTS_DIR . '/pre-setup/dispatcher.php';
}
```

## 已知问题与解决方案

### Academy LMS 的 `update_option` 问题

**问题**: 调用 `update_option('academy_settings', ...)` 会触发 Academy LMS 的钩子，导致脚本无限挂起。

**原因**: Academy LMS 在 `update_option` 钩子中执行了某些操作（可能是重新初始化或远程请求），导致脚本无法继续。

**解决方案**: `lms-url-conflict-resolver.php` 已修改为使用**直接 SQL 更新**，绕过所有钩子：

```php
// ❌ 错误 - 会导致脚本挂起
update_option( 'academy_settings', $academy_settings );

// ✅ 正确 - 使用直接 SQL 更新
global $wpdb;
$row = $wpdb->get_row(
    $wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
        'academy_settings'
    )
);

if ( $row ) {
    $academy_settings = json_decode( $row->option_value, true );
    $academy_settings['course_permalink_base'] = 'academy-course';
    $new_value = wp_json_encode( $academy_settings );

    $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s",
            $new_value,
            'academy_settings'
        )
    );

    // 清除缓存
    wp_cache_delete( 'academy_settings', 'options' );
    wp_cache_delete( 'alloptions', 'options' );
}
```

**注意事项**:
- Academy LMS 使用 **JSON 格式**存储设置（不是 PHP 序列化）
- 更新后必须清除对象缓存

---

## 常见问题

### Q: 为什么不在插件激活时自动配置？

A: 插件激活时可能还没有其他冲突插件。前置配置脚本在所有插件都激活后运行，可以检测到完整的冲突情况。

### Q: 如果已经填充了数据怎么办？

A: 修改 URL slug 会导致已有内容的 URL 变化。建议：
1. 清空数据
2. 运行 pre-setup
3. 重新填充

### Q: 脚本执行后配置又被覆盖了？

A: 某些插件可能在特定操作时重置配置。解决方案：
1. 检查插件的设置保存逻辑
2. 使用 filter hook 强制覆盖配置
3. 在 post-setup 中再次确认配置

### Q: 新插件的 update_option 也导致脚本挂起？

A: 参考 Academy LMS 的处理方式，使用直接 SQL 更新绕过钩子。步骤：
1. 检查插件使用的 option 名称
2. 确认存储格式（JSON 或 PHP 序列化）
3. 使用 `$wpdb->query()` 直接更新
4. 清除相关缓存

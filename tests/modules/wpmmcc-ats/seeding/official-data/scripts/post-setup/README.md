# 数据填充后置配置脚本

本目录包含各插件数据填充后的配置脚本，用于在导入/填充数据后配置必要的页面和设置。

## 为什么需要后置配置

很多 WordPress 插件需要特定的页面配置才能正常工作：
- WooCommerce 需要 Shop、Cart、Checkout、My Account 页面
- LMS 插件需要课程归档页、用户仪表盘页
- 食谱插件需要浏览页面

官方数据导入或自定义数据填充通常只创建内容，不配置这些页面。

## 脚本列表

| 脚本 | 插件 | 配置内容 |
|------|------|----------|
| `woocommerce-post-setup.php` | WooCommerce | Shop/Cart/Checkout/My Account 页面 |
| `masterstudy-post-setup.php` | MasterStudy LMS | 课程归档页、用户仪表盘 |
| `classified-listing-post-setup.php` | Classified Listing | Listings/Submit/Checkout/My Account 页面 |
| `cooked-post-setup.php` | Cooked | 食谱浏览页面 |
| `dispatcher.php` | - | 根据计划自动执行对应脚本 |

## 使用方式

### 单独执行

```bash
cd /usr/local/var/www

# WooCommerce 后置配置
wp eval-file /Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data/scripts/post-setup/woocommerce-post-setup.php

# MasterStudy 后置配置
wp eval-file /Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data/scripts/post-setup/masterstudy-post-setup.php

# Cooked 后置配置
wp eval-file /Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data/scripts/post-setup/cooked-post-setup.php
```

### 通过调度器执行

```bash
cd /usr/local/var/www

# 执行 Plan A 的所有后置配置
wp eval-file /Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data/scripts/post-setup/dispatcher.php A
```

### 集成到填充流程

后置配置脚本会在 `seed-content.php` 的 Phase 1 和 Phase 2 完成后自动执行。

## 编写新的后置配置脚本

### 脚本模板

```php
<?php
/**
 * {插件名称} 数据填充后置配置脚本
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

// 检查插件是否激活
if ( ! post_type_exists( '{post_type}' ) ) {
    echo "❌ {插件名称} 未激活，跳过配置\n";
    return;
}

$changes = array();

// 1. 创建/配置页面
// ...

// 2. 配置设置
// ...

// 3. 刷新永久链接
flush_rewrite_rules();

// 4. 输出结果
// ...
```

### 命名规范

- 文件名：`{plugin-slug}-post-setup.php`
- 应该是幂等的（可重复执行，不会重复创建）
- 先检查配置是否已存在，只在需要时创建

## 配置项参考

### WooCommerce

| Option | 说明 |
|--------|------|
| `woocommerce_shop_page_id` | 商店页面 ID |
| `woocommerce_cart_page_id` | 购物车页面 ID |
| `woocommerce_checkout_page_id` | 结账页面 ID |
| `woocommerce_myaccount_page_id` | 我的账户页面 ID |

### MasterStudy LMS

| Option | 说明 |
|--------|------|
| `stm_lms_courses_page` | 课程归档页面 ID |
| `stm_lms_user_url` | 用户仪表盘页面 ID |
| `stm_lms_courses_slug` | 课程 URL 前缀 |

### Classified Listing

| Option | 说明 |
|--------|------|
| `rtcl_advanced_settings['listings_page']` | 分类信息列表页 ID |
| `rtcl_advanced_settings['submission_form_page']` | 提交表单页 ID |
| `rtcl_advanced_settings['checkout_page']` | 结账页 ID |
| `rtcl_advanced_settings['myaccount_page']` | 我的账户页 ID |

### Cooked

| Option | 说明 |
|--------|------|
| `cooked_browse_page` | 食谱浏览页面 ID |
| `cooked_settings['recipe_slug']` | 食谱 URL 前缀 |

## 常见问题

### Q: 为什么页面创建了但 URL 还是 404？

A: 需要刷新永久链接。脚本会自动调用 `flush_rewrite_rules()`，但如果问题仍存在，可以：
1. 手动访问 后台 → 设置 → 固定链接，点击保存
2. 或执行 `wp rewrite flush`

### Q: 脚本执行后配置还是不对？

A: 检查插件是否正确激活。部分插件在激活时会注册自己的设置，如果插件未激活，设置可能不会生效。

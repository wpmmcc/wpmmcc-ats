# 数据填充验证脚本

本目录包含各插件数据填充后的验证脚本，用于验证填充结果的完整性和可访问性。

## 验证内容

每个插件的验证脚本通常检查以下内容：

1. **页面配置** - 插件必要页面是否已配置（如 WooCommerce 的 Shop/Cart 页面）
2. **内容数量** - 填充的内容数量是否达到预期
3. **前台访问** - 归档页、单页等 URL 是否可访问（HTTP 200）
4. **后台访问** - 后台列表页、编辑页是否可访问

## 脚本列表

| 脚本 | 插件 | 验证内容 |
|------|------|----------|
| `woocommerce-verify.php` | WooCommerce | 页面配置、产品数量、前后台访问 |
| `bbpress-verify.php` | bbPress | 论坛/话题/回复数量、前后台访问 |
| `edd-verify.php` | Easy Digital Downloads | 下载数量、前后台访问 |
| `academy-verify.php` | Academy LMS | 课程数量、前后台访问 |
| `masterstudy-verify.php` | MasterStudy LMS | 页面配置、课程数量、前后台访问 |
| `classified-listing-verify.php` | Classified Listing | 分类信息数量、前后台访问 |
| `easy-property-listings-verify.php` | Easy Property Listings | 房产数量、前后台访问 |
| `cooked-verify.php` | Cooked | 页面配置、食谱数量、前后台访问 |
| `envira-verify.php` | Envira Gallery | 图库数量、前后台访问 |
| `site-reviews-verify.php` | Site Reviews | 评论数量、前后台访问 |
| `dispatcher.php` | - | 根据计划自动执行对应验证脚本 |

## 使用方式

### 单独执行

```bash
cd /usr/local/var/www

# WooCommerce 验证
wp eval-file /Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data/scripts/verify/woocommerce-verify.php

# Cooked 验证
wp eval-file /Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data/scripts/verify/cooked-verify.php
```

### 通过调度器执行

```bash
cd /usr/local/var/www

# 执行 Plan A 的所有验证
wp eval-file /Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data/scripts/verify/dispatcher.php A
```

## 输出格式

### 控制台输出

```
╔══════════════════════════════════════════════════════════════╗
║  WooCommerce 验证                                            ║
╚══════════════════════════════════════════════════════════════╝

--- 1. 页面配置 ---
  ✓ Shop 页面 (ID 7)
  ✓ Cart 页面 (ID 8)
  ✓ Checkout 页面 (ID 9)
  ✓ My Account 页面 (ID 10)

--- 2. 内容数量 ---
  ✓ 产品 (product) (693 条)
  ✓ 产品分类 (product_cat) (15 个)

--- 3. 前台访问 ---
  ✓ Shop 页面 (/shop/) -> 200
  ✓ Cart 页面 (/cart/) -> 200
  ✓ 产品单页 (/product/beanie/) -> 200

--- 4. 后台访问 ---
  ✓ 产品列表 -> 200
  ✓ 新建产品 -> 200

╔══════════════════════════════════════════════════════════════╗
║  验证完成: 12/12 通过 (100.0%)                               ║
╚══════════════════════════════════════════════════════════════╝
```

### JSON 结果文件

验证完成后，结果会保存到 `plans/{计划ID}/results/verify-result.json`：

```json
{
  "plan_id": "A",
  "timestamp": "2026-01-27 10:30:00",
  "executed": 10,
  "skipped": 0,
  "total_passed": 85,
  "total_tests": 90,
  "overall_rate": 94.4,
  "plugins": {
    "woocommerce": {
      "plugin": "woocommerce",
      "results": {...},
      "summary": {"total": 12, "passed": 12, "failed": 0, "rate": 100}
    }
  }
}
```

## 编写新的验证脚本

### 脚本模板

```php
<?php
/**
 * {插件名称} 数据填充验证脚本
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

require_once __DIR__ . '/verify-helpers.php';

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  {插件名称} 验证                                             ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// 检查插件是否激活
if ( ! post_type_exists( '{post_type}' ) ) {
    echo "❌ {插件名称} 未激活，跳过验证\n";
    return array( 'skipped' => true, 'reason' => 'plugin_inactive' );
}

$results = array();

// 1. 内容数量
echo "--- 1. 内容数量 ---\n";
$results['count_xxx'] = wptsall_verify_count_output( '{post_type}', '内容类型', 5 );

// 2. 前台访问
echo "\n--- 2. 前台访问 ---\n";
$sample_url = wptsall_get_sample_url( '{post_type}' );
if ( $sample_url ) {
    $sample_path = str_replace( home_url(), '', $sample_url );
    $result = wptsall_verify_url( $sample_path );
    wptsall_verify_url_output( "单页访问", $result );
    $results['url_single'] = $result;
}

// 3. 后台访问
echo "\n--- 3. 后台访问 ---\n";
$result = wptsall_verify_admin_url( 'edit.php?post_type={post_type}' );
wptsall_verify_url_output( '后台列表', $result );
$results['admin_list'] = $result;

// 汇总
$summary = wptsall_verify_summary( $results );
printf( "验证完成: %d/%d 通过 (%.1f%%)\n",
    $summary['passed'], $summary['total'], $summary['rate'] );

return array(
    'plugin'  => '{plugin-slug}',
    'results' => $results,
    'summary' => $summary,
);
```

### 命名规范

- 文件名：`{plugin-slug}-verify.php`
- 返回值：包含 `plugin`、`results`、`summary` 的数组
- 如果插件未激活：返回 `array( 'skipped' => true, 'reason' => 'plugin_inactive' )`

## 辅助函数

`verify-helpers.php` 提供以下辅助函数：

| 函数 | 说明 |
|------|------|
| `wptsall_verify_url($path)` | 测试前台 URL 访问状态 |
| `wptsall_verify_admin_url($path)` | 测试后台 URL 访问状态 |
| `wptsall_get_post_count($post_type)` | 获取 post_type 内容数量 |
| `wptsall_get_term_count($taxonomy)` | 获取 taxonomy term 数量 |
| `wptsall_get_sample_url($post_type)` | 获取随机一条内容的 URL |
| `wptsall_get_archive_url($post_type)` | 获取归档页 URL |
| `wptsall_verify_output($label, $success, $detail)` | 输出验证结果行 |
| `wptsall_verify_url_output($label, $result)` | 输出 URL 测试结果 |
| `wptsall_verify_count_output($post_type, $label, $expected)` | 输出内容统计 |
| `wptsall_verify_summary($results)` | 创建验证结果摘要 |

## 通过标准

- ✓ 通过率 >= 80%：正常
- ⚠️ 通过率 50-80%：警告
- ✗ 通过率 < 50%：失败

## 常见问题

### Q: 前台访问返回 404？

A: 可能原因：
1. 插件归档页未配置，需要运行 post-setup 脚本
2. 永久链接未刷新，执行 `wp rewrite flush`
3. 内容为空，需要先填充数据

### Q: 后台访问返回 302？

A: 302 重定向到登录页是正常的，验证脚本会将其视为成功。

### Q: 如何只验证特定插件？

A: 直接执行单个验证脚本：
```bash
wp eval-file /path/to/woocommerce-verify.php
```

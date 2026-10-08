# 官方数据资源

本目录管理有**官方示例数据**的插件资源。

---

## 支持的插件

| 插件 | 格式 | 导入方式 |
|------|------|----------|
| WordPress Core | XML | `wp import` |
| WooCommerce | CSV | PHP 脚本 |
| bbPress | XML | `wp import` |
| Easy Digital Downloads | XML | `wp import` |
| BuddyPress | WP-CLI | `wp bp generate` |
| LearnPress | Playwright | 后台导入 |
| Sensei LMS | CSV | `wp sensei-import` |
| LifterLMS | JSON | PHP 脚本 |
| Tutor LMS | XML | `wp import` |

---

## 目录结构

```
official-data/
├── README.md               # 本文件
├── wordpress-core/         # WP 核心测试数据
├── woocommerce/            # WooCommerce 示例
├── easy-digital-downloads/ # EDD 示例
├── sensei-lms/             # Sensei 课程数据
├── lifterlms/              # LifterLMS 数据
├── buddypress/             # BuddyPress 说明
├── downloads/              # 下载的官方数据
├── scripts/                # 导入脚本
└── archive/                # 归档的分析文档
```

---

## 使用方式

导入配置定义在 `seeding-config.json` 的 `official_data_sources` 节：

```json
{
  "official_data_sources": {
    "plugins": {
      "woocommerce": {
        "method": "php_script",
        "file": "scripts/wc-csv-import.php"
      }
    }
  }
}
```

---

## 归档文档

`archive/` 目录包含历史分析文档（仅供参考）：

- MANUAL-DATA-PLUGINS.md
- AUTO-GENERATION-PLUGINS.md
- PLAN-DISTRIBUTION.md
- TESTING-WORKFLOW.md
- 等

---

**最后更新**: 2026-01-27

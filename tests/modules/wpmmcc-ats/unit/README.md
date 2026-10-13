# WPTSALL 单元测试

测试单个类和方法的功能，不依赖外部服务。

**最后更新**: 2026-09-05

> **同步契约（必读）**：Lab 下请只走  
> `bash scripts/wptsall.sh test wp-unit [--file=…]`  
> 该入口会重新 `tar` + `docker cp` 到容器 `/tmp/tests`。直接 `docker exec … php /tmp/tests/…` **不会**同步 host 改动，会测到旧文件。  
> Sites/VS ↔ 产品流程对照见 `vsother/12-plugin-tests-vs-product.md`。

---

## 测试边界

**测试对象（In-scope）**

| 组件 | 说明 |
|------|------|
| WPTSALL WP 插件 | `wpmmcc-ats/source/` 下的全部 PHP 类 |
| WP 客户端 | `web/source/client/` — Tauri/Rust 客户端（由 E2E 覆盖） |
| 官网/服务端 | `web/source/server/` — Axum/Rust 服务（由 E2E 覆盖） |

**不在范围内（Out-of-scope）**

- 第三方内容插件（WooCommerce、Elementor、TEC 等）的代码修改  
- WordPress CMS 核心代码修改  
- 内容插件数据迁移逻辑

如果测试发现第三方插件的内容不符合预期（如 CPT 无内容、permalink 404），
修复方向是调整 WPTSALL 的**路由容错**、**样本选择策略**或**虚拟前端过滤逻辑**，
而不是修改第三方代码。

---

---

## 目录结构

```
tests/unit/
├── run.php                     # 测试运行器
├── README.md                   # 本文档
│
├── core/                       # Core 模块测试 (4)
│   ├── test-data-classifier.php
│   ├── test-smart-field-classifier.php
│   ├── test-url-classifier.php
│   └── test-helpers.php
│
├── models/                     # Models 模块测试 (14)
│   ├── test-model-service.php
│   ├── test-model-rest-controller.php
│   ├── test-model-scanner-v2.php
│   ├── test-plugin-mapping-service.php
│   ├── test-post-mapping-service.php
│   ├── test-term-mapping-service.php
│   ├── test-media-mapping-service.php
│   ├── test-discovery-rest-controller.php
│   ├── test-database-scanner.php
│   ├── test-template-field-validator.php
│   ├── test-unified-scanner.php
│   ├── test-orphan-resolver.php
│   ├── test-custom-model-service.php
│   └── test-get-merged-config.php
│
├── sites/                      # Sites 模块测试 (13)
│   ├── test-site-relation-service.php
│   ├── test-site-relation-validator.php
│   ├── test-sites-rest-controller.php
│   ├── test-site-relations-rest-controller.php
│   ├── test-site-relations-ajax.php
│   ├── test-virtual-site-service.php
│   ├── test-virtual-sites-rest-controller.php
│   ├── test-url-transformer.php
│   ├── test-relation-model-service.php
│   ├── test-relation-config-service.php
│   ├── test-config-driven-sync.php
│   ├── test-sites-rest-user-mappings.php
│   ├── test-sites-rest-capability-depth.php
│   └── test-manual-content-block-comments.php
│
├── tasks/                      # Tasks 模块测试 (7)
│   ├── test-tasks.php
│   ├── test-tasks-rest-controller.php
│   ├── test-monitoring-task-service.php
│   ├── test-direct-db-service.php
│   ├── test-translation-simulation-service.php
│   ├── test-virtual-site-storage.php
│   └── test-task-orchestrator.php
│
├── hooks/                      # Hooks 模块测试 (4)
│   ├── test-hook-manager.php
│   ├── test-hook-classes.php
│   ├── test-virtual-site-router.php
│   └── test-admin-virtual-site-manager.php
│
├── templates/                  # Templates 模块测试 (6)
│   ├── test-template-service.php
│   ├── test-template-entry-service.php
│   ├── test-template-rest-controller.php
│   ├── test-pot-parser.php
│   ├── test-content-string-scanner.php
│   ├── test-i18n-source-scanner.php
│   └── test-language-pack-scanner.php
│
├── log/                        # Log 模块测试 (1)
│   └── test-logger.php
│
└── docs/                       # 文档（归档）
    └── UNIT-TESTS-GUIDE.md
```

---

## 运行测试

### 运行所有测试

```bash
php /Users/zhangxiao/wptsall-dev/tests/unit/run.php
```

### 运行特定模块

```bash
# 运行 models 模块测试
php tests/unit/run.php --file=models/test-model-service.php

# 运行 sites 模块测试
php tests/unit/run.php --file=sites/test-site-relation-service.php
```

### 预期输出

```
=================================================
WPTSALL Unit Tests Runner
=================================================
WordPress 版本: 6.8.3
PHP 版本: 8.3.28

✅ WPTSALL 插件已激活
✅ 数据库表已就绪

发现 48 个测试文件
...

=================================================
测试结果
=================================================
✅ 通过: 156
❌ 失败: 0
总计: 156
```

---

## 测试覆盖

### Core 模块

| 测试文件 | 测试内容 |
|----------|----------|
| `test-data-classifier.php` | 数据类型分类器 |
| `test-smart-field-classifier.php` | 智能字段分类（翻译/同步/映射/计算） |
| `test-url-classifier.php` | URL 类型分类器 |
| `test-helpers.php` | 辅助函数（表名、虚拟站点等） |

### Models 模块

| 测试文件 | 测试内容 |
|----------|----------|
| `test-model-service.php` | 模型 CRUD、URL 规则 |
| `test-model-scanner-v2.php` | 插件扫描、字段发现 |
| `test-plugin-mapping-service.php` | 插件映射服务 |
| `test-*-mapping-service.php` | Post/Term/Media ID 映射 |
| `test-discovery-rest-controller.php` | 发现 API |

### Sites 模块

| 测试文件 | 测试内容 |
|----------|----------|
| `test-site-relation-service.php` | 站点关系 CRUD |
| `test-virtual-site-service.php` | 虚拟站点管理 |
| `test-url-transformer.php` | URL 转换 |
| `test-relation-model-service.php` | 关系-模型关联 |

### Tasks 模块

| 测试文件 | 测试内容 |
|----------|----------|
| `test-tasks.php` | 任务表结构、插入 |
| `test-monitoring-task-service.php` | 监控任务服务 |
| `test-translation-simulation-service.php` | 翻译模拟 |
| `test-task-orchestrator.php` | 任务编排 |

### Hooks 模块

| 测试文件 | 测试内容 |
|----------|----------|
| `test-hook-manager.php` | 钩子管理器 |
| `test-virtual-site-router.php` | 虚拟站点路由：permalink fallback / CPT 不可达 / template 500 / P0-3 redirect / 分页路径提取 |
| `test-admin-virtual-site-manager.php` | 管理员虚拟站点 |

### Templates 模块

| 测试文件 | 测试内容 |
|----------|----------|
| `test-template-service.php` | 模板 CRUD |
| `test-template-entry-service.php` | 模板条目 |
| `test-pot-parser.php` | POT 文件解析 |
| `test-*-scanner.php` | 内容/i18n 扫描器 |

---

## 测试运行器

`run.php` 是自定义测试运行器，特点：

1. **加载 WordPress 环境** - 通过 `wp-load.php` 使用真实环境
2. **模拟 WP_UnitTestCase** - 提供兼容的断言方法
3. **自动发现测试** - 运行所有 `test-*.php` 中的 `test_*` 方法
4. **Factory 支持** - 模拟 PHPUnit WordPress Test Library 的 `$this->factory`

### Factory 支持（v0.9.1+）

测试类可以使用 `$this->factory` 创建测试数据，创建的数据会在 `tearDown()` 时自动清理：

```php
class Test_My_REST_Controller extends WP_UnitTestCase {
    public function setUp(): void {
        parent::setUp();

        // 创建测试用户
        $this->admin_id = $this->factory->user->create( array(
            'role' => 'administrator',
        ) );
    }

    public function test_create_post() {
        // 创建测试文章
        $post_id = $this->factory->post->create( array(
            'post_title'  => 'Test Post',
            'post_status' => 'publish',
        ) );

        // 测试逻辑...
    }
}
```

**支持的 Factory 方法**：

| Factory | 方法 | 底层函数 |
|---------|------|----------|
| `$this->factory->user` | `create()`, `create_and_get()` | `wp_insert_user()` |
| `$this->factory->post` | `create()`, `create_and_get()` | `wp_insert_post()` |
| `$this->factory->term` | `create()` | `wp_insert_term()` |
| `$this->factory->comment` | `create()` | `wp_insert_comment()` |

### 支持的断言

```php
$this->assertEquals($expected, $actual);
$this->assertTrue($value);
$this->assertFalse($value);
$this->assertNull($value);
$this->assertIsArray($value);
$this->assertArrayHasKey($key, $array);
$this->assertContains($needle, $haystack);
$this->assertInstanceOf($class, $object);
$this->assertStringContainsString($needle, $haystack);
```

---

## 编写测试

### 基本结构

```php
<?php
class Test_My_Feature extends WP_UnitTestCase {

    public function setUp(): void {
        parent::setUp();
        // 测试前设置
    }

    public function tearDown(): void {
        // 测试后清理
        parent::tearDown();
    }

    public function test_my_function_returns_expected() {
        $result = my_function();
        $this->assertEquals('expected', $result);
    }

    public function test_my_function_handles_error() {
        $result = my_function('invalid');
        $this->assertInstanceOf(WP_Error::class, $result);
    }
}
```

### 命名规范

- 测试文件: `test-{feature}.php`
- 测试类: `Test_{Feature}`
- 测试方法: `test_{description}`

---

## 故障排除

### WordPress 未找到

修改 `run.php` 中的 `WP_SITE_PATH` 常量。

### 插件未激活

```bash
./sync.sh
cd /usr/local/var/www && wp plugin activate wpmmcc-ats
```

### 数据库表不存在

```bash
wp eval "wptsall_ensure_task_table(); wptsall_create_model_tables();"
```

---

**测试文件数**: 62 | **测试用例数**: 1373 | **通过标准**: 100%

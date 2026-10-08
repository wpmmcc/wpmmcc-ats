# WPTSALL 集成测试

按链路（`chains`）+ 契约（`contracts`）+ 流程（`flows`）组织的集成测试，使用 REST API 驱动测试。

**最后更新**: 2026-04-23

> 重要：当前建议的完整执行顺序为 `bootstrap -> contracts -> flows -> chains`。  
> `bootstrap` 已包含规则对齐门禁（`verify-rule-alignment.php`），若扫描结果与翻译规则不一致会直接阻断后续测试。
>
> 当前规范补充：
> 1. `wptsall/tests/integration/run.php` / `tests/modules/wpmmcc-ats/integration/run.php` 是当前仓库内可直接执行的 WP integration 入口。
> 2. 历史 `bash tests/modules/wpmmcc-ats/integration/run-stage1.sh` 当前缺少配套 `stage1` 源脚本，不能作为现役门禁。
> 3. 当前现役跨系统门禁统一走 `tests/modules/wpmmcc-ats/e2e/run.sh`、`run-project-matrix.sh`、`run-release-gate.sh` 等本地脚本。
> 4. 本仓库当前不再以 GitHub Actions runner / `gh workflow run ...` 作为默认测试回路；提交到 GitHub 不应再触发仓库内 workflow。

---

## 目录结构（当前仓库）

```
tests/modules/wpmmcc-ats/integration/
├── run.php                              # 集成测试运行器
├── README.md                            # 本文档
│
├── bootstrap/                           # ★ 环境准备与门禁（非测试类）
│   ├── reset-wordpress.php              # 清理 WP 内容与 wptsall 表
│   ├── run-seeding.php                  # 执行 seeding（默认 Plan A full）
│   ├── install-wptsall.php              # 重装并初始化 wptsall
│   └── verify-rule-alignment.php        # 规则-扫描字段对齐门禁（失败即阻断）
│
├── base/                                # 测试基类
│   └── class-rest-integration-test-case.php
│
├── chains/                              # ★ 按链路组织的测试（主要）
│   │
│   │ ─── 核心链路 (P0) ───
│   ├── test-chain-1-model-scanning.php  # Chain 1: 模型扫描
│   ├── test-chain-2-site-relation.php   # Chain 2: 站点关系
│   ├── test-chain-3-content-sync.php    # Chain 3: 内容同步
│   ├── test-chain-4-virtual-routing.php # Chain 4: 虚拟路由
│   ├── test-chain-5-gettext.php         # Chain 5: Gettext 翻译
│   ├── test-chain-6-language-pack.php   # Chain 6: 语言包扫描
│   ├── test-chain-7-hook-generation.php # Chain 7: 钩子生成
│   ├── test-chain-8-id-mapping.php      # Chain 8: ID 映射
│   │
│   │ ─── 功能链路 (P1) ───
│   ├── test-chain-9-sync-preview.php    # Chain 9: 同步预览与冲突
│   ├── test-chain-11-cron-automation.php # Chain 11: Cron 调度自动化
│   │
│   │ ─── 辅助链路 (P2) ───
│   ├── test-chain-12-data-classification.php # Chain 12: 数据分类
│   ├── test-chain-13-custom-model.php   # Chain 13: 自定义模型
│   ├── test-chain-14-field-discovery.php # Chain 14: 字段发现
│   ├── test-chain-15-task-parameters.php # Chain 15: 任务参数
│   ├── test-chain-16-validation.php     # Chain 16: 验证器
│   │
│   │ ─── 补充链路 (P3) ───
│   ├── test-chain-17-import-export.php  # Chain 17: 导入导出
│   ├── test-chain-18-virtual-frontend.php # Chain 18: 虚拟站点前端
│   ├── test-chain-19-admin-pages.php    # Chain 19: 管理页面 UI
│   ├── test-chain-20-rest-api-additional.php # Chain 20: REST API 补充
│   └── test-chain-21-plugin-discovery.php # Chain 21: 插件动态发现
│
├── helpers/                             # 测试辅助类
│   └── class-url-comparison-helper.php
│
└── legacy/                              # 旧测试（已归档）
    ├── models/                          # Models 模块旧测试
    ├── sites/                           # Sites 模块旧测试
    ├── tasks/                           # Tasks 模块旧测试
    ├── hooks/                           # Hooks 模块旧测试
    ├── templates/                       # Templates 模块旧测试
    └── workflow/                        # 工作流旧测试
```

---

## 运行测试

### 当前标准入口（推荐）

```bash
# WP integration 主套件
php wptsall/tests/integration/run.php --suite=main

# 官方三系统 E2E 主链
bash tests/modules/wpmmcc-ats/e2e/run.sh

# 发布前统一入口
bash tests/modules/wpmmcc-ats/e2e/run-release-gate.sh
```

说明：
1. `php wptsall/tests/integration/run.php --suite=main` 是当前 WP integration canonical 入口。
2. 若需要现役跨系统 gate，请直接运行 `bash tests/modules/wpmmcc-ats/e2e/run.sh` 或对应 lane 脚本。
3. 当前仓库不再维护 `.github/workflows/` 触发链；测试归档与证据以本地脚本输出目录为准。

### 手工命令

```bash
php tests/modules/wpmmcc-ats/integration/run.php --suite=all
```

说明：
1. `--suite=all` 会先执行 `bootstrap`，再执行 `contracts + flows + chains`。
2. 规则对齐门禁在 `bootstrap/verify-rule-alignment.php`，若失败会在最终 Summary 中显示失败并返回非 0。

### 运行 Stage-1 三系统回归（官网 mock + WP + 客户端）

```bash
bash tests/modules/wpmmcc-ats/integration/run-stage1.sh
```

说明：
1. 该入口当前是历史兼容包装器。
2. 当前仓库未包含 `stage1` 源脚本目录，因此它会直接报错并提示改走现役 `tests/modules/wpmmcc-ats/e2e/` 本地 lane。

可选参数：

1. `BASE_URL`（默认 `http://127.0.0.1:8787`）
2. `WP_PATH`（默认 `/usr/local/var/www`）
3. `START_SERVER_IF_NEEDED`（默认 `1`，自动拉起 mock server）
4. `RUN_COMPONENT_SELECTION_CASES`（默认 `1`，是否执行组件选择优先级回归）

产物：

1. 运行日志：`tests/modules/wpmmcc-ats/integration/reports/stage1-regression-*.log`
2. 摘要 JSON：`tests/modules/wpmmcc-ats/integration/reports/stage1-regression-*.json`

### 运行特定链路

```bash
# Chain 1: 模型扫描
php tests/modules/wpmmcc-ats/integration/run.php --file=test-chain-1-model-scanning.php

# Chain 9: 同步预览
php tests/modules/wpmmcc-ats/integration/run.php --file=test-chain-9-sync-preview.php

# Chain 17: 导入导出
php tests/modules/wpmmcc-ats/integration/run.php --file=test-chain-17-import-export.php
```

### 按优先级运行

```bash
# 只运行核心链路 (P0)
php tests/modules/wpmmcc-ats/integration/run.php --level=L1
php tests/modules/wpmmcc-ats/integration/run.php --level=L2

# 只运行 flows
php tests/modules/wpmmcc-ats/integration/run.php --suite=flows

# 只运行 contracts
php tests/modules/wpmmcc-ats/integration/run.php --suite=contracts
```

### 包含 legacy 测试

```bash
php tests/modules/wpmmcc-ats/integration/run.php --legacy
```

### 预期输出

```
==========================================
WPTSALL Integration Test Runner
==========================================

✓ WordPress loaded
✓ WPTSALL plugin active
✓ Loaded REST_Integration_Test_Case base class

Found 20 test file(s) (use --legacy to include legacy tests)

Running: test-chain-1-model-scanning.php
----------------------------------------
  ✓ test_model_scanner_exists
  ✓ test_scan_single_plugin
  ✓ test_field_classification
  ...

==========================================
Test Summary
==========================================

Total:   200+
Passed:  195+
Failed:  0
Skipped: 5

✅ All tests passed!
```

---

## 链路概览

| 优先级 | 链路 | 文件 | 说明 |
|--------|------|------|------|
| P0 | Chain 1 | `test-chain-1-model-scanning.php` | 模型扫描与字段分类 |
| P0 | Chain 2 | `test-chain-2-site-relation.php` | 站点关系配置 |
| P0 | Chain 3 | `test-chain-3-content-sync.php` | 内容同步执行 |
| P0 | Chain 4 | `test-chain-4-virtual-routing.php` | 虚拟站点路由 |
| P0 | Chain 5 | `test-chain-5-gettext.php` | Gettext 翻译 |
| P0 | Chain 6 | `test-chain-6-language-pack.php` | 语言包扫描 |
| P0 | Chain 7 | `test-chain-7-hook-generation.php` | 钩子自动生成 |
| P0 | Chain 8 | `test-chain-8-id-mapping.php` | ID 映射服务 |
| P1 | Chain 9 | `test-chain-9-sync-preview.php` | 同步预览与冲突解决 |
| P1 | Chain 11 | `test-chain-11-cron-automation.php` | Cron 调度与自动化 |
| P2 | Chain 12 | `test-chain-12-data-classification.php` | 数据分类与访问验证 |
| P2 | Chain 13 | `test-chain-13-custom-model.php` | 自定义模型服务 |
| P2 | Chain 14 | `test-chain-14-field-discovery.php` | 字段发现服务 |
| P2 | Chain 15 | `test-chain-15-task-parameters.php` | 任务参数与缓存 |
| P2 | Chain 16 | `test-chain-16-validation.php` | 验证器链路 |
| P3 | Chain 17 | `test-chain-17-import-export.php` | 导入导出 |
| P3 | Chain 18 | `test-chain-18-virtual-frontend.php` | 虚拟站点前端路由 |
| P3 | Chain 19 | `test-chain-19-admin-pages.php` | 管理页面 UI |
| P3 | Chain 20 | `test-chain-20-rest-api-additional.php` | REST API 补充测试 |
| P3 | Chain 21 | `test-chain-21-plugin-discovery.php` | 插件动态发现 |

---

## 核心链路详细说明 (P0)

### Chain 1: 模型扫描链路

**文件**: `test-chain-1-model-scanning.php`

**REST 端点**:
- `POST /wptsall/v2/discovery/scan`
- `GET /wptsall/v2/translation-rules`

| 测试方法 | 覆盖点 |
|----------|--------|
| `test_scan_single_plugin` | 单插件扫描 |
| `test_scan_all_plugins` | 批量扫描 |
| `test_field_classification` | 字段分类 (translate/sync/mapping/compute) |
| `test_scan_result_persistence` | 结果持久化 |
| `test_scan_rest_api` | REST API 端点 |

### Chain 2: 站点关系配置链路

**文件**: `test-chain-2-site-relation.php`

**REST 端点**:
- `POST /wptsall/v1/site-relations`
- `GET /wptsall/v1/site-relations`
- `POST /wptsall/v1/site-relations/{id}/models`

| 测试方法 | 覆盖点 |
|----------|--------|
| `test_create_relation_via_rest` | REST 创建关系 |
| `test_five_tuple_uniqueness` | 五元组唯一约束 |
| `test_associate_models` | 模型关联 |
| `test_delete_cascades` | 级联删除 |
| `test_relation_crud_rest` | 完整 CRUD |

### Chain 3: 内容同步执行链路

**文件**: `test-chain-3-content-sync.php`

**REST 端点**:
- `POST /wptsall/v2/tasks/discover`
- `POST /wptsall/v2/tasks/orchestrate`
- `POST /wptsall/v2/tasks/{id}/execute`

| 测试方法 | 覆盖点 |
|----------|--------|
| `test_task_discovery` | 任务发现 |
| `test_orchestrate_tasks` | 任务编排 |
| `test_execute_single_task` | 单任务执行 |
| `test_sync_post_content` | 文章同步 |
| `test_virtual_content_storage` | 虚拟站点存储 |

### Chain 4: 虚拟站点路由链路

**文件**: `test-chain-4-virtual-routing.php`

**REST 端点**:
- `POST /wptsall/v1/virtual-sites`
- `GET /wptsall/v1/virtual-sites`
- `GET /wptsall/v1/virtual-sites/check-url`

| 测试方法 | 覆盖点 |
|----------|--------|
| `test_create_virtual_site` | 创建虚拟站点 |
| `test_url_path_routing` | URL 路由 |
| `test_content_resolution` | 内容解析 |
| `test_url_conflict_detection` | 冲突检测 |

### Chain 5: Gettext 翻译链路

**文件**: `test-chain-5-gettext.php`

**REST 端点**:
- `POST /wptsall/v2/tasks/scan-language-pack`
- `POST /wptsall/v2/tasks/translate-language-pack`

| 测试方法 | 覆盖点 |
|----------|--------|
| `test_gettext_filter_registration` | 过滤器注册 |
| `test_string_translation` | 字符串翻译 |
| `test_context_translation` | 上下文翻译 |
| `test_virtual_site_locale` | 虚拟站点语言 |

### Chain 6: 语言包扫描链路

**文件**: `test-chain-6-language-pack.php`

**REST 端点**:
- `POST /wptsall/v2/templates/scan`
- `GET /wptsall/v2/templates`
- `GET /wptsall/v2/templates/{id}/entries`

| 测试方法 | 覆盖点 |
|----------|--------|
| `test_pot_file_discovery` | POT 发现 |
| `test_pot_parsing` | 条目提取 |
| `test_template_creation` | 模板创建 |
| `test_templates_rest_api` | REST API |

### Chain 7: 钩子自动生成链路

**文件**: `test-chain-7-hook-generation.php`

**服务层**: `Hook_Manager::generate_auto_hooks()`

| 测试方法 | 覆盖点 |
|----------|--------|
| `test_generate_auto_hooks` | 自动生成 |
| `test_post_type_hooks` | 文章类型钩子 |
| `test_hook_deduplication` | 去重 |
| `test_hook_execution` | 钩子执行 |

### Chain 8: ID 映射链路

**文件**: `test-chain-8-id-mapping.php`

**REST 端点**:
- `GET /wptsall/v1/user-mappings`
- `POST /wptsall/v1/user-mappings`

**服务层**:
- `Post_Mapping_Service`
- `Media_Mapping_Service`
- `Term_Mapping_Service`
- `User_Mapping_Service`

| 测试方法 | 覆盖点 |
|----------|--------|
| `test_post_mapping` | 文章映射 |
| `test_media_mapping` | 媒体映射 |
| `test_term_mapping` | 分类映射 |
| `test_user_mapping_rest` | 用户映射 REST |

---

## 功能链路详细说明 (P1)

### Chain 9: 同步预览与冲突解决

**文件**: `test-chain-9-sync-preview.php`

**核心类**:
- `Tasks\Sync\Preview_Handler`
- `Tasks\Sync\Conflict_Resolver`

| 测试方法 | 覆盖点 |
|----------|--------|
| `test_generate_preview` | 预览生成 |
| `test_conflict_detection` | 冲突检测 |
| `test_conflict_resolution_strategies` | 解决策略 (source_wins, target_wins, etc.) |
| `test_preview_comparison` | 源/目标对比 |
| `test_rollback_mechanism` | 回滚机制 |

### Chain 11: Cron 调度与自动化

**文件**: `test-chain-11-cron-automation.php`

**核心类**: `Tasks\Automation\Cron_Scheduler`

| 测试方法 | 覆盖点 |
|----------|--------|
| `test_cron_schedule_registration` | 调度注册 |
| `test_task_queue_processing` | 队列处理 |
| `test_priority_processing` | 优先级处理 |
| `test_cleanup_cron` | 清理任务 |

---

## 辅助链路详细说明 (P2)

### Chain 12: 数据分类与访问验证

**文件**: `test-chain-12-data-classification.php`

**核心类**:
- `Core\Classifiers\Sync_Access_Validator`
- `Core\Classifiers\Data_Classifier`
- `Core\Classifiers\URL_Classifier`

| 测试方法 | 覆盖点 |
|----------|--------|
| `test_pii_detection` | PII 检测 (邮箱/电话/身份证) |
| `test_url_classification` | URL 分类 (管理/公开/受保护) |
| `test_field_classification_four_types` | 四类字段分类 |
| `test_privacy_level_assessment` | 隐私级别评估 |

### Chain 13: 自定义模型服务

**文件**: `test-chain-13-custom-model.php`

**核心类**: `Models\Services\Custom_Model_Service`

| 测试方法 | 覆盖点 |
|----------|--------|
| `test_discover_plugins_without_models` | 发现未注册插件 |
| `test_create_custom_model` | 创建自定义模型 |
| `test_set_field_overrides` | 设置字段覆盖 |
| `test_url_parsing_link_chain` | URL 解析链 |

### Chain 14: 字段发现服务

**文件**: `test-chain-14-field-discovery.php`

**核心类**: `Models\Services\Field_Discovery_Service`

| 测试方法 | 覆盖点 |
|----------|--------|
| `test_get_all_tables` | 获取数据库表 |
| `test_get_table_columns` | 获取表列信息 |
| `test_get_post_meta_keys` | 获取 post meta keys |
| `test_filter_hidden_meta_keys` | 过滤隐藏 keys |

### Chain 15: 任务参数与缓存配置

**文件**: `test-chain-15-task-parameters.php`

**函数**:
- `wptsall_task_parameters()`
- `wptsall_get_cache_ttl()`

| 测试方法 | 覆盖点 |
|----------|--------|
| `test_task_parameters_structure` | 参数结构 |
| `test_cache_ttl_types` | 缓存 TTL 类型 |
| `test_automation_interval_setting` | 自动化间隔 |
| `test_parameters_persistence` | 参数持久化 |

### Chain 16: 验证器链路

**文件**: `test-chain-16-validation.php`

**核心类**:
- `Models\Validators\Translation_Rule_Validator`
- `Sites\Validators\Site_Relation_Validator`
- `Sites\Services\Relation_Config_Service`

| 测试方法 | 覆盖点 |
|----------|--------|
| `test_five_tuple_uniqueness_constraint` | 五元组唯一性 |
| `test_field_capabilities_structure_validation` | 字段能力验证 |
| `test_user_mapping_strategy_validation` | 映射策略验证 |
| `test_circular_relation_detection` | 循环关系检测 |

---

## 补充链路详细说明 (P3)

### Chain 17: 导入导出

**文件**: `test-chain-17-import-export.php`

| 测试方法 | 覆盖点 |
|----------|--------|
| `test_export_templates_to_json` | 模板导出 |
| `test_import_templates_from_json` | 模板导入 |
| `test_export_import_roundtrip_integrity` | 往返完整性 |
| `test_version_compatibility` | 版本兼容 |

### Chain 18: 虚拟站点前端路由

**文件**: `test-chain-18-virtual-frontend.php`

**核心类**: `Hooks\Virtual_Site_Router`

| 测试方法 | 覆盖点 |
|----------|--------|
| `test_parse_virtual_site_path` | 路径解析 |
| `test_resolve_virtual_site_content` | 内容解析 |
| `test_virtual_site_locale_setting` | 语言设置 |
| `test_nonexistent_virtual_site_path` | 404 处理 |

### Chain 19: 管理页面 UI

**文件**: `test-chain-19-admin-pages.php`

**核心类**:
- `Admin\Initialization_Page`
- `Models\Admin\Model_Editor_Page`
- `Sites\Admin\Sites_Page`

| 测试方法 | 覆盖点 |
|----------|--------|
| `test_admin_menu_registered` | 菜单注册 |
| `test_admin_scripts_registered` | 脚本入队 |
| `test_rest_config_localized` | 数据本地化 |
| `test_list_pagination` | 列表分页 |

### Chain 20: REST API 补充测试

**文件**: `test-chain-20-rest-api-additional.php`

| 测试方法 | 覆盖点 |
|----------|--------|
| `test_get_overall_stats` | 统计端点 |
| `test_health_check_endpoint` | 健康检查 |
| `test_bulk_operations` | 批量操作 |
| `test_error_format` | 错误格式 |

### Chain 21: 插件动态发现

**文件**: `test-chain-21-plugin-discovery.php`

**核心类**:
- `Models\Services\Plugin_Discovery_Service`
- `Models\Scanners\Model_Scanner_V2`

| 测试方法 | 覆盖点 |
|----------|--------|
| `test_get_active_plugins` | 获取激活插件 |
| `test_get_all_post_types` | 获取 post_type |
| `test_get_all_taxonomies` | 获取 taxonomy |
| `test_scan_result_persistence` | 扫描持久化 |

---

## 基类说明

### REST_Integration_Test_Case

位于 `base/class-rest-integration-test-case.php`，提供 REST API 测试支持：

```php
class My_Test extends REST_Integration_Test_Case {

    public function test_example() {
        // REST 请求
        $response = $this->rest_get( 'virtual-sites' );
        $response = $this->rest_post( 'site-relations', array( ... ) );
        $response = $this->rest_put( "virtual-sites/{$id}", array( ... ) );
        $response = $this->rest_delete( "site-relations/{$id}" );

        // 断言
        $this->assertRestSuccess( $response, 200 );
        $this->assertRestError( $response, 'not_found', 404 );

        // 获取响应数据
        $data = $this->get_response_data( $response );

        // 创建测试数据（自动跟踪清理）
        $post_id = $this->create_test_post( array( ... ) );
        $vs_id = $this->create_test_virtual_site( array( ... ) );
        $relation = $this->create_test_relation( $vs_id );
    }
}
```

### 资源跟踪

基类自动跟踪创建的资源，在 `tearDown()` 中清理：

```php
// 手动跟踪资源
$this->track_resource( 'posts', $post_id );
$this->track_resource( 'virtual_sites', $vs_id );
$this->track_resource( 'site_relations', $relation_id );
$this->track_resource( 'tasks', $task_id );
```

---

## 环境要求

| 要求 | 说明 |
|------|------|
| WordPress | 需要运行中的 WordPress 站点 |
| WPTSALL 插件 | 必须已激活 |
| 管理员权限 | REST API 需要管理员权限 |

### 检查环境

```bash
cd /usr/local/var/www

# 检查插件状态
wp plugin list | grep wptsall

# 检查数据库表
wp db query "SHOW TABLES LIKE 'wp_wptsall%'"

# 检查 REST API
curl -s http://localhost/wp-json/wptsall/v1/ | jq .
```

---

## 故障排除

### 测试被跳过

部分测试需要特定条件：
- 测试数据存在
- 特定类/方法存在
- REST API 端点可用

### 站点关系测试失败

清理测试数据后重试：
```bash
wp db query "DELETE FROM wp_wptsall_site_relations WHERE template LIKE 'test_%';"
```

### REST API 权限错误

确保测试以管理员身份运行：
```php
// 基类自动设置管理员用户
$this->admin_user_id = $this->factory->user->create(['role' => 'administrator']);
wp_set_current_user( $this->admin_user_id );
```

---

## 验收标准

| 指标 | 标准 |
|------|------|
| 链路覆盖 | 20 条链路都有测试 |
| 测试方法 | 每条链路至少 5 个测试 |
| REST API | 核心测试使用 REST API |
| 数据清理 | 测试数据自动清理 |
| 通过率 | ≥ 95% |

---

**链路测试数**: 20 | **测试方法数**: 200+ | **通过标准**: ≥95%

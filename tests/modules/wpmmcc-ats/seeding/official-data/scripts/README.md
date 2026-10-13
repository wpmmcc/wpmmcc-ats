# 数据填充脚本使用说明

本目录包含用于内容插件数据填充的自动化脚本。

> **注意**：本工作流仅用于填充内容插件的测试数据（产品、论坛、课程等），与 WPTSALL 翻译插件无关。WPTSALL 作为公共插件保持启用即可。

## 📁 脚本列表

| 脚本 | 用途 | 示例 |
|------|------|------|
| `install-plan.sh` | 安装并激活指定计划的所有插件 | `bash install-plan.sh A` |
| `uninstall-plan.sh` | 卸载指定计划的所有插件（触发 uninstall.php） | `bash uninstall-plan.sh A` |
| `activate-plan.sh` | 激活指定计划的插件（插件已安装） | `bash activate-plan.sh A` |
| `deactivate-plan.sh` | 停用指定计划的所有插件 | `bash deactivate-plan.sh A` |
| `import-by-plan.sh` | 导入指定计划的官方数据 | `bash import-by-plan.sh A` |
| `full-test-cycle.sh` | 完整测试周期（A → B → C → D） | `bash full-test-cycle.sh` |

---

## 🚀 快速开始

### 场景 1: 首次测试 Plan A（推荐）

```bash
cd /usr/local/var/www

# 一键运行完整工作流（Pre-Setup → Seed → Post-Setup → Verify）
wp eval-file /Users/zhangxiao/wptsall-dev/dev-tools/seeding/run-plan.php A full
```

**输出结果**: `plans/A/results/verify-result.json`

### 场景 1b: 首次测试（需要安装插件）

```bash
cd /Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data/scripts

# 1. 安装并激活插件
bash install-plan.sh A

# 2. 执行完整工作流
cd /usr/local/var/www
wp eval-file /Users/zhangxiao/wptsall-dev/dev-tools/seeding/run-plan.php A full
```

### 场景 2: 从 Plan A 切换到 Plan B

```bash
cd /Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data/scripts

# 1. 停用 Plan A
bash deactivate-plan.sh A

# 2. 卸载 Plan B（确保干净安装）
bash uninstall-plan.sh B

# 3. 安装 Plan B
bash install-plan.sh B

# 4. 执行工作流
cd /usr/local/var/www
wp eval-file /Users/zhangxiao/wptsall-dev/dev-tools/seeding/run-plan.php B
```

### 场景 3: 快速切换（插件已验证）

```bash
cd /Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data/scripts

# 1. 停用旧计划
bash deactivate-plan.sh A

# 2. 激活新计划（不重装）
bash activate-plan.sh B

# 3. 运行验证
cd /usr/local/var/www
wp eval-file /Users/zhangxiao/wptsall-dev/dev-tools/seeding/run-plan.php B verify
```

### 场景 4: 完整测试周期

```bash
cd /Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data/scripts

# 完整测试（卸载重装）
bash full-test-cycle.sh

# 快速测试（仅激活切换）
bash full-test-cycle.sh --quick
```

---

## 🔄 工作流阶段

完整的数据填充工作流包含以下阶段：

| 阶段 | 说明 | 脚本位置 | 执行时机 |
|------|------|----------|----------|
| **Pre-Setup** | 前置配置（如 LMS URL 冲突解决） | `pre-setup/dispatcher.php` | 插件激活后、数据导入前 |
| **Import** | 导入官方数据 | `import-by-plan.sh` | Pre-Setup 后 |
| **Seed** | 填充自定义数据 (Phase 1-3) | `seed-content.php` | Import 后 |
| **Post-Setup** | 后置配置（如页面创建） | `post-setup/dispatcher.php` | Seed 后 |
| **Verify** | 验证测试（前后台 URL 检查） | `verify/dispatcher.php` | 所有填充完成后 |

### Pre-Setup 脚本

| 脚本 | 用途 |
|------|------|
| `lms-url-conflict-resolver.php` | 解决多个 LMS 插件 URL slug 冲突 |
| `mu-plugin-lms-url-fixer.php` | mu-plugin，修改 post_type rewrite 参数 |

### Post-Setup 脚本

| 脚本 | 插件 | 用途 |
|------|------|------|
| `woocommerce-post-setup.php` | WooCommerce | 配置 Shop/Cart/Checkout/My Account 页面 |
| `masterstudy-post-setup.php` | MasterStudy LMS | 创建课程归档页和仪表盘页面 |
| `cooked-post-setup.php` | Cooked | 创建食谱浏览页面 |
| `classified-listing-post-setup.php` | Classified Listing | 配置页面和性能优化 |

### Verify 脚本

每个插件有独立的验证脚本，检查：
- 内容数量（post_type 条目数）
- 前台 URL（归档页、单页、分类页）
- 后台 URL（管理页面、新建页面）

### 一体化工作流

使用 `run-plan.php` 可以一次执行所有阶段：

```bash
# 完整工作流
wp eval-file /Users/zhangxiao/wptsall-dev/dev-tools/seeding/run-plan.php A

# 只执行特定阶段
wp eval-file /Users/zhangxiao/wptsall-dev/dev-tools/seeding/run-plan.php A pre      # Pre-Setup
wp eval-file /Users/zhangxiao/wptsall-dev/dev-tools/seeding/run-plan.php A seed     # Seed
wp eval-file /Users/zhangxiao/wptsall-dev/dev-tools/seeding/run-plan.php A post     # Post-Setup
wp eval-file /Users/zhangxiao/wptsall-dev/dev-tools/seeding/run-plan.php A verify   # Verify
```

---

## 📝 脚本详细说明

### 1. install-plan.sh

**用途**: 安装并激活指定计划的所有插件

**参数**:
- `<A|B|C|D>` - 计划名称

**示例**:
```bash
bash install-plan.sh A    # 安装 Plan A 的 20 个插件
```

---

### 2. uninstall-plan.sh

**用途**: 卸载指定计划的所有插件，触发 `uninstall.php` 完全清理数据

**参数**:
- `<A|B|C|D>` - 计划名称

**示例**:
```bash
bash uninstall-plan.sh A    # 卸载 Plan A 的 20 个插件
```

**清理内容**:
- 插件的自定义表（如 `wp_woocommerce_*`）
- 插件的 postmeta 数据
- 插件的 options 配置
- 插件的其他自定义数据

**注意**: 插件文件不会删除（使用 `--skip-delete`），可以重新安装

---

### 3. activate-plan.sh

**用途**: 激活指定计划的插件（插件已安装，快速激活）

**参数**:
- `<A|B|C|D>` - 计划名称

**示例**:
```bash
bash activate-plan.sh A    # 激活 Plan A 的 20 个插件
```

**适用场景**: 插件已验证正确，后续测试只需激活不同计划

---

### 4. deactivate-plan.sh

**用途**: 停用指定计划的所有插件（不卸载，保留数据）

**参数**:
- `<A|B|C|D>` - 计划名称

**示例**:
```bash
bash deactivate-plan.sh A    # 停用 Plan A 的 20 个插件
```

---

### 5. import-by-plan.sh

**用途**: 导入指定计划的官方数据

**参数**:
- `<A|B|C|D>` - 计划名称

**示例**:
```bash
bash import-by-plan.sh A    # 导入 Plan A 的官方数据（6个插件）
```

**Plan A 官方数据**:
- WooCommerce (CSV)
- bbPress (XML)
- BuddyPress (BP Default Data 插件)
- Easy Digital Downloads (XML)
- Academy LMS (Starter Templates)
- MasterStudy LMS (Demo Import)

**Plan B 官方数据**:
- Tutor LMS (XML)

**Plan C 官方数据**:
- Sensei LMS (XML)

**Plan D 官方数据**:
- LearnPress (XML)
- LifterLMS (JSON)

---

### 6. full-test-cycle.sh

**用途**: 完整测试周期，依次测试 Plan A → B → C → D

**参数**:
- `[--quick]` - 可选，快速模式（不卸载重装）

**示例**:
```bash
# 完整测试（卸载重装）
bash full-test-cycle.sh

# 快速测试（仅激活切换）
bash full-test-cycle.sh --quick
```

**流程**:
1. 检查 WordPress Core 数据（如无则填充）
2. 测试 Plan A
3. 切换到 Plan B
4. 切换到 Plan C
5. 切换到 Plan D
6. 输出统计信息

---

## 🎯 插件计划分布

### Plan A (20个插件)
- **Content**: woocommerce, bbpress, academy, masterstudy-lms, easy-digital-downloads, classified-listing, easy-property-listings, cooked, envira-gallery-lite, site-reviews, tablepress, testimonial-free
- **Utility**: wordpress-seo, advanced-custom-fields, contact-form-7, buddypress, easy-appointments, simply-schedule-appointments, powerpress, redirection
- **官方数据**: 6个 (30%)

### Plan B (18个插件)
- **Content**: ecwid-shopping-cart, directorist, the-events-calendar, wp-job-manager, tutor, essential-real-estate, asgaros-forum, delicious-recipes, foogallery, reviews-feed, portfolio-post-type
- **Utility**: all-in-one-seo-pack, meta-box, ninja-forms, paid-member-subscriptions, booking, fluent-booking, seriously-simple-podcasting
- **官方数据**: 1个 (6%)

### Plan C (13个插件)
- **Content**: wp-easycart, wpforo, sensei-lms, estatik, wp-recipe-maker, simple-job-board, ultimate-faqs, wp-customer-reviews
- **Utility**: seo-by-rank-math, pods, restrict-content, bookly-responsive-appointment-booking-tool, podcast-player
- **官方数据**: 1个 (8%)

### Plan D (13个插件)
- **Content**: storeengine, learnpress, lifterlms, hivepress, geodirectory, forumwp, propertyhive, events-manager, wp-job-openings, give
- **Utility**: simple-membership, ameliabooking, podlove-podcasting-plugin-for-wordpress
- **官方数据**: 2个 (15%)

---

## ⚠️ 注意事项

1. **WordPress Core 数据**：只填充一次，所有计划共享
2. **卸载重装**：首次测试使用卸载重装，确保数据干净
3. **快速切换**：验证正确后，可使用 activate/deactivate 快速切换
4. **公共插件**：WPTSALL、ACF 等公共插件保持启用，不参与计划切换

---

## 📊 时间估算

| 操作 | 首次测试 | 快速切换 |
|------|----------|----------|
| 卸载插件 | 2 分钟 | - |
| 安装插件 | 2 分钟 | - |
| 激活插件 | - | 10 秒 |
| 停用插件 | 10 秒 | 10 秒 |
| 导入官方数据 | 3 分钟 | - |
| 填充自定义数据 | 2 分钟 | 2 分钟 |
| 运行验证 | 1 分钟 | 1 分钟 |
| **单计划总计** | **~10 分钟** | **~3 分钟** |
| **4 计划总计** | **~40 分钟** | **~12 分钟** |

---

## 🔗 相关文档

- [TESTING-WORKFLOW.md](../TESTING-WORKFLOW.md) - 完整测试工作流文档
- [PLAN-DISTRIBUTION.md](../PLAN-DISTRIBUTION.md) - 计划分布详情
- [README.md](../README.md) - 官方数据使用指南

---

**最后更新**: 2026-01-27
**维护**: WPTSALL 开发团队

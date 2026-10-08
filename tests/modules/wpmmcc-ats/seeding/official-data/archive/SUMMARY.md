# 官方数据整理汇总

**整理日期**: 2026-01-26
**整理内容**: 从 87 个已安装插件中提取带有官方数据支持的插件

---

## 📊 整理结果

### ✅ 本地已整理的插件 (6个)

**位置**: 已复制到 `official-data/` 目录

| 插件 | 数据文件数 | 总大小 | 数据格式 | 导入方式 |
|------|-----------|--------|---------|---------|
| **WordPress Core** | 1 | 595 KB | XML | wp import |
| **WooCommerce** | 5 | 215 KB | CSV/XML | wp wc / wp import |
| **Easy Digital Downloads** | 1 | 81 KB | XML | wp import |
| **LearnPress** | 4 | 213 KB | XML | wp import |
| **LifterLMS** | 1 | 56 KB | JSON | 后台手动导入 |
| **Sensei LMS** | 2 | 145 KB | CSV | 后台手动导入 |
| **小计** | **14** | **~1.3 MB** | - | - |

### 🌐 互联网可下载的插件 (4个)

**位置**: 需从官方网站/GitHub 下载到 `downloads/` 目录

| 插件 | 数据来源 | 数据格式 | 导入方式 | 状态 |
|------|----------|---------|---------|------|
| **bbPress** | [官方 Codex](https://codex.bbpress.org/) | XML | wp import | ✅ 可用 |
| **BuddyPress** | [BP Default Data 插件](https://wordpress.org/plugins/bp-default-data/) | 插件生成 | 后台操作 | ✅ 已安装 |
| **Tutor LMS** | [官方文档](https://docs.themeum.com/tutor-lms/) | XML | wp import | ✅ 可用 |
| **Academy LMS** | [Starter Templates 插件](https://wordpress.org/plugins/academy-starter-templates/) | 模板导入 | 后台操作 | ✅ 完全免费 |

### 📦 GitHub 集合资源

**[maheshwaghmare/sample-data](https://github.com/maheshwaghmare/sample-data)** - 一键下载多个插件示例数据

包含内容：
- ✅ WordPress Core (Theme Unit Test)
- ✅ WooCommerce
- ✅ bbPress
- ✅ 其他主题示例数据

### ❌ 无官方数据的插件 (73个)

大部分插件不提供官方示例数据，需要使用自定义填充系统。

### 📈 总体统计

| 类型 | 数量 | 覆盖率 | 说明 |
|------|------|--------|------|
| 本地已有 | 6 | 7% | 插件自带文件 |
| 互联网下载 | 4 | 4% | 官方文档/插件生成 |
| **可用总计** | **10** | **11%** | 有明确的获取方式 |
| 需自定义填充 | 77 | 89% | 使用 seed-content.php 或手动创建 |

---

## 📁 目录结构

```
official-data/
├── README.md                              # 详细使用文档
├── SUMMARY.md                             # 本汇总文件
├── import-all.sh                          # 一键导入脚本
├── update-official-data.sh                # 更新数据脚本（含下载功能）
│
├── wordpress-core/                        # WordPress 核心 (595 KB)
│   └── themeunittestdata.wordpress.xml
│
├── woocommerce/                           # WooCommerce (215 KB)
│   ├── sample_products.csv                (18 KB, 15 products)
│   ├── sample_products.xml                (184 KB, 15 products)
│   ├── sample_tax_rates.csv               (266 B)
│   ├── experimental_sample_9_products.csv (6.2 KB)
│   └── experimental_fashion_sample_9_products.csv (6.1 KB)
│
├── easy-digital-downloads/                # EDD (81 KB)
│   └── sample-products-import.xml
│
├── learnpress/                            # LearnPress (213 KB)
│   ├── sample-data.xml                    (57 KB, 完整示例)
│   ├── dummy-data.xml                     (75 KB, 测试数据)
│   ├── learnpress-how-to-use-learnpress.xml (57 KB, 使用教程)
│   └── dummy-text.txt                     (24 KB, 文本素材)
│
├── lifterlms/                             # LifterLMS (56 KB)
│   └── sample-course.json                 (需后台导入)
│
├── sensei-lms/                            # Sensei LMS (145 KB)
│   ├── courses.csv                        (2.3 KB)
│   └── lessons.csv                        (143 KB)
│
├── downloads/                             # 互联网下载数据存放区
│   ├── bbpress/                           # bbPress 测试数据
│   │   └── bbpress-sample-data.xml        (需从 Codex 下载)
│   ├── tutor-lms/                         # Tutor LMS 示例课程
│   │   └── tutor-demo-data.xml            (需从官方文档下载)
│   ├── academy-lms/                       # Academy LMS
│   │   └── README.md                      (使用 Starter Templates 扩展)
│   └── github-collections/                # GitHub 集合资源
│       └── sample-data/                   (maheshwaghmare/sample-data)
│
└── buddypress/                            # BuddyPress (使用插件生成)
    └── README.md                          (说明如何使用 BP Default Data 插件)
```

---

## 🚀 快速使用

### 方式 1: 一键导入所有数据

```bash
cd /Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data
bash import-all.sh
```

### 方式 2: 选择性导入

```bash
# 仅导入 WordPress Core 和 WooCommerce
bash import-all.sh wordpress-core woocommerce

# 仅导入 LMS 插件数据
bash import-all.sh learnpress lifterlms sensei-lms
```

### 方式 3: 手动导入单个插件

```bash
cd /usr/local/var/www

# WordPress Core
wp import /Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data/wordpress-core/themeunittestdata.wordpress.xml --authors=create

# WooCommerce
wp wc product_csv import /Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data/woocommerce/sample_products.csv --user=1

# LearnPress
wp import /Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data/learnpress/sample-data.xml --authors=create
```

---

## 🔄 数据更新

当插件版本更新后，重新同步官方数据：

```bash
cd /Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data
bash update-official-data.sh
```

---

## 📊 数据质量评估

### 本地已有数据

| 插件 | 数据量 | 数据质量 | 中文支持 | 推荐使用 |
|------|--------|---------|---------|---------|
| WordPress Core | 30+ posts | ⭐⭐⭐⭐⭐ | ❌ 英文 | ✅ 基础测试 |
| WooCommerce | 15 products | ⭐⭐⭐⭐ | ❌ 英文 | ✅ 快速演示 |
| Easy Digital Downloads | 10+ downloads | ⭐⭐⭐ | ❌ 英文 | ✅ 功能测试 |
| LearnPress | 10 courses | ⭐⭐⭐⭐ | ❌ 英文 | ✅ 结构完整 |
| LifterLMS | 1 course | ⭐⭐⭐ | ❌ 英文 | ⚠️ 数据量少 |
| Sensei LMS | 5 courses | ⭐⭐⭐ | ❌ 英文 | ⚠️ 仅 CSV |

### 互联网可下载数据

| 插件 | 数据量 | 数据质量 | 中文支持 | 推荐使用 |
|------|--------|---------|---------|---------|
| bbPress | 17 forums | ⭐⭐⭐⭐ | ❌ 英文 | ✅ 论坛测试 |
| BuddyPress | 自动生成 | ⭐⭐⭐⭐ | ❌ 英文 | ✅ 社区测试 |
| Tutor LMS | 5+ courses | ⭐⭐⭐⭐ | ❌ 英文 | ✅ LMS 测试 |
| Academy LMS | 完整站点 | ⭐⭐⭐⭐ | ❌ 英文 | ✅ LMS 测试 |

**总体评价**:
- ✅ **优点**: 真实、结构完整、一键导入、维护成本低
- ❌ **缺点**: 英文为主、数据量少（仅10-30条）、覆盖率低（11%）
- ✨ **改进**: 通过互联网资源，覆盖率从 7% 提升到 11%

---

## 🎯 使用建议

### 场景 1: 初次搭建环境

**推荐**: 使用官方数据快速填充

```bash
# 导入所有官方数据
bash import-all.sh

# 验证数据
wp post list --post_type=post,product,download,lp_course --format=count
```

### 场景 2: 自动化测试

**推荐**: 使用自定义填充系统 (`seed-content.php`)

```bash
# 填充大量中文测试数据
cd /usr/local/var/www
wp eval-file /Users/zhangxiao/wptsall-dev/tests/workflow/seed-content.php A
```

### 场景 3: 压力测试

**推荐**: 结合两者使用

```bash
# 1. 导入官方数据作为基准
bash official-data/import-all.sh

# 2. 使用自定义填充批量增加数据
wp eval-file tests/workflow/seed-content.php A
```

---

## 📝 与其他填充方式对比

| 方式 | 覆盖率 | 中文支持 | 数据量 | 自动化 | 推荐场景 |
|------|--------|---------|--------|--------|----------|
| **官方数据（本地）** | 7% (6/87) | ❌ | 10-30 | ✅ | 快速演示 |
| **官方数据（含互联网）** | 11% (10/87) | ❌ | 10-50 | ✅ | 完整演示 |
| **自定义填充** | 35% (30+/87) | ✅ | 自定义 | ✅ | 自动化测试 |
| **FakerPress** | 80% | ⚠️ 部分 | 大量 | ⚠️ 半自动 | 压力测试 |
| **手动创建** | 100% | ✅ | 少量 | ❌ | 特定测试 |

---

## 🔍 发现过程

### 搜索方法

#### 1. 本地插件目录搜索

```bash
# 搜索包含 sample/demo/dummy 关键词的目录或文件
find /usr/local/var/www/wp-content/plugins -name "*sample*" -o -name "*demo*"

# 检查特定插件的示例数据目录
ls -la /usr/local/var/www/wp-content/plugins/{plugin}/sample-data/
ls -la /usr/local/var/www/wp-content/plugins/{plugin}/dummy-data/
```

#### 2. 插件官方资源

- **WordPress.org 插件页面**: 查看 Screenshots, FAQ, Description 部分
- **插件官方网站**: 搜索 "demo data", "sample content", "import"
- **官方文档站点**: `{plugin}.com/documentation` 或 `docs.{plugin}.com`

#### 3. GitHub 仓库

```bash
# 搜索插件的 GitHub 仓库
github.com/{organization}/{plugin}

# 查看常见目录
- sample-data/
- examples/
- demo/
- docs/
```

#### 4. 互联网搜索

```
Google: "{plugin-name} demo data download"
Google: "{plugin-name} sample content import"
Google: "{plugin-name} test data XML"
```

#### 5. 社区和集合资源

- GitHub Topic: `wordpress sample data`
- [maheshwaghmare/sample-data](https://github.com/maheshwaghmare/sample-data) - 多插件集合
- WordPress 支持论坛
- Stack Overflow

### 统计数据

- **检查插件总数**: 87 个
- **本地有官方数据**: 6 个 (7%)
- **互联网可下载**: 4 个 (4%)
- **可用总计**: 10 个 (11%)
- **无官方数据**: 77 个 (89%)

---

## 📚 相关资源

### 官方数据下载链接

#### 本地已有（插件自带）

| 插件 | 位置 |
|------|------|
| WordPress Core | `wordpress-core/themeunittestdata.wordpress.xml` |
| WooCommerce | `woocommerce/*.csv` + `woocommerce/*.xml` |
| Easy Digital Downloads | `easy-digital-downloads/sample-products-import.xml` |
| LearnPress | `learnpress/*.xml` |
| LifterLMS | `lifterlms/sample-course.json` |
| Sensei LMS | `sensei-lms/*.csv` |

#### 互联网下载

| 插件 | 下载链接 | 说明 |
|------|---------|------|
| **bbPress** | [官方 Codex](https://codex.bbpress.org/getting-started/testing-your-bbpress-installation/creating-test-data/) | 测试数据 XML，17 个论坛 |
| **BuddyPress** | [BP Default Data 插件](https://wordpress.org/plugins/bp-default-data/) | 自动生成用户、消息、群组 |
| **Tutor LMS** | [官方文档](https://docs.themeum.com/tutor-lms/tutorials/importing-tutor-demo-data/) | 示例课程 XML |
| **Academy LMS** | [Starter Templates](https://academylms.net/academy-starter-templates/) | 一键导入扩展（需注册） |

#### GitHub 集合资源

| 资源 | 链接 | 包含内容 |
|------|------|----------|
| **Sample Data 集合** | [maheshwaghmare/sample-data](https://github.com/maheshwaghmare/sample-data) | WordPress Core, WooCommerce, bbPress 等 |
| **LifterLMS GitHub** | [gocodebox/lifterlms](https://github.com/gocodebox/lifterlms) | 插件源码和示例 |
| **WordPress 官方** | [WordPress/WordPress](https://github.com/WordPress) | 核心代码和资源 |

### 文档链接

- [README.md](./README.md) - 详细使用文档
- [import-all.sh](./import-all.sh) - 导入脚本源码
- [update-official-data.sh](./update-official-data.sh) - 更新脚本源码（含下载功能）

---

## 🎉 下一步

### 待完成任务

1. **下载互联网资源**
   ```bash
   # 运行更新脚本（含下载功能）
   bash update-official-data.sh --download
   ```

2. **创建 downloads/ 目录结构**
   ```bash
   mkdir -p downloads/{bbpress,tutor-lms,academy-lms,github-collections}
   ```

3. **测试导入流程**
   - 验证本地 6 个插件的导入脚本
   - 测试 bbPress、Tutor LMS 的导入
   - 验证 BuddyPress Default Data 插件

4. **集成到工作流**
   - 将官方数据导入整合到 `tests/workflow/`
   - 创建组合填充方案（官方数据 + 自定义脚本）

5. **监控更新**
   - 定期运行 `update-official-data.sh` 同步本地插件数据
   - 关注插件版本更新，及时下载新的示例数据

### 改进方向

- 探索更多插件的官方数据资源
- 为常用插件创建中文示例数据模板
- 优化下载和导入的自动化流程

---

**维护**: WPTSALL 开发团队
**最后更新**: 2026-01-26
**覆盖率**: 11% (10/87 插件)

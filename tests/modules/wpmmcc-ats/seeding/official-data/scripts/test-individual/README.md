# 官方数据单独测试

逐个测试有官方数据的插件，验证数据是否可用。

## 测试列表

| 插件 | 计划 | 脚本 | 导入方式 |
|------|------|------|---------|
| WooCommerce | A | `test-woocommerce.sh` | 自动 (CSV) |
| bbPress | A | `test-bbpress.sh` | 自动 (XML) |
| Easy Digital Downloads | A | `test-edd.sh` | 自动 (XML) |
| Academy LMS | A | - | 手动 (后台向导) |
| MasterStudy LMS | A | - | 手动 (后台向导) |
| BuddyPress | A | - | 手动 (bp-default-data) |
| Tutor LMS | B | `test-tutor.sh` | 自动 (XML) |
| Sensei LMS | C | `test-sensei.sh` | 手动 (后台 CSV) |
| LearnPress | D | `test-learnpress.sh` | 半自动 |
| LifterLMS | D | `test-lifterlms.sh` | 手动 (JSON) |

## 使用方法

### 运行单个测试

```bash
cd /Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data/scripts/test-individual

# 测试 WooCommerce
bash test-woocommerce.sh

# 测试 bbPress
bash test-bbpress.sh

# 测试 Tutor LMS
bash test-tutor.sh
```

### 运行指定计划的测试

```bash
# 测试 Plan A 所有插件
bash run-all-tests.sh A

# 测试 Plan B
bash run-all-tests.sh B

# 测试 Plan D
bash run-all-tests.sh D
```

### 运行所有测试

```bash
bash run-all-tests.sh all
# 或
bash run-all-tests.sh
```

## 测试前准备

1. **激活对应插件**
   ```bash
   cd /usr/local/var/www
   wp plugin activate woocommerce bbpress easy-digital-downloads
   ```

2. **安装 WordPress Importer**
   ```bash
   wp plugin install wordpress-importer --activate
   ```

3. **确保插件正常运行**
   ```bash
   wp plugin list --status=active
   ```

## 测试结果解读

| 结果 | 含义 |
|------|------|
| ✅ 通过 | 官方数据可自动导入 |
| ⚠️ 需手动 | 需要通过后台手动操作 |
| ❌ 失败 | 数据文件有问题或插件不兼容 |

## 测试后清理

每个测试脚本结尾会显示清理命令，例如：

```bash
# 清理 WooCommerce 产品
wp post delete $(wp post list --post_type=product --format=ids) --force

# 清理 bbPress 数据
wp post delete $(wp post list --post_type=forum,topic,reply --format=ids) --force
```

## 手动导入插件说明

### Academy LMS

1. 安装 `academy-starter-templates` 插件
2. 后台 → Academy Starter → Import Demo

### MasterStudy LMS

1. 后台 → STM LMS → Demo Import
2. 选择模板并导入

### BuddyPress

1. 安装 `bp-default-data` 插件
2. 后台 → 工具 → BP Default Data
3. 设置数量并点击 Generate

### Sensei LMS

1. 后台 → Sensei LMS → Tools → Import
2. 上传 courses.csv 和 lessons.csv

### LifterLMS

1. 后台 → LifterLMS → Import
2. 选择 Import a Course
3. 上传 sample-course.json

## 根据测试结果更新策略

测试完成后，根据结果更新 `OFFICIAL-DATA-STATUS.md`：

- 自动导入成功 → ✅ 可用
- 需手动后台操作 → ⚠️ 后台导入
- 导入失败 → ❌ 改为自定义填充

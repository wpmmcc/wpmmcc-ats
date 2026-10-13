# GitHub 集合资源

这里存放从 GitHub 下载的多个插件示例数据集合。

## 主要资源

### 1. maheshwaghmare/sample-data

**仓库地址**: https://github.com/maheshwaghmare/sample-data

**包含内容**:
- ✅ WordPress Core (Theme Unit Test)
- ✅ WooCommerce (产品数据)
- ✅ bbPress (论坛数据)
- ✅ 其他主题示例数据

**下载方法**:

```bash
cd /Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data/downloads/github-collections/

# 克隆整个仓库
git clone https://github.com/maheshwaghmare/sample-data.git

# 或下载特定文件
mkdir -p sample-data
cd sample-data
curl -O https://raw.githubusercontent.com/maheshwaghmare/sample-data/master/theme-unit-test-data.xml
curl -O https://raw.githubusercontent.com/maheshwaghmare/sample-data/master/woocommerce-sample-products.xml
curl -O https://raw.githubusercontent.com/maheshwaghmare/sample-data/master/bbpress-sample-data.xml
```

**使用方法**:

```bash
cd /usr/local/var/www

# WordPress Core
wp import /Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data/downloads/github-collections/sample-data/theme-unit-test-data.xml --authors=create

# WooCommerce
wp import /Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data/downloads/github-collections/sample-data/woocommerce-sample-products.xml --authors=create

# bbPress
wp import /Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data/downloads/github-collections/sample-data/bbpress-sample-data.xml --authors=create
```

---

### 2. WordPress 官方 GitHub

**仓库地址**: https://github.com/WordPress

**相关仓库**:
- [wordpress-develop](https://github.com/WordPress/wordpress-develop) - WordPress 核心开发
- [theme-unit-test](https://github.com/WPTT/theme-unit-test) - 主题单元测试数据
- [plugin-check](https://github.com/WordPress/plugin-check) - 插件检查工具

---

### 3. OceanWP Sample Data

**仓库地址**: https://github.com/oceanwp/oceanwp-sample-data

**包含内容**:
- ✅ OceanWP 主题演示数据
- ✅ 页面布局
- ✅ Elementor 模板

**使用场景**: 如果使用 OceanWP 主题，可以导入完整的演示站点

---

### 4. jcmpagel/wordpress-dataset

**仓库地址**: https://github.com/jcmpagel/wordpress-dataset

**包含内容**:
- ✅ WordPress 插件数据集
- ✅ 插件生命周期数据
- ✅ 评论模式数据
- ✅ 使用统计

**使用场景**: 研究和分析用途，不是实际填充数据

---

## 更新集合资源

```bash
cd /Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data/downloads/github-collections/

# 更新已克隆的仓库
cd sample-data && git pull origin master && cd ..

# 或重新下载特定文件
curl -O https://raw.githubusercontent.com/maheshwaghmare/sample-data/master/theme-unit-test-data.xml
```

---

## 搜索更多资源

### GitHub Topics 搜索

```bash
# 在浏览器中搜索
open "https://github.com/topics/wordpress-sample-data"
open "https://github.com/search?q=wordpress+demo+data"
open "https://github.com/search?q=woocommerce+sample+products"
```

### 常用搜索关键词

| 关键词 | 用途 |
|--------|------|
| `wordpress sample data` | WordPress 示例数据 |
| `woocommerce demo products` | WooCommerce 产品 |
| `bbpress test data` | bbPress 论坛数据 |
| `lms demo courses` | LMS 课程数据 |
| `wordpress theme demo` | 主题演示数据 |

---

## 贡献资源

如果你发现了新的 GitHub 资源：

1. 克隆或下载到此目录
2. 更新本 README.md
3. 更新父目录的 SUMMARY.md
4. 添加导入示例

---

## 注意事项

- ✅ GitHub 资源通常是最新的
- ✅ 可以通过 Git 追踪更新
- ⚠️ 需要验证数据质量和完整性
- ⚠️ 部分仓库可能已过时，注意检查最后更新日期
- ⚠️ 确保遵守各仓库的开源许可证

---

## 相关链接

- [GitHub WordPress Topic](https://github.com/topics/wordpress)
- [GitHub WordPress Plugin Topic](https://github.com/topics/wordpress-plugin)
- [WordPress.org GitHub](https://github.com/WordPress)

---

**最后更新**: 2026-01-26

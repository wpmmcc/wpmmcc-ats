# bbPress 测试数据

bbPress 官方提供测试数据 XML 文件，用于快速填充论坛内容。

## 数据来源

**官方文档**: https://codex.bbpress.org/getting-started/testing-your-bbpress-installation/creating-test-data/

**GitHub 集合**: https://github.com/maheshwaghmare/sample-data

## 包含内容

- ✅ **17 个论坛**
  - 15 个公开论坛
  - 2 个隐藏/私密论坛
- ✅ **多个主题 (topics)**
- ✅ **多个回复 (replies)**
- ✅ **用户关联**

## 下载方法

### 方式 1: 从官方 Codex 下载

```bash
cd /Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data/downloads/bbpress/

# 从官方文档获取下载链接
# https://codex.bbpress.org/getting-started/testing-your-bbpress-installation/creating-test-data/

# 下载 XML 文件（示例 URL，请从官方文档获取实际链接）
curl -O https://bbpress.trac.wordpress.org/export/1234/trunk/test-data.xml
mv test-data.xml bbpress-sample-data.xml
```

### 方式 2: 从 GitHub 集合下载

```bash
cd /Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data/downloads/github-collections/

# 克隆集合仓库
git clone https://github.com/maheshwaghmare/sample-data.git

# 复制 bbPress 数据
cp sample-data/bbpress/*.xml ../bbpress/
```

### 方式 3: 使用 bbpFauxData 插件

如果找不到官方 XML 文件，可以使用生成插件：

```bash
cd /usr/local/var/www
wp plugin install bbp-faux-data --activate

# 后台生成
# bbPress → Tools → bbpFauxData
# 设置论坛、主题、回复数量
```

## 导入方法

### 使用 WordPress Importer

```bash
cd /usr/local/var/www

# 安装 WordPress Importer
wp plugin install wordpress-importer --activate

# 导入 bbPress 数据
wp import /Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data/downloads/bbpress/bbpress-sample-data.xml \
  --authors=create \
  --skip=attachment
```

### 验证导入结果

```bash
# 查看论坛数量
wp post list --post_type=forum --format=count

# 查看主题数量
wp post list --post_type=topic --format=count

# 查看回复数量
wp post list --post_type=reply --format=count
```

## 注意事项

- ⚠️ 导入前确保 bbPress 插件已激活
- ⚠️ 使用 `--authors=create` 自动创建关联用户
- ✅ 可以多次导入不同的数据文件
- ✅ 导入后可以在后台 bbPress → 论坛 中查看

## 相关链接

- [bbPress Codex - Test Data](https://codex.bbpress.org/getting-started/testing-your-bbpress-installation/creating-test-data/)
- [WP Tavern - 3 Quick Ways](https://wptavern.com/3-quick-ways-to-create-bbpress-test-data)
- [GitHub Sample Data](https://github.com/maheshwaghmare/sample-data)

# Tutor LMS 示例数据

Tutor LMS 官方提供演示课程数据，用于快速了解插件功能。

## 数据来源

**官方文档**: https://docs.themeum.com/tutor-lms/tutorials/importing-tutor-demo-data/

**演示站点**: https://demo.themeum.com/plugins/tutor/

## 包含内容

- ✅ **课程 (Courses)** - 多个完整课程
- ✅ **主题 (Topics)** - 课程章节
- ✅ **课时 (Lessons)** - 视频、文本、图片课时
- ✅ **测验 (Quizzes)** - 测验题目和答案
- ✅ **作业 (Assignments)** - 可选
- ✅ **课程分类和标签**

## 下载方法

### 从官方文档下载

访问官方文档页面获取下载链接：

```bash
cd /Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data/downloads/tutor-lms/

# 1. 访问官方文档
open https://docs.themeum.com/tutor-lms/tutorials/importing-tutor-demo-data/

# 2. 下载 XML 文件
# 页面会提供下载按钮或链接

# 3. 将下载的文件重命名并放到此目录
mv ~/Downloads/tutor-demo.xml ./tutor-demo-data.xml
```

### 从演示站点导出

如果有 Tutor LMS Pro：

```bash
# 访问演示站点
# 导出课程为 JSON 格式
# Tutor LMS → 导入/导出 → 导出课程
```

## 导入方法

### 方式 1: WordPress Importer (XML)

```bash
cd /usr/local/var/www

# 安装 WordPress Importer
wp plugin install wordpress-importer --activate

# 导入 Tutor LMS 数据
wp import /Users/zhangxiao/wptsall-dev/dev-tools/seeding/official-data/downloads/tutor-lms/tutor-demo-data.xml \
  --authors=create \
  --skip=attachment
```

### 方式 2: Tutor LMS Pro (JSON)

如果使用 Tutor LMS Pro：

```
1. 后台 → Tutor LMS → 导入/导出
2. 选择"导入课程"
3. 上传 JSON 文件
4. 点击导入
```

### 方式 3: CSV 导入（第三方插件）

使用第三方插件导入 CSV 格式：

```bash
# 安装 CSV 导入插件
wp plugin install tutor-lms-csv-course-importer --activate

# 后台导入
# Tutor LMS → 导入 CSV
```

## 验证导入结果

```bash
cd /usr/local/var/www

# 查看课程数量
wp post list --post_type=courses --format=count

# 查看课时数量
wp post list --post_type=lesson --format=count

# 查看测验数量
wp post list --post_type=tutor_quiz --format=count

# 列出所有课程
wp post list --post_type=courses --fields=ID,post_title,post_status
```

## 注意事项

- ⚠️ 导入前确保 Tutor LMS 插件已激活
- ⚠️ XML 导入需要 WordPress Importer 插件
- ⚠️ JSON 导入需要 Tutor LMS Pro 版本
- ✅ 免费版也可以使用 XML 导入
- ✅ 导入后可能需要重新生成缩略图

## 清理数据

```bash
cd /usr/local/var/www

# 删除所有 Tutor 课程
wp post delete $(wp post list --post_type=courses --format=ids) --force

# 删除所有课时
wp post delete $(wp post list --post_type=lesson --format=ids) --force

# 删除所有测验
wp post delete $(wp post list --post_type=tutor_quiz --format=ids) --force
```

## 相关链接

- [Tutor LMS 文档](https://docs.themeum.com/tutor-lms/)
- [导入演示数据指南](https://docs.themeum.com/tutor-lms/tutorials/importing-tutor-demo-data/)
- [导入/导出课程](https://docs.themeum.com/tutor-lms/tutorials/import-export-tutor-lms-courses/)
- [Tutor LMS 演示站](https://demo.themeum.com/plugins/tutor/)

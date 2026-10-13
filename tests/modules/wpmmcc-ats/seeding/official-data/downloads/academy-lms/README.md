# Academy LMS 示例数据

Academy LMS 通过 **Starter Templates** 扩展提供一键导入的完整站点模板。

## 数据来源

**官方页面**: https://academylms.net/academy-starter-templates/

**插件主页**: https://wordpress.org/plugins/academy/

**演示站点**: https://demo.creativeitem.com/academy

## 包含内容

Starter Templates 提供完整的站点模板，包括：

- ✅ **课程 (Courses)** - 多个示例课程
- ✅ **课时 (Lessons)** - 视频、文本、图片课时
- ✅ **测验 (Quizzes)** - 测验题目
- ✅ **课程分类和标签**
- ✅ **学生和教师用户** - 示例用户
- ✅ **页面和布局** - 课程列表、单个课程页面
- ✅ **主题设置** - 配色、字体等

## 使用方法（完全免费，无需注册）

### 步骤 1: 安装 Academy LMS 插件

```bash
cd /usr/local/var/www

# 安装 Academy LMS（免费版）
wp plugin install academy --activate
```

### 步骤 2: 安装 Starter Templates 插件

```bash
# 方式 1: WP-CLI（推荐）
wp plugin install academy-starter-templates --activate

# 方式 2: 通过 WordPress 后台
# 插件 → 安装插件 → 搜索 "Academy Starter Templates"
# 点击"安装"，然后"激活"
```

### 步骤 3: 一键导入模板

```
1. 登录 WordPress 后台
2. 侧边栏 → Academy Starter
3. 浏览 3 个免费模板：
   - Marketplace (市场模式)
   - University (大学模式)
   - Instructor (讲师模式)
4. 鼠标悬停在模板上
5. 点击 "Import Demo" 按钮
6. 确认导入（会显示所需插件列表）
7. 等待导入完成（约 2-5 分钟）
8. 访问前台查看效果
```

### 访问演示站点

如果需要先预览：

```bash
# 打开演示站点
open https://demo.creativeitem.com/academy

# 登录信息（演示站点）
# Email: admin@example.com
# Password: 1234
```

## 注意事项

- ✅ **完全免费**: 无需注册账号，直接从 WordPress.org 安装
- ✅ **3 个免费模板**: Marketplace, University, Instructor 都是免费的
- ⚠️ **Pro 版本**: 如果需要更多高级功能，可以升级到 Pro（可选）
- ✅ **完整站点**: 导入的是完整站点，包括课程、页面、设置
- ⚠️ **会覆盖现有内容**: 导入前建议在测试环境或备份后操作
- ✅ **依赖插件**: 部分模板可能需要额外的免费插件（会自动提示）

## 免费版 vs Pro 版

| 特性 | 免费版 | Pro 版 |
|------|--------|--------|
| 基础课程功能 | ✅ | ✅ |
| 课时和测验 | ✅ | ✅ |
| Starter Templates | ⚠️ 部分 | ✅ 全部 |
| 高级测验类型 | ❌ | ✅ |
| 证书系统 | ❌ | ✅ |
| Zoom 集成 | ❌ | ✅ |

## 替代方案

如果 Starter Templates 不可用，可以使用自定义填充系统：

```bash
cd /usr/local/var/www

# 使用 WPTSALL 自定义填充脚本
wp eval-file /Users/zhangxiao/wptsall-dev/tests/workflow/seed-content.php A

# 查看生成的 Academy 课程
wp post list --post_type=academy_courses --format=table
```

## 验证导入结果

```bash
cd /usr/local/var/www

# 查看课程数量
wp post list --post_type=academy_courses --format=count

# 查看课时数量
wp post list --post_type=academy_lessons --format=count

# 列出所有课程
wp post list --post_type=academy_courses --fields=ID,post_title,post_status
```

## 清理数据

```bash
cd /usr/local/var/www

# 删除所有 Academy 课程
wp post delete $(wp post list --post_type=academy_courses --format=ids) --force

# 删除所有课时
wp post delete $(wp post list --post_type=academy_lessons --format=ids) --force
```

## 相关链接

- [Academy LMS 官网](https://academylms.net/)
- [Starter Templates 页面](https://academylms.net/academy-starter-templates/)
- [WordPress.org 插件页面](https://wordpress.org/plugins/academy/)
- [演示站点](https://demo.creativeitem.com/academy)
- [官方文档](https://academylms.net/docs/)

## 其他 LMS 插件对比

如果 Academy 数据不可用，可以考虑其他有官方数据的 LMS 插件：

| 插件 | 官方数据 | 格式 | 推荐指数 |
|------|---------|------|----------|
| LearnPress | ✅ 自带 | XML | ⭐⭐⭐⭐⭐ |
| Tutor LMS | ✅ 可下载 | XML | ⭐⭐⭐⭐ |
| LifterLMS | ✅ 自带 | JSON | ⭐⭐⭐ |
| Sensei LMS | ✅ 自带 | CSV | ⭐⭐⭐ |
| Academy LMS | ⚠️ 需注册 | 扩展 | ⭐⭐ |

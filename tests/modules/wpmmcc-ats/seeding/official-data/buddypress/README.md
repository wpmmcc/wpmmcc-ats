# BuddyPress 测试数据

BuddyPress 不使用文件导入，而是通过 **BP Default Data** 插件自动生成测试数据。

## 插件信息

**插件名称**: BP Default Data
**插件 Slug**: `bp-default-data`
**WordPress.org**: https://wordpress.org/plugins/bp-default-data/
**安装状态**: ✅ 已安装

## 生成内容

- ✅ **用户 (Users)** - 带头像 (8biticon.com)
- ✅ **好友关系 (Friends)** - 随机好友连接
- ✅ **群组 (Groups)** - 带描述、成员、活动
- ✅ **活动流 (Activity)** - 个人和群组活动
- ✅ **私信 (Messages)** - 用户间消息对话
- ✅ **扩展资料 (XProfile)** - 填写的用户资料字段
- ✅ **论坛帖子 (Forum Posts)** - 如果启用了 bbPress

## 使用方法

### 1. 后台生成

```
1. 登录 WordPress 后台
2. 工具 → BP Default Data
3. 设置数量（推荐）：
   - Users: 50
   - Friends: 100
   - Groups: 20
   - Activity: 200
   - Messages: 50
   - Forum Posts: 100
4. 点击 "Generate" 按钮
5. 等待完成（约 1-2 分钟）
```

### 2. 验证生成结果

```bash
cd /usr/local/var/www

# 查看生成的用户
wp user list --fields=ID,user_login,display_name

# 查看 BuddyPress 群组
wp eval 'echo count( groups_get_groups( array( "per_page" => 9999 ) )["total"] );'

# 查看活动流数量
wp eval 'echo bp_activity_get_total_activity_count();'
```

### 3. 清理数据

```
后台操作:
工具 → BP Default Data → "Clear BuddyPress" 按钮

会删除通过插件生成的所有数据：
- 消息、群组、通知、好友关系、论坛帖子、xprofile 资料
```

## 注意事项

- ⚠️ 生成的用户会占用 email 地址（格式: user{N}@example.com）
- ⚠️ 头像通过 8biticon.com 生成，显示在 Gravatar 上
- ⚠️ **仅用于测试环境**，不要在生产站点使用
- ✅ 可以多次运行，每次生成新的数据
- ✅ 清理功能会保留手动创建的内容

## 相关链接

- [插件官方页面](https://wordpress.org/plugins/bp-default-data/)
- [WP Tavern 文章](https://wptavern.com/how-to-create-demo-data-for-a-buddypress-site)
- [Wbcom Designs 教程](https://wbcomdesigns.com/getting-started-buddypress-install-demo-data/)

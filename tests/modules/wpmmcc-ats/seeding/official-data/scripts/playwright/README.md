# WordPress 后台数据导入 - Playwright 自动化脚本

通过 Playwright 模拟浏览器操作，自动完成需要后台登录的数据导入。

## 适用插件

| 插件 | 脚本 | 说明 |
|------|------|------|
| LearnPress | `import-learnpress.spec.js` | 上传 XML 文件导入课程 |
| MasterStudy LMS | `import-masterstudy.spec.js` | Demo Import 向导 |
| BuddyPress | `import-buddypress.spec.js` | 安装 bp-default-data 并生成数据 |
| Academy LMS | `import-academy.spec.js` | 安装 Starter Templates 并导入 |

## 安装

```bash
cd scripts/playwright

# 安装依赖
npm install

# 安装 Playwright 浏览器
npm run install-deps
# 或
npx playwright install chromium
```

## 配置

### 环境变量

```bash
# WordPress 管理员账号（默认 admin/password）
export WP_USER=admin
export WP_PASS=password
```

### Playwright 配置

编辑 `playwright.config.js`：

```js
use: {
  baseURL: 'http://localhost',  // WordPress 站点地址
  headless: true,               // 改为 false 可查看浏览器操作
}
```

## 运行

```bash
# 运行所有导入
npm run import:all

# 运行单个插件导入
npm run import:learnpress
npm run import:masterstudy
npm run import:buddypress
npm run import:academy

# 直接运行（显示浏览器）
npx playwright test import-learnpress.spec.js --headed

# 调试模式
npx playwright test import-learnpress.spec.js --debug
```

## 导入流程

### LearnPress

1. 登录 WordPress 后台
2. 导航到 LearnPress → Tools → Import
3. 上传 `learnpress/sample-data.xml`
4. 点击 Import 按钮
5. 等待导入完成

### MasterStudy LMS

1. 登录 WordPress 后台
2. 导航到 MS LMS → Demo Import
3. 选择一个 Demo 模板
4. 点击 Import 按钮
5. 等待导入完成（可能需要几分钟）

### BuddyPress

1. 登录 WordPress 后台
2. 检查/安装 bp-default-data 插件
3. 导航到 Tools → BP Default Data
4. 设置生成数量（用户、群组、活动等）
5. 点击 Generate 按钮

### Academy LMS

1. 登录 WordPress 后台
2. 检查/安装 academy-starter-templates 插件
3. 导航到 Academy → Starter Templates
4. 选择一个模板
5. 点击 Import Demo 按钮

## 故障排除

### 查看截图

失败时会自动截图保存：
- `learnpress-tools-page.png`
- `masterstudy-page.png`
- `buddypress-default-data.png`
- `academy-starter-page.png`

### 调试模式

```bash
# 显示浏览器操作
npx playwright test --headed

# 单步调试
npx playwright test --debug

# 打开追踪查看器
npx playwright show-trace trace.zip
```

### 常见问题

1. **登录失败**：检查 WP_USER 和 WP_PASS 环境变量
2. **页面找不到**：插件可能有不同的菜单路径，查看截图
3. **导入超时**：增加 `playwright.config.js` 中的 timeout 值
4. **元素找不到**：插件更新可能改变了 UI，需要更新选择器

## 注意事项

- 脚本会自动安装辅助插件（bp-default-data, academy-starter-templates）
- 导入过程可能需要几分钟，请耐心等待
- 建议在干净的测试环境中运行，避免数据冲突
- 运行前确保插件已激活且正常工作

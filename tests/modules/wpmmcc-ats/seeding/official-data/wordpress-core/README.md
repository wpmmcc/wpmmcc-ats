# WordPress Core - Plan E 数据填充

## 概述

Plan E 用于填充 WordPress 核心内容，覆盖所有核心字段类型：

| 字段类型 | 覆盖内容 |
|----------|----------|
| post_title | 文章/页面标题 |
| post_content | 文章/页面内容（含 Block Editor 格式） |
| post_excerpt | 文章摘要 |
| post_name | URL slug |
| category | 分类（4个） |
| post_tag | 标签（10个） |
| comment | 评论（6条） |
| nav_menu | 导航菜单 |

---

## 内容统计

| 类型 | 数量 | 详情 |
|------|------|------|
| 页面 | 5 | 首页、服务、关于、联系、新闻中心 |
| 文章 | 10 | 公司新闻×3、产品更新×2、行业动态×3、技术分享×2 |
| 分类 | 4 | 公司新闻、行业动态、产品更新、技术分享 |
| 标签 | 10 | 数字化转型、人工智能、云计算、企业服务等 |
| 评论 | 6 | 分布在多篇文章下 |
| 菜单 | 1 | 主导航（5项） |

---

## 执行方式

```bash
cd /usr/local/var/www

# 方式 1: 使用调度器（推荐）
wp eval-file /path/to/scripts/seed/dispatcher.php E

# 方式 2: 分步执行
# Step 1: 清理已有数据
wp eval-file /path/to/wordpress-core/cleanup-wp-core.php

# Step 2: 填充数据
wp eval-file /path/to/wordpress-core/corporate-site-seed.php

# Step 3: 验证
wp eval-file /path/to/scripts/verify/dispatcher.php E
```

---

## 创建的页面

| 页面 | Slug | 说明 |
|------|------|------|
| 首页 | `home` | 静态首页，含 Hero 区块和优势展示 |
| 服务项目 | `services` | 网站开发、移动应用、系统集成、技术咨询 |
| 关于我们 | `about` | 公司介绍、使命、愿景、价值观 |
| 联系我们 | `contact` | 地址、电话、邮箱、工作时间 |
| 新闻中心 | `news` | 文章归档页（设为博客页） |

---

## 创建的文章

### 公司新闻（3篇）
| 文章 | 标签 |
|------|------|
| 公司荣获2025年度创新企业奖 | 获奖荣誉、技术创新 |
| 公司与行业领先企业达成战略合作 | 合作伙伴、企业服务 |
| 公司成功举办年度技术峰会 | 技术创新、人工智能、云计算 |

### 产品更新（2篇）
| 文章 | 标签 |
|------|------|
| 新版产品 3.0 正式发布 | 产品发布、人工智能 |
| 移动端 App 2.5 版本更新 | 产品发布 |

### 行业动态（3篇）
| 文章 | 标签 |
|------|------|
| 数字化转型：企业发展的必由之路 | 数字化转型、企业服务 |
| 2025年人工智能发展趋势展望 | 人工智能、技术创新、数字化转型 |
| 云计算市场格局与发展机遇 | 云计算、企业服务 |

### 技术分享（2篇）
| 文章 | 标签 |
|------|------|
| API 设计最佳实践指南 | 开发者、最佳实践 |
| 微服务架构实践经验分享 | 开发者、最佳实践、云计算 |

---

## 创建的分类和标签

### 分类（4个）
| 分类 | Slug | 说明 |
|------|------|------|
| 公司新闻 | company-news | 公司最新动态、公告和活动 |
| 行业动态 | industry-news | 行业趋势、市场分析和技术前沿 |
| 产品更新 | product-updates | 产品发布、功能更新和版本说明 |
| 技术分享 | tech-sharing | 技术文章、最佳实践和开发经验 |

### 标签（10个）
| 标签 | Slug |
|------|------|
| 数字化转型 | digital-transformation |
| 人工智能 | ai |
| 云计算 | cloud-computing |
| 企业服务 | enterprise-service |
| 技术创新 | tech-innovation |
| 产品发布 | product-launch |
| 获奖荣誉 | awards |
| 合作伙伴 | partnership |
| 开发者 | developer |
| 最佳实践 | best-practices |

---

## 字段分类（用于同步）

| WordPress 字段 | 字段类型 | 同步方式 |
|----------------|----------|----------|
| `post_title` | translate | 翻译 |
| `post_content` | translate | 翻译 |
| `post_excerpt` | translate | 翻译 |
| `post_name` | compute | 重新生成 slug |
| `post_date` | sync | 直接同步 |
| `post_status` | sync | 直接同步 |
| `post_author` | field_mapping | ID 映射 |
| `post_parent` | field_mapping | ID 映射 |
| `_thumbnail_id` | field_mapping | ID 映射 |
| `guid` | compute | 自动生成 |
| `category` | field_mapping | term_id 映射 |
| `post_tag` | field_mapping | term_id 映射 |
| `comment` | translate + field_mapping | 评论内容翻译 + post_id 映射 |

---

## 清理脚本

`cleanup-wp-core.php` 会清理以下内容：

| 类型 | 说明 |
|------|------|
| posts | 所有文章 |
| pages | 所有页面 |
| attachments | 所有附件（含文件） |
| revisions | 所有修订版本 |
| nav_menu_item | 所有菜单项 |
| comments | 所有评论 |
| categories | 所有分类（保留默认） |
| tags | 所有标签 |
| nav_menu | 所有导航菜单 |

支持 `--dry-run` 参数预览：

```bash
wp eval-file cleanup-wp-core.php --dry-run
```

---

## 备用数据源

如需更多测试数据（57篇文章、21个页面），可使用 WordPress 官方 Theme Unit Test Data：

```bash
# 安装 WordPress Importer
wp plugin install wordpress-importer --activate

# 清理数据
wp eval-file cleanup-wp-core.php

# 导入官方数据
wp import themeunittestdata.wordpress.xml --authors=create
```

数据来源：https://github.com/WordPress/theme-test-data

---

## 文件列表

```
wordpress-core/
├── README.md                           # 本文件
├── cleanup-wp-core.php                 # 清理脚本
├── corporate-site-seed.php             # 企业官网填充脚本
└── themeunittestdata.wordpress.xml     # 官方测试数据（备用）
```

---

*最后更新: 2026-01-27*

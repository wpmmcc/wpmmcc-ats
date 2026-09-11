# WPMMCC ATS — WordPress 多语言插件

**让 WordPress 多语言化，实用为先：扫描、关联、翻译。**

[English](../README.md) | **简体中文** | [繁體中文](README.zh-TW.md) | [日本語](README.ja.md) | [한국어](README.ko.md) | [Español](README.es.md) | [Français](README.fr.md) | [Deutsch](README.de.md) | [Português (Brasil)](README.pt-BR.md) | [Italiano](README.it.md) | [Русский](README.ru.md) | [العربية](README.ar.md) | [हिन्दी](README.hi.md) | [Türkçe](README.tr.md) | [Tiếng Việt](README.vi.md) | [Bahasa Indonesia](README.id.md)

WPMMCC ATS 是一个把单语言 WordPress 站点转为多语言站点的插件。它扫描内容插件、主题与菜单中的可翻译字段，构建可复用的翻译规则，管理语言之间的站点关系，并在 wp-admin 内提供完整的手动翻译编辑器。配合配套的 WPTSALL 客户端可实现自动翻译 — 无需账号、无需许可证密钥、没有厂商锁定。

## 相关链接
- 项目官网 — https://www.wpmm.cc/
- 文档与使用帮助（English / 简体中文）— https://www.wpmm.cc/docs/
- WordPress.org 插件页 — https://wordpress.org/plugins/wpmmcc-ats/
- 配套客户端仓库 — https://github.com/wpmmcc/wptsall-client

## 功能
- 内容扫描 — 探测文章、分类法、meta、第三方内容插件与主题中的可翻译字段
- 站点关系 — 关联源语言与目标语言，支持带独立 URL 前缀的虚拟站点
- 手动翻译编辑器 — 完全在 WordPress 后台（wp-admin）内完成文章与字段翻译
- 翻译规则 — 手动翻译编辑器与客户端 API 共用同一套规则集
- Protocol v2 客户端 API — 供 WPTSALL 客户端领取任务并写回结果
- 语言包 — 英文为内置源语言；简体中文（zh_CN）语言包内置并随版本提供（见下文）

## 环境要求
- WordPress 6.2 或更高版本
- PHP 7.4 或更高版本
- 无需账号、订阅或许可证密钥

## 安装
1. 从本仓库 Releases 页下载最新 ZIP 包（或自行打包源码）
2. 在 wp-admin 进入 插件 → 安装插件 → 上传插件，上传 ZIP 后启用
3. 在 wp-admin 打开 WPMMCC ATS 菜单

## 快速上手（手动翻译，无需客户端）
1. 在 WPMMCC ATS 管理页扫描内容插件或创建翻译规则集
2. 添加站点关系：选择源语言与目标语言 — 虚拟站点会获得独立 URL 前缀（如 /en_us/）
3. 在手动翻译编辑器中打开一篇文章并翻译；保存后目标站点随之更新

## 自动翻译
自动翻译请安装配套的 WPTSALL 客户端（WebUI 或 Desktop）。它使用设备级令牌直连站点，领取翻译任务，调用配置的翻译厂商端点，并把结果写回。客户端仓库：https://github.com/wpmmcc/wptsall-client

## 更新
- 通过标准 WordPress 更新程序升级 — 仪表盘 → 更新，或插件列表页。无需手动操作
- 升级绝不会影响你的翻译数据：数据表、虚拟站点、翻译记忆、术语表与配置均完整保留
- 每个版本的发布说明均可在 readme.txt 的 Changelog 章节中查看

## 卸载
- 在插件列表页停用该插件，然后删除
- 自 2.1.3 版本起，删除插件默认保留你的翻译数据（数据表、已翻译文章/分类、翻译记忆、术语表、语言包），重新安装即可完整恢复
- 如需彻底清理，可在删除前进入插件设置开启“卸载时删除数据”。无论如何选择，插件配置项、临时缓存与计划任务均会被彻底移除

## 开源组件
- 不捆绑任何第三方代码：基于纯原生 PHP 与 WordPress 核心 API（REST、WPDB/dbDelta、cron、gettext），管理后台采用原生 JavaScript 与 WordPress 内置 jQuery 构建
- 界面本地化翻译来源于 WordPress.org 翻译协作系统（GlotPress）— translate.wordpress.org
- 字段探测功能仅读取 WooCommerce、Elementor、ACF、Yoast SEO 等第三方内容插件的数据；未对这些项目进行捆绑或修改
- 与 WPML、Polylang 等多语言插件友好并存 — 未引用两者的任何代码

## 多语言
英文为内置源语言。简体中文（zh_CN）语言包位于 languages/ 目录，切换 WordPress 站点语言至“简体中文”后自动生效。如需新增更多语言，请使用常用的 PO 编辑器翻译 languages/wpmmcc-ats.pot 并欢迎贡献回馈。

## 许可证
GPL-2.0-or-later，详见 [LICENSE](../LICENSE)。


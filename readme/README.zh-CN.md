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

## Updating
- Updates install through the standard WordPress updater — Dashboard → Updates, or the Plugins page. No manual steps are required
- Updating never touches your translation data: tables, virtual sites, translation memory, terminology and settings all carry over
- Release notes for every version are in the Changelog section of readme.txt

## Uninstalling
- Deactivate the plugin on the Plugins page, then delete it
- Since 2.1.3, deleting the plugin keeps your translation data by default (tables, translated posts/terms, translation memory, terminology, language packs), so a reinstall restores everything
- For a full cleanup instead, enable "Delete data on uninstall" in the plugin settings before deleting. Plugin settings, transients and scheduled tasks are always removed either way

## Open-source components
- No third-party code is bundled: plain PHP on WordPress core APIs (REST, WPDB/dbDelta, cron, gettext), with admin pages in vanilla JavaScript plus jQuery as shipped with WordPress
- Interface translations come from the WordPress.org translation system (GlotPress) — translate.wordpress.org
- Field discovery reads data from third-party content plugins such as WooCommerce, Elementor, ACF and Yoast SEO; those projects are not bundled or modified
- Coexists with the multilingual plugins WPML and Polylang — no code from either project is used
## 多语言
英文为内置源语言。简体中文（zh_CN）语言包位于 languages/ 目录，切换 WordPress 站点语言至“简体中文”后自动生效。如需新增更多语言，请使用常用的 PO 编辑器翻译 languages/wpmmcc-ats.pot 并欢迎贡献回馈。

## 许可证
GPL-2.0-or-later，详见 [LICENSE](../LICENSE)。


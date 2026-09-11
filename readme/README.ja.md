# WPMMCC ATS — WordPress 多言語プラグイン

**WordPress を実用的に多言語化:スキャン・関連付け・翻訳。**

[English](../README.md) | [简体中文](README.zh-CN.md) | [繁體中文](README.zh-TW.md) | **日本語** | [한국어](README.ko.md) | [Español](README.es.md) | [Français](README.fr.md) | [Deutsch](README.de.md) | [Português (Brasil)](README.pt-BR.md) | [Italiano](README.it.md) | [Русский](README.ru.md) | [العربية](README.ar.md) | [हिन्दी](README.hi.md) | [Türkçe](README.tr.md) | [Tiếng Việt](README.vi.md) | [Bahasa Indonesia](README.id.md)

WPMMCC ATS は、単一言語の WordPress サイトを多言語サイトに変えるプラグインです。コンテンツプラグイン・テーマ・メニューから翻訳可能なフィールドをスキャンし、再利用可能な翻訳ルールを生成し、言語間のサイトリレーションを管理し、wp-admin 内で完結する手動翻訳エディターを提供します。自動翻訳は companion の WPTSALL Client で実現できます。アカウントもライセンスもベンダーロックインも不要です。

## Links
- Project website — https://www.wpmm.cc/
- Documentation and usage help (English / 简体中文) — https://www.wpmm.cc/docs/
- This plugin on WordPress.org — https://wordpress.org/plugins/wpmmcc-ats/
- Companion client repository — https://github.com/wpmmcc/wptsall-client

## 機能
- コンテンツスキャン — 投稿・タクソノミー・meta・サードパーティコンテンツプラグイン・テーマの翻訳可能フィールドを検出
- サイトリレーション — ソース言語とターゲット言語を関連付け、専用 URL プレフィックスを持つ仮想サイトをサポート
- 手動翻訳エディター — WordPress 管理画面内ですべての翻訳を完結
- 翻訳ルール — 手動エディターとクライアント API で同じルールセットを共有
- Protocol v2 クライアント API — WPTSALL Client がタスクを取得して結果を書き戻し
- 言語パック — 英語内蔵、簡体字中国語は翻訳進行中(下記参照)

## 要件
- WordPress 6.2 以上
- PHP 7.4 以上
- アカウント・サブスクリプション・ライセンスキーは不要

## インストール
1. このリポジトリの Releases ページから最新 ZIP をダウンロード(またはソースから自分で zip を作成)
2. wp-admin の プラグイン → 新規追加 → プラグインのアップロード から ZIP をアップロードして有効化
3. wp-admin の WPMMCC ATS メニューを開く

## クイックスタート(手動翻訳・クライアント不要)
1. WPMMCC ATS 管理ページからコンテンツプラグインをスキャン、または翻訳ルールセットを作成
2. サイトリレーションを追加:ソース言語とターゲット言語を選ぶと、仮想サイトには /en_us/ のような専用 URL プレフィックスが付きます
3. 手動翻訳エディターで投稿を開いて翻訳;保存するとターゲットサイトに反映されます

## 自動翻訳
自動翻訳には companion の WPTSALL Client(WebUI または Desktop)をインストールしてください。デバイストークンであなたのサイトへ直接接続し、翻訳タスクを取得し、設定した翻訳プロバイダーを呼び出し、結果を書き戻します。クライアントリポジトリ: https://github.com/wpmmcc/wptsall-client

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
## Languages
英語が内蔵のソース言語です。簡体字中国語(zh_CN)パックは languages/ で翻訳を進めています。カタログのコンパイル後、設定 → 一般 でサイトの言語を選ぶと自動的に読み込まれます。他の言語の追加は、languages/wpmmcc-ats.pot をお好みの PO エディターで翻訳してコントリビュートしてください。

## License
GPL-2.0-or-later。[LICENSE](../LICENSE) を参照。


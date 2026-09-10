# WPMMCC ATS — Multilingual WordPress Plugin

**Multilingual WordPress, made practical: scan, relate, translate.**

**English** | [简体中文](readme/README.zh-CN.md) | [繁體中文](readme/README.zh-TW.md) | [日本語](readme/README.ja.md) | [한국어](readme/README.ko.md) | [Español](readme/README.es.md) | [Français](readme/README.fr.md) | [Deutsch](readme/README.de.md) | [Português (Brasil)](readme/README.pt-BR.md) | [Italiano](readme/README.it.md) | [Русский](readme/README.ru.md) | [العربية](readme/README.ar.md) | [हिन्दी](readme/README.hi.md) | [Türkçe](readme/README.tr.md) | [Tiếng Việt](readme/README.vi.md) | [Bahasa Indonesia](readme/README.id.md)

WPMMCC ATS is a WordPress plugin that turns a single-language site into a multilingual one. It scans content plugins, themes and menus for translatable fields, builds reusable translation rules, manages site relations between languages, and ships a complete manual translation editor inside wp-admin. Automatic translation is available through the companion WPTSALL Client — no account, no license, no vendor lock-in.

## Links
- Project website — https://www.wpmm.cc/
- Documentation and usage help (English / 简体中文) — https://www.wpmm.cc/docs/
- This plugin on WordPress.org — https://wordpress.org/plugins/wpmmcc-ats/
- Companion client repository — https://github.com/wpmmcc/wptsall-client

## Features
- Content scanning — detects translatable fields in posts, taxonomies, meta, third-party content plugins and themes
- Site relations — link source and target languages, including virtual sites with their own URL prefix
- Manual translation editor — translate posts and fields entirely inside WordPress admin
- Translation rules — one rule set shared by the manual editor and the client API
- Protocol v2 client API — lets the WPTSALL Client claim tasks and write results back
- Language packs — English built in; Simplified Chinese (zh_CN) bundled and auto-loaded when your site language is set (see below)

## Requirements
- WordPress 6.2 or newer
- PHP 7.4 or newer
- No account, subscription or license key required

## Installation
1. Download the latest release ZIP from this repository's Releases page (or zip the source yourself)
2. In wp-admin go to Plugins → Add New → Upload Plugin, upload the ZIP, then activate
3. Open the WPMMCC ATS menu in wp-admin

## Quick start (manual translation, no client needed)
1. Scan your content plugins or create a translation rule set from the WPMMCC ATS admin pages
2. Add a site relation: pick a source language and a target language — a virtual site gets its own URL prefix such as /en_us/
3. Open a post in the manual translation editor and translate it; the target site updates as you save

## Automatic translation
For automatic translation, install the companion WPTSALL Client (WebUI or Desktop). It connects to your site directly with a device-scoped token, claims translation tasks, calls the translation provider you configure, and writes the results back. See the client repository: https://github.com/wpmmcc/wptsall-client

## Languages
English is the built-in source language. A complete Simplified Chinese (zh_CN) pack ships in languages/ and WordPress loads it automatically once the site language is set to 简体中文 under Settings → General. To add another language, translate languages/wpmmcc-ats.pot with your favourite PO editor and contribute it back.

## License
GPL-2.0-or-later. See [LICENSE](LICENSE).


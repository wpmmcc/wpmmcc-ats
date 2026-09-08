# WPMMCC ATS — Multilingual WordPress Plugin

**Multilingual WordPress, made practical: scan, relate, translate.**


WPMMCC ATS is a WordPress plugin that turns a single-language site into a multilingual one. It scans content plugins, themes and menus for translatable fields, builds reusable translation rules, manages site relations between languages, and ships a complete manual translation editor inside wp-admin. Automatic translation is available through the companion WPTSALL Client — no account, no license, no vendor lock-in.

## Features
- Content scanning — detects translatable fields in posts, taxonomies, meta, third-party content plugins and themes
- Site relations — link source and target languages, including virtual sites with their own URL prefix
- Manual translation editor — translate posts and fields entirely inside WordPress admin
- Translation rules — one rule set shared by the manual editor and the client API
- Protocol v2 client API — lets the WPTSALL Client claim tasks and write results back
- Language packs — English built in; Simplified Chinese in progress (see below)

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
English is the built-in source language and the only language shipped today. Translations (starting with Simplified Chinese) are planned and will be produced from this English text.

## License
GPL-2.0-or-later. See [LICENSE](LICENSE).


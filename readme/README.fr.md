# WPMMCC ATS — Plugin WordPress multilingue

**WordPress multilingue, sans complication : scanner, relier, traduire.**

[English](../README.md) | [简体中文](README.zh-CN.md) | [繁體中文](README.zh-TW.md) | [日本語](README.ja.md) | [한국어](README.ko.md) | [Español](README.es.md) | **Français** | [Deutsch](README.de.md) | [Português (Brasil)](README.pt-BR.md) | [Italiano](README.it.md) | [Русский](README.ru.md) | [العربية](README.ar.md) | [हिन्दी](README.hi.md) | [Türkçe](README.tr.md) | [Tiếng Việt](README.vi.md) | [Bahasa Indonesia](README.id.md)

WPMMCC ATS est un plugin WordPress qui transforme un site monolingue en site multilingue. Il analyse vos plugins de contenu, thèmes et menus pour trouver les champs traduisibles, construit des règles de traduction réutilisables, gère les relations de site entre langues et fournit un éditeur de traduction manuelle complet dans wp-admin. La traduction automatique est possible avec le client compagnon WPTSALL Client — sans compte, sans licence, sans dépendance à un fournisseur.

## Links
- Project website — https://www.wpmm.cc/
- Documentation and usage help (English / 简体中文) — https://www.wpmm.cc/docs/
- This plugin on WordPress.org — https://wordpress.org/plugins/wpmmcc-ats/
- Companion client repository — https://github.com/wpmmcc/wptsall-client

## Fonctionnalités
- Analyse de contenu — détecte les champs traduisibles dans les articles, taxonomies, meta, plugins de contenu tiers et thèmes
- Relations de site — lie langue source et langue cible, avec sites virtuels et préfixe d'URL dédié
- Éditeur de traduction manuelle — traduisez articles et champs entièrement dans l'admin WordPress
- Règles de traduction — un même jeu de règles partagé par l'éditeur manuel et l'API client
- API client Protocol v2 — permet à WPTSALL Client de réclamer des tâches et d'écrire les résultats
- Paquets de langue — anglais intégré ; chinois simplifié en cours (voir ci-dessous)

## Prérequis
- WordPress 6.2 ou plus récent
- PHP 7.4 ou plus récent
- Aucun compte, abonnement ni clé de licence requis

## Installation
1. Téléchargez le ZIP de la dernière version depuis la page Releases de ce dépôt (ou compressez vous-même les sources)
2. Dans wp-admin, allez dans Extensions → Ajouter → Téléverser une extension, téléversez le ZIP puis activez
3. Ouvrez le menu WPMMCC ATS dans wp-admin

## Démarrage rapide (traduction manuelle, sans client)
1. Analysez vos plugins de contenu ou créez un jeu de règles depuis les pages d'administration WPMMCC ATS
2. Ajoutez une relation de site : choisissez une langue source et une langue cible — un site virtuel reçoit son propre préfixe d'URL, p. ex. /en_us/
3. Ouvrez un article dans l'éditeur de traduction manuelle et traduisez-le ; le site cible se met à jour à l'enregistrement

## Traduction automatique
Pour la traduction automatique, installez le client compagnon WPTSALL Client (WebUI ou Desktop). Il se connecte directement à votre site avec un jeton d'appareil, réclame des tâches, appelle le fournisseur que vous configurez et écrit les résultats. Dépôt du client : https://github.com/wpmmcc/wptsall-client

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
L'anglais est la langue source intégrée. Un paquet chinois simplifié (zh_CN) est en cours dans languages/ ; une fois le catalogue compilé, choisissez la langue du site dans Réglages → Général et WordPress le charge automatiquement. Pour ajouter une langue, traduisez languages/wpmmcc-ats.pot avec votre éditeur PO préféré et contribuez.

## License
GPL-2.0-or-later. Voir [LICENSE](../LICENSE).


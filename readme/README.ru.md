# WPMMCC ATS — многоязычный плагин для WordPress

**Практичная многоязычность для WordPress: сканируй, связывай, переводи.**

[English](../README.md) | [简体中文](README.zh-CN.md) | [繁體中文](README.zh-TW.md) | [日本語](README.ja.md) | [한국어](README.ko.md) | [Español](README.es.md) | [Français](README.fr.md) | [Deutsch](README.de.md) | [Português (Brasil)](README.pt-BR.md) | [Italiano](README.it.md) | **Русский** | [العربية](README.ar.md) | [हिन्दी](README.hi.md) | [Türkçe](README.tr.md) | [Tiếng Việt](README.vi.md) | [Bahasa Indonesia](README.id.md)

WPMMCC ATS — плагин WordPress, превращающий одноязычный сайт в многоязычный. Он сканирует контентные плагины, темы и меню в поисках переводимых полей, строит переиспользуемые правила перевода, управляет связями сайтов между языками и включает полноценный редактор ручного перевода прямо в wp-admin. Автоматический перевод доступен с клиентом-компаньоном WPTSALL Client — без аккаунта, без лицензии, без привязки к вендору.

## Links
- Project website — https://www.wpmm.cc/
- Documentation and usage help (English / 简体中文) — https://www.wpmm.cc/docs/
- This plugin on WordPress.org — https://wordpress.org/plugins/wpmmcc-ats/
- Companion client repository — https://github.com/wpmmcc/wptsall-client

## Возможности
- Сканирование контента — находит переводимые поля в записях, таксономиях, meta, сторонних контентных плагинах и темах
- Связи сайтов — связывает исходный и целевой языки, включая виртуальные сайты со своим префиксом URL
- Редактор ручного перевода — перевод записей и полей целиком внутри админки WordPress
- Правила перевода — один набор правил для ручного редактора и клиентского API
- Клиентское API Protocol v2 — позволяет WPTSALL Client забирать задачи и записывать результаты
- Языковые пакеты — английский встроен; упрощённый китайский в процессе (см. ниже)

## Требования
- WordPress 6.2 или новее
- PHP 7.4 или новее
- Аккаунт, подписка и лицензионный ключ не нужны

## Установка
1. Скачайте ZIP последней версии со страницы Releases этого репозитория (или упакуйте исходники сами)
2. В wp-admin: Плагины → Добавить новый → Загрузить плагин, загрузите ZIP и активируйте
3. Откройте меню WPMMCC ATS в wp-admin

## Быстрый старт (ручной перевод, клиент не нужен)
1. Просканируйте контентные плагины или создайте набор правил перевода на страницах WPMMCC ATS
2. Добавьте связь сайтов: выберите исходный и целевой язык — виртуальный сайт получит свой префикс URL, например /en_us/
3. Откройте запись в редакторе ручного перевода и переведите её; при сохранении целевой сайт обновится

## Автоматический перевод
Для автоматического перевода установите клиент-компаньон WPTSALL Client (WebUI или Desktop). Он подключается к вашему сайту напрямую с токеном устройства, забирает задачи перевода, вызывает настроенного провайдера и записывает результаты обратно. Репозиторий клиента: https://github.com/wpmmcc/wptsall-client

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
Английский — встроенный исходный язык. Пакет упрощённого китайского (zh_CN) готовится в languages/; после компиляции каталога выберите язык сайта в Настройки → Общие, и WordPress загрузит его автоматически. Чтобы добавить язык, переведите languages/wpmmcc-ats.pot в любом PO-редакторе и внесите вклад.

## License
GPL-2.0-or-later. См. [LICENSE](../LICENSE).


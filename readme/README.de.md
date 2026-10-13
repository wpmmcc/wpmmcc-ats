# WPMMCC ATS — Mehrsprachiges WordPress-Plugin

**WordPress praktisch mehrsprachig: scannen, verknüpfen, übersetzen.**

[English](../README.md) | [简体中文](README.zh-CN.md) | [繁體中文](README.zh-TW.md) | [日本語](README.ja.md) | [한국어](README.ko.md) | [Español](README.es.md) | [Français](README.fr.md) | **Deutsch** | [Português (Brasil)](README.pt-BR.md) | [Italiano](README.it.md) | [Русский](README.ru.md) | [العربية](README.ar.md) | [हिन्दी](README.hi.md) | [Türkçe](README.tr.md) | [Tiếng Việt](README.vi.md) | [Bahasa Indonesia](README.id.md)

WPMMCC ATS ist ein WordPress-Plugin, das eine einsprachige Website in eine mehrsprachige verwandelt. Es durchsucht Content-Plugins, Themes und Menüs nach übersetzbaren Feldern, erstellt wiederverwendbare Übersetzungsregeln, verwaltet Website-Beziehungen zwischen Sprachen und liefert einen vollständigen manuellen Übersetzungs-Editor direkt im wp-admin. Automatisches Übersetzen ist mit dem Begleit-Client WPTSALL Client möglich — ohne Konto, ohne Lizenz, ohne Anbieter-Lock-in.

## Links
- Project website — https://www.wpmm.cc/
- Documentation (English / 简体中文) — https://www.wpmm.cc/docs/
- Getting started — https://www.wpmm.cc/docs/getting-started.html
- This plugin on WordPress.org — https://wordpress.org/plugins/wpmmcc-ats/
- Companion client — https://github.com/wpmmcc/wptsall-client
- Client releases — https://github.com/wpmmcc/wptsall-client/releases
- Client guide — https://www.wpmm.cc/docs/client-guide.html
- WPMMCC sync plugin — https://github.com/wpmmcc/wpmmcc
- WPMMCC on WordPress.org — https://wordpress.org/plugins/wpmmcc/

## Funktionen
- Content-Scan — findet übersetzbare Felder in Beiträgen, Taxonomien, Meta, Content-Plugins von Drittanbietern und Themes
- Website-Beziehungen — verknüpft Quell- und Zielsprache, inklusive virtueller Sites mit eigenem URL-Präfix
- Manueller Übersetzungs-Editor — Beiträge und Felder vollständig im WordPress-Admin übersetzen
- Übersetzungsregeln — ein Regelwerk, gemeinsam vom Editor und der Client-API genutzt
- Protocol-v2-Client-API — WPTSALL Client holt Aufgaben ab und schreibt Ergebnisse zurück
- Sprachpakete — Englisch eingebaut; vereinfachtes Chinesisch in Arbeit (siehe unten)

## A different plugin

WPMMCC (https://github.com/wpmmcc/wpmmcc) is a separate plugin that copies content between sites. Its wp-admin menu is WPMMCC Sync and its REST namespace is wpmmcc/v1. It does not translate. A direct pairing copies content only. The optional WPTSALL Client can also pair with WPMMCC as a sync peer. This plugin's menu is WPTSALL and its REST namespace is wptsall/v2. Directory page: https://wordpress.org/plugins/wpmmcc/ .

## Voraussetzungen
- WordPress 6.2 oder neuer
- PHP 7.4 oder neuer
- Kein Konto, kein Abo, kein Lizenzschlüssel nötig

## Installation
1. Installation über WordPress.org: Plugins → Installieren, nach WPMMCC ATS suchen und aktivieren. Seite: https://wordpress.org/plugins/wpmmcc-ats/
2. In wp-admin unter Plugins → Installieren → Plugin hochladen das ZIP hochladen und aktivieren
3. Öffne das Menü WPTSALL in wp-admin

## Schnellstart (manuelles Übersetzen, ohne Client)
1. Scanne deine Content-Plugins oder erstelle einen Übersetzungsregel-Satz in den WPMMCC-ATS-Adminseiten
2. Lege eine Website-Beziehung an: Quell- und Zielsprache wählen — eine virtuelle Site erhält ein eigenes URL-Präfix wie /en_us/
3. Öffne einen Beitrag im manuellen Übersetzungs-Editor und übersetze ihn; beim Speichern wird die Zielsite aktualisiert

## Automatisches Übersetzen
Für automatisches Übersetzen installiere den Begleit-Client WPTSALL Client (WebUI oder Desktop). Er verbindet sich mit einem Gerätetoken direkt mit deiner Website, holt Übersetzungsaufträge ab, ruft den von dir konfigurierten Anbieter auf und schreibt die Ergebnisse zurück. Client-Repository: https://github.com/wpmmcc/wptsall-client Derselbe Client kann sich auch mit WPMMCC paaren: dann kopiert er nur Inhalte und übersetzt nicht über dieses Plugin. Die WebUI nutzt 127.0.0.1:8977, Desktop lauscht auf 127.0.0.1:8978 und bleibt auf diesem Computer. Anleitung: https://www.wpmm.cc/docs/client-guide.html .

## Aktualisierung
- Updates werden über die Standard-WordPress-Aktualisierung installiert — Dashboard → Aktualisierungen oder die Plugin-Seite. Es sind keine manuellen Schritte erforderlich
- Bei einer Aktualisierung bleiben deine Übersetzungsdaten stets unberührt: Tabellen, virtuelle Websites, Übersetzungsspeicher, Terminologie und Einstellungen bleiben vollständig erhalten
- Versionshinweise für jede Version findest du im Abschnitt Changelog der Datei readme.txt

## Deinstallation
- Deaktiviere das Plugin auf der Plugin-Seite und lösche es anschließend
- Seit Version 2.1.3 bleiben deine Übersetzungsdaten (Tabellen, übersetzte Beiträge/Begriffe, Übersetzungsspeicher, Terminologie, Sprachpakete) beim Löschen standardmäßig erhalten, sodass eine Neuinstallation alles wiederherstellt
- Für eine vollständige Bereinigung aktiviere vor dem Löschen in den Plugin-Einstellungen "Daten bei Deinstallation löschen". Plugin-Einstellungen, Transients und geplante Aufgaben werden in jedem Fall restlos entfernt

## Open-Source-Komponenten
- Kein Drittanbieter-Code gebündelt: reines PHP auf WordPress-Core-APIs (REST, WPDB/dbDelta, cron, gettext), mit Administrationsseiten in Vanilla-JavaScript und dem mit WordPress gelieferten jQuery
- Oberflächenübersetzungen stammen aus dem Übersetzungssystem von WordPress.org (GlotPress) — translate.wordpress.org
- Die Felderkennung liest Daten aus Drittanbieter-Content-Plugins wie WooCommerce, Elementor, ACF und Yoast SEO; diese Projekte sind weder gebündelt noch modifiziert
- Koexistiert reibungslos mit den mehrsprachigen Plugins WPML und Polylang — es wird kein Code aus diesen Projekten verwendet

## Languages
Englisch ist die eingebaute Quellsprache. Ein Paket für vereinfachtes Chinesisch (zh_CN) ist in languages/ in Arbeit; nach dem Kompilieren des Katalogs wird es unter Einstellungen → Allgemein automatisch geladen, sobald du die Website-Sprache wählst. Für weitere Sprachen: languages/wpmmcc-ats.pot mit deinem PO-Editor übersetzen und beitragen.

## License
GPL-2.0-or-later. Siehe [LICENSE](../LICENSE).


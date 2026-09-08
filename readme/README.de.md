# WPMMCC ATS — Mehrsprachiges WordPress-Plugin

**WordPress praktisch mehrsprachig: scannen, verknüpfen, übersetzen.**

[English](../README.md) | [简体中文](README.zh-CN.md) | [繁體中文](README.zh-TW.md) | [日本語](README.ja.md) | [한국어](README.ko.md) | [Español](README.es.md) | [Français](README.fr.md) | **Deutsch** | [Português (Brasil)](README.pt-BR.md) | [Italiano](README.it.md) | [Русский](README.ru.md) | [العربية](README.ar.md) | [हिन्दी](README.hi.md) | [Türkçe](README.tr.md) | [Tiếng Việt](README.vi.md) | [Bahasa Indonesia](README.id.md)

WPMMCC ATS ist ein WordPress-Plugin, das eine einsprachige Website in eine mehrsprachige verwandelt. Es durchsucht Content-Plugins, Themes und Menüs nach übersetzbaren Feldern, erstellt wiederverwendbare Übersetzungsregeln, verwaltet Website-Beziehungen zwischen Sprachen und liefert einen vollständigen manuellen Übersetzungs-Editor direkt im wp-admin. Automatisches Übersetzen ist mit dem Begleit-Client WPTSALL Client möglich — ohne Konto, ohne Lizenz, ohne Anbieter-Lock-in.

## Funktionen
- Content-Scan — findet übersetzbare Felder in Beiträgen, Taxonomien, Meta, Content-Plugins von Drittanbietern und Themes
- Website-Beziehungen — verknüpft Quell- und Zielsprache, inklusive virtueller Sites mit eigenem URL-Präfix
- Manueller Übersetzungs-Editor — Beiträge und Felder vollständig im WordPress-Admin übersetzen
- Übersetzungsregeln — ein Regelwerk, gemeinsam vom Editor und der Client-API genutzt
- Protocol-v2-Client-API — WPTSALL Client holt Aufgaben ab und schreibt Ergebnisse zurück
- Sprachpakete — Englisch eingebaut; vereinfachtes Chinesisch in Arbeit (siehe unten)

## Voraussetzungen
- WordPress 6.2 oder neuer
- PHP 7.4 oder neuer
- Kein Konto, kein Abo, kein Lizenzschlüssel nötig

## Installation
1. Lade das ZIP der neuesten Version von der Releases-Seite dieses Repositorys herunter (oder packe den Quellcode selbst)
2. In wp-admin unter Plugins → Installieren → Plugin hochladen das ZIP hochladen und aktivieren
3. Öffne das WPMMCC-ATS-Menü in wp-admin

## Schnellstart (manuelles Übersetzen, ohne Client)
1. Scanne deine Content-Plugins oder erstelle einen Übersetzungsregel-Satz in den WPMMCC-ATS-Adminseiten
2. Lege eine Website-Beziehung an: Quell- und Zielsprache wählen — eine virtuelle Site erhält ein eigenes URL-Präfix wie /en_us/
3. Öffne einen Beitrag im manuellen Übersetzungs-Editor und übersetze ihn; beim Speichern wird die Zielsite aktualisiert

## Automatisches Übersetzen
Für automatisches Übersetzen installiere den Begleit-Client WPTSALL Client (WebUI oder Desktop). Er verbindet sich mit einem Gerätetoken direkt mit deiner Website, holt Übersetzungsaufträge ab, ruft den von dir konfigurierten Anbieter auf und schreibt die Ergebnisse zurück. Client-Repository: https://github.com/wpmmcc/wptsall-client

## Languages
Englisch ist die eingebaute Quellsprache. Ein Paket für vereinfachtes Chinesisch (zh_CN) ist in languages/ in Arbeit; nach dem Kompilieren des Katalogs wird es unter Einstellungen → Allgemein automatisch geladen, sobald du die Website-Sprache wählst. Für weitere Sprachen: languages/wpmmcc-ats.pot mit deinem PO-Editor übersetzen und beitragen.

## License
GPL-2.0-or-later. Siehe [LICENSE](../LICENSE).


# WPMMCC ATS — Plugin WordPress multilingue

**WordPress multilingue, in pratica: scansione, collegamento, traduzione.**

[English](../README.md) | [简体中文](README.zh-CN.md) | [繁體中文](README.zh-TW.md) | [日本語](README.ja.md) | [한국어](README.ko.md) | [Español](README.es.md) | [Français](README.fr.md) | [Deutsch](README.de.md) | [Português (Brasil)](README.pt-BR.md) | **Italiano** | [Русский](README.ru.md) | [العربية](README.ar.md) | [हिन्दी](README.hi.md) | [Türkçe](README.tr.md) | [Tiếng Việt](README.vi.md) | [Bahasa Indonesia](README.id.md)

WPMMCC ATS è un plugin WordPress che trasforma un sito monolingue in un sito multilingue. Analizza plugin di contenuto, temi e menu per individuare i campi traducibili, crea regole di traduzione riutilizzabili, gestisce le relazioni tra siti e lingue e offre un editor completo di traduzione manuale dentro wp-admin. La traduzione automatica è disponibile con il client companion WPTSALL Client: senza account, senza licenza e senza vendor lock-in.

## Links
- Project website — https://www.wpmm.cc/
- Documentation and usage help (English / 简体中文) — https://www.wpmm.cc/docs/
- This plugin on WordPress.org — https://wordpress.org/plugins/wpmmcc-ats/
- Companion client repository — https://github.com/wpmmcc/wptsall-client

## Caratteristiche
- Scansione dei contenuti — rileva i campi traducibili in articoli, tassonomie, meta, plugin di contenuto di terze parti e temi
- Relazioni tra siti — collega lingua di origine e di destinazione, con siti virtuali e prefisso URL dedicato
- Editor di traduzione manuale — traduci articoli e campi interamente nell'amministrazione di WordPress
- Regole di traduzione — un unico set di regole condiviso da editor manuale e API client
- API client Protocol v2 — consente a WPTSALL Client di prelevare le attività e riscrivere i risultati
- Pacchetti lingua — inglese integrato; cinese semplificato in corso (vedi sotto)

## Requisiti
- WordPress 6.2 o successivo
- PHP 7.4 o successivo
- Nessun account, abbonamento o chiave di licenza richiesto

## Installazione
1. Scarica lo ZIP dell'ultima versione dalla pagina Releases di questo repository (oppure comprimi tu il codice sorgente)
2. In wp-admin vai su Plugin → Aggiungi nuovo → Carica plugin, carica lo ZIP e attiva
3. Apri il menu WPMMCC ATS in wp-admin

## Avvio rapido (traduzione manuale, senza client)
1. Analizza i tuoi plugin di contenuto o crea un set di regole dalle pagine di amministrazione di WPMMCC ATS
2. Aggiungi una relazione tra siti: scegli lingua di origine e di destinazione — un sito virtuale riceve un prefisso URL dedicato, es. /en_us/
3. Apri un articolo nell'editor di traduzione manuale e traducilo; al salvataggio il sito di destinazione si aggiorna

## Traduzione automatica
Per la traduzione automatica installa il client companion WPTSALL Client (WebUI o Desktop). Si collega direttamente al tuo sito con un token di dispositivo, preleva le attività di traduzione, chiama il provider configurato e riscrive i risultati. Repository del client: https://github.com/wpmmcc/wptsall-client

## Aggiornamento
- Gli aggiornamenti si installano tramite l'aggiornatore standard di WordPress — Bacheca → Aggiornamenti, o dalla pagina Plugin. Non sono richiesti passaggi manuali
- L'aggiornamento non tocca mai i tuoi dati di traduzione: tabelle, siti virtuali, memoria di traduzione, terminologia e impostazioni vengono tutti mantenuti
- Le note di rilascio per ogni versione si trovano nella sezione Changelog del file readme.txt

## Disinstallazione
- Disattiva il plugin nella pagina Plugin, quindi eliminalo
- Dalla versione 2.1.3, l'eliminazione del plugin conserva per impostazione predefinita i dati di traduzione (tabelle, articoli/termini tradotti, memoria di traduzione, terminologia, pacchetti lingua), quindi una reinstallazione ripristina tutto
- Per una pulizia completa, attiva "Elimina i dati alla disinstallazione" nelle impostazioni del plugin prima di eliminarlo. Le impostazioni del plugin, i transient e le attività pianificate vengono sempre rimossi in entrambi i casi

## Componenti open source
- Nessun codice di terze parti è incluso: puro PHP sulle API core di WordPress (REST, WPDB/dbDelta, cron, gettext), con pagine di amministrazione in JavaScript nativo e jQuery fornito da WordPress
- Le traduzioni dell'interfaccia provengono dal sistema di traduzione di WordPress.org (GlotPress) — translate.wordpress.org
- Il rilevamento dei campi legge dati da plugin di contenuto di terze parti come WooCommerce, Elementor, ACF e Yoast SEO; tali progetti non sono né inclusi né modificati
- Coesiste senza problemi con i plugin multilingue WPML e Polylang — non viene utilizzato codice di nessuno dei due progetti

## Languages
L'inglese è la lingua sorgente integrata. Un pacchetto cinese semplificato (zh_CN) è in corso in languages/; una volta compilato il catalogo, scegli la lingua del sito in Impostazioni → Generali e WordPress lo carica automaticamente. Per aggiungere un'altra lingua, traduci languages/wpmmcc-ats.pot con il tuo editor PO preferito e contribuisci.

## License
GPL-2.0-or-later. Vedi [LICENSE](../LICENSE).


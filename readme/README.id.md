# WPMMCC ATS — Plugin WordPress Multibahasa

**WordPress multibahasa yang praktis: pindai, hubungkan, terjemahkan.**

[English](../README.md) | [简体中文](README.zh-CN.md) | [繁體中文](README.zh-TW.md) | [日本語](README.ja.md) | [한국어](README.ko.md) | [Español](README.es.md) | [Français](README.fr.md) | [Deutsch](README.de.md) | [Português (Brasil)](README.pt-BR.md) | [Italiano](README.it.md) | [Русский](README.ru.md) | [العربية](README.ar.md) | [हिन्दी](README.hi.md) | [Türkçe](README.tr.md) | [Tiếng Việt](README.vi.md) | **Bahasa Indonesia**

WPMMCC ATS adalah plugin WordPress yang mengubah situs satu bahasa menjadi multibahasa. Ia memindai plugin konten, tema, dan menu untuk menemukan bidang yang dapat diterjemahkan, membangun aturan terjemahan yang dapat dipakai ulang, mengelola relasi situs antarbahasa, dan menyediakan editor terjemahan manual lengkap di dalam wp-admin. Terjemahan otomatis tersedia dengan klien pendamping WPTSALL Client — tanpa akun, tanpa lisensi, tanpa kunci vendor.

## Links
- Project website — https://www.wpmm.cc/
- Documentation and usage help (English / 简体中文) — https://www.wpmm.cc/docs/
- This plugin on WordPress.org — https://wordpress.org/plugins/wpmmcc-ats/
- Companion client repository — https://github.com/wpmmcc/wptsall-client

## Fitur
- Pemindaian konten — mendeteksi bidang terjemahan di pos, taksonomi, meta, plugin konten pihak ketiga, dan tema
- Relasi situs — menghubungkan bahasa sumber dan target, termasuk situs virtual dengan prefiks URL sendiri
- Editor terjemahan manual — terjemahkan pos dan bidang sepenuhnya di dalam admin WordPress
- Aturan terjemahan — satu set aturan yang dipakai bersama editor manual dan API klien
- API klien Protocol v2 — memungkinkan WPTSALL Client mengambil tugas dan menulis hasilnya kembali
- Paket bahasa — Inggris bawaan; Mandarin sederhana sedang berjalan (lihat di bawah)

## Persyaratan
- WordPress 6.2 atau lebih baru
- PHP 7.4 atau lebih baru
- Tanpa akun, langganan, atau kunci lisensi

## Instalasi
1. Unduh ZIP rilis terbaru dari halaman Releases repositori ini (atau zip sendiri dari kode sumber)
2. Di wp-admin buka Plugins → Add New → Upload Plugin, unggah ZIP lalu aktifkan
3. Buka menu WPMMCC ATS di wp-admin

## Mulai cepat (terjemahan manual, tanpa klien)
1. Pindai plugin konten Anda atau buat set aturan terjemahan dari halaman admin WPMMCC ATS
2. Tambahkan relasi situs: pilih bahasa sumber dan target — situs virtual mendapat prefiks URL sendiri, mis. /en_us/
3. Buka sebuah pos di editor terjemahan manual dan terjemahkan; saat disimpan situs target ikut diperbarui

## Terjemahan otomatis
Untuk terjemahan otomatis, pasang klien pendamping WPTSALL Client (WebUI atau Desktop). Ia terhubung langsung ke situs Anda dengan token perangkat, mengambil tugas terjemahan, memanggil penyedia yang Anda konfigurasi, dan menulis hasilnya kembali. Repositori klien: https://github.com/wpmmcc/wptsall-client

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
Bahasa Inggris adalah bahasa sumber bawaan. Paket Mandarin sederhana (zh_CN) sedang dikerjakan di languages/; setelah katalog dikompilasi, pilih bahasa situs di Settings → General dan WordPress memuatnya otomatis. Untuk menambah bahasa lain, terjemahkan languages/wpmmcc-ats.pot dengan editor PO favorit Anda dan kontribusikan.

## License
GPL-2.0-or-later. Lihat [LICENSE](../LICENSE).


---

> This translation is an initial draft; corrections and improvements via pull requests are welcome.

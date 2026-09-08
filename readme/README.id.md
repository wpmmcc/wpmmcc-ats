# WPMMCC ATS — Plugin WordPress Multibahasa

**WordPress multibahasa yang praktis: pindai, hubungkan, terjemahkan.**

[English](../README.md) | [简体中文](README.zh-CN.md) | [繁體中文](README.zh-TW.md) | [日本語](README.ja.md) | [한국어](README.ko.md) | [Español](README.es.md) | [Français](README.fr.md) | [Deutsch](README.de.md) | [Português (Brasil)](README.pt-BR.md) | [Italiano](README.it.md) | [Русский](README.ru.md) | [العربية](README.ar.md) | [हिन्दी](README.hi.md) | [Türkçe](README.tr.md) | [Tiếng Việt](README.vi.md) | **Bahasa Indonesia**

WPMMCC ATS adalah plugin WordPress yang mengubah situs satu bahasa menjadi multibahasa. Ia memindai plugin konten, tema, dan menu untuk menemukan bidang yang dapat diterjemahkan, membangun aturan terjemahan yang dapat dipakai ulang, mengelola relasi situs antarbahasa, dan menyediakan editor terjemahan manual lengkap di dalam wp-admin. Terjemahan otomatis tersedia dengan klien pendamping WPTSALL Client — tanpa akun, tanpa lisensi, tanpa kunci vendor.

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

## Languages
Bahasa Inggris adalah bahasa sumber bawaan. Paket Mandarin sederhana (zh_CN) sedang dikerjakan di languages/; setelah katalog dikompilasi, pilih bahasa situs di Settings → General dan WordPress memuatnya otomatis. Untuk menambah bahasa lain, terjemahkan languages/wpmmcc-ats.pot dengan editor PO favorit Anda dan kontribusikan.

## License
GPL-2.0-or-later. Lihat [LICENSE](../LICENSE).


---

> This translation is an initial draft; corrections and improvements via pull requests are welcome.

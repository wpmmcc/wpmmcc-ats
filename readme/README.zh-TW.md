# WPMMCC ATS — WordPress 多語系外掛

**讓 WordPress 多語系化,實用為先:掃描、關聯、翻譯。**

[English](../README.md) | [简体中文](README.zh-CN.md) | **繁體中文** | [日本語](README.ja.md) | [한국어](README.ko.md) | [Español](README.es.md) | [Français](README.fr.md) | [Deutsch](README.de.md) | [Português (Brasil)](README.pt-BR.md) | [Italiano](README.it.md) | [Русский](README.ru.md) | [العربية](README.ar.md) | [हिन्दी](README.hi.md) | [Türkçe](README.tr.md) | [Tiếng Việt](README.vi.md) | [Bahasa Indonesia](README.id.md)

WPMMCC ATS 是一個把單語 WordPress 網站變成多語系網站的外掛。它掃描內容外掛、佈景主題與選單中的可翻譯欄位,產生可重用的翻譯規則,管理語言之間的站台關聯,並在 wp-admin 內提供完整的手動翻譯編輯器。搭配獨立的 WPTSALL 用戶端可實現自動翻譯——無需帳號、無需授權、沒有廠商綁定。

## 相關連結
- 專案官網 — https://www.wpmm.cc/
- 文件與使用說明(English / 簡體中文)— https://www.wpmm.cc/docs/
- WordPress.org 外掛頁 — https://wordpress.org/plugins/wpmmcc-ats/
- 配套用戶端儲存庫 — https://github.com/wpmmcc/wptsall-client

## 功能
- 內容掃描——偵測文章、分類法、meta、第三方內容外掛與佈景主題中的可翻譯欄位
- 站台關聯——關聯來源語言與目標語言,支援帶獨立 URL 前綴的虛擬站台
- 手動翻譯編輯器——完全在 WordPress 後台內完成文章與欄位翻譯
- 翻譯規則——手動編輯器與用戶端 API 共用同一套規則
- Protocol v2 用戶端 API——供 WPTSALL 用戶端領取任務並寫回結果
- 語言包——英文內建;簡體中文包翻譯進行中(見下文)

## 環境需求
- WordPress 6.2 或更新版本
- PHP 7.4 或更新版本
- 無需帳號、訂閱或授權金鑰

## 安裝
1. 從本儲存庫 Releases 頁下載最新 ZIP(或自行打包原始碼)
2. 在 wp-admin 進入 外掛 → 安裝外掛 → 上傳外掛,上傳 ZIP 後啟用
3. 在 wp-admin 開啟 WPMMCC ATS 選單

## 快速上手(手動翻譯,無需用戶端)
1. 從 WPMMCC ATS 管理頁掃描內容外掛或建立翻譯規則
2. 新增站台關聯:選擇來源語言與目標語言——虛擬站台會獲得獨立 URL 前綴(如 /en_us/)
3. 在手動翻譯編輯器中開啟一篇文章並翻譯;儲存後目標站台隨之更新

## 自動翻譯
自動翻譯請安裝配套的 WPTSALL 用戶端(WebUI 或 Desktop)。它使用裝置級權杖直連你的網站,領取翻譯任務,呼叫你設定的翻譯廠商,並把結果寫回。用戶端儲存庫:https://github.com/wpmmcc/wptsall-client

## Languages
英文為內建來源語言。簡體中文(zh_CN)語言包正在 languages/ 目錄推進翻譯;訊息目錄編譯完成後,在 設定 → 一般 選擇網站語言即可自動載入。要新增語言,請用常用的 PO 編輯器翻譯 languages/wpmmcc-ats.pot 並歡迎回饋貢獻。

## License
GPL-2.0-or-later,見 [LICENSE](../LICENSE)。


# WPMMCC ATS — Plugin WordPress đa ngôn ngữ

**WordPress đa ngôn ngữ, thực tế: quét, liên kết, dịch.**

[English](../README.md) | [简体中文](README.zh-CN.md) | [繁體中文](README.zh-TW.md) | [日本語](README.ja.md) | [한국어](README.ko.md) | [Español](README.es.md) | [Français](README.fr.md) | [Deutsch](README.de.md) | [Português (Brasil)](README.pt-BR.md) | [Italiano](README.it.md) | [Русский](README.ru.md) | [العربية](README.ar.md) | [हिन्दी](README.hi.md) | [Türkçe](README.tr.md) | **Tiếng Việt** | [Bahasa Indonesia](README.id.md)

WPMMCC ATS là plugin WordPress biến một site đơn ngữ thành site đa ngôn ngữ. Nó quét các plugin nội dung, theme và menu để tìm các trường có thể dịch, tạo quy tắc dịch dùng lại được, quản lý quan hệ site giữa các ngôn ngữ, và cung cấp trình soạn thảo dịch thủ công đầy đủ ngay trong wp-admin. Dịch tự động khả dụng với client đi kèm WPTSALL Client — không cần tài khoản, không giấy phép, không ràng buộc nhà cung cấp.

## Links
- Project website — https://www.wpmm.cc/
- Documentation and usage help (English / 简体中文) — https://www.wpmm.cc/docs/
- This plugin on WordPress.org — https://wordpress.org/plugins/wpmmcc-ats/
- Companion client repository — https://github.com/wpmmcc/wptsall-client

## Tính năng
- Quét nội dung — phát hiện trường dịch được trong bài viết, taxonomy, meta, plugin nội dung bên thứ ba và theme
- Quan hệ site — liên kết ngôn ngữ nguồn và đích, gồm site ảo với tiền tố URL riêng
- Trình soạn thảo dịch thủ công — dịch bài viết và trường hoàn toàn trong quản trị WordPress
- Quy tắc dịch — một bộ quy tắc dùng chung cho trình soạn thảo thủ công và API client
- API client Protocol v2 — cho phép WPTSALL Client nhận tác vụ và ghi kết quả về
- Gói ngôn ngữ — tiếng Anh tích hợp; tiếng Trung giản thể đang tiến hành (xem dưới)

## Yêu cầu
- WordPress 6.2 trở lên
- PHP 7.4 trở lên
- Không cần tài khoản, thuê bao hay khóa giấy phép

## Cài đặt
1. Tải ZIP bản mới nhất từ trang Releases của repo này (hoặc tự nén mã nguồn)
2. Trong wp-admin vào Plugins → Add New → Upload Plugin, tải ZIP lên rồi kích hoạt
3. Mở menu WPMMCC ATS trong wp-admin

## Bắt đầu nhanh (dịch thủ công, không cần client)
1. Quét các plugin nội dung hoặc tạo bộ quy tắc dịch từ trang quản trị WPMMCC ATS
2. Thêm quan hệ site: chọn ngôn ngữ nguồn và đích — site ảo có tiền tố URL riêng, ví dụ /en_us/
3. Mở một bài trong trình soạn thảo dịch thủ công và dịch; khi lưu, site đích được cập nhật

## Dịch tự động
Để dịch tự động, hãy cài client đi kèm WPTSALL Client (WebUI hoặc Desktop). Nó kết nối trực tiếp tới site của bạn bằng token thiết bị, nhận tác vụ dịch, gọi nhà cung cấp bạn cấu hình và ghi kết quả về. Repo client: https://github.com/wpmmcc/wptsall-client

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
Tiếng Anh là ngôn ngữ gốc tích hợp. Gói tiếng Trung giản thể (zh_CN) đang tiến hành trong languages/; sau khi biên dịch catalog, chọn ngôn ngữ site trong Settings → General và WordPress tự nạp. Để thêm ngôn ngữ khác, hãy dịch languages/wpmmcc-ats.pot bằng trình soạn PO ưa thích và đóng góp.

## License
GPL-2.0-or-later. Xem [LICENSE](../LICENSE).


---

> This translation is an initial draft; corrections and improvements via pull requests are welcome.

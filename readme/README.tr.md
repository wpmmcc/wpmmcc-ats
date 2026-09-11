# WPMMCC ATS — Çok Dilli WordPress Eklentisi

**WordPress'i pratik biçimde çok dilli yapın: tara, ilişkilendir, çevir.**

[English](../README.md) | [简体中文](README.zh-CN.md) | [繁體中文](README.zh-TW.md) | [日本語](README.ja.md) | [한국어](README.ko.md) | [Español](README.es.md) | [Français](README.fr.md) | [Deutsch](README.de.md) | [Português (Brasil)](README.pt-BR.md) | [Italiano](README.it.md) | [Русский](README.ru.md) | [العربية](README.ar.md) | [हिन्दी](README.hi.md) | **Türkçe** | [Tiếng Việt](README.vi.md) | [Bahasa Indonesia](README.id.md)

WPMMCC ATS, tek dilli bir siteyi çok dilli bir siteye dönüştüren bir WordPress eklentisidir. İçerik eklentilerini, temaları ve menüleri tarayarak çevrilebilir alanları bulur, yeniden kullanılabilir çeviri kuralları oluşturur, diller arası site ilişkilerini yönetir ve wp-admin içinde eksiksiz bir manuel çeviri editörü sunar. Otomatik çeviri için companion istemci WPTSALL Client ile mümkündür — hesap yok, lisans yok, satıcı kilidi yok.

## Links
- Project website — https://www.wpmm.cc/
- Documentation and usage help (English / 简体中文) — https://www.wpmm.cc/docs/
- This plugin on WordPress.org — https://wordpress.org/plugins/wpmmcc-ats/
- Companion client repository — https://github.com/wpmmcc/wptsall-client

## Özellikler
- İçerik tarama — yazılarda, taksonomilerde, meta verilerde, üçüncü taraf içerik eklentilerinde ve temalarda çevrilebilir alanları tespit eder
- Site ilişkileri — kaynak ve hedef dili bağlar; kendi URL önekine sahip sanal siteleri destekler
- Manuel çeviri editörü — yazı ve alan çevirisini tamamen WordPress yönetim panelinde yapın
- Çeviri kuralları — manuel editör ve istemci API'si aynı kural setini paylaşır
- Protocol v2 istemci API'si — WPTSALL Client görevleri alır ve sonuçları geri yazar
- Dil paketleri — İngilizce gömülü; Basitleştirilmiş Çince sürüyor (aşağıya bakın)

## Gereksinimler
- WordPress 6.2 veya daha yenisi
- PHP 7.4 veya daha yenisi
- Hesap, abonelik veya lisans anahtarı gerekmez

## Kurulum
1. Bu depodun Releases sayfasından en son ZIP'i indirin (veya kaynağı kendiniz zip'leyin)
2. wp-admin'de Eklentiler → Yeni Ekle → Eklenti Yükle ile ZIP'i yükleyip etkinleştirin
3. wp-admin'de WPMMCC ATS menüsünü açın

## Hızlı başlangıç (manuel çeviri, istemci gerekmez)
1. WPMMCC ATS yönetim sayfalarından içerik eklentilerinizi tarayın veya bir çeviri kural seti oluşturun
2. Site ilişkisi ekleyin: kaynak ve hedef dili seçin — sanal site /en_us/ gibi kendi URL önekini alır
3. Manuel çeviri editöründe bir yazıyı açıp çevirin; kaydettiğinizde hedef site güncellenir

## Otomatik çeviri
Otomatik çeviri için companion istemci WPTSALL Client'ı (WebUI veya Desktop) kurun. Cihaz token'ı ile sitenize doğrudan bağlanır, çeviri görevlerini alır, yapılandırdığınız sağlayıcıyı çağırır ve sonuçları geri yazar. İstemci deposu: https://github.com/wpmmcc/wptsall-client

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
İngilizce gömülü kaynak dildir. Basitleştirilmiş Çince (zh_CN) paketi languages/ içinde sürüyor; katalog derlendikten sonra Ayarlar → Genel'den site dilini seçin, WordPress otomatik yükler. Başka bir dil eklemek için languages/wpmmcc-ats.pot dosyasını sevdiğiniz PO editörüyle çevirip katkı verin.

## License
GPL-2.0-or-later. Bakınız [LICENSE](../LICENSE).


---

> This translation is an initial draft; corrections and improvements via pull requests are welcome.

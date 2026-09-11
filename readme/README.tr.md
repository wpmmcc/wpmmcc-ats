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

## Güncelleme
- Güncellemeler standart WordPress güncelleyicisi aracılığıyla kurulur — Başlangıç → Güncellemeler veya Eklentiler sayfası. Manuel bir işlem gerekmez
- Güncelleme çeviri verilerinize asla dokunmaz: tablolar, sanal siteler, çeviri belleği, terminoloji ve ayarların tümü korunur
- Her sürümün sürüm notları readme.txt dosyasının Changelog bölümünde yer almaktadır

## Kaldırma
- Eklentiyi Eklentiler sayfasından etkisizleştirin ve ardından silin
- 2.1.3 sürümünden itibaren, eklentiyi silmek çeviri verilerinizi varsayılan olarak korur (tablolar, çevrilmiş yazılar/terimler, çeviri belleği, terminoloji, dil paketleri); bu sayede yeniden kurulum her şeyi geri yükler
- Tam bir temizlik için, silmeden önce eklenti ayarlarından "Kaldırmada verileri sil" seçeneğini etkinleştirin. Eklenti ayarları, transient veriler ve zamanlanmış görevler her iki durumda da tamamen kaldırılır

## Açık kaynaklı bileşenler
- Hiçbir üçüncü taraf kod paketlenmemiştir: WordPress çekirdek API'leri (REST, WPDB/dbDelta, cron, gettext) üzerinde saf PHP ve WordPress ile birlikte gelen saf JavaScript ve jQuery ile oluşturulmuş yönetim sayfaları
- Arayüz çevirileri WordPress.org çeviri sisteminden (GlotPress) gelir — translate.wordpress.org
- Alan algılama, WooCommerce, Elementor, ACF ve Yoast SEO gibi üçüncü taraf içerik eklentilerinden veri okur; bu projeler paketlenmez veya değiştirilmez
- Çok dilli eklentiler WPML ve Polylang ile sorunsuz bir arada çalışır — her iki projeden de hiçbir kod kullanılmaz

## Languages
İngilizce gömülü kaynak dildir. Basitleştirilmiş Çince (zh_CN) paketi languages/ içinde sürüyor; katalog derlendikten sonra Ayarlar → Genel'den site dilini seçin, WordPress otomatik yükler. Başka bir dil eklemek için languages/wpmmcc-ats.pot dosyasını sevdiğiniz PO editörüyle çevirip katkı verin.

## License
GPL-2.0-or-later. Bakınız [LICENSE](../LICENSE).


---

> This translation is an initial draft; corrections and improvements via pull requests are welcome.

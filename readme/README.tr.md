# WPMMCC ATS — Çok Dilli WordPress Eklentisi

**WordPress'i pratik biçimde çok dilli yapın: tara, ilişkilendir, çevir.**

[English](../README.md) | [简体中文](README.zh-CN.md) | [繁體中文](README.zh-TW.md) | [日本語](README.ja.md) | [한국어](README.ko.md) | [Español](README.es.md) | [Français](README.fr.md) | [Deutsch](README.de.md) | [Português (Brasil)](README.pt-BR.md) | [Italiano](README.it.md) | [Русский](README.ru.md) | [العربية](README.ar.md) | [हिन्दी](README.hi.md) | **Türkçe** | [Tiếng Việt](README.vi.md) | [Bahasa Indonesia](README.id.md)

WPMMCC ATS, tek dilli bir siteyi çok dilli bir siteye dönüştüren bir WordPress eklentisidir. İçerik eklentilerini, temaları ve menüleri tarayarak çevrilebilir alanları bulur, yeniden kullanılabilir çeviri kuralları oluşturur, diller arası site ilişkilerini yönetir ve wp-admin içinde eksiksiz bir manuel çeviri editörü sunar. Otomatik çeviri için companion istemci WPTSALL Client ile mümkündür — hesap yok, lisans yok, satıcı kilidi yok.

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

## Özellikler
- İçerik tarama — yazılarda, taksonomilerde, meta verilerde, üçüncü taraf içerik eklentilerinde ve temalarda çevrilebilir alanları tespit eder
- Site ilişkileri — kaynak ve hedef dili bağlar; kendi URL önekine sahip sanal siteleri destekler
- Manuel çeviri editörü — yazı ve alan çevirisini tamamen WordPress yönetim panelinde yapın
- Çeviri kuralları — manuel editör ve istemci API'si aynı kural setini paylaşır
- Protocol v2 istemci API'si — WPTSALL Client görevleri alır ve sonuçları geri yazar
- Dil paketleri — İngilizce gömülü; Basitleştirilmiş Çince sürüyor (aşağıya bakın)

## A different plugin

WPMMCC (https://github.com/wpmmcc/wpmmcc) is a separate plugin that copies content between sites. Its wp-admin menu is WPMMCC Sync and its REST namespace is wpmmcc/v1. A direct pairing copies content only, and that form rejects sync_and_translate. The optional WPTSALL Client can pair with WPMMCC and translate the title, content, and excerpt while syncing. This plugin's menu is WPTSALL and its REST namespace is wptsall/v2. Directory page: https://wordpress.org/plugins/wpmmcc/ .

## Gereksinimler
- WordPress 6.2 veya daha yenisi
- PHP 7.4 veya daha yenisi
- Hesap, abonelik veya lisans anahtarı gerekmez

## Kurulum
1. WordPress.org üzerinden kurun: Eklentiler → Yeni Ekle, WPMMCC ATS arayıp etkinleştirin. Sayfa: https://wordpress.org/plugins/wpmmcc-ats/
2. wp-admin'de Eklentiler → Yeni Ekle → Eklenti Yükle ile ZIP'i yükleyip etkinleştirin
3. wp-admin'de WPTSALL menüsünü açın

## Hızlı başlangıç (manuel çeviri, istemci gerekmez)
1. WPMMCC ATS yönetim sayfalarından içerik eklentilerinizi tarayın veya bir çeviri kural seti oluşturun
2. Site ilişkisi ekleyin: kaynak ve hedef dili seçin — sanal site /en_us/ gibi kendi URL önekini alır
3. Manuel çeviri editöründe bir yazıyı açıp çevirin; kaydettiğinizde hedef site güncellenir

## Otomatik çeviri
Otomatik çeviri için companion istemci WPTSALL Client'ı (WebUI veya Desktop) kurun. Cihaz token'ı ile sitenize doğrudan bağlanır, çeviri görevlerini alır, yapılandırdığınız sağlayıcıyı çağırır ve sonuçları geri yazar. İstemci deposu: https://github.com/wpmmcc/wptsall-client Aynı istemci WPMMCC ile de eşleşebilir. Doğrudan eşleme yalnızca eşitler ve bu form sync_and_translate isteğini reddeder. İstemci üzerinden başlık, içerik ve özet göndermeden önce çevrilir. Eklenti çeviri sağlayıcısını çağırmaz. WebUI 127.0.0.1:8977 kullanır, Desktop 127.0.0.1:8978 adresini dinler ve bu bilgisayarda kalır. Kılavuz: https://www.wpmm.cc/docs/client-guide.html .

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

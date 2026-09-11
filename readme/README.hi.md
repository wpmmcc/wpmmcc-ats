# WPMMCC ATS — बहुभाषी WordPress प्लगइन

**WordPress को व्यावहारिक बनाए बहुभाषी: स्कैन, जोड़ें, अनुवाद करें।**

[English](../README.md) | [简体中文](README.zh-CN.md) | [繁體中文](README.zh-TW.md) | [日本語](README.ja.md) | [한국어](README.ko.md) | [Español](README.es.md) | [Français](README.fr.md) | [Deutsch](README.de.md) | [Português (Brasil)](README.pt-BR.md) | [Italiano](README.it.md) | [Русский](README.ru.md) | [العربية](README.ar.md) | **हिन्दी** | [Türkçe](README.tr.md) | [Tiếng Việt](README.vi.md) | [Bahasa Indonesia](README.id.md)

WPMMCC ATS एक WordPress प्लगइन है जो एकभाषी साइट को बहुभाषी साइट में बदलता है। यह कंटेंट प्लगइन, थीम और मेन्यू में अनुवाद योग्य फ़ील्ड स्कैन करता है, पुनः उपयोगी अनुवाद नियम बनाता है, भाषाओं के बीच साइट संबंध प्रबंधित करता है, और wp-admin के भीतर पूर्ण मैनुअल अनुवाद संपादक देता है। स्वचालित अनुवाद के लिए साथी क्लाइंट WPTSALL Client उपलब्ध है — बिना खाते, बिना लाइसेंस, बिना वेंडर लॉक-इन।

## Links
- Project website — https://www.wpmm.cc/
- Documentation and usage help (English / 简体中文) — https://www.wpmm.cc/docs/
- This plugin on WordPress.org — https://wordpress.org/plugins/wpmmcc-ats/
- Companion client repository — https://github.com/wpmmcc/wptsall-client

## विशेषताएँ
- कंटेंट स्कैन — पोस्ट, टैक्सोनॉमी, meta, थर्ड-पार्टी कंटेंट प्लगइन और थीम के अनुवाद योग्य फ़ील्ड पहचानता है
- साइट संबंध — स्रोत और लक्ष्य भाषा जोड़ता है, अपने URL प्रीफ़िक्स वाली वर्चुअल साइट सहित
- मैनुअल अनुवाद संपादक — पोस्ट और फ़ील्ड का अनुवाद पूरी तरह WordPress एडमिन में करें
- अनुवाद नियम — मैनुअल संपादक और क्लाइंट API के लिए एक ही नियम सेट
- Protocol v2 क्लाइंट API — WPTSALL Client कार्य लेकर परिणाम वापस लिखता है
- भाषा पैक — अंग्रेज़ी अंतर्निहित; सरलीकृत चीनी प्रगति पर (नीचे देखें)

## आवश्यकताएँ
- WordPress 6.2 या नया
- PHP 7.4 या नया
- कोई खाता, सदस्यता या लाइसेंस कुंजी आवश्यक नहीं

## स्थापना
1. इस रिपॉज़िटरी के Releases पृष्ठ से नवीनतम ZIP डाउनलोड करें (या स्रोत से स्वयं zip बनाएँ)
2. wp-admin में Plugins → Add New → Upload Plugin से ZIP अपलोड कर सक्रिय करें
3. wp-admin में WPMMCC ATS मेन्यू खोलें

## त्वरित शुरुआत (मैनुअल अनुवाद, क्लाइंट की आवश्यकता नहीं)
1. WPMMCC ATS एडमिन पृष्ठों से कंटेंट प्लगइन स्कैन करें या अनुवाद नियम सेट बनाएँ
2. साइट संबंध जोड़ें: स्रोत और लक्ष्य भाषा चुनें — वर्चुअल साइट को अपना URL प्रीफ़िक्स मिलता है जैसे /en_us/
3. मैनुअल अनुवाद संपादक में पोस्ट खोलकर अनुवाद करें; सहेजने पर लक्ष्य साइट अद्यतन होती है

## स्वचालित अनुवाद
स्वचालित अनुवाद के लिए साथी क्लाइंट WPTSALL Client (WebUI या Desktop) स्थापित करें। यह डिवाइस टोकन से आपकी साइट से सीधे जुड़ता है, अनुवाद कार्य लेता है, आपके विन्यस्त प्रोवाइडर को कॉल करता है और परिणाम वापस लिखता है। क्लाइंट रिपॉज़िटरी: https://github.com/wpmmcc/wptsall-client

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
अंग्रेज़ी अंतर्निहित स्रोत भाषा है। सरलीकृत चीनी (zh_CN) पैक languages/ में प्रगति पर है; कैटलॉग संकलित होने पर Settings → General में साइट की भाषा चुनें, WordPress इसे स्वतः लोड करेगा। नई भाषा जोड़ने के लिए languages/wpmmcc-ats.pot को अपने PO संपादक से अनुवाद करके योगदान दें।

## License
GPL-2.0-or-later। [LICENSE](../LICENSE) देखें।


---

> This translation is an initial draft; corrections and improvements via pull requests are welcome.

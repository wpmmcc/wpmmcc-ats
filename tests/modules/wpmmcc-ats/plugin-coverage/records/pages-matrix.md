# Pages Matrix (虚拟站全页面类型交付)

- date: 2026-08-13
- status: **passed**  (20 checks)
- plugins: wpmmcc-ats + portfolio-post-type + autodescription(TSF)

| check | result | detail |
|---|---|---|
| fixture_terms | ✅ | {} |
| fixture_shadows_tagged | ✅ | {} |
| fixture_page_translation | ✅ | {} |
| fixture_zh_translation | ✅ | {} |
| fixture_cpt | ✅ | {} |
| virtual_home | ✅ | {"code": 200, "title": "WPTSALL Blog Lab", "virtual_class": true} |
| virtual_home_paged2 | ✅ | {"code": 200, "title": "WPTSALL Blog Lab - Page 2", "virtual_class": true} |
| virtual_category | ✅ | {"code": 200, "title": "Category: COV Cat pages-b8b58153 - WPTSALL Blog Lab", "virtual_class": true} |
| virtual_category_paged2 | ✅ | {"code": 200, "title": "Category: COV Cat pages-b8b58153 - Page 2 - WPTSALL Blog Lab", "virtual_class": true} |
| virtual_tag | ✅ | {"code": 200, "title": "Tag: COV Tag pages-b8b58153 - WPTSALL Blog Lab", "virtual_class": true} |
| virtual_404_negative | ✅ | {"code": 404, "title": "Page not found - WPTSALL Blog Lab"} |
| virtual_page | ✅ | {"code": 200, "title": "COV EN Page pages-b8b58153 - WPTSALL Blog Lab", "hreflang": true, "canonical": true, "canonical_url": "http://192.168.1.14:9081/en-us/co |
| virtual_post | ✅ | {"code": 200, "title": "COV EN autodescription - WPTSALL Blog Lab", "hreflang": true, "canonical": true, "canonical_url": "http://192.168.1.14:9081/en-us/blog/2 |
| virtual_cpt | ✅ | {"code": 200, "title": "COV EN CPT pages-b8b58153 - WPTSALL Blog Lab", "hreflang": true, "canonical": true, "canonical_url": "http://192.168.1.14:9081/en-us/blo |
| virtual_zh_home | ✅ | {"code": 200, "title": "WPTSALL Blog Lab", "virtual_class": true} |
| virtual_zh_post | ✅ | {"code": 200, "title": "COV ZH pages-b8b58153 - WPTSALL Blog Lab", "hreflang": true, "canonical": true, "canonical_url": "http://192.168.1.14:9081/test-vs-5b4e8 |
| subsite_home | ✅ | {"code": 200, "title": "English Subsite", "virtual_class": false} |
| subsite_category | ✅ | {"code": 200, "title": "Category: SUB Cat pages-b8b58153 - English Subsite", "virtual_class": false} |
| subsite_page | ✅ | {"code": 200, "title": "SUB Page pages-b8b58153 - English Subsite", "virtual_class": false} |
| subsite_post | ✅ | {"code": 200, "title": "SUB Post pages-b8b58153 - English Subsite", "virtual_class": false} |

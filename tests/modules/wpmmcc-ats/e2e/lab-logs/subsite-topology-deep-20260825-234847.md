# Subsite topology deep smoke — 20260825-234847

- WP_BASE: http://127.0.0.1:9083
- T1 path: /en/
- VS prefix: /en_us/

| check | result | detail |
|---|---|---|
| is_multisite | PASS | is_multisite=1 |
| T1 blog exists | PASS | blog_id=2 |
| T1 front home HTTP | PASS | GET http://127.0.0.1:9083/en/ → 200 |
| Relation target_site_type=wp | PASS | id=440 target=2 |
| T1 front post#18 | PASS | http://127.0.0.1:9083/en/wptsall-e2e-baseline-fixture-en/ → 200 |
| T1 admin edit post#18 | PASS | auth HTTP 200 |
| T1 front product#19 | PASS | http://127.0.0.1:9083/en/product/insulated-stainless-steel-water-bottle-6-en/ → 200 |
| T1 admin edit product#19 | PASS | auth HTTP 200 |
| T1 front download#20 | PASS | http://127.0.0.1:9083/en/downloads/a-music-album-en/ → 200 |
| T1 admin edit download#20 | PASS | route OK (auth HTTP 200) |
| T1 front lp_course#32 | PASS | http://127.0.0.1:9083/en/lp-course/learnpress-business-translation-essentials-en/ → 200 |
| T1 admin edit lp_course#32 | PASS | auth HTTP 200 |
| T1 front page#33 | PASS | http://127.0.0.1:9083/en/sample-page-en/ → 200 |
| T1 admin edit page#33 | PASS | auth HTTP 200 |
| T1 front job_listing#34 | PASS | http://127.0.0.1:9083/en/job/sales-development-representative-2-en/ → 200 |
| T1 admin edit job_listing#34 | PASS | route OK (auth HTTP 200) |
| T1 mapped sample count | PASS | count=6 |
| Network sites admin | PASS | HTTP 200 |
| T1 wp-admin dashboard | PASS | HTTP 200 |
| VS home still OK | PASS | /en_us/ → 200 |
| VS marker in body | PASS | virtual marker present |

## Summary
- PASS: 21
- FAIL: 0
- WARN: 0

# Subsite topology deep smoke — 20260906-131942

- WP_BASE: http://127.0.0.1:9083
- T1 path: /en/
- VS prefix: /en_us/

| check | result | detail |
|---|---|---|
| is_multisite | PASS | is_multisite=1 |
| T1 blog exists | PASS | blog_id=2 |
| T1 front home HTTP | PASS | GET http://127.0.0.1:9083/en/ → 200 |
| Relation target_site_type=wp | PASS | id=440 target=2 |
| T1 front post#2541 | PASS | http://127.0.0.1:9083/en/manual-gate-source-post-b522579a-en/ → 200 |
| T1 admin edit post#2541 | PASS | auth HTTP 200 |
| T1 front product#2542 | PASS | http://127.0.0.1:9083/en/product/bamboo-cutting-board-set-3-piece-34-en/ → 200 |
| T1 admin edit product#2542 | PASS | auth HTTP 200 |
| T1 front download#2543 | PASS | http://127.0.0.1:9083/en/downloads/a-music-album-en-2/ → 200 |
| T1 admin edit download#2543 | PASS | route OK (auth HTTP 200) |
| T1 front lp_course#2544 | PASS | http://127.0.0.1:9083/en/lp-course/learnpress-business-translation-essentials-en/ → 200 |
| T1 admin edit lp_course#2544 | PASS | auth HTTP 200 |
| T1 front page#2545 | PASS | http://127.0.0.1:9083/en/wptsall-core-component-page-en/ → 200 |
| T1 admin edit page#2545 | PASS | auth HTTP 200 |
| T1 front job_listing#2546 | PASS | http://127.0.0.1:9083/en/job/sales-development-representative-17-en/ → 200 |
| T1 admin edit job_listing#2546 | PASS | route OK (auth HTTP 200) |
| T1 front product#2550 | FAIL | http://127.0.0.1:9083/en/?post_type=product&p=2550 → 404 |
| T1 admin edit product#2550 | PASS | auth HTTP 200 |
| T1 front post#0 | PASS | http://127.0.0.1:9083/en/?p=0 → 200 |
| T1 admin edit post#0 | PASS | auth HTTP 200 |
| T1 front post#0 | PASS | http://127.0.0.1:9083/en/?p=0 → 200 |
| T1 admin edit post#0 | PASS | auth HTTP 200 |
| T1 front post#0 | PASS | http://127.0.0.1:9083/en/?p=0 → 200 |
| T1 admin edit post#0 | PASS | auth HTTP 200 |
| T1 front post#0 | PASS | http://127.0.0.1:9083/en/?p=0 → 200 |
| T1 admin edit post#0 | PASS | auth HTTP 200 |
| T1 front post#0 | PASS | http://127.0.0.1:9083/en/?p=0 → 200 |
| T1 admin edit post#0 | PASS | auth HTTP 200 |
| T1 front post#0 | PASS | http://127.0.0.1:9083/en/?p=0 → 200 |
| T1 admin edit post#0 | PASS | auth HTTP 200 |
| T1 front product#0 | PASS | http://127.0.0.1:9083/en/?p=0 → 200 |
| T1 admin edit product#0 | PASS | auth HTTP 200 |
| T1 front product#2564 | PASS | http://127.0.0.1:9083/en/product/src-product-mtopb6mv-en/ → 200 |
| T1 admin edit product#2564 | PASS | auth HTTP 200 |
| T1 front post#0 | PASS | http://127.0.0.1:9083/en/?p=0 → 200 |
| T1 admin edit post#0 | PASS | auth HTTP 200 |
| T1 front post#0 | PASS | http://127.0.0.1:9083/en/?p=0 → 200 |
| T1 admin edit post#0 | PASS | auth HTTP 200 |
| T1 front post#0 | PASS | http://127.0.0.1:9083/en/?p=0 → 200 |
| T1 admin edit post#0 | PASS | auth HTTP 200 |
| T1 front post#0 | PASS | http://127.0.0.1:9083/en/?p=0 → 200 |
| T1 admin edit post#0 | PASS | auth HTTP 200 |
| T1 front post#0 | PASS | http://127.0.0.1:9083/en/?p=0 → 200 |
| T1 admin edit post#0 | PASS | auth HTTP 200 |
| T1 mapped sample count | PASS | count=20 |
| Network sites admin | PASS | HTTP 200 |
| T1 wp-admin dashboard | PASS | HTTP 200 |
| VS home still OK | PASS | /en_us/ → 200 |
| VS marker in body | PASS | virtual marker present |

## Summary
- PASS: 48
- FAIL: 1
- WARN: 0

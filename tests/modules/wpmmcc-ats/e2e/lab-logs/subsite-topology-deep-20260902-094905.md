# Subsite topology deep smoke — 20260902-094905

- WP_BASE: http://127.0.0.1:9083
- T1 path: /en/
- VS prefix: /en_us/

| check | result | detail |
|---|---|---|
| is_multisite | PASS | is_multisite=1 |
| T1 blog exists | PASS | blog_id=2 |
| T1 front home HTTP | PASS | GET http://127.0.0.1:9083/en/ → 200 |
| Relation target_site_type=wp | PASS | id=440 target=2 |
| T1 front post#2376 | PASS | http://127.0.0.1:9083/en/manual-gate-source-post-b62533b1-en/ → 200 |
| T1 admin edit post#2376 | PASS | auth HTTP 200 |
| T1 front product#2377 | PASS | http://127.0.0.1:9083/en/product/bamboo-cutting-board-set-3-piece-26-en/ → 200 |
| T1 admin edit product#2377 | PASS | auth HTTP 200 |
| T1 front download#2378 | PASS | http://127.0.0.1:9083/en/downloads/a-music-album-en-2/ → 200 |
| T1 admin edit download#2378 | PASS | route OK (auth HTTP 200) |
| T1 front lp_course#2379 | PASS | http://127.0.0.1:9083/en/lp-course/learnpress-business-translation-essentials-en/ → 200 |
| T1 admin edit lp_course#2379 | PASS | auth HTTP 200 |
| T1 front page#2380 | PASS | http://127.0.0.1:9083/en/manual-matrix-bridge-wptsall-fc410b5a-en/ → 200 |
| T1 admin edit page#2380 | PASS | auth HTTP 200 |
| T1 front job_listing#2381 | PASS | http://127.0.0.1:9083/en/job/sales-development-representative-12-en/ → 200 |
| T1 admin edit job_listing#2381 | PASS | route OK (auth HTTP 200) |
| T1 mapped sample count | PASS | count=6 |
| Network sites admin | PASS | HTTP 200 |
| T1 wp-admin dashboard | PASS | HTTP 200 |
| VS home still OK | PASS | /en_us/ → 200 |
| VS marker in body | PASS | virtual marker present |

## Summary
- PASS: 21
- FAIL: 0
- WARN: 0

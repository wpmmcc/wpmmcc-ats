# Subsite topology deep smoke — 20260902-113608

- WP_BASE: http://127.0.0.1:9083
- T1 path: /en/
- VS prefix: /en_us/

| check | result | detail |
|---|---|---|
| is_multisite | PASS | is_multisite=1 |
| T1 blog exists | PASS | blog_id=2 |
| T1 front home HTTP | PASS | GET http://127.0.0.1:9083/en/ → 200 |
| Relation target_site_type=wp | PASS | id=440 target=2 |
| T1 front post#2384 | PASS | http://127.0.0.1:9083/en/manual-gate-source-post-cdbffb70-en/ → 200 |
| T1 admin edit post#2384 | PASS | auth HTTP 200 |
| T1 front product#2385 | PASS | http://127.0.0.1:9083/en/product/bamboo-cutting-board-set-3-piece-27-en/ → 200 |
| T1 admin edit product#2385 | PASS | auth HTTP 200 |
| T1 front download#2386 | PASS | http://127.0.0.1:9083/en/downloads/a-music-album-en-2/ → 200 |
| T1 admin edit download#2386 | PASS | route OK (auth HTTP 200) |
| T1 front lp_course#2387 | PASS | http://127.0.0.1:9083/en/lp-course/learnpress-business-translation-essentials-en/ → 200 |
| T1 admin edit lp_course#2387 | PASS | auth HTTP 200 |
| T1 front page#2388 | PASS | http://127.0.0.1:9083/en/manual-matrix-bridge-wptsall-39c9a59d-en/ → 200 |
| T1 admin edit page#2388 | PASS | auth HTTP 200 |
| T1 front job_listing#2389 | PASS | http://127.0.0.1:9083/en/job/sales-development-representative-13-en/ → 200 |
| T1 admin edit job_listing#2389 | PASS | route OK (auth HTTP 200) |
| T1 mapped sample count | PASS | count=6 |
| Network sites admin | PASS | HTTP 200 |
| T1 wp-admin dashboard | PASS | HTTP 200 |
| VS home still OK | PASS | /en_us/ → 200 |
| VS marker in body | PASS | virtual marker present |

## Summary
- PASS: 21
- FAIL: 0
- WARN: 0

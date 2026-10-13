# Subsite topology deep smoke — 20260901-183053

- WP_BASE: http://127.0.0.1:9083
- T1 path: /en/
- VS prefix: /en_us/

| check | result | detail |
|---|---|---|
| is_multisite | PASS | is_multisite=1 |
| T1 blog exists | PASS | blog_id=2 |
| T1 front home HTTP | PASS | GET http://127.0.0.1:9083/en/ → 200 |
| Relation target_site_type=wp | PASS | id=440 target=2 |
| T1 front post#2336 | PASS | http://127.0.0.1:9083/en/manual-gate-source-post-6291467b-en/ → 200 |
| T1 admin edit post#2336 | PASS | auth HTTP 200 |
| T1 front product#2337 | PASS | http://127.0.0.1:9083/en/product/bamboo-cutting-board-set-3-piece-21-en/ → 200 |
| T1 admin edit product#2337 | PASS | auth HTTP 200 |
| T1 front download#2338 | PASS | http://127.0.0.1:9083/en/downloads/a-music-album-en-2/ → 200 |
| T1 admin edit download#2338 | PASS | route OK (auth HTTP 200) |
| T1 front lp_course#2339 | PASS | http://127.0.0.1:9083/en/lp-course/learnpress-business-translation-essentials-en/ → 200 |
| T1 admin edit lp_course#2339 | PASS | auth HTTP 200 |
| T1 front page#2340 | PASS | http://127.0.0.1:9083/en/manual-matrix-bridge-wptsall-b13f9c78-en/ → 200 |
| T1 admin edit page#2340 | PASS | auth HTTP 200 |
| T1 front job_listing#2341 | PASS | http://127.0.0.1:9083/en/job/sales-development-representative-7-en/ → 200 |
| T1 admin edit job_listing#2341 | PASS | route OK (auth HTTP 200) |
| T1 mapped sample count | PASS | count=6 |
| Network sites admin | PASS | HTTP 200 |
| T1 wp-admin dashboard | PASS | HTTP 200 |
| VS home still OK | PASS | /en_us/ → 200 |
| VS marker in body | PASS | virtual marker present |

## Summary
- PASS: 21
- FAIL: 0
- WARN: 0

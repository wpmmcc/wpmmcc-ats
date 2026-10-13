# Subsite topology deep smoke — 20260901-014821

- WP_BASE: http://127.0.0.1:9083
- T1 path: /en/
- VS prefix: /en_us/

| check | result | detail |
|---|---|---|
| is_multisite | PASS | is_multisite=1 |
| T1 blog exists | PASS | blog_id=2 |
| T1 front home HTTP | PASS | GET http://127.0.0.1:9083/en/ → 200 |
| Relation target_site_type=wp | PASS | id=440 target=2 |
| T1 front post#0 | PASS | http://127.0.0.1:9083/en/?p=0 → 200 |
| T1 admin edit post#0 | PASS | auth HTTP 200 |
| T1 front page#0 | PASS | http://127.0.0.1:9083/en/?p=0 → 200 |
| T1 admin edit page#0 | PASS | auth HTTP 200 |
| T1 front post#0 | PASS | http://127.0.0.1:9083/en/?p=0 → 200 |
| T1 admin edit post#0 | PASS | auth HTTP 200 |
| T1 front post#0 | PASS | http://127.0.0.1:9083/en/?p=0 → 200 |
| T1 admin edit post#0 | PASS | auth HTTP 200 |
| T1 front post#0 | PASS | http://127.0.0.1:9083/en/?p=0 → 200 |
| T1 admin edit post#0 | PASS | auth HTTP 200 |
| T1 front post#0 | PASS | http://127.0.0.1:9083/en/?p=0 → 200 |
| T1 admin edit post#0 | PASS | auth HTTP 200 |
| T1 front page#0 | PASS | http://127.0.0.1:9083/en/?p=0 → 200 |
| T1 admin edit page#0 | PASS | auth HTTP 200 |
| T1 front page#0 | PASS | http://127.0.0.1:9083/en/?p=0 → 200 |
| T1 admin edit page#0 | PASS | auth HTTP 200 |
| T1 front page#0 | PASS | http://127.0.0.1:9083/en/?p=0 → 200 |
| T1 admin edit page#0 | PASS | auth HTTP 200 |
| T1 front page#0 | PASS | http://127.0.0.1:9083/en/?p=0 → 200 |
| T1 admin edit page#0 | PASS | auth HTTP 200 |
| T1 front page#0 | PASS | http://127.0.0.1:9083/en/?p=0 → 200 |
| T1 admin edit page#0 | PASS | auth HTTP 200 |
| T1 front page#0 | PASS | http://127.0.0.1:9083/en/?p=0 → 200 |
| T1 admin edit page#0 | PASS | auth HTTP 200 |
| T1 front page#0 | PASS | http://127.0.0.1:9083/en/?p=0 → 200 |
| T1 admin edit page#0 | PASS | auth HTTP 200 |
| T1 front page#0 | PASS | http://127.0.0.1:9083/en/?p=0 → 200 |
| T1 admin edit page#0 | PASS | auth HTTP 200 |
| T1 front page#0 | PASS | http://127.0.0.1:9083/en/?p=0 → 200 |
| T1 admin edit page#0 | PASS | auth HTTP 200 |
| T1 front page#0 | PASS | http://127.0.0.1:9083/en/?p=0 → 200 |
| T1 admin edit page#0 | PASS | auth HTTP 200 |
| T1 front page#0 | PASS | http://127.0.0.1:9083/en/?p=0 → 200 |
| T1 admin edit page#0 | PASS | auth HTTP 200 |
| T1 front page#0 | PASS | http://127.0.0.1:9083/en/?p=0 → 200 |
| T1 admin edit page#0 | PASS | auth HTTP 200 |
| T1 front page#0 | PASS | http://127.0.0.1:9083/en/?p=0 → 200 |
| T1 admin edit page#0 | PASS | auth HTTP 200 |
| T1 front page#0 | PASS | http://127.0.0.1:9083/en/?p=0 → 200 |
| T1 admin edit page#0 | PASS | auth HTTP 200 |
| T1 mapped sample count | PASS | count=20 |
| Network sites admin | PASS | HTTP 200 |
| T1 wp-admin dashboard | PASS | HTTP 200 |
| VS home still OK | PASS | /en_us/ → 200 |
| VS marker in body | PASS | virtual marker present |

## Summary
- PASS: 49
- FAIL: 0
- WARN: 0

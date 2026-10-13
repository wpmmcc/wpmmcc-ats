# Subsite topology deep smoke — 20260909-144140

- WP_BASE: http://127.0.0.1:9083
- T1 path: /en/
- VS prefix: /en_us/

| check | result | detail |
|---|---|---|
| is_multisite | PASS | is_multisite=1 |
| T1 blog exists | PASS | blog_id=2 |
| T1 front home HTTP | PASS | GET http://127.0.0.1:9083/en/ → 200 |
| Relation target_site_type=wp | PASS | id=440 target=2 |
| T1 map post#0 | WARN | skip empty target_post_id (source=30784) |
| T1 map post#0 | WARN | skip empty target_post_id (source=30770) |
| T1 map post#0 | WARN | skip empty target_post_id (source=30769) |
| T1 map post#0 | WARN | skip empty target_post_id (source=30768) |
| T1 map post#0 | WARN | skip empty target_post_id (source=30767) |
| T1 map post#0 | WARN | skip empty target_post_id (source=30766) |
| T1 map post#0 | WARN | skip empty target_post_id (source=30765) |
| T1 map post#0 | WARN | skip empty target_post_id (source=30764) |
| T1 map post#0 | WARN | skip empty target_post_id (source=30763) |
| T1 map post#0 | WARN | skip empty target_post_id (source=30762) |
| T1 map post#0 | WARN | skip empty target_post_id (source=1) |
| T1 front page#2722 | WARN | skip non-publish status=unknown |
| T1 admin edit page#2722 | PASS | route OK (auth HTTP 404) |
| T1 map post#0 | WARN | skip empty target_post_id (source=64) |
| T1 map post#0 | WARN | skip empty target_post_id (source=66) |
| T1 map post#0 | WARN | skip empty target_post_id (source=68) |
| T1 map post#0 | WARN | skip empty target_post_id (source=70) |
| T1 map post#0 | WARN | skip empty target_post_id (source=72) |
| T1 map post#0 | WARN | skip empty target_post_id (source=74) |
| T1 map post#0 | WARN | skip empty target_post_id (source=76) |
| T1 map post#0 | WARN | skip empty target_post_id (source=78) |
| T1 mapped sample count | WARN | only 1 (want ≥4 of 6) |
| T1 stuck tasks (wp relation) | WARN | pending/retry=9 (subsite-translation gate hard-asserts 0 after its closed loop) |
| Network sites admin | PASS | HTTP 200 |
| T1 wp-admin dashboard | PASS | HTTP 200 |
| VS home still OK | PASS | /en_us/ → 200 |
| VS marker in body | PASS | virtual marker present |

## Summary
- PASS: 9
- FAIL: 0
- WARN: 22

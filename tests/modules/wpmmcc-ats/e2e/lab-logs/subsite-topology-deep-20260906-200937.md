# Subsite topology deep smoke — 20260906-200937

- WP_BASE: http://127.0.0.1:9083
- T1 path: /en/
- VS prefix: /en_us/

| check | result | detail |
|---|---|---|
| is_multisite | PASS | is_multisite=1 |
| T1 blog exists | PASS | blog_id=2 |
| T1 front home HTTP | PASS | GET http://127.0.0.1:9083/en/ → 200 |
| Relation target_site_type=wp | PASS | id=440 target=2 |
| T1 map page#0 | WARN | skip empty target_post_id (source=26583) |
| T1 map page#0 | WARN | skip empty target_post_id (source=26582) |
| T1 map page#0 | WARN | skip empty target_post_id (source=26581) |
| T1 map page#0 | WARN | skip empty target_post_id (source=26580) |
| T1 map page#0 | WARN | skip empty target_post_id (source=26579) |
| T1 front page#2663 | PASS | http://127.0.0.1:9083/en/term_conditions-en/ → 200 |
| T1 admin edit page#2663 | PASS | auth HTTP 200 |
| T1 map page#0 | WARN | skip empty target_post_id (source=26577) |
| T1 map page#0 | WARN | skip empty target_post_id (source=26576) |
| T1 map page#0 | WARN | skip empty target_post_id (source=26575) |
| T1 map page#0 | WARN | skip empty target_post_id (source=26574) |
| T1 map page#0 | WARN | skip empty target_post_id (source=26573) |
| T1 map page#0 | WARN | skip empty target_post_id (source=26572) |
| T1 map page#0 | WARN | skip empty target_post_id (source=26571) |
| T1 map page#0 | WARN | skip empty target_post_id (source=26570) |
| T1 map page#0 | WARN | skip empty target_post_id (source=26569) |
| T1 map page#0 | WARN | skip empty target_post_id (source=26568) |
| T1 map page#0 | WARN | skip empty target_post_id (source=26567) |
| T1 map page#0 | WARN | skip empty target_post_id (source=26566) |
| T1 map page#0 | WARN | skip empty target_post_id (source=26565) |
| T1 map page#0 | WARN | skip empty target_post_id (source=26564) |
| T1 mapped sample count | WARN | only 1 (want ≥4 of 6) |
| Network sites admin | PASS | HTTP 200 |
| T1 wp-admin dashboard | PASS | HTTP 200 |
| VS home still OK | PASS | /en_us/ → 200 |
| VS marker in body | PASS | virtual marker present |

## Summary
- PASS: 10
- FAIL: 0
- WARN: 20

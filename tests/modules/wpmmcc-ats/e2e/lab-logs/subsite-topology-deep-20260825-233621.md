# Subsite topology deep smoke — 20260825-233621

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
| T1 admin edit post#18 | FAIL | /en/wp-admin/post.php?post=18 → 404 |
| T1 front product#19 | PASS | http://127.0.0.1:9083/en/product/insulated-stainless-steel-water-bottle-6-en/ → 200 |
| T1 admin edit product#19 | FAIL | /en/wp-admin/post.php?post=19 → 404 |
| T1 front download#20 | PASS | http://127.0.0.1:9083/en/downloads/a-music-album-en/ → 200 |
| T1 admin edit download#20 | FAIL | /en/wp-admin/post.php?post=20 → 404 |
| T1 front courses#21 | PASS | http://127.0.0.1:9083/en/tutor-course/wordpress-for-beginners-master-wordpress-quickly-en/ → 200 |
| T1 admin edit courses#21 | FAIL | /en/wp-admin/post.php?post=21 → 404 |
| T1 front tribe_events#22 | FAIL | http://127.0.0.1:9083/en/event/lab-content-matrix-conference-en/ → 404 |
| T1 admin edit tribe_events#22 | FAIL | /en/wp-admin/post.php?post=22 → 404 |
| T1 front topic#23 | PASS | http://127.0.0.1:9083/en/?p=23 → 200 |
| T1 admin edit topic#23 | FAIL | /en/wp-admin/post.php?post=23 → 404 |
| T1 mapped sample count | PASS | count=6 |
| Network sites admin | PASS | HTTP 200 |
| T1 wp-admin dashboard | FAIL | HTTP 302 |
| VS home still OK | PASS | /en_us/ → 200 |
| VS marker in body | PASS | virtual marker present |

## Summary
- PASS: 13
- FAIL: 8
- WARN: 0

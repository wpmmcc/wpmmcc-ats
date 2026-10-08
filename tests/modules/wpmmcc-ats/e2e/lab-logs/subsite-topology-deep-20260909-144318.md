# Subsite topology deep smoke — 20260909-144318

- WP_BASE: http://127.0.0.1:9083
- T1 path: /en/
- VS prefix: /en_us/

| check | result | detail |
|---|---|---|
| is_multisite | PASS | is_multisite=1 |
| T1 blog exists | PASS | blog_id=2 |
| T1 front home HTTP | PASS | GET http://127.0.0.1:9083/en/ → 200 |
| Relation target_site_type=wp | PASS | id=440 target=2 |
| T1 front job_listing#3191 | PASS | http://127.0.0.1:9083/en/job/sales-development-representative-17-en/ → 200 |
| T1 admin edit job_listing#3191 | PASS | route OK (auth HTTP 200) |
| T1 front lp_course#3190 | PASS | http://127.0.0.1:9083/en/lp-course/learnpress-business-translation-essentials-en/ → 200 |
| T1 admin edit lp_course#3190 | PASS | auth HTTP 200 |
| T1 front product#3188 | PASS | http://127.0.0.1:9083/en/product/bamboo-cutting-board-set-3-piece-36-en/ → 200 |
| T1 admin edit product#3188 | PASS | auth HTTP 200 |
| T1 front download#3189 | PASS | http://127.0.0.1:9083/en/downloads/a-music-album-en-2/ → 200 |
| T1 admin edit download#3189 | PASS | route OK (auth HTTP 200) |
| T1 front page#3051 | PASS | http://127.0.0.1:9083/en/home/ → 200 |
| T1 admin edit page#3051 | PASS | auth HTTP 200 |
| T1 front page#3050 | PASS | http://127.0.0.1:9083/en/services/ → 200 |
| T1 admin edit page#3050 | PASS | auth HTTP 200 |
| T1 front page#3049 | PASS | http://127.0.0.1:9083/en/about/ → 200 |
| T1 admin edit page#3049 | PASS | auth HTTP 200 |
| T1 front page#3048 | PASS | http://127.0.0.1:9083/en/contact/ → 200 |
| T1 admin edit page#3048 | PASS | auth HTTP 200 |
| T1 front page#3047 | PASS | http://127.0.0.1:9083/en/news/ → 200 |
| T1 admin edit page#3047 | PASS | auth HTTP 200 |
| T1 front post#3139 | PASS | http://127.0.0.1:9083/en/subsite-translation-gate-20260909-061007-a/ → 200 |
| T1 admin edit post#3139 | PASS | auth HTTP 200 |
| T1 front post#3138 | PASS | http://127.0.0.1:9083/en/subsite-translation-gate-20260909-061007-b/ → 200 |
| T1 admin edit post#3138 | PASS | auth HTTP 200 |
| T1 front post#3105 | PASS | http://127.0.0.1:9083/en/%e5%85%ac%e5%8f%b8%e8%8d%a3%e8%8e%b72025%e5%b9%b4%e5%ba%a6%e5%88%9b%e6%96%b0%e4%bc%81%e4%b8%9a%e5%a5%96/ → 200 |
| T1 admin edit post#3105 | PASS | auth HTTP 200 |
| T1 front post#3104 | PASS | http://127.0.0.1:9083/en/%e5%85%ac%e5%8f%b8%e4%b8%8e%e8%a1%8c%e4%b8%9a%e9%a2%86%e5%85%88%e4%bc%81%e4%b8%9a%e8%be%be%e6%88%90%e6%88%98%e7%95%a5%e5%90%88%e4%bd%9c/ → 200 |
| T1 admin edit post#3104 | PASS | auth HTTP 200 |
| T1 front post#3103 | PASS | http://127.0.0.1:9083/en/%e5%85%ac%e5%8f%b8%e6%88%90%e5%8a%9f%e4%b8%be%e5%8a%9e%e5%b9%b4%e5%ba%a6%e6%8a%80%e6%9c%af%e5%b3%b0%e4%bc%9a/ → 200 |
| T1 admin edit post#3103 | PASS | auth HTTP 200 |
| T1 front post#3102 | PASS | http://127.0.0.1:9083/en/%e6%96%b0%e7%89%88%e4%ba%a7%e5%93%81-3-0-%e6%ad%a3%e5%bc%8f%e5%8f%91%e5%b8%83/ → 200 |
| T1 admin edit post#3102 | PASS | auth HTTP 200 |
| T1 front post#3101 | PASS | http://127.0.0.1:9083/en/%e7%a7%bb%e5%8a%a8%e7%ab%af-app-2-5-%e7%89%88%e6%9c%ac%e6%9b%b4%e6%96%b0/ → 200 |
| T1 admin edit post#3101 | PASS | auth HTTP 200 |
| T1 front post#3100 | PASS | http://127.0.0.1:9083/en/%e6%95%b0%e5%ad%97%e5%8c%96%e8%bd%ac%e5%9e%8b%ef%bc%9a%e4%bc%81%e4%b8%9a%e5%8f%91%e5%b1%95%e7%9a%84%e5%bf%85%e7%94%b1%e4%b9%8b%e8%b7%af/ → 200 |
| T1 admin edit post#3100 | PASS | auth HTTP 200 |
| T1 front post#3099 | PASS | http://127.0.0.1:9083/en/2025%e5%b9%b4%e4%ba%ba%e5%b7%a5%e6%99%ba%e8%83%bd%e5%8f%91%e5%b1%95%e8%b6%8b%e5%8a%bf%e5%b1%95%e6%9c%9b/ → 200 |
| T1 admin edit post#3099 | PASS | auth HTTP 200 |
| T1 front post#3098 | PASS | http://127.0.0.1:9083/en/%e4%ba%91%e8%ae%a1%e7%ae%97%e5%b8%82%e5%9c%ba%e6%a0%bc%e5%b1%80%e4%b8%8e%e5%8f%91%e5%b1%95%e6%9c%ba%e9%81%87/ → 200 |
| T1 admin edit post#3098 | PASS | auth HTTP 200 |
| T1 front post#3097 | PASS | http://127.0.0.1:9083/en/api-%e8%ae%be%e8%ae%a1%e6%9c%80%e4%bd%b3%e5%ae%9e%e8%b7%b5%e6%8c%87%e5%8d%97/ → 200 |
| T1 admin edit post#3097 | PASS | auth HTTP 200 |
| T1 mapped sample count | PASS | count=20 |
| T1 stuck tasks (wp relation) | WARN | pending/retry=9 (subsite-translation gate hard-asserts 0 after its closed loop) |
| Network sites admin | PASS | HTTP 200 |
| T1 wp-admin dashboard | PASS | HTTP 200 |
| VS home still OK | PASS | /en_us/ → 200 |
| VS marker in body | PASS | virtual marker present |

## Summary
- PASS: 49
- FAIL: 0
- WARN: 1

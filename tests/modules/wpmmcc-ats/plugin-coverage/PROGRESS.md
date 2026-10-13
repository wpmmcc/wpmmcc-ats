# Plugin coverage progress

<!--
数据时间窗声明（2026-09-25 发布批 P1 终局更新）：
- **101/101 passed，零 non-pass**——发布批（§41）收官。最后一项 wp-easycart 于本批
  销案：其长期隔离（SKIP_ACTIVATE，install 阶段 plugins_loaded fatal）经取证定谳为
  **SQL 激活伪影**——旧 SQL 预置激活绕 ec_activate 钩子 → 新站无
  ec_option_wpoptions_version 选项 → wpeasycart_update_check 对无版本站调
  update_language_data() → wp_easycart_language::$languages 静态 null →
  in_array(…, null) TypeError（ec_language.php:112，子站 fatal）。真用户不踩（激活
  钩子写好版本选项）。治愈实证：isolate_cli 重置后两 blog `wp plugin activate`
  真激活 → 双站激活 clean + 双站 web 200 → SKIP_ACTIVATE 清空 → 复跑 passed。
- 前批（2026-09-25 九 slug 归因批）终局口径保留：bbpress=产品（子站块主题
  template_include=false 空壳，init() 无条件注册安全网修复）；strong-testimonials/
  fluentform=测试环境（SQL 直写绕钩子→子站表不建，真激活修复）；EM/EO/TEC/DLM=
  测试数据（日期 meta/schedule/commit_post_updates/set_date 播种）；masterstudy=
  测试脚本（author=0 → WP_User 断言，author 种子）；BD=测试数据+脚本
  （[businessdirectory] 主页建页 + wpbdp 静态缓存绕开）。
- harness 制度（前批确立，本批沿用）：真激活两 blog + 每 blog 独立进程 flush；
  激活钩子 wp-cli 作用域 fatal 类走「SQL 兜底 + Apache 真请求暖机」恢复路径；
  bootstrap 解析用「Plugin Name:」头扫描优先。
- passed 行为 2026-09-25 04:51 UTC 本轮实跑（.12 URL 为现值；.14 URL 行为
  2026-09-13 快照混排，引用须知悉时间窗）。
- 现行权威口径 = guards 的 plugin-support-matrix（源快照推导，零漂移门禁）+ 逐插件
  records/*.md 证据文档；本文件为进度快照+复核记录。
-->

- updated: 2026-09-25 04:51 UTC
- catalog: 100
- recorded: 101
- passed: 101
- failed: 0
- not_installed: 0

| slug | category | status | post_type | virtual URL |
|---|---|---|---|---|
| [wordpress-blog](records/wordpress-blog.md) | wordpress-core | passed | post | http://192.168.1.14:9081/en-us/blog/2026/08/13/cov-wordpress-blog-1b8b76-en/ |
| [woocommerce](records/woocommerce.md) | ecommerce | passed | post | http://192.168.1.14:9081/en-us/blog/2026/08/13/cov-woocommerce-47b3c4-en/ |
| [easy-digital-downloads](records/easy-digital-downloads.md) | ecommerce | passed | download | http://192.168.1.14:9081/en-us/downloads/cov-en-easy-digital-downloads/ |
| [ecwid-shopping-cart](records/ecwid-shopping-cart.md) | ecommerce | passed | post | http://192.168.1.14:9081/en-us/blog/2026/08/13/cov-ecwid-shopping-cart-8095b1-en/ |
| [wp-easycart](records/wp-easycart.md) | ecommerce | passed | post | http://192.168.1.12:9081/en-us/cov-wp-easycart-761dc3-en/ |
| [surecart](records/surecart.md) | ecommerce | passed | sc_product | http://192.168.1.14:9081/en-us/?p=1455 |
| [dokan-lite](records/dokan-lite.md) | ecommerce | passed | product | http://192.168.1.14:9081/en-us/?p=1504 |
| [bbpress](records/bbpress.md) | forum-community | passed | topic | http://192.168.1.12:9081/en-us/forums/topic/cov-bbpress-eeba33-en/ |
| [buddypress](records/buddypress.md) | forum-community | passed | page | http://192.168.1.14:9081/en-us/cov-buddypress-96a789-en/ |
| [wpforo](records/wpforo.md) | forum-community | passed | post | http://192.168.1.14:9081/en-us/blog/2026/08/13/cov-wpforo-e94b8a-en/ |
| [asgaros-forum](records/asgaros-forum.md) | forum-community | passed | page | http://192.168.1.12:9081/en-us/cov-asgaros-forum-4bf0d4-en/ |
| [forumwp](records/forumwp.md) | forum-community | passed | post | http://192.168.1.14:9081/en-us/blog/2026/08/13/cov-forumwp-cb4f32-en/ |
| [wpdiscuz](records/wpdiscuz.md) | forum-community | passed | post | http://192.168.1.14:9081/en-us/blog/2026/08/13/cov-wpdiscuz-fe1450-en/ |
| [learnpress](records/learnpress.md) | lms | passed | lp_course | http://192.168.1.14:9081/en-us/courses/cov-en-learnpress/ |
| [sensei-lms](records/sensei-lms.md) | lms | passed | course | http://192.168.1.14:9081/en-us/?p=1434 |
| [tutor](records/tutor.md) | lms | passed | courses | http://192.168.1.12:9081/en-us/courses/cov-en-tutor-3/ |
| [lifterlms](records/lifterlms.md) | lms | passed | course | http://192.168.1.14:9081/en-us/?p=1405 |
| [masterstudy-lms-learning-management-system](records/masterstudy-lms-learning-management-system.md) | lms | passed | stm-courses | http://192.168.1.12:9081/en-us/blog/courses/cov-en-masterstudy-lms-learning-management-system-10/ |
| [academy](records/academy.md) | lms | passed | academy_courses | http://192.168.1.14:9081/en-us/?p=1322 |
| [the-events-calendar](records/the-events-calendar.md) | events-booking | passed | tribe_events | http://192.168.1.12:9081/en-us/event/cov-en-the-events-calendar-15/ |
| [events-manager](records/events-manager.md) | events-booking | passed | event | http://192.168.1.12:9081/en-us/events/cov-en-events-manager-10/ |
| [ameliabooking](records/ameliabooking.md) | events-booking | passed | post | http://192.168.1.14:9081/en-us/blog/2026/08/13/cov-ameliabooking-f3c18e-en/ |
| [bookly-responsive-appointment-booking-tool](records/bookly-responsive-appointment-booking-tool.md) | events-booking | passed | page | http://192.168.1.14:9081/en-us/cov-bookly-responsive-appointment-booking-tool-ec825d-en/ |
| [simply-schedule-appointments](records/simply-schedule-appointments.md) | events-booking | passed | page | http://192.168.1.14:9081/en-us/cov-simply-schedule-appointments-21f21d-en/ |
| [fluent-booking](records/fluent-booking.md) | events-booking | passed | page | http://192.168.1.14:9081/en-us/cov-fluent-booking-a9d9bb-en/ |
| [easy-appointments](records/easy-appointments.md) | events-booking | passed | page | http://192.168.1.14:9081/en-us/cov-easy-appointments-67a59c-en/ |
| [booking](records/booking.md) | events-booking | passed | page | http://192.168.1.14:9081/en-us/cov-booking-236c22-en/ |
| [modern-events-calendar-lite](records/modern-events-calendar-lite.md) | events-booking | passed | mec-events | http://192.168.1.14:9081/en-us/?p=1414 |
| [wp-event-manager](records/wp-event-manager.md) | events-booking | passed | event_listing | http://192.168.1.14:9081/en-us/?p=1474 |
| [event-organiser](records/event-organiser.md) | events-booking | passed | event | http://192.168.1.12:9081/en-us/events/event/cov-en-event-organiser-10/ |
| [my-calendar](records/my-calendar.md) | events-booking | passed | page | http://192.168.1.12:9081/en-us/cov-my-calendar-6d1f9a-en/ |
| [give](records/give.md) | donations | passed | give_forms | http://192.168.1.14:9081/en-us/?p=1396 |
| [directorist](records/directorist.md) | directory-listings | passed | post | http://192.168.1.14:9081/en-us/blog/2026/08/13/cov-directorist-d96a38-en/ |
| [geodirectory](records/geodirectory.md) | directory-listings | passed | gd_place | http://192.168.1.14:9081/en-us/?p=1393 |
| [hivepress](records/hivepress.md) | directory-listings | passed | hp_listing | http://192.168.1.14:9081/en-us/?p=1403 |
| [classified-listing](records/classified-listing.md) | directory-listings | passed | rtcl_listing | http://192.168.1.14:9081/en-us/?p=1347 |
| [business-directory-plugin](records/business-directory-plugin.md) | directory-listings | passed | wpbdp_listing | http://192.168.1.12:9081/en-us/business-directory/cov-en-business-directory-plugin-15/ |
| [wp-job-manager](records/wp-job-manager.md) | jobs | passed | job_listing | http://192.168.1.14:9081/en-us/?p=1477 |
| [wp-job-openings](records/wp-job-openings.md) | jobs | passed | awsm_job_openings | http://192.168.1.14:9081/en-us/?p=1480 |
| [simple-job-board](records/simple-job-board.md) | jobs | passed | jobpost | http://192.168.1.14:9081/en-us/jobs/cov-en-simple-job-board/ |
| [estatik](records/estatik.md) | real-estate | passed | properties | http://192.168.1.14:9081/en-us/property/cov-en-estatik/ |
| [essential-real-estate](records/essential-real-estate.md) | real-estate | passed | property | http://192.168.1.14:9081/en-us/blog/property/cov-en-essential-real-estate/ |
| [easy-property-listings](records/easy-property-listings.md) | real-estate | passed | post | http://192.168.1.14:9081/en-us/blog/2026/08/13/cov-easy-property-listings-29bdfb-en/ |
| [propertyhive](records/propertyhive.md) | real-estate | passed | property | http://192.168.1.14:9081/en-us/?p=1432 |
| [envira-gallery-lite](records/envira-gallery-lite.md) | gallery-portfolio | passed | envira | http://192.168.1.12:9081/en-us/?p=5748 |
| [foogallery](records/foogallery.md) | gallery-portfolio | passed | foogallery | http://192.168.1.12:9081/en-us/?p=5762 |
| [visual-portfolio](records/visual-portfolio.md) | gallery-portfolio | passed | portfolio | http://192.168.1.14:9081/en-us/portfolio/cov-en-visual-portfolio/ |
| [portfolio-post-type](records/portfolio-post-type.md) | gallery-portfolio | passed | portfolio | http://192.168.1.14:9081/en-us/?p=1428 |
| [nextgen-gallery](records/nextgen-gallery.md) | gallery-portfolio | passed | page | http://192.168.1.14:9081/en-us/cov-nextgen-gallery-d188c3-en/ |
| [ml-slider](records/ml-slider.md) | gallery-portfolio | passed | page | http://192.168.1.14:9081/en-us/cov-ml-slider-1c9469-en/ |
| [powerpress](records/powerpress.md) | podcasts | passed | post | http://192.168.1.14:9081/en-us/blog/2026/08/13/cov-powerpress-02d076-en/ |
| [seriously-simple-podcasting](records/seriously-simple-podcasting.md) | podcasts | passed | podcast | http://192.168.1.14:9081/en-us/?p=1441 |
| [podlove-podcasting-plugin-for-wordpress](records/podlove-podcasting-plugin-for-wordpress.md) | podcasts | passed | podcast | http://192.168.1.12:9081/en-us/cov-en-podlove-podcasting-plugin-for-wordpress/ |
| [podcast-player](records/podcast-player.md) | podcasts | passed | page | http://192.168.1.14:9081/en-us/cov-podcast-player-579f01-en/ |
| [wp-recipe-maker](records/wp-recipe-maker.md) | recipes | passed | post | http://192.168.1.14:9081/en-us/blog/2026/08/13/cov-wp-recipe-maker-e7c220-en/ |
| [delicious-recipes](records/delicious-recipes.md) | recipes | passed | recipe | http://192.168.1.14:9081/en-us/?p=1357 |
| [cooked](records/cooked.md) | recipes | passed | cp_recipe | http://192.168.1.14:9081/en-us/?p=1350 |
| [reviews-feed](records/reviews-feed.md) | reviews-testimonials | passed | page | http://192.168.1.12:9081/en-us/cov-reviews-feed-7061cb-en/ |
| [site-reviews](records/site-reviews.md) | reviews-testimonials | passed | site-review | http://192.168.1.12:9081/en-us/?p=5772 |
| [testimonial-free](records/testimonial-free.md) | reviews-testimonials | passed | post | http://192.168.1.14:9081/en-us/blog/2026/08/13/cov-testimonial-free-3b610b-en/ |
| [wp-customer-reviews](records/wp-customer-reviews.md) | reviews-testimonials | passed | page | http://192.168.1.14:9081/en-us/cov-wp-customer-reviews-3439b4-en/ |
| [strong-testimonials](records/strong-testimonials.md) | reviews-testimonials | passed | wpm-testimonial | http://192.168.1.12:9081/en-us/blog/testimonial/cov-en-strong-testimonials-12/ |
| [ultimate-faqs](records/ultimate-faqs.md) | faq-kb | passed | ufaq | http://192.168.1.14:9081/en-us/blog/ufaq/cov-en-ultimate-faqs/ |
| [helpie-faq](records/helpie-faq.md) | faq-kb | passed | helpie_faq | http://192.168.1.14:9081/en-us/?p=1399 |
| [betterdocs](records/betterdocs.md) | faq-kb | passed | docs | http://192.168.1.14:9081/en-us/docs/cov-en-betterdocs/ |
| [restrict-content](records/restrict-content.md) | membership | passed | page | http://192.168.1.14:9081/en-us/cov-restrict-content-7ae421-en/ |
| [paid-memberships-pro](records/paid-memberships-pro.md) | membership | passed | page | http://192.168.1.14:9081/en-us/cov-paid-memberships-pro-b9ffdb-en/ |
| [ultimate-member](records/ultimate-member.md) | membership | passed | page | http://192.168.1.14:9081/en-us/cov-ultimate-member-cf4be1-en/ |
| [custom-post-type-ui](records/custom-post-type-ui.md) | cpt-custom-fields | passed | post | http://192.168.1.14:9081/en-us/blog/2026/08/13/cov-custom-post-type-ui-feacfb-en/ |
| [pods](records/pods.md) | cpt-custom-fields | passed | post | http://192.168.1.14:9081/en-us/blog/2026/08/13/cov-pods-6ccd06-en/ |
| [meta-box](records/meta-box.md) | cpt-custom-fields | passed | post | http://192.168.1.14:9081/en-us/blog/2026/08/13/cov-meta-box-926192-en/ |
| [advanced-custom-fields](records/advanced-custom-fields.md) | cpt-custom-fields | passed | post | http://192.168.1.14:9081/en-us/blog/2026/08/13/cov-advanced-custom-fields-a613dd-en/ |
| [carbon-fields](records/carbon-fields.md) | cpt-custom-fields | passed | post | http://192.168.1.14:9081/en-us/blog/2026/08/13/cov-carbon-fields-83bf56-en/ |
| [wordpress-seo](records/wordpress-seo.md) | seo | passed | post | http://192.168.1.14:9081/en-us/blog/2026/08/13/cov-wordpress-seo-c23a5e-en/ |
| [seo-by-rank-math](records/seo-by-rank-math.md) | seo | passed | post | http://192.168.1.14:9081/en-us/blog/2026/08/13/cov-seo-by-rank-math-8b851a-en/ |
| [all-in-one-seo-pack](records/all-in-one-seo-pack.md) | seo | passed | post | http://192.168.1.14:9081/en-us/blog/2026/08/13/cov-all-in-one-seo-pack-bdf85c-en/ |
| [wp-seopress](records/wp-seopress.md) | seo | passed | post | http://192.168.1.14:9081/en-us/blog/2026/08/13/cov-wp-seopress-6c3c23-en/ |
| [autodescription](records/autodescription.md) | seo | passed | post | http://192.168.1.14:9081/en-us/blog/2026/08/13/cov-autodescription-82d63a-en/ |
| [slim-seo](records/slim-seo.md) | seo | passed | post | http://192.168.1.14:9081/en-us/blog/2026/08/13/cov-slim-seo-b674f6-en/ |
| [schema-and-structured-data-for-wp](records/schema-and-structured-data-for-wp.md) | seo | passed | post | http://192.168.1.14:9081/en-us/blog/2026/08/13/cov-schema-and-structured-data-for-wp-03dd79-en/ |
| [elementor](records/elementor.md) | page-builders | passed | page | http://192.168.1.14:9081/en-us/cov-elementor-5cbc1d-en/ |
| [classic-editor](records/classic-editor.md) | page-builders | passed | post | http://192.168.1.14:9081/en-us/blog/2026/08/13/cov-classic-editor-373a57-en/ |
| [beaver-builder-lite-version](records/beaver-builder-lite-version.md) | page-builders | passed | page | http://192.168.1.14:9081/en-us/cov-beaver-builder-lite-version-5b889f-en/ |
| [siteorigin-panels](records/siteorigin-panels.md) | page-builders | passed | page | http://192.168.1.14:9081/en-us/cov-siteorigin-panels-6070c8-en/ |
| [kadence-blocks](records/kadence-blocks.md) | page-builders | passed | page | http://192.168.1.14:9081/en-us/cov-kadence-blocks-aa3810-en/ |
| [generateblocks](records/generateblocks.md) | page-builders | passed | page | http://192.168.1.14:9081/en-us/cov-generateblocks-ef6df0-en/ |
| [ultimate-addons-for-gutenberg](records/ultimate-addons-for-gutenberg.md) | page-builders | passed | page | http://192.168.1.14:9081/en-us/cov-ultimate-addons-for-gutenberg-99eb96-en/ |
| [contact-form-7](records/contact-form-7.md) | forms | passed | post | http://192.168.1.14:9081/en-us/blog/2026/08/13/cov-contact-form-7-c2a4f8-en/ |
| [wpforms-lite](records/wpforms-lite.md) | forms | passed | post | http://192.168.1.14:9081/en-us/blog/2026/08/13/cov-wpforms-lite-cbc53e-en/ |
| [formidable](records/formidable.md) | forms | passed | post | http://192.168.1.14:9081/en-us/blog/2026/08/13/cov-formidable-6adebe-en/ |
| [forminator](records/forminator.md) | forms | passed | post | http://192.168.1.14:9081/en-us/blog/2026/08/13/cov-forminator-8bcdf7-en/ |
| [fluentform](records/fluentform.md) | forms | passed | post | http://192.168.1.12:9081/en-us/blog/2026/09/25/cov-fluentform-538c27-en/ |
| [ninja-forms](records/ninja-forms.md) | forms | passed | nf_sub | http://192.168.1.12:9081/en-us/?p=5769 |
| [tablepress](records/tablepress.md) | documents-tables-misc | passed | post | http://192.168.1.14:9081/en-us/blog/2026/08/13/cov-tablepress-ff93fe-en/ |
| [download-monitor](records/download-monitor.md) | documents-tables-misc | passed | dlm_download | http://192.168.1.12:9081/en-us/?p=5921 |
| [newsletter](records/newsletter.md) | documents-tables-misc | passed | page | http://192.168.1.12:9081/en-us/cov-newsletter-507ff4-en/ |
| [mailchimp-for-wp](records/mailchimp-for-wp.md) | documents-tables-misc | passed | page | http://192.168.1.14:9081/en-us/cov-mailchimp-for-wp-5d32bf-en/ |
| [web-stories](records/web-stories.md) | documents-tables-misc | passed | web-story | http://192.168.1.12:9081/en-us/?web-story=cov-en-web-stories-6 |
| [amp](records/amp.md) | documents-tables-misc | passed | post | http://192.168.1.14:9081/en-us/blog/2026/08/13/cov-amp-120f70-en/ |
| [document-library-lite](records/document-library-lite.md) | documents-tables-misc | passed | dlp_document | http://192.168.1.12:9081/en-us/?p=5742 |

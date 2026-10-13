<?php
if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "wp eval-file only\n" ); exit( 1 ); }
global $wpdb;
$own = array(
    'woocommerce' => 'product', 'easy-digital-downloads' => 'download',
    'bbpress' => 'topic', 'tutor' => 'courses', 'learnpress' => 'lp_course',
    'the-events-calendar' => 'tribe_events', 'wp-job-manager' => 'job_listing',
    'envira-gallery-lite' => 'envira', 'seriously-simple-podcasting' => 'podcast',
    'wp-recipe-maker' => 'wprm_recipe', 'site-reviews' => 'site-review',
    'give' => 'give_forms', 'directorist' => 'at_biz_dir',
    'lifterlms' => 'course', 'events-manager' => 'event', 'hivepress' => 'hp_listing',
);
$out = static function ( array $d ) { echo wp_json_encode( $d, JSON_UNESCAPED_SLASHES ) . "\n"; };
$fail = static function ( string $m ) use ( $out ) { $out( array( 'success' => false, 'error' => $m ) ); exit( 1 ); };

# Incremental re-scan so the newly activated plugins get models
# (same chain as the setup wizard / core leg provision).
$plugin_dir = trailingslashit( WP_PLUGIN_DIR ) . 'wpmmcc-ats';
foreach ( array( 'models/scanners/class-model-scanner-v2.php', 'models/scanners/class-runtime-tracker.php' ) as $rel ) {
    $f = $plugin_dir . '/includes/' . $rel;
    if ( is_readable( $f ) ) { require_once $f; }
}
if ( ! class_exists( 'WPTSALL\Models\Scanners\Model_Scanner_V2' ) ) { $fail( 'Model_Scanner_V2 unavailable' ); }
if ( class_exists( 'WPTSALL\Models\Scanners\Runtime_Tracker' ) ) {
    WPTSALL\Models\Scanners\Runtime_Tracker::init();
}
$scanner = new WPTSALL\Models\Scanners\Model_Scanner_V2();
$scanner->set_mode( 'incremental' );
$scan = $scanner->scan_all_plugins();
if ( (int) ( $scan['models_failed'] ?? 1 ) > 0 ) { $fail( 'scan failed: ' . wp_json_encode( $scan ) ); }

$models_table = $wpdb->prefix . 'wptsall_models';
$model_id = static function ( string $slug ) use ( $wpdb, $models_table ): int {
    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$models_table} WHERE plugin_slug = %s ORDER BY is_system ASC, id ASC LIMIT 1", $slug ) );
};

# Dedicated virtual site + relation for the plugin projects (keeps
# the core leg's assertions scoped to its own relation).
$vs_table = $wpdb->prefix . 'wptsall_virtual_sites';
$virtual_id = 'v_plugin_rt';
if ( ! (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$vs_table} WHERE id = %s", $virtual_id ) ) ) {
    $wpdb->insert( $vs_table, array(
        'id' => $virtual_id, 'site_name' => 'Journey Plugins 站',
        'site_path' => '/zh-plugins/', 'site_language' => 'zh_CN',
        'source_blog_id' => 1, 'enable_blog_sync' => 1, 'status' => 'enabled',
    ) );
}
$rel_table = $wpdb->prefix . 'wptsall_site_relations';
$rel_id = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT id FROM {$rel_table} WHERE target_site_id = %s AND source_lang = %s AND target_lang = %s LIMIT 1",
    $virtual_id, 'en_US', 'zh_CN' ) );
if ( $rel_id <= 0 ) {
    $wpdb->insert( $rel_table, array(
        'source_site_id' => 1, 'source_site_type' => 'wp', 'source_lang' => 'en_US',
        'source_theme_name' => 'Twenty Twenty-Five', 'source_theme_path' => 'twentytwentyfive',
        'template' => 'wordpress-blog', 'target_site_id' => $virtual_id,
        'target_site_type' => 'virtual', 'target_lang' => 'zh_CN',
        'target_theme_name' => 'Journey Plugins 站', 'target_theme_path' => 'zh-plugins',
        'media_handling' => 'copy', 'sync_mode' => 'new_only',
        'direction' => 'source_to_target', 'conflict_strategy' => 'source_wins',
        'status' => 'active',
    ) );
    $rel_id = (int) $wpdb->insert_id;
}
if ( $rel_id <= 0 ) { $fail( 'no relation' ); }

# Bind every model the projects need to the relation. Rules live on
# MODELS (not relations), so the existing post rule on wordpress-blog
# applies to the wordpress-seo post item automatically.
$rm_table = $wpdb->prefix . 'wptsall_relation_models';
$bind = static function ( int $mid ) use ( $wpdb, $rm_table, $rel_id ): void {
    if ( $mid <= 0 ) { return; }
    if ( ! (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$rm_table} WHERE relation_id = %d AND model_id = %d", $rel_id, $mid ) ) ) {
        $wpdb->insert( $rm_table, array( 'relation_id' => $rel_id, 'model_id' => $mid ) );
    }
};
$blog_model = $model_id( 'wordpress-blog' );
if ( $blog_model <= 0 ) { $fail( 'wordpress-blog model missing' ); }
$bind( $blog_model );
foreach ( array_keys( $own ) as $slug ) {
    $mid = $model_id( $slug );
    if ( $mid <= 0 ) { $fail( "model missing for $slug (scan did not create it?)" ); }
    $bind( $mid );
}

# Rules: the product's own bulk generation — the same path the
# setup wizard / trigger-scan uses (create_rules_for_model first
# merges the model's scan_result fields into the template, THEN
# generates rules with proper caps: post_title/post_content as
# translate). Hand-rolled create_rule with post caps is rejected
# by the template constraint on fresh plugin models — their post
# objects carry no declared fields until the scan result is
# merged (runner round 2: rule_fields_not_in_template).
if ( ! class_exists( 'WPTSALL\Models\Services\Translation_Rule_Service' ) ) {
    require_once trailingslashit( WP_PLUGIN_DIR ) . 'wpmmcc-ats/includes/models/services/class-translation-rule-service.php';
}
if ( ! class_exists( 'WPTSALL\Models\Services\Translation_Rule_Service' ) ) { $fail( 'Translation_Rule_Service unavailable' ); }
foreach ( array_merge( array( 'wordpress-blog' ), array_keys( $own ) ) as $slug ) {
    $res = WPTSALL\Models\Services\Translation_Rule_Service::create_rules_for_model( $model_id( $slug ) );
    if ( (int) ( $res['errors'] ?? 1 ) > 0 ) { $fail( 'rule generation failed for ' . $slug . ': ' . wp_json_encode( $res ) ); }
    echo 'rules ' . $slug . ': created=' . (int) ( $res['created'] ?? 0 )
        . ' updated=' . (int) ( $res['updated'] ?? 0 )
        . ' skipped=' . (int) ( $res['skipped'] ?? 0 ) . "\n";
}

# Seed one published item per project. bbpress topics need a forum
# parent; everything else seeds bare (CPTs register on activation).
$stamp = gmdate( 'Ymd-His' );
$items = array();
$seed = static function ( string $project, string $plugin, string $cpt, array $extra = array() ) use ( &$items, $stamp ) {
    $title = 'Journey ' . $project . ' ' . $stamp;
    $post_id = wp_insert_post( array_merge( array(
        'post_title' => $title,
        'post_content' => "<!-- wp:paragraph -->\n<p>Content item for the {$plugin} plugin project. The client should discover it via its {$cpt} rule and translate it to Chinese.</p>\n<!-- /wp:paragraph -->\n",
        'post_status' => 'publish', 'post_type' => $cpt, 'post_author' => 1,
    ), $extra ), true );
    if ( is_wp_error( $post_id ) ) { fwrite( STDERR, $post_id->get_error_message() . "\n" ); exit( 1 ); }
    $items[] = array( 'project' => $project, 'plugin' => $plugin, 'cpt' => $cpt, 'post_id' => (int) $post_id );
};
foreach ( $own as $slug => $cpt ) {
    if ( 'bbpress' === $slug ) {
        $forum_id = wp_insert_post( array(
            'post_title' => 'Journey bbpress forum ' . $stamp,
            'post_content' => 'Forum container for the topic item.',
            'post_status' => 'publish', 'post_type' => 'forum', 'post_author' => 1,
        ), true );
        if ( is_wp_error( $forum_id ) ) { fwrite( STDERR, $forum_id->get_error_message() . "\n" ); exit( 1 ); }
        $seed( 'bbpress-content', 'bbpress', 'topic', array( 'post_parent' => (int) $forum_id ) );
    } else {
        $seed( $slug . '-content', $slug, $cpt );
    }
}
$seed( 'elementor-content', 'elementor', 'page' );
$seed( 'wordpress-seo-content', 'wordpress-seo', 'post' );
$seed( 'advanced-custom-fields-content', 'advanced-custom-fields', 'product' );
$out( array( 'success' => true, 'relation_id' => $rel_id, 'items' => $items ) );

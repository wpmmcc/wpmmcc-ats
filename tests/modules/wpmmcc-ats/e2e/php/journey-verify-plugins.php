<?php
if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "wp eval-file only\n" ); exit( 1 ); }
global $wpdb;
$relation_id = (int) getenv( 'JOURNEY_PLUGIN_RELATION_ID' );
$items = json_decode( (string) getenv( 'JOURNEY_PLUGIN_ITEMS' ), true );
$out = static function ( array $d ) { echo wp_json_encode( $d, JSON_UNESCAPED_SLASHES ) . "\n"; };
if ( $relation_id <= 0 || ! is_array( $items ) ) { $out( array( 'pass' => false, 'error' => 'input' ) ); exit( 1 ); }
$pm_table = $wpdb->prefix . 'wptsall_post_mappings';
$prefix = trim( (string) $wpdb->get_var( $wpdb->prepare(
    "SELECT target_theme_path FROM {$wpdb->prefix}wptsall_site_relations WHERE id = %d", $relation_id ) ), '/' );
$projects = array();
$pass = true;
foreach ( $items as $it ) {
    $post_id = (int) ( $it['post_id'] ?? 0 );
    $project = (string) ( $it['project'] ?? '?' );
    $row = array(
        'project' => $project, 'plugin' => (string) ( $it['plugin'] ?? '?' ),
        'cpt' => (string) ( $it['cpt'] ?? '?' ), 'post_id' => $post_id,
        'target_post_id' => 0, 'title_marker' => false, 'content_marker' => false,
        'virtual_url' => '',
    );
    $mapping = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$pm_table} WHERE source_post_id = %d AND relation_id = %d LIMIT 1", $post_id, $relation_id ), ARRAY_A );
    if ( ! empty( $mapping ) ) {
        $target_post_id = (int) $mapping['target_post_id'];
        $target = get_post( $target_post_id );
        $row['target_post_id'] = $target_post_id;
        if ( $target && 'publish' === $target->post_status ) {
            $row['title_marker'] = false !== mb_strpos( $target->post_title, '【zh_CN】' );
            $row['content_marker'] = false !== mb_strpos( $target->post_content, '【zh_CN】' );
            if ( '' !== $prefix && '' !== (string) $target->post_name ) {
                $row['virtual_url'] = '/' . $prefix . '/' . $target->post_name . '/';
            }
        }
    }
    if ( ! $row['title_marker'] || ! $row['content_marker'] || '' === $row['virtual_url'] ) { $pass = false; }
    $projects[] = $row;
}
$out( array( 'pass' => $pass, 'relation_id' => $relation_id, 'projects' => $projects ) );
exit( $pass ? 0 : 1 );

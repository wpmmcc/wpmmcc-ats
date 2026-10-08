<?php
/**
 * Manual-only multilingual gate fixture and assertions.
 *
 * Creates an isolated WPMMCC ATS virtual language site and validates manual
 * plugin capabilities without a desktop/Rust client and without automatic
 * translation: language catalog, virtual-site relation, manual post/meta save,
 * taxonomy mapping, menu mapping, string translation, custom-field hook, and
 * SEO alternate generation.
 *
 * Output: JSON report with URLs for the shell wrapper to curl from the host.
 *
 * @package WPTSALL
 */

use WPTSALL\Languages\Services\Language_Service;
use WPTSALL\Sites\Services\Virtual_Site_Service;
use WPTSALL\Sites\Services\Site_Relation_Service;
use WPTSALL\Sites\Services\Manual_Content_Service;
use WPTSALL\Sites\API\Manual_Translation_REST_Controller;
use WPTSALL\ManualTranslation\Services\Taxonomy_Translation_Service;
use WPTSALL\MenuTranslation\Admin\Menu_Sync_Page;
use WPTSALL\MenuTranslation\Menu_Mapping_Service;
use WPTSALL\Strings\Services\String_Translation_Service;
use WPTSALL\CustomFields\Services\Custom_Field_Translation_Service;
use WPTSALL\Hooks\Virtual_Site_SEO;
use WPTSALL\Hooks\Virtual_Site_Router;
use WPTSALL\Hooks\Virtual_Site_Query_Switch;
use WPTSALL\Settings\Services\Settings_Service;

$started_at = gmdate( 'c' );
$failures   = array();
$assertions = array();
$created    = array(
	'posts'       => array(),
	'terms'       => array(),
	'menus'       => array(),
	'languages'   => array(),
	'virtual_site'=> 0,
	'relation'    => 0,
	'templates'   => array(),
	'strings'     => array(),
);

$ok = static function ( string $name, bool $condition, $detail = null ) use ( &$assertions, &$failures ) {
	$assertions[] = array(
		'name'   => $name,
		'ok'     => $condition,
		'detail' => $detail,
	);
	if ( ! $condition ) {
		$failures[] = array(
			'name'   => $name,
			'detail' => $detail,
		);
	}
};

$emit = static function ( array $report ) {
	echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . PHP_EOL;
};

try {
	if ( ! defined( 'WPTSALL_VERSION' ) ) {
		throw new RuntimeException( 'WPMMCC ATS plugin is not loaded.' );
	}

	foreach ( array(
		'Language_Service'                  => Language_Service::class,
		'Virtual_Site_Service'              => Virtual_Site_Service::class,
		'Site_Relation_Service'             => Site_Relation_Service::class,
		'Manual_Translation_REST_Controller'=> Manual_Translation_REST_Controller::class,
		'Taxonomy_Translation_Service'      => Taxonomy_Translation_Service::class,
		'Menu_Sync_Page'                    => Menu_Sync_Page::class,
		'Menu_Mapping_Service'              => Menu_Mapping_Service::class,
		'String_Translation_Service'        => String_Translation_Service::class,
		'Custom_Field_Translation_Service'  => Custom_Field_Translation_Service::class,
		'Virtual_Site_SEO'                  => Virtual_Site_SEO::class,
		'Virtual_Site_Router'               => Virtual_Site_Router::class,
		'Virtual_Site_Query_Switch'         => Virtual_Site_Query_Switch::class,
	) as $label => $class_name ) {
		$ok( 'class loaded: ' . $label, class_exists( $class_name ), $class_name );
	}

	if ( function_exists( 'wptsall_run_migrations' ) ) {
		wptsall_run_migrations();
	}
	foreach ( array(
		'wptsall_create_languages_table',
		'wptsall_create_virtual_sites_table',
		'wptsall_create_site_relations_table',
		'wptsall_create_field_mapping_tables',
		'wptsall_create_templates_table',
		'wptsall_create_template_entries_table',
		'wptsall_create_strings_table',
		'wptsall_create_menu_mappings_table',
	) as $schema_fn ) {
		if ( function_exists( $schema_fn ) ) {
			call_user_func( $schema_fn );
		}
	}

	global $wpdb, $wp_rest_server;
	$required_tables = array( 'languages', 'virtual_sites', 'site_relations', 'post_mappings', 'term_mappings', 'menu_mappings', 'strings', 'templates', 'template_entries' );
	foreach ( $required_tables as $table_key ) {
		$table = wptsall_table( $table_key );
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		$ok( 'schema table exists: ' . $table_key, $exists === $table, $table );
	}

	$admin = get_user_by( 'login', 'wptsall_manual_gate_admin' );
	if ( ! $admin ) {
		$user_id = wp_insert_user( array(
			'user_login' => 'wptsall_manual_gate_admin',
			'user_pass'  => wp_generate_password( 24, true ),
			'user_email' => 'wptsall-manual-gate@example.invalid',
			'role'       => 'administrator',
		) );
		if ( is_wp_error( $user_id ) ) {
			throw new RuntimeException( 'Could not create gate admin: ' . $user_id->get_error_message() );
		}
		$admin = get_user_by( 'id', (int) $user_id );
	}
	wp_set_current_user( (int) $admin->ID );
	foreach ( array( 'manage_wptsall', 'manage_wptsall_translations', 'manage_wptsall_settings', 'manage_wptsall_sync' ) as $cap ) {
		$admin->add_cap( $cap, true );
	}
	$ok( 'manual translation permission', function_exists( 'wptsall_user_can_manage_translations' ) && wptsall_user_can_manage_translations(), array( 'user_id' => (int) $admin->ID ) );

	$source_lang = get_locale() ?: 'en_US';
	$default_id = Language_Service::upsert( array(
		'code'        => $source_lang,
		'slug'        => strtolower( str_replace( '_', '-', $source_lang ) ),
		'name'        => 'Source ' . $source_lang,
		'native_name' => 'Source ' . $source_lang,
		'locale'      => $source_lang,
		'is_default'  => 1,
		'status'      => 'active',
	) );
	if ( $default_id ) {
		Language_Service::set_default( (int) $default_id );
		$created['languages'][] = (int) $default_id;
	}
	$target_lang = 'fr_FR';
	$target_language_id = Language_Service::upsert( array(
		'code'        => $target_lang,
		'slug'        => 'fr',
		'name'        => 'French',
		'native_name' => 'Français',
		'locale'      => $target_lang,
		'flag'        => '🇫🇷',
		'sort_order'  => 10,
		'status'      => 'active',
	) );
	if ( $target_language_id ) {
		$created['languages'][] = (int) $target_language_id;
	}
	$ok( 'language catalog default exists', is_array( Language_Service::get_default() ) && ! empty( Language_Service::get_default()['code'] ?? '' ), Language_Service::get_default() );
	$ok( 'language catalog target exists', (bool) Language_Service::get_by_code( $target_lang ), Language_Service::get_by_code( $target_lang ) );

	$stamp  = strtolower( substr( md5( uniqid( 'manual-gate-', true ) ), 0, 8 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.md5_md5
	$prefix = 'manual-fr-' . $stamp;
	$vs_result = Virtual_Site_Service::create( array(
		'name'        => 'Manual Gate Français ' . $stamp,
		'path_prefix' => $prefix,
		'lang'        => $target_lang,
	) );
	$ok( 'virtual site create succeeds', ! empty( $vs_result['success'] ) && ! empty( $vs_result['site_id'] ), $vs_result );
	if ( empty( $vs_result['site_id'] ) ) {
		throw new RuntimeException( 'Could not create virtual site.' );
	}
	$vs_id = (string) $vs_result['site_id'];
	$created['virtual_site'] = $vs_id;

	$relation_result = Site_Relation_Service::create_relation( array(
		'source_site_id'    => get_current_blog_id(),
		'source_lang'       => $source_lang,
		'template'          => 'wordpress-blog',
		'auto_create_model' => true,
		'target_sites'      => array(
			array(
				'id'   => $vs_id,
				'type' => 'virtual',
				'lang' => $target_lang,
			),
		),
	) );
	$relation_id = (int) ( $relation_result['relation_ids'][0] ?? 0 );
	$created['relation'] = $relation_id;
	$ok( 'site relation create succeeds', ! empty( $relation_result['success'] ) && $relation_id > 0, $relation_result );
	if ( $relation_id <= 0 ) {
		throw new RuntimeException( 'Could not create site relation.' );
	}
	$vs = Virtual_Site_Service::get_by_relation( $relation_id );
	$ok( 'virtual site resolves by relation', is_array( $vs ) && trim( (string) ( $vs['path_prefix'] ?? '' ), '/' ) === $prefix, $vs );

	if ( class_exists( Settings_Service::class ) ) {
		Settings_Service::update( array(
			'url_form'                  => 'subdir',
			'hreflang_emitter'          => 'wpmmcc-ats',
			'prefer_sitemap_hreflang'   => false,
			'default_language'          => $source_lang,
		) );
	}

	$source_term = wp_insert_term( 'Manual Gate Source Category ' . $stamp, 'category', array( 'slug' => 'manual-gate-source-' . $stamp ) );
	if ( is_wp_error( $source_term ) ) {
		throw new RuntimeException( 'Could not create source term: ' . $source_term->get_error_message() );
	}
	$source_term_id = (int) $source_term['term_id'];
	$created['terms'][] = array( 'term_id' => $source_term_id, 'taxonomy' => 'category' );

	$source_post_id = wp_insert_post( array(
		'post_title'   => 'Manual Gate Source Post ' . $stamp,
		'post_name'    => 'manual-gate-source-post-' . $stamp,
		'post_content' => 'Manual gate source body ' . $stamp,
		'post_excerpt' => 'Manual gate source excerpt ' . $stamp,
		'post_status'  => 'publish',
		'post_type'    => 'post',
		'post_author'  => (int) $admin->ID,
	), true );
	if ( is_wp_error( $source_post_id ) ) {
		throw new RuntimeException( 'Could not create source post: ' . $source_post_id->get_error_message() );
	}
	$source_post_id = (int) $source_post_id;
	$created['posts'][] = $source_post_id;
	wp_set_post_terms( $source_post_id, array( $source_term_id ), 'category', false );
	update_post_meta( $source_post_id, 'manual_gate_meta', 'source meta ' . $stamp );

	$wp_rest_server = new WP_REST_Server();
	do_action( 'rest_api_init' );
	$request = new WP_REST_Request( 'POST', '/wptsall/v2/manual-translations' );
	$request->set_body_params( array(
		'source_post_id'  => $source_post_id,
		'relation_id'     => $relation_id,
		'translated_data' => array(
			'post_title'              => 'Article Manuel Français ' . $stamp,
			'post_name'               => 'article-manuel-francais-' . $stamp,
			'post_content'            => '<p>Contenu manuel français ' . $stamp . '</p>',
			'post_excerpt'            => 'Extrait manuel français ' . $stamp,
			'manual_gate_meta' => 'méta manuelle française ' . $stamp,
		),
	) );
	$response = $wp_rest_server->dispatch( $request );
	$status   = (int) $response->get_status();
	$data     = $response->get_data();
	$target_post_id = (int) ( is_array( $data ) ? ( $data['target_id'] ?? 0 ) : 0 );
	if ( $target_post_id > 0 ) {
		$created['posts'][] = $target_post_id;
	}
	$ok( 'manual REST save succeeds', in_array( $status, array( 200, 201 ), true ) && $target_post_id > 0, array( 'status' => $status, 'data' => $data ) );
	$ok( 'target shadow post has virtual marker', (string) get_post_meta( $target_post_id, '_wptsall_virtual_site_id', true ) === 'v_' . $vs_id || (string) get_post_meta( $target_post_id, '_wptsall_virtual_site_id', true ) === (string) $vs_id, get_post_meta( $target_post_id, '_wptsall_virtual_site_id', true ) );
	$ok( 'manual plugin meta saved to target', get_post_meta( $target_post_id, 'manual_gate_meta', true ) === 'méta manuelle française ' . $stamp, get_post_meta( $target_post_id, 'manual_gate_meta', true ) );
	$mapping_row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE source_post_id = %d AND relation_id = %d AND target_post_id = %d', wptsall_table( 'post_mappings' ), $source_post_id, $relation_id, $target_post_id ), ARRAY_A );
	$ok( 'post mapping saved relation-scoped', is_array( $mapping_row ), $mapping_row );

	// Product associations seam: shadow post must mount shadow terms (not source IDs).
	$relation = Site_Relation_Service::get_relation( $relation_id );
	Manual_Content_Service::sync_term_associations(
		$source_post_id,
		$target_post_id,
		$relation_id,
		is_array( $relation ) ? $relation : array(),
		array( 'related_taxonomies' => array( 'category' ) )
	);
	$target_cats = wp_get_object_terms( $target_post_id, 'category', array( 'fields' => 'ids' ) );
	$target_term_id = ( ! is_wp_error( $target_cats ) && ! empty( $target_cats ) ) ? (int) $target_cats[0] : 0;
	if ( $target_term_id > 0 ) {
		$created['terms'][] = array( 'term_id' => $target_term_id, 'taxonomy' => 'category' );
	}
	$ok( 'associations remap category to shadow term', $target_term_id > 0 && $target_term_id !== $source_term_id, array( 'source' => $source_term_id, 'shadow' => $target_term_id, 'cats' => $target_cats ) );
	$ok(
		'associations shadow category has VS marker',
		$target_term_id > 0 && '' !== (string) get_term_meta( $target_term_id, '_wptsall_virtual_site_id', true ),
		get_term_meta( $target_term_id, '_wptsall_virtual_site_id', true )
	);
	$ok(
		'associations shadow category points to source term',
		$target_term_id > 0 && (string) get_term_meta( $target_term_id, '_wptsall_source_term_id', true ) === (string) $source_term_id,
		get_term_meta( $target_term_id, '_wptsall_source_term_id', true )
	);
	$shadow_term_name = $target_term_id > 0 ? (string) get_term_field( 'name', $target_term_id, 'category' ) : '';
	$shadow_term_slug = $target_term_id > 0 ? (string) get_term_field( 'slug', $target_term_id, 'category' ) : '';
	$ok( 'associations shadow term name/slug ready for HTTP', '' !== $shadow_term_name && '' !== $shadow_term_slug, array( 'name' => $shadow_term_name, 'slug' => $shadow_term_slug ) );

	// Register a manual custom-field string and validate the get_post_metadata hook.
	$string_id = String_Translation_Service::register( 'cpt_field', 'field_manual_gate_meta_' . $source_post_id, get_post_meta( $source_post_id, 'manual_gate_meta', true ), $source_lang );
	if ( $string_id ) {
		$created['strings'][] = (int) $string_id;
		String_Translation_Service::set_translations( (int) $string_id, array( $target_lang => 'champ personnalisé traduit ' . $stamp ) );
	}
	$GLOBALS['wptsall_current_virtual_site'] = array_merge( is_array( $vs ) ? $vs : array(), array( 'relation_id' => $relation_id, 'relation' => Site_Relation_Service::get_relation( $relation_id ) ) );
	if ( class_exists( '\\WPTSALL\\Core\\Language_Context' ) ) {
		\WPTSALL\Core\Language_Context::reset();
		\WPTSALL\Core\Language_Context::set_language( $target_lang );
	}
	$meta_hook_value = Custom_Field_Translation_Service::filter_meta( null, $source_post_id, 'manual_gate_meta', true );
	$ok( 'custom-field translation hook swaps metadata', $meta_hook_value === 'champ personnalisé traduit ' . $stamp, $meta_hook_value );

	// Register a manual UI/site string and verify the public translation filter/service.
	$ui_string_id = String_Translation_Service::register( 'site_title', 'manual_gate_' . $stamp, 'Manual Gate Site Label ' . $stamp, $source_lang );
	if ( $ui_string_id ) {
		$created['strings'][] = (int) $ui_string_id;
		String_Translation_Service::set_translations( (int) $ui_string_id, array( $target_lang => 'Libellé manuel français ' . $stamp ) );
	}
	$translated_string = String_Translation_Service::translate( 'site_title', 'manual_gate_' . $stamp, 'Manual Gate Site Label ' . $stamp, $target_lang );
	$filtered_string   = apply_filters( 'wptsall_translate_string', 'Manual Gate Site Label ' . $stamp, 'manual_gate_' . $stamp, $target_lang, 'site_title' );
	$ok( 'manual string service translates', $translated_string === 'Libellé manuel français ' . $stamp, $translated_string );
	$ok( 'manual string hook/filter translates', $filtered_string === 'Libellé manuel français ' . $stamp, $filtered_string );

	$source_menu_id = wp_create_nav_menu( 'Manual Gate Source Menu ' . $stamp );
	if ( is_wp_error( $source_menu_id ) ) {
		throw new RuntimeException( 'Could not create source menu: ' . $source_menu_id->get_error_message() );
	}
	$source_menu_id = (int) $source_menu_id;
	$created['menus'][] = $source_menu_id;
	wp_update_nav_menu_item( $source_menu_id, 0, array(
		'menu-item-title'     => 'Source Menu Article ' . $stamp,
		'menu-item-object'    => 'post',
		'menu-item-object-id' => $source_post_id,
		'menu-item-type'      => 'post_type',
		'menu-item-status'    => 'publish',
	) );
	$target_menu_id = Menu_Sync_Page::sync_menu( $source_menu_id, $vs_id, 'Menu Manuel Français ' . $stamp );
	$ok( 'manual menu sync succeeds', ! is_wp_error( $target_menu_id ) && (int) $target_menu_id > 0, is_wp_error( $target_menu_id ) ? $target_menu_id->get_error_message() : $target_menu_id );
	if ( ! is_wp_error( $target_menu_id ) && (int) $target_menu_id > 0 ) {
		$created['menus'][] = (int) $target_menu_id;
	}
	$target_menu_lookup = Menu_Mapping_Service::get_target_menu( $source_menu_id, $vs_id );
	$ok( 'menu mapping lookup returns target menu', (int) $target_menu_lookup === (int) $target_menu_id, array( 'lookup' => $target_menu_lookup, 'target' => $target_menu_id ) );
	$filtered_menu_args = Menu_Mapping_Service::filter_nav_menu_args( array( 'menu' => $source_menu_id ) );
	$ok( 'menu hook swaps source menu to target menu', (int) ( $filtered_menu_args['menu'] ?? 0 ) === (int) $target_menu_id, $filtered_menu_args );
	$target_items = ! is_wp_error( $target_menu_id ) ? wp_get_nav_menu_items( (int) $target_menu_id ) : array();
	$target_item_url = '';
	foreach ( (array) $target_items as $item ) {
		$target_item_url = (string) $item->url;
		break;
	}
	$ok( 'target menu item URL is virtualized', '' !== $target_item_url && false !== strpos( $target_item_url, '/' . $prefix . '/' ), $target_item_url );

	// Gettext/template-domain manual translation hook.
	$template_id = \WPTSALL\Templates\Services\Template_Service::create( array(
		'relation_id'       => $relation_id,
		'slug'              => 'manual-gate-wpmmcc-ats-' . $stamp,
		'source_type'       => 'plugin',
		'source_identifier' => 'wpmmcc-ats',
		'text_domain'       => 'wpmmcc-ats',
		'source_name'       => 'WPMMCC ATS',
		'source_version'    => defined( 'WPTSALL_VERSION' ) ? WPTSALL_VERSION : '',
		'source_language'   => $source_lang,
		'target_language'   => $target_lang,
		'status'            => 'active',
	) );
	if ( $template_id ) {
		$created['templates'][] = (int) $template_id;
		\WPTSALL\Templates\Services\Template_Entry_Service::create( array(
			'template_id' => (int) $template_id,
			'msgid'       => 'Manual Gate Gettext ' . $stamp,
			'msgstr'      => 'Gettext manuel français ' . $stamp,
			'status'      => 'translated',
			'source'      => 'manual',
		) );
		\WPTSALL\Templates\Services\Template_Service::update_stats( (int) $template_id );
		\WPTSALL\Templates\Services\Template_Entry_Service::clear_translations_cache( $relation_id, 'wpmmcc-ats' );
		\WPTSALL\Hooks\Gettext_Filter::clear_cache();
		\WPTSALL\Hooks\Gettext_Filter::maybe_init_filters();
		\WPTSALL\Hooks\Gettext_Filter::preload_translations( $relation_id, 'wpmmcc-ats' );
	}
	$gettext = __( 'Manual Gate Gettext ' . $stamp, 'wpmmcc-ats' );
	$ok( 'gettext hook translates wpmmcc-ats domain', false !== strpos( $gettext, 'Gettext manuel français ' . $stamp ), $gettext );

	// SEO alternate payload can be tested without relying on theme wp_head output.
	$alternates = Virtual_Site_SEO::build_sitemap_alternates( $target_post_id );
	$alt_map = array();
	foreach ( $alternates as $alt ) {
		$alt_map[ (string) ( $alt['hreflang'] ?? '' ) ] = (string) ( $alt['href'] ?? '' );
	}
	$target_alt = '';
	foreach ( $alt_map as $lang_key => $href ) {
		if ( strtolower( (string) $lang_key ) === 'fr-fr' ) {
			$target_alt = (string) $href;
			break;
		}
	}
	$ok( 'SEO alternates include target hreflang', '' !== $target_alt && false !== strpos( $target_alt, '/' . $prefix . '/' ), $alt_map );
	$ok( 'SEO alternates include x-default', ! empty( $alt_map['x-default'] ), $alt_map );

	$status_request = new WP_REST_Request( 'GET', '/wptsall/v2/manual-translations/status' );
	$status_request->set_query_params( array( 'source_post_id' => $source_post_id, 'post_type' => 'post' ) );
	$status_response = $wp_rest_server->dispatch( $status_request );
	$ok( 'manual translation status REST succeeds', 200 === (int) $status_response->get_status(), array( 'status' => $status_response->get_status(), 'data' => $status_response->get_data() ) );

	if ( function_exists( 'flush_rewrite_rules' ) ) {
		flush_rewrite_rules();
	}
	wp_cache_flush();

	$source_url = get_permalink( $source_post_id );
	if ( class_exists( '\\WPTSALL\\Sites\\Services\\Url_Converter' ) ) {
		$target_url      = \WPTSALL\Sites\Services\Url_Converter::virtualize( get_permalink( $target_post_id ), $vs );
		$source_path_url = \WPTSALL\Sites\Services\Url_Converter::virtualize( get_permalink( $source_post_id ), $vs );
	} else {
		$target_url      = home_url( '/' . trim( $prefix, '/' ) . '/' . get_post_field( 'post_name', $target_post_id ) . '/' );
		$source_path_url = home_url( '/' . trim( $prefix, '/' ) . '/' . get_post_field( 'post_name', $source_post_id ) . '/' );
	}
	$term_url = home_url( '/' . trim( $prefix, '/' ) . '/category/' . $shadow_term_slug . '/' );
	$home_url = home_url( '/' . trim( $prefix, '/' ) . '/' );

	$report = array(
		'ok'              => empty( $failures ),
		'started_at'      => $started_at,
		'finished_at'     => gmdate( 'c' ),
		'plugin_version'  => defined( 'WPTSALL_VERSION' ) ? WPTSALL_VERSION : '',
		'mode'            => 'manual-only-no-client-no-auto-translation',
		'prefix'          => $prefix,
		'source_lang'     => $source_lang,
		'target_lang'     => $target_lang,
		'virtual_site_id' => $vs_id,
		'relation_id'     => $relation_id,
		'source_post_id'  => $source_post_id,
		'target_post_id'  => $target_post_id,
		'source_term_id'  => $source_term_id,
		'target_term_id'  => $target_term_id,
		'source_menu_id'  => $source_menu_id,
		'target_menu_id'  => is_wp_error( $target_menu_id ) ? 0 : (int) $target_menu_id,
		'urls'            => array(
			'home'        => $home_url,
			'source'      => $source_url,
			'virtual'     => $target_url,
			'virtual_alt' => $source_path_url,
			'term'        => $term_url,
		),
		'expected'        => array(
			'title'        => 'Article Manuel Français ' . $stamp,
			'content'      => 'Contenu manuel français ' . $stamp,
			'meta'         => 'méta manuelle française ' . $stamp,
			'custom_field' => 'champ personnalisé traduit ' . $stamp,
			'string'       => 'Libellé manuel français ' . $stamp,
			'gettext'      => 'Gettext manuel français ' . $stamp,
			'term'         => $shadow_term_name,
			'menu_url_part'=> '/' . $prefix . '/',
			'hreflang'     => 'fr-fr',
		),
		'assertions'      => $assertions,
		'failures'        => $failures,
	);
	$emit( $report );
	exit( empty( $failures ) ? 0 : 1 );
} catch ( Throwable $e ) {
	$emit( array(
		'ok'          => false,
		'started_at'  => $started_at,
		'finished_at' => gmdate( 'c' ),
		'mode'        => 'manual-only-no-client-no-auto-translation',
		'error'       => array(
			'type'    => get_class( $e ),
			'message' => $e->getMessage(),
			'file'    => $e->getFile(),
			'line'    => $e->getLine(),
		),
		'assertions'  => $assertions,
		'failures'    => $failures,
	) );
	exit( 1 );
}

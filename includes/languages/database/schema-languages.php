<?php
/**
 * WPTSALL Languages Table Schema
 *
 * Stores the site-language catalog used by manual translation, virtual-site
 * routing, admin switchers, and SEO hreflang generation. This is independent
 * of site_relations so admins can manage languages before creating relations.
 *
 * @package WPTSALL\Languages
 * @since 2.3.1
 */
/**
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create wp_wptsall_languages table.
 *
 * @return void
 */
function wptsall_create_languages_table() {
	global $wpdb;
	$table_name      = function_exists( 'wptsall_table' ) ? wptsall_table( 'languages' ) : $wpdb->prefix . 'wptsall_languages';
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		code VARCHAR(20) NOT NULL COMMENT 'Locale/language code, e.g. en_US',
		slug VARCHAR(50) NOT NULL DEFAULT '' COMMENT 'URL slug or short language code, e.g. en',
		name VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'Admin display name',
		native_name VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'Native language name',
		locale VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'WordPress locale to switch to',
		flag VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'Optional flag/emoji/css token',
		direction VARCHAR(3) NOT NULL DEFAULT 'ltr' COMMENT 'ltr|rtl',
		sort_order INT NOT NULL DEFAULT 0,
		is_default TINYINT(1) NOT NULL DEFAULT 0,
		status VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active|inactive',
		created_at DATETIME NOT NULL,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY (id),
		UNIQUE KEY unique_code (code),
		UNIQUE KEY unique_slug (slug),
		INDEX idx_default (is_default),
		INDEX idx_status (status),
		INDEX idx_sort (sort_order)
	) {$charset_collate} ENGINE=InnoDB COMMENT='WPTSALL site languages catalog';";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	wptsall_seed_default_languages();

	wptsall_log(
		'database',
		'info',
		'Languages table created',
		array(
			'table'   => $table_name,
			'version' => defined( 'WPTSALL_VERSION' ) ? WPTSALL_VERSION : '',
		)
	);
}

/**
 * Ensure at least one default source-language row exists.
 *
 * Manual multilingual setup starts with language management. A fresh or
 * upgraded install must therefore have a usable default language before any
 * site relation, string, menu, or taxonomy mapping is created.
 *
 * @return void
 */
function wptsall_seed_default_languages() {
	global $wpdb;
	$table_name = function_exists( 'wptsall_table' ) ? wptsall_table( 'languages' ) : $wpdb->prefix . 'wptsall_languages';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
	if ( $table_exists !== $table_name ) {
		return;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$has_default = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE is_default = 1', $table_name ) );
	if ( $has_default > 0 ) {
		return;
	}

	$settings = get_option( 'wptsall_settings', array() );
	$code     = is_array( $settings ) && ! empty( $settings['default_language'] ) ? (string) $settings['default_language'] : get_locale();
	$code     = '' !== $code ? $code : 'en_US';
	$slug     = strtolower( str_replace( '_', '-', $code ) );
	$name     = $code;

	// Do not call wp_get_available_translations() here: activation/migration
	// must be offline-safe and must not contact wordpress.org just to seed the
	// local language catalog.
	$known_names = array(
		'en_US' => 'English (United States)',
		'zh_CN' => '简体中文',
		'zh_TW' => '繁體中文',
		'ja'    => '日本語',
		'ko_KR' => '한국어',
		'fr_FR' => 'Français',
		'de_DE' => 'Deutsch',
		'es_ES' => 'Español',
	);
	if ( isset( $known_names[ $code ] ) ) {
		$name = $known_names[ $code ];
	}

	// If the language row already exists but no row is marked default, promote it.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$existing_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE code = %s LIMIT 1', $table_name, $code ) );
	if ( $existing_id > 0 ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update( $table_name, array( 'is_default' => 1, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $existing_id ), array( '%d', '%s' ), array( '%d' ) );
		return;
	}

	$now = current_time( 'mysql' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	$wpdb->insert(
		$table_name,
		array(
			'code'        => sanitize_text_field( $code ),
			'slug'        => sanitize_title( $slug ),
			'name'        => sanitize_text_field( $name ),
			'native_name' => sanitize_text_field( $name ),
			'locale'      => sanitize_text_field( $code ),
			'flag'        => '',
			'direction'   => is_rtl() ? 'rtl' : 'ltr',
			'sort_order'  => 0,
			'is_default'  => 1,
			'status'      => 'active',
			'created_at'  => $now,
			'updated_at'  => $now,
		),
		array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
	);
}

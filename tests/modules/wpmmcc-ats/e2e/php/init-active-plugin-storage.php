<?php
/**
 * E2E Lab plugin storage bootstrap.
 *
 * Some third-party plugins lazily create auxiliary tables during their own
 * onboarding/migration routines. Matrix slots activate plugins through WP-CLI
 * and then immediately visit wp-admin; run bounded, plugin-specific installers
 * so admin pages do not fatal on missing plugin tables.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$summary = array(
	'give' => array(
		'active'  => false,
		'actions' => array(),
	),
);

if ( is_plugin_active( 'give/give.php' ) || class_exists( 'Give' ) || function_exists( 'give' ) ) {
	$summary['give']['active'] = true;

	// Preferred GiveWP installer hooks across recent versions.
	foreach ( array(
		'give_install',
		'give_create_tables',
	) as $function ) {
		if ( function_exists( $function ) ) {
			try {
				$function();
				$summary['give']['actions'][] = $function;
			} catch ( Throwable $e ) {
				$summary['give']['actions'][] = $function . ':error:' . $e->getMessage();
			}
		}
	}

	if ( class_exists( 'Give\\Install' ) ) {
		foreach ( array( 'install', 'create_tables' ) as $method ) {
			if ( is_callable( array( 'Give\\Install', $method ) ) ) {
				try {
					call_user_func( array( 'Give\\Install', $method ) );
					$summary['give']['actions'][] = 'Give\\Install::' . $method;
				} catch ( Throwable $e ) {
					$summary['give']['actions'][] = 'Give\\Install::' . $method . ':error:' . $e->getMessage();
				}
			}
		}
	}

	// Fail-safe for the FormBuilder route builder fatal observed in matrix slots:
	// it SELECTs from wp_give_campaigns during admin_enqueue_scripts. Creating an
	// empty table is enough for admin rendering; Give's own migrations can enrich
	// it later when available.
	global $wpdb;
	$table = $wpdb->prefix . 'give_campaigns';
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	if ( ! $exists ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				form_id bigint(20) unsigned NOT NULL DEFAULT 0,
				campaign_type varchar(32) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY form_id (form_id),
				KEY campaign_type (campaign_type)
			) {$charset_collate};"
		);
		$summary['give']['actions'][] = 'created_minimal_give_campaigns';
	}
}

echo wp_json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";

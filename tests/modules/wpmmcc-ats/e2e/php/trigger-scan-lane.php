<?php
/**
 * E2E v2 lane-scoped model scan (matrix parallel).
 *
 * Scans only the current E2E_PROJECT plugin slugs — no global scan-all flock.
 */

function e2e_trigger_scan_lane_plugins(): bool {
	global $wpdb;

	$models_table = e2e_table( 'models' );
	$rules_table  = e2e_table( 'translation_rules' );
	$slugs        = e2e_lane_model_plugin_slugs();

	echo '  lane=' . e2e_lane_namespace() . ' slugs=' . implode( ',', $slugs ) . "\n";

	wp_set_current_user( 1 );

	$scanner = new WPTSALL\Models\Scanners\Model_Scanner_V2();
	$scanner->set_mode( 'incremental' );

	$scan_ok = 0;
	$scan_fail = 0;

	foreach ( $slugs as $plugin_slug ) {
		echo "  scanning {$plugin_slug}...\n";
		$scan_result = $scanner->scan_plugin( $plugin_slug );
		if ( is_wp_error( $scan_result ) ) {
			++$scan_fail;
			echo '    FAIL scan: ' . $scan_result->get_error_message() . "\n";
			continue;
		}
		$save_result = $scanner->save_scan_result( $scan_result );
		if ( is_wp_error( $save_result ) ) {
			++$scan_fail;
			echo '    FAIL save: ' . $save_result->get_error_message() . "\n";
			continue;
		}
		++$scan_ok;
		echo '    OK model_id=' . (int) ( $save_result['model_id'] ?? 0 ) . "\n";
	}

	if ( $scan_ok === 0 ) {
		echo "ERROR: lane scan produced no models\n";
		return false;
	}

	$rule_created = 0;
	$rule_updated  = 0;
	$rule_failed   = 0;

	$placeholders = implode( ',', array_fill( 0, count( $slugs ), '%s' ) );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$models = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, plugin_slug FROM {$models_table} WHERE status = 'active' AND plugin_slug IN ($placeholders) ORDER BY id ASC",
			...$slugs
		),
		ARRAY_A
	);

	foreach ( $models as $model ) {
		$model_id    = (int) ( $model['id'] ?? 0 );
		$plugin_slug = (string) ( $model['plugin_slug'] ?? '' );
		if ( $model_id <= 0 ) {
			continue;
		}
		$result = WPTSALL\Models\Services\Translation_Rule_Service::create_rules_for_model( $model_id );
		if ( is_wp_error( $result ) ) {
			++$rule_failed;
			echo "  [FAIL] {$plugin_slug}: " . $result->get_error_message() . "\n";
			continue;
		}
		$rule_created += (int) ( $result['created'] ?? 0 );
		$rule_updated += (int) ( $result['updated'] ?? 0 );
		echo "  [OK] {$plugin_slug}: created=" . (int) ( $result['created'] ?? 0 ) . ', updated=' . (int) ( $result['updated'] ?? 0 ) . "\n";
	}

	echo "  lane scan_ok={$scan_ok} scan_fail={$scan_fail} rules created={$rule_created} updated={$rule_updated} failed={$rule_failed}\n";

	return $rule_failed === 0 && e2e_table_count( $rules_table ) > 0;
}

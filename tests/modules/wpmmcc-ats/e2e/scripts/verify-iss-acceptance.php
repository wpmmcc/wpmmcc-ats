#!/usr/bin/env php
<?php
/**
 * ISS acceptance matrix — runs inside WP (wp eval-file).
 *
 * Proves P-A6/A10, S1–S7 core, T4/T5/T6 subset with real DB/HTTP assertions.
 *
 * Usage:
 *   wp eval-file tests/modules/wpmmcc-ats/e2e/scripts/verify-iss-acceptance.php --allow-root
 *
 * Exit 0 only when hard_fail=0.
 *
 * @package WPTSALL\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Must run via wp eval-file\n" );
	exit( 2 );
}

$report = array(
	'generated'  => gmdate( 'c' ),
	'checks'     => array(),
	'hard_fail'  => 0,
	'soft_fail'  => 0,
	'pass'       => 0,
);

$record = static function ( &$report, $id, $ok, $msg, $hard = true ) {
	$report['checks'][] = array(
		'id'   => $id,
		'ok'   => (bool) $ok,
		'msg'  => (string) $msg,
		'hard' => (bool) $hard,
	);
	if ( $ok ) {
		++$report['pass'];
	} elseif ( $hard ) {
		++$report['hard_fail'];
	} else {
		++$report['soft_fail'];
	}
};

// Ensure migrations / schema.
if ( function_exists( 'wptsall_run_migrations' ) ) {
	wptsall_run_migrations();
}
if ( class_exists( '\\WPTSALL\\Sync\\Services\\Field_Ownership_Service' ) ) {
	\WPTSALL\Sync\Services\Field_Ownership_Service::ensure_schema();
}
if ( class_exists( '\\WPTSALL\\Core\\Capabilities' ) ) {
	\WPTSALL\Core\Capabilities::ensure_caps();
}

// --- S1 route secret ---
$secret = function_exists( 'wptsall_get_client_route_secret' ) ? wptsall_get_client_route_secret() : '';
$record( $report, 'S1_secret_entropy', strlen( $secret ) >= 32, 'len=' . strlen( $secret ) );
global $wpdb;
$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name=%s", 'wptsall_client_route_secret' ) );
$record( $report, 'S1_autoload_no', in_array( (string) $autoload, array( 'no', 'off', '0' ), true ), 'autoload=' . $autoload );

// S5: the shared logger must never persist credentials from arbitrary nested
// context fields or message strings. Exercise the redactor directly so the
// proof has no dependency on the Lab's current logging level.
$log_redacted = function_exists( 'wptsall_redact_log_context' )
	? wptsall_redact_log_context(
		array(
			'client_token' => 'iss-secret-client-token',
			'nested'       => array( 'password' => 'iss-secret-password', 'safe' => 'visible' ),
			'url'          => 'https://alice:iss-secret-url@example.invalid/path?api_key=iss-secret-query',
		)
	)
	: array();
$log_message_redacted = function_exists( 'wptsall_redact_log_value' )
	? wptsall_redact_log_value( 'Authorization: Bearer iss-secret-bearer' )
	: '';
$log_redaction_ok = is_array( $log_redacted )
	&& '[REDACTED]' === (string) ( $log_redacted['client_token'] ?? '' )
	&& '[REDACTED]' === (string) ( $log_redacted['nested']['password'] ?? '' )
	&& 'visible' === (string) ( $log_redacted['nested']['safe'] ?? '' )
	&& false === strpos( wp_json_encode( $log_redacted ), 'iss-secret-' )
	&& false === strpos( (string) $log_message_redacted, 'iss-secret-bearer' );
$record( $report, 'S5_log_redaction', $log_redaction_ok, $log_redaction_ok ? 'nested credentials redacted' : wp_json_encode( $log_redacted ) );

// --- S2 device token ---
$issued = function_exists( 'wptsall_issue_client_device_token' )
	? wptsall_issue_client_device_token( 'iss-accept-' . wp_generate_password( 8, false ), 'iss-acceptance' )
	: null;
$ok_issue = is_array( $issued ) && ! empty( $issued['token'] ) && ! empty( $issued['device_id'] );
$record( $report, 'S2_issue_device', $ok_issue, $ok_issue ? 'issued' : 'issue failed' );
$verify_ok = $ok_issue && wptsall_client_token_service()->verify_client_token( $issued['token'], $issued['device_id'] );
$record( $report, 'S2_verify_device', $verify_ok, 'verify device token' );
$expiry_shape_ok = $ok_issue && isset( $issued['expires_at'], $issued['expires_in'] )
	&& (int) $issued['expires_at'] > time() && (int) $issued['expires_in'] > 0;
$record( $report, 'S2_expiry_metadata', $expiry_shape_ok, 'device token has bounded expiry metadata' );
$revoked = $ok_issue && wptsall_revoke_client_device_token( $issued['device_id'] );
$record( $report, 'S2_revoke', $revoked, 'revoke' );
$after_revoke = $ok_issue && ! wptsall_client_token_service()->verify_client_token( $issued['token'], $issued['device_id'] );
$record( $report, 'S2_revoked_401', $after_revoke, 'revoked token rejected' );

// Protocol v2 must not fall back to the installation-level legacy bearer
// token, and a token is bound to the device id it was issued for.
$device_b = function_exists( 'wptsall_issue_client_device_token' )
	? wptsall_issue_client_device_token( 'iss-accept-b-' . wp_generate_password( 8, false ), 'iss-acceptance-b' )
	: null;
$wrong_device = is_array( $device_b ) && ! empty( $device_b['token'] )
		&& ! wptsall_client_token_service()->verify_client_token( $device_b['token'], 'iss-accept-wrong', true );
$record( $report, 'S2_wrong_device_rejected', $wrong_device, 'device token cannot cross device scope' );
// Install-level bearer is retired; planted leftovers must never authenticate.
update_option( 'wptsall_client_api_token', 'planted-legacy-install-token', false );
$legacy_v2_rejected = ! wptsall_client_token_service()->verify_client_token( 'planted-legacy-install-token', 'iss-legacy-device', true );
$record( $report, 'S2_legacy_v2_rejected', $legacy_v2_rejected, 'legacy installation token rejected' );
$install_cleared = function_exists( 'wptsall_get_client_api_token' ) && '' === (string) wptsall_get_client_api_token( false );
$record( $report, 'S2_install_token_retired', $install_cleared, 'get_client_api_token clears install-level option' );
$expired_issued = function_exists( 'wptsall_issue_client_device_token' )
	? wptsall_issue_client_device_token( 'iss-expired-' . wp_generate_password( 8, false ), 'iss-expiry', 0 )
	: null;
$expired_rejected = is_array( $expired_issued ) && ! empty( $expired_issued['token'] )
	&& isset( $expired_issued['expires_at'] ) && (int) $expired_issued['expires_at'] <= time()
	&& ! wptsall_client_token_service()->verify_client_token( $expired_issued['token'], $expired_issued['device_id'], true );
$record( $report, 'S2_expired_rejected', $expired_rejected, 'expired device token rejected' );
$permission_probe = null;
if ( class_exists( '\WPTSALL\Tasks\API\Client_Data_REST_Controller' ) ) {
	update_option( 'wptsall_client_api_token', 'planted-legacy-install-token', false );
	$permission_request = new WP_REST_Request( 'GET', '/wptsall/v2/client/site-relations' );
	$permission_request->set_header( 'X-WPTSALL-Protocol-Version', '2' );
	$permission_request->set_header( 'X-WPTSALL-Client-Token', 'planted-legacy-install-token' );
	$permission_request->set_header( 'X-WPTSALL-Device-Id', 'iss-legacy-device' );
	$permission_probe = ( new \WPTSALL\Tasks\API\Client_Data_REST_Controller() )->check_client_permission( $permission_request );
}
$record( $report, 'S2_permission_legacy_v2_rejected', is_wp_error( $permission_probe ) && 401 === (int) $permission_probe->get_error_data()['status'], 'REST permission rejects legacy token on Protocol v2' );

// Exercise the actual Protocol v2 HMAC verifier (same canonical bytes as the
// Rust client) at runtime, including replay protection.
$sig_token = is_array( $device_b ) ? (string) ( $device_b['token'] ?? '' ) : '';
$sig_nonce = 'iss-signature-' . wp_generate_uuid4();
$sig_body  = '{"probe":"iss"}';
$sig_route = '/wptsall/v2/unit-secret/client/validate-token';
$sig_ts    = (string) time();
$sig_key   = class_exists( '\WPTSALL\Core\Transport_Crypto' )
	? \WPTSALL\Core\Transport_Crypto::derive_signing_key( $sig_token )
	: false;
$sig_canonical = "POST\n" . $sig_route . "\n" . $sig_ts . "\n" . $sig_nonce . "\n" . hash( 'sha256', $sig_body ) . "\n" . hash( 'sha256', '' );
$sig_value = false === $sig_key ? '' : rtrim( strtr( base64_encode( hash_hmac( 'sha256', $sig_canonical, $sig_key, true ) ), '+/', '-_' ), '=' );
$sig_request = new WP_REST_Request( 'POST', $sig_route );
$sig_request->set_header( 'X-WPTSALL-Protocol-Version', '2' );
$sig_request->set_header( 'X-WPTSALL-Client-Token', $sig_token );
$sig_request->set_header( 'X-WPTSALL-Timestamp', $sig_ts );
$sig_request->set_header( 'X-WPTSALL-Signature-Nonce', $sig_nonce );
$sig_request->set_header( 'X-WPTSALL-Signature', $sig_value );
$sig_validator = new ReflectionMethod( '\WPTSALL\Core\Transport_Middleware', 'validate_protocol_v2_request' );
$sig_validator->setAccessible( true );
$sig_ok = '' !== $sig_token && true === $sig_validator->invoke( null, $sig_request, $sig_body, $sig_token );
$record( $report, 'S2_signature_valid', $sig_ok, 'Protocol v2 HMAC signature accepted' );
$sig_replay = $sig_validator->invoke( null, $sig_request, $sig_body, $sig_token );
$record( $report, 'S2_signature_replay_rejected', is_wp_error( $sig_replay ) && 'request_signature_replay' === $sig_replay->get_error_code(), 'signature nonce replay rejected' );

// --- S4 component trust ---
$digest = hash( 'sha256', 'iss-component' );
\WPTSALL\Core\Component_Trust::revoke( $digest, 'iss-test' );
$denied = \WPTSALL\Core\Component_Trust::assert_component_trusted(
	array(
		'digest'       => $digest,
		'capabilities' => array( 'translate' ),
	)
);
$record( $report, 'S4_revoke_blocks', is_wp_error( $denied ), is_wp_error( $denied ) ? $denied->get_error_code() : 'not blocked' );
$ok_comp = \WPTSALL\Core\Component_Trust::assert_component_trusted(
	array(
		'digest'       => hash( 'sha256', 'fresh-' . microtime( true ) ),
		'capabilities' => array( 'translate' ),
		'expires_at'   => gmdate( 'c', time() + 3600 ),
	)
);
$record( $report, 'S4_allow_fresh', true === $ok_comp, 'fresh component allowed' );
$bad_cap = \WPTSALL\Core\Component_Trust::assert_component_trusted(
	array(
		'digest'       => hash( 'sha256', 'cap-' . microtime( true ) ),
		'capabilities' => array( 'shell_exec' ),
	)
);
$record( $report, 'S4_cap_deny', is_wp_error( $bad_cap ), is_wp_error( $bad_cap ) ? $bad_cap->get_error_code() : 'cap allowed wrongly' );

// --- S7 SSRF ---
$ssrf = \WPTSALL\Core\Egress_Guard::assert_provider_url_allowed( 'http://127.0.0.1/secret' );
$record( $report, 'S7_block_localhost', is_wp_error( $ssrf ), is_wp_error( $ssrf ) ? $ssrf->get_error_code() : 'localhost allowed' );
$ssrf2 = \WPTSALL\Core\Egress_Guard::assert_provider_url_allowed( 'http://169.254.169.254/latest/meta-data/' );
$record( $report, 'S7_block_metadata', is_wp_error( $ssrf2 ), is_wp_error( $ssrf2 ) ? $ssrf2->get_error_code() : 'metadata allowed' );
$http = wp_remote_get(
	'http://127.0.0.1/',
	array(
		'timeout'             => 2,
		'wptsall_egress_check' => true,
	)
);
$record( $report, 'S7_pre_http_block', is_wp_error( $http ), is_wp_error( $http ) ? $http->get_error_code() : 'http not blocked' );

// --- S6 capabilities ---
$translator = get_role( 'wptsall_translator' );
$record( $report, 'S6_translator_role', (bool) $translator, 'translator role exists' );
$uid = wp_insert_user(
	array(
		'user_login' => 'iss_translator_' . wp_generate_password( 6, false ),
		'user_pass'  => wp_generate_password( 16 ),
		'role'       => 'wptsall_translator',
	)
);
$record( $report, 'S6_translator_user', ! is_wp_error( $uid ) && $uid > 0, is_wp_error( $uid ) ? $uid->get_error_message() : 'uid=' . $uid );
if ( ! is_wp_error( $uid ) ) {
	wp_set_current_user( $uid );
	$record( $report, 'S6_translator_no_security', ! wptsall_user_can_manage_security(), 'translator cannot security' );
	$record( $report, 'S6_translator_no_settings', ! wptsall_user_can_manage_settings(), 'translator cannot settings' );
	$record( $report, 'S6_translator_no_sync', ! wptsall_user_can( 'manage_wptsall_sync' ), 'translator cannot sync' );
	$record( $report, 'S6_translator_has_translations', wptsall_user_can_manage_translations(), 'translator can translations' );
	wp_set_current_user( 1 );
}

// The role split is only meaningful if admin screens no longer retain direct
// manage_options gates. The compatibility fallback stays confined to the core
// helper; all product admin entry points must use a WPTSALL-specific cap.
$admin_cap_files = array_merge(
	glob( WP_PLUGIN_DIR . '/wptsall/includes/*/admin/*.php' ),
	array(
		WP_PLUGIN_DIR . '/wptsall/includes/admin/menu.php',
		WP_PLUGIN_DIR . '/wptsall/includes/admin/class-initialization-page.php',
		WP_PLUGIN_DIR . '/wptsall/includes/wizard/class-setup-wizard.php',
	)
);
$raw_manage_options = array();
foreach ( array_unique( $admin_cap_files ) as $admin_cap_file ) {
	if ( is_readable( $admin_cap_file ) && false !== strpos( (string) file_get_contents( $admin_cap_file ), 'manage_options' ) ) {
		$raw_manage_options[] = basename( $admin_cap_file );
	}
}
$record( $report, 'S6_admin_no_raw_manage_options', empty( $raw_manage_options ), empty( $raw_manage_options ) ? 'granular caps only' : implode( ',', $raw_manage_options ) );

// S6 object-level REST authorization. Capability checks alone are not enough:
// addressed model objects must exist and nested object/field IDs must belong to
// the model in the URL. Exercise the permission callbacks directly with real
// seeded rows so cross-object access is rejected before mutation callbacks run.
$object_auth_model_rows = $wpdb->get_results(
	"SELECT id FROM {$wpdb->prefix}wptsall_models ORDER BY id LIMIT 2",
	ARRAY_A
);
$object_auth_rows = $wpdb->get_results(
	"SELECT id, model_id FROM {$wpdb->prefix}wptsall_model_objects ORDER BY id LIMIT 20",
	ARRAY_A
);
$object_auth_ok = false;
if ( count( $object_auth_model_rows ) >= 2 && count( $object_auth_rows ) >= 2
	&& class_exists( '\WPTSALL\Models\API\Model_Objects_REST_Controller' ) ) {
	$owner_model_id = (int) $object_auth_model_rows[0]['id'];
	$other_model_id = (int) $object_auth_model_rows[1]['id'];
	$foreign_object_id = 0;
	foreach ( $object_auth_rows as $object_auth_row ) {
		if ( (int) $object_auth_row['model_id'] === $other_model_id ) {
			$foreign_object_id = (int) $object_auth_row['id'];
			break;
		}
	}
	if ( $owner_model_id > 0 && $other_model_id > 0 && $foreign_object_id > 0 ) {
		$object_auth_request = new WP_REST_Request(
			'POST',
			'/wptsall/v2/models/' . $owner_model_id . '/objects/' . $foreign_object_id
		);
		$object_auth_request->set_param( 'id', $owner_model_id );
		$object_auth_request->set_param( 'object_id', $foreign_object_id );
		$object_auth_result = ( new \WPTSALL\Models\API\Model_Objects_REST_Controller() )->check_permission( $object_auth_request );
		$object_auth_ok = is_wp_error( $object_auth_result )
			&& 'rest_object_forbidden' === $object_auth_result->get_error_code();
	}
}
$record( $report, 'S6_rest_object_owner_guard', $object_auth_ok, $object_auth_ok ? 'foreign model object rejected in permission callback' : 'object ownership guard not proven' );

$model_id_guard_ok = false;
if ( class_exists( '\WPTSALL\Models\API\Translation_Rule_REST_Controller' ) ) {
	$model_guard_request = new WP_REST_Request( 'GET', '/wptsall/v2/models/2147483647' );
	$model_guard_request->set_param( 'id', 2147483647 );
	$model_guard_result = ( new \WPTSALL\Models\API\Translation_Rule_REST_Controller() )->check_permission( $model_guard_request );
	$model_id_guard_ok = is_wp_error( $model_guard_result )
		&& 'rest_object_not_found' === $model_guard_result->get_error_code();
}
$record( $report, 'S6_rest_model_existence_guard', $model_id_guard_ok, $model_id_guard_ok ? 'missing model rejected in permission callback' : 'model existence guard not proven' );

// S6 site-management REST object authorization.  These routes used to defer
// object existence checks to their callbacks, allowing an authenticated caller
// to reach callback/plugin hooks with fabricated relation or virtual-site IDs.
// Prove that the Sites permission callback now rejects those references first.
$site_relation_guard_ok = false;
$virtual_site_guard_ok  = false;
$bulk_virtual_guard_ok  = false;
if ( class_exists( '\WPTSALL\\Sites\\API\\Sites_REST_Controller' ) ) {
	$sites_controller = new \WPTSALL\Sites\API\Sites_REST_Controller();
	$relation_guard_request = new WP_REST_Request( 'GET', '/wptsall/v2/site-relations/2147483647/targets' );
	$relation_guard_request->set_param( 'id', 2147483647 );
	$relation_guard_result = $sites_controller->check_permission( $relation_guard_request );
	$site_relation_guard_ok = is_wp_error( $relation_guard_result )
		&& 'rest_object_not_found' === $relation_guard_result->get_error_code();

	$virtual_guard_request = new WP_REST_Request( 'GET', '/wptsall/v2/virtual-sites/2147483647' );
	$virtual_guard_request->set_param( 'id', 2147483647 );
	$virtual_guard_result = $sites_controller->check_permission( $virtual_guard_request );
	$virtual_site_guard_ok = is_wp_error( $virtual_guard_result )
		&& 'rest_object_not_found' === $virtual_guard_result->get_error_code();

	$bulk_virtual_guard_request = new WP_REST_Request( 'POST', '/wptsall/v2/virtual-sites/bulk-delete' );
	$bulk_virtual_guard_request->set_param( 'site_ids', array( 2147483647 ) );
	$bulk_virtual_guard_result = $sites_controller->check_permission( $bulk_virtual_guard_request );
	$bulk_virtual_guard_ok = is_wp_error( $bulk_virtual_guard_result )
		&& 'rest_object_not_found' === $bulk_virtual_guard_result->get_error_code();
}
$record( $report, 'S6_rest_site_relation_existence_guard', $site_relation_guard_ok, $site_relation_guard_ok ? 'missing relation rejected in permission callback' : 'site relation permission guard not proven' );
$record( $report, 'S6_rest_virtual_site_existence_guard', $virtual_site_guard_ok, $virtual_site_guard_ok ? 'missing virtual site rejected in permission callback' : 'virtual site permission guard not proven' );
$record( $report, 'S6_rest_bulk_virtual_guard', $bulk_virtual_guard_ok, $bulk_virtual_guard_ok ? 'missing bulk virtual site rejected in permission callback' : 'bulk virtual permission guard not proven' );

// Tasks use several distinct references (single task IDs, relation IDs, job
// IDs and task-ID batches).  Exercise each permission path with a fabricated
// ID so a later callback refactor cannot silently re-open that boundary.
$task_object_guard_ok    = false;
$task_relation_guard_ok  = false;
$task_job_guard_ok       = false;
$task_batch_guard_ok     = false;
if ( class_exists( '\WPTSALL\\Tasks\\API\\Tasks_REST_Controller' ) ) {
	$tasks_controller = new \WPTSALL\Tasks\API\Tasks_REST_Controller();
	$task_guard_request = new WP_REST_Request( 'POST', '/wptsall/v2/tasks/2147483647/manual-queue/resolve' );
	$task_guard_request->set_param( 'id', 2147483647 );
	$task_guard_result = $tasks_controller->check_permission( $task_guard_request );
	$task_object_guard_ok = is_wp_error( $task_guard_result )
		&& 'rest_object_not_found' === $task_guard_result->get_error_code();

	$task_relation_request = new WP_REST_Request( 'POST', '/wptsall/v2/tasks/monitor/start' );
	$task_relation_request->set_param( 'relation_id', 2147483647 );
	$task_relation_result = $tasks_controller->check_permission( $task_relation_request );
	$task_relation_guard_ok = is_wp_error( $task_relation_result )
		&& 'rest_object_not_found' === $task_relation_result->get_error_code();

	$task_job_request = new WP_REST_Request( 'GET', '/wptsall/v2/tasks/jobs/iss-no-such-job/tasks' );
	$task_job_request->set_param( 'job_id', 'iss-no-such-job' );
	$task_job_result = $tasks_controller->check_permission( $task_job_request );
	$task_job_guard_ok = is_wp_error( $task_job_result )
		&& 'rest_object_not_found' === $task_job_result->get_error_code();

	$task_batch_request = new WP_REST_Request( 'POST', '/wptsall/v2/tasks/batch/status' );
	$task_batch_request->set_param( 'ids', array( 2147483647 ) );
	$task_batch_result = $tasks_controller->check_permission( $task_batch_request );
	$task_batch_guard_ok = is_wp_error( $task_batch_result )
		&& 'rest_object_not_found' === $task_batch_result->get_error_code();
}
$record( $report, 'S6_rest_task_existence_guard', $task_object_guard_ok, $task_object_guard_ok ? 'missing task rejected in permission callback' : 'task permission guard not proven' );
$record( $report, 'S6_rest_task_relation_guard', $task_relation_guard_ok, $task_relation_guard_ok ? 'missing task relation rejected in permission callback' : 'task relation permission guard not proven' );
$record( $report, 'S6_rest_task_job_guard', $task_job_guard_ok, $task_job_guard_ok ? 'missing job rejected in permission callback' : 'task job permission guard not proven' );
$record( $report, 'S6_rest_task_batch_guard', $task_batch_guard_ok, $task_batch_guard_ok ? 'missing task batch rejected in permission callback' : 'task batch permission guard not proven' );

// Manual translation routes carry source/relation/target post identifiers.
// Keep these checks at the permission boundary so an authenticated manager
// cannot invoke manual-content hooks with a fabricated object reference.
$manual_relation_guard_ok = false;
$manual_source_guard_ok   = false;
$manual_target_guard_ok   = false;
if ( class_exists( '\WPTSALL\Sites\API\Manual_Translation_REST_Controller' ) ) {
	$manual_controller = new \WPTSALL\Sites\API\Manual_Translation_REST_Controller();
	$manual_relation_request = new WP_REST_Request( 'GET', '/wptsall/v2/manual-translations/editor-data' );
	$manual_relation_request->set_param( 'source_post_id', 2147483647 );
	$manual_relation_request->set_param( 'relation_id', 2147483647 );
	$manual_relation_result = $manual_controller->check_permission( $manual_relation_request );
	$manual_relation_guard_ok = is_wp_error( $manual_relation_result )
		&& 'rest_object_not_found' === $manual_relation_result->get_error_code();

	$manual_relation_row = $wpdb->get_row(
		"SELECT id FROM {$wpdb->prefix}wptsall_site_relations ORDER BY id LIMIT 1",
		ARRAY_A
	);
	$manual_relation_id = (int) ( $manual_relation_row['id'] ?? 0 );
	if ( $manual_relation_id > 0 ) {
		$manual_source_request = new WP_REST_Request( 'GET', '/wptsall/v2/manual-translations/editor-data' );
		$manual_source_request->set_param( 'source_post_id', 2147483647 );
		$manual_source_request->set_param( 'relation_id', $manual_relation_id );
		$manual_source_result = $manual_controller->check_permission( $manual_source_request );
		$manual_source_guard_ok = is_wp_error( $manual_source_result )
			&& 'rest_object_not_found' === $manual_source_result->get_error_code();

		$manual_target_request = new WP_REST_Request( 'POST', '/wptsall/v2/manual-translations/2147483647' );
		$manual_target_request->set_param( 'relation_id', $manual_relation_id );
		$manual_target_result = $manual_controller->check_permission( $manual_target_request );
		$manual_target_guard_ok = is_wp_error( $manual_target_result )
			&& 'rest_object_not_found' === $manual_target_result->get_error_code();
	}
}
$record( $report, 'S6_rest_manual_relation_guard', $manual_relation_guard_ok, $manual_relation_guard_ok ? 'missing manual-translation relation rejected in permission callback' : 'manual relation permission guard not proven' );
$record( $report, 'S6_rest_manual_source_guard', $manual_source_guard_ok, $manual_source_guard_ok ? 'missing manual-translation source rejected in permission callback' : 'manual source permission guard not proven' );
$record( $report, 'S6_rest_manual_target_guard', $manual_target_guard_ok, $manual_target_guard_ok ? 'missing manual-translation target rejected in permission callback' : 'manual target permission guard not proven' );

// Template language-pack routes expose both relation-scoped templates and
// standalone template-entry IDs.  Exercise both permission paths so a caller
// cannot reach PO import/export or entry mutation with a fabricated object.
$template_object_guard_ok       = false;
$template_entry_object_guard_ok = false;
if ( class_exists( '\WPTSALL\Templates\API\Template_REST_Controller' ) ) {
	$template_controller = new \WPTSALL\Templates\API\Template_REST_Controller();
	$template_guard_request = new WP_REST_Request( 'GET', '/wptsall/v2/templates/2147483647' );
	$template_guard_request->set_param( 'id', 2147483647 );
	$template_guard_result = $template_controller->check_permission( $template_guard_request );
	$template_object_guard_ok = is_wp_error( $template_guard_result )
		&& 'rest_object_not_found' === $template_guard_result->get_error_code();

	$template_entry_guard_request = new WP_REST_Request( 'GET', '/wptsall/v2/template-entries/2147483647' );
	$template_entry_guard_request->set_param( 'id', 2147483647 );
	$template_entry_guard_result = $template_controller->check_permission( $template_entry_guard_request );
	$template_entry_object_guard_ok = is_wp_error( $template_entry_guard_result )
		&& 'rest_object_not_found' === $template_entry_guard_result->get_error_code();
}
$record( $report, 'S6_rest_template_existence_guard', $template_object_guard_ok, $template_object_guard_ok ? 'missing template rejected in permission callback' : 'template permission guard not proven' );
$record( $report, 'S6_rest_template_entry_guard', $template_entry_object_guard_ok, $template_entry_object_guard_ok ? 'missing template entry rejected in permission callback' : 'template entry permission guard not proven' );

$plugin_mapping_guard_ok = false;
if ( class_exists( '\WPTSALL\Models\API\Plugin_Mapping_REST_Controller' ) ) {
	$plugin_mapping_request = new WP_REST_Request( 'GET', '/wptsall/v2/plugins/iss-no-such-plugin' );
	$plugin_mapping_request->set_param( 'slug', 'iss-no-such-plugin' );
	$plugin_mapping_result = ( new \WPTSALL\Models\API\Plugin_Mapping_REST_Controller() )->check_permission( $plugin_mapping_request );
	$plugin_mapping_guard_ok = is_wp_error( $plugin_mapping_result )
		&& 'rest_object_not_found' === $plugin_mapping_result->get_error_code();
}
$record( $report, 'S6_rest_plugin_mapping_guard', $plugin_mapping_guard_ok, $plugin_mapping_guard_ok ? 'missing plugin mapping rejected in permission callback' : 'plugin mapping permission guard not proven' );

// S3 transaction boundary prerequisite: callback result/task writes rely on
// InnoDB transactions. Keep this as a hard runtime assertion so a deployment
// with an accidentally downgraded table engine cannot claim atomicity.
$result_engine = strtoupper( (string) $wpdb->get_var( $wpdb->prepare(
	'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
	wptsall_table( 'translation_results' )
) ) );
$task_engine = strtoupper( (string) $wpdb->get_var( $wpdb->prepare(
	'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
	wptsall_table( 'tasks' )
) ) );
$record( $report, 'S3_translation_results_innodb', 'INNODB' === $result_engine, 'translation_results engine=' . $result_engine );
$record( $report, 'S3_tasks_innodb', 'INNODB' === $task_engine, 'tasks engine=' . $task_engine );

// --- P-A10 snapshot ---
$post_id = wp_insert_post(
	array(
		'post_title'   => 'ISS Snapshot ' . wp_generate_password( 4, false ),
		'post_content' => 'alpha content',
		'post_status'  => 'publish',
		'post_type'    => 'post',
	),
	true
);
$record( $report, 'PA10_create_post', ! is_wp_error( $post_id ) && $post_id > 0, is_wp_error( $post_id ) ? $post_id->get_error_message() : 'post=' . $post_id );
$rev1 = \WPTSALL\Core\Job_Snapshot::compute_source_revision( 'post_type', (int) $post_id );
wp_update_post(
	array(
		'ID'           => $post_id,
		'post_content' => 'beta content changed',
	)
);
$rev2 = \WPTSALL\Core\Job_Snapshot::compute_source_revision( 'post_type', (int) $post_id );
$record( $report, 'PA10_revision_changes', $rev1 && $rev2 && ! hash_equals( $rev1, $rev2 ), 'rev changed after edit' );
$stale = \WPTSALL\Core\Job_Snapshot::assert_fresh( 'post_type', (int) $post_id, $rev1, \WPTSALL\Core\Job_Snapshot::current_policy_version() );
$record( $report, 'PA10_stale_rejected', is_wp_error( $stale ), is_wp_error( $stale ) ? $stale->get_error_code() : 'stale allowed' );
$fresh = \WPTSALL\Core\Job_Snapshot::assert_fresh( 'post_type', (int) $post_id, $rev2, \WPTSALL\Core\Job_Snapshot::current_policy_version() );
$record( $report, 'PA10_fresh_ok', true === $fresh, 'fresh ok' );

// Exercise the actual callback boundary: a revision returned at discovery time
// must be rejected after the source has changed, before a result/sync row can be
// created. A temporary active relation also serves the media mapping proof below.
$iss_relation_id      = 0;
$iss_media_mapping_id = 0;
$iss_upload_attachment_id = 0;
$iss_relation_target  = 'iss-media-' . wp_generate_password( 10, false );
$iss_relation_lang    = 'en_US';
$iss_media_source_attachment_id = 0;
$iss_media_frontend_target_id  = 0;
$relations_table      = wptsall_table( 'site_relations' );
$relation_inserted    = $wpdb->insert(
	$relations_table,
	array(
		'source_site_id'   => (int) get_current_blog_id(),
		'source_site_type' => 'wp',
		'source_lang'      => 'zh_CN',
		'template'         => 'iss-accept-' . wp_generate_password( 10, false ),
		'target_site_id'   => $iss_relation_target,
		'target_site_type' => 'virtual',
		'target_lang'      => $iss_relation_lang,
		'status'           => 'active',
		'created_at'       => current_time( 'mysql' ),
		'updated_at'       => current_time( 'mysql' ),
	),
	array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
);
$iss_relation_id = $relation_inserted ? (int) $wpdb->insert_id : 0;
$record( $report, 'PA10_callback_relation', $iss_relation_id > 0, 'relation=' . $iss_relation_id );

// Device-scoped content claims (P0 security model): every content callback
// verifies post_mappings.claimed_at/claim_owner_hash against the device that
// claimed the item. The verifier mirrors the claim endpoint's behavior
// (update the mapping row when present, otherwise insert a claim_placeholder).
$iss_claim_content = function ( $post_id, $post_type, $device_id ) use ( $wpdb, $iss_relation_id ) {
	if ( $iss_relation_id <= 0 || ! class_exists( '\WPTSALL\Sites\Services\Site_Relation_Service' ) ) {
		return false;
	}
	$relation = \WPTSALL\Sites\Services\Site_Relation_Service::get_relation( $iss_relation_id );
	if ( ! is_array( $relation ) ) {
		return false;
	}
	$source_site_id = (int) ( $relation['source_site_id'] ?? get_current_blog_id() );
	if ( $source_site_id <= 0 ) {
		$source_site_id = (int) get_current_blog_id();
	}
	$target_site_id = (string) ( $relation['target_site_id'] ?? '' );
	$lang           = (string) ( $relation['target_lang'] ?? '' );
	$hash           = function_exists( 'wptsall_client_claim_owner_hash' )
		? wptsall_client_claim_owner_hash( $device_id, $iss_relation_id, $lang, $post_type )
		: hash( 'sha256', 'wptsall-claim-owner-v1|' . $device_id . '|' . $iss_relation_id . '|' . $lang . '|' . $post_type );
	$table = wptsall_table( 'post_mappings' );
	$now   = current_time( 'mysql', true );
	$existing = $wpdb->get_var(
		$wpdb->prepare(
			'SELECT id FROM %i WHERE relation_id = %d AND source_post_id = %d AND source_post_type = %s AND source_site_id = %d AND target_site_id = %s LIMIT 1',
			$table,
			$iss_relation_id,
			(int) $post_id,
			$post_type,
			$source_site_id,
			$target_site_id
		)
	);
	if ( $existing ) {
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET claimed_at = %s, updated_at = %s, claim_owner_hash = %s WHERE id = %d',
				$table,
				$now,
				$now,
				$hash,
				(int) $existing
			)
		);
		return true;
	}
	$wpdb->insert(
		$table,
		array(
			'source_post_id'    => (int) $post_id,
			'source_post_type'  => $post_type,
			'source_site_id'    => $source_site_id,
			'relation_id'       => $iss_relation_id,
			'target_post_id'    => 0,
			'target_post_type'  => $post_type,
			'target_site_id'    => $target_site_id,
			'relationship_type' => 'claim_placeholder',
			'claimed_at'        => $now,
			'claim_owner_hash'  => $hash,
			'created_at'        => $now,
			'updated_at'        => $now,
		),
		array( '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
	);
	return true;
};
$iss_claim_device = 'iss-verify-device';
if ( $iss_relation_id > 0 && class_exists( '\\WPTSALL\\Tasks\\API\\Client_Data_REST_Controller' ) ) {
	// Inject a one-request task insert failure and prove the callback
	// transaction rolls back its translation_results row. The trigger is
	// namespaced, scoped to this temporary relation/object, and removed in the
	// same block so the Lab is left unchanged.
	$transaction_probe_trigger = 'wptsall_iss_fail_' . wp_rand( 100000, 999999 );
	$transaction_probe_created = false;
	$task_probe_table = wptsall_table( 'tasks' );
	$trigger_sql = sprintf(
		"CREATE TRIGGER `%s` BEFORE INSERT ON `%s` FOR EACH ROW BEGIN IF NEW.relation_id = %d AND NEW.object_id = %d AND NEW.object_type = 'post_type' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'iss_task_insert_failure'; END IF; END",
		$transaction_probe_trigger,
		$task_probe_table,
		$iss_relation_id,
		(int) $post_id
	);
	$transaction_probe_created = false !== $wpdb->query( $trigger_sql );
	$transaction_probe_claimed = $transaction_probe_created
		? $iss_claim_content( (int) $post_id, 'post', $iss_claim_device )
		: false;
	$transaction_probe_key = 'iss-transaction-' . wp_generate_password( 12, false );
	$transaction_probe_response = null;
	$transaction_probe_rows = -1;
	if ( $transaction_probe_created ) {
		$transaction_probe_request = new WP_REST_Request( 'POST', '/wptsall/v2/client/translation-callback' );
		$transaction_probe_request->set_header( 'Content-Type', 'application/json' );
		$transaction_probe_request->set_header( 'X-WPTSALL-Device-Id', $iss_claim_device );
		$transaction_probe_request->set_body(
			wp_json_encode(
				array(
					'business_line'     => 'post_content',
					'client_task_id'    => $transaction_probe_key,
					'relation_id'       => $iss_relation_id,
					'object_type'       => 'post_type',
					'object_id'         => (int) $post_id,
					'post_type'         => 'post',
					'source_lang'       => 'zh_CN',
					'target_lang'       => $iss_relation_lang,
					'source_revision'   => $rev2,
					'policy_version'    => \WPTSALL\Core\Job_Snapshot::current_policy_version(),
					'translated_fields' => array( 'post_title' => 'transaction rollback probe' ),
					'translated_meta'   => array(),
					'media_mappings'    => array(),
				),
			),
		);
		$transaction_probe_response = ( new \WPTSALL\Tasks\API\Client_Data_REST_Controller() )->translation_callback( $transaction_probe_request );
		$transaction_probe_rows = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE client_task_id = %s', wptsall_table( 'translation_results' ), $transaction_probe_key )
		);
	}
	if ( $transaction_probe_created ) {
		$wpdb->query( 'DROP TRIGGER IF EXISTS `' . $transaction_probe_trigger . '`' );
	}
	$transaction_probe_ok = $transaction_probe_created
		&& $transaction_probe_response instanceof WP_REST_Response
		&& 500 === $transaction_probe_response->get_status()
		&& 'sync_task_materialization_failed' === ( $transaction_probe_response->get_data()['error'] ?? '' )
		&& 0 === $transaction_probe_rows;
$record(
	$report,
	'S3_callback_task_failure_rolls_back_result',
	$transaction_probe_ok,
	$transaction_probe_ok
		? 'task insert failure returned 500 and left zero translation_results rows'
		: ( $transaction_probe_created
			? ( 'trigger=created status=' . ( $transaction_probe_response instanceof WP_REST_Response ? $transaction_probe_response->get_status() : 'n/a' ) . ' rows=' . $transaction_probe_rows )
			: 'skipped_lab_no_trigger (enable log_bin_trust_function_creators + GRANT TRIGGER)' ),
	// Soft when Lab MySQL cannot create temporary TRIGGERs; hard when the
	// probe ran but did not prove rollback.
	$transaction_probe_created
);

	$stale_task_id = 'iss-stale-' . wp_generate_password( 12, false );
	$stale_claimed = $iss_claim_content( (int) $post_id, 'post', $iss_claim_device );
	$callback      = new \WPTSALL\Tasks\API\Client_Data_REST_Controller();
	$request       = new WP_REST_Request( 'POST', '/wptsall/v2/client/translation-callback' );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_header( 'X-WPTSALL-Device-Id', $iss_claim_device );
	$request->set_body(
		wp_json_encode(
			array(
				'business_line'     => 'post_content',
				'client_task_id'    => $stale_task_id,
				'relation_id'       => $iss_relation_id,
				'object_type'       => 'post_type',
				'subtype'           => 'post',
				'post_type'         => 'post',
				'object_id'         => (int) $post_id,
				'source_lang'       => 'zh_CN',
				'target_lang'       => $iss_relation_lang,
				'source_revision'   => $rev1,
				'policy_version'    => \WPTSALL\Core\Job_Snapshot::current_policy_version(),
				'translated_fields' => array( 'post_title' => 'stale callback must not write' ),
				'translated_meta'   => array(),
				'media_mappings'    => array(),
			)
		)
	);
	$callback_response = $callback->translation_callback( $request );
	$callback_data     = $callback_response->get_data();
	$record(
		$report,
		'PA10_callback_stale_409',
		409 === $callback_response->get_status() && 'stale_source_revision' === ( $callback_data['error'] ?? '' ),
		'status=' . $callback_response->get_status() . ' error=' . ( $callback_data['error'] ?? '' )
	);
	$stale_rows = (int) $wpdb->get_var(
		$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE client_task_id = %s', wptsall_table( 'translation_results' ), $stale_task_id )
	);
	$record( $report, 'PA10_callback_no_result', 0 === $stale_rows, 'rows=' . $stale_rows );
} else {
	$record( $report, 'PA10_callback_stale_409', false, 'controller/relation unavailable' );
	$record( $report, 'PA10_callback_no_result', false, 'controller/relation unavailable' );
}

// Canonical media mappings are keyed by target_site_id. Verify that both
// frontend lookup and admin-language filtering resolve through the active
// relation instead of treating the site ID as a language code.
if ( $iss_relation_id > 0 && class_exists( '\\WPTSALL\\Models\\Services\\Media_Mapping_Service' ) && class_exists( '\\WPTSALL\\MediaTranslation\\Services\\Media_Translation_Service' ) ) {
	$iss_media_mapping_id = \WPTSALL\Models\Services\Media_Mapping_Service::create_mapping(
		array(
			'source_media_id'  => (int) $post_id,
			'source_site_id'   => (int) get_current_blog_id(),
			'source_file_path' => '/tmp/iss-source-' . $post_id . '.jpg',
			'source_file_url'  => 'https://example.invalid/iss-source-' . $post_id . '.jpg',
			'target_media_id'  => (int) $post_id + 1000000,
			'target_site_id'   => $iss_relation_target,
			'target_file_path' => '/tmp/iss-target-' . ( (int) $post_id + 1000000 ) . '.jpg',
			'target_file_url'  => 'https://example.invalid/iss-target-' . ( (int) $post_id + 1000000 ) . '.jpg',
			'mapping_method'   => 'iss-acceptance',
		)
	);
	$resolved_media_id = \WPTSALL\MediaTranslation\Services\Media_Translation_Service::get_target_id( (int) $post_id, $iss_relation_lang );
	$record( $report, 'PA1_media_target_site_lookup', (int) $post_id + 1000000 === $resolved_media_id, 'resolved=' . $resolved_media_id );
	$listed_mappings = \WPTSALL\MediaTranslation\Services\Media_Translation_Service::list_mappings(
		array( 'source_id' => (int) $post_id, 'target_lang' => $iss_relation_lang, 'limit' => 10 )
	);
	$listed_ok = false;
	foreach ( $listed_mappings as $mapping ) {
		if ( (int) ( $mapping['id'] ?? 0 ) === (int) $iss_media_mapping_id
			&& $iss_relation_lang === (string) ( $mapping['target_lang'] ?? '' ) ) {
			$listed_ok = true;
			break;
		}
	}
	$record( $report, 'PA1_media_admin_language_filter', $listed_ok, 'mapping=' . $iss_media_mapping_id );
	if ( $iss_media_mapping_id ) {
		$media_event_count = 0;
		$media_event_hook  = static function () use ( &$media_event_count ) {
			++$media_event_count;
		};
		add_action( 'wptsall_media_changed', $media_event_hook, 10, 6 );
		// The test post is not itself an attachment. Invoke the public lifecycle
		// handler with a temporary real attachment below so this check covers the
		// WordPress attachment/edit + mapping state boundary.
		$media_attachment_id = wp_insert_post(
			array(
				'post_title'     => 'ISS Media ' . wp_generate_password( 4, false ),
				'post_status'    => 'inherit',
				'post_type'      => 'attachment',
				'post_mime_type' => 'image/jpeg',
			),
			true
		);
		$media_lifecycle_mapping_id = 0;
		if ( ! is_wp_error( $media_attachment_id ) ) {
			$media_lifecycle_mapping_id = \WPTSALL\Models\Services\Media_Mapping_Service::create_mapping(
				array(
					'source_media_id'  => (int) $media_attachment_id,
					'source_site_id'   => (int) get_current_blog_id(),
					'source_file_path' => '',
					'source_file_url'  => '',
					'target_media_id'  => (int) $media_attachment_id + 2000000,
					'target_site_id'   => $iss_relation_target,
					'target_file_path' => '',
					'target_file_url'  => '',
					'mapping_method'   => 'iss-lifecycle',
				)
			);
			wp_update_post( array( 'ID' => (int) $media_attachment_id, 'post_title' => 'ISS Media Updated' ) );
			$media_dirty = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT needs_resync FROM %i WHERE id = %d', wptsall_table( 'media_mappings' ), (int) $media_lifecycle_mapping_id )
			);
			$record( $report, 'PA1_media_lifecycle_resync', 1 === $media_dirty && $media_event_count >= 1, 'dirty=' . $media_dirty . ' events=' . $media_event_count );
			wp_delete_post( (int) $media_attachment_id, true );
		}
		if ( is_wp_error( $media_attachment_id ) ) {
			$record( $report, 'PA1_media_lifecycle_resync', false, $media_attachment_id->get_error_message() );
		}
		remove_action( 'wptsall_media_changed', $media_event_hook, 10 );
		if ( $media_lifecycle_mapping_id ) {
			$wpdb->delete( wptsall_table( 'media_mappings' ), array( 'id' => (int) $media_lifecycle_mapping_id ), array( '%d' ) );
		}
	}

	// Exercise the real binary upload boundary with a valid 1x1 JPEG. The
	// endpoint must validate content, persist the attachment, and register a
	// relation-scoped media mapping that later write-back can consume.
	// Contract: X-WPTSALL-Task-ID is a numeric tasks.id; Source-ID must be an
	// attachment owned by an open media/attachment task for this relation.
	if ( class_exists( '\\WPTSALL\\Tasks\\API\\Client_Data_REST_Controller' ) ) {
		$binary = base64_decode( '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAGf/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9k=', true );
		$iss_upload_attachment_id = 0;
		$upload_seed_ok = false;
		$upload_seed_msg = 'seed skipped';
		if ( function_exists( 'wp_upload_bits' ) && function_exists( 'wp_insert_attachment' ) && ! empty( $binary ) ) {
			$seed_file = wp_upload_bits( 'iss-seed-source-' . (int) $post_id . '.jpg', null, $binary );
			if ( empty( $seed_file['error'] ) && ! empty( $seed_file['file'] ) ) {
				$seed_attachment = wp_insert_attachment(
					array(
						'post_title'     => 'ISS Media Upload Source',
						'post_status'    => 'inherit',
						'post_mime_type' => 'image/jpeg',
					),
					$seed_file['file'],
					0,
					true
				);
				if ( ! is_wp_error( $seed_attachment ) && (int) $seed_attachment > 0 ) {
					$seed_attachment_id = (int) $seed_attachment;
					$now_mysql = current_time( 'mysql' );
					$task_inserted = $wpdb->insert(
						wptsall_table( 'tasks' ),
						array(
							'blog_id'           => (int) get_current_blog_id(),
							'target_blog'       => 0,
							'target_type'       => 'virtual',
							'target_identifier' => $iss_relation_target,
							'site_id'           => $iss_relation_id,
							'relation_id'       => $iss_relation_id,
							'template'          => '',
							'object_type'       => 'media',
							'subtype'           => 'attachment',
							'object_id'         => $seed_attachment_id,
							'lang_from'         => 'zh_CN',
							'lang_to'           => $iss_relation_lang,
							'site_mode'         => 'virtual',
							'status'            => 'pending',
							'retry_count'       => 0,
							'payload'           => '{}',
							'created_at'        => $now_mysql,
							'updated_at'        => $now_mysql,
						),
						array( '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
					);
					$media_task_id = $task_inserted ? (int) $wpdb->insert_id : 0;
					if ( $media_task_id > 0 ) {
						$upload_seed_ok = true;
						$upload_request = new WP_REST_Request( 'POST', '/wptsall/v2/client/media-upload' );
						$upload_request->set_header( 'Content-Type', 'image/jpeg' );
						$upload_request->set_header( 'X-WPTSALL-Task-ID', (string) $media_task_id );
						$upload_request->set_header( 'X-WPTSALL-Source-ID', (string) $seed_attachment_id );
						$upload_request->set_header( 'X-WPTSALL-Relation-ID', (string) $iss_relation_id );
						$upload_request->set_header( 'X-WPTSALL-Filename', 'iss-binary-' . (int) $post_id . '.jpg' );
						$upload_request->set_body( $binary );
						$upload_response = ( new \WPTSALL\Tasks\API\Client_Data_REST_Controller() )->media_upload( $upload_request );
						$upload_data = $upload_response->get_data();
						$iss_upload_attachment_id = (int) ( $upload_data['attachment_id'] ?? 0 );
						$upload_path = $iss_upload_attachment_id > 0 ? (string) get_attached_file( $iss_upload_attachment_id ) : '';
						$upload_seed_msg = 'status=' . $upload_response->get_status()
							. ' error=' . ( $upload_data['error'] ?? '' )
							. ' attachment=' . $iss_upload_attachment_id;
						$record(
							$report,
							'PA1_media_binary_upload',
							200 === $upload_response->get_status() && ! empty( $upload_data['success'] ) && $iss_upload_attachment_id > 0 && '' !== $upload_path && is_readable( $upload_path ),
							$upload_seed_msg
						);
						$upload_mapping = $iss_upload_attachment_id > 0
							? $wpdb->get_row(
								$wpdb->prepare(
									'SELECT target_media_id, target_site_id, relation_id FROM %i WHERE source_media_id = %d AND target_media_id = %d AND relation_id = %d ORDER BY id DESC LIMIT 1',
									wptsall_table( 'media_mappings' ),
									$seed_attachment_id,
									$iss_upload_attachment_id,
									$iss_relation_id
								),
								ARRAY_A
							)
							: null;
						$record(
							$report,
							'PA1_media_binary_mapping',
							is_array( $upload_mapping ) && (int) $upload_mapping['target_media_id'] === $iss_upload_attachment_id && (int) $upload_mapping['relation_id'] === $iss_relation_id && (string) $upload_mapping['target_site_id'] === $iss_relation_target,
							'mapping=' . ( is_array( $upload_mapping ) ? wp_json_encode( $upload_mapping ) : 'missing' )
						);
					} else {
						$upload_seed_msg = 'task insert failed';
					}
				} else {
					$upload_seed_msg = is_wp_error( $seed_attachment ) ? $seed_attachment->get_error_message() : 'attachment insert failed';
				}
			} else {
				$upload_seed_msg = (string) ( $seed_file['error'] ?? 'upload_bits failed' );
			}
		}
		if ( ! $upload_seed_ok ) {
			$record( $report, 'PA1_media_binary_upload', false, $upload_seed_msg );
			$record( $report, 'PA1_media_binary_mapping', false, $upload_seed_msg );
		}

		// Build a real source attachment with generated intermediate sizes, then
		// upload its translated binary through the same endpoint. This exercises
		// the frontend src/srcset URL boundary instead of only checking database
		// rows. The source and target use different filenames so source leakage is
		// detectable even when both live under the same uploads directory.
		if ( function_exists( 'imagecreatetruecolor' ) && function_exists( 'imagejpeg' )
			&& function_exists( 'wp_upload_bits' ) && function_exists( 'wp_insert_attachment' ) ) {
			$canvas = imagecreatetruecolor( 640, 480 );
			imagefill( $canvas, 0, 0, imagecolorallocate( $canvas, 32, 96, 160 ) );
			ob_start();
			imagejpeg( $canvas, null, 88 );
			$large_binary = ob_get_clean();
			imagedestroy( $canvas );
			$source_file = wp_upload_bits( 'iss-source-frontend-' . (int) $post_id . '.jpg', null, $large_binary );
			if ( empty( $source_file['error'] ) && ! empty( $source_file['file'] ) ) {
				$source_attachment = wp_insert_attachment(
					array(
						'post_title'     => 'ISS Frontend Source Media',
						'post_status'    => 'inherit',
						'post_mime_type' => 'image/jpeg',
					),
					$source_file['file'],
					0,
					true
				);
				if ( ! is_wp_error( $source_attachment ) && (int) $source_attachment > 0 ) {
					$iss_media_source_attachment_id = (int) $source_attachment;
					require_once ABSPATH . 'wp-admin/includes/image.php';
					$source_meta = wp_generate_attachment_metadata( $iss_media_source_attachment_id, $source_file['file'] );
					if ( ! empty( $source_meta ) ) {
						wp_update_attachment_metadata( $iss_media_source_attachment_id, $source_meta );
					}
					$frontend_task_id = 0;
					$frontend_now = current_time( 'mysql' );
					$frontend_task_ok = $wpdb->insert(
						wptsall_table( 'tasks' ),
						array(
							'blog_id'           => (int) get_current_blog_id(),
							'target_blog'       => 0,
							'target_type'       => 'virtual',
							'target_identifier' => $iss_relation_target,
							'site_id'           => $iss_relation_id,
							'relation_id'       => $iss_relation_id,
							'template'          => '',
							'object_type'       => 'media',
							'subtype'           => 'attachment',
							'object_id'         => $iss_media_source_attachment_id,
							'lang_from'         => 'zh_CN',
							'lang_to'           => $iss_relation_lang,
							'site_mode'         => 'virtual',
							'status'            => 'pending',
							'retry_count'       => 0,
							'payload'           => '{}',
							'created_at'        => $frontend_now,
							'updated_at'        => $frontend_now,
						),
						array( '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
					);
					$frontend_task_id = $frontend_task_ok ? (int) $wpdb->insert_id : 0;
					$frontend_upload_request = new WP_REST_Request( 'POST', '/wptsall/v2/client/media-upload' );
					$frontend_upload_request->set_header( 'Content-Type', 'image/jpeg' );
					$frontend_upload_request->set_header( 'X-WPTSALL-Task-ID', (string) $frontend_task_id );
					$frontend_upload_request->set_header( 'X-WPTSALL-Source-ID', (string) $iss_media_source_attachment_id );
					$frontend_upload_request->set_header( 'X-WPTSALL-Relation-ID', (string) $iss_relation_id );
					$frontend_upload_request->set_header( 'X-WPTSALL-Filename', 'iss-target-frontend-' . (int) $post_id . '.jpg' );
					$frontend_upload_request->set_body( $large_binary );
					$frontend_upload_response = ( new \WPTSALL\Tasks\API\Client_Data_REST_Controller() )->media_upload( $frontend_upload_request );
					$frontend_upload_data = $frontend_upload_response->get_data();
					$iss_media_frontend_target_id = (int) ( $frontend_upload_data['attachment_id'] ?? 0 );
					if ( $frontend_task_id <= 0 || $iss_media_frontend_target_id <= 0 ) {
						$record(
							$report,
							'PA1_media_frontend_upload_seed',
							false,
							'task=' . $frontend_task_id . ' status=' . $frontend_upload_response->get_status() . ' error=' . ( $frontend_upload_data['error'] ?? '' )
						);
					}
					$source_url_before = (string) wp_get_attachment_url( $iss_media_source_attachment_id );
					$target_url_real   = $iss_media_frontend_target_id > 0 ? (string) wp_get_attachment_url( $iss_media_frontend_target_id ) : '';
					$target_medium_real = $iss_media_frontend_target_id > 0 ? wp_get_attachment_image_src( $iss_media_frontend_target_id, 'medium' ) : false;
					// WP-CLI eval-file can run before the normal init action. Ensure the
					// same frontend filters used by an HTTP virtual-site request are active.
					if ( class_exists( '\WPTSALL\MediaTranslation\Hooks\Media_Translation_Frontend' ) ) {
						\WPTSALL\MediaTranslation\Hooks\Media_Translation_Frontend::init();
					}
					$record( $report, 'PA1_media_frontend_filters_registered', has_filter( 'wp_get_attachment_url', array( 'WPTSALL\MediaTranslation\Hooks\Media_Translation_Frontend', 'filter_attachment_url' ) ) !== false, 'url_filter=' . var_export( has_filter( 'wp_get_attachment_url', array( 'WPTSALL\MediaTranslation\Hooks\Media_Translation_Frontend', 'filter_attachment_url' ) ), true ) );
					if ( class_exists( '\WPTSALL\Core\Language_Context' ) ) {
						\WPTSALL\Core\Language_Context::set_language( $iss_relation_lang );
					}
					$frontend_url = (string) wp_get_attachment_url( $iss_media_source_attachment_id );
					$frontend_image = wp_get_attachment_image_src( $iss_media_source_attachment_id, 'medium' );
					$frontend_srcset = wp_get_attachment_image_srcset( $iss_media_source_attachment_id, 'medium' );
					$frontend_html = wp_get_attachment_image( $iss_media_source_attachment_id, 'medium' );
					$frontend_html_srcset = '';
					if ( is_string( $frontend_html ) && preg_match( '/\ssrcset=["\']([^"\']+)["\']/i', $frontend_html, $srcset_match ) ) {
						$frontend_html_srcset = html_entity_decode( (string) $srcset_match[1], ENT_QUOTES, 'UTF-8' );
					}
					$source_stem = pathinfo( $source_url_before, PATHINFO_FILENAME );
					$target_stem = pathinfo( $target_url_real, PATHINFO_FILENAME );
					$frontend_url_ok = $iss_media_frontend_target_id > 0 && '' !== $target_url_real && $frontend_url === $target_url_real && $frontend_url !== $source_url_before;
					$frontend_src_ok = $frontend_image && is_array( $target_medium_real ) && $frontend_image[0] === $target_medium_real[0];
					$frontend_srcset_ok = ( is_string( $frontend_srcset ) && '' !== $frontend_srcset && false === strpos( $frontend_srcset, $source_stem ) && false !== strpos( $frontend_srcset, $target_stem ) )
						|| ( '' !== $frontend_html_srcset && false === strpos( $frontend_html_srcset, $source_stem ) && false !== strpos( $frontend_html_srcset, $target_stem ) );
					$frontend_html_ok = is_string( $frontend_html ) && false === strpos( $frontend_html, $source_stem ) && false !== strpos( $frontend_html, $target_stem );
					$record( $report, 'PA1_media_frontend_url_swap', $frontend_url_ok, 'source=' . $source_url_before . ' rendered=' . $frontend_url . ' target=' . $target_url_real );
					$record( $report, 'PA1_media_frontend_src', (bool) $frontend_src_ok, 'src=' . ( is_array( $frontend_image ) ? (string) $frontend_image[0] : 'missing' ) );
					$record( $report, 'PA1_media_frontend_srcset', $frontend_srcset_ok, 'srcset=' . ( '' !== $frontend_html_srcset ? $frontend_html_srcset : (string) $frontend_srcset ) );
					$record( $report, 'PA1_media_frontend_html_no_source', $frontend_html_ok, 'html=' . substr( (string) $frontend_html, 0, 240 ) );
					// SEO providers consume wp_get_attachment_url() for OG image tags;
					// assert that the URL exposed at that common boundary is target-only.
					$record( $report, 'PA1_media_frontend_og_url', $frontend_url_ok && false === strpos( $frontend_url, $source_stem ), 'og_url=' . $frontend_url );
					if ( class_exists( '\\WPTSALL\\Core\\Language_Context' ) ) {
						\WPTSALL\Core\Language_Context::reset();
					}
				}
			}
		}
	} else {
		$record( $report, 'PA1_media_binary_upload', false, 'controller unavailable' );
		$record( $report, 'PA1_media_binary_mapping', false, 'controller unavailable' );
	}
} else {
	$record( $report, 'PA1_media_target_site_lookup', false, 'media services/relation unavailable' );
	$record( $report, 'PA1_media_admin_language_filter', false, 'media services/relation unavailable' );
	$record( $report, 'PA1_media_lifecycle_resync', false, 'media services/relation unavailable' );
	$record( $report, 'PA1_media_binary_upload', false, 'media services/relation unavailable' );
	$record( $report, 'PA1_media_binary_mapping', false, 'media services/relation unavailable' );
}

// --- P-A6 CAS ---
$target_id = wp_insert_post(
	array(
		'post_title'   => 'ISS Target Manual',
		'post_content' => 'machine text',
		'post_status'  => 'publish',
		'post_type'    => 'post',
		'meta_input'   => array(
			'_wptsall_virtual_site_id' => $iss_relation_target,
			'_wptsall_source_post_id'  => (int) $post_id,
			'_wptsall_relation_id'     => (int) $iss_relation_id,
		),
	),
	true
);
$record( $report, 'PA6_target_post', ! is_wp_error( $target_id ) && $target_id > 0, is_wp_error( $target_id ) ? $target_id->get_error_message() : 'tid=' . $target_id );
if ( ! is_wp_error( $target_id ) ) {
	// Simulate the editor changing the target value, then persist ownership for
	// the actual value that must survive the later machine callback.
	wp_update_post( array( 'ID' => (int) $target_id, 'post_title' => 'HUMAN TITLE' ) );
	\WPTSALL\Sync\Services\Field_Ownership_Service::claim_manual( 'post', (int) $target_id, 'post_title', 'HUMAN TITLE' );
	$filtered = \WPTSALL\Sync\Services\Field_Ownership_Service::filter_machine_writeback(
		(int) $target_id,
		'post_type',
		array(
			'post_title'   => 'MACHINE TITLE SHOULD NOT APPLY',
			'post_content' => 'machine content ok',
		),
		array(),
		0,
		(int) $post_id,
		'iss-cas-' . wp_generate_password( 8, false )
	);
	$record( $report, 'PA6_manual_skipped', ! isset( $filtered['fields']['post_title'] ), 'title skipped' );
	$record( $report, 'PA6_other_kept', isset( $filtered['fields']['post_content'] ), 'content kept' );
	$record( $report, 'PA6_conflict_recorded', (int) $filtered['conflicts'] >= 1, 'conflicts=' . $filtered['conflicts'] );
	$ctable = wptsall_table( 'conflicts' );
	$ccount = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ctable} WHERE status='open'" ); // phpcs:ignore
	$record( $report, 'PA6_conflict_row', $ccount > 0, 'open conflicts=' . $ccount );

	// Exercise the real callback -> task -> Sync_Executor path. The target
	// already has a human-owned title; an otherwise-fresh machine callback must
	// complete without replacing that value while recording a conflict.
	if ( $iss_relation_id > 0 && class_exists( '\WPTSALL\Tasks\API\Client_Data_REST_Controller' ) ) {
		$e2e_task_id = 'iss-cas-e2e-' . wp_generate_password( 10, false );
		$e2e_claimed = $iss_claim_content( (int) $post_id, 'post', $iss_claim_device );
		$e2e_request  = new WP_REST_Request( 'POST', '/wptsall/v2/client/translation-callback' );
		$e2e_request->set_header( 'Content-Type', 'application/json' );
		$e2e_request->set_header( 'X-WPTSALL-Device-Id', $iss_claim_device );
		$e2e_request->set_body(
			wp_json_encode(
				array(
					'business_line'     => 'post_content',
					'client_task_id'    => $e2e_task_id,
					'relation_id'       => $iss_relation_id,
					'object_type'       => 'post_type',
					'object_id'         => (int) $post_id,
					'post_type'         => 'post',
					'source_lang'       => 'zh_CN',
					'target_lang'       => $iss_relation_lang,
					'source_revision'   => $rev2,
					'policy_version'    => \WPTSALL\Core\Job_Snapshot::current_policy_version(),
					'translated_fields' => array( 'post_title' => 'MACHINE TITLE MUST NOT APPLY' ),
					'translated_meta'   => array(),
					'media_mappings'    => array(),
				)
			)
		);
		$e2e_response = ( new \WPTSALL\Tasks\API\Client_Data_REST_Controller() )->translation_callback( $e2e_request );
		$e2e_status   = $e2e_response->get_status();
		$e2e_target   = get_post( (int) $target_id );
		$e2e_title_ok = $e2e_target && 'HUMAN TITLE' === (string) $e2e_target->post_title;
		$record( $report, 'PA6_callback_sync_200', 200 === $e2e_status, 'status=' . $e2e_status );
		$record( $report, 'PA6_callback_manual_preserved', $e2e_title_ok, 'title=' . ( $e2e_target ? $e2e_target->post_title : 'missing' ) );
	}
}

// --- S3 idempotency conflict (same key different body) ---
$task_key = 'iss-idem-' . wp_generate_password( 12, false );
$hash_a   = \WPTSALL\Core\Job_Snapshot::request_body_hash( array( 'client_task_id' => $task_key, 'v' => 1 ) );
$hash_b   = \WPTSALL\Core\Job_Snapshot::request_body_hash( array( 'client_task_id' => $task_key, 'v' => 2 ) );
$record( $report, 'S3_hash_differs', $hash_a && $hash_b && ! hash_equals( $hash_a, $hash_b ), 'body hashes differ' );
$ins = wptsall_insert_translation_result(
	array(
		'relation_id'       => 1,
		'object_type'       => 'post_type',
		'object_id'         => (int) $post_id,
		'translated_fields' => array( 'post_title' => 'A' ),
		'client_task_id'    => $task_key,
		'source_revision'   => $rev2,
		'policy_version'    => \WPTSALL\Core\Job_Snapshot::current_policy_version(),
		'request_hash'      => $hash_a,
		'source_lang'       => 'zh_CN',
		'target_lang'       => 'en_US',
	)
);
$record( $report, 'S3_insert_result', (bool) $ins, 'result_id=' . $ins );
$dup = wptsall_insert_translation_result(
	array(
		'relation_id'       => 1,
		'object_type'       => 'post_type',
		'object_id'         => (int) $post_id,
		'translated_fields' => array( 'post_title' => 'A' ),
		'client_task_id'    => $task_key,
		'request_hash'      => $hash_a,
	)
);
$record( $report, 'S3_unique_returns_same', (int) $dup === (int) $ins, "dup={$dup} ins={$ins}" );

// Concurrent same-key inserts: only one row.
$conc_key = 'iss-conc-' . wp_generate_password( 10, false );
$ids      = array();
for ( $i = 0; $i < 20; $i++ ) {
	$ids[] = wptsall_insert_translation_result(
		array(
			'relation_id'       => 1,
			'object_type'       => 'post_type',
			'object_id'         => (int) $post_id,
			'translated_fields' => array( 'post_title' => 'C' . $i ),
			'client_task_id'    => $conc_key,
			'request_hash'      => hash( 'sha256', 'same' ),
		)
	);
}
$uniq_ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
$row_cnt  = (int) $wpdb->get_var(
	$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE client_task_id = %s', wptsall_table( 'translation_results' ), $conc_key )
);
$record( $report, 'S3_concurrent_one_row', 1 === $row_cnt && 1 === count( $uniq_ids ), "rows={$row_cnt} uniq_ids=" . count( $uniq_ids ) );

// Exercise the actual REST callback boundary: reusing one client_task_id (and
// Idempotency-Key) with a different request body must be rejected with 409.
// This is intentionally separate from the direct INSERT/UNIQUE proof above.
if ( $iss_relation_id > 0 && class_exists( '\\WPTSALL\\Tasks\\API\\Client_Data_REST_Controller' ) ) {
	// Use a fresh post: earlier flows delivered translations for $post_id to the
	// virtual target, so the origin backflow guard would skip this delivery.
	// A fresh post isolates what this check tests: REST-boundary idempotency.
	$idem_post_id = wp_insert_post(
		array(
			'post_title'   => 'ISS idem source ' . wp_generate_password( 6, false ),
			'post_content' => 'iss idem content',
			'post_status'  => 'publish',
			'post_type'    => 'post',
		)
	);
	$idem_rev = is_numeric( $idem_post_id ) && $idem_post_id > 0
		? \WPTSALL\Core\Job_Snapshot::compute_source_revision( 'post_type', (int) $idem_post_id )
		: '';
	$idem_claimed = ( $idem_post_id > 0 && '' !== $idem_rev )
		? $iss_claim_content( (int) $idem_post_id, 'post', $iss_claim_device )
		: false;
	$callback_key  = 'iss-callback-idem-' . wp_generate_password( 10, false );
	$callback_base = array(
		'business_line'     => 'post_content',
		'client_task_id'    => $callback_key,
		'relation_id'       => $iss_relation_id,
		'object_type'       => 'post_type',
		'object_id'         => (int) $idem_post_id,
		'post_type'         => 'post',
		'source_lang'       => 'zh_CN',
		'target_lang'       => $iss_relation_lang,
		'source_revision'   => $idem_rev,
		'policy_version'    => \WPTSALL\Core\Job_Snapshot::current_policy_version(),
		'translated_fields' => array( 'post_content' => 'callback-idem-a' ),
		'translated_meta'   => array(),
		'media_mappings'    => array(),
	);
	$idem_controller = new \WPTSALL\Tasks\API\Client_Data_REST_Controller();
	// The earlier PA6 success consumed the claim (callbacks clear it on
	// writeback); re-claim so this flow tests idempotency, not the claim gate.
	$idem_claimed = $iss_claim_content( (int) $post_id, 'post', $iss_claim_device );
	$first_request   = new WP_REST_Request( 'POST', '/wptsall/v2/client/translation-callback' );
	$first_request->set_header( 'Content-Type', 'application/json' );
	$first_request->set_header( 'Idempotency-Key', $callback_key );
	$first_request->set_header( 'X-WPTSALL-Device-Id', $iss_claim_device );
	$first_request->set_body( wp_json_encode( $callback_base ) );
	$first_response = $idem_controller->translation_callback( $first_request );
	$changed        = $callback_base;
	$changed['translated_fields'] = array( 'post_content' => 'callback-idem-b' );
	$second_request = new WP_REST_Request( 'POST', '/wptsall/v2/client/translation-callback' );
	$second_request->set_header( 'Content-Type', 'application/json' );
	$second_request->set_header( 'Idempotency-Key', $callback_key );
	$second_request->set_body( wp_json_encode( $changed ) );
	$second_response = $idem_controller->translation_callback( $second_request );
	$record( $report, 'S3_callback_first_accepted', $first_response->get_status() >= 200 && $first_response->get_status() < 300, 'status=' . $first_response->get_status() . ' body=' . wp_json_encode( $first_response->get_data() ) );
	$record( $report, 'S3_callback_different_body_409', 409 === $second_response->get_status(), 'status=' . $second_response->get_status() );
} else {
	$record( $report, 'S3_callback_first_accepted', false, 'controller/relation unavailable' );
	$record( $report, 'S3_callback_different_body_409', false, 'controller/relation unavailable' );
}

// --- Term dispatcher smoke ---
$term = wp_insert_term( 'ISS Term ' . wp_generate_password( 4, false ), 'category' );
$record( $report, 'PA1_term_create', ! is_wp_error( $term ), is_wp_error( $term ) ? $term->get_error_message() : 'term ok' );
if ( ! is_wp_error( $term ) && class_exists( '\\WPTSALL\\Hooks\\Content_Change_Dispatcher' ) ) {
	$outbox_table = wptsall_table( 'content_change_outbox' );
	$outbox_id    = (int) $wpdb->get_var(
		$wpdb->prepare( 'SELECT id FROM %i WHERE source_type = %s AND source_id = %d AND event_name = %s ORDER BY id DESC LIMIT 1', $outbox_table, 'term', (int) $term['term_id'], 'term_created' )
	);
	$record( $report, 'PA1_outbox_enqueue', $outbox_id > 0, 'outbox_id=' . $outbox_id );
	$claimed_outbox = \WPTSALL\Hooks\Content_Change_Dispatcher::claim_outbox( 50 );
	$claimed_match  = false;
	foreach ( (array) $claimed_outbox as $outbox_row ) {
		if ( (int) ( $outbox_row['id'] ?? 0 ) === $outbox_id ) {
			$claimed_match = true;
			\WPTSALL\Hooks\Content_Change_Dispatcher::complete_outbox( $outbox_id );
			break;
		}
	}
	$record( $report, 'PA1_outbox_claim_complete', $claimed_match, $claimed_match ? 'claimed+completed' : 'claim missed' );
}

// --- P-A3 FSE: Site Editor object save -> outbox -> discovery -> callback -> write-back ---
// This is intentionally object-level, rather than just a string registration
// smoke test. It exercises both Gutenberg block markup and theme.json-style
// global styles without asking a provider to translate CSS/color tokens.
$menu_source_id   = 0;
$menu_target_id   = 0;
$menu_virtual_id  = 0;
$lifecycle_source_id = 0;
$lifecycle_target_id = 0;
$fse_source_ids  = array();
$fse_target_ids  = array();
$fse_result_ids  = array();
$fse_task_ids    = array();
$fse_outbox_ids  = array();
$fse_string_keys = array();
if ( $iss_relation_id > 0
	&& class_exists( '\\WPTSALL\\Fse\\Fse_Content_Adapter' )
	&& class_exists( '\\WPTSALL\\Tasks\\API\\Client_Data_REST_Controller' )
	&& class_exists( '\\WPTSALL\\Hooks\\Content_Change_Dispatcher' ) ) {
	$fse_cases = array(
		'wp_template' => array(
			'source_title'     => 'ISS FSE Template Source',
			'target_title'     => 'ISS FSE Template Target',
			'source_content'   => '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>ISS source block</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
			'target_content'   => '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>ISS translated block</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
			'content_format'   => 'rich_html',
		),
		'wp_global_styles' => array(
			'source_title'     => 'ISS FSE Styles Source',
			'target_title'     => 'ISS FSE Styles Target',
			'source_content'   => '{"version":3,"styles":{"color":{"text":"#ff0000"}},"settings":{"custom":{"css":".iss-fse { color: red; }"}}}',
			'target_content'   => '{"version":3,"styles":{"color":{"text":"#ff0000"}},"settings":{"custom":{"css":".iss-fse { color: red; }"}}}',
			'content_format'   => 'json_structured',
		),
	);

	foreach ( $fse_cases as $fse_type => $fse_case ) {
		$fse_source_id = wp_insert_post(
			array(
				'post_type'    => $fse_type,
				'post_status'  => 'publish',
				'post_title'   => $fse_case['source_title'],
				'post_name'    => 'iss-fse-' . sanitize_title( $fse_type ) . '-' . wp_generate_password( 6, false ),
				'post_content' => $fse_case['source_content'],
			),
			true
		);
		$created = ! is_wp_error( $fse_source_id ) && (int) $fse_source_id > 0;
		$record( $report, 'PA3_' . $fse_type . '_create', $created, $created ? 'source=' . $fse_source_id : $fse_source_id->get_error_message() );
		if ( ! $created ) {
			continue;
		}
		$fse_source_id = (int) $fse_source_id;
		$fse_source_ids[] = $fse_source_id;

		// The adapter registers titles and block text into Layer B strings.
		if ( 'wp_template' === $fse_type ) {
			$fse_context    = 'fse_' . $fse_type;
			$fse_title_key  = 'title_' . $fse_source_id;
			$fse_block_key  = 'block_' . $fse_source_id . '_0';
			$fse_string_keys[] = array( $fse_context, $fse_title_key );
			$fse_string_keys[] = array( $fse_context, $fse_block_key );
			$fse_string_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE context = %s AND string_key IN (%s, %s)',
					wptsall_table( 'strings' ),
					$fse_context,
					$fse_title_key,
					$fse_block_key
				)
			);
			$record( $report, 'PA3_template_strings_registered', $fse_string_count >= 2, 'strings=' . $fse_string_count );
		}

		$discovery_request = new WP_REST_Request( 'GET', '/wptsall/v2/client/content' );
		$discovery_request->set_param( 'relation_id', $iss_relation_id );
		$discovery_request->set_param( 'data_type', 'post' );
		$discovery_request->set_param( 'subtype', $fse_type );
		$discovery_request->set_param( 'include_ids', (string) $fse_source_id );
		$discovery_response = ( new \WPTSALL\Tasks\API\Client_Data_REST_Controller() )->get_content( $discovery_request );
		$discovery_data     = $discovery_response->get_data();
		$discovered         = false;
		foreach ( (array) ( $discovery_data['items'] ?? array() ) as $discovery_item ) {
			if ( $fse_type === ( $discovery_item['subtype'] ?? '' ) && $fse_source_id === (int) ( $discovery_item['object_id'] ?? 0 ) ) {
				$discovered = true;
				break;
			}
		}
		$record( $report, 'PA3_' . $fse_type . '_discovery', $discovered, 'status=' . $discovery_response->get_status() );

		$fse_outbox_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE relation_id = %d AND source_type = %s AND source_id = %d AND event_name = %s ORDER BY id DESC LIMIT 1',
				wptsall_table( 'content_change_outbox' ),
				$iss_relation_id,
				'post',
				$fse_source_id,
				'post_created'
			)
		);
		$fse_outbox_ids[] = $fse_outbox_id;
		// Callbacks with outbox_id require the same device that claimed the lease.
		$fse_device_id    = 'iss-fse-device-' . sanitize_title( $fse_type ) . '-' . wp_generate_password( 8, false );
		$claimed_rows     = \WPTSALL\Hooks\Content_Change_Dispatcher::claim_outbox( 1, 900, $iss_relation_id, $fse_device_id );
		$claimed_fse      = false;
		foreach ( (array) $claimed_rows as $claimed_row ) {
			if ( $fse_outbox_id === (int) ( $claimed_row['id'] ?? 0 ) ) {
				$claimed_fse = true;
				break;
			}
		}
		$claim_fse = $claimed_fse ? $iss_claim_content( (int) $fse_source_id, $fse_type, $fse_device_id ) : false;
		$record( $report, 'PA3_' . $fse_type . '_outbox_claim', $fse_outbox_id > 0 && $claimed_fse, 'outbox=' . $fse_outbox_id . ' claim=' . ( $claim_fse ? 'ok' : 'failed' ) );

		$fse_callback_key = 'iss-fse-' . sanitize_title( $fse_type ) . '-' . wp_generate_password( 10, false );
		$fse_request      = new WP_REST_Request( 'POST', '/wptsall/v2/client/translation-callback' );
		$fse_request->set_header( 'Content-Type', 'application/json' );
		$fse_request->set_header( 'Idempotency-Key', $fse_callback_key );
		$fse_request->set_header( 'X-WPTSALL-Device-Id', $fse_device_id );
		$fse_request->set_body(
			wp_json_encode(
				array(
					'business_line'     => 'post_content',
					'client_task_id'    => $fse_callback_key,
					'relation_id'       => $iss_relation_id,
					'object_type'       => 'post_type',
					'post_type'         => $fse_type,
					'subtype'           => $fse_type,
					'object_id'         => $fse_source_id,
					'outbox_id'         => $fse_outbox_id,
					'source_lang'       => 'zh_CN',
					'target_lang'       => $iss_relation_lang,
					'source_revision'   => \WPTSALL\Core\Job_Snapshot::compute_source_revision( 'post_type', $fse_source_id ),
					'policy_version'    => \WPTSALL\Core\Job_Snapshot::current_policy_version(),
					'translated_fields' => array(
						'post_title'   => $fse_case['target_title'],
						'post_content' => $fse_case['target_content'],
					),
					'translated_meta'   => array(),
					'media_mappings'    => array(),
					'field_results'     => array(
						array( 'field' => 'post_title', 'status' => 'success', 'content_format' => 'plain_text', 'storage' => 'post_column' ),
						array( 'field' => 'post_content', 'status' => 'success', 'content_format' => $fse_case['content_format'], 'storage' => 'post_column' ),
					),
				)
			)
		);
		$fse_callback_response = ( new \WPTSALL\Tasks\API\Client_Data_REST_Controller() )->translation_callback( $fse_request );
		$fse_callback_data     = $fse_callback_response->get_data();
		$fse_target_id         = (int) ( $fse_callback_data['sync_result']['target_id'] ?? 0 );
		$fse_result_ids[]      = (int) ( $fse_callback_data['result_id'] ?? 0 );
		$fse_task_ids[]        = (int) ( $fse_callback_data['sync_task_id'] ?? 0 );
		if ( $fse_target_id > 0 ) {
			$fse_target_ids[] = $fse_target_id;
		}
		$fse_target = $fse_target_id > 0 ? get_post( $fse_target_id ) : null;
		$fse_written = $fse_target instanceof WP_Post
			&& $fse_type === $fse_target->post_type
			&& $fse_case['target_title'] === $fse_target->post_title
			&& $fse_case['target_content'] === $fse_target->post_content;
		$record(
			$report,
			'PA3_' . $fse_type . '_callback_writeback',
			200 === $fse_callback_response->get_status() && $fse_written,
			'status=' . $fse_callback_response->get_status()
				. ' error=' . ( $fse_callback_data['error'] ?? '' )
				. ' target=' . $fse_target_id
		);
		$fse_outbox_status = (string) $wpdb->get_var(
			$wpdb->prepare( 'SELECT status FROM %i WHERE id = %d', wptsall_table( 'content_change_outbox' ), $fse_outbox_id )
		);
		$record( $report, 'PA3_' . $fse_type . '_outbox_completed', 'completed' === $fse_outbox_status, 'status=' . $fse_outbox_status );
	}
} else {
	$record( $report, 'PA3_fse_prerequisites', false, 'FSE/controller/dispatcher/relation unavailable' );
}

// --- P-A4 classic menu incremental update + delete cascade ---
// A source edit must refresh the existing target menu rather than attempting
// a duplicate-name clone. Source deletion must remove the owned clone and its
// mapping so virtual-site frontends never serve an orphaned navigation tree.
if ( class_exists( '\\WPTSALL\\Sites\\Services\\Virtual_Site_Service' )
	&& class_exists( '\\WPTSALL\\MenuTranslation\\Menu_Mapping_Service' )
	&& class_exists( '\\WPTSALL\\MenuTranslation\\Admin\\Menu_Sync_Page' ) ) {
	$menu_tag       = wp_generate_password( 8, false );
	$menu_vs_result = \WPTSALL\Sites\Services\Virtual_Site_Service::create(
		array(
			'name'        => 'ISS Menu ' . $menu_tag,
			'path_prefix' => 'iss-menu-' . sanitize_title( $menu_tag ),
			'lang'        => 'en_US',
		)
	);
	$menu_virtual_id = ! empty( $menu_vs_result['success'] ) ? (int) ( $menu_vs_result['site_id'] ?? 0 ) : 0;
	$record( $report, 'PA4_virtual_site_create', $menu_virtual_id > 0, 'virtual_site=' . $menu_virtual_id );

	$menu_source = wp_create_nav_menu( 'ISS Source Menu ' . $menu_tag );
	$menu_source_id = is_wp_error( $menu_source ) ? 0 : (int) $menu_source;
	$menu_parent = $menu_source_id > 0
		? wp_update_nav_menu_item(
			$menu_source_id,
			0,
			array(
				'menu-item-title'  => 'ISS Parent ' . $menu_tag,
				'menu-item-url'    => home_url( '/iss-parent-' . $menu_tag . '/' ),
				'menu-item-status' => 'publish',
				'menu-item-type'   => 'custom',
			)
		)
		: new WP_Error( 'menu_source_missing' );
	$menu_child = ! is_wp_error( $menu_parent )
		? wp_update_nav_menu_item(
			$menu_source_id,
			0,
			array(
				'menu-item-title'     => 'ISS Child ' . $menu_tag,
				'menu-item-url'       => home_url( '/iss-child-' . $menu_tag . '/' ),
				'menu-item-status'    => 'publish',
				'menu-item-type'      => 'custom',
				'menu-item-parent-id' => (int) $menu_parent,
			)
		)
		: $menu_parent;
	$menu_seeded = $menu_source_id > 0 && ! is_wp_error( $menu_parent ) && ! is_wp_error( $menu_child );
	$record( $report, 'PA4_source_menu_seed', $menu_seeded, 'source=' . $menu_source_id );

	$menu_target = $menu_seeded && $menu_virtual_id > 0
		? \WPTSALL\MenuTranslation\Admin\Menu_Sync_Page::sync_menu( $menu_source_id, (string) $menu_virtual_id )
		: new WP_Error( 'menu_prerequisites_missing' );
	$menu_target_id = is_wp_error( $menu_target ) ? 0 : (int) $menu_target;
	$initial_mapping = $menu_target_id > 0
		&& $menu_target_id === \WPTSALL\MenuTranslation\Menu_Mapping_Service::get_target_menu( $menu_source_id, (string) $menu_virtual_id );
	$record( $report, 'PA4_initial_clone_mapping', $initial_mapping, 'target=' . $menu_target_id );

	if ( $initial_mapping ) {
		$updated_title = 'ISS Child Updated ' . $menu_tag;
		wp_update_nav_menu_item(
			$menu_source_id,
			(int) $menu_child,
			array(
				'menu-item-title'     => $updated_title,
				'menu-item-url'       => home_url( '/iss-child-' . $menu_tag . '/' ),
				'menu-item-status'    => 'publish',
				'menu-item-type'      => 'custom',
				'menu-item-parent-id' => (int) $menu_parent,
			)
		);
		$menu_mapping_table = wptsall_table( 'menu_mappings' );
		$menu_pending       = (string) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT sync_status FROM %i WHERE source_menu_term_id = %d AND virtual_site_id = %s',
				$menu_mapping_table,
				$menu_source_id,
				(string) $menu_virtual_id
			)
		);
		$record( $report, 'PA4_source_update_marks_pending', 'pending' === $menu_pending, 'status=' . $menu_pending );

		$menu_refreshed = \WPTSALL\MenuTranslation\Menu_Mapping_Service::resync_pending_for_source( $menu_source_id );
		$menu_target_after = \WPTSALL\MenuTranslation\Menu_Mapping_Service::get_target_menu( $menu_source_id, (string) $menu_virtual_id );
		$target_items      = wp_get_nav_menu_items( $menu_target_after );
		$has_updated_child = false;
		$has_child_parent  = false;
		foreach ( (array) $target_items as $target_item ) {
			if ( $updated_title === (string) $target_item->title ) {
				$has_updated_child = true;
				$has_child_parent  = (int) $target_item->menu_item_parent > 0;
			}
		}
		$record(
			$report,
			'PA4_incremental_refresh_in_place',
			1 === (int) $menu_refreshed && $menu_target_id === (int) $menu_target_after && $has_updated_child && $has_child_parent,
			'refreshed=' . (int) $menu_refreshed . ' target=' . (int) $menu_target_after
		);

		$had_current_vs = array_key_exists( 'wptsall_current_virtual_site', $GLOBALS );
		$previous_vs    = $GLOBALS['wptsall_current_virtual_site'] ?? null;
		$GLOBALS['wptsall_current_virtual_site'] = \WPTSALL\Sites\Services\Virtual_Site_Service::get( (string) $menu_virtual_id );
		$menu_args = \WPTSALL\MenuTranslation\Menu_Mapping_Service::filter_nav_menu_args( array( 'menu' => $menu_source_id ) );
		if ( $had_current_vs ) {
			$GLOBALS['wptsall_current_virtual_site'] = $previous_vs;
		} else {
			unset( $GLOBALS['wptsall_current_virtual_site'] );
		}
		$record( $report, 'PA4_virtual_menu_swap', (int) ( $menu_args['menu'] ?? 0 ) === $menu_target_id, 'menu=' . (int) ( $menu_args['menu'] ?? 0 ) );

		wp_delete_nav_menu( $menu_source_id );
		$menu_source_id = 0; // deleted above; cleanup must not repeat it.
		$mapping_rows = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE target_menu_term_id = %d',
				$menu_mapping_table,
				$menu_target_id
			)
		);
		$record(
			$report,
			'PA4_source_delete_cascade',
			! term_exists( $menu_target_id, 'nav_menu' ) && 0 === $mapping_rows,
			'target_exists=' . ( term_exists( $menu_target_id, 'nav_menu' ) ? 'yes' : 'no' ) . ' mappings=' . $mapping_rows
		);
	} else {
		$record( $report, 'PA4_source_update_marks_pending', false, 'initial clone/mapping unavailable' );
		$record( $report, 'PA4_incremental_refresh_in_place', false, 'initial clone/mapping unavailable' );
		$record( $report, 'PA4_virtual_menu_swap', false, 'initial clone/mapping unavailable' );
		$record( $report, 'PA4_source_delete_cascade', false, 'initial clone/mapping unavailable' );
	}
} else {
	$record( $report, 'PA4_virtual_site_create', false, 'menu prerequisites unavailable' );
	$record( $report, 'PA4_source_menu_seed', false, 'menu prerequisites unavailable' );
	$record( $report, 'PA4_initial_clone_mapping', false, 'menu prerequisites unavailable' );
	$record( $report, 'PA4_source_update_marks_pending', false, 'menu prerequisites unavailable' );
	$record( $report, 'PA4_incremental_refresh_in_place', false, 'menu prerequisites unavailable' );
	$record( $report, 'PA4_virtual_menu_swap', false, 'menu prerequisites unavailable' );
	$record( $report, 'PA4_source_delete_cascade', false, 'menu prerequisites unavailable' );
}

// --- P-A9/T6 scenario 8: status -> trash -> untrash -> delete propagation ---
// Use a real post mapping and invoke public WordPress lifecycle functions;
// this proves the dispatcher changes the owned target rather than merely
// registering hooks.
if ( $iss_relation_id > 0 && class_exists( '\\WPTSALL\\Models\\Services\\Post_Mapping_Service' ) ) {
	$lifecycle_source = wp_insert_post(
		array(
			'post_title'   => 'ISS Lifecycle Source ' . wp_generate_password( 5, false ),
			'post_content' => 'lifecycle source',
			'post_status'  => 'publish',
			'post_type'    => 'post',
		),
		true
	);
	$lifecycle_target = wp_insert_post(
		array(
			'post_title'   => 'ISS Lifecycle Target ' . wp_generate_password( 5, false ),
			'post_content' => 'lifecycle target',
			'post_status'  => 'publish',
			'post_type'    => 'post',
			'meta_input'   => array(
				'_wptsall_virtual_site_id' => $iss_relation_target,
				'_wptsall_relation_id'     => (int) $iss_relation_id,
			),
		),
		true
	);
	$lifecycle_source_id = is_wp_error( $lifecycle_source ) ? 0 : (int) $lifecycle_source;
	$lifecycle_target_id = is_wp_error( $lifecycle_target ) ? 0 : (int) $lifecycle_target;
	$mapping_id = ( $lifecycle_source_id > 0 && $lifecycle_target_id > 0 )
		? \WPTSALL\Models\Services\Post_Mapping_Service::create_mapping(
			array(
				'source_post_id'    => $lifecycle_source_id,
				'source_post_type'  => 'post',
				'source_site_id'    => (int) get_current_blog_id(),
				'target_post_id'    => $lifecycle_target_id,
				'target_post_type'  => 'post',
				'target_site_id'    => $iss_relation_target,
				'relation_id'       => (int) $iss_relation_id,
				'relationship_type' => 'translation',
			)
		)
		: false;
	$record( $report, 'PA9_lifecycle_mapping', false !== $mapping_id, 'mapping=' . (int) $mapping_id );
	if ( false !== $mapping_id ) {
		wp_update_post( array( 'ID' => $lifecycle_source_id, 'post_status' => 'private' ) );
		$record( $report, 'PA9_status_propagation', 'private' === get_post_status( $lifecycle_target_id ), 'target=' . get_post_status( $lifecycle_target_id ) );
		wp_trash_post( $lifecycle_source_id );
		$record( $report, 'PA9_trash_propagation', 'trash' === get_post_status( $lifecycle_target_id ), 'target=' . get_post_status( $lifecycle_target_id ) );
		wp_untrash_post( $lifecycle_source_id );
		$source_restored_status = (string) get_post_status( $lifecycle_source_id );
		$target_restored_status = (string) get_post_status( $lifecycle_target_id );
		// WordPress owns the exact restored state (some policies restore a
		// trashed private post as draft). The contract here is target/source
		// convergence and leaving trash, rather than overriding WP's policy.
		$record( $report, 'PA9_untrash_propagation', 'trash' !== $source_restored_status && $source_restored_status === $target_restored_status, 'source=' . $source_restored_status . ' target=' . $target_restored_status );
		wp_delete_post( $lifecycle_source_id, true );
		$lifecycle_source_id = 0;
		$record( $report, 'PA9_delete_propagation', ! get_post( $lifecycle_target_id ), 'target_exists=' . ( get_post( $lifecycle_target_id ) ? 'yes' : 'no' ) );
		$lifecycle_target_id = 0;
	}
} else {
	$record( $report, 'PA9_lifecycle_mapping', false, 'mapping service/relation unavailable' );
	$record( $report, 'PA9_status_propagation', false, 'mapping service/relation unavailable' );
	$record( $report, 'PA9_trash_propagation', false, 'mapping service/relation unavailable' );
	$record( $report, 'PA9_untrash_propagation', false, 'mapping service/relation unavailable' );
	$record( $report, 'PA9_delete_propagation', false, 'mapping service/relation unavailable' );
}

// --- T5 fault profile fixture present ---
$fixture_candidates = array(
	'/opt/wptsall-e2e/fixtures/provider-fault-profiles.yaml',
	dirname( __DIR__ ) . '/fixtures/provider-fault-profiles.yaml',
	'/home/john/wpmmcc-ats3.0/tests/modules/wpmmcc-ats/e2e/fixtures/provider-fault-profiles.yaml',
);
$fixture_ok = false;
$fixture_msg = 'missing';
foreach ( $fixture_candidates as $fixture ) {
	if ( is_readable( $fixture ) ) {
		$fixture_ok  = true;
		$fixture_msg = $fixture;
		break;
	}
}
$record( $report, 'T5_fixture', $fixture_ok, $fixture_msg );

// Cleanup created users/posts lightly.
if ( ! is_wp_error( $uid ) ) {
	wp_delete_user( (int) $uid );
}
if ( ! is_wp_error( $post_id ) ) {
	wp_delete_post( (int) $post_id, true );
}
if ( ! is_wp_error( $target_id ) ) {
	wp_delete_post( (int) $target_id, true );
}
if ( $iss_media_mapping_id > 0 ) {
	$wpdb->delete( wptsall_table( 'media_mappings' ), array( 'id' => (int) $iss_media_mapping_id ), array( '%d' ) );
}
if ( $iss_upload_attachment_id > 0 ) {
	$wpdb->delete( wptsall_table( 'media_mappings' ), array( 'target_media_id' => (int) $iss_upload_attachment_id ), array( '%d' ) );
	wp_delete_post( (int) $iss_upload_attachment_id, true );
}
if ( $iss_media_source_attachment_id > 0 ) {
	wp_delete_post( (int) $iss_media_source_attachment_id, true );
}
if ( $iss_media_frontend_target_id > 0 ) {
	$wpdb->delete( wptsall_table( 'media_mappings' ), array( 'target_media_id' => (int) $iss_media_frontend_target_id ), array( '%d' ) );
	wp_delete_post( (int) $iss_media_frontend_target_id, true );
}
if ( $menu_source_id > 0 ) {
	wp_delete_nav_menu( $menu_source_id );
}
if ( $menu_target_id > 0 && term_exists( $menu_target_id, 'nav_menu' ) ) {
	wp_delete_nav_menu( $menu_target_id );
}
if ( $menu_virtual_id > 0 && class_exists( '\\WPTSALL\\Sites\\Services\\Virtual_Site_Service' ) ) {
	\WPTSALL\Sites\Services\Virtual_Site_Service::delete( (string) $menu_virtual_id );
}
if ( $lifecycle_source_id > 0 ) {
	wp_delete_post( (int) $lifecycle_source_id, true );
}
if ( $lifecycle_target_id > 0 ) {
	wp_delete_post( (int) $lifecycle_target_id, true );
}
if ( $iss_relation_id > 0 ) {
	$wpdb->delete( wptsall_table( 'site_relations' ), array( 'id' => (int) $iss_relation_id ), array( '%d' ) );
}
foreach ( (array) $fse_target_ids as $fse_target_id ) {
	if ( $fse_target_id > 0 ) {
		wp_delete_post( (int) $fse_target_id, true );
	}
}
foreach ( (array) $fse_source_ids as $fse_source_id ) {
	if ( $fse_source_id > 0 ) {
		wp_delete_post( (int) $fse_source_id, true );
		$wpdb->delete( wptsall_table( 'post_mappings' ), array( 'source_post_id' => (int) $fse_source_id ), array( '%d' ) );
		$wpdb->delete( wptsall_table( 'content_change_outbox' ), array( 'source_id' => (int) $fse_source_id ), array( '%d' ) );
	}
}
foreach ( (array) $fse_result_ids as $fse_result_id ) {
	if ( $fse_result_id > 0 ) {
		$wpdb->delete( wptsall_table( 'translation_results' ), array( 'id' => (int) $fse_result_id ), array( '%d' ) );
	}
}
foreach ( (array) $fse_task_ids as $fse_task_id ) {
	if ( $fse_task_id > 0 ) {
		$wpdb->delete( wptsall_table( 'tasks' ), array( 'id' => (int) $fse_task_id ), array( '%d' ) );
	}
}
foreach ( (array) $fse_string_keys as $fse_string_key ) {
	if ( is_array( $fse_string_key ) && 2 === count( $fse_string_key ) ) {
		$wpdb->delete(
			wptsall_table( 'strings' ),
			array( 'context' => (string) $fse_string_key[0], 'string_key' => (string) $fse_string_key[1] ),
			array( '%s', '%s' )
		);
	}
}

echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
exit( $report['hard_fail'] > 0 ? 1 : 0 );

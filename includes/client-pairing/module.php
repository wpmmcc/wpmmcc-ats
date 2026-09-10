<?php
/**
 * Client Pairing Module
 *
 * Provides client API token and site-pairing management for
 * communication with the standalone translation client. All plugin
 * features are free; no license gating, no remote calls.
 *
 * @package WPTSALL\Client_Pairing
 * @since 1.0.0
 * @updated 1.5.2 Removed license validation. All features are free.
 * @updated 2.1.2 Renamed from the historical `enterprise` module
 *               (License_Service -> Client_Token_Service).
 */

namespace WPTSALL\Client_Pairing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Module {

	public static function init() {
		$module_path = dirname( __FILE__ );
		require_once $module_path . '/services/class-client-token-service.php';
		require_once $module_path . '/functions.php';
	}
}

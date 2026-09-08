<?php
/**
 * Enterprise Module
 *
 * Provides client API token management for communication with the
 * standalone translation client. All plugin features are free.
 *
 * @package WPTSALL\Enterprise
 * @since 1.0.0
 * @updated 1.5.2 Removed license validation. All features are free.
 */

namespace WPTSALL\Enterprise;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Module {

	public static function init() {
		$module_path = dirname( __FILE__ );
		require_once $module_path . '/services/class-license-service.php';
		require_once $module_path . '/functions.php';
	}
}

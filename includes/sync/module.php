<?php
namespace WPTSALL\Sync;
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Module {
	public static function init() {
		// Hooks register themselves conditionally based on sync_mode.
		add_action( 'init', array( '\\WPTSALL\\Sync\\Services\\Sync_Service', 'init' ) );
		add_action( 'init', array( '\\WPTSALL\\Sync\\Services\\Field_Ownership_Service', 'init' ), 20 );
	}
}

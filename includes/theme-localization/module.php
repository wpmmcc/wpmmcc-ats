<?php
namespace WPTSALL\ThemeLocalization;
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Module {
	public static function init() {
		add_action( 'init', array( '\\WPTSALL\\ThemeLocalization\\Theme_Localization', 'init' ) );
	}
}

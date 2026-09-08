<?php
namespace WPTSALL\WidgetTranslation;
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Module {
	public static function init() {
		add_action( 'init', array( '\\WPTSALL\\WidgetTranslation\\Widget_Translation', 'init' ) );
	}
}

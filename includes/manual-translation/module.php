<?php
/**
 * Manual Translation Module (P5 - 1.4.0)
 *
 * Wires up all admin pages for manual content type / taxonomy / field
 * translation management:
 *   - Hub
 *   - URL Discovery
 *   - Pending Translations
 *   - Taxonomy Translations
 *   - Field Discovery
 *
 * @package WPTSALL\ManualTranslation
 * @since 1.4.0
 */

namespace WPTSALL\ManualTranslation;

use WPTSALL\ManualTranslation\Admin\Manual_Translation_Hub;
use WPTSALL\ManualTranslation\Admin\Url_Discovery_Page;
use WPTSALL\ManualTranslation\Admin\Pending_Translations_Page;
use WPTSALL\ManualTranslation\Admin\Taxonomy_Translation_Page;
use WPTSALL\ManualTranslation\Admin\Field_Discovery_Page;
use WPTSALL\ManualTranslation\Admin\Content_Types_Config_Page;
use WPTSALL\ManualTranslation\Admin\Dashboard_Page;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Module {

	public static function init() {
		Manual_Translation_Hub::init();
		Url_Discovery_Page::init();
		Pending_Translations_Page::init();
		Taxonomy_Translation_Page::init();
		Field_Discovery_Page::init();
		Content_Types_Config_Page::init();
		Dashboard_Page::init();

		add_action( 'admin_menu', array( __NAMESPACE__ . '\\Admin\\Dashboard_Page', 'add_menu_page' ), 11 );
		add_action( 'admin_menu', array( __NAMESPACE__ . '\\Admin\\Manual_Translation_Hub', 'add_menu_page' ), 18 );
		add_action( 'admin_menu', array( __NAMESPACE__ . '\\Admin\\Url_Discovery_Page', 'add_menu_page' ), 19 );
		add_action( 'admin_menu', array( __NAMESPACE__ . '\\Admin\\Pending_Translations_Page', 'add_menu_page' ), 20 );
		add_action( 'admin_menu', array( __NAMESPACE__ . '\\Admin\\Taxonomy_Translation_Page', 'add_menu_page' ), 21 );
		add_action( 'admin_menu', array( __NAMESPACE__ . '\\Admin\\Field_Discovery_Page', 'add_menu_page' ), 22 );
		add_action( 'admin_menu', array( __NAMESPACE__ . '\\Admin\\Content_Types_Config_Page', 'add_menu_page' ), 23 );
	}
}

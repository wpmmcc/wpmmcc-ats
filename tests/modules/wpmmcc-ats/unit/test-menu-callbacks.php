<?php
/**
 * Test Menu Callbacks
 *
 * 验证所有菜单回调类和方法是否存在
 * 这可以捕获命名空间不匹配的问题
 *
 * @package WPTSALL
 * @since 0.5.0
 */

class Test_Menu_Callbacks extends SimpleTestCase {

	/**
	 * Test Model Editor Page callback exists
	 */
	public function test_model_editor_page_callback_exists() {
		$class_name = 'WPTSALL\Models\Admin\Model_Editor_Page';

		$this->assertTrue(
			class_exists( $class_name ),
			"Class {$class_name} should exist"
		);

		$this->assertTrue(
			method_exists( $class_name, 'render_page' ),
			"Method {$class_name}::render_page() should exist"
		);
	}

	/**
	 * Test Sites Page callback exists
	 */
	public function test_sites_page_callback_exists() {
		$class_name = 'WPTSALL\Sites\Admin\Sites_Page';

		$this->assertTrue(
			class_exists( $class_name ),
			"Class {$class_name} should exist"
		);

		$this->assertTrue(
			method_exists( $class_name, 'render_page' ),
			"Method {$class_name}::render_page() should exist"
		);
	}

	/**
	 * Test Templates List Page callback exists
	 */
	public function test_templates_list_page_callback_exists() {
		$class_name = 'WPTSALL\Templates\Admin\Template_List_Page';

		$this->assertTrue(
			class_exists( $class_name ),
			"Class {$class_name} should exist"
		);

		$this->assertTrue(
			method_exists( $class_name, 'render_page' ),
			"Method {$class_name}::render_page() should exist"
		);
	}

	/**
	 * Test all scanner classes exist with correct namespace
	 */
	public function test_scanner_classes_namespace() {
		$scanners = array(
			'WPTSALL\Models\Scanners\Runtime_Tracker',
			'WPTSALL\Models\Scanners\Orphan_Resolver',
			'WPTSALL\Models\Scanners\Model_Scanner_V2',
		);

		foreach ( $scanners as $class_name ) {
			$this->assertTrue(
				class_exists( $class_name ),
				"Scanner class {$class_name} should exist"
			);
		}
	}

	/**
	 * Test all service classes exist with correct namespace
	 */
	public function test_service_classes_namespace() {
		$services = array(
			// Models services (Model_Service was replaced by Translation_Rule_Service in v0.6.1)
			'WPTSALL\Models\Services\Plugin_Scanner',
			'WPTSALL\Models\Services\Plugin_Mapping_Service',
			'WPTSALL\Models\Services\Translation_Rule_Service',
			// Sites services
			'WPTSALL\Sites\Services\Site_Relation_Service',
			'WPTSALL\Sites\Services\Virtual_Site_Service',
			// Templates services
			'WPTSALL\Templates\Services\Template_Service',
			'WPTSALL\Templates\Services\Template_Entry_Service',
		);

		foreach ( $services as $class_name ) {
			$this->assertTrue(
				class_exists( $class_name ),
				"Service class {$class_name} should exist"
			);
		}
	}

	/**
	 * Test REST controller classes exist with correct namespace
	 */
	public function test_rest_controller_classes_namespace() {
		$controllers = array(
			// Model_REST_Controller was removed in v0.6.1, replaced by Translation_Rule_REST_Controller
			'WPTSALL\Models\API\Translation_Rule_REST_Controller',
			'WPTSALL\Sites\API\Sites_REST_Controller',
			'WPTSALL\Templates\API\Template_REST_Controller',
		);

		foreach ( $controllers as $class_name ) {
			$this->assertTrue(
				class_exists( $class_name ),
				"REST controller class {$class_name} should exist"
			);
		}
	}

	/**
	 * Test that old namespaces do NOT exist (regression test)
	 */
	public function test_old_namespaces_do_not_exist() {
		$old_classes = array(
			'WPTSALL\Admin\Pages\Model_Editor_Page',
			'WPTSALL\Admin\Pages\Sites_Page',
			'WPTSALL\Scanners\Runtime_Tracker',
			'WPTSALL\Scanners\Orphan_Resolver',
			'WPTSALL\Scanners\Unified_Scanner',
			'WPTSALL\Services\Site_Relation_Service',
			'WPTSALL\Services\Plugin_Mapping_Service',
		);

		foreach ( $old_classes as $class_name ) {
			$this->assertFalse(
				class_exists( $class_name ),
				"Old namespace class {$class_name} should NOT exist (use new modular namespace)"
			);
		}
	}

	/**
	 * Test menu registration uses correct callbacks
	 *
	 * This parses menu.php to verify the callback strings match existing classes
	 */
	public function test_menu_php_callbacks_are_valid() {
		$menu_file = WPTSALL_PATH . 'includes/admin/menu.php';

		if ( ! file_exists( $menu_file ) ) {
			$this->markTestSkipped( 'menu.php not found' );
			return;
		}

		$content = file_get_contents( $menu_file );

		// Extract callback arrays from add_submenu_page calls
		// Pattern: array( 'Namespace\Class', 'method' )
		preg_match_all(
			"/array\s*\(\s*['\"]([^'\"]+)['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/",
			$content,
			$matches,
			PREG_SET_ORDER
		);

		foreach ( $matches as $match ) {
			// Raw PHP source may use \\ to represent \; normalize for class_exists().
			$class_name = str_replace( '\\\\', '\\', $match[1] );
			$method_name = $match[2];

			// Skip non-WPTSALL classes
			if ( strpos( $class_name, 'WPTSALL' ) !== 0 ) {
				continue;
			}

			$this->assertTrue(
				class_exists( $class_name ),
				"Menu callback class {$class_name} should exist"
			);

			$this->assertTrue(
				method_exists( $class_name, $method_name ),
				"Menu callback method {$class_name}::{$method_name}() should exist"
			);
		}
	}
}

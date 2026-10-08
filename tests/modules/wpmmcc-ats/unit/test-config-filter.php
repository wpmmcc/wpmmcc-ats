<?php
/**
 * Config Filter Tests
 *
 * Tests for WPTSALL\Hooks\Config_Filter:
 * - init(): no-op in admin context, registers hooks on frontend
 * - translation round-trip once the msgctxt cache is populated (via
 *   reflection, mirroring maybe_init_filters' cache layout):
 *   blogname/blogdescription option filters, nav-menu object titles,
 *   widget titles; unknown msgids pass through unchanged
 * - on_entry_updated()/on_translation_completed() clear the cache so
 *   translations stop applying; non-config domains leave the cache intact
 * - get_cache_stats()/is_initialized() diagnostics
 *
 * catalog: WP-CLASS-Config_Filter
 * oracle: L2
 *
 * @package WPTSALL\Tests\Unit
 * @since 2.2.0
 */

use WPTSALL\Hooks\Config_Filter;

class Test_Config_Filter extends SimpleTestCase {

	public function setUp(): void {
		parent::setUp();
		$this->reset_filter_state();
	}

	public function tearDown(): void {
		$this->reset_filter_state();
		remove_action( 'init', array( Config_Filter::class,'maybe_init_filters' ), 21 );
		remove_action( 'wptsall_translation_completed', array( Config_Filter::class,'on_translation_completed' ) );
		remove_action( 'wptsall_entry_updated', array( Config_Filter::class,'on_entry_updated' ) );
		remove_filter( 'option_blogname', array( Config_Filter::class,'filter_blogname' ) );
		remove_filter( 'option_blogdescription', array( Config_Filter::class,'filter_blogdescription' ) );
		remove_filter( 'wp_nav_menu_objects', array( Config_Filter::class,'filter_nav_menu_objects' ) );
		remove_filter( 'widget_title', array( Config_Filter::class,'filter_widget_title' ) );
		parent::tearDown();
	}

	/**
	 * Reset the static state Config_Filter keeps between requests.
	 */
	private function reset_filter_state() {
		Config_Filter::clear_cache();
		$prop = new ReflectionProperty( Config_Filter::class, 'initialized' );
		$prop->setAccessible( true );
		$prop->setValue( null, false );
		$rel = new ReflectionProperty( Config_Filter::class, 'current_relation_id' );
		$rel->setAccessible( true );
		$rel->setValue( null, null );
	}

	/**
	 * Populate the msgctxt cache the same way maybe_init_filters does.
	 *
	 * @param array $cache [ msgctxt => [ msgid => msgstr ] ]
	 */
	private function seed_cache( array $cache ) {
		$prop = new ReflectionProperty( Config_Filter::class, 'cache' );
		$prop->setAccessible( true );
		$prop->setValue( null, $cache );
	}

	public function test_class_exists() {
		$this->assertTrue( class_exists( 'WPTSALL\Hooks\Config_Filter' ) );
	}

	public function test_init_registers_frontend_hooks_when_not_admin() {
		remove_action( 'init', array( Config_Filter::class,'maybe_init_filters' ), 21 );
		remove_action( 'wptsall_translation_completed', array( Config_Filter::class,'on_translation_completed' ) );

		$this->assertFalse( is_admin(), 'runner must be in a frontend-like context for this case' );
		Config_Filter::init();

		$this->assertEquals( 21, has_action( 'init', array( Config_Filter::class,'maybe_init_filters' ) ) );
		$this->assertEquals( 10, has_action( 'wptsall_translation_completed', array( Config_Filter::class,'on_translation_completed' ) ) );
		$this->assertEquals( 10, has_action( 'wptsall_entry_updated', array( Config_Filter::class,'on_entry_updated' ) ) );
	}

	public function test_init_is_noop_in_admin_context() {
		if ( ! function_exists( 'set_current_screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}
		remove_action( 'init', array( Config_Filter::class,'maybe_init_filters' ), 21 );
		remove_action( 'wptsall_translation_completed', array( Config_Filter::class,'on_translation_completed' ) );
		remove_action( 'wptsall_entry_updated', array( Config_Filter::class,'on_entry_updated' ) );

		set_current_screen( 'dashboard' );
		try {
			$this->assertTrue( is_admin() );
			Config_Filter::init();
			$this->assertFalse( has_action( 'init', array( Config_Filter::class,'maybe_init_filters' ) ), 'init() must not register hooks in admin' );
			$this->assertFalse( has_action( 'wptsall_translation_completed', array( Config_Filter::class,'on_translation_completed' ) ) );
		} finally {
			unset( $GLOBALS['current_screen'], $GLOBALS['screen'] );
		}
	}

	public function test_blogname_and_blogdescription_filters_translate_known_values() {
		$this->seed_cache( array(
			'site_title'   => array( 'My Site' => '我的站点' ),
			'site_tagline' => array( 'Just another blog' => '只是另一个博客' ),
		) );

		$this->assertEquals( '我的站点', Config_Filter::filter_blogname( 'My Site' ) );
		$this->assertEquals( '只是另一个博客', Config_Filter::filter_blogdescription( 'Just another blog' ) );

		// Unknown msgid / empty value pass through unchanged.
		$this->assertEquals( 'Unknown Title', Config_Filter::filter_blogname( 'Unknown Title' ) );
		$this->assertSame( '', Config_Filter::filter_blogname( '' ) );
	}

	public function test_nav_menu_objects_translate_title_and_attr_title() {
		$this->seed_cache( array(
			'menu_item_title'     => array( 'Home' => '首页' ),
			'menu_item_attr_title' => array( 'Go home' => '回首页' ),
		) );

		$item = (object) array( 'title' => 'Home', 'attr_title' => 'Go home' );
		$untouched = (object) array( 'title' => 'Contact', 'attr_title' => '' );

		$items = Config_Filter::filter_nav_menu_objects( array( $item, $untouched ), array() );

		$this->assertEquals( '首页', $items[0]->title );
		$this->assertEquals( '回首页', $items[0]->attr_title );
		$this->assertEquals( 'Contact', $items[1]->title, 'items without translation entries stay unchanged' );

		// Empty input short-circuits.
		$this->assertSame( array(), Config_Filter::filter_nav_menu_objects( array(), array() ) );
	}

	public function test_widget_title_filter() {
		$this->seed_cache( array( 'widget_title' => array( 'Recent Posts' => '最新文章' ) ) );

		$this->assertEquals( '最新文章', Config_Filter::filter_widget_title( 'Recent Posts' ) );
		$this->assertEquals( 'Some Widget', Config_Filter::filter_widget_title( 'Some Widget' ) );
		// Empty title short-circuits.
		$this->assertSame( '', Config_Filter::filter_widget_title( '' ) );
	}

	public function test_entry_updated_with_config_domain_clears_cache() {
		$this->seed_cache( array( 'site_title' => array( 'My Site' => '我的站点' ) ) );
		$this->assertEquals( '我的站点', Config_Filter::filter_blogname( 'My Site' ) );

		Config_Filter::on_entry_updated( 1, array( 'text_domain' => 'config-site' ) );

		$this->assertSame( array(), Config_Filter::get_cache_stats()['entry_types'], 'config-site entry updates must drop the cache' );
		$this->assertEquals( 'My Site', Config_Filter::filter_blogname( 'My Site' ), 'translations stop applying after cache clear' );
	}

	public function test_entry_updated_with_other_domain_keeps_cache() {
		$this->seed_cache( array( 'site_title' => array( 'My Site' => '我的站点' ) ) );

		Config_Filter::on_entry_updated( 1, array( 'text_domain' => 'some-plugin' ) );

		$this->assertEquals( '我的站点', Config_Filter::filter_blogname( 'My Site' ), 'non-config domains must not drop the config cache' );
	}

	public function test_translation_completed_clears_cache() {
		$this->seed_cache( array( 'widget_title' => array( 'A' => 'B' ) ) );

		Config_Filter::on_translation_completed( 42 );

		$this->assertSame( array(), Config_Filter::get_cache_stats()['entry_types'] );
	}

	public function test_cache_stats_report_seeded_state() {
		$this->seed_cache( array(
			'site_title' => array( 'A' => 'B' ),
			'widget_title' => array( 'C' => 'D', 'E' => 'F' ),
		) );

		$stats = Config_Filter::get_cache_stats();
		$this->assertFalse( $stats['initialized'], 'stats initialized flag is independent of the cache contents' );
		$this->assertNull( $stats['relation_id'] );
		$this->assertEquals( array( 'site_title', 'widget_title' ), array_values( $stats['entry_types'] ) );
		$this->assertEquals( array( 'site_title' => 1, 'widget_title' => 2 ), $stats['entry_counts'] );
	}
}

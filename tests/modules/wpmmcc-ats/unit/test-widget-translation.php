<?php
/**
 * Widget Translation Tests
 *
 * Tests for WPTSALL\WidgetTranslation\Widget_Translation: hook registration,
 * display-time translation via String_Translation_Service, and the
 * widget_update_callback persistence path.
 *
 * catalog: WP-CLASS-Widget_Translation
 * oracle: L2
 *
 * @package WPTSALL\Tests\Unit
 * @since 1.3.0
 */

use WPTSALL\Core\Language_Context;
use WPTSALL\Strings\Services\String_Translation_Service;
use WPTSALL\WidgetTranslation\Widget_Translation;

class Test_Widget_Translation extends SimpleTestCase {

	/**
	 * String_Translation_Service row ids created by this test.
	 *
	 * @var int[]
	 */
	private $string_ids = array();

	/**
	 * Unique widget id fragment for this run (avoids cross-run leftovers).
	 *
	 * @var string
	 */
	private $wid = '';

	public function setUp(): void {
		parent::setUp();
		$this->wid = 'wtsave-' . uniqid();
		Language_Context::reset();
		unset( $GLOBALS['wptsall_current_virtual_site'] );
	}

	public function tearDown(): void {
		foreach ( $this->string_ids as $id ) {
			String_Translation_Service::delete( $id );
		}
		$this->string_ids = array();
		Language_Context::reset();
		unset( $GLOBALS['wptsall_current_virtual_site'] );
		parent::tearDown();
	}

	/**
	 * Register a widget string with an en_US translation and track the row.
	 *
	 * @param string $key   String key (widget id + field).
	 * @param string $text  Source text.
	 * @param string $tr    en_US translation.
	 * @return int Row id.
	 */
	private function seed_string( $key, $text, $tr ) {
		$id = String_Translation_Service::register( Widget_Translation::CONTEXT, $key, $text );
		$this->assertNotFalse( $id );
		$this->assertTrue( String_Translation_Service::set_translations( $id, array( 'en_US' => $tr ) ) );
		$this->string_ids[] = (int) $id;
		return (int) $id;
	}

	/**
	 * A minimal widget stand-in exposing ->id like WP_Widget does.
	 *
	 * @param string $id
	 * @return object
	 */
	private function make_widget( $id ) {
		return new class( $id ) {
			public $id;
			public function __construct( $id ) {
				$this->id = $id;
			}
		};
	}

	/**
	 * The module registers both widget filters on init.
	 */
	public function test_hooks_registered() {
		$this->assertNotFalse(
			has_filter( 'widget_display_callback', array( 'WPTSALL\WidgetTranslation\Widget_Translation', 'translate_widget' ) ),
			'widget_display_callback should have Widget_Translation::translate_widget registered'
		);
		$this->assertNotFalse(
			has_filter( 'widget_update_callback', array( 'WPTSALL\WidgetTranslation\Widget_Translation', 'register_on_save' ) ),
			'widget_update_callback should have Widget_Translation::register_on_save registered'
		);
	}

	/**
	 * A registered + translated widget title is swapped at display time while
	 * untranslated fields pass through untouched.
	 */
	public function test_widget_title_translated_on_display() {
		$this->seed_string( 'wtest-1_title', 'Hello Widget', 'Bonjour Widget' );
		Language_Context::set_language( 'en_US' );

		$instance = array( 'title' => 'Hello Widget', 'text' => 'Plain Body' );
		$out = Widget_Translation::translate_widget( $instance, $this->make_widget( 'wtest-1' ), array() );

		$this->assertEquals( 'Bonjour Widget', $out['title'] );
		$this->assertEquals( 'Plain Body', $out['text'], 'fields without a registered translation must stay original' );
	}

	/**
	 * Body text fields are translated using the widget-id + field key.
	 */
	public function test_widget_text_field_translated_on_display() {
		$this->seed_string( 'wtest-2_text', 'Body Text', 'Corps du texte' );
		Language_Context::set_language( 'en_US' );

		$out = Widget_Translation::translate_widget(
			array( 'title' => 'No Title Row', 'text' => 'Body Text' ),
			$this->make_widget( 'wtest-2' ),
			array()
		);
		$this->assertEquals( 'Corps du texte', $out['text'] );
		$this->assertEquals( 'No Title Row', $out['title'], 'title row was not seeded, original must survive' );
	}

	/**
	 * When no translation exists the instance is returned unchanged.
	 */
	public function test_untranslated_widget_instance_unchanged() {
		Language_Context::set_language( 'en_US' );
		$instance = array( 'title' => 'Never Registered', 'text' => 'Neither' );

		$out = Widget_Translation::translate_widget( $instance, $this->make_widget( 'wtest-unknown' ), array() );
		$this->assertEquals( $instance, $out );
	}

	/**
	 * Without a resolved request language the display callback is a no-op
	 * (even when a translation exists for the would-be language).
	 */
	public function test_no_language_leaves_instance_untranslated() {
		$this->seed_string( 'wtest-3_title', 'Silent Title', 'Translated Anyway' );

		$instance = array( 'title' => 'Silent Title' );
		$out = Widget_Translation::translate_widget( $instance, $this->make_widget( 'wtest-3' ), array() );
		$this->assertEquals( 'Silent Title', $out['title'] );
	}

	/**
	 * Non-array instances pass through both callbacks unharmed.
	 */
	public function test_non_array_instance_passthrough() {
		$this->assertEquals( null, Widget_Translation::translate_widget( null, $this->make_widget( 'x' ), array() ) );
		$this->assertEquals( 'raw', Widget_Translation::register_on_save( 'raw', array(), array(), $this->make_widget( 'x' ) ) );
	}

	/**
	 * register_on_save() (widget_update_callback) persists title and text
	 * strings under the widget context, keyed by widget id, and returns the
	 * instance unchanged.
	 */
	public function test_register_on_save_persists_strings() {
		$widget = $this->make_widget( $this->wid );
		$instance = array( 'title' => 'Saved Title', 'text' => 'Saved Body', 'custom_field' => 'Ignored' );

		$out = Widget_Translation::register_on_save( $instance, $instance, array(), $widget );
		$this->assertEquals( $instance, $out, 'register_on_save must not mutate the instance' );

		$rows = String_Translation_Service::list( array( 'context' => Widget_Translation::CONTEXT, 'search' => $this->wid ) );
		$keys = array_map( function ( $r ) {
			return $r['string_key'];
		}, $rows );
		$this->assertContains( $this->wid . '_title', $keys );
		$this->assertContains( $this->wid . '_text', $keys );
		$this->assertNotContains( $this->wid . '_custom_field', $keys, 'only title + known text keys are registered' );
		$this->assertCount( 2, $keys, 'exactly title + text should be registered for this widget' );

		// Registered source texts are stored verbatim (cast to string).
		foreach ( $rows as $row ) {
			if ( $this->wid . '_title' === $row['string_key'] ) {
				$this->assertEquals( 'Saved Title', $row['source_text'] );
			}
			$this->string_ids[] = (int) $row['id'];
		}
	}
}

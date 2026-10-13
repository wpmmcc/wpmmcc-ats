<?php
/**
 * Gettext_Filter Unit Tests
 *
 * Tests for WPTSALL\Hooks\Gettext_Filter class.
 *
 * @package WPTSALL\Tests\Unit\Hooks
 * @since 0.5.0
 */

namespace WPTSALL\Tests\Unit\Hooks;

use WPTSALL\Hooks\Gettext_Filter;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Test class for Gettext_Filter
 */
class Test_Gettext_Filter extends \SimpleTestCase {

	/**
	 * Original cache backup
	 *
	 * @var array
	 */
	private $original_cache = array();

	/**
	 * Original loaded_domains backup
	 *
	 * @var array
	 */
	private $original_loaded_domains = array();

	/**
	 * Original target_lang backup
	 *
	 * @var string
	 */
	private $original_target_lang = '';

	/**
	 * Set up test environment
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		// Enable translation simulation so markers are applied.
		update_option( 'wptsall_enable_translation_simulation', true );

		// Backup original static properties
		$this->original_cache          = $this->get_static_property( 'cache' );
		$this->original_loaded_domains = $this->get_static_property( 'loaded_domains' );
		$this->original_target_lang    = $this->get_static_property( 'target_lang' );

		// Reset static properties for clean test state
		$this->set_static_property( 'cache', array() );
		$this->set_static_property( 'loaded_domains', array() );
		$this->set_static_property( 'target_lang', 'zh_CN' );
	}

	/**
	 * Tear down test environment
	 *
	 * @return void
	 */
	public function tearDown(): void {
		// Restore original static properties
		$this->set_static_property( 'cache', $this->original_cache );
		$this->set_static_property( 'loaded_domains', $this->original_loaded_domains );
		$this->set_static_property( 'target_lang', $this->original_target_lang );

		// Clean up translation simulation option.
		delete_option( 'wptsall_enable_translation_simulation' );

		parent::tearDown();
	}

	/**
	 * Get static property value using Reflection
	 *
	 * @param string $name Property name.
	 * @return mixed
	 */
	private function get_static_property( $name ) {
		$reflection = new ReflectionClass( Gettext_Filter::class );
		$property   = $reflection->getProperty( $name );
		$property->setAccessible( true );
		return $property->getValue();
	}

	/**
	 * Set static property value using Reflection
	 *
	 * @param string $name  Property name.
	 * @param mixed  $value New value.
	 * @return void
	 */
	private function set_static_property( $name, $value ) {
		$reflection = new ReflectionClass( Gettext_Filter::class );
		$property   = $reflection->getProperty( $name );
		$property->setAccessible( true );
		$property->setValue( null, $value );
	}

	/**
	 * Add entry to cache directly for testing
	 *
	 * @param string      $domain       Text domain.
	 * @param string      $text         Source text.
	 * @param string|null $context      Context.
	 * @param string      $msgstr       Translation.
	 * @param string|null $msgstr_plural Plural translation.
	 * @return void
	 */
	private function add_to_cache( $domain, $text, $context, $msgstr, $msgstr_plural = null ) {
		$method = new ReflectionMethod( Gettext_Filter::class, 'get_cache_key' );
		$method->setAccessible( true );
		$key = $method->invoke( null, $domain, $text, $context );

		$cache         = $this->get_static_property( 'cache' );
		$cache[ $key ] = array(
			'msgstr'        => $msgstr,
			'msgstr_plural' => $msgstr_plural,
		);
		$this->set_static_property( 'cache', $cache );
	}

	// =========================================================================
	// Cache Key Generation Tests
	// =========================================================================

	/**
	 * Test get_cache_key generates consistent keys
	 */
	public function test_get_cache_key_generates_consistent_keys() {
		$method = new ReflectionMethod( Gettext_Filter::class, 'get_cache_key' );
		$method->setAccessible( true );

		$key1 = $method->invoke( null, 'default', 'Hello', null );
		$key2 = $method->invoke( null, 'default', 'Hello', null );

		$this->assertEquals( $key1, $key2 );
	}

	/**
	 * Test get_cache_key generates different keys for different domains
	 */
	public function test_get_cache_key_different_for_different_domains() {
		$method = new ReflectionMethod( Gettext_Filter::class, 'get_cache_key' );
		$method->setAccessible( true );

		$key1 = $method->invoke( null, 'default', 'Hello', null );
		$key2 = $method->invoke( null, 'my-plugin', 'Hello', null );

		$this->assertNotEquals( $key1, $key2 );
	}

	/**
	 * Test get_cache_key generates different keys for different texts
	 */
	public function test_get_cache_key_different_for_different_texts() {
		$method = new ReflectionMethod( Gettext_Filter::class, 'get_cache_key' );
		$method->setAccessible( true );

		$key1 = $method->invoke( null, 'default', 'Hello', null );
		$key2 = $method->invoke( null, 'default', 'World', null );

		$this->assertNotEquals( $key1, $key2 );
	}

	/**
	 * Test get_cache_key generates different keys for different contexts
	 */
	public function test_get_cache_key_different_for_different_contexts() {
		$method = new ReflectionMethod( Gettext_Filter::class, 'get_cache_key' );
		$method->setAccessible( true );

		$key1 = $method->invoke( null, 'default', 'Hello', null );
		$key2 = $method->invoke( null, 'default', 'Hello', 'greeting' );
		$key3 = $method->invoke( null, 'default', 'Hello', 'farewell' );

		$this->assertNotEquals( $key1, $key2 );
		$this->assertNotEquals( $key2, $key3 );
	}

	// =========================================================================
	// filter_gettext Tests
	// =========================================================================

	/**
	 * Test filter_gettext returns translation when cached
	 */
	public function test_filter_gettext_returns_translation_when_cached() {
		$this->add_to_cache( 'default', 'Hello', null, '你好' );

		$result = Gettext_Filter::filter_gettext( 'Hello', 'Hello', 'default' );

		$this->assertStringContainsString( '你好', $result );
	}

	/**
	 * Test filter_gettext returns original when not cached
	 */
	public function test_filter_gettext_returns_original_when_not_cached() {
		$result = Gettext_Filter::filter_gettext( 'Hello', 'Hello', 'default' );

		$this->assertEquals( 'Hello', $result );
	}

	/**
	 * Test filter_gettext returns original when cached but empty msgstr
	 */
	public function test_filter_gettext_returns_original_when_empty_msgstr() {
		$this->add_to_cache( 'default', 'Hello', null, '' );

		$result = Gettext_Filter::filter_gettext( 'Hello', 'Hello', 'default' );

		$this->assertEquals( 'Hello', $result );
	}

	/**
	 * Test filter_gettext wraps with marker
	 */
	public function test_filter_gettext_wraps_with_marker() {
		$this->add_to_cache( 'default', 'Hello', null, '你好' );

		$result = Gettext_Filter::filter_gettext( 'Hello', 'Hello', 'default' );

		// Should be wrapped with language marker
		$this->assertStringContainsString( '【', $result );
		$this->assertStringContainsString( '】', $result );
	}

	// =========================================================================
	// filter_gettext_with_context Tests
	// =========================================================================

	/**
	 * Test filter_gettext_with_context returns translation when cached
	 */
	public function test_filter_gettext_with_context_returns_translation_when_cached() {
		$this->add_to_cache( 'my-plugin', 'Post', 'noun', '文章' );

		$result = Gettext_Filter::filter_gettext_with_context( 'Post', 'Post', 'noun', 'my-plugin' );

		$this->assertStringContainsString( '文章', $result );
	}

	/**
	 * Test filter_gettext_with_context returns original when not cached
	 */
	public function test_filter_gettext_with_context_returns_original_when_not_cached() {
		$result = Gettext_Filter::filter_gettext_with_context( 'Post', 'Post', 'noun', 'my-plugin' );

		$this->assertEquals( 'Post', $result );
	}

	/**
	 * Test filter_gettext_with_context uses context in cache lookup
	 */
	public function test_filter_gettext_with_context_uses_context() {
		// Add same text with different contexts
		$this->add_to_cache( 'my-plugin', 'Post', 'noun', '文章' );
		$this->add_to_cache( 'my-plugin', 'Post', 'verb', '发布' );

		$result_noun = Gettext_Filter::filter_gettext_with_context( 'Post', 'Post', 'noun', 'my-plugin' );
		$result_verb = Gettext_Filter::filter_gettext_with_context( 'Post', 'Post', 'verb', 'my-plugin' );

		$this->assertStringContainsString( '文章', $result_noun );
		$this->assertStringContainsString( '发布', $result_verb );
	}

	// =========================================================================
	// filter_ngettext Tests (Plural Forms)
	// =========================================================================

	/**
	 * Test filter_ngettext returns singular when number is 1
	 */
	public function test_filter_ngettext_returns_singular_when_one() {
		$this->add_to_cache( 'default', '%d item', null, '%d 个项目', '%d 个项目' );

		$result = Gettext_Filter::filter_ngettext( '%d item', '%d item', '%d items', 1, 'default' );

		$this->assertStringContainsString( '%d 个项目', $result );
	}

	/**
	 * Test filter_ngettext returns plural when number is not 1
	 */
	public function test_filter_ngettext_returns_plural_when_not_one() {
		$this->add_to_cache( 'default', '%d item', null, '%d 个项目', '%d 个项目们' );

		$result = Gettext_Filter::filter_ngettext( '%d items', '%d item', '%d items', 5, 'default' );

		$this->assertStringContainsString( '%d 个项目们', $result );
	}

	/**
	 * Test filter_ngettext falls back to singular when no plural translation
	 */
	public function test_filter_ngettext_fallback_to_singular_when_no_plural() {
		$this->add_to_cache( 'default', '%d item', null, '%d 个项目', null );

		$result = Gettext_Filter::filter_ngettext( '%d items', '%d item', '%d items', 5, 'default' );

		$this->assertStringContainsString( '%d 个项目', $result );
	}

	/**
	 * Test filter_ngettext returns original when not cached
	 */
	public function test_filter_ngettext_returns_original_when_not_cached() {
		$result = Gettext_Filter::filter_ngettext( '%d item', '%d item', '%d items', 1, 'default' );

		$this->assertEquals( '%d item', $result );
	}

	/**
	 * Test filter_ngettext with number 0
	 */
	public function test_filter_ngettext_with_zero() {
		$this->add_to_cache( 'default', '%d item', null, '%d 个项目', '%d 个项目们' );

		$result = Gettext_Filter::filter_ngettext( '%d items', '%d item', '%d items', 0, 'default' );

		// 0 is not 1, so should use plural
		$this->assertStringContainsString( '%d 个项目们', $result );
	}

	// =========================================================================
	// filter_ngettext_with_context Tests
	// =========================================================================

	/**
	 * Test filter_ngettext_with_context returns translation with context
	 */
	public function test_filter_ngettext_with_context_returns_translation() {
		$this->add_to_cache( 'my-plugin', '%d comment', 'post comment', '%d 条评论', '%d 条评论' );

		$result = Gettext_Filter::filter_ngettext_with_context(
			'%d comment',
			'%d comment',
			'%d comments',
			1,
			'post comment',
			'my-plugin'
		);

		$this->assertStringContainsString( '%d 条评论', $result );
	}

	/**
	 * Test filter_ngettext_with_context returns original when not cached
	 */
	public function test_filter_ngettext_with_context_returns_original_when_not_cached() {
		$result = Gettext_Filter::filter_ngettext_with_context(
			'%d comment',
			'%d comment',
			'%d comments',
			1,
			'post comment',
			'my-plugin'
		);

		$this->assertEquals( '%d comment', $result );
	}

	/**
	 * Test filter_ngettext_with_context uses context in cache lookup
	 */
	public function test_filter_ngettext_with_context_uses_context() {
		$this->add_to_cache( 'my-plugin', '%d comment', 'post comment', '%d 条文章评论', '%d 条文章评论' );
		$this->add_to_cache( 'my-plugin', '%d comment', 'product comment', '%d 条商品评论', '%d 条商品评论' );

		$result_post = Gettext_Filter::filter_ngettext_with_context(
			'%d comment',
			'%d comment',
			'%d comments',
			1,
			'post comment',
			'my-plugin'
		);

		$result_product = Gettext_Filter::filter_ngettext_with_context(
			'%d comment',
			'%d comment',
			'%d comments',
			1,
			'product comment',
			'my-plugin'
		);

		$this->assertStringContainsString( '文章评论', $result_post );
		$this->assertStringContainsString( '商品评论', $result_product );
	}

	// =========================================================================
	// Translation Marker Tests
	// =========================================================================

	/**
	 * Test maybe_wrap_with_marker wraps content
	 */
	public function test_maybe_wrap_with_marker_wraps_content() {
		$method = new ReflectionMethod( Gettext_Filter::class, 'maybe_wrap_with_marker' );
		$method->setAccessible( true );

		$result = $method->invoke( null, '你好' );

		$this->assertStringContainsString( '【', $result );
		$this->assertStringContainsString( '】', $result );
		$this->assertStringContainsString( '你好', $result );
	}

	/**
	 * Test maybe_wrap_with_marker returns empty for empty content
	 */
	public function test_maybe_wrap_with_marker_returns_empty_for_empty() {
		$method = new ReflectionMethod( Gettext_Filter::class, 'maybe_wrap_with_marker' );
		$method->setAccessible( true );

		$result = $method->invoke( null, '' );

		$this->assertEquals( '', $result );
	}

	// =========================================================================
	// Cache Management Tests
	// =========================================================================

	/**
	 * Test clear_cache clears all cache
	 */
	public function test_clear_cache_clears_all() {
		$this->add_to_cache( 'default', 'Hello', null, '你好' );
		$this->add_to_cache( 'my-plugin', 'World', null, '世界' );

		$this->assertNotEmpty( $this->get_static_property( 'cache' ) );

		Gettext_Filter::clear_cache();

		$this->assertEmpty( $this->get_static_property( 'cache' ) );
	}

	/**
	 * Test clear_cache clears loaded_domains
	 */
	public function test_clear_cache_clears_loaded_domains() {
		$this->set_static_property( 'loaded_domains', array( 'default', 'my-plugin' ) );

		Gettext_Filter::clear_cache();

		$this->assertEmpty( Gettext_Filter::get_loaded_domains() );
	}

	/**
	 * Test clear_cache with specific domain removes that domain from loaded_domains
	 */
	public function test_clear_cache_with_domain_removes_from_loaded_domains() {
		$this->set_static_property( 'loaded_domains', array( 'default', 'my-plugin', 'other' ) );

		Gettext_Filter::clear_cache( 'my-plugin' );

		$domains = Gettext_Filter::get_loaded_domains();
		$this->assertNotContains( 'my-plugin', $domains );
	}

	// =========================================================================
	// Event Handler Tests
	// =========================================================================

	/**
	 * Test on_translation_completed clears cache
	 */
	public function test_on_translation_completed_clears_cache() {
		$this->add_to_cache( 'default', 'Hello', null, '你好' );

		Gettext_Filter::on_translation_completed( 1 );

		$this->assertEmpty( $this->get_static_property( 'cache' ) );
	}

	/**
	 * Test on_entry_updated clears cache for domain
	 */
	public function test_on_entry_updated_clears_cache_with_domain() {
		$this->set_static_property( 'loaded_domains', array( 'default', 'my-plugin' ) );

		Gettext_Filter::on_entry_updated( 1, array( 'text_domain' => 'my-plugin' ) );

		$domains = Gettext_Filter::get_loaded_domains();
		$this->assertNotContains( 'my-plugin', $domains );
	}

	/**
	 * Test do_action( wptsall_entry_updated ) reaches the registered listener.
	 */
	public function test_do_action_entry_updated_clears_cache() {
		Gettext_Filter::init();
		$this->set_static_property( 'loaded_domains', array( 'default', 'my-plugin' ) );

		do_action(
			'wptsall_entry_updated',
			1,
			array(
				'text_domain' => 'my-plugin',
			)
		);

		$domains = Gettext_Filter::get_loaded_domains();
		$this->assertNotContains( 'my-plugin', $domains );
	}

	/**
	 * Test on_entry_updated clears all cache when no domain provided
	 */
	public function test_on_entry_updated_clears_all_when_no_domain() {
		$this->add_to_cache( 'default', 'Hello', null, '你好' );
		$this->set_static_property( 'loaded_domains', array( 'default', 'my-plugin' ) );

		Gettext_Filter::on_entry_updated( 1, array() );

		$this->assertEmpty( $this->get_static_property( 'cache' ) );
		$this->assertEmpty( Gettext_Filter::get_loaded_domains() );
	}

	// =========================================================================
	// State Inspection Tests
	// =========================================================================

	/**
	 * Test is_initialized returns correct state
	 */
	public function test_is_initialized_returns_state() {
		$this->set_static_property( 'initialized', false );
		$this->assertFalse( Gettext_Filter::is_initialized() );

		$this->set_static_property( 'initialized', true );
		$this->assertTrue( Gettext_Filter::is_initialized() );

		// Reset
		$this->set_static_property( 'initialized', false );
	}

	/**
	 * Test get_current_relation_id returns correct value
	 */
	public function test_get_current_relation_id_returns_value() {
		$this->set_static_property( 'current_relation_id', 123 );

		$this->assertEquals( 123, Gettext_Filter::get_current_relation_id() );

		// Reset
		$this->set_static_property( 'current_relation_id', null );
	}

	/**
	 * Test get_loaded_domains returns correct array
	 */
	public function test_get_loaded_domains_returns_array() {
		$this->set_static_property( 'loaded_domains', array( 'default', 'my-plugin' ) );

		$domains = Gettext_Filter::get_loaded_domains();

		$this->assertIsArray( $domains );
		$this->assertContains( 'default', $domains );
		$this->assertContains( 'my-plugin', $domains );
	}

	/**
	 * Test get_cache_stats returns correct structure
	 */
	public function test_get_cache_stats_returns_structure() {
		$this->add_to_cache( 'default', 'Hello', null, '你好' );
		$this->add_to_cache( 'default', 'World', null, '世界' );
		$this->set_static_property( 'loaded_domains', array( 'default' ) );

		$stats = Gettext_Filter::get_cache_stats();

		$this->assertIsArray( $stats );
		$this->assertArrayHasKey( 'entries', $stats );
		$this->assertArrayHasKey( 'loaded_domains', $stats );
		$this->assertEquals( 2, $stats['entries'] );
		$this->assertEquals( array( 'default' ), $stats['loaded_domains'] );
	}

	// =========================================================================
	// get_cached Tests
	// =========================================================================

	/**
	 * Test get_cached returns cached entry
	 */
	public function test_get_cached_returns_entry() {
		$this->add_to_cache( 'default', 'Hello', null, '你好', '你好们' );

		$method = new ReflectionMethod( Gettext_Filter::class, 'get_cached' );
		$method->setAccessible( true );

		$result = $method->invoke( null, 'default', 'Hello', null );

		$this->assertIsArray( $result );
		$this->assertEquals( '你好', $result['msgstr'] );
		$this->assertEquals( '你好们', $result['msgstr_plural'] );
	}

	/**
	 * Test get_cached returns false when not found
	 */
	public function test_get_cached_returns_false_when_not_found() {
		$method = new ReflectionMethod( Gettext_Filter::class, 'get_cached' );
		$method->setAccessible( true );

		$result = $method->invoke( null, 'default', 'NonExistent', null );

		$this->assertFalse( $result );
	}
}

<?php
/**
 * Virtual Site Router Tests
 *
 * Tests for WPTSALL\Hooks\Virtual_Site_Router class.
 * Virtual content is stored in wp_posts with _wptsall_virtual_site_id
 * and _wptsall_source_post_id meta markers (v1.2.0+).
 *
 * @package WPTSALL
 * @since 0.5.0
 * @updated 1.2.0 Tests rewritten to use wp_posts + postmeta storage.
 */

use WPTSALL\Hooks\Virtual_Site_Router;
use WPTSALL\Sites\Services\Virtual_Site_Service;

class Test_Virtual_Site_Router extends SimpleTestCase {

	/**
	 * Test post IDs for cleanup
	 *
	 * @var array
	 */
	protected $test_post_ids = array();

	/**
	 * Set up test environment
	 */
	public function setUp(): void {
		parent::setUp();
		$this->clear_content_cache();
	}

	/**
	 * Clean up test data
	 */
	public function tearDown(): void {
		foreach ( $this->test_post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		$this->clear_content_cache();
		parent::tearDown();
	}

	/**
	 * Clear the Virtual_Site_Router content cache.
	 */
	private function clear_content_cache() {
		$ref = new ReflectionClass( Virtual_Site_Router::class );
		$prop = $ref->getProperty( 'content_cache' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );
	}

	/**
	 * Insert virtual content as a wp_posts row with meta markers.
	 *
	 * This matches the write path in tasks.php (v1.2.0+) which stores
	 * virtual content in wp_posts with _wptsall_virtual_site_id and
	 * _wptsall_source_post_id postmeta.
	 *
	 * @param string $virtual_site_id  Virtual site identifier (e.g., 'v_123').
	 * @param int    $source_post_id   Source post ID to reference.
	 * @param array  $post_data        Post fields (post_title, post_content, etc.).
	 * @param array  $meta_data        Optional custom meta to attach.
	 * @return int Inserted post ID.
	 */
	private function insert_virtual_post( $virtual_site_id, $source_post_id, $post_data, $meta_data = array() ) {
		$post_id = wp_insert_post( array_merge(
			array(
				'post_status' => 'publish',
				'post_type'   => 'post',
			),
			$post_data
		) );

		$this->test_post_ids[] = $post_id;

		// Add virtual site meta markers.
		update_post_meta( $post_id, '_wptsall_virtual_site_id', $virtual_site_id );
		update_post_meta( $post_id, '_wptsall_source_post_id', $source_post_id );

		// Add custom meta.
		foreach ( $meta_data as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		return $post_id;
	}

	// ========================================
	// get_virtual_content() Tests (v1.2.0 — wp_posts + postmeta)
	// ========================================

	/**
	 * Test: get_virtual_content() retrieves post from wp_posts + postmeta
	 */
	public function test_get_virtual_content_retrieves_post() {
		$source_post_id = 42;
		$virtual_site_id = 'v_123';

		// Insert virtual content as wp_post with meta markers.
		$this->insert_virtual_post( $virtual_site_id, $source_post_id, array(
			'post_title'   => 'Translated Post',
			'post_content' => 'Translated content',
			'post_excerpt' => 'Translated excerpt',
			'post_name'    => 'translated-post',
			'post_status'  => 'publish',
			'post_type'    => 'post',
		), array(
			'custom_field' => 'custom_value',
		) );

		// Prepare virtual site data.
		$virtual_site = array(
			'id'             => 123,
			'relation_id'    => 123,
			'path_prefix'    => '/zh',
			'lang'           => 'zh_CN',
			'source_blog_id' => get_current_blog_id(),
		);

		// Use reflection to access private method.
		$reflection = new ReflectionClass( Virtual_Site_Router::class );
		$method     = $reflection->getMethod( 'get_virtual_content' );
		$method->setAccessible( true );

		// Call get_virtual_content().
		$content = $method->invoke( null, $virtual_site, 'post_type', 'post', $source_post_id );

		// Assert content structure.
		$this->assertIsArray( $content, 'Should return array' );
		$this->assertArrayHasKey( 'post', $content, 'Should have post key' );
		$this->assertArrayHasKey( 'meta', $content, 'Should have meta key' );

		// Assert post data.
		$this->assertEquals( 'Translated Post', $content['post']['post_title'] );
		$this->assertEquals( 'Translated content', $content['post']['post_content'] );
		$this->assertEquals( 'Translated excerpt', $content['post']['post_excerpt'] );

		// Assert meta data.
		$this->assertEquals( 'custom_value', $content['meta']['custom_field'] );
	}

	/**
	 * Test: get_virtual_content() returns null when no virtual content exists
	 */
	public function test_get_virtual_content_returns_null_when_not_found() {
		$virtual_site = array(
			'id'             => 999,
			'relation_id'    => 999,
			'path_prefix'    => '/test',
			'lang'           => 'en_US',
			'source_blog_id' => 1,
		);

		$reflection = new ReflectionClass( Virtual_Site_Router::class );
		$method     = $reflection->getMethod( 'get_virtual_content' );
		$method->setAccessible( true );

		// Try to get content for non-existent source.
		$content = $method->invoke( null, $virtual_site, 'post_type', 'post', 99999 );

		$this->assertNull( $content, 'Should return null when virtual content not found' );
	}

	/**
	 * Test: get_virtual_content() returns null for taxonomy objects
	 *
	 * Taxonomy content is not stored in wp_posts (only post_type objects are).
	 * get_virtual_content_from_legacy_table() correctly returns null for non-post_type.
	 */
	public function test_get_virtual_content_returns_null_for_taxonomy() {
		$virtual_site = array(
			'id'             => 456,
			'relation_id'    => 456,
			'path_prefix'    => '/zh',
			'lang'           => 'zh_CN',
			'source_blog_id' => get_current_blog_id(),
		);

		$reflection = new ReflectionClass( Virtual_Site_Router::class );
		$method     = $reflection->getMethod( 'get_virtual_content' );
		$method->setAccessible( true );

		// Taxonomy objects are not stored in wp_posts, should return null.
		$content = $method->invoke( null, $virtual_site, 'taxonomy', 'category', 55 );

		$this->assertNull( $content, 'Should return null for taxonomy object_type (not stored in wp_posts)' );
	}

	/**
	 * Test: filter_title() returns translated title from wp_posts + meta
	 */
	public function test_filter_title_returns_translated_title() {
		// Create a real source post so get_post_type() works.
		$source_post_id = wp_insert_post( array(
			'post_type'    => 'post',
			'post_title'   => 'Original Title',
			'post_content' => 'Original content',
			'post_status'  => 'publish',
		) );
		$this->test_post_ids[] = $source_post_id;

		// Insert translated content as wp_post with meta markers.
		$this->insert_virtual_post( 'v_789', $source_post_id, array(
			'post_title'   => 'Translated Title',
			'post_content' => 'Translated content',
			'post_type'    => 'post',
		) );

		// Simulate virtual site context.
		$GLOBALS['wptsall_current_virtual_site'] = array(
			'id'             => 789,
			'relation_id'    => 789,
			'path_prefix'    => '/zh',
			'lang'           => 'zh_CN',
			'source_blog_id' => get_current_blog_id(),
		);

		// Call filter_title().
		$filtered_title = Virtual_Site_Router::filter_title( 'Original Title', $source_post_id );

		// Assert.
		$this->assertEquals( 'Translated Title', $filtered_title, 'Should return translated title' );

		// Clean up global.
		unset( $GLOBALS['wptsall_current_virtual_site'] );
	}

	/**
	 * Test: filter_content() returns translated content from wp_posts + meta
	 */
	public function test_filter_content_returns_translated_content() {
		// Create a real source post.
		$source_post_id = wp_insert_post( array(
			'post_type'    => 'post',
			'post_title'   => 'Original Title',
			'post_content' => 'Original content',
			'post_status'  => 'publish',
		) );
		$this->test_post_ids[] = $source_post_id;

		// Insert translated content as wp_post with meta markers.
		$this->insert_virtual_post( 'v_888', $source_post_id, array(
			'post_title'   => 'Translated Title',
			'post_content' => 'Translated content',
			'post_type'    => 'post',
		) );

		// Simulate virtual site context.
		$GLOBALS['wptsall_current_virtual_site'] = array(
			'id'             => 888,
			'relation_id'    => 888,
			'path_prefix'    => '/zh',
			'lang'           => 'zh_CN',
			'source_blog_id' => get_current_blog_id(),
		);

		// Simulate the_post() global setup.
		global $post;
		$post = get_post( $source_post_id );
		setup_postdata( $post );

		// Call filter_content().
		$filtered_content = Virtual_Site_Router::filter_content( 'Original content' );

		// Assert.
		$this->assertEquals( 'Translated content', $filtered_content, 'Should return translated content' );

		// Clean up.
		wp_reset_postdata();
		unset( $GLOBALS['wptsall_current_virtual_site'] );
	}

	/**
	 * Test: Cache mechanism works correctly
	 */
	public function test_cache_mechanism_works() {
		$source_post_id = 77;

		// Insert virtual content as wp_post with meta markers.
		$this->insert_virtual_post( 'v_111', $source_post_id, array(
			'post_title'   => 'Virtual',
			'post_content' => 'Virtual Content',
			'post_type'    => 'post',
		) );

		$virtual_site = array(
			'id'             => 111,
			'relation_id'    => 111,
			'path_prefix'    => '/test',
			'lang'           => 'en_US',
			'source_blog_id' => get_current_blog_id(),
		);

		$reflection = new ReflectionClass( Virtual_Site_Router::class );
		$method     = $reflection->getMethod( 'get_virtual_content' );
		$method->setAccessible( true );

		// First call - should query database.
		$content1 = $method->invoke( null, $virtual_site, 'post_type', 'post', $source_post_id );

		// Second call - should use cache.
		$content2 = $method->invoke( null, $virtual_site, 'post_type', 'post', $source_post_id );

		// Both should return same content.
		$this->assertEquals( $content1, $content2, 'Cache should return same content' );
		$this->assertEquals( 'Virtual', $content1['post']['post_title'] );
	}

	/**
	 * Test: build_virtual_site_ids() tries multiple ID formats
	 */
	public function test_build_virtual_site_ids_multiple_formats() {
		$reflection = new ReflectionClass( Virtual_Site_Router::class );
		$method     = $reflection->getMethod( 'build_virtual_site_ids' );
		$method->setAccessible( true );

		$virtual_site = array(
			'id'          => 42,
			'relation_id' => 42,
			'lang'        => 'en_US',
		);

		$ids = $method->invoke( null, $virtual_site );

		$this->assertIsArray( $ids );
		$this->assertContains( 'v_42', $ids, 'Should include v_{id} format' );
		$this->assertContains( 42, $ids, 'Should include relation_id' );
		$this->assertContains( 'v_en', $ids, 'Should include v_{lang_code} format' );
		$this->assertContains( '42', $ids, 'Should include raw ID as string' );
	}

	// ========================================
	// URL Transformation Tests (v0.6.0)
	// ========================================

	/**
	 * Test: Virtual site inherits permalink structure when empty
	 */
	public function test_virtual_site_inherits_permalink_structure() {
		// Get current permalink structure.
		$source_structure = get_option( 'permalink_structure', '' );

		// Virtual site with empty permalink (should inherit).
		$virtual_site = array(
			'id'                  => 300,
			'path_prefix'         => 'en',
			'lang'                => 'en_US',
			'permalink_structure' => '',
			'source_blog_id'      => 0,
		);

		if ( class_exists( '\\WPTSALL\\Sites\\Services\\URL_Transformer' ) ) {
			$effective = \WPTSALL\Sites\Services\URL_Transformer::get_effective_permalink_structure( $virtual_site );
			$this->assertEquals( $source_structure, $effective );
		} else {
			$this->markTestSkipped( 'URL_Transformer class not available' );
		}
	}

	/**
	 * Test: Virtual site with explicit permalink structure
	 */
	public function test_virtual_site_explicit_permalink_structure() {
		$virtual_site = array(
			'id'                  => 400,
			'path_prefix'         => 'ja',
			'lang'                => 'ja',
			'permalink_structure' => '/%year%/%monthnum%/%day%/%postname%/',
			'source_blog_id'      => 0,
		);

		if ( class_exists( '\\WPTSALL\\Sites\\Services\\URL_Transformer' ) ) {
			$effective = \WPTSALL\Sites\Services\URL_Transformer::get_effective_permalink_structure( $virtual_site );
			$this->assertEquals( '/%year%/%monthnum%/%day%/%postname%/', $effective );
		} else {
			$this->markTestSkipped( 'URL_Transformer class not available' );
		}
	}

	/**
	 * Test: Virtual site with custom category_base
	 */
	public function test_virtual_site_custom_category_base() {
		$virtual_site = array(
			'id'             => 500,
			'path_prefix'    => 'de',
			'lang'           => 'de_DE',
			'category_base'  => 'kategorie',
			'source_blog_id' => 0,
		);

		if ( class_exists( '\\WPTSALL\\Sites\\Services\\URL_Transformer' )
			&& method_exists( '\\WPTSALL\\Sites\\Services\\URL_Transformer', 'get_effective_category_base' ) ) {
			$effective = \WPTSALL\Sites\Services\URL_Transformer::get_effective_category_base( $virtual_site );
			$this->assertEquals( 'kategorie', $effective );
		} else {
			$this->markTestSkipped( 'URL_Transformer::get_effective_category_base not available' );
		}
	}

	/**
	 * Test: Virtual site with custom tag_base
	 */
	public function test_virtual_site_custom_tag_base() {
		$virtual_site = array(
			'id'             => 600,
			'path_prefix'    => 'fr',
			'lang'           => 'fr_FR',
			'tag_base'       => 'etiquette',
			'source_blog_id' => 0,
		);

		if ( class_exists( '\\WPTSALL\\Sites\\Services\\URL_Transformer' )
			&& method_exists( '\\WPTSALL\\Sites\\Services\\URL_Transformer', 'get_effective_tag_base' ) ) {
			$effective = \WPTSALL\Sites\Services\URL_Transformer::get_effective_tag_base( $virtual_site );
			$this->assertEquals( 'etiquette', $effective );
		} else {
			$this->markTestSkipped( 'URL_Transformer::get_effective_tag_base not available' );
		}
	}

	// ========================================
	// Dynamic Taxonomy Mapping Tests (v0.9.1)
	// ========================================

	/**
	 * Test: build_taxonomy_map() includes registered taxonomies
	 *
	 * @since 0.9.1
	 */
	public function test_build_taxonomy_map_includes_registered_taxonomies() {
		$reflection = new ReflectionClass( Virtual_Site_Router::class );
		$method     = $reflection->getMethod( 'build_taxonomy_map' );
		$method->setAccessible( true );

		// Clear cache first.
		$cache_prop = $reflection->getProperty( 'taxonomy_map_cache' );
		$cache_prop->setAccessible( true );
		$cache_prop->setValue( null, null );

		$tax_map = $method->invoke( null );

		$this->assertIsArray( $tax_map, 'Should return array' );
		$this->assertArrayHasKey( 'category', $tax_map, 'Should include category taxonomy' );
		$this->assertArrayHasKey( 'post_tag', $tax_map, 'Should include post_tag taxonomy' );

		$this->assertEquals( 'category', $tax_map['category'] );
		$this->assertEquals( 'post_tag', $tax_map['post_tag'] );
	}

	/**
	 * Test: build_taxonomy_map() includes rewrite slugs
	 *
	 * @since 0.9.1
	 */
	public function test_build_taxonomy_map_uses_rewrite_slugs() {
		$reflection = new ReflectionClass( Virtual_Site_Router::class );
		$method     = $reflection->getMethod( 'build_taxonomy_map' );
		$method->setAccessible( true );

		$cache_prop = $reflection->getProperty( 'taxonomy_map_cache' );
		$cache_prop->setAccessible( true );
		$cache_prop->setValue( null, null );

		$tax_map = $method->invoke( null );

		// post_tag has a rewrite slug of 'tag'.
		$post_tag_obj = get_taxonomy( 'post_tag' );
		if ( $post_tag_obj && ! empty( $post_tag_obj->rewrite['slug'] ) ) {
			$rewrite_slug = $post_tag_obj->rewrite['slug'];
			$this->assertArrayHasKey( $rewrite_slug, $tax_map, 'Should include rewrite slug mapping' );
			$this->assertEquals( 'post_tag', $tax_map[ $rewrite_slug ], 'Rewrite slug should map to taxonomy name' );
		}
	}

	/**
	 * Test: build_taxonomy_map() caches result
	 *
	 * @since 0.9.1
	 */
	public function test_build_taxonomy_map_caches_result() {
		$reflection = new ReflectionClass( Virtual_Site_Router::class );
		$method     = $reflection->getMethod( 'build_taxonomy_map' );
		$method->setAccessible( true );

		$cache_prop = $reflection->getProperty( 'taxonomy_map_cache' );
		$cache_prop->setAccessible( true );
		$cache_prop->setValue( null, null );

		// First call — should build and cache.
		$result1 = $method->invoke( null );

		// Get cached value.
		$cached = $cache_prop->getValue( null );

		$this->assertNotNull( $cached, 'Cache should be populated after first call' );
		$this->assertIsArray( $cached, 'Cache should be an array' );

		// Second call — should return cached result.
		$result2 = $method->invoke( null );
		$this->assertEquals( $result1, $result2, 'Cached result should be identical' );

		$cache_prop->setValue( null, null );
	}

	/**
	 * Test: build_taxonomy_map() applies filter hook
	 *
	 * @since 0.9.1
	 */
	public function test_build_taxonomy_map_applies_filter_hook() {
		$reflection = new ReflectionClass( Virtual_Site_Router::class );
		$method     = $reflection->getMethod( 'build_taxonomy_map' );
		$method->setAccessible( true );

		$cache_prop = $reflection->getProperty( 'taxonomy_map_cache' );
		$cache_prop->setAccessible( true );
		$cache_prop->setValue( null, null );

		$custom_slug = 'custom-test-slug';
		$custom_tax  = 'custom_test_taxonomy';

		add_filter( 'wptsall_taxonomy_url_map', function( $tax_map ) use ( $custom_slug, $custom_tax ) {
			$tax_map[ $custom_slug ] = $custom_tax;
			return $tax_map;
		} );

		$tax_map = $method->invoke( null );

		$this->assertArrayHasKey( $custom_slug, $tax_map, 'Filter should add custom mapping' );
		$this->assertEquals( $custom_tax, $tax_map[ $custom_slug ], 'Custom mapping should be correct' );

		remove_all_filters( 'wptsall_taxonomy_url_map' );
		$cache_prop->setValue( null, null );
	}

	/**
	 * Test: Route taxonomy URL matches dynamic map
	 *
	 * @since 0.9.1
	 */
	public function test_route_taxonomy_url_matches_dynamic_map() {
		$reflection = new ReflectionClass( Virtual_Site_Router::class );
		$method     = $reflection->getMethod( 'build_taxonomy_map' );
		$method->setAccessible( true );

		$cache_prop = $reflection->getProperty( 'taxonomy_map_cache' );
		$cache_prop->setAccessible( true );
		$cache_prop->setValue( null, null );

		$tax_map = $method->invoke( null );

		$this->assertArrayHasKey( 'category', $tax_map );
		$this->assertEquals( 'category', $tax_map['category'] );

		$potential_taxonomy = 'tag';
		if ( isset( $tax_map[ $potential_taxonomy ] ) ) {
			$this->assertEquals( 'post_tag', $tax_map[ $potential_taxonomy ] );
		}

		$cache_prop->setValue( null, null );
	}

	/**
	 * Test: virtual home pagination path resolves to paged type.
	 */
	public function test_resolve_path_to_object_paged_home() {
		$virtual_site = array(
			'id'             => 424,
			'relation_id'    => 900001,
			'path_prefix'    => 'en-us',
			'lang'           => 'en_US',
			'source_blog_id' => 1,
		);
		$reflection = new ReflectionClass( Virtual_Site_Router::class );
		$method     = $reflection->getMethod( 'resolve_path_to_object' );
		$method->setAccessible( true );

		$resolved = $method->invoke( null, 'page/2', $virtual_site );
		$this->assertIsArray( $resolved, 'page/N must resolve instead of falling through to a 404' );
		$this->assertSame( 'paged', $resolved['type'] );
		$this->assertEquals( 2, $resolved['paged'] );
	}

	/**
	 * Test: add_rewrite_rules self-heals after a hard flush wiped the rules.
	 *
	 * A previous flush_rewrite_rules(true) erased the wptsall rules from the
	 * rewrite_rules option while the internal hash said "no change", leaving
	 * every virtual URL a 404. The rules must be re-registered + flushed
	 * whenever the stored rules no longer contain the plugin marker.
	 */
	public function test_add_rewrite_rules_self_heals_after_hard_flush() {
		// Precondition 1: pretty permalinks. WordPress only persists
		// permastruct rules (including the wptsall virtual-path rules) when
		// permalink_structure is non-empty; under plain permalinks a flush
		// writes the trivial rules array and the plugin marker can never
		// appear — the lab slots are pre-set to /%postname%/ by
		// ensure-slot-wordpress.sh, fresh runner installs are not. Pin the
		// structure and restore it afterwards.
		global $wp_rewrite;
		$orig_structure = $wp_rewrite->permalink_structure;
		$wp_rewrite->set_permalink_structure( '/%postname%/' );

		// Precondition 2: add_rewrite_rules() no-ops when no active virtual
		// sites exist. Fresh installs (module-ci runner) have none; lab
		// slots carry e2e leftovers, which is why this only failed on the
		// 2026-09-09 runner shard. Create one when absent so the self-heal
		// path is exercised hermetically (creation happens BEFORE the rules
		// wipe below, preserving the scenario under test).
		$existing = Virtual_Site_Service::get_all( array( 'status' => 'active' ) );
		if ( empty( $existing ) ) {
			Virtual_Site_Service::create(
				array(
					'name'        => 'Self-heal Test Site',
					'path_prefix' => 'self-heal-test',
					'lang'        => 'fr_FR',
				)
			);
		}
		update_option( 'rewrite_rules', array( 'x' => 'index.php?x=1' ), 'no' );
		update_option( 'wptsall_rewrite_hash', md5( 'simulated-hash' ), 'no' );

		// Clear the cached virtual-sites static so the DB is re-read.
		$reflection = new ReflectionClass( Virtual_Site_Router::class );
		$cache_prop = $reflection->getProperty( 'cached_virtual_sites' );
		$cache_prop->setAccessible( true );
		$cache_prop->setValue( null, null );

		Virtual_Site_Router::add_rewrite_rules();

		$rules = get_option( 'rewrite_rules', array() );
		$found = false;
		foreach ( (array) $rules as $regex => $query ) {
			if ( false !== strpos( (string) $query, 'wptsall_virtual_path' ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'add_rewrite_rules must re-register and flush when stored rules were wiped' );

		$wp_rewrite->set_permalink_structure( $orig_structure );
	}

	/**
	 * Force router "main site" state (no current virtual site).
	 *
	 * @param mixed $value Value for the current virtual site static.
	 * @return void
	 */
	private function set_router_current_virtual_site( $value ) {
		$reflection = new ReflectionClass( Virtual_Site_Router::class );
		$prop       = $reflection->getProperty( 'current_virtual_site' );
		$prop->setAccessible( true );
		$prop->setValue( null, $value );
	}

	/**
	 * Whether the lab has any configured virtual sites (the main-site
	 * exclusion path requires at least one to be meaningful).
	 *
	 * @return bool
	 */
	private function router_has_virtual_sites() {
		$reflection = new ReflectionClass( Virtual_Site_Router::class );
		$method     = $reflection->getMethod( 'get_cached_virtual_sites' );
		$method->setAccessible( true );
		return ! empty( $method->invoke( null ) );
	}

	/**
	 * Test: main-site exclusion must not override an explicit meta query
	 * for virtual-site markers.
	 *
	 * exclude_virtual_posts_from_main_query() merges a
	 * `_wptsall_virtual_site_id NOT EXISTS` clause into every main-site
	 * archive query so shadow copies do not leak into source queries.
	 * But when the caller deliberately queries those markers (storage
	 * layer, sync tooling), the exclusion would sabotage the explicit
	 * intent and return 0 rows.
	 */
	public function test_main_site_exclusion_honors_explicit_shadow_meta_query() {
		if ( ! $this->router_has_virtual_sites() ) {
			$this->markTestSkipped( 'No virtual sites configured' );
		}
		$this->set_router_current_virtual_site( null );

		$query = new WP_Query( array(
			'post_type'        => 'post',
			'post_status'      => 'publish',
			'suppress_filters' => true,
			'meta_query'       => array(
				array(
					'key'   => '_wptsall_virtual_site_id',
					'value' => 'v_query_9876',
				),
				array(
					'key'   => '_wptsall_source_post_id',
					'value' => '9876',
				),
			),
		) );

		Virtual_Site_Router::exclude_virtual_posts_from_main_query( $query );

		$merged = $query->get( 'meta_query' );
		$this->assertIsArray( $merged, 'meta_query should stay an array' );
		foreach ( $merged as $clause ) {
			if ( ! is_array( $clause ) || empty( $clause['key'] ) ) {
				continue;
			}
			if ( '_wptsall_virtual_site_id' === $clause['key'] ) {
				$this->assertNotEquals(
					'NOT EXISTS',
					$clause['compare'] ?? '',
					'explicit shadow meta query must not be overridden by the main-site NOT EXISTS exclusion'
				);
			}
		}
	}

	/**
	 * Test: main-site exclusion still applies to a plain archive query
	 * (no explicit shadow-marker meta query).
	 */
	public function test_main_site_exclusion_applies_to_plain_archive_query() {
		if ( ! $this->router_has_virtual_sites() ) {
			$this->markTestSkipped( 'No virtual sites configured' );
		}
		$this->set_router_current_virtual_site( null );

		$query = new WP_Query( array(
			'post_type'        => 'post',
			'post_status'      => 'publish',
			'suppress_filters' => true,
		) );

		Virtual_Site_Router::exclude_virtual_posts_from_main_query( $query );

		$merged   = $query->get( 'meta_query' );
		$excluded = false;
		foreach ( (array) $merged as $clause ) {
			if ( is_array( $clause ) && ( $clause['key'] ?? '' ) === '_wptsall_virtual_site_id' && ( $clause['compare'] ?? '' ) === 'NOT EXISTS' ) {
				$excluded = true;
				break;
			}
		}
		$this->assertTrue( $excluded, 'plain main-site archive query must still exclude shadow copies' );
	}

	/**
	 * Classic theme-compat plugins may empty template_include on block themes;
	 * ensure_usable_template_include must restore a readable canvas path.
	 */
	public function test_ensure_usable_template_include_restores_block_canvas() {
		$reflection = new ReflectionClass( Virtual_Site_Router::class );
		$orig_prop  = $reflection->getProperty( 'original_template_include' );
		$orig_prop->setAccessible( true );
		$orig_prop->setValue( null, null );

		$canvas = ABSPATH . WPINC . '/template-canvas.php';
		if ( ! is_readable( $canvas ) ) {
			$this->markTestSkipped( 'template-canvas.php not readable in this environment' );
		}

		Virtual_Site_Router::capture_template_include_original( $canvas );
		$restored = Virtual_Site_Router::ensure_usable_template_include( '' );
		$this->assertSame( $canvas, $restored, 'empty template_include must restore captured original' );

		$orig_prop->setValue( null, null );
		$restored_false = Virtual_Site_Router::ensure_usable_template_include( false );
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			$this->assertSame( $canvas, $restored_false, 'false template_include must fall back to block canvas' );
		}
	}

	/**
	 * Test: the block-theme template_include safeguard registers without virtual sites.
	 *
	 * Native subsite blogs where this plugin is active have no virtual sites of
	 * their own, yet classic theme-compat plugins (bbPress topic singles) drop
	 * template_include to false there exactly like on the main blog. The
	 * safeguard must not be gated on virtual sites, or subsite CPT singles
	 * render as 200 with an empty body.
	 */
	public function test_template_safeguard_registered_without_virtual_sites() {
		$reflection = new ReflectionClass( Virtual_Site_Router::class );
		$cache_prop = $reflection->getProperty( 'cached_virtual_sites' );
		$cache_prop->setAccessible( true );
		$cache_prop->setValue( null, array() ); // No virtual sites on this blog.

		remove_all_filters( 'template_include' );
		Virtual_Site_Router::init();

		$this->assertNotFalse(
			has_filter( 'template_include', array( Virtual_Site_Router::class, 'capture_template_include_original' ) ),
			'capture filter must be registered without virtual sites (subsite coverage)'
		);
		$this->assertNotFalse(
			has_filter( 'template_include', array( Virtual_Site_Router::class, 'ensure_usable_template_include' ) ),
			'ensure filter must be registered without virtual sites (subsite coverage)'
		);
	}

	/**
	 * Test: maybe_redirect_source_post_to_virtual skips when permalink_fallback is disabled.
	 */
	public function test_permalink_fallback_skips_when_disabled() {
		// Simulate a 404 query.
		global $wp_query;
		$original_is_404 = $wp_query->is_404 ?? false;
		$wp_query->is_404 = true;

		// Ensure no current virtual site context.
		$this->set_router_current_virtual_site( null );

		// Disable permalink_fallback via Settings_Service mock if available.
		if ( class_exists( '\\WPTSALL\\Settings\\Services\\Settings_Service' ) ) {
			\WPTSALL\Settings\Services\Settings_Service::update( array( 'permalink_fallback' => false ) );
		} else {
			$this->markTestSkipped( 'Settings_Service not available' );
		}

		// Should not redirect (returns void without wp_redirect).
		ob_start();
		Virtual_Site_Router::maybe_redirect_source_post_to_virtual();
		$output = ob_get_clean();

		$this->assertEmpty( $output, 'permalink_fallback=false must suppress P0-3 redirect' );

		// Restore.
		\WPTSALL\Settings\Services\Settings_Service::update( array( 'permalink_fallback' => true ) );
		$wp_query->is_404 = $original_is_404;
	}

	/**
	 * Test: resolve_path_to_object returns null + sets routing_error when
	 * site relation does not exist.
	 */
	public function test_resolve_path_returns_routing_error_for_missing_relation() {
		$virtual_site = array(
			'id'             => 99999,
			'relation_id'    => 0,
			'path_prefix'    => 'nonexistent',
			'lang'           => 'xx_XX',
			'source_blog_id' => 1,
		);

		$reflection = new ReflectionClass( Virtual_Site_Router::class );
		$method     = $reflection->getMethod( 'resolve_path_to_object' );
		$method->setAccessible( true );

		$result = $method->invoke( null, 'some-post', $virtual_site );
		$this->assertNull( $result, 'Missing site relation must yield null' );

		$error_prop = $reflection->getProperty( 'routing_error' );
		$error_prop->setAccessible( true );
		$error = $error_prop->getValue( null );
		$this->assertIsArray( $error );
		$this->assertSame( 'site_relation_not_found', $error['code'] );

		// Clean up static.
		$error_prop->setValue( null, null );
	}

	/**
	 * Test: resolve_path_to_object strips /page/N from CPT paths and
	 * preserves the paged number in the result.
	 */
	public function test_resolve_path_strips_pagination_from_cpt_path() {
		$virtual_site = array(
			'id'             => 424,
			'relation_id'    => 900001,
			'path_prefix'    => 'en-us',
			'lang'           => 'en_US',
			'source_blog_id' => 1,
		);

		$reflection = new ReflectionClass( Virtual_Site_Router::class );
		$method     = $reflection->getMethod( 'resolve_path_to_object' );
		$method->setAccessible( true );

		// Even if the full path can't resolve to a post, the paged extraction
		// logic must run. Passing just "page/3" should yield 'paged' type.
		$resolved = $method->invoke( null, 'page/3', $virtual_site );
		$this->assertIsArray( $resolved );
		$this->assertEquals( 3, $resolved['paged'] ?? 0, '/page/N must extract paged=3' );
	}

	/**
	 * Test: resolve_path_to_object handles non-publicly-queryable CPT slug
	 * by skipping detected_post_type (only public types are matched).
	 */
	public function test_resolve_path_ignores_non_public_post_type_slug() {
		// Register a private post type temporarily.
		register_post_type( 'wptsall_test_private', array(
			'public' => false,
			'label'  => 'Private Test',
		) );

		$virtual_site = array(
			'id'             => 424,
			'relation_id'    => 900001,
			'path_prefix'    => 'en-us',
			'lang'           => 'en_US',
			'source_blog_id' => 1,
		);

		$reflection = new ReflectionClass( Virtual_Site_Router::class );
		$method     = $reflection->getMethod( 'resolve_path_to_object' );
		$method->setAccessible( true );

		// Path starts with the private CPT slug — should NOT be detected.
		$result = $method->invoke( null, 'wptsall_test_private/some-slug', $virtual_site );

		// The result may be null (unresolvable) but the key point is that
		// it must NOT have subtype = 'wptsall_test_private'.
		if ( is_array( $result ) ) {
			$this->assertNotEquals(
				'wptsall_test_private',
				$result['subtype'] ?? '',
				'Non-public CPT slug must not be detected as post type'
			);
		}

		unregister_post_type( 'wptsall_test_private' );

		// Clean up routing error if set.
		$error_prop = $reflection->getProperty( 'routing_error' );
		$error_prop->setAccessible( true );
		$error_prop->setValue( null, null );
	}

	/**
	 * Test: ensure_usable_template_include returns valid template unchanged.
	 */
	public function test_ensure_usable_template_include_passes_readable_template() {
		$readable = __FILE__; // This test file itself is readable.
		$result   = Virtual_Site_Router::ensure_usable_template_include( $readable );
		$this->assertSame( $readable, $result, 'Readable template must be returned as-is' );
	}

	/**
	 * Test: display_routing_error outputs wp_die content with the error code.
	 */
	public function test_display_routing_error_outputs_error_code() {
		$reflection = new ReflectionClass( Virtual_Site_Router::class );
		$method     = $reflection->getMethod( 'display_routing_error' );
		$method->setAccessible( true );

		$error = array(
			'code'    => 'model_not_found',
			'message' => 'Model does not exist',
			'details' => array( 'object_name' => 'event' ),
		);

		$captured = '';
		add_filter(
			'wp_die_handler',
			function () use ( &$captured ) {
				return function ( $message ) use ( &$captured ) {
					$captured = (string) $message;
					throw new \RuntimeException( 'wp_die captured for test' );
				};
			}
		);

		try {
			$method->invoke( null, $error );
			$this->fail( 'display_routing_error must call wp_die' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_die captured for test', $e->getMessage() );
		}

		$this->assertStringContainsString(
			'model_not_found',
			$captured,
			'Routing error page must contain the error code'
		);
	}
}

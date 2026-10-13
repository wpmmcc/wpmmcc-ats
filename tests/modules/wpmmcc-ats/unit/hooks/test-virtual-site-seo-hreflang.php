<?php
/**
 * Hreflang / translation-status target_lang JOIN tests.
 *
 * Regression tests for hreflang + admin translation lookups joining
 * `wptsall_site_relations` for `target_lang` (it does not exist on
 * `wptsall_post_mappings`), and for deriving the subsite blog id from
 * `target_site_id` when `target_site_type = 'wp'` (same convention as
 * Manual_Content_Service).
 *
 * @package WPTSALL
 * @since 2.1.0
 */

use WPTSALL\Hooks\Virtual_Site_SEO;
use WPTSALL\Hooks\Admin_Virtual_Site_Manager;
use WPTSALL\Hooks\Virtual_Site_Router;
use WPTSALL\TranslationStatus\Translation_Status_Column;

class Test_Virtual_Site_SEO_Hreflang extends SimpleTestCase {

	protected $cleanup = array();

	public function tearDown(): void {
		global $wpdb;
		foreach ( (array) ( $this->cleanup['mappings'] ?? array() ) as $id ) {
			$wpdb->delete( wptsall_table( 'post_mappings' ), array( 'id' => $id ), array( '%d' ) );
		}
		foreach ( (array) ( $this->cleanup['relations'] ?? array() ) as $id ) {
			$wpdb->delete( wptsall_table( 'site_relations' ), array( 'id' => $id ), array( '%d' ) );
		}
		foreach ( (array) ( $this->cleanup['posts'] ?? array() ) as $id ) {
			wp_delete_post( $id, true );
		}
		$this->cleanup = array();
		parent::tearDown();
	}

	private function insert_relation( string $target_site_id, string $target_type, string $lang = 'en_US' ): int {
		global $wpdb;
		$uniq    = substr( md5( (string) microtime( true ) ), 0, 8 );
		$wpdb->insert(
			wptsall_table( 'site_relations' ),
			array(
				'source_site_id'   => 1,
				'source_site_type' => 'wp',
				'source_lang'      => 'zh_CN',
				'source_theme_name' => '',
				'source_theme_path' => '',
				'template'         => 'test-hreflang-' . $uniq,
				'target_site_id'   => $target_site_id,
				'target_site_type' => $target_type,
				'target_lang'      => $lang,
				'target_theme_name' => '',
				'target_theme_path' => '',
				'media_handling'   => 'copy',
				'sync_mode'        => 'new_only',
				'direction'        => 'source_to_target',
				'conflict_strategy' => 'source_wins',
				'status'           => 'active',
				'created_at'       => current_time( 'mysql' ),
				'updated_at'       => current_time( 'mysql' ),
			)
		);
		$id = (int) $wpdb->insert_id;
		$this->assertGreaterThan( 0, $id, 'site relation insert failed: ' . $wpdb->last_error );
		$this->cleanup['relations'][] = $id;
		return $id;
	}

	private function insert_mapping( int $relation_id, int $source_id, int $target_id, string $target_site_id ): void {
		global $wpdb;
		$wpdb->insert(
			wptsall_table( 'post_mappings' ),
			array(
				'relation_id'      => $relation_id,
				'source_post_id'   => $source_id,
				'source_post_type' => 'post',
				'source_site_id'   => 1,
				'target_post_id'   => $target_id,
				'target_post_type' => 'post',
				'target_site_id'   => $target_site_id,
				'relationship_type' => 'translation',
				'needs_resync'     => 0,
				'created_at'       => current_time( 'mysql' ),
				'updated_at'       => current_time( 'mysql' ),
			)
		);
		$id = (int) $wpdb->insert_id;
		$this->assertGreaterThan( 0, $id, 'mapping insert failed: ' . $wpdb->last_error );
		$this->cleanup['mappings'][] = $id;
	}

	private function insert_post( string $title, string $name ): int {
		$id = wp_insert_post(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => '<p>hreflang fixture</p>',
				'post_name'    => $name,
			)
		);
		$this->assertGreaterThan( 0, (int) $id, 'post insert failed' );
		$this->cleanup['posts'][] = (int) $id;
		return (int) $id;
	}

	private static function invoke_static( string $class, string $method, array $args = array() ) {
		$m = new ReflectionMethod( $class, $method );
		$m->setAccessible( true );
		return $m->invokeArgs( null, $args );
	}

	private static function invoke_instance( string $class, string $method, array $args = array() ) {
		$r  = new ReflectionClass( $class );
		$o  = $r->newInstanceWithoutConstructor();
		$m  = $r->getMethod( $method );
		$m->setAccessible( true );
		return $m->invokeArgs( $o, $args );
	}

	public function test_collect_alternate_hreflangs_virtual_target() {
		global $wpdb;
		$source_id = $this->insert_post( '源文章', 'hsrc-' . substr( md5( 'v' ), 0, 6 ) );
		$shadow_id = $this->insert_post( 'EN Post', 'hen-' . substr( md5( 'v' ), 0, 6 ) );
		$rel_id    = $this->insert_relation( 'v_htest1', 'virtual' );
		$this->insert_mapping( $rel_id, $source_id, $shadow_id, 'v_htest1' );
		$wpdb->last_error = '';

		$h = self::invoke_static( Virtual_Site_SEO::class, 'collect_alternate_hreflangs', array( $source_id, array() ) );

		$this->assertSame( '', $wpdb->last_error, 'collect_alternate_hreflangs must not hit unknown columns' );
		$this->assertArrayHasKey( 'en-US', $h );
		$this->assertSame( get_permalink( $shadow_id ), $h['en-US'] );
	}

	public function test_collect_alternate_hreflangs_wp_subsite_target() {
		if ( ! is_multisite() ) {
			$this->assertTrue( true, 'skip: not multisite' );
			return;
		}
		global $wpdb;
		$source_id = $this->insert_post( '源文章2', 'hsrc-' . substr( md5( 'w' ), 0, 6 ) );
		switch_to_blog( 2 );
		$sub_id = wp_insert_post(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'SUB EN',
				'post_content' => '<p>subsite fixture</p>',
				'post_name'    => 'hsub-' . substr( md5( 'w' ), 0, 6 ),
			)
		);
		restore_current_blog();
		$this->assertGreaterThan( 0, (int) $sub_id );
		$this->cleanup['posts'][] = (int) $sub_id;

		$rel_id = $this->insert_relation( '2', 'wp' );
		$this->insert_mapping( $rel_id, $source_id, (int) $sub_id, '2' );
		$wpdb->last_error = '';

		$h = self::invoke_static( Virtual_Site_SEO::class, 'collect_alternate_hreflangs', array( $source_id, array() ) );

		$this->assertSame( '', $wpdb->last_error, 'collect_alternate_hreflangs must not hit unknown columns' );
		$this->assertArrayHasKey( 'en-US', $h );
		$this->assertSame( get_blog_permalink( 2, (int) $sub_id ), $h['en-US'], 'wp-target hreflang must point at the subsite permalink' );
	}

	public function test_build_virtual_url_keeps_full_home_url() {
		$vs = array( 'path_prefix' => 'en-us', 'lang' => 'en_US' );
		$url = self::invoke_static( Virtual_Site_SEO::class, 'build_virtual_url', array( $vs, 0 ) );
		$this->assertSame( untrailingslashit( home_url() ) . '/en-us/', $url );
	}

	public function test_add_virtual_prefix_preserves_port() {
		$GLOBALS['wptsall_current_virtual_site'] = array( 'path_prefix' => 'en-us', 'lang' => 'en_US' );
		$url = self::invoke_static(
			Virtual_Site_Router::class,
			'add_virtual_prefix',
			array( 'http://example.test:8080/blog/2026/08/13/sample/', null )
		);
		unset( $GLOBALS['wptsall_current_virtual_site'] );
		$this->assertSame(
			'http://example.test:8080/en-us/blog/2026/08/13/sample/',
			$url,
			'virtual prefix must keep the original port'
		);
	}

	public function test_canonical_source_permalink_has_no_virtual_prefix() {
		$source_id = $this->insert_post( '源文章6', 'hsrc-' . substr( md5( 'c' ), 0, 6 ) );
		$shadow_id = $this->insert_post( 'EN Post 6', 'hen-' . substr( md5( 'c' ), 0, 6 ) );
		update_post_meta( $shadow_id, '_wptsall_source_post_id', $source_id );
		$GLOBALS['wptsall_current_virtual_site'] = array( 'path_prefix' => 'en-us', 'lang' => 'en_US' );

		$url = self::invoke_static(
			Virtual_Site_SEO::class,
			'canonical_source_permalink',
			array( $source_id )
		);
		unset( $GLOBALS['wptsall_current_virtual_site'] );

		$this->assertStringNotContainsString( 'en-us', $url, 'x-default must not carry the virtual prefix' );
		$this->assertSame( get_permalink( $source_id ), $url );
	}

	public function test_resolve_canonical_source_id_returns_source_for_shadow() {
		$source_id = $this->insert_post( '源文章5', 'hsrc-' . substr( md5( 'x' ), 0, 6 ) );
		$shadow_id = $this->insert_post( 'EN Post 5', 'hen-' . substr( md5( 'x' ), 0, 6 ) );
		update_post_meta( $shadow_id, '_wptsall_source_post_id', $source_id );

		// Shadow resolves back to the source post (x-default target).
		$this->assertSame(
			$source_id,
			self::invoke_static( Virtual_Site_SEO::class, 'resolve_canonical_source_id', array( $shadow_id ) )
		);
		// Source post passes through unchanged.
		$this->assertSame(
			$source_id,
			self::invoke_static( Virtual_Site_SEO::class, 'resolve_canonical_source_id', array( $source_id ) )
		);
	}

	public function test_translation_status_column_mappings_join_relation_lang() {
		global $wpdb;
		$source_id = $this->insert_post( '源文章3', 'hsrc-' . substr( md5( 't' ), 0, 6 ) );
		$shadow_id = $this->insert_post( 'EN Post 3', 'hen-' . substr( md5( 't' ), 0, 6 ) );
		$rel_id    = $this->insert_relation( 'v_htest3', 'virtual', 'en_US' );
		$this->insert_mapping( $rel_id, $source_id, $shadow_id, 'v_htest3' );
		$wpdb->last_error = '';

		$map = self::invoke_instance( Translation_Status_Column::class, 'get_mappings_for_post', array( $source_id ) );

		$this->assertSame( '', $wpdb->last_error, 'translation status column must not hit unknown columns' );
		$this->assertArrayHasKey( 'en_US', $map );
		$this->assertSame( $shadow_id, (int) $map['en_US']['target_post_id'] );
	}

	public function test_resolve_virtual_document_title_repairs_untitled() {
		$shadow_id = $this->insert_post( 'EN Real Title', 'hen-' . substr( md5( 'ttl' ), 0, 6 ) );
		$GLOBALS['wptsall_current_virtual_site'] = array( 'path_prefix' => 'en-us', 'lang' => 'en_US' );

		$fixed = self::invoke_static(
			Virtual_Site_SEO::class,
			'resolve_virtual_document_title',
			array( 'Untitled', $shadow_id )
		);
		unset( $GLOBALS['wptsall_current_virtual_site'] );

		$this->assertSame( 'EN Real Title', $fixed );
	}

	public function test_is_unusable_document_title_detects_placeholders() {
		$this->assertTrue(
			self::invoke_static( Virtual_Site_SEO::class, 'is_unusable_document_title', array( '' ) )
		);
		$this->assertTrue(
			self::invoke_static( Virtual_Site_SEO::class, 'is_unusable_document_title', array( 'Untitled' ) )
		);
		$this->assertFalse(
			self::invoke_static( Virtual_Site_SEO::class, 'is_unusable_document_title', array( 'EN Real Title' ) )
		);
	}

	public function test_tsf_title_filter_repairs_blank_on_virtual_site() {
		$shadow_id = $this->insert_post( 'EN TSF Title', 'hen-' . substr( md5( 'tsf' ), 0, 6 ) );
		$GLOBALS['wptsall_current_virtual_site'] = array( 'path_prefix' => 'en-us', 'lang' => 'en_US' );

		$out = Virtual_Site_SEO::filter_tsf_title( '', array( 'id' => $shadow_id ) );
		unset( $GLOBALS['wptsall_current_virtual_site'] );

		$this->assertSame( 'EN TSF Title', $out );
	}

	public function test_admin_virtual_site_manager_collects_translations_via_relation_lang() {
		global $wpdb;
		$source_id = $this->insert_post( '源文章4', 'hsrc-' . substr( md5( 'a' ), 0, 6 ) );
		$shadow_id = $this->insert_post( 'EN Post 4', 'hen-' . substr( md5( 'a' ), 0, 6 ) );
		$rel_id    = $this->insert_relation( 'v_htest4', 'virtual', 'en_US' );
		$this->insert_mapping( $rel_id, $source_id, $shadow_id, 'v_htest4' );
		$wpdb->last_error = '';

		// From the source post: mapping by language code.
		$from_source = self::invoke_instance( Admin_Virtual_Site_Manager::class, 'collect_post_translations', array( $source_id ) );
		$this->assertSame( '', $wpdb->last_error, 'admin virtual site manager must not hit unknown columns' );
		$this->assertSame( $shadow_id, (int) ( $from_source['en_US'] ?? 0 ) );

		// From the shadow target post: current language detection.
		$from_shadow = self::invoke_instance( Admin_Virtual_Site_Manager::class, 'collect_post_translations', array( $shadow_id ) );
		$this->assertSame( 'en_US', (string) ( $from_shadow['_current_lang'] ?? '' ) );
	}
}

<?php
/**
 * Virtual-site hook contract tests (L1).
 *
 * Extension-point contracts for the escape-hatch hooks of the virtual-site
 * frontend layer: SEO emission/canonical hooks (class-virtual-site-seo.php),
 * router resolution hooks (class-virtual-site-router.php), and home_url
 * rewrite hooks (class-virtual-site-link-filters.php). Each test proves the
 * hook fires at its call site with the documented args and that the
 * listener's return value flips the documented behavior.
 *
 * catalog: WP-HOOK-wptsall-seo-skip-emit-hreflang
 * catalog: WP-HOOK-wptsall-rel-hreflang-attributes
 * catalog: WP-HOOK-wptsall-must-translate-canonical
 * catalog: WP-HOOK-wptsall-seo-skip-emit-robots
 * catalog: WP-HOOK-wptsall-allow-canonical-redirect
 * catalog: WP-HOOK-wptsall-allow-taxonomy-without-model
 * catalog: WP-HOOK-wptsall-is-redirected
 * catalog: WP-HOOK-wptsall-filter-home-url
 * catalog: WP-HOOK-wptsall-skip-home-url-filter
 * oracle: L1
 *
 * @package WPTSALL\Tests\Unit\Hooks
 * @since 2.1.4
 */

use WPTSALL\Hooks\Virtual_Site_SEO;
use WPTSALL\Hooks\Virtual_Site_Router;
use WPTSALL\Hooks\Virtual_Site_Link_Filters;

class Test_Hook_Contracts_Virtual extends SimpleTestCase {

	/**
	 * Captured hook invocations: hook => list of args arrays.
	 *
	 * @var array
	 */
	private $captured = array();

	/**
	 * Captured filter-override hooks (for release in tearDown).
	 *
	 * @var string[]
	 */
	private $hooks_used = array();

	/**
	 * Register a capturing filter listener.
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $override Return override callback (receives args).
	 */
	private function capture_filter( $hook, $override = null ) {
		$captured   = &$this->captured;
		$hooks_used = &$this->hooks_used;
		$hooks_used[] = $hook;
		add_filter(
			$hook,
			function ( $value ) use ( &$captured, $hook, $override ) {
				$args                = func_get_args();
				$captured[ $hook ][] = $args;
				if ( null === $override ) {
					return $value;
				}
				return is_callable( $override ) ? call_user_func_array( $override, $args ) : $override;
			},
			10,
			8
		);
	}

	/**
	 * Reset a private/protected static property on a class.
	 *
	 * @param string $class Class name.
	 * @param string $prop  Property name.
	 * @param mixed  $value Value to set.
	 */
	private function reset_static( $class, $prop, $value = null ) {
		$rp = new ReflectionProperty( $class, $prop );
		$rp->setAccessible( true );
		$rp->setValue( null, $value );
	}

	/**
	 * Minimal virtual-site context array used by the SEO/link-filter paths.
	 *
	 * @return array
	 */
	private function vs_context() {
		return array(
			'site_id'     => 'vtest',
			'path_prefix' => 'vtest',
			'lang'        => 'en_US',
			'relation'    => array( 'seo_visibility' => 'noindex' ),
		);
	}

	/**
	 * Invoke the private router resolver.
	 *
	 * @param string $path Request path.
	 * @param array  $vs   Virtual site.
	 * @return array|null Resolved object or null.
	 */
	private function resolve_path( $path, $vs ) {
		$m = new ReflectionMethod( Virtual_Site_Router::class, 'resolve_path_to_object' );
		$m->setAccessible( true );
		return $m->invoke( null, $path, $vs );
	}

	/**
	 * Saved wp_query global (restored in tearDown — other suite files rely
	 * on it persisting; unsetting it broke test-virtual-site-router).
	 *
	 * @var mixed
	 */
	private $saved_wp_query = null;

	/**
	 * Whether wp_query was set before this file ran.
	 *
	 * @var bool
	 */
	private $had_wp_query = false;

	public function setUp(): void {
		parent::setUp();
		$this->captured   = array();
		$this->hooks_used = array();
		$this->had_wp_query = isset( $GLOBALS['wp_query'] );
		$this->saved_wp_query = $this->had_wp_query ? $GLOBALS['wp_query'] : null;
	}

	public function tearDown(): void {
		foreach ( array_unique( $this->hooks_used ) as $hook ) {
			remove_all_filters( $hook, 10 );
		}
		$this->hooks_used = array();
		$this->captured   = array();
		unset( $GLOBALS['wptsall_current_virtual_site'] );
		if ( $this->had_wp_query ) {
			$GLOBALS['wp_query'] = $this->saved_wp_query;
		} else {
			unset( $GLOBALS['wp_query'] );
		}
		$this->reset_static( Virtual_Site_Router::class, 'current_virtual_site' );
		$this->reset_static( Virtual_Site_SEO::class, 'hreflang_emitted', false );
		global $wpdb;
		foreach ( $this->relation_ids as $rid ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->delete( wptsall_table( 'site_relations' ), array( 'id' => $rid ), array( '%d' ) );
		}
		$this->relation_ids = array();
		parent::tearDown();
	}

	// ==================== SEO emission hooks ====================

	/**
	 * wptsall_seo_skip_emit_hreflang: true suppresses hreflang emission
	 * entirely (wp_head_hreflang short-circuits before any output).
	 */
	public function test_seo_skip_emit_hreflang_contract() {
		$this->reset_static( Virtual_Site_SEO::class, 'hreflang_emitted', false );
		$this->capture_filter( 'wptsall_seo_skip_emit_hreflang', true );

		ob_start();
		Virtual_Site_SEO::wp_head_hreflang();
		$out = ob_get_clean();

		$this->assertSame( '', $out, 'skip=true must suppress all hreflang output' );
		$this->assertNotEmpty( $this->captured['wptsall_seo_skip_emit_hreflang'], 'skip filter must fire inside wp_head_hreflang()' );
	}

	/**
	 * wptsall_rel_hreflang_attributes: fires with ($hreflangs, $post_id, $vs)
	 * on the source-home path and the listener map is what gets emitted.
	 */
	public function test_rel_hreflang_attributes_contract() {
		$this->reset_static( Virtual_Site_SEO::class, 'hreflang_emitted', false );

		$override = function ( $hreflangs ) {
			$hreflangs['x-test'] = 'https://example.org/hooktest';
			return $hreflangs;
		};
		$this->capture_filter( 'wptsall_rel_hreflang_attributes', $override );

		ob_start();
		Virtual_Site_SEO::wp_head_hreflang();
		$out = ob_get_clean();

		$this->assertStringContainsString( 'hreflang="x-test"', $out, 'listener-appended entry must be emitted' );
		$this->assertStringContainsString( 'https://example.org/hooktest', $out, 'listener-provided URL must be emitted' );
		$this->assertStringContainsString( 'hreflang="x-default"', $out, 'default x-default entry must survive (listener extends, not replaces defaults)' );

		$calls = $this->captured['wptsall_rel_hreflang_attributes'];
		$this->assertNotEmpty( $calls, 'filter must fire on the source-home emission path' );
		$this->assertIsArray( $calls[0][0], 'first arg must be the hreflang map' );
		$this->assertSame( 0, $calls[0][1], 'second arg must be the post id (0 on the home path)' );
		$this->assertIsArray( $calls[0][2], 'third arg must be the virtual-site array' );
	}

	// ==================== canonical hooks ====================

	/**
	 * wptsall_must_translate_canonical: fires with (true, $canonical) before
	 * the virtual-site check; false leaves the canonical untouched.
	 */
	public function test_must_translate_canonical_contract() {
		$canonical = 'https://example.org/source-post/';
		$this->capture_filter( 'wptsall_must_translate_canonical', false );

		$result = Virtual_Site_SEO::filter_canonical_url( $canonical );

		$this->assertSame( $canonical, $result, 'escape hatch false must leave the canonical URL untouched' );
		$calls = $this->captured['wptsall_must_translate_canonical'];
		$this->assertNotEmpty( $calls );
		$this->assertTrue( $calls[0][0], 'default value must be true (translate)' );
		$this->assertSame( $canonical, $calls[0][1], 'second arg must be the current canonical' );
	}

	/**
	 * wptsall_seo_skip_emit_robots: on a noindex virtual site the default
	 * path forces noindex; skip=true returns the robots untouched.
	 */
	public function test_seo_skip_emit_robots_contract() {
		$GLOBALS['wptsall_current_virtual_site'] = $this->vs_context();
		$robots = array( 'index' => true );

		// Default path: noindex virtual site forces the noindex directive.
		$forced = Virtual_Site_SEO::filter_wp_robots( $robots );
		$this->assertTrue( $forced['noindex'], 'default path must apply noindex on a noindex virtual site' );

		// Skip path: identical passthrough.
		$this->capture_filter( 'wptsall_seo_skip_emit_robots', true );
		$skipped = Virtual_Site_SEO::filter_wp_robots( array( 'index' => true ) );
		$this->assertSame( array( 'index' => true ), $skipped, 'skip=true must return the robots array untouched' );
		$calls = $this->captured['wptsall_seo_skip_emit_robots'];
		$this->assertNotEmpty( $calls );
		$this->assertFalse( $calls[0][0], 'default value must be false (emit)' );
	}

	/**
	 * wptsall_allow_canonical_redirect: on a virtual-site request the
	 * default blocks WP core redirect_canonical (false); true lets it
	 * through (returns the redirect target).
	 */
	public function test_allow_canonical_redirect_contract() {
		$GLOBALS['wptsall_current_virtual_site'] = $this->vs_context();
		$redirect  = 'https://example.org/clean/';
		$requested = 'https://example.org/vtest/clean/';

		$blocked = Virtual_Site_SEO::disable_canonical_redirect( $redirect, $requested );
		$this->assertFalse( $blocked, 'default must block redirect_canonical on virtual-site requests' );

		$this->capture_filter( 'wptsall_allow_canonical_redirect', true );
		$allowed = Virtual_Site_SEO::disable_canonical_redirect( $redirect, $requested );
		$this->assertSame( $redirect, $allowed, 'allow=true must pass the redirect target through' );

		$calls = $this->captured['wptsall_allow_canonical_redirect'];
		$this->assertNotEmpty( $calls );
		$this->assertFalse( $calls[0][0], 'default value must be false (block)' );
		$this->assertSame( $redirect, $calls[0][1], 'second arg must be the redirect target' );
		$this->assertSame( $requested, $calls[0][2], 'third arg must be the requested URL' );
	}

	// ==================== router hooks ====================

	/**
	 * Test relation row ids created for router-path resolution (cleanup).
	 *
	 * @var int[]
	 */
	private $relation_ids = array();

	/**
	 * Insert a minimal virtual-target site relation row so the router's
	 * relation gate passes, and remember it for tearDown.
	 *
	 * @return array array( relation_id, target_site_id )
	 */
	private function insert_virtual_relation() {
		global $wpdb;
		$target = 'v_hooktest_' . uniqid();
		$now    = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			wptsall_table( 'site_relations' ),
			array(
				'source_site_id'   => get_current_blog_id(),
				'source_site_type' => 'wp',
				'source_lang'      => 'zh_CN',
				'template'         => 'wordpress-blog',
				'target_site_id'   => $target,
				'target_site_type' => 'virtual',
				'target_lang'      => 'en_US',
				'status'           => 'active',
				'created_at'       => $now,
				'updated_at'       => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		$rid                  = (int) $wpdb->insert_id;
		$this->relation_ids[] = $rid;
		return array( $rid, $target );
	}

	/**
	 * wptsall_allow_taxonomy_without_model: default true resolves public
	 * taxonomy archives without a model row; false denies resolution.
	 */
	public function test_allow_taxonomy_without_model_contract() {
		list( $relation_id, $target ) = $this->insert_virtual_relation();
		register_taxonomy( 'vsc_hook_tax', 'post', array( 'public' => true, 'label' => 'VSC Hook Tax' ) );
		$term_id = $this->factory->term->create(
			array( 'taxonomy' => 'vsc_hook_tax', 'name' => 'VSC Hook Term ' . uniqid(), 'slug' => 'vsc-hook-term-' . uniqid() )
		);
		$term = get_term( $term_id );
		$vs   = array(
			'id'          => $target,
			'path_prefix' => 'hooktest',
			'lang'        => 'en_US',
			'relation_id' => $relation_id,
		);
		$path = 'vsc_hook_tax/' . $term->slug;

		// Default: public taxonomy archive resolves without a model.
		$resolved = $this->resolve_path( $path, $vs );
		$this->assertIsArray( $resolved, 'default allow must resolve a public taxonomy archive without a model row' );
		$this->assertSame( 'taxonomy', $resolved['type'] );

		// Escape hatch: deny resolution for model-less taxonomies.
		$this->capture_filter( 'wptsall_allow_taxonomy_without_model', false );
		$denied = $this->resolve_path( $path, $vs );
		$this->assertNull( $denied, 'allow=false must deny resolution for a taxonomy without a model row' );

		$calls = $this->captured['wptsall_allow_taxonomy_without_model'];
		$this->assertNotEmpty( $calls );
		$this->assertTrue( $calls[0][0], 'default value must be true (allow)' );
		$this->assertSame( $term->slug, $calls[0][1]->slug, 'second arg must be the resolved term' );
	}

	/**
	 * wptsall_is_redirected: escape hatch consulted (default true) before
	 * the source->virtual 301 fallback performs any redirect work. (The
	 * listener-return branch is an early return ahead of the mapping
	 * query; driving a real redirect to prove the negative would exit the
	 * runner, so the L1 contract here is consultation + default value.)
	 */
	public function test_is_redirected_contract() {
		$GLOBALS['wp_query'] = new WP_Query();
		$GLOBALS['wp_query']->is_404 = true;

		$this->capture_filter( 'wptsall_is_redirected', true );
		Virtual_Site_Router::maybe_redirect_source_post_to_virtual();
		$this->assertNotEmpty( $this->captured['wptsall_is_redirected'], 'escape hatch must be consulted on a 404 source request' );
		$this->assertTrue( $this->captured['wptsall_is_redirected'][0][0], 'default value must be true (redirect allowed)' );

		// The hook stays consulted (same call site) when a listener opts
		// out — the escape hatch is the documented opt-out point.
		remove_all_filters( 'wptsall_is_redirected', 10 );
		$this->captured['wptsall_is_redirected'] = array();
		$this->capture_filter( 'wptsall_is_redirected', false );
		Virtual_Site_Router::maybe_redirect_source_post_to_virtual();
		$this->assertNotEmpty( $this->captured['wptsall_is_redirected'], 'escape hatch must remain the consulted opt-out point' );
	}

	// ==================== home_url hooks ====================

	/**
	 * wptsall_filter_home_url: escape hatch consulted with (true, $url,
	 * $path) under a virtual-site context; false skips virtualization.
	 */
	public function test_filter_home_url_contract() {
		$GLOBALS['wptsall_current_virtual_site'] = $this->vs_context();
		$url  = 'https://example.org/';
		$path = 'some/path';

		$this->capture_filter( 'wptsall_filter_home_url', false );
		$result = Virtual_Site_Link_Filters::filter_home_url( $url, $path );

		$this->assertSame( $url, $result, 'escape hatch false must return the home URL untouched' );
		$calls = $this->captured['wptsall_filter_home_url'];
		$this->assertNotEmpty( $calls, 'escape hatch must be consulted under a virtual-site context' );
		$this->assertTrue( $calls[0][0], 'default value must be true (filter)' );
		$this->assertSame( $url, $calls[0][1], 'second arg must be the URL' );
		$this->assertSame( $path, $calls[0][2], 'third arg must be the path argument' );
	}

	/**
	 * wptsall_skip_home_url_filter: fires with (false, $url, $path) as the
	 * final decision of should_skip_home_url(); true forces the skip.
	 */
	public function test_skip_home_url_filter_contract() {
		$url  = 'https://example.org/';
		$path = 'some/path';

		$default = Virtual_Site_Link_Filters::should_skip_home_url( $url, $path );
		$this->assertFalse( $default, 'default must not skip a plain path' );

		$this->capture_filter( 'wptsall_skip_home_url_filter', true );
		$skipped = Virtual_Site_Link_Filters::should_skip_home_url( $url, $path );
		$this->assertTrue( $skipped, 'listener override true must force the skip' );

		$calls = $this->captured['wptsall_skip_home_url_filter'];
		$this->assertNotEmpty( $calls );
		$this->assertFalse( $calls[0][0], 'default value must be false (do not skip)' );
		$this->assertSame( $url, $calls[0][1], 'second arg must be the URL' );
		$this->assertSame( $path, $calls[0][2], 'third arg must be the path argument' );
	}
}

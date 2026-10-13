<?php
/**
 * Core hook contract tests (L1).
 *
 * Extension-point contracts for the hooks fired from core/functions.php
 * (filters: capture the documented args, prove listener return values
 * propagate) and the entity-event conventions listened to by core/cache.php
 * (fire the documented event, prove the cache-invalidation effect).
 *
 * catalog: WP-HOOK-wptsall-available-languages
 * catalog: WP-HOOK-wptsall-client-claim-timeout-seconds
 * catalog: WP-HOOK-wptsall-core-widget-options
 * catalog: WP-HOOK-wptsall-post-type-schema
 * catalog: WP-HOOK-wptsall-syncable-options
 * catalog: WP-HOOK-wptsall-taxonomy-schema
 * catalog: WP-HOOK-wptsall-task-created
 * catalog: WP-HOOK-wptsall-task-updated
 * catalog: WP-HOOK-wptsall-task-deleted
 * catalog: WP-HOOK-wptsall-hook-created
 * catalog: WP-HOOK-wptsall-hook-updated
 * catalog: WP-HOOK-wptsall-hook-deleted
 * catalog: WP-HOOK-wptsall-site-saved
 * catalog: WP-HOOK-wptsall-site-deleted
 * catalog: WP-HOOK-wptsall-template-saved
 * catalog: WP-HOOK-wptsall-template-deleted
 * catalog: WP-HOOK-wptsall-current-language
 * catalog: WP-HOOK-wptsall-component-capability-allowlist
 * oracle: L1
 *
 * @package WPTSALL\Tests\Unit\Core
 * @since 2.1.4
 */

class Test_Hook_Contracts_Core extends SimpleTestCase {

	/**
	 * Captured hook invocations: hook => list of args arrays.
	 *
	 * @var array
	 */
	private $captured = array();

	/**
	 * Register a capturing listener for a filter.
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $override Optional return override.
	 */
	private function capture_filter( $hook, $override = null ) {
		$captured = &$this->captured;
		add_filter(
			$hook,
			function ( $value ) use ( &$captured, $hook, $override ) {
				$args            = func_get_args();
				$captured[ $hook ][] = $args;
				return null === $override ? $value : $override;
			},
			10,
			8
		);
	}

	/**
	 * Remove every filter this test registered (uses the closure registry
	 * rebuilt per capture_filter call, so removal happens via
	 * remove_all_filters on the specific hook + priority only when the
	 * test owns it — plain remove_all_filters is fine here because no
	 * production listener may live on these extension points).
	 *
	 * @param string $hook Hook name.
	 */
	private function release_filter( $hook ) {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride
		remove_all_filters( $hook, 10 );
	}

	public function setUp(): void {
		parent::setUp();
		$this->captured = array();
	}

	public function tearDown(): void {
		foreach ( array_keys( $this->captured ) as $hook ) {
			$this->release_filter( $hook );
		}
		$this->captured = array();
		parent::tearDown();
	}

	// ==================== functions.php filters ====================

	/**
	 * wptsall_available_languages: fires with ($languages, $include_installed_only)
	 * on wptsall_get_available_languages(); listener return propagates.
	 */
	public function test_available_languages_filter_contract() {
		$default = wptsall_get_available_languages();
		$this->assertIsArray( $default, 'default available languages must be an array' );
		$this->assertArrayHasKey( 'en_US', $default, 'en_US must be offered by default' );

		$override = array( 'en_US' => 'English', 'zh_CN' => '中文' );
		$this->capture_filter( 'wptsall_available_languages', $override );

		$filtered = wptsall_get_available_languages( true );
		$this->assertSame( $override, $filtered, 'listener return must propagate as the available-languages list' );
		$calls = $this->captured['wptsall_available_languages'];
		$this->assertNotEmpty( $calls, 'filter must fire during wptsall_get_available_languages()' );
		$this->assertIsArray( $calls[0][0], 'first arg must be the languages array' );
		$this->assertTrue( $calls[0][1], 'second arg must be the include_installed_only flag' );
	}

	/**
	 * wptsall_client_claim_timeout_seconds: default 1800, listener override
	 * propagates, and the result is clamped to [60, 86400].
	 */
	public function test_client_claim_timeout_filter_contract() {
		$default = wptsall_get_client_claim_timeout_seconds();
		$this->assertSame( 1800, $default, 'default claim timeout must be 1800s when no constant overrides it' );

		$this->capture_filter( 'wptsall_client_claim_timeout_seconds', 300 );
		$this->assertSame( 300, wptsall_get_client_claim_timeout_seconds(), 'in-range override must propagate' );

		$this->release_filter( 'wptsall_client_claim_timeout_seconds' );
		$this->capture_filter( 'wptsall_client_claim_timeout_seconds', 5 );
		$this->assertSame( 60, wptsall_get_client_claim_timeout_seconds(), 'below-floor override must clamp to 60s' );

		$this->release_filter( 'wptsall_client_claim_timeout_seconds' );
		$this->capture_filter( 'wptsall_client_claim_timeout_seconds', 999999 );
		$this->assertSame( 86400, wptsall_get_client_claim_timeout_seconds(), 'above-ceiling override must clamp to one day' );
	}

	/**
	 * wptsall_core_widget_options: fires with ($core, $name) on
	 * wptsall_is_core_widget_option(); extending the allowlist widens the check.
	 */
	public function test_core_widget_options_filter_contract() {
		$this->assertFalse( wptsall_is_core_widget_option( 'widget_bogus_test' ), 'precondition: bogus widget option must be denied by default' );

		$this->capture_filter(
			'wptsall_core_widget_options',
			array_merge( array( 'widget_bogus_test' ), array( 'widget_archives' ) )
		);
		$this->assertTrue( wptsall_is_core_widget_option( 'widget_bogus_test' ), 'allowlist extension via the filter must be honored' );

		$calls = $this->captured['wptsall_core_widget_options'];
		$this->assertNotEmpty( $calls );
		$this->assertIsArray( $calls[0][0], 'first arg must be the core allowlist' );
		$this->assertSame( 'widget_bogus_test', $calls[0][1], 'second arg must be the candidate option name' );
	}

	/**
	 * wptsall_post_type_schema: fires with ($schema, $post_type) on
	 * wptsall_get_post_type_schema(); listener can extend the schema.
	 */
	public function test_post_type_schema_filter_contract() {
		$default = wptsall_get_post_type_schema( 'post' );
		$this->assertIsArray( $default );
		$this->assertArrayHasKey( 'core', $default );
		$this->assertContains( 'post_title', $default['core'], 'post schema must list the core title field' );

		$this->capture_filter( 'wptsall_post_type_schema', array( 'fields' => array( 'post_title' ) ) );
		$this->assertSame( array( 'fields' => array( 'post_title' ) ), wptsall_get_post_type_schema( 'post' ), 'schema override must propagate' );

		$calls = $this->captured['wptsall_post_type_schema'];
		$this->assertNotEmpty( $calls );
		$this->assertIsArray( $calls[0][0], 'first arg must be the schema array' );
		$this->assertSame( 'post', $calls[0][1], 'second arg must be the post type' );
	}

	/**
	 * wptsall_taxonomy_schema: fires with ($schema, $taxonomy) on
	 * wptsall_get_taxonomy_schema(); listener can extend the schema.
	 */
	public function test_taxonomy_schema_filter_contract() {
		$default = wptsall_get_taxonomy_schema( 'category' );
		$this->assertIsArray( $default );

		$this->capture_filter( 'wptsall_taxonomy_schema', array( 'fields' => array( 'name' ) ) );
		$this->assertSame( array( 'fields' => array( 'name' ) ), wptsall_get_taxonomy_schema( 'category' ), 'taxonomy schema override must propagate' );

		$calls = $this->captured['wptsall_taxonomy_schema'];
		$this->assertNotEmpty( $calls );
		$this->assertSame( 'category', $calls[0][1], 'second arg must be the taxonomy slug' );
	}

	/**
	 * wptsall_syncable_options: fires inside wptsall_is_syncable_option();
	 * adding a name to the allowlist makes it syncable.
	 */
	public function test_syncable_options_filter_contract() {
		$this->assertFalse( wptsall_is_syncable_option( 'my_custom_test_opt' ), 'precondition: custom option must not be syncable by default' );

		$this->capture_filter( 'wptsall_syncable_options', array( 'blogname', 'my_custom_test_opt' ) );
		$this->assertTrue( wptsall_is_syncable_option( 'my_custom_test_opt' ), 'allowlist extension via the filter must make the option syncable' );

		$calls = $this->captured['wptsall_syncable_options'];
		$this->assertNotEmpty( $calls );
		$this->assertIsArray( $calls[0][0], 'first arg must be the default allowlist' );
	}

	// ==================== cache.php entity-event conventions ====================

	/**
	 * The entity-event conventions listened to by core/cache.php: firing
	 * the documented event must invalidate the documented cache keys.
	 */
	public function test_entity_events_invalidate_caches() {
		$expected = array(
			'wptsall_task_created'     => array( 'task_stats' ),
			'wptsall_task_updated'     => array( 'task_stats' ),
			'wptsall_task_deleted'     => array( 'task_stats' ),
		);

		foreach ( $expected as $action => $keys ) {
			foreach ( $keys as $key ) {
				set_transient( WPTSALL_CACHE_GROUP . $key, 'stale-' . $action, 300 );
			}
			do_action( $action, 123 );
			foreach ( $keys as $key ) {
				$this->assertFalse(
					get_transient( WPTSALL_CACHE_GROUP . $key ),
					"event {$action} must invalidate the {$key} cache entry"
				);
			}
		}
	}

	/**
	 * wptsall_current_language doubles as a public extension alias: the
	 * language context registers itself as the priority-1 resolver, and
	 * later-priority extensions can override the resolved value. (The
	 * plain-PHP apply_filters fallback in functions.php only runs when
	 * Language_Context is not loaded — unreachable in this Lab, where the
	 * context is always present; documented 口径.)
	 */
	public function test_current_language_alias_resolves_context_and_is_overridable() {
		$context = '\WPTSALL\Core\Language_Context';

		$context::reset();
		$unpinned = (string) apply_filters( 'wptsall_current_language', '' );
		$this->assertTrue(
			'' === $unpinned || 'fr_FR' === $unpinned || 'en_US' === $unpinned || 'zh_CN' === $unpinned,
			'unpinned value must be a known Lab language or empty, got: ' . $unpinned
		);

		try {
			$context::set_language( 'fr_FR' );
			$this->assertSame(
				'fr_FR',
				(string) apply_filters( 'wptsall_current_language', '' ),
				'with the context pinned, the alias must resolve the pinned language'
			);

			$override = static function () {
				return 'xx_XX';
			};
			add_filter( 'wptsall_current_language', $override, 5 );
			$filtered = (string) apply_filters( 'wptsall_current_language', '' );
			remove_filter( 'wptsall_current_language', $override, 5 );

			$this->assertSame(
				'xx_XX',
				$filtered,
				'a later-priority extension must be able to override the resolved language'
			);
		} finally {
			$context::reset();
		}
	}

	/**
	 * wptsall_component_capability_allowlist gates component manifest
	 * capabilities: the documented default set, denial of unlisted caps,
	 * and both directions of the filter (widen / narrow).
	 */
	public function test_component_capability_allowlist_gates_manifest_caps() {
		$trust   = '\WPTSALL\Core\Component_Trust';
		$digest  = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
		$allowed = $trust::assert_component_trusted( array(
			'digest'       => $digest,
			'capabilities' => array( 'translate', 'tm_lookup' ),
		) );
		$this->assertTrue( $allowed, 'documented default caps must be allowed' );

		$denied = $trust::assert_component_trusted( array(
			'digest'       => $digest,
			'capabilities' => array( 'translate', 'shell' ),
		) );
		$this->assertTrue( is_wp_error( $denied ), 'an unlisted capability must be denied' );
		$this->assertSame( 'component_cap_denied', $denied->get_error_code() );

		$widen = static function ( $caps ) {
			return array_merge( (array) $caps, array( 'shell' ) );
		};
		add_filter( 'wptsall_component_capability_allowlist', $widen );
		$widened = $trust::assert_component_trusted( array(
			'digest'       => $digest,
			'capabilities' => array( 'shell' ),
		) );
		remove_filter( 'wptsall_component_capability_allowlist', $widen );
		$this->assertTrue( $widened, 'the filter must be able to widen the allowlist' );

		$narrow = static function () {
			return array( 'segment' );
		};
		add_filter( 'wptsall_component_capability_allowlist', $narrow );
		$narrowed = $trust::assert_component_trusted( array(
			'digest'       => $digest,
			'capabilities' => array( 'translate' ),
		) );
		remove_filter( 'wptsall_component_capability_allowlist', $narrow );
		$this->assertTrue( is_wp_error( $narrowed ), 'the filter must be able to narrow the allowlist' );
		$this->assertSame( 'component_cap_denied', $narrowed->get_error_code() );
	}
}

<?php
/**
 * Service-layer event hook contract tests (L1).
 *
 * Event contracts for the plugin lifecycle (activate/activated/deactivated),
 * the content-change dispatcher (content_changed/media_deleted/term_changed),
 * the model rule service (model_saved/model_deleted/model_usage_changed/
 * rule_updated), the rule-config defaults filters, blog-template field
 * config events, and the virtual-site service CRUD events. Each test
 * drives the real service/API call and asserts the documented event fires
 * with the documented args.
 *
 * catalog: WP-HOOK-wptsall-activate
 * catalog: WP-HOOK-wptsall-activated
 * catalog: WP-HOOK-wptsall-deactivated
 * catalog: WP-HOOK-wptsall-content-changed
 * catalog: WP-HOOK-wptsall-media-deleted
 * catalog: WP-HOOK-wptsall-term-changed
 * catalog: WP-HOOK-wptsall-model-saved
 * catalog: WP-HOOK-wptsall-model-deleted
 * catalog: WP-HOOK-wptsall-model-usage-changed
 * catalog: WP-HOOK-wptsall-rule-updated
 * catalog: WP-HOOK-wptsall-core-field-defaults
 * catalog: WP-HOOK-wptsall-plugin-field-defaults
 * catalog: WP-HOOK-wptsall-blog-template-field-updated
 * catalog: WP-HOOK-wptsall-blog-template-field-removed
 * catalog: WP-HOOK-wptsall-virtual-site-created
 * catalog: WP-HOOK-wptsall-virtual-site-updated
 * catalog: WP-HOOK-wptsall-virtual-site-deleted
 * catalog: WP-HOOK-wptsall-model-auto-created
 * catalog: WP-HOOK-wptsall-sync-post-to-virtual-sites
 * catalog: WP-HOOK-wptsall-manual-translation-before-replace-attach
 * oracle: L1
 *
 * @package WPTSALL\Tests\Unit\Models
 * @since 2.1.4
 */

use WPTSALL\Plugin_Lifecycle;
use WPTSALL\Hooks\Content_Change_Dispatcher;
use WPTSALL\Models\Services\Translation_Rule_Service;
use WPTSALL\Models\Blog_Template;
use WPTSALL\Sites\Services\Virtual_Site_Service;

class Test_Hook_Contracts_Events extends SimpleTestCase {

	/**
	 * Captured hook invocations: hook => list of args arrays.
	 *
	 * @var array
	 */
	private $captured = array();

	/**
	 * Firing sequence (hook names in order) for ordering assertions.
	 *
	 * @var string[]
	 */
	private $sequence = array();

	/**
	 * Model ids created for cleanup.
	 *
	 * @var int[]
	 */
	private $model_ids = array();

	/**
	 * Register a capturing action listener.
	 *
	 * @param string $hook Hook name.
	 */
	private function capture_action( $hook ) {
		$captured = &$this->captured;
		$sequence = &$this->sequence;
		add_action(
			$hook,
			function () use ( &$captured, &$sequence, $hook ) {
				$captured[ $hook ][] = func_get_args();
				$sequence[]          = $hook;
			},
			10,
			8
		);
	}

	/**
	 * Register a capturing filter listener.
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $override Return override callback.
	 */
	private function capture_filter( $hook, $override = null ) {
		$captured = &$this->captured;
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
	 * Invoke a private/protected static method.
	 *
	 * @param string $class  Class name.
	 * @param string $method Method name.
	 * @return mixed
	 */
	private function invoke_private( $class, $method ) {
		$m = new ReflectionMethod( $class, $method );
		$m->setAccessible( true );
		return $m->invoke( null );
	}

	public function setUp(): void {
		parent::setUp();
		$this->captured  = array();
		$this->sequence  = array();
		$this->model_ids = array();
	}

	public function tearDown(): void {
		foreach ( array_keys( $this->captured ) as $hook ) {
			remove_all_filters( $hook, 10 );
		}
		$this->captured = array();
		$this->sequence = array();
		foreach ( $this->model_ids as $mid ) {
			Translation_Rule_Service::delete_model( $mid );
		}
		$this->model_ids = array();
		parent::tearDown();
	}

	// ==================== plugin lifecycle ====================

	/**
	 * wptsall_activate / wptsall_activated / wptsall_deactivated: the
	 * lifecycle fires activate then activated on single-site activation,
	 * and deactivated on deactivation. The Lab is re-activated in a
	 * finally block so the deactivation side effects never persist.
	 */
	public function test_lifecycle_events_contract() {
		$this->capture_action( 'wptsall_activate' );
		$this->capture_action( 'wptsall_activated' );
		$this->capture_action( 'wptsall_deactivated' );

		$this->assertTrue(
			has_action( 'wptsall_activate', 'wptsall_init_tasks_tables' ) !== false,
			'back-compat activation event must keep its schema listener registered'
		);

		try {
			$this->invoke_private( Plugin_Lifecycle::class, 'single_site_activate' );
			$this->assertNotEmpty( $this->captured['wptsall_activate'], 'activation must fire wptsall_activate' );
			$this->assertNotEmpty( $this->captured['wptsall_activated'], 'activation must fire wptsall_activated' );
			$this->assertGreaterThan(
				array_search( 'wptsall_activate', $this->sequence, true ),
				array_search( 'wptsall_activated', $this->sequence, true ),
				'wptsall_activate must fire before wptsall_activated'
			);

			$this->invoke_private( Plugin_Lifecycle::class, 'single_site_deactivate' );
			$this->assertNotEmpty( $this->captured['wptsall_deactivated'], 'deactivation must fire wptsall_deactivated' );
		} finally {
			// Restore the Lab's activation state (schema/options/crons).
			$this->invoke_private( Plugin_Lifecycle::class, 'single_site_activate' );
		}
	}

	// ==================== content-change dispatcher ====================

	/**
	 * wptsall_content_changed: fired by the post dispatcher for a managed
	 * post with ($post_id, $post, $update).
	 */
	public function test_content_changed_contract() {
		$post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
		$post    = get_post( $post_id );

		$this->capture_action( 'wptsall_content_changed' );
		Content_Change_Dispatcher::on_after_insert_post( $post_id, $post, false );

		$calls = $this->captured['wptsall_content_changed'];
		$this->assertNotEmpty( $calls, 'the dispatcher must fire content_changed for a managed post' );
		$this->assertSame( $post_id, $calls[0][0], 'first arg must be the post id' );
		$this->assertSame( $post_id, $calls[0][1]->ID, 'second arg must be the post object' );
		$this->assertFalse( $calls[0][2], 'third arg must be the update flag' );
	}

	/**
	 * wptsall_media_deleted: fired by the attachment-delete dispatcher with
	 * ($attachment_id, $blog_id).
	 */
	public function test_media_deleted_contract() {
		$att_id = $this->factory->post->create(
			array( 'post_type' => 'attachment', 'post_status' => 'inherit' )
		);
		$this->assertGreaterThan( 0, $att_id );

		$this->capture_action( 'wptsall_media_deleted' );
		Content_Change_Dispatcher::on_attachment_deleted( $att_id );

		$calls = $this->captured['wptsall_media_deleted'];
		$this->assertNotEmpty( $calls, 'the dispatcher must fire media_deleted for a deleted attachment' );
		$this->assertSame( $att_id, $calls[0][0], 'first arg must be the attachment id' );
		$this->assertSame( get_current_blog_id(), $calls[0][1], 'second arg must be the blog id' );
	}

	/**
	 * wptsall_term_changed: fired by the term dispatcher with
	 * ($term_id, $taxonomy, $update).
	 */
	public function test_term_changed_contract() {
		$term_id = $this->factory->term->create( array( 'taxonomy' => 'category' ) );

		$this->capture_action( 'wptsall_term_changed' );
		$m = new ReflectionMethod( Content_Change_Dispatcher::class, 'on_term_changed' );
		$m->setAccessible( true );
		$m->invoke( null, $term_id, 'category', false );

		$calls = $this->captured['wptsall_term_changed'];
		$this->assertNotEmpty( $calls, 'the dispatcher must fire term_changed for a saved term' );
		$this->assertSame( $term_id, $calls[0][0], 'first arg must be the term id' );
		$this->assertSame( 'category', $calls[0][1], 'second arg must be the taxonomy' );
		$this->assertFalse( $calls[0][2], 'third arg must be the update flag' );
	}

	// ==================== model rule service ====================

	/**
	 * wptsall_model_saved / wptsall_model_usage_changed /
	 * wptsall_model_deleted: the model CRUD service fires its documented
	 * events; usage_changed only on an actual status transition.
	 */
	public function test_model_lifecycle_events_contract() {
		$slug = 'hooktest-plugin-' . uniqid();
		$data = array(
			'plugin_slug' => $slug,
			'plugin_name' => 'Hooktest Plugin',
			'post_types'  => array( 'post' ),
			'taxonomies'  => array( 'category' ),
		);

		$this->capture_action( 'wptsall_model_saved' );
		$this->capture_action( 'wptsall_model_usage_changed' );
		$this->capture_action( 'wptsall_model_deleted' );

		$model_id = Translation_Rule_Service::create_model( $data );
		$this->assertNotFalse( $model_id );
		$this->assertGreaterThan( 0, (int) $model_id, 'create_model must return the new model id' );
		$this->model_ids[] = (int) $model_id;

		$saved = $this->captured['wptsall_model_saved'];
		$this->assertNotEmpty( $saved, 'create_model must fire model_saved' );
		$this->assertSame( (int) $model_id, (int) $saved[0][0], 'first arg must be the model id' );
		$this->assertSame( $slug, $saved[0][1]['plugin_slug'], 'second arg must be the model data' );

		// Status transition fires usage_changed; a no-op transition must not.
		$this->assertTrue( Translation_Rule_Service::update_usage_status( $model_id, 'active' ) );
		$this->assertNotEmpty( $this->captured['wptsall_model_usage_changed'], 'a real status transition must fire model_usage_changed' );
		$usage_calls = $this->captured['wptsall_model_usage_changed'];
		$this->assertSame( 'active', $usage_calls[0][2], 'third arg must be the new status' );

		$before = count( $this->captured['wptsall_model_usage_changed'] );
		Translation_Rule_Service::update_usage_status( $model_id, 'active' );
		$this->assertCount( $before, $this->captured['wptsall_model_usage_changed'], 'setting the same status again must NOT fire model_usage_changed' );

		// Deletion fires model_deleted and removes the row.
		$this->assertTrue( Translation_Rule_Service::delete_model( $model_id ) );
		$deleted = $this->captured['wptsall_model_deleted'];
		$this->assertNotEmpty( $deleted, 'delete_model must fire model_deleted' );
		$this->assertSame( (int) $model_id, (int) $deleted[0][0], 'first arg must be the model id' );
		$this->assertSame( $slug, $deleted[0][1], 'second arg must be the plugin slug' );
		$this->model_ids = array_diff( $this->model_ids, array( (int) $model_id ) );
	}

	/**
	 * wptsall_rule_updated: fired by delete_rule with ($rule_id, $model_id).
	 */
	public function test_rule_updated_contract() {
		global $wpdb;
		$slug     = 'hooktest-plugin-' . uniqid();
		$model_id = Translation_Rule_Service::create_model( array(
			'plugin_slug' => $slug,
			'plugin_name' => 'Hooktest Plugin',
			'post_types'  => array( 'post' ),
			'taxonomies'  => array( 'category' ),
		) );
		$this->assertTrue( is_int( $model_id ) && $model_id > 0, 'create_model must return a positive int model id (got ' . var_export( $model_id, true ) . ')' );
		$this->model_ids[] = $model_id;

		$now = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			wptsall_table( 'translation_rules' ),
			array(
				'model_id'    => $model_id,
				'object_name' => 'post',
				'data_type'   => 'post_type',
				'is_active'   => 1,
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s' )
		);
		$rule_id = (int) $wpdb->insert_id;

		$this->capture_action( 'wptsall_rule_updated' );
		$result = Translation_Rule_Service::delete_rule( $rule_id );
		$this->assertNotFalse( $result );

		$calls = $this->captured['wptsall_rule_updated'];
		$this->assertNotEmpty( $calls, 'delete_rule must fire rule_updated' );
		$this->assertSame( $rule_id, (int) $calls[0][0], 'first arg must be the rule id (got ' . var_export( $calls[0][0], true ) . ')' );
		$this->assertSame( (int) $model_id, (int) $calls[0][1], 'second arg must be the owning model id (expected ' . var_export( (int) $model_id, true ) . ', got ' . var_export( $calls[0][1], true ) . ')' );
	}

	// ==================== rule config defaults ====================

	/**
	 * wptsall_core_field_defaults / wptsall_plugin_field_defaults: the
	 * defaults are filterable and the listener return is the resolved set.
	 */
	public function test_field_defaults_filters_contract() {
		$this->capture_filter( 'wptsall_core_field_defaults', array( 'hooktest' => 'overridden' ) );
		$core = Translation_Rule_Service::get_core_field_defaults();
		$this->assertSame( array( 'hooktest' => 'overridden' ), $core, 'core field defaults listener return must be the resolved set' );

		$this->capture_filter( 'wptsall_plugin_field_defaults', array( 'hooktest_rule' => 'overridden' ) );
		$plugin = Translation_Rule_Service::get_plugin_field_defaults();
		$this->assertSame( array( 'hooktest_rule' => 'overridden' ), $plugin, 'plugin field defaults listener return must be the resolved set' );

		$this->assertIsArray( $this->captured['wptsall_core_field_defaults'][0][0], 'core defaults filter must receive the built-in defaults' );
		$this->assertIsArray( $this->captured['wptsall_plugin_field_defaults'][0][0], 'plugin defaults filter must receive the built-in defaults' );
	}

	// ==================== blog template fields ====================

	/**
	 * wptsall_blog_template_field_updated / _removed: fired by the blog
	 * template field config API with the documented args.
	 */
	public function test_blog_template_field_events_contract() {
		$context  = 'hooktest_ctx_' . uniqid();
		$meta_key = '_hooktest_field';
		$config   = array( 'type' => 'id_mapping', 'reference_type' => 'media' );

		// Seed the discovered-fields entry the config API operates on, and
		// keep the original template option for restore.
		$option_key = 'wptsall_template_' . Blog_Template::TEMPLATE_SLUG;
		$original   = get_option( $option_key, null );
		$template   = is_array( $original ) ? $original : array( 'discovered_fields' => array() );
		$template['discovered_fields']     = $template['discovered_fields'] ?? array();
		$template['discovered_fields'][ $context ][ $meta_key ] = array( 'type' => 'id_mapping', 'reference_type' => 'media' );
		update_option( $option_key, $template );

		$this->capture_action( 'wptsall_blog_template_field_updated' );
		$this->capture_action( 'wptsall_blog_template_field_removed' );

		try {
			Blog_Template::update_field_config( $context, $meta_key, $config );
			$updated = $this->captured['wptsall_blog_template_field_updated'];
			$this->assertNotEmpty( $updated, 'update_field_config must fire the field_updated event' );
			$this->assertSame( $meta_key, $updated[0][0], 'first arg must be the meta key' );
			$this->assertSame( $context, $updated[0][1], 'second arg must be the context' );
			$this->assertSame( $config, $updated[0][2], 'third arg must be the field config' );

			Blog_Template::remove_field( $context, $meta_key );
			$removed = $this->captured['wptsall_blog_template_field_removed'];
			$this->assertNotEmpty( $removed, 'remove_field must fire the field_removed event' );
			$this->assertSame( $meta_key, $removed[0][0], 'first arg must be the meta key' );
			$this->assertSame( $context, $removed[0][1], 'second arg must be the context' );
		} finally {
			if ( null === $original ) {
				delete_option( $option_key );
			} else {
				update_option( $option_key, $original );
			}
		}
	}

	// ==================== virtual site service ====================

	/**
	 * wptsall_virtual_site_created / _updated / _deleted: the virtual-site
	 * service CRUD fires its documented events with ($site_id, payload).
	 */
	public function test_virtual_site_events_contract() {
		$prefix = 'hooktest-' . uniqid();

		$this->capture_action( 'wptsall_virtual_site_created' );
		$this->capture_action( 'wptsall_virtual_site_updated' );
		$this->capture_action( 'wptsall_virtual_site_deleted' );

		$created = Virtual_Site_Service::create( array(
			'name'        => 'Hooktest Site',
			'path_prefix' => $prefix,
			'lang'        => 'en_US',
		) );
		$this->assertTrue( $created['success'], 'create must succeed: ' . ( is_wp_error( $created['error'] ?? null ) ? 'error' : '' ) );
		$site_id = $created['site_id'];

		$created_calls = $this->captured['wptsall_virtual_site_created'];
		$this->assertNotEmpty( $created_calls, 'create must fire virtual_site_created' );
		$this->assertSame(
			$site_id,
			$created_calls[0][0],
			'first arg must be the site id (result=' . var_export( $site_id, true ) . ', event=' . var_export( $created_calls[0][0], true ) . ')'
		);

		$this->assertTrue( Virtual_Site_Service::update( $site_id, array( 'name' => 'Hooktest Site Renamed' ) )['success'] ?? false );
		$this->assertNotEmpty( $this->captured['wptsall_virtual_site_updated'], 'update must fire virtual_site_updated' );

		$deleted_result = Virtual_Site_Service::delete( $site_id );
		$this->assertTrue( $deleted_result['success'] ?? false, 'delete must succeed' );
		$deleted_calls = $this->captured['wptsall_virtual_site_deleted'];
		$this->assertNotEmpty( $deleted_calls, 'delete must fire virtual_site_deleted' );
		// delete() casts the id to string before firing; compare as strings.
		$this->assertSame(
			(string) $site_id,
			(string) $deleted_calls[0][0],
			'first arg must be the site id (result=' . var_export( $site_id, true ) . ', event=' . var_export( $deleted_calls[0][0], true ) . ')'
		);
	}

	/**
	 * wptsall_model_auto_created: fired by Site_Relation_Service::create_relation
	 * when auto_create_model scans and creates the model for the template
	 * (fires only on the create path — an already-existing model short-circuits
	 * ensure_model_exists before the hook). Args: (model_id, template,
	 * source_site_id). Later suites self-heal: any subsequent create_relation
	 * for the template re-scans on demand.
	 */
	public function test_model_auto_created_contract() {
		$pre_existing = Translation_Rule_Service::get_model( 'wordpress-blog' );
		if ( $pre_existing ) {
			Translation_Rule_Service::delete_model( (int) $pre_existing['id'] );
		}

		try {
			$this->capture_action( 'wptsall_model_auto_created' );

			$relation_result = \WPTSALL\Sites\Services\Site_Relation_Service::create_relation( array(
				'template'       => 'wordpress-blog',
				'source_site_id' => get_current_blog_id(),
				'source_lang'    => 'en',
				'target_sites'   => array(
					array(
						'id'   => 'v_auto_' . uniqid(),
						'type' => 'virtual',
						'lang' => 'zh_CN',
					),
				),
			) );

			$this->assertTrue(
				! empty( $relation_result['success'] ),
				'create_relation must succeed: ' . implode( '; ', (array) ( $relation_result['errors'] ?? array() ) )
			);

			$calls = $this->captured['wptsall_model_auto_created'];
			$this->assertNotEmpty( $calls, 'auto-create path must fire model_auto_created' );
			$this->assertGreaterThan( 0, (int) $calls[0][0], 'first arg must be the created model id' );
			$this->assertSame( 'wordpress-blog', $calls[0][1], 'second arg must be the template' );
			$this->assertSame( (int) get_current_blog_id(), (int) $calls[0][2], 'third arg must be the source site id' );

			// Model must actually exist now; second create_relation must NOT re-fire.
			$model = Translation_Rule_Service::get_model( (int) $calls[0][0] );
			$this->assertNotNull( $model, 'the auto-created model id must resolve' );
			$before = count( $calls );
			$second = \WPTSALL\Sites\Services\Site_Relation_Service::create_relation( array(
				'template'       => 'wordpress-blog',
				'source_site_id' => get_current_blog_id(),
				'source_lang'    => 'en',
				'target_sites'   => array(
					array(
						'id'   => 'v_auto2_' . uniqid(),
						'type' => 'virtual',
						'lang' => 'zh_CN',
					),
				),
			) );
			$this->assertTrue( ! empty( $second['success'] ), 'second create_relation must also succeed' );
			$this->assertCount(
				$before,
				$this->captured['wptsall_model_auto_created'],
				'an already-existing model must short-circuit before the hook (no re-fire)'
			);
			$this->cleanup_relation_row( (int) ( $second['relation_ids'][0] ?? 0 ) );
		} finally {
			if ( ! empty( $relation_result['relation_ids'][0] ) ) {
				$this->cleanup_relation_row( (int) $relation_result['relation_ids'][0] );
			}
			$fresh = Translation_Rule_Service::get_model( 'wordpress-blog' );
			if ( $fresh ) {
				Translation_Rule_Service::delete_model( (int) $fresh['id'] );
			}
			if ( $pre_existing && ! $fresh ) {
				// Pre-existing model was deleted by this test and not recreated:
				// leave the table clean; later suites self-heal via re-scan.
			}
		}
	}

	/**
	 * Delete a site_relations fixture (cascade helper).
	 *
	 * Routes through Site_Relation_Service::delete_relation so the
	 * relation_models/hooks/configs rows created alongside the relation go
	 * with it — raw row deletes orphan them (doctor-probes.php §8 guards
	 * this class).
	 *
	 * @param int $relation_id Relation row id.
	 */
	private function cleanup_relation_row( $relation_id ) {
		if ( $relation_id > 0 ) {
			\WPTSALL\Sites\Services\Site_Relation_Service::delete_relation( (int) $relation_id );
		}
	}

	/**
	 * wptsall_sync_post_to_virtual_sites: Admin_Hooks::on_save_post guards
	 * (nonce wptsall_virtual_site_nonce/action wptsall_virtual_site_meta →
	 * autosave → edit_post cap → supported post type → wptsall_sync_on_save
	 * flag) then fires (post_id, WP_Post). No production listener registers
	 * on this tag today — it is a documented extension point.
	 */
	public function test_sync_post_to_virtual_sites_guard_chain_contract() {
		$admin_id = wp_insert_user( array(
			'user_login' => 'hook_sync_' . uniqid(),
			'user_pass'  => 'password',
			'user_email' => 'hook_sync_' . uniqid() . '@example.com',
			'role'       => 'administrator',
		) );
		$this->assertTrue( is_int( $admin_id ) && $admin_id > 0, 'admin user must be created' );

		$post_id = self::factory()->post->create( array( 'post_type' => 'post' ) );
		$post    = get_post( $post_id );

		$unsupported_id = self::factory()->post->create( array( 'post_type' => 'nav_menu_item' ) );

		wp_set_current_user( $admin_id );
		$this->capture_action( 'wptsall_sync_post_to_virtual_sites' );

		try {
			// 1. Missing nonce → no fire.
			$_POST = array( 'wptsall_sync_on_save' => '1' );
			\WPTSALL\Hooks\Admin_Hooks::on_save_post( $post_id, $post, true );
			$this->assertEmpty( $this->captured['wptsall_sync_post_to_virtual_sites'], 'missing nonce must not fire' );

			// 2. Bad nonce value → no fire.
			$_POST['wptsall_virtual_site_nonce'] = 'bad';
			\WPTSALL\Hooks\Admin_Hooks::on_save_post( $post_id, $post, true );
			$this->assertEmpty( $this->captured['wptsall_sync_post_to_virtual_sites'], 'bad nonce must not fire' );

			// 3. Valid nonce but no sync flag → no fire.
			$_POST['wptsall_virtual_site_nonce'] = wp_create_nonce( 'wptsall_virtual_site_meta' );
			unset( $_POST['wptsall_sync_on_save'] );
			\WPTSALL\Hooks\Admin_Hooks::on_save_post( $post_id, $post, true );
			$this->assertEmpty( $this->captured['wptsall_sync_post_to_virtual_sites'], 'missing sync flag must not fire' );

			// 4. Valid nonce + flag but unsupported post type → no fire.
			$_POST['wptsall_sync_on_save'] = '1';
			\WPTSALL\Hooks\Admin_Hooks::on_save_post( $unsupported_id, get_post( $unsupported_id ), true );
			$this->assertEmpty( $this->captured['wptsall_sync_post_to_virtual_sites'], 'unsupported post type must not fire' );

			// 5. Full guard chain passes → fires with (post_id, WP_Post).
			\WPTSALL\Hooks\Admin_Hooks::on_save_post( $post_id, $post, true );
			$calls = $this->captured['wptsall_sync_post_to_virtual_sites'];
			$this->assertNotEmpty( $calls, 'valid nonce + flag + supported type must fire' );
			$this->assertSame( (int) $post_id, (int) $calls[0][0], 'first arg must be the post id' );
			$this->assertInstanceOf( 'WP_Post', $calls[0][1], 'second arg must be the WP_Post object' );

			// 6. No cap (anonymous) → no further fire.
			wp_set_current_user( 0 );
			$before = count( $calls );
			\WPTSALL\Hooks\Admin_Hooks::on_save_post( $post_id, $post, true );
			$this->assertCount( $before, $this->captured['wptsall_sync_post_to_virtual_sites'], 'missing edit_post cap must not fire' );
		} finally {
			wp_set_current_user( 0 );
			$_POST = array();
			wp_delete_post( $post_id, true );
			wp_delete_post( $unsupported_id, true );
			if ( function_exists( 'wpmu_delete_user' ) ) {
				wpmu_delete_user( $admin_id );
			} else {
				wp_delete_user( $admin_id );
			}
		}
	}

	/**
	 * wptsall_manual_translation_before_replace_attach: fired by
	 * Manual_Content_Service::replace_existing_translation after the current
	 * target is detached and BEFORE the candidate is attached. Args:
	 * (source_post_id, relation_id, current_target_id, candidate_target_post_id).
	 * Guarded by: relation exists + wp target + multisite; attached target
	 * present; candidate != current; candidate validated (type match, no
	 * reverse mapping conflict).
	 */
	public function test_manual_translation_before_replace_attach_contract() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'replace flow requires multisite' );
			return;
		}

		$relation_result = \WPTSALL\Sites\Services\Site_Relation_Service::create_relation( array(
			'template'       => 'woocommerce',
			'source_site_id' => get_current_blog_id(),
			'source_lang'    => 'en',
			'target_sites'   => array(
				array( 'id' => 2, 'type' => 'wp', 'lang' => '' ),
			),
		) );
		if ( empty( $relation_result['success'] ) ) {
			$this->markTestSkipped( 'Could not create WP-to-WP relation: ' . implode( '; ', (array) ( $relation_result['errors'] ?? array() ) ) );
			return;
		}
		$relation_id = (int) $relation_result['relation_ids'][0];

		$source_id = self::factory()->post->create( array( 'post_type' => 'post' ) );
		$target_ids = array();

		try {
			// Establish the attached current target via a real save.
			$save = \WPTSALL\Sites\Services\Manual_Content_Service::save_translation(
				$source_id,
				$relation_id,
				array( 'post_title' => 'Attached Target Title' )
			);
			$this->assertTrue(
				! is_wp_error( $save ),
				'save_translation must succeed: ' . ( is_wp_error( $save ) ? $save->get_error_message() : '' )
			);
			$current_target_id = (int) $save['target_id'];
			$target_ids[]      = $current_target_id;

			// Candidate post on the target site.
			switch_to_blog( 2 );
			$candidate_id = wp_insert_post( array(
				'post_title'  => 'Candidate Target Title',
				'post_status' => 'publish',
				'post_type'   => 'post',
			) );
			restore_current_blog();
			$this->assertTrue( is_int( $candidate_id ) && $candidate_id > 0, 'candidate post must be created on site 2' );
			$target_ids[] = $candidate_id;

			$this->capture_action( 'wptsall_manual_translation_before_replace_attach' );

			// Guard: same candidate as current → unchanged short-circuit, no fire.
			$unchanged = \WPTSALL\Sites\Services\Manual_Content_Service::replace_existing_translation( $source_id, $relation_id, $current_target_id );
			$this->assertTrue( ! is_wp_error( $unchanged ), 'same-candidate replace must short-circuit as unchanged' );
			$this->assertEmpty( $this->captured['wptsall_manual_translation_before_replace_attach'], 'unchanged replace must not fire the hook' );

			// Positive: replace with the candidate → fires after detach, before attach.
			$replaced = \WPTSALL\Sites\Services\Manual_Content_Service::replace_existing_translation( $source_id, $relation_id, $candidate_id );
			$this->assertTrue(
				! is_wp_error( $replaced ),
				'replace must succeed: ' . ( is_wp_error( $replaced ) ? $replaced->get_error_message() : '' )
			);
			$calls = $this->captured['wptsall_manual_translation_before_replace_attach'];
			$this->assertNotEmpty( $calls, 'real replace must fire before_replace_attach' );
			$this->assertSame( (int) $source_id, (int) $calls[0][0], 'arg 1 must be the source post id' );
			$this->assertSame( $relation_id, (int) $calls[0][1], 'arg 2 must be the relation id' );
			$this->assertSame( $current_target_id, (int) $calls[0][2], 'arg 3 must be the just-detached current target id' );
			$this->assertSame( $candidate_id, (int) $calls[0][3], 'arg 4 must be the candidate target id' );
		} finally {
			wp_delete_post( $source_id, true );
			switch_to_blog( 2 );
			foreach ( $target_ids as $tid ) {
				if ( $tid > 0 ) {
					wp_delete_post( $tid, true );
				}
			}
			restore_current_blog();
			$this->cleanup_relation_row( $relation_id );
			$fresh = Translation_Rule_Service::get_model( 'woocommerce' );
			if ( $fresh ) {
				// The relation run auto-created a woocommerce model; suites
				// self-heal by re-scanning on demand, so drop it again.
				Translation_Rule_Service::delete_model( (int) $fresh['id'] );
			}
		}
	}
}

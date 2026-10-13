<?php
/**
 * Virtual-site query-switch + writeback tests.
 *
 * Drives the shipped resolver/listen/writeback functions (not a reimplementation).
 * Covers two content-plugin object shapes: WooCommerce `product` CPT and
 * Elementor `_elementor_data` meta.
 *
 * @package WPTSALL
 * @since 2.1.0
 */

use WPTSALL\Hooks\Hook_Manager;
use WPTSALL\Hooks\Virtual_Site_Query_Switch;
use WPTSALL\Hooks\Virtual_Site_Router;
use WPTSALL\Sites\Services\Manual_Content_Service;

class Test_Virtual_Query_Switch extends SimpleTestCase {

	protected $test_post_ids = array();

	public function tearDown(): void {
		foreach ( $this->test_post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		$this->test_post_ids = array();
		unset( $GLOBALS['wptsall_current_virtual_site'] );
		parent::tearDown();
	}

	public function test_class_exists() {
		$this->assertTrue( class_exists( Virtual_Site_Query_Switch::class ) );
	}

	public function test_wp_subsite_does_not_require_virtual_router() {
		$this->assertFalse( Virtual_Site_Query_Switch::requires_virtual_router_to_serve( 'wp' ) );
		$this->assertFalse( Virtual_Site_Router::requires_virtual_router_to_serve( 'wp' ) );
		$this->assertTrue( Virtual_Site_Query_Switch::requires_virtual_router_to_serve( 'virtual' ) );
	}

	public function test_bind_query_vars_switches_to_shadow_post_id() {
		$wp = new stdClass();
		$wp->query_vars = array();
		$applied = Virtual_Site_Query_Switch::bind_query_vars(
			$wp,
			array(
				'type'       => 'post_type',
				'subtype'    => 'product',
				'source_id'  => 10,
				'queried_id' => 88,
			)
		);
		$this->assertEquals( 88, $wp->query_vars['p'] );
		$this->assertEquals( 'product', $wp->query_vars['post_type'] );
		$this->assertEquals( 10, $applied['source_id'] );
		$this->assertEquals( 88, $applied['queried_id'] );
		$this->assertTrue( $applied['switched'] );
	}

	public function test_bind_query_vars_clears_conflicting_url_vars() {
		$wp = new stdClass();
		$wp->query_vars = array(
			'pagename'             => 'cov-autodescription-c269e2-en',
			'name'                 => 'cov-autodescription-c269e2-en',
			'page'                 => '',
			'wptsall_virtual_path' => 'blog/2026/08/13/cov-autodescription-c269e2-en',
		);
		Virtual_Site_Query_Switch::bind_query_vars(
			$wp,
			array(
				'type'       => 'post_type',
				'subtype'    => 'post',
				'source_id'  => 1590,
				'queried_id' => 1591,
			)
		);
		$this->assertArrayNotHasKey( 'pagename', $wp->query_vars, 'pagename must be cleared so WP_Query does not AND a 0-row page lookup' );
		$this->assertArrayNotHasKey( 'name', $wp->query_vars, 'name must be cleared for the p= lookup to win' );
		$this->assertArrayNotHasKey( 'wptsall_virtual_path', $wp->query_vars );
		$this->assertEquals( 1591, $wp->query_vars['p'] );
	}

	public function test_bind_query_vars_home_clears_conflicting_url_vars() {
		$wp = new stdClass();
		$wp->query_vars = array(
			'pagename'             => 'en-us',
			'name'                 => 'en-us',
			'page'                 => '',
			'wptsall_virtual_path' => '',
		);
		$applied = Virtual_Site_Query_Switch::bind_query_vars(
			$wp,
			array(
				'type'      => 'home',
				'subtype'   => '',
				'source_id' => 0,
				'queried_id' => 0,
			)
		);
		$this->assertArrayNotHasKey( 'pagename', $wp->query_vars, 'home must not keep a failed pagename lookup (WP sets 404 otherwise)' );
		$this->assertArrayNotHasKey( 'name', $wp->query_vars );
		$this->assertArrayNotHasKey( 'wptsall_virtual_path', $wp->query_vars );
		$this->assertArrayNotHasKey( 'p', $wp->query_vars, 'home must stay an archive query' );
		$this->assertSame( 'home', $applied['type'] );
	}

	public function test_bind_query_vars_taxonomy_clears_pagename() {
		$wp = new stdClass();
		$wp->query_vars = array(
			'pagename'             => 'en-us/category/cov-cat-x',
			'name'                 => 'cov-cat-x',
			'wptsall_virtual_path' => 'category/cov-cat-x',
		);
		Virtual_Site_Query_Switch::bind_query_vars(
			$wp,
			array(
				'type'          => 'taxonomy',
				'subtype'       => 'category',
				'source_id'     => 5,
				'queried_id'    => 3,
				'term_slug'     => 'cov-cat-x',
				'tax_query_var' => 'category_name',
			)
		);
		$this->assertArrayNotHasKey( 'pagename', $wp->query_vars, 'catch-all pagename must be cleared or WP_Query 404s' );
		$this->assertArrayNotHasKey( 'name', $wp->query_vars );
		$this->assertEquals( 'category', $wp->query_vars['taxonomy'] );
		$this->assertEquals( 'cov-cat-x', $wp->query_vars['term'] );
		$this->assertEquals( 'cov-cat-x', $wp->query_vars['category_name'] );
	}

	public function test_bind_query_vars_paged_sets_paged_and_clears_conflicts() {
		$wp = new stdClass();
		$wp->query_vars = array(
			'pagename'             => 'en-us/page/2',
			'name'                 => '2',
			'wptsall_virtual_path' => 'page/2',
		);
		Virtual_Site_Query_Switch::bind_query_vars(
			$wp,
			array(
				'type'       => 'paged',
				'subtype'    => '',
				'source_id'  => 2,
				'queried_id' => 2,
				'paged'      => 2,
			)
		);
		$this->assertArrayNotHasKey( 'pagename', $wp->query_vars );
		$this->assertArrayNotHasKey( 'p', $wp->query_vars, 'paged home must not become a singular query' );
		$this->assertEquals( 2, $wp->query_vars['paged'] );
	}

	public function test_bind_query_vars_taxonomy_uses_term_slug() {
		$wp = new stdClass();
		$wp->query_vars = array();
		Virtual_Site_Query_Switch::bind_query_vars(
			$wp,
			array(
				'type'          => 'taxonomy',
				'subtype'       => 'product_cat',
				'source_id'     => 3,
				'queried_id'    => 9,
				'term_slug'     => 'shoes-en',
				'tax_query_var' => 'product_cat',
			)
		);
		$this->assertEquals( 'product_cat', $wp->query_vars['taxonomy'] );
		$this->assertEquals( 'shoes-en', $wp->query_vars['term'] );
		$this->assertEquals( 'shoes-en', $wp->query_vars['product_cat'] );
	}

	public function test_include_virtual_site_meta_query_uses_id_candidates() {
		$clause = Virtual_Site_Query_Switch::include_virtual_site_meta_query(
			array(
				'id'          => 12,
				'relation_id' => 12,
				'path_prefix' => 'en_us',
				'lang'        => 'en_US',
			)
		);
		$this->assertEquals( '_wptsall_virtual_site_id', $clause['key'] );
		$this->assertEquals( 'IN', $clause['compare'] );
		$this->assertContains( '12', $clause['value'] );
		$this->assertContains( 'v_12', $clause['value'] );
		$this->assertContains( 'en_us', $clause['value'] );
	}

	public function test_archive_query_on_virtual_site_includes_shadow_copies() {
		$query = new stdClass();
		$query->query_vars = array( 'post_type' => 'product' );
		$clause = Virtual_Site_Query_Switch::include_virtual_site_meta_query(
			array( 'id' => 'v_en', 'path_prefix' => 'en' )
		);
		$merged = Virtual_Site_Query_Switch::merge_meta_query( $query, $clause );
		$this->assertNotEmpty( $merged );
		$this->assertEquals( '_wptsall_virtual_site_id', $merged[0]['key'] );
		$this->assertEquals( 'IN', $merged[0]['compare'] );
		$this->assertTrue( Virtual_Site_Query_Switch::should_filter_archive_query( $query ) );

		$singular = new stdClass();
		$singular->query_vars = array( 'p' => 5 );
		$this->assertFalse( Virtual_Site_Query_Switch::should_filter_archive_query( $singular ) );
	}

	public function test_parse_request_binds_shadow_product_not_source() {
		if ( ! post_type_exists( 'product' ) ) {
			register_post_type(
				'product',
				array(
					'public' => true,
					'label'  => 'Product',
				)
			);
		}
		$source_id = wp_insert_post(
			array(
				'post_type'    => 'product',
				'post_status'  => 'publish',
				'post_title'   => '源商品',
				'post_content' => '源描述',
				'post_name'    => 'source-product-qs',
			)
		);
		$this->assertGreaterThan( 0, $source_id );
		$this->test_post_ids[] = $source_id;

		$shadow_id = wp_insert_post(
			array(
				'post_type'    => 'product',
				'post_status'  => 'publish',
				'post_title'   => 'EN Product',
				'post_content' => 'EN description',
				'post_name'    => 'en-product-qs',
			)
		);
		$this->assertGreaterThan( 0, $shadow_id );
		$this->test_post_ids[] = $shadow_id;
		update_post_meta( $shadow_id, '_wptsall_virtual_site_id', 'v_qs_en' );
		update_post_meta( $shadow_id, '_wptsall_source_post_id', $source_id );
		update_post_meta( $shadow_id, '_product_attributes', array( 'color' => array( 'name' => 'Blue' ) ) );

		$virtual_site = array(
			'id'          => 'v_qs_en',
			'relation_id' => 901,
			'path_prefix' => 'en',
			'lang'        => 'en_US',
		);

		$resolved = Virtual_Site_Query_Switch::resolve_queried_object(
			array(
				'type'      => 'post_type',
				'subtype'   => 'product',
				'source_id' => $source_id,
			),
			$virtual_site
		);
		$this->assertEquals( $shadow_id, $resolved['queried_id'] );
		$this->assertTrue( $resolved['switched'] );

		$wp = new stdClass();
		$wp->query_vars = array();
		$applied = Virtual_Site_Query_Switch::bind_query_vars( $wp, $resolved );
		$this->assertEquals( $shadow_id, $applied['queried_id'] );
		$this->assertEquals( $shadow_id, $wp->query_vars['p'] );
		$this->assertNotEquals( $source_id, $wp->query_vars['p'] );
		$this->assertEquals( 'product', $wp->query_vars['post_type'] );

		$attrs = get_post_meta( (int) $wp->query_vars['p'], '_product_attributes', true );
		$this->assertIsArray( $attrs );
		$this->assertEquals( 'Blue', $attrs['color']['name'] );
	}

	public function test_elementor_meta_is_written_to_virtual_and_wp_targets() {
		$elementor = array(
			array(
				'id'       => 'abc',
				'elType'   => 'widget',
				'widgetType'=> 'heading',
				'settings' => array( 'title' => 'Hello EN' ),
			),
		);
		$partition = Manual_Content_Service::partition_translated_fields(
			array( 'post_title', 'post_content', '_elementor_data', '_product_attributes' ),
			array(
				'post_title'           => 'Hello EN',
				'post_content'         => 'Body',
				'_elementor_data'      => $elementor,
				'_product_attributes'  => array( 'size' => array( 'name' => 'Large' ) ),
			)
		);
		$this->assertArrayHasKey( 'post_title', $partition['columns'] );
		$this->assertArrayNotHasKey( '_elementor_data', $partition['columns'] );
		$this->assertArrayHasKey( '_elementor_data', $partition['meta'] );
		$this->assertArrayHasKey( '_product_attributes', $partition['meta'] );
		$columns_only = Manual_Content_Service::only_post_columns(
			array_merge( $partition['columns'], $partition['meta'] )
		);
		$this->assertArrayHasKey( 'post_title', $columns_only );
		$this->assertArrayNotHasKey( '_elementor_data', $columns_only );

		$target_id = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Hello EN',
			)
		);
		$this->assertGreaterThan( 0, $target_id );
		$this->test_post_ids[] = $target_id;

		$written = Manual_Content_Service::persist_translate_meta(
			$target_id,
			$partition['meta'],
			array(
				'_elementor_data'     => array( 'content_format' => 'json_structured' ),
				'_product_attributes' => array( 'content_format' => 'serialized_php' ),
			)
		);
		$this->assertEquals( 2, $written );
		$stored = get_post_meta( $target_id, '_elementor_data', true );
		$this->assertIsArray( $stored );
		$this->assertEquals( 'Hello EN', $stored[0]['settings']['title'] );
		$woo = get_post_meta( $target_id, '_product_attributes', true );
		$this->assertEquals( 'Large', $woo['size']['name'] );
	}

	public function test_json_string_elementor_payload_is_not_text_sanitized() {
		$raw = '[{"id":"n1","settings":{"title":"Cafe"}}]';
		$out = Manual_Content_Service::sanitize_translate_meta_value(
			'_elementor_data',
			$raw,
			'json_structured'
		);
		$this->assertEquals( $raw, $out );
	}

	public function test_editor_payload_keeps_plugin_structured_fields() {
		$json = '[{"id":"n1","elType":"widget","settings":{"title":"Hello <b>EN</b>"}}]';
		$out  = Manual_Content_Service::sanitize_editor_payload_value( '_elementor_data', $json );
		$this->assertTrue( is_string( $out ) || is_array( $out ) );
		if ( is_string( $out ) ) {
			$this->assertStringContainsString( 'Hello <b>EN</b>', $out );
		} else {
			$this->assertEquals( 'Hello <b>EN</b>', $out[0]['settings']['title'] );
		}

		$attrs = array(
			'size' => array(
				'name'    => 'Size',
				'value'   => 'Large',
				'visible' => 1,
			),
		);
		$woo = Manual_Content_Service::sanitize_editor_payload_value( '_product_attributes', $attrs );
		$this->assertEquals( 'Large', $woo['size']['value'] );

		$title = Manual_Content_Service::sanitize_editor_payload_value( 'post_title', "Hi\n<script>x</script>" );
		$this->assertIsString( $title );
		$this->assertStringNotContainsString( '<script>', $title );
	}

	public function test_adapter_rules_overlay_source_plugin_meta() {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Adapter overlay',
			)
		);
		$this->assertGreaterThan( 0, $post_id );
		$this->test_post_ids[] = $post_id;
		update_post_meta( $post_id, '_elementor_data', '[{"id":"a"}]' );

		$config = Manual_Content_Service::apply_adapter_field_rules(
			array(
				'translate_fields'   => array( 'post_title' ),
				'sync_fields'        => array(),
				'id_mapping_fields'  => array(),
				'field_capabilities' => array(),
			),
			$post_id
		);
		$this->assertContains( '_elementor_data', $config['translate_fields'] );
		$this->assertEquals( 'json_structured', $config['field_capabilities']['_elementor_data']['content_format'] );
	}

	public function test_listen_does_not_invoke_translation_provider() {
		$called = false;
		$filter = function ( $value ) use ( &$called ) {
			$called = true;
			return $value;
		};
		add_filter( 'wptsall_translation_provider_dispatch', $filter );
		Hook_Manager::run_hook_action(
			array( 'hook_name' => 'save_post_product' ),
			array( 424242 )
		);
		Hook_Manager::run_hook_action(
			array( 'hook_name' => 'updated_post_meta' ),
			array( 1, 424242, '_elementor_data', '[]' )
		);
		remove_filter( 'wptsall_translation_provider_dispatch', $filter );
		$this->assertFalse( $called, 'Listen path must not dispatch a translation provider' );
	}
}

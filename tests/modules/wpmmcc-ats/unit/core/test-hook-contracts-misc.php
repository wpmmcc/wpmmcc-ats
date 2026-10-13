<?php
/**
 * Cross-layer misc hook contract tests (L1).
 *
 * Extension-point contracts spread across the hooks layer (Elementor URL
 * rewrite, output link localizer, metadata id remap), core (egress guard
 * allowlists, WPML peer configs), menu translation (auto clone), client
 * pairing (device token TTL), sites (lang query arg), and the model
 * adapters/manifest registries. Each test proves the hook fires at its
 * call site with the documented default/args and that the listener return
 * flips the documented behavior wherever cheaply observable.
 *
 * catalog: WP-HOOK-wptsall-enable-elementor-url-rewrite
 * catalog: WP-HOOK-wptsall-enable-output-link-localizer
 * catalog: WP-HOOK-wptsall-enable-metadata-id-remap
 * catalog: WP-HOOK-wptsall-metadata-id-remap-fields
 * catalog: WP-HOOK-wptsall-menu-auto-clone-enabled
 * catalog: WP-HOOK-wptsall-provider-url-allowlist
 * catalog: WP-HOOK-wptsall-provider-http-urls
 * catalog: WP-HOOK-wptsall-peer-language-configs
 * catalog: WP-HOOK-wptsall-lang-query-arg
 * catalog: WP-HOOK-wptsall-json-field-rules-adapters
 * catalog: WP-HOOK-wptsall-plugin-field-rules-adapters
 * catalog: WP-HOOK-wptsall-adapter-manifests
 * catalog: WP-HOOK-wptsall-device-token-ttl
 * catalog: WP-HOOK-wptsall-fse-managed-post-types
 * oracle: L1
 *
 * @package WPTSALL\Tests\Unit\Core
 * @since 2.1.4
 */

use WPTSALL\Hooks\Elementor_Data_Url_Rewriter;
use WPTSALL\Hooks\Output_Link_Localizer;
use WPTSALL\Hooks\Metadata_Id_Remapper;
use WPTSALL\Core\Egress_Guard;
use WPTSALL\Core\WPML_Config_Reader;
use WPTSALL\MenuTranslation\Menu_Mapping_Service;
use WPTSALL\Client_Pairing\Services\Client_Token_Service;
use WPTSALL\Sites\Services\Url_Converter;
use WPTSALL\Models\Adapters\Plugin_Field_Rules_Registry;
use WPTSALL\Models\Adapters\Adapter_Manifest;

class Test_Hook_Contracts_Misc extends SimpleTestCase {

	/**
	 * Captured hook invocations: hook => list of args arrays.
	 *
	 * @var array
	 */
	private $captured = array();

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
	 * Minimal virtual-site context.
	 *
	 * @return array
	 */
	private function vs_context() {
		return array(
			'site_id'     => 'vtest',
			'path_prefix' => 'vtest',
			'lang'        => 'en_US',
		);
	}

	public function setUp(): void {
		parent::setUp();
		$this->captured = array();
	}

	public function tearDown(): void {
		foreach ( array_keys( $this->captured ) as $hook ) {
			remove_all_filters( $hook, 10 );
		}
		$this->captured = array();
		unset( $GLOBALS['wptsall_current_virtual_site'] );
		$this->reset_static( Output_Link_Localizer::class, 'started', false );
		Metadata_Id_Remapper::reset_cache();
		$this->reset_static( WPML_Config_Reader::class, 'configs', null );
		parent::tearDown();
	}

	// ==================== frontend enable flags ====================

	/**
	 * wptsall_enable_elementor_url_rewrite: consulted with (true, $vs) for
	 * _elementor_data reads under a virtual-site context; false skips the
	 * rewrite (value returned untouched).
	 */
	public function test_enable_elementor_url_rewrite_contract() {
		$GLOBALS['wptsall_current_virtual_site'] = $this->vs_context();

		$this->capture_filter( 'wptsall_enable_elementor_url_rewrite', false );
		$result = Elementor_Data_Url_Rewriter::filter_get_post_metadata( 'raw-json', 0, '_elementor_data', true );

		$this->assertSame( 'raw-json', $result, 'escape hatch false must return the meta value untouched' );
		$calls = $this->captured['wptsall_enable_elementor_url_rewrite'];
		$this->assertNotEmpty( $calls, 'enable flag must be consulted for _elementor_data reads in a virtual context' );
		$this->assertTrue( $calls[0][0], 'default value must be true (rewrite enabled)' );
		$this->assertIsArray( $calls[0][1], 'second arg must be the virtual-site array' );
	}

	/**
	 * wptsall_enable_output_link_localizer: consulted with (true, $vs)
	 * before any output buffering starts; false skips the buffer entirely
	 * (asserted via the started flag staying false).
	 */
	public function test_enable_output_link_localizer_contract() {
		$GLOBALS['wptsall_current_virtual_site'] = $this->vs_context();
		$this->reset_static( Output_Link_Localizer::class, 'started', false );

		$this->capture_filter( 'wptsall_enable_output_link_localizer', false );
		Output_Link_Localizer::maybe_start_buffer();

		$calls = $this->captured['wptsall_enable_output_link_localizer'];
		$this->assertNotEmpty( $calls, 'enable flag must be consulted before buffering starts' );
		$this->assertTrue( $calls[0][0], 'default value must be true (localizer enabled)' );
		$this->assertIsArray( $calls[0][1], 'second arg must be the virtual-site array' );
		$started = new ReflectionProperty( Output_Link_Localizer::class, 'started' );
		$started->setAccessible( true );
		$this->assertFalse( $started->getValue(), 'escape hatch false must prevent the output buffer from starting' );
	}

	// ==================== metadata id remap ====================

	/**
	 * wptsall_metadata_id_remap_fields: fired with the built-in id_mapping
	 * field map; listener-added fields become remappable.
	 */
	public function test_metadata_id_remap_fields_contract() {
		Metadata_Id_Remapper::reset_cache();
		$default = Metadata_Id_Remapper::get_field_map();
		$this->assertArrayHasKey( '_thumbnail_id', $default, 'built-in _thumbnail_id mapping must be in the default map' );

		Metadata_Id_Remapper::reset_cache();
		$this->capture_filter(
			'wptsall_metadata_id_remap_fields',
			function ( $map ) {
				$map['_hooktest_remap_field'] = array(
					'type'           => 'id_mapping',
					'reference_type' => 'media',
				);
				return $map;
			}
		);
		$extended = Metadata_Id_Remapper::get_field_map();
		$this->assertArrayHasKey( '_hooktest_remap_field', $extended, 'listener-added field must reach the runtime map' );

		$calls = $this->captured['wptsall_metadata_id_remap_fields'];
		$this->assertNotEmpty( $calls );
		$this->assertIsArray( $calls[0][0], 'first arg must be the built-in field map' );
	}

	/**
	 * wptsall_enable_metadata_id_remap: consulted with
	 * (true, $meta_key, $object_id, $config) for mapped keys under a
	 * virtual-site context; false skips remapping.
	 */
	public function test_enable_metadata_id_remap_contract() {
		$GLOBALS['wptsall_current_virtual_site'] = $this->vs_context();
		Metadata_Id_Remapper::reset_cache();
		$this->capture_filter(
			'wptsall_metadata_id_remap_fields',
			function ( $map ) {
				$map['_hooktest_remap_field'] = array(
					'type'           => 'id_mapping',
					'reference_type' => 'media',
				);
				return $map;
			}
		);

		$this->capture_filter( 'wptsall_enable_metadata_id_remap', false );
		$result = Metadata_Id_Remapper::filter_get_post_metadata( '5', 0, '_hooktest_remap_field', true );

		$this->assertSame( '5', $result, 'escape hatch false must return the meta value untouched' );
		$calls = $this->captured['wptsall_enable_metadata_id_remap'];
		$this->assertNotEmpty( $calls, 'enable flag must be consulted for mapped keys in a virtual context' );
		$this->assertTrue( $calls[0][0], 'default value must be true (remap enabled)' );
		$this->assertSame( '_hooktest_remap_field', $calls[0][1], 'second arg must be the meta key' );
		$this->assertIsArray( $calls[0][3], 'fourth arg must be the field config' );
	}

	// ==================== menu / egress / wpml ====================

	/**
	 * wptsall_menu_auto_clone_enabled: fired with the settings-derived
	 * value; listener override decides the final behavior.
	 */
	public function test_menu_auto_clone_enabled_contract() {
		$default = Menu_Mapping_Service::menu_auto_clone_enabled();
		$this->assertIsBool( $default );

		$this->capture_filter( 'wptsall_menu_auto_clone_enabled', true );
		$this->assertTrue( Menu_Mapping_Service::menu_auto_clone_enabled(), 'listener override must decide the final value' );

		$calls = $this->captured['wptsall_menu_auto_clone_enabled'];
		$this->assertNotEmpty( $calls, 'the filter must be consulted by the auto-clone check' );
	}

	/**
	 * wptsall_provider_url_allowlist: default empty allowlist keeps the
	 * SSRF host guard active (localhost blocked); allowlisting a host lets
	 * its URLs pass.
	 */
	public function test_provider_url_allowlist_contract() {
		$url = 'http://localhost:9090/hook-test';

		// Default: empty allowlist -> the SSRF host guard blocks localhost.
		$blocked = Egress_Guard::assert_provider_url_allowed( $url );
		$this->assertTrue( is_wp_error( $blocked ), 'default empty allowlist must keep the SSRF host guard active' );
		$this->assertSame( 'egress_blocked_host', $blocked->get_error_code(), 'localhost must be blocked as an SSRF host by default' );

		// Allowlisted host passes before the SSRF checks.
		$this->capture_filter( 'wptsall_provider_url_allowlist', array( 'localhost' ) );
		$allowed = Egress_Guard::assert_provider_url_allowed( $url );
		$this->assertTrue( $allowed, 'allowlisted host must pass the provider URL assertion' );

		$calls = $this->captured['wptsall_provider_url_allowlist'];
		$this->assertNotEmpty( $calls );
		$this->assertSame( array(), $calls[0][0], 'default allowlist must be empty' );
	}

	/**
	 * wptsall_provider_http_urls: consulted with the pattern list during
	 * HTTP preflight interception.
	 */
	public function test_provider_http_urls_contract() {
		$this->capture_filter( 'wptsall_provider_http_urls', array() );
		Egress_Guard::filter_pre_http_request( false, array( 'method' => 'GET' ), 'https://example.org/hook-test' );

		$calls = $this->captured['wptsall_provider_http_urls'];
		$this->assertNotEmpty( $calls, 'pattern list must be consulted during HTTP interception' );
		$this->assertIsArray( $calls[0][0], 'first arg must be the pattern list' );
	}

	/**
	 * wptsall_peer_language_configs: fired with the discovered provider
	 * configs; listener return becomes the resolved config set.
	 */
	public function test_peer_language_configs_contract() {
		$this->reset_static( WPML_Config_Reader::class, 'configs', null );

		$override = array( 'hooktest-provider' => array( 'lang' => 'en_US' ) );
		$this->capture_filter( 'wptsall_peer_language_configs', $override );
		$result = WPML_Config_Reader::get_all_configs();

		$this->assertSame( $override, $result, 'listener return must become the resolved config set' );
		$calls = $this->captured['wptsall_peer_language_configs'];
		$this->assertNotEmpty( $calls );
		$this->assertIsArray( $calls[0][0], 'first arg must be the discovered configs' );
	}

	// ==================== sites / adapters ====================

	/**
	 * wptsall_lang_query_arg: default query arg key 'lang'; listener
	 * override renames the appended parameter.
	 */
	public function test_lang_query_arg_contract() {
		$vs = $this->vs_context();

		$default = Url_Converter::append_lang_param( 'https://example.org/page', $vs );
		$this->assertStringContainsString( 'lang=en_US', $default, 'default query arg key must be lang' );

		$this->capture_filter( 'wptsall_lang_query_arg', 'hl' );
		$renamed = Url_Converter::append_lang_param( 'https://example.org/page', $vs );
		$this->assertStringContainsString( 'hl=en_US', $renamed, 'listener override must rename the query arg' );
		$this->assertStringNotContainsString( 'lang=', $renamed, 'the default key must not appear when overridden' );

		$calls = $this->captured['wptsall_lang_query_arg'];
		$this->assertNotEmpty( $calls );
		$this->assertSame( 'lang', $calls[0][0], 'default query arg must be lang' );
		$this->assertSame( $vs, $calls[0][1], 'second arg must be the virtual-site array' );
	}

	/**
	 * wptsall_json_field_rules_adapters: fired with discovered JSON
	 * adapters; listener return is the resolved adapter list.
	 */
	public function test_json_field_rules_adapters_contract() {
		$this->capture_filter( 'wptsall_json_field_rules_adapters', array( 'hooktest-adapter' ) );
		$result = Plugin_Field_Rules_Registry::get_json_adapters();
		$this->assertSame( array( 'hooktest-adapter' ), $result, 'listener return must be the resolved adapter list' );

		$calls = $this->captured['wptsall_json_field_rules_adapters'];
		$this->assertNotEmpty( $calls );
		$this->assertIsArray( $calls[0][0], 'first arg must be the discovered adapter list' );
	}

	/**
	 * wptsall_plugin_field_rules_adapters: fired with the registered
	 * adapter class names; listener return is the resolved list.
	 */
	public function test_plugin_field_rules_adapters_contract() {
		$this->capture_filter( 'wptsall_plugin_field_rules_adapters', array( 'Hooktest_Adapter' ) );
		$result = Plugin_Field_Rules_Registry::get_adapters();
		$this->assertSame( array( 'Hooktest_Adapter' ), $result, 'listener return must be the resolved adapter list' );

		$calls = $this->captured['wptsall_plugin_field_rules_adapters'];
		$this->assertNotEmpty( $calls );
		$this->assertIsArray( $calls[0][0], 'first arg must be the registered adapter class list' );
	}

	/**
	 * wptsall_adapter_manifests: fired with the merged manifest map after
	 * JSON + built-in discovery; listener return is the resolved map.
	 */
	public function test_adapter_manifests_contract() {
		$this->capture_filter( 'wptsall_adapter_manifests', array( 'hooktest-plugin' => array( 'plugin_slug' => 'hooktest-plugin' ) ) );
		$result = Adapter_Manifest::all();
		$this->assertArrayHasKey( 'hooktest-plugin', $result, 'listener return must be the resolved manifest map' );

		$calls = $this->captured['wptsall_adapter_manifests'];
		$this->assertNotEmpty( $calls );
		$this->assertIsArray( $calls[0][0], 'first arg must be the discovered manifest map' );
	}

	// ==================== client pairing ====================

	/**
	 * wptsall_device_token_ttl: consulted with the default 3600s when no
	 * explicit lifetime was requested; listener override decides the TTL.
	 */
	public function test_device_token_ttl_contract() {
		$service = new Client_Token_Service();
		$m       = new ReflectionMethod( Client_Token_Service::class, 'resolve_device_token_ttl' );
		$m->setAccessible( true );

		$this->capture_filter( 'wptsall_device_token_ttl', 99 );
		$ttl = $m->invoke( $service, null );
		$this->assertSame( 99, $ttl, 'listener override must decide the device token TTL' );

		$calls = $this->captured['wptsall_device_token_ttl'];
		$this->assertNotEmpty( $calls, 'tunable must be consulted when no explicit TTL was requested' );
		$this->assertSame( 3600, $calls[0][0], 'default device token TTL must be 3600s' );
	}

	/**
	 * wptsall_fse_managed_post_types declares which FSE post types the
	 * plugin treats as managed content (REST subtype discovery at
	 * Client_Data_REST_Controller and sync write-back format alignment at
	 * Sync_Executor both consult it with the documented 4-type default).
	 * The FSE_Content_Adapter registers itself on the tag and re-merges the
	 * core 4, so extensions can widen the set but cannot drop core types.
	 */
	public function test_fse_managed_post_types_default_and_adapter_merge() {
		$default = (array) apply_filters( 'wptsall_fse_managed_post_types', array( 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_global_styles' ) );
		foreach ( array( 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_global_styles' ) as $core_type ) {
			$this->assertContains( $core_type, $default, "default set must include core FSE type {$core_type}" );
		}

		$widen = static function ( $types ) {
			return array_values( array_unique( array_merge( (array) $types, array( 'my_fse_pt' ) ) ) );
		};
		add_filter( 'wptsall_fse_managed_post_types', $widen );
		$widened = (array) apply_filters( 'wptsall_fse_managed_post_types', array( 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_global_styles' ) );
		remove_filter( 'wptsall_fse_managed_post_types', $widen );
		$this->assertContains( 'my_fse_pt', $widened, 'an extension must be able to widen the managed set' );

		// Adapter merge semantics: core 4 always survive, extension types kept.
		$merged = \WPTSALL\Fse\FSE_Content_Adapter::filter_managed_types( array( 'my_fse_pt' ) );
		foreach ( array( 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_global_styles', 'my_fse_pt' ) as $expect ) {
			$this->assertContains( $expect, $merged, "adapter merge must keep {$expect}" );
		}
		$this->assertCount( 5, $merged, 'merge result must be de-duplicated' );

		$narrow = \WPTSALL\Fse\FSE_Content_Adapter::filter_managed_types( array() );
		foreach ( array( 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_global_styles' ) as $core_type ) {
			$this->assertContains( $core_type, $narrow, "adapter merge must re-add core FSE type {$core_type} even if a listener dropped it" );
		}
	}
}

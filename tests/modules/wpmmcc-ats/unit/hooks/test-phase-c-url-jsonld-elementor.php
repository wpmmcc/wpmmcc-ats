<?php
/**
 * Phase C: URL param / default-dir, JSON-LD, Elementor rewrite unit tests.
 *
 * @package WPTSALL
 * @since 2.2.0
 */

use WPTSALL\Sites\Services\Url_Converter;
use WPTSALL\Hooks\Output_Link_Localizer;
use WPTSALL\Hooks\Elementor_Data_Url_Rewriter;
use WPTSALL\Hooks\Virtual_Site_Router;
use WPTSALL\Settings\Services\Settings_Service;

class Test_Phase_C_Url_Jsonld_Elementor extends SimpleTestCase {

	private $saved_settings = null;

	public function setUp(): void {
		if ( class_exists( Settings_Service::class ) ) {
			$this->saved_settings = Settings_Service::get_all();
		}
	}

	public function tearDown(): void {
		if ( null !== $this->saved_settings && class_exists( Settings_Service::class ) ) {
			Settings_Service::update( $this->saved_settings );
		}
		$GLOBALS['wptsall_current_virtual_site'] = null;
		$ref = new ReflectionClass( Virtual_Site_Router::class );
		if ( $ref->hasProperty( 'current_virtual_site' ) ) {
			$prop = $ref->getProperty( 'current_virtual_site' );
			$prop->setAccessible( true );
			$prop->setValue( null, null );
		}
	}

	public function test_directory_for_default_language_omits_prefix_when_off() {
		Settings_Service::update(
			array(
				'default_language'                  => 'zh_CN',
				'directory_for_default_language'    => false,
				'url_form'                          => 'subdir',
			)
		);
		$vs  = array( 'path_prefix' => 'zh', 'lang' => 'zh_CN' );
		$url = 'https://example.test/about/';
		$out = Url_Converter::virtualize( $url, $vs );
		$this->assertEquals( 'https://example.test/about/', $out );
		$this->assertTrue( Url_Converter::should_omit_prefix_for_vs( $vs ) );
	}

	public function test_directory_for_default_language_keeps_prefix_when_on() {
		Settings_Service::update(
			array(
				'default_language'               => 'zh_CN',
				'directory_for_default_language' => true,
				'url_form'                       => 'subdir',
			)
		);
		$vs  = array( 'path_prefix' => 'zh', 'lang' => 'zh_CN' );
		$out = Url_Converter::virtualize( 'https://example.test/about/', $vs );
		$this->assertTrue( false !== strpos( $out, '/zh/about/' ), $out );
	}

	public function test_param_url_form_appends_lang_query() {
		Settings_Service::update(
			array(
				'url_form'                       => 'param',
				'directory_for_default_language' => true,
				'default_language'               => 'zh_CN',
			)
		);
		$vs  = array( 'path_prefix' => 'en_us', 'lang' => 'en_US' );
		$out = Url_Converter::virtualize( 'https://example.test/hello/', $vs );
		$this->assertTrue( false !== strpos( $out, 'lang=' ), $out );
		$this->assertTrue( false !== strpos( $out, 'en_US' ) || false !== strpos( $out, 'en_us' ), $out );
		$this->assertFalse( false !== strpos( $out, '/en_us/' ), 'param mode must not inject path prefix: ' . $out );
	}

	public function test_is_param_mode_helper() {
		Settings_Service::update( array( 'url_form' => 'param' ) );
		$this->assertTrue( Virtual_Site_Router::is_param_mode() );
		Settings_Service::update( array( 'url_form' => 'subdir' ) );
		$this->assertFalse( Virtual_Site_Router::is_param_mode() );
	}

	public function test_json_ld_script_urls_are_prefixed() {
		Settings_Service::update( array( 'url_form' => 'subdir', 'directory_for_default_language' => true ) );
		$vs = array( 'id' => 'v1', 'path_prefix' => 'en_us', 'lang' => 'en_US' );
		$GLOBALS['wptsall_current_virtual_site'] = $vs;
		$ref = new ReflectionClass( Virtual_Site_Router::class );
		$prop = $ref->getProperty( 'current_virtual_site' );
		$prop->setAccessible( true );
		$prop->setValue( null, $vs );

		$home = home_url( '/product/x/' );
		$html = '<html><head><script type="application/ld+json">{"@type":"Product","url":"' . $home . '"}</script></head><body></body></html>';
		$out  = Output_Link_Localizer::rewrite_html( $html );
		$this->assertTrue( false !== strpos( $out, '/en_us/' ), $out );
		$this->assertTrue( false !== strpos( $out, 'application/ld+json' ), $out );
	}

	public function test_elementor_data_url_walk_virtualizes() {
		Settings_Service::update( array( 'url_form' => 'subdir', 'directory_for_default_language' => true ) );
		$vs   = array( 'path_prefix' => 'en_us', 'lang' => 'en_US' );
		$json = wp_json_encode(
			array(
				array(
					'widgetType' => 'button',
					'settings'   => array(
						'link' => array( 'url' => 'https://example.test/contact/' ),
					),
				),
			)
		);
		$out = Elementor_Data_Url_Rewriter::rewrite_meta_value( $json, $vs );
		$this->assertTrue( false !== strpos( (string) $out, '/en_us/contact/' ), (string) $out );
	}

	public function test_normalize_lang() {
		$this->assertEquals( 'en_us', Url_Converter::normalize_lang( 'en-US' ) );
		$this->assertEquals( 'zh_cn', Url_Converter::normalize_lang( 'zh_CN' ) );
	}
}

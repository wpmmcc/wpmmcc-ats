<?php
/**
 * Theme Localization Tests
 *
 * Tests for WPTSALL\ThemeLocalization\Theme_Localization: locale override
 * option API plus the deprecated wrappers delegating to
 * \WPTSALL\Core\Language_Context.
 *
 * catalog: WP-CLASS-Theme_Localization
 * oracle: L2
 *
 * @package WPTSALL\Tests\Unit
 * @since 1.3.0
 */

use WPTSALL\Core\Language_Context;
use WPTSALL\ThemeLocalization\Theme_Localization;

class Test_Theme_Localization extends SimpleTestCase {

	public function setUp(): void {
		parent::setUp();
		Language_Context::reset();
		delete_option( 'wptsall_locale_overrides' );
	}

	public function tearDown(): void {
		Language_Context::reset();
		delete_option( 'wptsall_locale_overrides' );
		parent::tearDown();
	}

	/**
	 * Class + option key contract.
	 */
	public function test_class_and_option_constant() {
		$this->assertTrue( class_exists( 'WPTSALL\ThemeLocalization\Theme_Localization' ) );
		$this->assertEquals( 'wptsall_locale_overrides', Theme_Localization::OPTION );
		// Fresh option resolves to an empty array, never a scalar.
		$this->assertIsArray( Theme_Localization::get_overrides() );
		$this->assertEmpty( Theme_Localization::get_overrides() );
	}

	/**
	 * set_override() stores and get_overrides() reads back the mapping.
	 */
	public function test_set_and_get_override() {
		$result = Theme_Localization::set_override( 'en_GB', 'en_GB@formal' );
		$this->assertIsArray( $result );
		$this->assertEquals( 'en_GB@formal', $result['en_GB'] );

		$overrides = Theme_Localization::get_overrides();
		$this->assertArrayHasKey( 'en_GB', $overrides );
		$this->assertEquals( 'en_GB@formal', $overrides['en_GB'] );
	}

	/**
	 * set_override() overwrites an existing mapping for the same language.
	 */
	public function test_set_override_overwrites_existing() {
		Theme_Localization::set_override( 'fr_FR', 'fr_BE' );
		Theme_Localization::set_override( 'fr_FR', 'fr_CA' );

		$overrides = Theme_Localization::get_overrides();
		$this->assertCount( 1, $overrides );
		$this->assertEquals( 'fr_CA', $overrides['fr_FR'] );
	}

	/**
	 * clear_override() removes only the requested language's mapping.
	 */
	public function test_clear_override() {
		Theme_Localization::set_override( 'de_DE', 'de_DE@formal' );
		Theme_Localization::set_override( 'fr_FR', 'fr_CA' );

		$result = Theme_Localization::clear_override( 'de_DE' );
		$this->assertArrayNotHasKey( 'de_DE', $result );
		$this->assertArrayHasKey( 'fr_FR', $result );

		// Clearing a never-set language leaves the option untouched.
		$result2 = Theme_Localization::clear_override( 'xx_XX' );
		$this->assertArrayHasKey( 'fr_FR', $result2 );
		$this->assertCount( 1, $result2 );
	}

	/**
	 * Deprecated filter_locale() passes the locale through when no target
	 * language is pinned for the request.
	 */
	public function test_filter_locale_without_target_returns_input() {
		$this->assertEquals( 'de_DE', Theme_Localization::filter_locale( 'de_DE' ) );
		$this->assertEquals( 'de_DE', Theme_Localization::filter_plugin_locale( 'de_DE', 'any-domain' ) );
	}

	/**
	 * filter_locale() returns the pinned request language when no override
	 * is configured; filter_plugin_locale() agrees (deprecated wrappers must
	 * not fatal and must return the delegated value).
	 */
	public function test_filter_locale_with_pinned_language() {
		Language_Context::set_language( 'fr_FR' );

		$this->assertEquals( 'fr_FR', Theme_Localization::filter_locale( 'en_US' ) );
		$this->assertEquals( 'fr_FR', Theme_Localization::filter_plugin_locale( 'en_US', 'wpmmcc-ats' ) );
		$this->assertEquals( 'fr_FR', Theme_Localization::current_target_lang() );
	}

	/**
	 * A configured locale override wins over the raw target language.
	 */
	public function test_filter_locale_respects_override() {
		Language_Context::set_language( 'fr_FR' );
		Theme_Localization::set_override( 'fr_FR', 'fr_CA' );

		$this->assertEquals( 'fr_CA', Theme_Localization::filter_locale( 'en_US' ) );
	}

	/**
	 * current_target_lang() reports the normalized pinned language, and ''
	 * once the context is reset.
	 */
	public function test_current_target_lang_normalizes_and_resets() {
		Language_Context::set_language( 'en-gb' );
		$this->assertEquals( 'en_GB', Theme_Localization::current_target_lang(), 'xx-YY tokens are normalized to xx_YY' );

		Language_Context::set_language( 'en' );
		$this->assertEquals( 'en', Theme_Localization::current_target_lang(), 'bare two-letter codes are preserved' );

		Language_Context::reset();
		$this->assertEquals( '', Theme_Localization::current_target_lang(), 'a reset context reports no target language' );
	}
}

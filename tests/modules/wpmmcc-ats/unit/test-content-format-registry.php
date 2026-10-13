<?php
/**
 * Content Format Registry Tests
 *
 * Tests for WPTSALL\Core\Content_Format_Registry against the canonical
 * content-formats-v1 vocabulary (authority: libs/wptsall-contracts/content_formats.json,
 * pinned here as literals so the assertions also hold inside the Lab container
 * where the repo libs/ tree is not mounted):
 * - canonical id list + alias map match the contract
 * - normalize() maps aliases to canonical ids, lowercases, defaults empty -> plain_text
 * - is_callback_allowed(): empty allowed, 'code' rejected (case-insensitive),
 *   canonical + aliases allowed, unknown rejected
 * - is_known()/is_canonical()
 *
 * catalog: WP-CLASS-Content_Format_Registry
 * oracle: L2
 *
 * @package WPTSALL\Tests\Unit
 * @since 2.2.0
 */

use WPTSALL\Core\Content_Format_Registry;

class Test_Content_Format_Registry extends SimpleTestCase {

	/**
	 * Canonical ids from libs/wptsall-contracts/content_formats.json (content-formats-v1).
	 */
	const CONTRACT_CANONICAL = array(
		'plain_text',
		'rich_html',
		'json_structured',
		'serialized_php',
		'slug',
		'code',
		'media_ref',
	);

	/**
	 * Alias map from the same contract file.
	 */
	const CONTRACT_ALIASES = array(
		'text'       => 'plain_text',
		'plain'      => 'plain_text',
		'html'       => 'rich_html',
		'json'       => 'json_structured',
		'serialized' => 'serialized_php',
	);

	public function test_class_exists() {
		$this->assertTrue( class_exists( 'WPTSALL\Core\Content_Format_Registry' ) );
	}

	public function test_canonical_matches_contract_vocabulary() {
		// The contract vocabulary is a SET: the contract file lists
		// json_structured before serialized_php while the registry returns
		// serialized_php first. canonical() order carries no semantics
		// (consumed via in_array), so the set is pinned sorted.
		$expected = self::CONTRACT_CANONICAL;
		$actual   = Content_Format_Registry::canonical();
		sort( $expected );
		sort( $actual );
		$this->assertSame( $expected, $actual );
	}

	public function test_aliases_match_contract_vocabulary() {
		$this->assertSame( self::CONTRACT_ALIASES, Content_Format_Registry::aliases() );
	}

	public function test_callback_rejected_is_code_only() {
		// Contract: code is the only non-translatable free-text format.
		$this->assertSame( array( 'code' ), Content_Format_Registry::callback_rejected() );
	}

	public function test_callback_allowed_is_canonical_plus_aliases() {
		$expected = array_merge(
			array_values( array_diff( self::CONTRACT_CANONICAL, array( 'code' ) ) ),
			array_keys( self::CONTRACT_ALIASES )
		);
		$allowed = Content_Format_Registry::callback_allowed();
		sort( $expected );
		$sorted_allowed = $allowed;
		sort( $sorted_allowed );
		$this->assertSame( $expected, $sorted_allowed, 'callback set = translatable canonical ids + aliases' );
		$this->assertNotContains( 'code', $allowed );
	}

	public function test_normalize_maps_aliases_and_case() {
		$this->assertEquals( 'plain_text', Content_Format_Registry::normalize( 'text' ) );
		$this->assertEquals( 'plain_text', Content_Format_Registry::normalize( 'plain' ) );
		$this->assertEquals( 'rich_html', Content_Format_Registry::normalize( 'html' ) );
		$this->assertEquals( 'json_structured', Content_Format_Registry::normalize( 'json' ) );
		$this->assertEquals( 'serialized_php', Content_Format_Registry::normalize( 'serialized' ) );

		// Whitespace + case are normalized.
		$this->assertEquals( 'rich_html', Content_Format_Registry::normalize( '  HTML ' ) );
		// Canonical ids pass through.
		$this->assertEquals( 'code', Content_Format_Registry::normalize( 'CODE' ) );
		// Unknown formats are lowercased, not aliased.
		$this->assertEquals( 'elementor_html', Content_Format_Registry::normalize( 'Elementor_HTML' ) );
		// Empty input defaults to plain_text per contract ("Default when empty").
		$this->assertEquals( 'plain_text', Content_Format_Registry::normalize( '' ) );
		$this->assertEquals( 'plain_text', Content_Format_Registry::normalize( '   ' ) );
	}

	public function test_is_callback_allowed_branches() {
		// Empty means "default" and is allowed.
		$this->assertTrue( Content_Format_Registry::is_callback_allowed( '' ) );
		$this->assertTrue( Content_Format_Registry::is_callback_allowed( '   ' ) );

		// 'code' rejected, case-insensitive and trimmed.
		$this->assertFalse( Content_Format_Registry::is_callback_allowed( 'code' ) );
		$this->assertFalse( Content_Format_Registry::is_callback_allowed( 'CODE' ) );
		$this->assertFalse( Content_Format_Registry::is_callback_allowed( ' Code ' ) );

		// Canonical translatable formats + aliases accepted.
		foreach ( array( 'plain_text', 'rich_html', 'slug', 'media_ref', 'serialized_php', 'json_structured' ) as $format ) {
			$this->assertTrue( Content_Format_Registry::is_callback_allowed( $format ), "canonical {$format} must be callback-allowed" );
		}
		foreach ( array_keys( self::CONTRACT_ALIASES ) as $alias ) {
			$this->assertTrue( Content_Format_Registry::is_callback_allowed( $alias ), "alias {$alias} must be callback-allowed" );
		}

		// Unknown vocabulary rejected.
		$this->assertFalse( Content_Format_Registry::is_callback_allowed( 'elementor_html' ) );
	}

	public function test_is_known_and_is_canonical() {
		foreach ( self::CONTRACT_CANONICAL as $id ) {
			$this->assertTrue( Content_Format_Registry::is_known( $id ), "canonical {$id} must be known" );
			$this->assertTrue( Content_Format_Registry::is_canonical( $id ), "canonical {$id} must be canonical" );
		}
		foreach ( array_keys( self::CONTRACT_ALIASES ) as $alias ) {
			$this->assertTrue( Content_Format_Registry::is_known( $alias ), "alias {$alias} must be known" );
			// Alias normalizes to a canonical id.
			$this->assertTrue( Content_Format_Registry::is_canonical( $alias ) );
		}

		// Unknown and empty.
		$this->assertFalse( Content_Format_Registry::is_known( 'elementor_html' ) );
		$this->assertFalse( Content_Format_Registry::is_known( '' ) );
		// Case-insensitive.
		$this->assertTrue( Content_Format_Registry::is_known( 'Rich_HTML ' ) );
	}
}

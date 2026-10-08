<?php
/**
 * Langpack Service Tests
 *
 * Tests for WPTSALL\Core\Langpack_Service::load_uploads_textdomain():
 * - loads a user-installed .mo from uploads/wpmmcc-ats/languages/ so that
 *   __() resolves entries (real MO round-trip built via core MO class)
 * - explicit locale parameter picks the right file
 * - no-arg call falls back to determine_locale()
 * - missing file is a silent no-op (original string kept)
 *
 * Isolation note (2026-09-07, probe-proven on the Lab's WP 7.1): after
 * unsetting $GLOBALS['l10n'][domain], a later same-process
 * load_textdomain() for the SAME (domain, msgid) still resolves through the
 * WP_Translations cache from the first load. Every test below therefore
 * uses a uniqid-tagged msgid so no cache entry can collide across tests.
 *
 * catalog: WP-CLASS-Langpack_Service
 * oracle: L2
 *
 * @package WPTSALL\Tests\Unit
 * @since 2.2.0
 */

use WPTSALL\Core\Langpack_Service;

class Test_Langpack_Service extends SimpleTestCase {

	/**
	 * Directories/files created by this run (uniqid-tagged, cleaned in tearDown).
	 *
	 * @var array<int, string>
	 */
	private $created_paths = array();

	/**
	 * Original $GLOBALS['l10n']['wpmmcc-ats'] (null = domain not loaded).
	 *
	 * @var mixed
	 */
	private $original_l10n;

	public function setUp(): void {
		parent::setUp();
		if ( isset( $GLOBALS['l10n']['wpmmcc-ats'] ) ) {
			$this->original_l10n = $GLOBALS['l10n']['wpmmcc-ats'];
		} else {
			$this->original_l10n = null;
		}
	}

	public function tearDown(): void {
		foreach ( $this->created_paths as $path ) {
			if ( is_file( $path ) ) {
				@unlink( $path );
			}
		}
		// Remove the per-run languages dir only when this run created it empty.
		$uploads = wp_upload_dir();
		$lang_dir = trailingslashit( $uploads['basedir'] ) . 'wpmmcc-ats/languages';
		if ( is_dir( $lang_dir ) ) {
			$remaining = @scandir( $lang_dir );
			if ( is_array( $remaining ) && count( $remaining ) <= 2 ) {
				@rmdir( $lang_dir );
				@rmdir( dirname( $lang_dir ) );
			}
		}
		if ( null === $this->original_l10n ) {
			unset( $GLOBALS['l10n']['wpmmcc-ats'] );
		} else {
			$GLOBALS['l10n']['wpmmcc-ats'] = $this->original_l10n;
		}
		parent::tearDown();
	}

	/**
	 * Build a real .mo file with one entry and drop it in the uploads
	 * langpack location for the given locale.
	 *
	 * @param string $locale  Locale slug.
	 * @param string $msgid   Original string.
	 * @param string $msgstr  Translation.
	 * @return string Absolute path written.
	 */
	private function write_uploads_mo( string $locale, string $msgid, string $msgstr ): string {
		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . 'wpmmcc-ats/languages';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$file = $dir . '/wpmmcc-ats-' . $locale . '.mo';

		$mo = new MO();
		$mo->set_header( 'Project-Id-Version', 'unit-test' );
		$mo->add_entry( new Translation_Entry( array(
			'singular'     => $msgid,
			'translations' => array( $msgstr ),
		) ) );
		$exported = $mo->export_to_file( $file );
		if ( ! $exported ) {
			$this->markTestSkipped( 'uploads langpack dir not writable in this environment' );
		}
		$this->created_paths[] = $file;
		return $file;
	}

	public function test_class_exists() {
		$this->assertTrue( class_exists( 'WPTSALL\Core\Langpack_Service' ) );
	}

	/**
	 * Round-trip: a user-installed pack for an explicit locale is loaded
	 * and __() resolves through it.
	 */
	public function test_load_uploads_textdomain_explicit_locale() {
		$msgid = 'Pack Msg ' . uniqid();
		$this->write_uploads_mo( 'fr_FR', $msgid, 'Bonjour Unit' );

		// Before load: untranslated.
		$this->assertEquals( $msgid, __( $msgid, 'wpmmcc-ats' ) );

		Langpack_Service::load_uploads_textdomain( 'fr_FR' );

		$this->assertEquals( 'Bonjour Unit', __( $msgid, 'wpmmcc-ats' ), 'explicit-locale pack must load from uploads' );
		// Other strings pass through untouched.
		$other = 'Not In Pack ' . uniqid();
		$this->assertEquals( $other, __( $other, 'wpmmcc-ats' ) );
	}

	/**
	 * No-arg call uses determine_locale() as the file selector.
	 */
	public function test_load_uploads_textdomain_defaults_to_determine_locale() {
		$locale = determine_locale();
		$marker = 'Default Locale Marker ' . uniqid();
		$this->write_uploads_mo( $locale, $marker, 'translated-' . $locale );

		Langpack_Service::load_uploads_textdomain();

		$this->assertStringContainsString( 'translated-', __( $marker, 'wpmmcc-ats' ), 'no-arg call must load the determine_locale() pack' );
	}

	/**
	 * Missing .mo for the requested locale: silent no-op, __() unchanged.
	 */
	public function test_load_uploads_textdomain_missing_file_is_noop() {
		$msgid = 'Noop Msg ' . uniqid();
		$this->write_uploads_mo( 'de_DE', $msgid, 'Hallo Unit' );
		// Load de_DE to prove the mechanism works, then ask for a locale with no pack.
		Langpack_Service::load_uploads_textdomain( 'de_DE' );
		$this->assertEquals( 'Hallo Unit', __( $msgid, 'wpmmcc-ats' ) );

		Langpack_Service::load_uploads_textdomain( 'zz_ZZ' );

		$this->assertEquals( 'Hallo Unit', __( $msgid, 'wpmmcc-ats' ), 'zz_ZZ has no pack; the previously loaded domain must stay intact' );
		$absent = 'No Pack For Me ' . uniqid();
		$this->assertEquals( $absent, __( $absent, 'wpmmcc-ats' ) );
	}

	/**
	 * The loader reads the documented path only:
	 * uploads/wpmmcc-ats/languages/wpmmcc-ats-<locale>.mo — a pack placed for
	 * one locale is not picked up for another locale.
	 */
	public function test_loader_is_locale_scoped() {
		$msgid = 'Scoped Msg ' . uniqid();
		$this->write_uploads_mo( 'it_IT', $msgid, 'Scoped IT' );

		Langpack_Service::load_uploads_textdomain( 'pt_PT' );

		$this->assertEquals( $msgid, __( $msgid, 'wpmmcc-ats' ), 'pack for it_IT must not leak into pt_PT' );

		Langpack_Service::load_uploads_textdomain( 'it_IT' );
		$this->assertEquals( 'Scoped IT', __( $msgid, 'wpmmcc-ats' ) );
	}
}

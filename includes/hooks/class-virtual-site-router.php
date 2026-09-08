<?php
/**
 * Virtual Site Router
 *
 * Handles virtual site frontend routing, content filtering, and link rewriting.
 *
 * @package WPTSALL\Hooks
 * @since 0.5.0
 */

namespace WPTSALL\Hooks;

use WPTSALL\Sites\Services\Virtual_Site_Service;
use WPTSALL\Sites\Services\URL_Transformer;
use WPTSALL\Settings\Services\Settings_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Custom plugin tables and intentional meta/tax lookups; all dynamic values go through $wpdb->prepare() / wptsall_db_* helpers (no unprepared user input).
/**
 * Virtual_Site_Router class
 *
 * Provides URL routing, content replacement, and link rewriting for virtual sites.
 *
 * == Virtual site content isolation ==
 *
 * Virtual site content is stored in `wp_posts` with `_wptsall_virtual_site_id`
 * and `_wptsall_source_post_id` post meta markers. This matches the write path
 * in wptsall_process_post_task() (tasks.php).
 *
 * Isolation strategy:
 * - A `pre_get_posts` filter excludes posts with `_wptsall_virtual_site_id` meta
 *   from normal WordPress queries when NOT serving a virtual site URL.
 * - When serving a virtual site URL, content is fetched via get_virtual_content()
 *   which queries wp_posts + postmeta by virtual_site_id and source_post_id.
 *
 * @see get_virtual_content_from_legacy_table() -- reads from wp_posts + meta
 */
class Virtual_Site_Router {

	/**
	 * Current virtual site data
	 *
	 * @var array|null
	 */
	private static $current_virtual_site = null;

	/**
	 * Virtual content cache
	 *
	 * @var array
	 */
	private static $content_cache = array();

	/**
	 * Routing error information
	 *
	 * @var array|null
	 */
	private static $routing_error = null;

	/**
	 * Taxonomy URL slug to name mapping cache
	 *
	 * Dynamically built from registered taxonomies.
	 *
	 * @since 0.9.1
	 * @var array|null
	 */
	private static $taxonomy_map_cache = null;

	/**
	 * Cached list of active virtual sites.
	 *
	 * Avoids redundant Virtual_Site_Service::get_all() calls within the same request.
	 *
	 * @since 1.4.0
	 * @var array|null
	 */
	private static $cached_virtual_sites = null;

	/**
	 * Original template_include path captured at priority 0 (before theme-compat).
	 *
	 * @since 2.1.1
	 * @var string|null
	 */
	private static $original_template_include = null;

	/**
	 * Initialize the virtual site router
	 *
	 * @return void
	 */
	public static function init() {
		// Only run on frontend
		if ( is_admin() ) {
			return;
		}

		// These always run - they have their own empty checks
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ), 999 );
		add_action( 'init', array( __CLASS__, 'detect_virtual_site' ), 1000 );

		// Conditionally register frontend hooks only if virtual sites exist
		add_action( 'init', array( __CLASS__, 'maybe_register_frontend_hooks' ), 1001 );
	}

	/**
	 * Conditionally register frontend hooks only if virtual sites exist.
	 *
	 * Called at init priority 1001 (after detect_virtual_site at 1000).
	 * Avoids registering expensive filters/actions when no virtual sites
	 * are configured.
	 *
	 * @since 1.5.0
	 *
	 * @return void
	 */
	public static function maybe_register_frontend_hooks() {
		if ( empty( self::get_cached_virtual_sites() ) ) {
			return;
		}

		add_action( 'pre_get_posts', array( __CLASS__, 'exclude_virtual_posts_from_main_query' ) );
		add_filter( 'get_terms_args', array( Virtual_Site_Query_Switch::class, 'filter_get_terms_args' ), 10, 2 );
		add_action( 'parse_request', array( __CLASS__, 'parse_request' ) );
		add_action( 'template_redirect', array( __CLASS__, 'template_redirect' ) );

		// Block-theme safeguard: classic theme-compat plugins (bbPress, BuddyPress,
		// some LMS loaders) may set template_include to false/'' when page.php is
		// missing. Downstream filters then leave an empty path and WP renders a
		// 200 with Content-Length 0. Restore the block canvas (or the original
		// template captured at the start of the chain) so CPT journeys stay usable.
		add_filter( 'template_include', array( __CLASS__, 'capture_template_include_original' ), 0 );
		add_filter( 'template_include', array( __CLASS__, 'ensure_usable_template_include' ), 100000 );

		// P0-3 fallback: 404 with a target post slug -> 301 redirect to its virtual-site URL (e.g. /en_us/{slug}/).
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect_source_post_to_virtual' ), 99 );
		// P0-1 fix: use priority 999 for content/title/excerpt filters so
		// translated content is applied AFTER all third-party plugins
		// (Elementor at priority 10, WooCommerce shortcodes at 10, etc.)
		// have processed the original content. This prevents partial
		// or double-filtered output.
		add_filter( 'the_title', array( __CLASS__, 'filter_title' ), 999, 2 );
		add_filter( 'the_content', array( __CLASS__, 'filter_content' ), 999 );
		add_filter( 'the_excerpt', array( __CLASS__, 'filter_excerpt' ), 999 );
		add_filter( 'single_post_title', array( __CLASS__, 'filter_single_title' ), 999, 2 );
		// Permalink filters at 20 (Polylang-aligned): run after typical CPT
		// rewriters at 10 so our virtual prefix is the last word.
		add_filter( 'post_link', array( __CLASS__, 'rewrite_post_link' ), 20, 3 );
		add_filter( 'page_link', array( __CLASS__, 'rewrite_page_link' ), 20, 3 );
		add_filter( 'post_type_link', array( __CLASS__, 'rewrite_post_type_link' ), 20, 4 );
		add_filter( 'term_link', array( __CLASS__, 'rewrite_term_link' ), 20, 3 );
		add_filter( 'attachment_link', array( __CLASS__, 'rewrite_attachment_link' ), 20, 2 );
		add_filter( 'post_type_archive_link', array( __CLASS__, 'rewrite_post_type_archive_link' ), 20, 2 );

		// P0-2 fix: defer SEO head/sitemap/canonical/lang-class/robots layer
		// to a separate class. See class-virtual-site-seo.php for the full
		// rationale (modeled on Polylang / WPML / TP).
		\WPTSALL\Hooks\Virtual_Site_SEO::init();

		// WPML TranslateIds-style runtime ID remap for declared id_mapping meta.
		\WPTSALL\Hooks\Metadata_Id_Remapper::init();

		// Elementor JSON deep-link URL rewrite (runtime get_post_metadata).
		\WPTSALL\Hooks\Elementor_Data_Url_Rewriter::init();

		// WP CMS link surface: home_url, menus, static pages, search, widgets.
		\WPTSALL\Hooks\Virtual_Site_Link_Filters::init();

		// Output-layer safety net for hardcoded same-host links in HTML.
		\WPTSALL\Hooks\Output_Link_Localizer::init();
	}

	/**
	 * Get cached list of active virtual sites.
	 *
	 * Fetches from Virtual_Site_Service::get_all() on first call, then
	 * returns cached result for subsequent calls within the same request.
	 *
	 * @since 1.4.0
	 *
	 * @return array
	 */
	private static function get_cached_virtual_sites() {
		if ( null === self::$cached_virtual_sites ) {
			self::$cached_virtual_sites = Virtual_Site_Service::get_all( array( 'status' => 'active' ) );
		}
		return self::$cached_virtual_sites;
	}

	/**
	 * Add rewrite rules for virtual sites.
	 *
	 * P1-2 fix: WordPress stores rewrite rules in the `rewrite_rules`
	 * option after the first flush. Calling add_rewrite_rule() on every
	 * `init` is redundant and adds overhead. Instead, we only register
	 * the rules when the option is empty or when a version hash
	 * indicates the virtual site configuration has changed.
	 *
	 * @return void
	 */
	public static function add_rewrite_rules() {
		$virtual_sites = self::get_cached_virtual_sites();

		if ( empty( $virtual_sites ) ) {
			return;
		}

		// Register query vars (always needed).
		add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );

		// Always register the rules in-memory: add_rewrite_rule() is cheap,
		// and WordPress only persists rules when flush_rewrite_rules() runs.
		foreach ( $virtual_sites as $site ) {
			$path_prefix = trim( (string) ( $site['path_prefix'] ?? '' ), '/' );
			if ( '' === $path_prefix ) {
				continue;
			}
			add_rewrite_rule(
				'^' . preg_quote( $path_prefix, '/' ) . '/(.*)$',
				'index.php?wptsall_virtual_path=$matches[1]&wptsall_virtual_site=' . (int) $site['id'],
				'top'
			);
		}

		// P2-2 fix: self-heal wiped rewrite rules. A hard flush
		// (flush_rewrite_rules(true), plugin deactivation, DB restore) can
		// erase the persisted rules while the stored hash says "no change",
		// leaving every virtual URL a 404. Only re-persist when the stored
		// rules no longer contain our marker rule.
		$stored_rules = get_option( 'rewrite_rules', array() );
		$has_ours     = false;
		if ( is_array( $stored_rules ) ) {
			foreach ( $stored_rules as $query ) {
				if ( false !== strpos( (string) $query, 'wptsall_virtual_path' ) ) {
					$has_ours = true;
					break;
				}
			}
		}
		if ( $has_ours ) {
			return;
		}

		// Build a hash of current virtual site path prefixes to detect
		// configuration changes.
		$path_prefixes = array();
		foreach ( $virtual_sites as $site ) {
			$path_prefixes[] = trim( (string) ( $site['path_prefix'] ?? '' ), '/' );
		}
		$current_hash = md5( implode( '|', $path_prefixes ) );

		update_option( 'wptsall_rewrite_hash', $current_hash );
		flush_rewrite_rules( false );
		wptsall_log_info(
			'hooks-router',
			'Rewrite rules rebuilt (stale or missing)',
			array( 'hash' => $current_hash )
		);
	}

	/**
	 * Add custom query vars
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public static function add_query_vars( $vars ) {
		$vars[] = 'wptsall_virtual_path';
		$vars[] = 'wptsall_virtual_site';
		return $vars;
	}

	/**
	 * Detect if current request is for a virtual site
	 *
	 * @return void
	 */
	public static function detect_virtual_site() {
		// P4-3: Subdomain URL form (url_form=='subdomain') uses HTTP_HOST.
		if ( self::is_subdomain_mode() ) {
			self::detect_virtual_site_by_subdomain();
			return;
		}

		// Parameter URL form: ?lang= / ?wptsall_lang=.
		if ( self::is_param_mode() ) {
			self::detect_virtual_site_by_param();
			return;
		}

		$request_uri = function_exists( 'wptsall_get_request_uri' ) ? wptsall_get_request_uri() : '';

		if ( empty( $request_uri ) ) {
			return;
		}

		// Get all active virtual sites (uses static cache shared with add_rewrite_rules).
		$virtual_sites = self::get_cached_virtual_sites();

		if ( empty( $virtual_sites ) ) {
			return;
		}

		// Check if request matches any virtual site path
		foreach ( $virtual_sites as $site ) {
			$path_prefix = '/' . trim( $site['path_prefix'], '/' ) . '/';

			if ( strpos( $request_uri, $path_prefix ) === 0 ) {
				self::bind_detected_virtual_site( $site, 'path', array( 'request_uri' => $request_uri ) );
				return;
			}
		}

		// Fallback: search forms may post to an unprefixed action with hidden
		// wptsall_vs=<path_prefix> (Polylang get_search_form + lang query var pattern).
		self::detect_virtual_site_by_search_vs_param();
	}

	/**
	 * Detect virtual site from hidden search-form field `wptsall_vs`.
	 *
	 * @since 2.3.0
	 * @return void
	 */
	private static function detect_virtual_site_by_search_vs_param(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public front routing.
		$raw = isset( $_REQUEST['wptsall_vs'] ) ? wp_unslash( $_REQUEST['wptsall_vs'] ) : '';
		$vs_param = sanitize_text_field( (string) $raw );
		$vs_param = trim( $vs_param, '/' );
		if ( '' === $vs_param ) {
			return;
		}
		$norm = strtolower( $vs_param );
		foreach ( self::get_cached_virtual_sites() as $site ) {
			$pref = strtolower( trim( (string) ( $site['path_prefix'] ?? '' ), '/' ) );
			if ( '' !== $pref && $norm === $pref ) {
				self::bind_detected_virtual_site( $site, 'search_vs', array( 'wptsall_vs' => $vs_param ) );
				return;
			}
		}
	}

	/**
	 * Whether Settings url_form is parameter mode.
	 *
	 * @since 2.2.0
	 * @return bool
	 */
	public static function is_param_mode(): bool {
		if ( ! class_exists( '\\WPTSALL\\Settings\\Services\\Settings_Service' ) ) {
			return false;
		}
		$settings = \WPTSALL\Settings\Services\Settings_Service::get_all();
		return ( $settings['url_form'] ?? 'subdir' ) === 'param';
	}

	/**
	 * Detect virtual site from ?lang= / ?wptsall_lang= query args.
	 *
	 * @since 2.2.0
	 * @return void
	 */
	private static function detect_virtual_site_by_param(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public front routing.
		$raw = isset( $_GET['wptsall_lang'] ) ? wp_unslash( $_GET['wptsall_lang'] ) : ( isset( $_GET['lang'] ) ? wp_unslash( $_GET['lang'] ) : '' );
		$lang = sanitize_text_field( (string) $raw );
		if ( '' === $lang ) {
			return;
		}
		$norm = class_exists( '\\WPTSALL\\Sites\\Services\\Url_Converter' )
			? \WPTSALL\Sites\Services\Url_Converter::normalize_lang( $lang )
			: strtolower( str_replace( '-', '_', $lang ) );

		foreach ( self::get_cached_virtual_sites() as $site ) {
			$site_lang = (string) ( $site['lang'] ?? '' );
			$site_pref = (string) ( $site['path_prefix'] ?? '' );
			$match_lang = class_exists( '\\WPTSALL\\Sites\\Services\\Url_Converter' )
				? \WPTSALL\Sites\Services\Url_Converter::normalize_lang( $site_lang )
				: strtolower( str_replace( '-', '_', $site_lang ) );
			$match_pref = strtolower( trim( $site_pref, '/' ) );
			if ( $norm === $match_lang || $norm === $match_pref || strtolower( $lang ) === $match_pref ) {
				self::bind_detected_virtual_site( $site, 'param', array( 'lang' => $lang ) );
				return;
			}
		}
	}

	/**
	 * Bind current virtual site after detection.
	 *
	 * @since 2.2.0
	 * @param array  $site   Virtual site row.
	 * @param string $via    Detection channel (path|param|subdomain).
	 * @param array  $extra  Log context.
	 * @return void
	 */
	private static function bind_detected_virtual_site( array $site, string $via, array $extra = array() ): void {
		self::$current_virtual_site                 = $site;
		$GLOBALS['wptsall_current_virtual_site']    = $site;

		if ( class_exists( '\\WPTSALL\\Core\\Language_Context' ) && ! empty( $site['lang'] ) ) {
			\WPTSALL\Core\Language_Context::set_language( (string) $site['lang'] );
		}

		// S8: switch locale as soon as VS is known (init:1000), not only at template_redirect.
		if ( ! empty( $site['lang'] ) && function_exists( 'switch_to_locale' ) ) {
			switch_to_locale( (string) $site['lang'] );
		}

		wptsall_log_info(
			'hooks-router',
			'Virtual site detected',
			array_merge(
				array(
					'site_id'     => $site['id'] ?? '',
					'site_name'   => $site['name'] ?? '',
					'path_prefix' => $site['path_prefix'] ?? '',
					'via'         => $via,
				),
				$extra
			)
		);

		$relation = self::get_site_relation( $site['id'] ?? '' );
		if ( $relation ) {
			self::$current_virtual_site['relation_id'] = $relation['id'];
			self::$current_virtual_site['relation']    = $relation;
			$GLOBALS['wptsall_current_virtual_site']   = self::$current_virtual_site;
		}
	}

	/**
	 * Get current virtual site
	 *
	 * @return array|null
	 */
	/**
	 * Is the subdomain URL form active?
	 *
	 * Reads `url_form` from Settings_Service. Subdomain mode is only
	 * fully supported on multisite + domain mapping; on a single-site
	 * install the helper still maps subdomain prefix → lang code (used
	 * by the SEO hreflang emitter), but the actual per-host routing
	 * requires the WP network / domain mapping to be configured.
	 *
	 * @since 1.4.0
	 */
	public static function is_subdomain_mode(): bool {
		if ( ! class_exists( '\\WPTSALL\\Settings\\Services\\Settings_Service' ) ) {
			return false;
		}
		$settings = \WPTSALL\Settings\Services\Settings_Service::get_all();
		return ( $settings['url_form'] ?? 'subdir' ) === 'subdomain';
	}

	/**
	 * Map a host like `en.blog.wpmm.cc` to its language code (`en_US`).
	 *
	 * Lookup strategy (first match wins):
	 *   1. `wptsall_seo_subdomain_langs` option (explicit map)
	 *   2. Subdomain prefix → language slug match (uses
	 *      `wptsall_languages.slug`; e.g. `en-` matches `en_US`)
	 *   3. Null if no match
	 *
	 * @since 1.4.0
	 *
	 * @param string $host Hostname, e.g. `en.blog.wpmm.cc`.
	 * @return string|null Language code or null.
	 */
	public static function detect_subdomain_lang( string $host ): ?string {
		$host = strtolower( trim( $host ) );
		if ( '' === $host ) {
			return null;
		}
		$sub = self::extract_subdomain( $host );
		if ( null === $sub || '' === $sub ) {
			return null;
		}

		// 1) Explicit option map.
		$map = get_option( 'wptsall_seo_subdomain_langs', array() );
		if ( is_array( $map ) && isset( $map[ $sub ] ) ) {
			return (string) $map[ $sub ];
		}

		// 2) Subdomain prefix matches a language slug (e.g. `en` -> `en_US`).
		if ( class_exists( '\\WPTSALL\\Languages\\Services\\Language_Service' ) ) {
			foreach ( \WPTSALL\Languages\Services\Language_Service::get_all( array( 'status' => 'all' ) ) as $lang ) {
				$slug  = strtolower( (string) ( $lang['slug'] ?? '' ) );
				$code  = strtolower( (string) str_replace( '_', '-', (string) ( $lang['code'] ?? '' ) ) );
				if ( '' === $slug && '' === $code ) {
					continue;
				}
				// Exact slug/code match.
				if ( $slug === $sub || $code === $sub ) {
					return (string) $lang['code'];
				}
				// Partial: subdomain is the language part (`en`) of `en-us` / `en_us`.
				foreach ( array( $slug, $code ) as $full ) {
					$dash = explode( '-', $full, 2 );
					if ( isset( $dash[0] ) && $dash[0] === $sub && strlen( $sub ) >= 2 ) {
						return (string) $lang['code'];
					}
				}
			}
		}
		return null;
	}

	/**
	 * Extract the leftmost subdomain label from a host.
	 *
	 * Returns null for a 1-label host (`localhost`) or 2-label host
	 * (`blog.wpmm.cc`). Returns the leftmost label otherwise
	 * (`en.blog.wpmm.cc` -> `en`).
	 *
	 * @since 1.4.0
	 */
	public static function extract_subdomain( string $host ): ?string {
		$host = preg_replace( '/:\d+$/', '', strtolower( $host ) );
		if ( '' === $host ) {
			return null;
		}
		// Strip the main domain (from home_url) to find the prefix.
		// Multi-label subdomains (e.g. de.at.blog.wpmm.cc → de.at) require a
		// non-IP home host so the base domain length is known.
		$home = wp_parse_url( home_url(), PHP_URL_HOST );
		$home = $home ? strtolower( (string) $home ) : '';
		$home_is_ip = ( '' !== $home && (bool) filter_var( $home, FILTER_VALIDATE_IP ) );
		if ( '' !== $home && ! $home_is_ip && substr( $host, -strlen( $home ) ) === $home ) {
			$prefix = substr( $host, 0, strlen( $host ) - strlen( $home ) );
			$prefix = rtrim( $prefix, '.' );
			return '' === $prefix ? null : $prefix;
		}
		// Fallback when home is an IP / does not match: treat the last two labels
		// as the registrable domain (example.com style). Multi-label site domains
		// (blog.wpmm.cc) need a correct home_url — callers in lab tests should
		// filter home_url accordingly.
		$parts = array_values( array_filter( explode( '.', $host ), 'strlen' ) );
		$count = count( $parts );
		if ( $count < 3 ) {
			return null;
		}
		return implode( '.', array_slice( $parts, 0, $count - 2 ) );
	}

	/**
	 * Detect a virtual site using the subdomain URL form.
	 *
	 * Looks at `$_SERVER['HTTP_HOST']`, resolves the lang code via
	 * `detect_subdomain_lang()`, then searches the active virtual sites
	 * for one whose `lang` matches.
	 *
	 * @since 1.4.0
	 */
	private static function detect_virtual_site_by_subdomain(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized immediately below.
		$host_raw = isset( $_SERVER['HTTP_HOST'] ) ? wp_unslash( $_SERVER['HTTP_HOST'] ) : '';
		$host     = preg_replace( '/[^a-z0-9.\-:]/', '', strtolower( sanitize_text_field( (string) $host_raw ) ) );
		if ( '' === $host ) {
			return;
		}
		$lang = self::detect_subdomain_lang( $host );
		if ( null === $lang ) {
			return;
		}
		foreach ( self::get_cached_virtual_sites() as $site ) {
			if ( isset( $site['lang'] ) && (string) $site['lang'] === $lang ) {
				self::$current_virtual_site = $site;
				$GLOBALS['wptsall_current_virtual_site'] = $site;
				$relation = self::get_site_relation( $site['id'] );
				if ( $relation ) {
					self::$current_virtual_site['relation_id'] = $relation['id'];
					self::$current_virtual_site['relation']    = $relation;
					$GLOBALS['wptsall_current_virtual_site']   = self::$current_virtual_site;
				}
				wptsall_log_info(
					'hooks-router',
					'Virtual site detected via subdomain',
					array(
						'site_id' => $site['id'],
						'lang'    => $lang,
						'host'    => $host,
					)
				);
				return;
			}
		}
	}

	public static function get_current_virtual_site() {
		return self::$current_virtual_site ?? $GLOBALS['wptsall_current_virtual_site'] ?? null;
	}

	/**
	 * Check if currently on a virtual site
	 *
	 * @return bool
	 */
	public static function is_virtual_site() {
		return ! empty( self::get_current_virtual_site() );
	}

	/**
	 * Parse request for virtual site
	 *
	 * @param \WP $wp WordPress environment object.
	 * @return void
	 */
	public static function parse_request( $wp ) {
		$virtual_site = self::get_current_virtual_site();

		if ( ! $virtual_site ) {
			return;
		}

		// Get virtual path from query var (set by rewrite rule) or REQUEST_URI
		$virtual_path = isset( $wp->query_vars['wptsall_virtual_path'] ) ? $wp->query_vars['wptsall_virtual_path'] : '';

		if ( ! $virtual_path ) {
			// Fallback: parse from REQUEST_URI
			$request_uri = function_exists( 'wptsall_get_request_uri' ) ? wptsall_get_request_uri() : '';
			if ( self::is_param_mode() ) {
				$virtual_path = (string) strtok( (string) $request_uri, '?' );
			} else {
				$path_prefix = '/' . trim( $virtual_site['path_prefix'], '/' ) . '/';
				if ( 0 === strpos( (string) $request_uri, $path_prefix ) ) {
					$virtual_path = substr( (string) $request_uri, strlen( $path_prefix ) );
				} else {
					$virtual_path = (string) $request_uri;
				}
				$virtual_path = (string) strtok( $virtual_path, '?' );
			}
		}

		wptsall_log_info(
			'hooks-router',
			'parse_request called',
			array(
				'virtual_path' => $virtual_path,
				'site_id'      => $virtual_site['id'],
			)
		);

		// Try to resolve the path to a source object
		$resolved = self::resolve_path_to_object( $virtual_path, $virtual_site );

		if ( ! $resolved ) {
			// P2-2 fix: a matched virtual prefix with an unresolved path must be
			// a real 404. Without this, the main query runs with only the
			// wptsall_* vars and silently falls back to the virtual home (soft
			// 404 = duplicate home, bad SEO).
			if ( is_object( $wp ) && isset( $wp->query_vars ) && is_array( $wp->query_vars ) ) {
				$wp->query_vars['error'] = '404';
			}
			wptsall_log_warning(
				'hooks-router',
				'Virtual path unresolved — forcing 404',
				array(
					'virtual_path' => $virtual_path,
					'site_id'      => $virtual_site['id'],
				)
			);
		}

		if ( $resolved ) {
			if ( 'taxonomy' === ( $resolved['type'] ?? '' ) ) {
				$taxonomy = $resolved['subtype'] ?? '';
				$term_id  = (int) ( $resolved['source_id'] ?? 0 );
				if ( $taxonomy && $term_id ) {
					$resolved = Virtual_Site_Query_Switch::resolve_queried_object( $resolved, $virtual_site );
					$term     = get_term( (int) $resolved['queried_id'], $taxonomy );
					if ( ( ! $term || is_wp_error( $term ) ) && $term_id !== (int) $resolved['queried_id'] ) {
						$term = get_term( $term_id, $taxonomy );
					}
					if ( $term && ! is_wp_error( $term ) ) {
						$resolved['term_slug'] = $term->slug;
						$tax_obj               = get_taxonomy( $taxonomy );
						$resolved['tax_query_var'] = $tax_obj ? ( $tax_obj->query_var ?? '' ) : '';
					}
				}
			} elseif ( in_array( ( $resolved['type'] ?? '' ), array( 'home', 'paged' ), true ) ) {
				// No object to switch: 'paged' source_id is a page number.
				$resolved['queried_id'] = 0;
			} else {
				$resolved = Virtual_Site_Query_Switch::resolve_queried_object( $resolved, $virtual_site );
			}

			$applied = Virtual_Site_Query_Switch::bind_query_vars( $wp, $resolved );
			$resolved['queried_id'] = $applied['queried_id'];
			$resolved['switched']   = $applied['switched'];
			$GLOBALS['wptsall_resolved_object'] = $resolved;

			/**
			 * Fires after a virtual-site request is bound to a queried object.
			 *
			 * Third parties (SEO, cache, builders) should read query state only
			 * after this action — analogous to Polylang's `pll_language_defined`.
			 *
			 * @since 2.2.0
			 * @param array  $virtual_site Current virtual site row.
			 * @param array  $resolved     Resolved path → object payload.
			 * @param object $wp           WP environment.
			 */
			do_action( 'wptsall_virtual_site_resolved', $virtual_site, $resolved, $wp );

			wptsall_log_info(
				'hooks-router',
				'Request parsed to queried object',
				array(
					'virtual_path' => $virtual_path,
					'source_id'    => $applied['source_id'],
					'queried_id'   => $applied['queried_id'],
					'switched'     => $applied['switched'],
					'type'         => $applied['type'],
					'subtype'      => $applied['subtype'],
					'query_vars'   => $wp->query_vars,
				)
			);
		} else {
			wptsall_log_warning(
				'hooks-router',
				'Request path could not be resolved',
				array( 'virtual_path' => $virtual_path )
			);
		}
	}

	/**
	 * Capture the theme-resolved template before theme-compat plugins mutate it.
	 *
	 * @since 2.1.1
	 *
	 * @param string $template Template path.
	 * @return string
	 */
	public static function capture_template_include_original( $template ) {
		if ( is_string( $template ) && '' !== $template ) {
			self::$original_template_include = $template;
		}
		return $template;
	}

	/**
	 * Restore a usable template when theme-compat left template_include empty.
	 *
	 * Block themes have no page.php/index.php for classic locate_template()
	 * fallbacks. Plugins that return false/'' from template_include then produce
	 * HTTP 200 with an empty body. Prefer the captured original, then the block
	 * theme canvas.
	 *
	 * @since 2.1.1
	 *
	 * @param string|false $template Template path (or false/empty after filters).
	 * @return string|false
	 */
	public static function ensure_usable_template_include( $template ) {
		if ( is_string( $template ) && '' !== $template && is_readable( $template ) ) {
			return $template;
		}

		if ( is_string( self::$original_template_include ) && '' !== self::$original_template_include
			&& is_readable( self::$original_template_include ) ) {
			return self::$original_template_include;
		}

		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			$canvas = ABSPATH . WPINC . '/template-canvas.php';
			if ( is_readable( $canvas ) ) {
				return $canvas;
			}
		}

		return $template;
	}

	/**
	 * Template redirect for virtual site
	 *
	 * @return void
	 */
	public static function template_redirect() {
		$virtual_site = self::get_current_virtual_site();

		if ( ! $virtual_site ) {
			return;
		}

		// Check for routing errors (validation failures)
		$routing_error = self::get_routing_error();
		if ( $routing_error ) {
			// Display error page based on error code
			self::display_routing_error( $routing_error );
			exit;
		}

		global $wp, $wp_query;

		// Get resolved object from parse_request
		$resolved = $GLOBALS['wptsall_resolved_object'] ?? null;

		// Handle taxonomy term pages
		if ( $resolved && 'taxonomy' === $resolved['type'] ) {
			$taxonomy = $resolved['subtype'] ?? '';
			$term_id  = (int) ( $resolved['queried_id'] ?? $resolved['source_id'] ?? 0 );

			if ( $taxonomy && $term_id && $wp_query->is_404 ) {
				$term = get_term( $term_id, $taxonomy );

				if ( $term && ! is_wp_error( $term ) ) {
					// Reset wp_query
					$wp_query->init();

					// Query posts for this term
					$posts = get_posts(
						array(
							'post_type'      => 'any',
							'post_status'    => 'publish',
							'posts_per_page' => get_option( 'posts_per_page', 10 ),
							'tax_query'      => array(
								array(
									'taxonomy' => $taxonomy,
									'field'    => 'term_id',
									'terms'    => $term_id,
								),
							),
						)
					);

					// Set up taxonomy archive query
					$wp_query->is_tax          = true;
					$wp_query->is_archive      = true;
					$wp_query->is_404          = false;
					$wp_query->queried_object  = $term;
					$wp_query->queried_object_id = $term->term_id;
					$wp_query->posts           = $posts;
					$wp_query->post_count      = count( $posts );
					$wp_query->found_posts     = count( $posts );

					// Set specific taxonomy flags
					if ( 'category' === $taxonomy ) {
						$wp_query->is_category = true;
					} elseif ( 'post_tag' === $taxonomy ) {
						$wp_query->is_tag = true;
					}

					// Set first post as global if available
					if ( ! empty( $posts ) ) {
						$wp_query->post    = $posts[0];
						$GLOBALS['post']   = $posts[0];
						setup_postdata( $posts[0] );
					}

					wptsall_log_info(
						'hooks-router',
						'Manually set up WP_Query for taxonomy archive',
						array(
							'taxonomy'   => $taxonomy,
							'term_id'    => $term->term_id,
							'term_name'  => $term->name,
							'post_count' => count( $posts ),
						)
					);
				}
			}
		} else {
			// Handle post type single pages (original logic)
			$post_id = $wp->query_vars['p'] ?? 0;
			$queried = $resolved['queried_id'] ?? $post_id;
			if ( $queried ) {
				$post_id = $queried;
			}

			// Query-switch may leave is_404 set even when the shadow post exists.
			$shadow_post = $post_id ? get_post( $post_id ) : null;
			if ( $shadow_post && 'publish' === $shadow_post->post_status && ! empty( $wp_query->is_404 ) ) {
				$wp_query->is_404            = false;
				$wp_query->is_single         = true;
				$wp_query->is_singular       = true;
				$wp_query->queried_object    = $shadow_post;
				$wp_query->queried_object_id = $shadow_post->ID;
				$wp_query->posts             = array( $shadow_post );
				$wp_query->post              = $shadow_post;
				$wp_query->post_count        = 1;
				$wp_query->found_posts       = 1;
				$GLOBALS['post']             = $shadow_post;
				if ( function_exists( 'status_header' ) ) {
					status_header( 200 );
				}
				if ( function_exists( 'setup_postdata' ) ) {
					setup_postdata( $shadow_post );
				}
			}

			if ( $post_id && ! $wp_query->have_posts() ) {
				// WordPress failed to find the post, manually set up the query
				$post = get_post( $post_id );

				if ( $post && 'publish' === $post->post_status ) {
					// Reset wp_query
					$wp_query->init();

					// Set up post data
					$wp_query->is_single       = true;
					$wp_query->is_singular     = true;
					$wp_query->is_404          = false;
					$wp_query->queried_object  = $post;
					$wp_query->queried_object_id = $post->ID;
					$wp_query->posts           = array( $post );
					$wp_query->post            = $post;
					$wp_query->post_count      = 1;
					$wp_query->found_posts     = 1;

					// Set global $post
					$GLOBALS['post'] = $post;
					setup_postdata( $post );

					wptsall_log_info(
						'hooks-router',
						'Manually set up WP_Query for virtual content',
						array(
							'post_id'    => $post->ID,
							'post_title' => $post->post_title,
							'post_type'  => $post->post_type,
						)
					);
				}
			}
		}

		// Set locale if virtual site has a language
		if ( ! empty( $virtual_site['lang'] ) ) {
			if ( class_exists( '\\WPTSALL\\Core\\Language_Context' ) ) {
				\WPTSALL\Core\Language_Context::set_language( (string) $virtual_site['lang'] );
			}
			switch_to_locale( $virtual_site['lang'] );

			wptsall_log_debug(
				'hooks-router',
				'Locale switched for virtual site',
				array(
					'site_id' => $virtual_site['id'] ?? 0,
					'lang'    => $virtual_site['lang'],
				)
			);
		}
	}

	/**
	 * Exclude virtual site posts from normal WordPress queries.
	 *
	 * Posts stored in wp_posts with _wptsall_virtual_site_id meta should not
	 * appear in standard WordPress queries (homepage, archives, search) on
	 * the main site. This filter only applies when NOT serving a virtual site URL.
	 *
	 * @since 1.2.0
	 *
	 * @param \WP_Query $query WordPress query object.
	 * @return void
	 */
	public static function exclude_virtual_posts_from_main_query( $query ) {
		if ( is_admin() && ! ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) ) {
			return;
		}

		// P0-2 fix: skip during REST API requests to avoid interfering
		// with headless / app data fetching (WooCommerce Store API,
		// Elementor data endpoints, etc.).
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		// P0-2 fix: when Polylang is active and managing the current
		// language, its own query filtering already scopes posts. Adding
		// a meta_query here can break Polylang's language joins.
		if ( function_exists( 'pll_current_language' ) && pll_current_language() ) {
			return;
		}

		// Skip if no virtual sites are configured (no virtual posts possible).
		if ( empty( self::get_cached_virtual_sites() ) ) {
			return;
		}

		// Virtual-site request: include this site's shadow copies so archives
		// and plugin CPT loops (Woo product, etc.) read translated objects.
		if ( self::$current_virtual_site ) {
			if ( ! Virtual_Site_Query_Switch::should_filter_archive_query( $query ) ) {
				return;
			}
			Virtual_Site_Query_Switch::merge_meta_query(
				$query,
				Virtual_Site_Query_Switch::include_virtual_site_meta_query( self::$current_virtual_site )
			);
			return;
		}

		// Main site: exclude shadow copies so they do not leak into source queries.
		// Unless the caller explicitly queries the shadow markers: the
		// storage / sync layer deliberately finds records by
		// `_wptsall_virtual_site_id` / `_wptsall_source_post_id`, and a
		// NOT EXISTS clause would sabotage that explicit intent (0 rows).
		if ( self::meta_query_targets_virtual_site( $query ) ) {
			return;
		}
		Virtual_Site_Query_Switch::merge_meta_query(
			$query,
			Virtual_Site_Query_Switch::exclude_virtual_site_meta_query()
		);
	}

	/**
	 * Whether a WP_Query explicitly targets virtual-site marker meta keys.
	 *
	 * The main-site exclusion only guards against accidental shadow copy
	 * leakage; queries that deliberately reference the shadow markers
	 * (storage layer, sync tooling) must be honored as-is.
	 *
	 * @param \WP_Query $query Query object (or query_vars array).
	 * @return bool
	 */
	private static function meta_query_targets_virtual_site( $query ) {
		if ( is_object( $query ) && method_exists( $query, 'get' ) ) {
			$meta_query = $query->get( 'meta_query' );
		} elseif ( is_object( $query ) ) {
			$meta_query = isset( $query->query_vars['meta_query'] ) ? $query->query_vars['meta_query'] : array();
		} elseif ( is_array( $query ) ) {
			// Raw meta_query array (nested relation group passed recursively).
			$meta_query = $query;
		} else {
			return false;
		}
		if ( ! is_array( $meta_query ) ) {
			return false;
		}
		foreach ( $meta_query as $clause ) {
			if ( ! is_array( $clause ) ) {
				continue;
			}
			if ( isset( $clause['key'] ) ) {
				if ( '_wptsall_virtual_site_id' === $clause['key'] || '_wptsall_source_post_id' === $clause['key'] ) {
					return true;
				}
			}
			// Nested relation group (clause with 'relation' + sub-clauses).
			if ( isset( $clause['relation'] ) && self::meta_query_targets_virtual_site( $clause ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Real WP subsites are served by the target blog itself.
	 *
	 * @param string $target_site_type Relation target type.
	 * @return bool
	 */
	public static function requires_virtual_router_to_serve( $target_site_type ) {
		return Virtual_Site_Query_Switch::requires_virtual_router_to_serve( $target_site_type );
	}

	/**
	 * Strip translation markers from text for display.
	 *
	 * Translation markers like 【en】content【/en】 are stored in translated
	 * content but should not be shown to end users on the frontend.
	 *
	 * @since 1.2.0
	 *
	 * @param string $text Text that may contain translation markers.
	 * @return string Text with markers removed.
	 */
	private static function strip_translation_markers( $text ) {
		if ( empty( $text ) || ! is_string( $text ) ) {
			return $text;
		}

		// Remove opening markers: 【en】, 【zh】, 【ja_JP】 etc.
		$text = preg_replace( '/\xe3\x80\x90[a-zA-Z]{2,5}(?:_[a-zA-Z]{2,5})?\xe3\x80\x91/u', '', $text );

		// Remove closing markers: 【/en】, 【/zh】, 【/ja_JP】 etc.
		$text = preg_replace( '/\xe3\x80\x90\/[a-zA-Z]{2,5}(?:_[a-zA-Z]{2,5})?\xe3\x80\x91/u', '', $text );

		return $text;
	}

	/**
	 * Filter post title
	 *
	 * @param string $title   Post title.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	public static function filter_title( $title, $post_id = 0 ) {
		$virtual_site = self::get_current_virtual_site();

		if ( ! $virtual_site || ! $post_id ) {
			return $title;
		}

		// Get virtual content
		$content = self::get_virtual_content( $virtual_site, 'post_type', get_post_type( $post_id ), $post_id );

		if ( $content && isset( $content['post']['post_title'] ) ) {
			return self::strip_translation_markers( $content['post']['post_title'] );
		}

		return $title;
	}

	/**
	 * Filter post content
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public static function filter_content( $content ) {
		$virtual_site = self::get_current_virtual_site();

		if ( ! $virtual_site ) {
			return $content;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return $content;
		}

		// Get virtual content
		$virtual_content = self::get_virtual_content( $virtual_site, 'post_type', get_post_type( $post_id ), $post_id );

		if ( $virtual_content && isset( $virtual_content['post']['post_content'] ) ) {
			return self::strip_translation_markers( $virtual_content['post']['post_content'] );
		}

		return $content;
	}

	/**
	 * Filter post excerpt
	 *
	 * @param string $excerpt Post excerpt.
	 * @return string
	 */
	public static function filter_excerpt( $excerpt ) {
		$virtual_site = self::get_current_virtual_site();

		if ( ! $virtual_site ) {
			return $excerpt;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return $excerpt;
		}

		// Get virtual content
		$content = self::get_virtual_content( $virtual_site, 'post_type', get_post_type( $post_id ), $post_id );

		if ( $content && isset( $content['post']['post_excerpt'] ) ) {
			return self::strip_translation_markers( $content['post']['post_excerpt'] );
		}

		return $excerpt;
	}

	/**
	 * Filter single post title
	 *
	 * @param string   $title Post title.
	 * @param \WP_Post $post  Post object.
	 * @return string
	 */
	public static function filter_single_title( $title, $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return $title;
		}

		return self::filter_title( $title, $post->ID );
	}

	/**
	 * Rewrite post link
	 *
	 * @param string   $permalink Post permalink.
	 * @param \WP_Post $post      Post object.
	 * @param bool     $leavename Whether to leave the post name.
	 * @return string
	 */
	public static function rewrite_post_link( $permalink, $post = null, $leavename = null ) {
		return self::add_virtual_prefix( $permalink, $post );
	}

	/**
	 * Rewrite page link
	 *
	 * @param string $link    Page link.
	 * @param int    $post_id Post ID.
	 * @param bool   $sample  Is sample.
	 * @return string
	 */
	public static function rewrite_page_link( $link, $post_id = 0, $sample = null ) {
		$post = $post_id ? get_post( $post_id ) : null;
		return self::add_virtual_prefix( $link, $post );
	}

	/**
	 * Rewrite post type link
	 *
	 * @param string   $post_link Post link.
	 * @param \WP_Post $post      Post object.
	 * @param bool     $leavename Whether to leave the post name.
	 * @param bool     $sample    Is sample.
	 * @return string
	 */
	public static function rewrite_post_type_link( $post_link, $post = null, $leavename = null, $sample = null ) {
		return self::add_virtual_prefix( $post_link, $post );
	}

	/**
	 * Rewrite term link
	 *
	 * When the caller passes a source term under a VS request, remap to the
	 * shadow term via term_mappings (relation-scoped) before prefixing.
	 *
	 * @param string   $termlink Term link.
	 * @param \WP_Term $term     Term object.
	 * @param string   $taxonomy Taxonomy name.
	 * @return string
	 */
	public static function rewrite_term_link( $termlink, $term = null, $taxonomy = null ) {
		$vs = self::get_current_virtual_site();
		if ( $vs && $term ) {
			$term_obj = is_object( $term ) ? $term : null;
			$term_id  = $term_obj ? (int) $term_obj->term_id : (int) $term;
			$tax      = $taxonomy ? (string) $taxonomy : ( $term_obj ? (string) $term_obj->taxonomy : '' );
			if ( $term_id > 0 ) {
				$own_vs = (string) get_term_meta( $term_id, '_wptsall_virtual_site_id', true );
				if ( '' === $own_vs ) {
					$relation_id = (int) ( $vs['relation_id'] ?? 0 );
					if ( $relation_id > 0 && class_exists( '\\WPTSALL\\Models\\Services\\Term_Mapping_Service' ) ) {
						$mapped = \WPTSALL\Models\Services\Term_Mapping_Service::get_mapped_id( $relation_id, $term_id );
						if ( $mapped && (int) $mapped !== $term_id ) {
							remove_filter( 'term_link', array( __CLASS__, 'rewrite_term_link' ), 20 );
							$shadow_link = get_term_link( (int) $mapped, $tax ? $tax : '' );
							add_filter( 'term_link', array( __CLASS__, 'rewrite_term_link' ), 20, 3 );
							if ( ! is_wp_error( $shadow_link ) && is_string( $shadow_link ) && '' !== $shadow_link ) {
								$termlink = $shadow_link;
							}
						}
					}
				}
			}
		}
		return self::add_virtual_prefix( $termlink );
	}

	/**
	 * Rewrite attachment permalink under the current virtual site.
	 *
	 * @param string $link     Attachment URL.
	 * @param int    $post_id  Attachment post ID.
	 * @return string
	 */
	public static function rewrite_attachment_link( $link, $post_id = 0 ) {
		$post = $post_id ? get_post( (int) $post_id ) : null;
		return self::add_virtual_prefix( $link, $post );
	}

	/**
	 * Rewrite CPT archive URL under the current virtual site.
	 *
	 * @param string $link      Archive URL.
	 * @param string $post_type Post type.
	 * @return string
	 */
	public static function rewrite_post_type_archive_link( $link, $post_type = '' ) {
		unset( $post_type );
		return self::add_virtual_prefix( $link );
	}

	/**
	 * Add virtual site prefix to URL
	 *
	 * @param string        $url  Original URL.
	 * @param \WP_Post|null $post Post object (optional).
	 * @return string
	 */
	private static function add_virtual_prefix( $url, $post = null ) {
		$virtual_site = null;

		// First, check if the post itself is virtual content
		if ( $post && isset( $post->ID ) ) {
			$virtual_site_id = \WPTSALL\Sites\Services\Translation_Identity::raw_meta( (int) $post->ID, \WPTSALL\Sites\Services\Translation_Identity::META_VIRTUAL_SITE_ID );
			if ( $virtual_site_id ) {
				$virtual_site = Virtual_Site_Service::get( $virtual_site_id );
			}
		}

		// Fallback to current request context
		if ( ! $virtual_site ) {
			$virtual_site = self::get_current_virtual_site();
		}

		if ( ! $virtual_site ) {
			return $url;
		}

		$path_prefix = '/' . trim( $virtual_site['path_prefix'], '/' );

		// Parse URL
		$parsed = wp_parse_url( $url );

		if ( ! $parsed || empty( $parsed['path'] ) ) {
			return $url;
		}

		// Check if already has prefix
		if ( strpos( $parsed['path'], $path_prefix ) === 0 ) {
			return $url;
		}

		// v0.6.0: Check if URL structure transformation is needed
		$source_structure = URL_Transformer::get_source_permalink_structure(
			$virtual_site['blog_source_site'] ?? $virtual_site['source_blog_id'] ?? 0
		);
		$target_structure = URL_Transformer::get_effective_permalink_structure( $virtual_site );

		// Normalize structures for comparison
		$source_structure = URL_Transformer::normalize_structure( $source_structure );
		$target_structure = URL_Transformer::normalize_structure( $target_structure );

		$new_path = $parsed['path'];

		// Transform URL if permalink structures differ
		if ( $source_structure !== $target_structure && $post && isset( $post->ID ) ) {
			// Build content info from post for URL generation
			$content = array(
				'type'    => 'post',
				'slug'    => $post->post_name,
				'post_id' => $post->ID,
			);

			// Get date info if needed
			if ( strpos( $target_structure, '%year%' ) !== false ||
				strpos( $target_structure, '%monthnum%' ) !== false ||
				strpos( $target_structure, '%day%' ) !== false ) {
				$post_date = get_the_date( 'Y-m-d', $post );
				$date_parts = explode( '-', $post_date );
				$content['year'] = $date_parts[0] ?? '';
				$content['month'] = $date_parts[1] ?? '';
				$content['day'] = $date_parts[2] ?? '';
			}

			// Get category if needed
			if ( strpos( $target_structure, '%category%' ) !== false ) {
				$categories = get_the_category( $post->ID );
				if ( ! empty( $categories ) ) {
					$content['category'] = $categories[0]->slug;
				}
			}

			// Generate new URL path with target structure
			$transformed = URL_Transformer::generate_url( $content, $target_structure, $virtual_site['path_prefix'] );
			if ( $transformed ) {
				$new_path = $transformed;
			} else {
				// Fallback: just add prefix
				$new_path = $path_prefix . $parsed['path'];
			}
		} else {
			// Same structure or no post - just add prefix
			$new_path = $path_prefix . $parsed['path'];
		}

		// Rebuild URL
		$new_url = '';

		if ( ! empty( $parsed['scheme'] ) ) {
			$new_url .= $parsed['scheme'] . '://';
		}

		if ( ! empty( $parsed['host'] ) ) {
			$new_url .= $parsed['host'];
		}

		// Preserve the port (non-standard ports on dev/staging/lab sites).
		if ( ! empty( $parsed['port'] ) ) {
			$new_url .= ':' . $parsed['port'];
		}

		$new_url .= $new_path;

		if ( ! empty( $parsed['query'] ) ) {
			$new_url .= '?' . $parsed['query'];
		}

		if ( ! empty( $parsed['fragment'] ) ) {
			$new_url .= '#' . $parsed['fragment'];
		}

		return $new_url;
	}

	/**
	 * Get virtual content from wp_posts + meta storage.
	 *
	 * Virtual sites read from wp_posts rows marked with _wptsall_virtual_site_id
	 * and _wptsall_source_post_id post meta, matching the write path in tasks.php.
	 *
	 * @since 0.8.0
	 * @since 1.2.0 Reads from wp_posts + meta instead of virtual_site_content table.
	 *
	 * @param array  $virtual_site Virtual site data.
	 * @param string $object_type  Object type (post_type/taxonomy).
	 * @param string $subtype      Subtype (post type or taxonomy name).
	 * @param int    $source_id    Source object ID.
	 * @return array|null
	 */
	private static function get_virtual_content( $virtual_site, $object_type, $subtype, $source_id ) {
		// Check per-request static cache first (fastest).
		$cache_key = $virtual_site['id'] . '_' . $object_type . '_' . $subtype . '_' . $source_id;
		if ( isset( self::$content_cache[ $cache_key ] ) ) {
			return self::$content_cache[ $cache_key ];
		}

		// P1-3 fix: check persistent object cache (redis/memcached/disk).
		// Avoids repeated DB queries across page loads. TTL: 5 min.
		$oc_key = 'wptsall_vc_' . $cache_key;
		$cached = wp_cache_get( $oc_key, 'wptsall_virtual_content' );
		if ( false !== $cached ) {
			self::$content_cache[ $cache_key ] = $cached;
			return $cached;
		}

		$content = self::get_virtual_content_from_legacy_table( $virtual_site, $object_type, $subtype, $source_id );

		// Cache result in both static and persistent cache.
		self::$content_cache[ $cache_key ] = $content;
		wp_cache_set( $oc_key, $content, 'wptsall_virtual_content', 300 );

		return $content;
	}

	/**
	 * Build list of virtual site IDs to try for queries.
	 *
	 * @since 0.8.0
	 *
	 * @param array $virtual_site Virtual site data.
	 * @return array List of virtual site IDs to try.
	 */
	private static function build_virtual_site_ids( $virtual_site ) {
		$ids_to_try = array();

		// Format 1: v_{id}.
		$formatted_id = \WPTSALL\Sites\Validators\Site_Relation_Validator::format_virtual_site_id( $virtual_site['id'] );
		$ids_to_try[] = $formatted_id;

		// Format 2: relation_id directly.
		if ( isset( $virtual_site['relation_id'] ) && ! in_array( $virtual_site['relation_id'], $ids_to_try, true ) ) {
			$ids_to_try[] = $virtual_site['relation_id'];
		}

		// Format 3: v_{language_code} from site_language (en_US → v_en).
		if ( ! empty( $virtual_site['lang'] ) ) {
			$lang_parts    = explode( '_', $virtual_site['lang'] );
			$lang_code     = strtolower( $lang_parts[0] );
			$lang_based_id = 'v_' . $lang_code;
			if ( ! in_array( $lang_based_id, $ids_to_try, true ) ) {
				$ids_to_try[] = $lang_based_id;
			}
		}

		// Format 4: Raw ID as string.
		$raw_id = (string) $virtual_site['id'];
		if ( ! in_array( $raw_id, $ids_to_try, true ) ) {
			$ids_to_try[] = $raw_id;
		}

		// Format 5: v_{path_prefix} and raw path_prefix (form-created relations normalize to v_{path_prefix}).
		$path_prefix = $virtual_site['path_prefix'] ?? '';
		if ( '' !== $path_prefix ) {
			$v_path_prefix = 'v_' . $path_prefix;
			if ( ! in_array( $v_path_prefix, $ids_to_try, true ) ) {
				$ids_to_try[] = $v_path_prefix;
			}
			if ( ! in_array( $path_prefix, $ids_to_try, true ) ) {
				$ids_to_try[] = $path_prefix;
			}
		}

		return $ids_to_try;
	}

	/**
	 * Get virtual content from wp_posts + postmeta storage.
	 *
	 * Delegates to Virtual_Site_Service::get_virtual_post_content() which handles
	 * multi-format ID matching and two-pass lookup (with/without post_type).
	 *
	 * @since 0.8.0
	 * @since 1.2.0 Rewritten to read from wp_posts + meta instead of virtual_site_content table.
	 * @since 1.5.0 Refactored from direct SQL to Service layer delegation.
	 *
	 * @param array  $virtual_site Virtual site data.
	 * @param string $object_type  Object type.
	 * @param string $subtype      Subtype.
	 * @param int    $source_id    Source object ID.
	 * @return array|null
	 */
	private static function get_virtual_content_from_legacy_table( $virtual_site, $object_type, $subtype, $source_id ) {
		// Only post_type objects are stored in wp_posts.
		if ( 'post_type' !== $object_type ) {
			return null;
		}

		// Build list of virtual site IDs to try (multiple formats).
		$site_ids_to_try = self::build_virtual_site_ids( $virtual_site );

		$content = Virtual_Site_Service::get_virtual_post_content( $site_ids_to_try, (int) $source_id, $subtype );

		if ( $content ) {
			wptsall_log_debug(
				'hooks-router',
				'Found virtual content in wp_posts + meta',
				array(
					'source_id'   => $source_id,
					'object_type' => $object_type,
					'subtype'     => $subtype,
				)
			);
		}

		return $content;
	}

	/**
	 * Build taxonomy URL slug to name mapping dynamically
	 *
	 * Replaces hardcoded taxonomy mapping with dynamic discovery.
	 * Uses static cache to avoid rebuilding on every request.
	 *
	 * @since 0.9.1
	 * @return array ['url_slug' => 'taxonomy_name', ...]
	 */
	private static function build_taxonomy_map() {
		// Return cached result if available
		if ( null !== self::$taxonomy_map_cache ) {
			return self::$taxonomy_map_cache;
		}

		$tax_map = array();

		// Get all public taxonomies
		$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );

		foreach ( $taxonomies as $taxonomy ) {
			// Add taxonomy name itself as a key
			$tax_map[ $taxonomy->name ] = $taxonomy->name;

			// Add rewrite slug if different from name (may be multi-segment).
			if ( ! empty( $taxonomy->rewrite['slug'] ) && $taxonomy->rewrite['slug'] !== $taxonomy->name ) {
				$slug = trim( (string) $taxonomy->rewrite['slug'], '/' );
				if ( '' !== $slug ) {
					$tax_map[ $slug ] = $taxonomy->name;
				}
			}
		}

		/**
		 * Filter the taxonomy URL slug mapping
		 *
		 * Allows plugins to add custom taxonomy slug mappings.
		 *
		 * @since 0.9.1
		 * @param array $tax_map Taxonomy mapping ['url_slug' => 'taxonomy_name', ...]
		 */
		self::$taxonomy_map_cache = apply_filters( 'wptsall_taxonomy_url_map', $tax_map );

		wptsall_log_debug(
			'hooks-router',
			'Built taxonomy URL mapping dynamically',
			array(
				'taxonomy_count' => count( self::$taxonomy_map_cache ),
				'mappings'       => self::$taxonomy_map_cache,
			)
		);

		return self::$taxonomy_map_cache;
	}

	/**
	 * Match the longest taxonomy rewrite prefix against a path.
	 *
	 * Supports multi-segment slugs such as EDD `downloads/category/{term}`.
	 *
	 * @since 2.2.0
	 * @param string $path Path without VS prefix.
	 * @return array{taxonomy:string,term_slug:string}|null
	 */
	public static function match_taxonomy_path( string $path ): ?array {
		$path = trim( $path, '/' );
		if ( '' === $path ) {
			return null;
		}
		$tax_map = self::build_taxonomy_map();
		$keys    = array_keys( $tax_map );
		usort(
			$keys,
			static function ( $a, $b ) {
				$cmp = substr_count( (string) $b, '/' ) <=> substr_count( (string) $a, '/' );
				return 0 !== $cmp ? $cmp : ( strlen( (string) $b ) <=> strlen( (string) $a ) );
			}
		);
		foreach ( $keys as $slug_key ) {
			$slug = trim( (string) $slug_key, '/' );
			if ( '' === $slug || $path === $slug ) {
				continue;
			}
			if ( 0 !== strpos( $path, $slug . '/' ) ) {
				continue;
			}
			$rest = trim( (string) substr( $path, strlen( $slug ) + 1 ), '/' );
			if ( '' === $rest ) {
				continue;
			}
			$parts = explode( '/', $rest );
			$term  = (string) end( $parts );
			$tax   = (string) ( $tax_map[ $slug_key ] ?? '' );
			if ( '' === $tax || '' === $term ) {
				continue;
			}
			return array(
				'taxonomy'  => $tax,
				'term_slug' => $term,
			);
		}
		return null;
	}

	/**
	 * Resolve URL path to source object
	 *
	 * @param string $path         URL path.
	 * @param array  $virtual_site Virtual site data.
	 * @return array|null
	 */
	private static function resolve_path_to_object( $path, $virtual_site ) {
		$path = trim( $path, '/' );

		// P2-2: strip trailing /page/N used for home/archive pagination so the
		// remaining path resolves and the page number is re-applied in
		// bind_query_vars (main query stays an archive, not a 404).
		$paged = 0;
		if ( preg_match( '#^(.+)/page/([0-9]+)/?$#i', $path, $pm ) ) {
			$path  = $pm[1];
			$paged = (int) $pm[2];
		} elseif ( preg_match( '#^page/([0-9]+)/?$#i', $path, $pm ) ) {
			$path  = '';
			$paged = (int) $pm[1];
		}
		$with_paged = function ( array $resolved ) use ( $paged ) {
			if ( $paged > 0 ) {
				$resolved['paged'] = $paged;
			}
			return $resolved;
		};

		if ( '' === $path ) {
			// Virtual-site home (or paginated home): keep request in virtual
			// context without a 404. A paged number is preserved so the main
			// query paginates the home archive.
			return $with_paged( array(
				'type'      => $paged ? 'paged' : 'home',
				'subtype'   => '',
				'source_id' => $paged,
			) );
		}

		// Step 1: Check if site relation exists for this virtual site
		if ( ! self::check_site_relation_exists( $virtual_site ) ) {
			self::$routing_error = array(
				'code'    => 'site_relation_not_found',
				'message' => __( 'Site relation does not exist', 'wpmmcc-ats' ),
				'details' => array(
					'virtual_site_id' => $virtual_site['id'],
					'virtual_site_path' => $virtual_site['path_prefix'],
				),
			);
			wptsall_log_warning(
				'hooks-router',
				'Site relation not found for virtual site',
				self::$routing_error['details']
			);
			return null;
		}

		// v0.6.0: Use URL_Transformer to parse the path based on virtual site's permalink structure
		$target_structure = URL_Transformer::get_effective_permalink_structure( $virtual_site );
		$parsed_content = null;
		$url_parsed_by_structure = false; // Flag whether URL was parsed via permalink structure

		if ( ! empty( $target_structure ) && 'plain' !== $target_structure ) {
			$parsed_content = URL_Transformer::parse_url( '/' . $path, $target_structure );
			// If slug was parsed, mark as parsed via structure
			if ( $parsed_content && ! empty( $parsed_content['slug'] ) ) {
				$url_parsed_by_structure = true;
			}

			if ( $parsed_content && isset( $parsed_content['post_id'] ) ) {
				// Direct lookup by post ID via Service layer.
				$ids_for_lookup = self::build_virtual_site_ids( $virtual_site );
				$virtual_post   = Virtual_Site_Service::find_virtual_post_by_id(
					$ids_for_lookup,
					(int) $parsed_content['post_id']
				);

				if ( $virtual_post ) {
					wptsall_log_debug(
						'hooks-router',
						'Found virtual content by parsed post_id',
						array(
							'post_id'   => $virtual_post->ID,
							'post_type' => $virtual_post->post_type,
							'path'      => $path,
							'structure' => $target_structure,
						)
					);

					return $with_paged( array(
						'type'       => 'post_type',
						'subtype'    => $virtual_post->post_type,
						'source_id'  => $virtual_post->ID,
						'virtual_id' => $virtual_post->ID,
					) );
				}
			}

			wptsall_log_debug(
				'hooks-router',
				'URL parsed with structure',
				array(
					'path'       => $path,
					'structure'  => $target_structure,
					'parsed'     => $parsed_content,
				)
			);
		}

		// Step 2: Analyze path structure to extract post type and slug
		$path_parts = explode( '/', $path );
		$potential_post_type = $path_parts[0] ?? '';

		// Use parsed slug if available, otherwise use last path segment
		$post_name = ( $parsed_content && isset( $parsed_content['slug'] ) )
			? $parsed_content['slug']
			: end( $path_parts );

		// Check if first part is a registered post type
		$post_types = get_post_types( array( 'public' => true ), 'names' );
		$detected_post_type = null;

		if ( in_array( $potential_post_type, $post_types, true ) ) {
			$detected_post_type = $potential_post_type;

			wptsall_log_debug(
				'hooks-router',
				'Detected post type from path',
				array(
					'path'           => $path,
					'detected_type'  => $detected_post_type,
					'post_name'      => $post_name,
				)
			);
		}

		// Step 3: Query virtual_site_content table first (primary storage for synced content)
		$source_blog_id  = ! empty( $virtual_site['source_blog_id'] ) ? (int) $virtual_site['source_blog_id'] : 1;
		$site_ids_to_try = self::build_virtual_site_ids( $virtual_site );

		$virtual_content_row = Virtual_Site_Service::find_content_by_slug(
			$site_ids_to_try,
			$source_blog_id,
			$post_name,
			$detected_post_type ?? ''
		);

		// If found in virtual_site_content table, return the resolved object
		if ( $virtual_content_row ) {
			wptsall_log_debug(
				'hooks-router',
				'Found virtual content in virtual_site_content table',
				array(
					'source_object_id' => $virtual_content_row->source_object_id,
					'subtype'          => $virtual_content_row->subtype,
					'post_name'        => $post_name,
					'path'             => $path,
				)
			);

			// Check if model exists for this post type
			$model_id = self::check_model_exists( 'post_type', $virtual_content_row->subtype );
			if ( ! $model_id ) {
				self::$routing_error = array(
					'code'    => 'model_not_found',
					'message' => __( 'Model does not exist', 'wpmmcc-ats' ),
					'details' => array(
						'object_type' => 'post_type',
						'object_name' => $virtual_content_row->subtype,
						'path'        => $path,
					),
				);
				wptsall_log_warning(
					'hooks-router',
					'Model not found for post type (virtual_site_content)',
					self::$routing_error['details']
				);
				return null;
			}

			return $with_paged( array(
				'type'      => 'post_type',
				'subtype'   => $virtual_content_row->subtype,
				'source_id' => (int) $virtual_content_row->source_object_id,
			) );
		}

		// Step 4: Fallback to wp_posts with _wptsall_virtual_site_id meta via Service layer.
		$ids_to_try   = self::build_virtual_site_ids( $virtual_site );
		$virtual_post = Virtual_Site_Service::find_virtual_post_by_slug(
			$ids_to_try,
			$post_name,
			$detected_post_type ?? ''
		);

		if ( $virtual_post ) {
			wptsall_log_debug(
				'hooks-router',
				'Found virtual content by post_name',
				array(
					'post_id'   => $virtual_post->ID,
					'post_name' => $post_name,
					'post_type' => $virtual_post->post_type,
				)
			);

			// A shadow copy already exists. Do not 404 on missing model/rule —
			// generic CPT delivery is driven by the queried object, not per-plugin packs.
			$model_id = self::check_model_exists( 'post_type', $virtual_post->post_type );
			if ( ! $model_id ) {
				wptsall_log_warning(
					'hooks-router',
					'Model not found for post type; serving existing shadow copy',
					array(
						'object_type' => 'post_type',
						'object_name' => $virtual_post->post_type,
						'path'        => $path,
						'post_id'     => $virtual_post->ID,
					)
				);
			} elseif ( ! self::check_object_rule_registered( $model_id, 'post', $virtual_post->post_type, 'single' ) ) {
				wptsall_log_warning(
					'hooks-router',
					'Rule not registered; serving existing shadow copy',
					array(
						'model_id'    => $model_id,
						'object_type' => 'post_type',
						'object_name' => $virtual_post->post_type,
						'path'        => $path,
						'post_id'     => $virtual_post->ID,
					)
				);
			}

			return $with_paged( array(
				'type'      => 'post_type',
				'subtype'   => $virtual_post->post_type,
				'source_id' => $virtual_post->ID,
			) );
		}

		// Fallback: Try to get post by path (for pages and standard posts)
		$post = get_page_by_path( $path, OBJECT, 'any' );

		if ( $post ) {
			// Step 2: Check if model exists for this post type
			$model_id = self::check_model_exists( 'post_type', $post->post_type );
			if ( ! $model_id ) {
				self::$routing_error = array(
					'code'    => 'model_not_found',
					'message' => __( 'Model does not exist', 'wpmmcc-ats' ),
					'details' => array(
						'object_type' => 'post_type',
						'object_name' => $post->post_type,
						'path'        => $path,
					),
				);
				wptsall_log_warning(
					'hooks-router',
					'Model not found for post type (fallback)',
					self::$routing_error['details']
				);
				return null;
			}

			// Step 3: Ensure the object is registered in translation rules (see note above).
			if ( ! self::check_object_rule_registered( $model_id, 'post', $post->post_type, 'single' ) ) {
				self::$routing_error = array(
					'code'    => 'url_pattern_not_registered',
					'message' => __( 'Rule not registered', 'wpmmcc-ats' ),
					'details' => array(
						'model_id'    => $model_id,
						'object_type' => 'post_type',
						'object_name' => $post->post_type,
						'path'        => $path,
					),
				);
				wptsall_log_warning(
					'hooks-router',
					'Rule not registered (fallback)',
					self::$routing_error['details']
				);
				return null;
			}

			return $with_paged( array(
				'type'      => 'post_type',
				'subtype'   => $post->post_type,
				'source_id' => $post->ID,
			) );
		}

		// Step 4: Try to find taxonomy term (supports multi-segment rewrite slugs).
		$term_slug         = basename( $path );
		$term_slug_decoded = urldecode( $term_slug );
		$detected_taxonomy = null;

		$matched = self::match_taxonomy_path( $path );
		if ( $matched ) {
			$detected_taxonomy = $matched['taxonomy'];
			$term_slug         = $matched['term_slug'];
			$term_slug_decoded = urldecode( $term_slug );
			wptsall_log_debug(
				'hooks-router',
				'Detected taxonomy from path',
				array(
					'path'               => $path,
					'detected_taxonomy'  => $detected_taxonomy,
					'term_slug'          => $term_slug,
					'term_slug_decoded'  => $term_slug_decoded,
				)
			);
		} elseif ( count( $path_parts ) >= 2 ) {
			// Legacy single-segment fallback.
			$potential_taxonomy = $path_parts[0];
			$tax_map            = self::build_taxonomy_map();
			if ( isset( $tax_map[ $potential_taxonomy ] ) ) {
				$detected_taxonomy = $tax_map[ $potential_taxonomy ];
			} else {
				$taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
				if ( in_array( $potential_taxonomy, $taxonomies, true ) ) {
					$detected_taxonomy = $potential_taxonomy;
				}
			}
		}

		// Query term
		$term = null;
		if ( $detected_taxonomy ) {
			// Try to find by original slug first (may be URL-encoded in database)
			$term = get_term_by( 'slug', $term_slug, $detected_taxonomy );

			// If not found, try with decoded slug
			if ( ! $term ) {
				$term = get_term_by( 'slug', $term_slug_decoded, $detected_taxonomy );
			}

			// If still not found, try by name
			if ( ! $term ) {
				$term = get_term_by( 'name', $term_slug_decoded, $detected_taxonomy );
			}
		}

		if ( $term && ! is_wp_error( $term ) ) {
			// Soft gate: prefer model/rule when present, but still serve public tax archives
			// on virtual sites (EDD/Woo taxonomies often lack a dedicated model row).
			$model_id = self::check_model_exists( 'taxonomy', $term->taxonomy );
			$has_rule = $model_id ? self::check_object_rule_registered( $model_id, 'term', $term->taxonomy, 'taxonomy' ) : false;
			/**
			 * Allow taxonomy archive resolution without model/rule.
			 *
			 * @since 2.2.0
			 * @param bool     $allow Whether to allow.
			 * @param \WP_Term $term  Term.
			 * @param array    $vs    Virtual site.
			 */
			$allow_without_model = (bool) apply_filters( 'wptsall_allow_taxonomy_without_model', true, $term, $virtual_site );
			if ( ( ! $model_id || ! $has_rule ) && ! $allow_without_model ) {
				self::$routing_error = array(
					'code'    => $model_id ? 'url_pattern_not_registered' : 'model_not_found',
					'message' => $model_id ? __( 'Rule not registered', 'wpmmcc-ats' ) : __( 'Model does not exist', 'wpmmcc-ats' ),
					'details' => array(
						'object_type' => 'taxonomy',
						'object_name' => $term->taxonomy,
						'path'        => $path,
					),
				);
				return null;
			}

			return $with_paged( array(
				'type'      => 'taxonomy',
				'subtype'   => $term->taxonomy,
				'source_id' => $term->term_id,
			) );
		}

		// Try url_to_postid
		$url     = home_url( '/' . $path );
		$post_id = url_to_postid( $url );

		if ( $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				return null;
			}

			// Step 2: Check if model exists for this post type
			$model_id = self::check_model_exists( 'post_type', $post->post_type );
			if ( ! $model_id ) {
				self::$routing_error = array(
					'code'    => 'model_not_found',
					'message' => __( 'Model does not exist', 'wpmmcc-ats' ),
					'details' => array(
						'object_type' => 'post_type',
						'object_name' => $post->post_type,
						'path'        => $path,
					),
				);
				wptsall_log_warning(
					'hooks-router',
					'Model not found for post type (url_to_postid)',
					self::$routing_error['details']
				);
				return null;
			}

			// Step 3: Ensure the object is registered in translation rules (see note above).
			if ( ! self::check_object_rule_registered( $model_id, 'post', $post->post_type, 'single' ) ) {
				self::$routing_error = array(
					'code'    => 'url_pattern_not_registered',
					'message' => __( 'Rule not registered', 'wpmmcc-ats' ),
					'details' => array(
						'model_id'    => $model_id,
						'object_type' => 'post_type',
						'object_name' => $post->post_type,
						'path'        => $path,
					),
				);
				wptsall_log_warning(
					'hooks-router',
					'Rule not registered (url_to_postid)',
					self::$routing_error['details']
				);
				return null;
			}

			return $with_paged( array(
				'type'      => 'post_type',
				'subtype'   => $post->post_type,
				'source_id' => $post->ID,
			) );
		}

		return null;
	}

	/**
	 * Get site relation for virtual site
	 *
	 * @param int|string $virtual_site_id Virtual site ID.
	 * @return array|null
	 */
	private static function get_site_relation( $virtual_site_id ) {
		// Build list of IDs to try (same logic as check_site_relation_exists).
		$ids_to_try = array();
		// Format 1: v_{id}
		$ids_to_try[] = \WPTSALL\Sites\Validators\Site_Relation_Validator::format_virtual_site_id( $virtual_site_id );

		// Format 2: v_{lang_code} from current virtual site context.
		$current = self::$current_virtual_site ?? null;
		if ( $current && ! empty( $current['lang'] ) ) {
			$lang_parts = explode( '_', $current['lang'] );
			$lang_code = strtolower( $lang_parts[0] );
			$lang_based_id = 'v_' . $lang_code;
			if ( ! in_array( $lang_based_id, $ids_to_try, true ) ) {
				$ids_to_try[] = $lang_based_id;
			}
		}

		// Format 3: v_{path_prefix} and raw path_prefix.
		$path_prefix = '';
		if ( $current ) {
			$path_prefix = $current['path_prefix'] ?? $current['site_path'] ?? '';
			$path_prefix = trim( (string) $path_prefix, '/' );
		}
		if ( '' !== $path_prefix ) {
			$v_path_prefix = 'v_' . $path_prefix;
			if ( ! in_array( $v_path_prefix, $ids_to_try, true ) ) {
				$ids_to_try[] = $v_path_prefix;
			}
			if ( ! in_array( $path_prefix, $ids_to_try, true ) ) {
				$ids_to_try[] = $path_prefix;
			}
		}

		// Get all active site relations using Service layer
		$all_relations = \WPTSALL\Sites\Services\Site_Relation_Service::get_all_relations(
			array( 'status' => 'active' )
		);

		// Find relations where target is this virtual site (try all ID formats)
		foreach ( $all_relations as $relation ) {
			if ( $relation['target_site_type'] === 'virtual' &&
				 in_array( $relation['target_site_id'], $ids_to_try, true ) ) {
				wptsall_log_info(
					'hooks-router',
					'Site relation found via Service',
					array(
						'relation_id'     => $relation['id'],
						'template'        => $relation['template'],
						'virtual_site_id' => $virtual_site_id,
						'ids_tried'       => $ids_to_try,
					)
				);
				return $relation;
			}
		}

		// Log if not found
		wptsall_log_warning(
			'hooks-router',
			'Site relation not found',
			array(
				'virtual_site_id' => $virtual_site_id,
				'ids_tried'       => $ids_to_try,
				'relations_count' => count( $all_relations ),
			)
		);

		return null;
	}

	/**
	 * Get virtual content helper function
	 *
	 * @param int    $site_id     Site/relation ID.
	 * @param string $object_type Object type.
	 * @param string $subtype     Subtype.
	 * @param int    $source_id   Source ID.
	 * @return array|null
	 */
	public static function get_content( $site_id, $object_type, $subtype, $source_id ) {
		$virtual_site = array(
			'id'          => $site_id,
			'relation_id' => $site_id,
		);

		return self::get_virtual_content( $virtual_site, $object_type, $subtype, $source_id );
	}

	/**
	 * Check if site relation exists for virtual site
	 *
	 * Delegates to Site_Relation_Service::get_relation_by_target() which
	 * handles multi-format ID matching and caching.
	 *
	 * @since 1.2.0 Refactored from 65-line direct SQL to Service layer delegation.
	 *
	 * @param array $virtual_site Virtual site data array.
	 * @return bool
	 */
	private static function check_site_relation_exists( $virtual_site ) {
		$ids_to_try = self::build_virtual_site_ids( $virtual_site );
		$primary_id = $ids_to_try[0] ?? '';

		$relation = \WPTSALL\Sites\Services\Site_Relation_Service::get_relation_by_target(
			$primary_id,
			'virtual',
			$ids_to_try
		);

		wptsall_log_info(
			'hooks-router',
			'check_site_relation_exists result',
			array(
				'virtual_site_id' => $virtual_site['id'] ?? 0,
				'ids_tried'       => $ids_to_try,
				'found'           => null !== $relation,
			)
		);

		return null !== $relation;
	}

	/**
	 * Check if model exists for object type
	 *
	 * Delegates to Translation_Rule_Service::get_model_id_for_object() which
	 * handles JSON column parsing, multi-candidate disambiguation, and caching.
	 *
	 * @since 1.2.0 Refactored from 76-line direct SQL to Service layer delegation.
	 *
	 * @param string $object_type Object type (post_type or taxonomy).
	 * @param string $object_name Object name.
	 * @return int|false Model ID if found, false otherwise.
	 */
	private static function check_model_exists( $object_type, $object_name ) {
		return \WPTSALL\Models\Services\Translation_Rule_Service::get_model_id_for_object(
			$object_type,
			$object_name
		);
	}

	/**
	 * Check if an object is registered (active rule exists) in translation_rules.
	 *
	 * Delegates to Translation_Rule_Service::rule_exists() with active_only=true.
	 *
	 * @since 1.5.0 Refactored from direct SQL to Service layer delegation.
	 *
	 * @param int    $model_id    Model ID.
	 * @param string $data_type   Data type (post/term/...).
	 * @param string $object_name Object name (post_type or taxonomy).
	 * @param string $url_type    URL type (single/taxonomy/...).
	 * @return bool
	 */
	private static function check_object_rule_registered( $model_id, $data_type, $object_name, $url_type ) {
		return \WPTSALL\Models\Services\Translation_Rule_Service::rule_exists(
			(int) $model_id,
			sanitize_key( $data_type ),
			sanitize_key( $object_name ),
			sanitize_key( $url_type ),
			true // active_only
		);
	}

	/**
	 * Get routing error information
	 *
	 * @return array|null
	 */
	public static function get_routing_error() {
		return self::$routing_error;
	}

	/**
	 * Display routing error page
	 *
	 * @param array $error Error information.
	 * @return void
	 */
	private static function display_routing_error( $error ) {
		$error_code    = $error['code'] ?? 'unknown_error';
		$error_message = $error['message'] ?? __( 'Unknown error', 'wpmmcc-ats' );
		$error_details = $error['details'] ?? array();

		// Set HTTP status code
		$current_path = function_exists( 'wptsall_get_request_uri' ) ? wptsall_get_request_uri() : '';
		$site_url     = home_url( '/' );

		// Build the error page body. Routing errors are emitted outside the
		// theme template, so wp_die() provides the rendered (and styled) page
		// rather than enqueuing assets that have no wp_head()/wp_footer() hook.
		$body  = '<p>' . esc_html( $error_message ) . '</p>';
		$body .= '<p><code>' . esc_html( $error_code ) . '</code></p>';

		$body .= '<dl>';
		$body .= '<dt>' . esc_html__( 'Request path:', 'wpmmcc-ats' ) . '</dt>';
		$body .= '<dd>' . esc_html( $current_path ) . '</dd>';
		foreach ( $error_details as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$body .= '<dt>' . esc_html( $key ) . ':</dt>';
				$body .= '<dd>' . esc_html( $value ) . '</dd>';
			}
		}
		$body .= '</dl>';

		$body .= '<p><a href="' . esc_url( $site_url ) . '">' . esc_html__( 'Back to Home', 'wpmmcc-ats' ) . '</a></p>';

		wp_die(
			wp_kses_post( $body ),
			esc_html__( 'Error', 'wpmmcc-ats' ),
			array( 'response' => 404 )
		);
	}

	/**
	 * P0-3 fallback: redirect 404s on translated (target) post slugs to their canonical
	 * virtual-site URL. Mirrors the canonicalization pattern used by Polylang's
	 * `pll_redirect_non_default_language` / TranslatePress's URL discovery: the canonical
	 * URL of a translated post lives under the virtual-site prefix, not the bare slug.
	 *
	 * Resolves target post slug -> post_mappings row -> site_relations row -> path_prefix
	 * (uses `target_theme_path` if set, else `sanitize_title(target_lang)`, which matches
	 * `Virtual_Site_Service::merge_site_relations()` normalization).
	 *
	 * @since 1.5.0
	 *
	 * @return void
	 */
	public static function maybe_redirect_source_post_to_virtual() {
		if ( is_admin() || ! isset( $GLOBALS["wp_query"] ) ) {
			return;
		}
		$q = $GLOBALS["wp_query"];
		if ( ! $q->is_404 ) {
			return;
		}
		// 1.2.0 — respect Settings -> permalink_fallback toggle (Polylang's
		// pll_force_lang behavior pattern).
		if ( class_exists( '\\WPTSALL\\Settings\\Services\\Settings_Service' )
			&& ! \WPTSALL\Settings\Services\Settings_Service::get( 'permalink_fallback', true ) ) {
			return;
		}
		/**
		 * Escape hatch: disable source→virtual 301 for this request.
		 *
		 * @since 2.2.0
		 * @param bool $is_redirected Whether to redirect.
		 */
		if ( ! apply_filters( 'wptsall_is_redirected', true ) ) {
			return;
		}
		if ( self::get_current_virtual_site() ) {
			return;
		}
		$request_uri = function_exists( 'wptsall_get_request_uri' ) ? wptsall_get_request_uri() : '';
		$path        = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
		$slug        = trim( (string) basename( untrailingslashit( $path ) ) );
		if ( "" === $slug ) {
			return;
		}
		global $wpdb;
		// Resolve slug -> post_mappings row (target_post_id side). Prefer relation_id
		// when present; fall back to legacy rows where target_site_id was set without
		// a relation.
		$mappings_table   = wptsall_table( 'post_mappings' );
		$relations_table  = wptsall_table( 'site_relations' );
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT p.ID AS target_post_id, p.post_name, m.relation_id, m.target_site_id, m.source_post_id
			 FROM %i p
			 INNER JOIN %i m ON m.target_post_id = p.ID
			 WHERE p.post_name = %s
			   AND p.post_status IN ('publish','draft','private')
			   AND m.target_post_id > 0
			 ORDER BY (m.relation_id > 0) DESC, m.id DESC
			 LIMIT 1",
			$wpdb->posts,
			$mappings_table,
			$slug
		), ARRAY_A );
		if ( ! $row ) {
			return;
		}
		$target_post_id  = (int) $row["target_post_id"];
		$relation_id     = (int) $row["relation_id"];
		$path_prefix     = "";
		$virtual_site_id = (string) ( $row["target_site_id"] ?? "" );
		// P0-3 step: resolve path_prefix from relation. Either direct (relation_id) or
		// lookup by target_site_id when relation_id=0 (e.g., legacy fixtures).
		$rel = null;
		if ( $relation_id > 0 ) {
			$rel = $wpdb->get_row( $wpdb->prepare(
				"SELECT target_lang, target_site_id, target_site_type, target_theme_path
				 FROM %i WHERE id = %d",
				$relations_table,
				$relation_id
			), ARRAY_A );
		} elseif ( "" !== $virtual_site_id ) {
			$rel = $wpdb->get_row( $wpdb->prepare(
				"SELECT target_lang, target_site_id, target_site_type, target_theme_path
				 FROM %i
				 WHERE target_site_id = %s AND target_site_type = %s AND status = %s
				 ORDER BY id DESC LIMIT 1",
				$relations_table,
				$virtual_site_id,
				"virtual",
				"active"
			), ARRAY_A );
		}
		if ( $rel ) {
			if ( ! empty( $rel["target_theme_path"] ) ) {
				$path_prefix = trim( (string) $rel["target_theme_path"], "/" );
			} elseif ( ! empty( $rel["target_lang"] ) ) {
				// Match Virtual_Site_Service::merge_site_relations() (which sanitize_title's the lang)
				// so the redirect lands on the same prefix the router serves.
				$path_prefix = sanitize_title( (string) $rel["target_lang"] );
			}
			$virtual_site_id = (string) ( $rel["target_site_id"] ?? $virtual_site_id );
		}
		if ( "" === $path_prefix && "" !== $virtual_site_id ) {
			$path_prefix = "virtual/" . $virtual_site_id;
		}
		if ( "" === $path_prefix ) {
			return;
		}
		$source_post_id = (int) ( $row['source_post_id'] ?? 0 );
		$rel_path       = $slug;
		if ( $source_post_id > 0 ) {
			$source_permalink = get_permalink( $source_post_id );
			$parsed           = is_string( $source_permalink ) ? wp_parse_url( $source_permalink ) : false;
			if ( is_array( $parsed ) && ! empty( $parsed['path'] ) ) {
				$rel_path = ltrim( (string) $parsed['path'], '/' );
			}
		}
		$target_url = home_url( '/' . $path_prefix . '/' . $rel_path );
		wptsall_log_info( "hooks-router", "P0-3: redirecting target post 404 to virtual URL", array(
			"target_post_id"  => $target_post_id,
			"relation_id"     => $relation_id,
			"virtual_site_id" => $virtual_site_id,
			"path_prefix"     => $path_prefix,
			"target_url"      => $target_url,
		) );
		wp_safe_redirect( $target_url, 301, "wptsall" );
		exit;
	}
}

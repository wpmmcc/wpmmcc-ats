<?php
/**
 * Virtual Site SEO
 *
 * Front-end SEO hook layer for virtual sites (hreflang / canonical / robots /
 * document title / body class / language attributes / sitemap / language
 * switcher). Modeled after Polylang's PLL_Frontend_Filters_Links and
 * WPML's WPML_SEO_HeadLangs (see AGENTS.md §20 for the design notes).
 *
 * Design principles (extracted from competitors):
 *  - All output is opt-in (constant WPTSALL_SEO_OUTPUT).
 *  - Settings hreflang_emitter=none disables hreflang only (not canonical/sitemap/OG).
 *  - `is_paged()` pages are skipped for hreflang (Google best practice
 *    and Polylang's `frontend-filters-links.php:201`).
 *  - Posts must be `publish` before hreflang is emitted (WPML
 *    `must_render()` enforces the same).
 *  - `x-default` points to the source post permalink (Google spec).
 *  - Yoast: bridge via `wpseo_canonical` / `wpseo_head` / sitemap entry
 *    (Polylang WPSEO + WPML DisableHeadLangs pattern).
 *  - Rank Math: bridge via `rank_math/frontend/canonical`, `rank_math/head`,
 *    `rank_math/sitemap/entry` + xhtml alternate inject (WPML RankMathSEO
 *    Loaders / DirectoryHooks). Do NOT disable the whole SEO layer when
 *    Rank Math is active.
 *  - Filter namespace `wptsall_rel_hreflang_attributes` is exposed so
 *    third-party plugins can adjust the list (Polylang: `pll_rel_hreflang_attributes`).
 *
 * @since 1.5.0 P0-2 fix.
 *
 * @package WPTSALL\Hooks
 */

namespace WPTSALL\Hooks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Virtual_Site_SEO {

	/**
	 * Whether hreflang was already printed this request (Rank Math may fire
	 * both `rank_math/head` and leave `wp_head` as fallback).
	 *
	 * @var bool
	 */
	private static $hreflang_emitted = false;

	/**
	 * Init: register the SEO hooks for virtual sites.
	 *
	 * Hooked to `init` from Virtual_Site_Router::maybe_register_frontend_hooks().
	 *
	 * @since 1.5.0
	 *
	 * @return void
	 */
	public static function init() {
		// Constant kill-switch only. Settings hreflang_emitter=none turns off
		// hreflang emission (should_emit_hreflang), not canonical/sitemap/robots/OG.
		if ( defined( 'WPTSALL_SEO_OUTPUT' ) && ! WPTSALL_SEO_OUTPUT ) {
			return;
		}
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		// Canonical + sitemap bridges — register every active SEO plugin's
		// shared pipeline (exclude shadow loc + xhtml alternates + x-default
		// via Url_Converter). Not elseif: AIOSEO/SEOPress may coexist with
		// core sitemap; head hreflang still picks one emitter below.
		$seo_bridge_registered = false;
		if ( self::is_yoast_active() ) {
			add_filter( 'wpseo_canonical', array( __CLASS__, 'filter_yoast_canonical' ), 10 );
			add_filter( 'wpseo_enable_xml_sitemap_transient_caching', '__return_false' );
			add_filter( 'wpseo_sitemap_entry', array( __CLASS__, 'filter_yoast_sitemap_entry' ), 10, 3 );
			add_filter( 'wpseo_sitemap_url', array( __CLASS__, 'filter_yoast_sitemap_url' ), 10, 2 );
			add_filter( 'wpseo_sitemap_urlset', array( __CLASS__, 'filter_yoast_sitemap_urlset' ), 10 );
			$seo_bridge_registered = true;
		}
		if ( self::is_rankmath_active() ) {
			add_filter( 'rank_math/frontend/canonical', array( __CLASS__, 'filter_rankmath_canonical' ), 10 );
			add_filter( 'rank_math/sitemap/enable_caching', '__return_false' );
			add_filter( 'rank_math/sitemap/entry', array( __CLASS__, 'filter_rankmath_sitemap_entry' ), 10, 3 );
			add_filter( 'rank_math/sitemap/url', array( __CLASS__, 'filter_rankmath_sitemap_url_xml' ), 10, 2 );
			add_filter( 'rank_math/sitemap_url', array( __CLASS__, 'filter_rankmath_sitemap_url_xml' ), 10, 2 );
			add_filter( 'rank_math/links/is_external', array( __CLASS__, 'filter_rankmath_is_external' ), 10, 2 );
			add_action( 'parse_query', array( __CLASS__, 'rankmath_catch_sitemap_on_virtual_prefix' ), -PHP_INT_MAX );
			$seo_bridge_registered = true;
		}
		if ( self::is_aioseo_active() ) {
			add_filter( 'aioseo_canonical_url', array( __CLASS__, 'filter_aioseo_canonical' ), 10 );
			add_filter( 'aioseo_sitemap_post', array( __CLASS__, 'filter_aioseo_sitemap_post' ), 10, 2 );
			add_filter( 'aioseo_sitemap_term', array( __CLASS__, 'filter_aioseo_sitemap_post' ), 10, 2 );
			$seo_bridge_registered = true;
		}
		if ( self::is_seopress_active() ) {
			add_filter( 'seopress_titles_canonical', array( __CLASS__, 'filter_seopress_canonical' ), 10 );
			add_filter( 'seopress_sitemaps_url', array( __CLASS__, 'filter_seopress_sitemaps_url' ), 10, 2 );
			add_filter( 'seopress_sitemaps_single_url', array( __CLASS__, 'filter_seopress_sitemaps_url' ), 10, 2 );
			$seo_bridge_registered = true;
		}
		if ( ! $seo_bridge_registered ) {
			add_filter( 'get_canonical_url', array( __CLASS__, 'filter_canonical_url' ), 10, 2 );
		}

		// Document title translation (core + TSF bridges).
		// TSF 5.x short-circuits via pre_get_document_title and builds titles through
		// the_seo_framework_title_from_* — document_title_parts alone is not enough
		// on virtual-site requests (coverage: autodescription → Untitled / site-only).
		add_filter( 'document_title_parts', array( __CLASS__, 'filter_document_title_parts' ), 10 );
		add_filter( 'pre_get_document_title', array( __CLASS__, 'filter_pre_get_document_title' ), 1000 );
		add_filter( 'the_seo_framework_title_from_generation', array( __CLASS__, 'filter_tsf_title' ), 10, 2 );
		add_filter( 'the_seo_framework_title_from_custom_field', array( __CLASS__, 'filter_tsf_title' ), 10, 2 );

		// robots / body class / language attributes.
		add_filter( 'wp_robots',           array( __CLASS__, 'filter_wp_robots' ), 10, 2 );
		add_filter( 'body_class',          array( __CLASS__, 'filter_body_class' ), 10, 2 );
		add_filter( 'language_attributes', array( __CLASS__, 'filter_language_attributes' ), 10, 2 );

		// Sitemaps (WP 5.5+ core sitemap).
		add_filter( 'wp_sitemaps_posts_entry',      array( __CLASS__, 'filter_sitemap_post_entry' ), 10, 3 );
		add_filter( 'wp_sitemaps_taxonomies_entry', array( __CLASS__, 'filter_sitemap_tax_entry' ), 10, 3 );

		// Canonical redirect: don't let WP strip the virtual prefix.
		add_filter( 'redirect_canonical', array( __CLASS__, 'disable_canonical_redirect' ), 10, 2 );

		// Open Graph / share URLs — virtualize under VS (field translate ≠ URL rewrite).
		add_filter( 'wpseo_opengraph_url', array( __CLASS__, 'filter_opengraph_url' ), 10 );
		add_filter( 'wpseo_twitter_url', array( __CLASS__, 'filter_opengraph_url' ), 10 );
		add_filter( 'rank_math/opengraph/url', array( __CLASS__, 'filter_opengraph_url' ), 10 );
		add_filter( 'rank_math/opengraph/facebook/url', array( __CLASS__, 'filter_opengraph_url' ), 10 );
		add_filter( 'rank_math/opengraph/twitter/url', array( __CLASS__, 'filter_opengraph_url' ), 10 );
		add_filter( 'aioseo_facebook_tags', array( __CLASS__, 'filter_aioseo_facebook_tags' ), 10 );
		add_filter( 'aioseo_twitter_tags', array( __CLASS__, 'filter_aioseo_facebook_tags' ), 10 );
		// SEOPress social URL bridges (canonical/sitemap already wired above).
		add_filter( 'seopress_social_og_url', array( __CLASS__, 'filter_opengraph_url' ), 10 );
		add_filter( 'seopress_social_twitter_card_url', array( __CLASS__, 'filter_opengraph_url' ), 10 );

		// hreflang emission depends on Settings -> hreflang_emitter:
		//  - none: hreflang off only (canonical/sitemap/OG/robots stay on)
		//  - yoast + Yoast active: full defer — do not inject our hreflang at all.
		//  - wpmmcc-ats (default): emit ourselves; when Yoast is active, use the
		//    wpseo_head bridge and suppress duplicate wp_head emission.
		// Even when an SEO plugin owns sitemap alternates, keep a minimal
		// source-home head set.  The unprefixed source root has no sitemap
		// request context in many themes/crawlers, so omitting it leaves the
		// canonical entry point without any hreflang signal. Virtual-site
		// requests continue to honor the sitemap-preference/defer setting.
		$source_home_request = ! Virtual_Site_Router::get_current_virtual_site()
			&& 'yoast' !== self::get_hreflang_emitter();
		if ( self::should_emit_hreflang() || $source_home_request ) {
			// Prefer the SEO plugin's head action so we do not double-emit on wp_head
			// (WPML SEO DisableHeadLangs / Yoast presenter / Rank Math rank_math/head).
			if ( self::is_yoast_active() ) {
				add_action( 'wpseo_head', array( __CLASS__, 'wp_head_hreflang' ), 1 );
			} elseif ( self::is_rankmath_active() ) {
				// Rank Math prints meta via rank_math/head; fresh installs may not
				// fire it until setup completes — keep wp_head fallback + once-guard.
				add_action( 'rank_math/head', array( __CLASS__, 'wp_head_hreflang' ), 25 );
				add_action( 'wp_head', array( __CLASS__, 'wp_head_hreflang' ), 2 );
			} elseif ( self::is_aioseo_active() ) {
				add_action( 'aioseo_head', array( __CLASS__, 'wp_head_hreflang' ), 1 );
				add_action( 'wp_head', array( __CLASS__, 'wp_head_hreflang' ), 2 );
			} elseif ( self::is_seopress_active() ) {
				add_action( 'seopress_wp_head', array( __CLASS__, 'wp_head_hreflang' ), 1 );
				add_action( 'wp_head', array( __CLASS__, 'wp_head_hreflang' ), 2 );
			} else {
				add_action( 'wp_head', array( __CLASS__, 'wp_head_hreflang' ), 1 );
			}
		}

		// Tiny language switcher marker (themes can replace via shortcode).
		add_action( 'wp_footer', array( __CLASS__, 'output_language_switcher_marker' ), 999 );
	}

	/**
	 * Canonical hreflang emitter setting value.
	 *
	 * @since 1.9.0
	 * @return string wpmmcc-ats|yoast|none
	 */
	public static function get_hreflang_emitter(): string {
		if ( ! class_exists( '\\WPTSALL\\Settings\\Services\\Settings_Service' ) ) {
			return 'wpmmcc-ats';
		}
		$emitter = (string) \WPTSALL\Settings\Services\Settings_Service::get( 'hreflang_emitter', 'wpmmcc-ats' );
		// Legacy UI bug stored value "wptsall".
		if ( 'wptsall' === $emitter ) {
			$emitter = 'wpmmcc-ats';
		}
		if ( ! in_array( $emitter, array( 'wpmmcc-ats', 'yoast', 'none' ), true ) ) {
			return 'wpmmcc-ats';
		}
		return $emitter;
	}

	/**
	 * Whether this plugin should emit hreflang link tags.
	 *
	 * @since 1.9.0
	 * @return bool
	 */
	public static function should_emit_hreflang(): bool {
		$emitter = self::get_hreflang_emitter();
		if ( 'none' === $emitter ) {
			return false;
		}
		// Full defer to Yoast when requested and Yoast is available.
		if ( 'yoast' === $emitter && self::is_yoast_active() ) {
			return false;
		}
		// WPML DisableHeadLangs: prefer sitemap xhtml:link when Yoast/Rank Math
		// can carry alternates. Overridable via Settings -> prefer_sitemap_hreflang.
		// Virtual-prefix pages need a head alternate pointing back to the
		// source/peer URL even when Yoast or Rank Math owns sitemap alternates;
		// crawlers and themes commonly consume only HTML. Keep sitemap
		// preference for the unprefixed source request, where the SEO plugin can
		// provide the complete XML set without duplicate head tags.
		$on_virtual_site = (bool) Virtual_Site_Router::get_current_virtual_site();
		if ( ! $on_virtual_site && self::prefer_sitemap_hreflang() && self::sitemap_plugin_owns_alternates() ) {
			/**
			 * Force head hreflang even when sitemap preference is on.
			 *
			 * @since 2.2.0
			 * @param bool $force_head Default false.
			 */
			if ( ! apply_filters( 'wptsall_force_head_hreflang', false ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether settings prefer sitemap alternates over head hreflang.
	 *
	 * @since 2.2.0
	 * @return bool
	 */
	public static function prefer_sitemap_hreflang(): bool {
		if ( ! class_exists( '\\WPTSALL\\Settings\\Services\\Settings_Service' ) ) {
			return true;
		}
		return (bool) \WPTSALL\Settings\Services\Settings_Service::get( 'prefer_sitemap_hreflang', true );
	}

	/**
	 * Yoast (with XML sitemap) or Rank Math can host xhtml:link alternates.
	 *
	 * @since 2.2.0
	 * @return bool
	 */
	public static function sitemap_plugin_owns_alternates(): bool {
		if ( self::is_rankmath_active() ) {
			return true;
		}
		if ( self::is_aioseo_active() || self::is_seopress_active() ) {
			return true;
		}
		if ( self::is_yoast_active() ) {
			$opts = get_option( 'wpseo', array() );
			if ( is_array( $opts ) && ! empty( $opts['enable_xml_sitemap'] ) ) {
				return true;
			}
			// Yoast 20+ may store sitemap under wpseo_titles / features — treat active Yoast as capable.
			if ( defined( 'WPSEO_VERSION' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether built-in SEO output is enabled.
	 *
	 * Disabled only by `WPTSALL_SEO_OUTPUT` = false.
	 * Settings `hreflang_emitter=none` does **not** disable this layer — it only
	 * stops hreflang via {@see should_emit_hreflang()}.
	 *
	 * @since 1.5.0
	 *
	 * @return bool
	 */
	public static function is_output_enabled(): bool {
		if ( defined( 'WPTSALL_SEO_OUTPUT' ) ) {
			return (bool) WPTSALL_SEO_OUTPUT;
		}
		return true;
	}

	/**
	 * Virtualize Open Graph / Twitter card URLs under the current virtual site.
	 *
	 * @param string $url Share URL from an SEO plugin.
	 * @return string
	 */
	public static function filter_opengraph_url( $url ) {
		$vs = Virtual_Site_Router::get_current_virtual_site();
		if ( ! $vs || ! is_string( $url ) || '' === $url ) {
			return $url;
		}
		if ( class_exists( '\\WPTSALL\\Sites\\Services\\Url_Converter' ) ) {
			return \WPTSALL\Sites\Services\Url_Converter::virtualize( $url, $vs );
		}
		return $url;
	}

	/**
	 * AIOSEO Facebook/Twitter tag bags: rewrite url keys when present.
	 *
	 * @param array $tags Tag map.
	 * @return array
	 */
	public static function filter_aioseo_facebook_tags( $tags ) {
		if ( ! is_array( $tags ) ) {
			return $tags;
		}
		foreach ( array( 'og:url', 'twitter:url', 'url' ) as $key ) {
			if ( ! empty( $tags[ $key ] ) && is_string( $tags[ $key ] ) ) {
				$tags[ $key ] = self::filter_opengraph_url( $tags[ $key ] );
			}
		}
		return $tags;
	}

	/**
	 * @since 1.5.0
	 * @return bool
	 */
	public static function is_yoast_active(): bool {
		return defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Frontend' ) || class_exists( 'Yoast\WP\SEO\Integrations\Front_End_Integration' );
	}

	/**
	 * @since 1.5.0
	 * @return bool
	 */
	public static function is_rankmath_active(): bool {
		return defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' );
	}

	/**
	 * @since 2.3.0
	 * @return bool
	 */
	public static function is_aioseo_active(): bool {
		return defined( 'AIOSEO_VERSION' ) || class_exists( '\\AIOSEO\\Plugin\\AIOSEO' ) || function_exists( 'aioseo' );
	}

	/**
	 * @since 2.3.0
	 * @return bool
	 */
	public static function is_seopress_active(): bool {
		return defined( 'SEOPRESS_VERSION' ) || defined( 'SEOPRESS_PRO_VERSION' ) || function_exists( 'seopress_get_toggle_option' );
	}

	/**
	 * wp_head hreflang + x-default emitter.
	 *
	 * Mirrors WPML_SEO_HeadLangs::head_langs and Polylang
	 * PLL_Frontend_Filters_Links::wp_head. Emits:
	 *   - rel="alternate" hreflang="<lang>" href="<url>" for each known
	 *     translation
	 *   - rel="alternate" hreflang="x-default" href="<source_url>"
	 *
	 * Skips: paged pages, non-singular queries, drafts/private posts.
	 *
	 * @since 1.5.0
	 *
	 * @return void
	 */
	public static function wp_head_hreflang() {
		if ( self::$hreflang_emitted ) {
			return;
		}
		if ( apply_filters( 'wptsall_seo_skip_emit_hreflang', false )
			&& ! doing_action( 'wpseo_head' )
			&& ! doing_action( 'rank_math/head' ) ) {
			return;
		}
		$vs = Virtual_Site_Router::get_current_virtual_site();
		// The source site's home page is also part of the alternate set.  A
		// request without a virtual prefix has no current virtual-site context,
		// but should still advertise x-default and each active virtual home.
		// This closes the SEO gap where the source root rendered no hreflang at
		// all while prefixed virtual homes did.
		if ( ! $vs ) {
			if ( is_singular() ) {
				// Reciprocal hreflang on source singulars (Google requires cluster reciprocity).
				self::emit_source_singular_hreflang();
				self::$hreflang_emitted = true;
			} else {
				self::emit_source_home_hreflang();
				self::$hreflang_emitted = true;
			}
			return;
		}
		// Google best practice: do not emit hreflang on paginated subpages.
		if ( is_paged() || ( is_singular() && (int) get_query_var( 'page' ) > 1 ) ) {
			return;
		}
		if ( ! is_singular() ) {
			// Taxonomy archives: term-mapping cluster when possible; else reciprocal path.
			if ( is_category() || is_tag() || is_tax() ) {
				self::emit_term_archive_hreflang( $vs );
			} else {
				self::emit_self_hreflang( $vs );
			}
			self::$hreflang_emitted = true;
			return;
		}
		$post_id = (int) get_queried_object_id();
		if ( $post_id <= 0 ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return;
		}

		// Self hreflang (current virtual URL).
		$self_url = self::build_virtual_url( $vs, $post_id );
		if ( $self_url ) {
			$hreflangs = array();
			$hreflangs[ self::normalize_lang( $vs['lang'] ?? get_locale() ) ] = $self_url;
			// x-default = the canonical source URL (no virtual prefix). On a
			// virtual request the queried object is the shadow copy, so resolve
			// back to the source post first, then bypass the router's
			// link-rewrite filters so the canonical stays prefix-free.
			$hreflangs['x-default'] = self::canonical_source_permalink( self::resolve_canonical_source_id( $post_id ) );
			// Discover alternate translations from post_mappings.
			$hreflangs = self::collect_alternate_hreflangs( $post_id, $hreflangs );

			/**
			 * Filters the list of rel hreflang attributes emitted by wptsall.
			 *
			 * @since 1.5.0
			 * @param array  $hreflangs Map of lang code (or x-default) to URL.
			 * @param int    $post_id   Source post id.
			 * @param array  $vs        Current virtual site data.
			 */
			$hreflangs = apply_filters( 'wptsall_rel_hreflang_attributes', $hreflangs, $post_id, $vs );

			foreach ( $hreflangs as $lang => $url ) {
				if ( empty( $url ) ) {
					continue;
				}
				printf(
					'<link rel="alternate" href="%s" hreflang="%s" />' . "\n",
					esc_url( $url ),
					esc_attr( $lang )
				);
			}
			self::$hreflang_emitted = true;
		}
	}

	/**
	 * Emit hreflang for category/tag/custom-tax archives under a virtual site.
	 *
	 * Reuses reciprocal path emission ({@see emit_self_hreflang}) and upgrades
	 * x-default to the source term permalink when the queried object is a shadow.
	 *
	 * @param array $vs Virtual site row.
	 * @return void
	 */
	protected static function emit_term_archive_hreflang( array $vs ) {
		$term = get_queried_object();
		if ( ! ( $term instanceof \WP_Term ) ) {
			self::emit_self_hreflang( $vs );
			return;
		}
		$source_term_id = (int) get_term_meta( (int) $term->term_id, '_wptsall_source_term_id', true );
		$taxonomy       = (string) $term->taxonomy;
		if ( $source_term_id > 0 ) {
			add_filter(
				'wptsall_rel_hreflang_attributes',
				static function ( $hreflangs ) use ( $source_term_id, $taxonomy ) {
					if ( ! is_array( $hreflangs ) ) {
						return $hreflangs;
					}
					$link = get_term_link( $source_term_id, $taxonomy );
					if ( ! is_wp_error( $link ) && is_string( $link ) && '' !== $link ) {
						$hreflangs['x-default'] = $link;
					}
					return $hreflangs;
				},
				5
			);
		}
		self::emit_self_hreflang( $vs );
	}

	/**
	 * Emit a single self hreflang for non-singular queries (home / archives).
	 *
	 * @since 1.5.0
	 *
	 * @param array $vs Virtual site data.
	 * @return void
	 */
	protected static function emit_self_hreflang( array $vs ) {
		$self_url = self::build_virtual_url( $vs, 0 );
		if ( ! $self_url ) {
			return;
		}
		$lang = self::normalize_lang( $vs['lang'] ?? get_locale() );
		$hreflangs = array( $lang => $self_url );
		// Reciprocal alternates across active virtual sites for the same request path.
		if ( class_exists( '\\WPTSALL\\Sites\\Services\\Virtual_Site_Service' ) ) {
			$sites = \WPTSALL\Sites\Services\Virtual_Site_Service::get_all( array( 'status' => 'active' ) );
			$path  = '';
			if ( function_exists( 'wptsall_get_request_uri' ) ) {
				$uri  = wptsall_get_request_uri();
				$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
				$pref = trim( (string) ( $vs['path_prefix'] ?? '' ), '/' );
				if ( '' !== $pref && 0 === strpos( trim( $path, '/' ), $pref ) ) {
					$path = substr( trim( $path, '/' ), strlen( $pref ) );
					$path = '/' . ltrim( (string) $path, '/' );
				}
			}
			foreach ( (array) $sites as $site ) {
				$slang = self::normalize_lang( $site['lang'] ?? '' );
				if ( '' === $slang || isset( $hreflangs[ $slang ] ) ) {
					continue;
				}
				$href = self::build_virtual_url( $site, 0 );
				if ( $href && '' !== $path && '/' !== $path ) {
					$href = untrailingslashit( $href ) . $path;
				}
				if ( $href ) {
					$hreflangs[ $slang ] = $href;
				}
			}
		}
		$hreflangs['x-default'] = untrailingslashit( (string) get_option( 'home' ) ) . '/';
		$hreflangs = apply_filters( 'wptsall_rel_hreflang_attributes', $hreflangs, 0, $vs );
		foreach ( $hreflangs as $hl => $url ) {
			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}
			printf(
				'<link rel="alternate" href="%s" hreflang="%s" />' . "\n",
				esc_url( $url ),
				esc_attr( (string) $hl )
			);
		}
	}

	/**
	 * Emit source-home alternates when the request is not under a virtual
	 * prefix.  The source URL is x-default; active virtual sites contribute
	 * one home URL per language. Duplicate language entries are suppressed.
	 *
	 * @since 2.3.0
	 * @return void
	 */
	protected static function emit_source_home_hreflang() {
		$home = untrailingslashit( (string) get_option( 'home' ) ) . '/';
		$hreflangs = array( 'x-default' => $home );
		if ( class_exists( '\\WPTSALL\\Sites\\Services\\Virtual_Site_Service' ) ) {
			$sites = \WPTSALL\Sites\Services\Virtual_Site_Service::get_all( array( 'status' => 'active' ) );
			foreach ( (array) $sites as $site ) {
				$lang = self::normalize_lang( (string) ( $site['lang'] ?? '' ) );
				$prefix = trim( (string) ( $site['path_prefix'] ?? '' ), '/' );
				if ( '' === $lang || '' === $prefix || isset( $hreflangs[ $lang ] ) ) {
					continue;
				}
				$hreflangs[ $lang ] = untrailingslashit( (string) get_option( 'home' ) ) . '/' . $prefix . '/';
			}
		}
		$hreflangs = apply_filters( 'wptsall_rel_hreflang_attributes', $hreflangs, 0, array() );
		foreach ( $hreflangs as $lang => $url ) {
			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}
			printf(
				'<link rel="alternate" href="%s" hreflang="%s" />' . "\n",
				esc_url( $url ),
				esc_attr( (string) $lang )
			);
		}
	}

	/**
	 * Emit reciprocal hreflang on source-language singular posts.
	 *
	 * @since 2.3.0
	 * @return void
	 */
	protected static function emit_source_singular_hreflang() {
		if ( is_paged() || (int) get_query_var( 'page' ) > 1 ) {
			return;
		}
		$post_id = (int) get_queried_object_id();
		if ( $post_id <= 0 ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return;
		}
		if ( self::is_virtual_shadow_post( $post_id ) ) {
			return;
		}
		$source_url = self::canonical_source_permalink( $post_id );
		if ( '' === $source_url ) {
			return;
		}
		$hreflangs    = array( 'x-default' => $source_url );
		$default_lang = '';
		if ( class_exists( '\\WPTSALL\\Settings\\Services\\Settings_Service' ) ) {
			$default_lang = self::normalize_lang( (string) \WPTSALL\Settings\Services\Settings_Service::get( 'default_language', get_locale() ) );
		}
		if ( '' === $default_lang ) {
			$default_lang = self::normalize_lang( get_locale() );
		}
		if ( '' !== $default_lang ) {
			$hreflangs[ $default_lang ] = $source_url;
		}
		$hreflangs = self::collect_alternate_hreflangs( $post_id, $hreflangs );
		$hreflangs = apply_filters( 'wptsall_rel_hreflang_attributes', $hreflangs, $post_id, array() );
		foreach ( $hreflangs as $lang => $url ) {
			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}
			printf(
				'<link rel="alternate" href="%s" hreflang="%s" />' . "\n",
				esc_url( $url ),
				esc_attr( (string) $lang )
			);
		}
	}

	/**
	 * Build the URL for a post in a virtual site context.
	 *
	 * @since 1.5.0
	 *
	 * @param array $vs      Virtual site data.
	 * @param int   $post_id Post id (0 = home / archive).
	 * @return string|null
	 */
	protected static function build_virtual_url( array $vs, int $post_id ) {
		$prefix = trim( (string) ( $vs['path_prefix'] ?? '' ), '/' );
		if ( '' === $prefix ) {
			return null;
		}
		// Use option 'home' (not home_url()) so Link_Filters' home_url rewrite
		// cannot double-prefix when called after template_redirect.
		$base = untrailingslashit( (string) get_option( 'home' ) );
		if ( '' === $base ) {
			$base = untrailingslashit( (string) home_url( '/' ) );
		}
		$base .= '/' . $prefix;
		if ( $post_id <= 0 ) {
			return $base . '/';
		}
		$src  = get_permalink( $post_id );
		$path = $src ? (string) wp_parse_url( $src, PHP_URL_PATH ) : '';
		$path = ltrim( $path, '/' );
		// Strip any pre-existing virtual prefix from the source URL (post_link
		// may have already rewritten it).
		if ( '' !== $path && 0 === strpos( $path, $prefix . '/' ) ) {
			$path = (string) substr( $path, strlen( $prefix ) + 1 );
		}
		return $base . '/' . $path;
	}

	/**
	 * Build the alternate-language hreflang map for a post using
	 * `wp_wptsall_post_mappings`.
	 *
	 * @since 1.5.0
	 *
	 * @param int   $post_id    Source post id.
	 * @param array $hreflangs  Existing map (will be augmented).
	 * @return array
	 */
	protected static function collect_alternate_hreflangs( int $post_id, array $hreflangs ): array {
		global $wpdb;
		$table     = wptsall_table( 'post_mappings' );
		$relations = wptsall_table( 'site_relations' );
		$lookup_id = class_exists( __NAMESPACE__ . '\\Virtual_Site_Query_Switch' )
			? Virtual_Site_Query_Switch::resolve_source_post_id( $post_id )
			: $post_id;
		// `target_lang` lives on `wptsall_site_relations`, not on
		// `wptsall_post_mappings` (which only stores relation_id + target_site_id).
		// Join the relation so hreflang gets the real target language and type.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT m.target_post_id, m.target_site_id, r.target_lang, r.target_site_type
				 FROM %i m
				 LEFT JOIN %i r ON r.id = m.relation_id
				 WHERE m.source_post_id = %d AND m.target_post_id > 0',
				$table,
				$relations,
				$lookup_id
			),
			ARRAY_A
		);
		$seen = isset( $hreflangs['x-default'] ) ? array( 'x-default' => true ) : array();
		$cur  = isset( $hreflangs[ array_key_first( $hreflangs ) ] ) ? array_key_first( $hreflangs ) : '';
		if ( '' !== $cur ) {
			$seen[ $cur ] = true;
		}
		foreach ( (array) $rows as $r ) {
			$lang = self::normalize_lang( (string) ( $r['target_lang'] ?? '' ) );
			if ( '' === $lang || isset( $seen[ $lang ] ) ) {
				continue;
			}
			$seen[ $lang ] = true;
			$target_type = (string) ( $r['target_site_type'] ?? '' );
			$target_site = (string) ( $r['target_site_id'] ?? '' );
			// Same convention as Manual_Content_Service: `wp` targets carry the
			// numeric blog id in target_site_id; everything else is a virtual
			// shadow copy served from the same blog.
			if ( 'wp' === $target_type && is_numeric( $target_site ) && (int) $target_site > 0 ) {
				$href = (string) get_blog_permalink( (int) $target_site, (int) $r['target_post_id'] );
			} elseif ( class_exists( '\\WPTSALL\\Sites\\Services\\Virtual_Permalink' ) ) {
				$href = (string) \WPTSALL\Sites\Services\Virtual_Permalink::for_post( (int) $r['target_post_id'] );
			} else {
				$href = (string) get_permalink( (int) $r['target_post_id'] );
			}
			if ( $href ) {
				$hreflangs[ $lang ] = $href;
			}
		}
		return $hreflangs;
	}

	/**
	 * Resolve a queried object id back to its canonical source post id.
	 *
	 * On a virtual-site request the queried object is the shadow copy; the
	 * canonical (x-default / source) URL must point at the source post.
	 *
	 * @since 2.1.0
	 *
	 * @param int $post_id Queried post id (may be a shadow copy).
	 * @return int
	 */
	protected static function resolve_canonical_source_id( int $post_id ): int {
		if ( class_exists( __NAMESPACE__ . '\\Virtual_Site_Query_Switch' ) ) {
			$resolved = Virtual_Site_Query_Switch::resolve_source_post_id( $post_id );
			if ( $resolved > 0 ) {
				return $resolved;
			}
		}
		return $post_id;
	}

	/**
	 * Canonical (prefix-free) permalink for a source post.
	 *
	 * On a virtual-site request the router rewrites every post link to the
	 * virtual path prefix; SEO outputs that must stay canonical (x-default,
	 * source URLs) temporarily suspend those filters.
	 *
	 * @since 2.1.0
	 *
	 * @param int $source_id Source post id.
	 * @return string
	 */
	protected static function canonical_source_permalink( int $source_id ): string {
		$suspended = array(
			array( 'post_link', array( Virtual_Site_Router::class, 'rewrite_post_link' ), 20, 3 ),
			array( 'page_link', array( Virtual_Site_Router::class, 'rewrite_page_link' ), 20, 2 ),
			array( 'post_type_link', array( Virtual_Site_Router::class, 'rewrite_post_type_link' ), 20, 2 ),
			array( 'term_link', array( Virtual_Site_Router::class, 'rewrite_term_link' ), 20, 3 ),
		);
		// get_permalink() builds from home_url(); under VS that filter prefixes paths,
		// which would poison x-default even after post_link filters are removed.
		if ( class_exists( __NAMESPACE__ . '\\Virtual_Site_Link_Filters' ) ) {
			$suspended[] = array( 'home_url', array( Virtual_Site_Link_Filters::class, 'filter_home_url' ), 10, 2 );
		}
		foreach ( $suspended as $f ) {
			remove_filter( $f[0], $f[1], $f[2] );
		}
		$url = (string) get_permalink( $source_id );
		foreach ( $suspended as $f ) {
			add_filter( $f[0], $f[1], $f[2], $f[3] );
		}
		return $url;
	}

	/**
	 * Normalize a locale string to a hreflang-safe value.
	 *
	 * @since 1.5.0
	 *
	 * @param string $lang Locale (e.g. en_US, zh-CN, en).
	 * @return string
	 */
	protected static function normalize_lang( string $lang ): string {
		$lang = trim( $lang );
		if ( '' === $lang ) {
			return '';
		}
		// hreflang uses '-' between language and region (Google spec).
		return str_replace( '_', '-', $lang );
	}

	/**
	 * Filter get_canonical_url so the canonical link points to the
	 * virtual-site URL when serving a translated post.
	 *
	 * @since 1.5.0
	 *
	 * @param string $canonical Current canonical.
	 * @param object $post      Post object (optional).
	 * @return string
	 */
	public static function filter_canonical_url( $canonical, $post = null ) {
		/**
		 * Escape hatch (WPML-style): return false to leave SEO plugin canonical alone.
		 *
		 * @since 2.2.0
		 * @param bool   $must_translate Whether to rewrite canonical.
		 * @param string $canonical      Current canonical.
		 */
		if ( ! apply_filters( 'wptsall_must_translate_canonical', true, $canonical ) ) {
			return $canonical;
		}
		$vs = Virtual_Site_Router::get_current_virtual_site();
		if ( ! $vs ) {
			return $canonical;
		}
		// Singular: map to shadow permalink under the VS prefix.
		if ( is_singular() ) {
			$post_id = (int) ( $post ? $post->ID : get_queried_object_id() );
			if ( $post_id <= 0 ) {
				return $canonical;
			}
			return self::build_virtual_url( $vs, $post_id ) ?: $canonical;
		}
		// Archives / home / tax: virtualize whatever canonical we were given.
		if ( class_exists( '\\WPTSALL\\Sites\\Services\\Url_Converter' ) && is_string( $canonical ) && '' !== $canonical ) {
			return \WPTSALL\Sites\Services\Url_Converter::virtualize( $canonical, $vs );
		}
		return $canonical;
	}

	/**
	 * Bridge canonical into Yoast's presenter chain when Yoast is active.
	 *
	 * @since 1.5.0
	 *
	 * @param string $canonical Current canonical from Yoast.
	 * @return string
	 */
	public static function filter_yoast_canonical( $canonical ) {
		$vs = Virtual_Site_Router::get_current_virtual_site();
		if ( ! $vs ) {
			return $canonical;
		}
		// Yoast (and the post_link filter chain) may have already produced a
		// URL that already includes the virtual prefix. Detect and skip
		// double-prefix.
		$prefix = trim( (string) ( $vs['path_prefix'] ?? '' ), '/' );
		if ( '' !== $prefix && false !== strpos( (string) $canonical, '/' . $prefix . '/' ) ) {
			return $canonical;
		}
		$override = self::filter_canonical_url( $canonical, null );
		return $override ?: $canonical;
	}

	/**
	 * Filter document_title_parts so the title reflects the translated title.
	 *
	 * @since 1.5.0
	 *
	 * @param array $parts Title parts.
	 * @return array
	 */
	public static function filter_document_title_parts( $parts ) {
		$vs = Virtual_Site_Router::get_current_virtual_site();
		if ( ! $vs || ! is_singular() ) {
			return $parts;
		}
		$post_id = (int) get_queried_object_id();
		if ( $post_id <= 0 ) {
			return $parts;
		}
		$current = isset( $parts['title'] ) ? (string) $parts['title'] : '';
		$fixed   = self::resolve_virtual_document_title( $current, $post_id );
		if ( '' !== $fixed ) {
			$parts['title'] = $fixed;
		}
		return $parts;
	}

	/**
	 * Last-resort document title repair for virtual singulars.
	 *
	 * Runs late so SEO plugins can build first; only replaces blank / "Untitled"
	 * / site-name-only titles that TSF intermittently emits on virtual binds.
	 *
	 * @since 2.1.0
	 *
	 * @param string $title Current document title (may already be final).
	 * @return string
	 */
	public static function filter_pre_get_document_title( $title ) {
		$vs = Virtual_Site_Router::get_current_virtual_site();
		if ( ! $vs || ! is_singular() ) {
			return $title;
		}
		if ( ! self::is_unusable_document_title( (string) $title ) ) {
			return $title;
		}
		$post_id = (int) get_queried_object_id();
		if ( $post_id <= 0 ) {
			return $title;
		}
		$fixed = self::resolve_virtual_document_title( '', $post_id );
		if ( '' === $fixed ) {
			return $title;
		}
		$site = trim( (string) get_bloginfo( 'name', 'display' ) );
		$sep  = (string) apply_filters( 'document_title_separator', '-' );
		if ( '' !== $site ) {
			return $fixed . ' ' . $sep . ' ' . $site;
		}
		return $fixed;
	}

	/**
	 * Bridge The SEO Framework generated / custom-field titles on virtual sites.
	 *
	 * @since 2.1.0
	 *
	 * @param string     $title Generated or custom title.
	 * @param array|null $args  TSF query args (null = current loop).
	 * @return string
	 */
	public static function filter_tsf_title( $title, $args = null ) {
		$vs = Virtual_Site_Router::get_current_virtual_site();
		if ( ! $vs ) {
			return $title;
		}
		$post_id = 0;
		if ( is_array( $args ) && ! empty( $args['id'] ) ) {
			$post_id = (int) $args['id'];
		} elseif ( is_singular() ) {
			$post_id = (int) get_queried_object_id();
		}
		if ( $post_id <= 0 ) {
			return $title;
		}
		$fixed = self::resolve_virtual_document_title( (string) $title, $post_id );
		return '' !== $fixed ? $fixed : $title;
	}

	/**
	 * Resolve a usable title for a virtual-site singular post.
	 *
	 * @since 2.1.0
	 *
	 * @param string $title   Candidate title (may be blank / Untitled).
	 * @param int    $post_id Queried / shadow post id.
	 * @return string Empty when no usable title could be resolved.
	 */
	protected static function resolve_virtual_document_title( string $title, int $post_id ): string {
		$candidate = (string) Virtual_Site_Router::filter_title( $title, $post_id );
		if ( self::is_unusable_document_title( $candidate ) ) {
			$post = get_post( $post_id );
			if ( $post instanceof \WP_Post && '' !== trim( (string) $post->post_title ) ) {
				$candidate = (string) Virtual_Site_Router::filter_title( $post->post_title, $post_id );
			}
		}
		if ( self::is_unusable_document_title( $candidate ) ) {
			return '';
		}
		return $candidate;
	}

	/**
	 * Whether a document title is blank, the WP "Untitled" placeholder, or only the site name.
	 *
	 * @since 2.1.0
	 *
	 * @param string $title Title string.
	 * @return bool
	 */
	protected static function is_unusable_document_title( string $title ): bool {
		$t = trim( wp_strip_all_tags( $title ) );
		if ( '' === $t ) {
			return true;
		}
		if ( 0 === strcasecmp( $t, 'Untitled' ) ) {
			return true;
		}
		$site = trim( (string) get_bloginfo( 'name', 'display' ) );
		if ( '' !== $site && 0 === strcasecmp( $t, $site ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Apply relation-level seo_visibility to wp_robots output.
	 *
	 * @since 1.5.0
	 *
	 * @param array  $robots  Robots directives.
	 * @param string $context Contextual string.
	 * @return array
	 */
	public static function filter_wp_robots( $robots, $context = '' ) {
		if ( apply_filters( 'wptsall_seo_skip_emit_robots', false ) ) {
			return $robots;
		}
		$vs = Virtual_Site_Router::get_current_virtual_site();
		if ( ! $vs ) {
			return $robots;
		}
		$rel = $vs['relation'] ?? null;
		if ( is_array( $rel ) && isset( $rel['seo_visibility'] ) && 'noindex' === $rel['seo_visibility'] ) {
			$robots['noindex'] = true;
			unset( $robots['index'] );
		} else {
			$robots['index']  = true;
			unset( $robots['noindex'] );
		}
		return $robots;
	}

	/**
	 * Append virtual-site + lang body classes.
	 *
	 * @since 1.5.0
	 *
	 * @param array $classes Existing classes.
	 * @param array $args    Additional args.
	 * @return array
	 */
	public static function filter_body_class( $classes, $args = array() ) {
		$vs = Virtual_Site_Router::get_current_virtual_site();
		if ( ! $vs ) {
			return $classes;
		}
		$classes[] = 'wptsall-virtual-site';
		$lang      = self::normalize_lang( $vs['lang'] ?? get_locale() );
		if ( '' !== $lang ) {
			$classes[] = 'lang-' . sanitize_html_class( $lang );
		}
		$prefix = trim( (string) ( $vs['path_prefix'] ?? '' ), '/' );
		if ( '' !== $prefix ) {
			$classes[] = 'wptsall-site-' . sanitize_html_class( $prefix );
		}
		return array_unique( $classes );
	}

	/**
	 * Disable WP core's redirect_canonical for virtual-site requests
	 * so the virtual path prefix is not stripped.
	 *
	 * @since 1.5.0
	 *
	 * @param string $redirect_url  Redirect target.
	 * @param string $requested_url Original request URL.
	 * @return string|false
	 */
	public static function disable_canonical_redirect( $redirect_url, $requested_url ) {
		if ( Virtual_Site_Router::get_current_virtual_site() ) {
			/**
			 * Escape hatch: return true to allow WP core redirect_canonical
			 * even on virtual-site requests.
			 *
			 * @since 2.2.0
			 * @param bool   $allow_redirect Whether to allow.
			 * @param string $redirect_url   Target.
			 * @param string $requested_url  Requested.
			 */
			if ( apply_filters( 'wptsall_allow_canonical_redirect', false, $redirect_url, $requested_url ) ) {
				return $redirect_url;
			}
			return false;
		}
		return $redirect_url;
	}

	/**
	 * Rewrite Yoast sitemap entry loc to the virtual-site URL when applicable.
	 *
	 * @since 2.2.0
	 *
	 * @param array  $url  Sitemap entry.
	 * @param string $type Entry type.
	 * @param object $object Post/term object.
	 * @return array
	 */
	public static function filter_yoast_sitemap_entry( $url, $type = '', $object = null ) {
		if ( ! is_array( $url ) || empty( $url['loc'] ) ) {
			return $url;
		}
		$vs = Virtual_Site_Router::get_current_virtual_site();
		// During sitemap generation there is often no "current" VS; still attach
		// alternates when we can resolve a post id from the object.
		$post_id = 0;
		if ( is_object( $object ) && isset( $object->ID ) ) {
			$post_id = (int) $object->ID;
		} elseif ( is_numeric( $object ) ) {
			$post_id = (int) $object;
		}
		// Polylang/WPML pattern: source URL is the sitemap <loc>; shadow copies
		// appear only as xhtml:link alternates — never as duplicate primary locs.
		if ( $post_id > 0 && self::is_virtual_shadow_post( $post_id ) ) {
			return false;
		}
		if ( $vs && is_singular() ) {
			$url['loc'] = self::filter_canonical_url( (string) $url['loc'], null );
		}
		if ( $post_id > 0 ) {
			$alts = self::build_sitemap_alternates( $post_id );
			if ( ! empty( $alts ) ) {
				$url['images'] = isset( $url['images'] ) && is_array( $url['images'] ) ? $url['images'] : array();
				$map = array();
				foreach ( $alts as $row ) {
					if ( empty( $row['hreflang'] ) || empty( $row['href'] ) ) {
						continue;
					}
					$map[ (string) $row['hreflang'] ] = (string) $row['href'];
				}
				// Yoast / WPML-style alternate language payloads on the entry.
				$url['alternates']     = $alts;
				$url['alternateLangs'] = $map;
			}
		}
		return $url;
	}

	/**
	 * Whether a post is a virtual-site shadow (translated copy), not the source.
	 *
	 * @since 2.3.0
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_virtual_shadow_post( int $post_id ): bool {
		if ( class_exists( '\\WPTSALL\\Sites\\Services\\Translation_Identity' ) ) {
			return \WPTSALL\Sites\Services\Translation_Identity::is_shadow_post( $post_id );
		}
		if ( $post_id <= 0 ) {
			return false;
		}
		// Fallback when identity service is unavailable: still bypass get_post_meta.
		$vs  = (string) Virtual_Site_Query_Switch::raw_post_meta( $post_id, '_wptsall_virtual_site_id' );
		$src = (int) Virtual_Site_Query_Switch::raw_post_meta( $post_id, '_wptsall_source_post_id' );
		return '' !== $vs && $src > 0;
	}

	/**
	 * Ensure Yoast urlset declares the xhtml namespace for hreflang links.
	 *
	 * @since 2.3.0
	 *
	 * @param string $urlset Opening urlset markup.
	 * @return string
	 */
	public static function filter_yoast_sitemap_urlset( $urlset ) {
		if ( ! is_string( $urlset ) || '' === $urlset || false !== strpos( $urlset, 'xmlns:xhtml' ) ) {
			return $urlset;
		}
		if ( preg_match( '/^(<urlset\b[^>]*)>/', $urlset, $m ) ) {
			return $m[1] . ' xmlns:xhtml="http://www.w3.org/1999/xhtml">' . substr( $urlset, strlen( $m[0] ) );
		}
		return $urlset;
	}

	/**
	 * Build xhtml:link-style alternate list for a post (sitemap).
	 *
	 * @since 2.2.0
	 *
	 * @param int $post_id Post ID.
	 * @return array<int,array{hreflang:string,href:string}>
	 */
	public static function build_sitemap_alternates( int $post_id ): array {
		$hreflangs = array();
		$vs        = Virtual_Site_Router::get_current_virtual_site();
		if ( $vs ) {
			$self = self::build_virtual_url( $vs, $post_id );
			if ( $self ) {
				$hreflangs[ self::normalize_lang( $vs['lang'] ?? get_locale() ) ] = $self;
			}
			$hreflangs['x-default'] = self::canonical_source_permalink( self::resolve_canonical_source_id( $post_id ) );
		} else {
			$hreflangs['x-default'] = (string) get_permalink( $post_id );
		}
		$hreflangs = self::collect_alternate_hreflangs( $post_id, $hreflangs );
		$hreflangs = apply_filters( 'wptsall_rel_hreflang_attributes', $hreflangs, $post_id, $vs ?: array() );
		$out       = array();
		foreach ( $hreflangs as $lang => $href ) {
			if ( empty( $href ) || empty( $lang ) ) {
				continue;
			}
			$out[] = array(
				'hreflang' => (string) $lang,
				'href'     => (string) $href,
			);
		}
		return $out;
	}

	/**
	 * Inject xhtml:link alternates into Yoast sitemap <url> XML.
	 *
	 * Yoast's `wpseo_sitemap_url` filter receives the rendered XML snippet as
	 * the first argument and the entry array as the second — not a bare URL.
	 * Setting `$url['alternates']` alone does not emit tags; XML inject is required
	 * (same contract as Rank Math / WPML SEO).
	 *
	 * @since 2.2.0
	 *
	 * @param string     $output Rendered <url>…</url> XML.
	 * @param array|null $url    Sitemap entry array (may carry alternates).
	 * @return string
	 */
	public static function filter_yoast_sitemap_url( $output, $url = null ) {
		return self::filter_rankmath_sitemap_url_xml( $output, $url );
	}

	/**
	 * Rank Math canonical bridge (Polylang / Rank Math docs: rank_math/frontend/canonical).
	 *
	 * @since 2.2.0
	 *
	 * @param string $canonical Canonical URL.
	 * @return string
	 */
	public static function filter_rankmath_canonical( $canonical ) {
		$override = self::filter_canonical_url( (string) $canonical, null );
		return is_string( $override ) && '' !== $override ? $override : $canonical;
	}

	/**
	 * AIOSEO canonical — same Url_Converter / x-default pipeline as Yoast/RM.
	 *
	 * @since 2.3.0
	 * @param string $canonical Canonical URL.
	 * @return string
	 */
	public static function filter_aioseo_canonical( $canonical ) {
		return self::filter_rankmath_canonical( $canonical );
	}

	/**
	 * AIOSEO sitemap post/term entry: drop shadows; attach alternates when possible.
	 *
	 * @since 2.3.0
	 * @param mixed $entry   Entry array or false.
	 * @param mixed $object  Post/term id or object.
	 * @return mixed
	 */
	public static function filter_aioseo_sitemap_post( $entry, $object = null ) {
		$post_id = 0;
		if ( is_object( $object ) && isset( $object->ID ) ) {
			$post_id = (int) $object->ID;
		} elseif ( is_numeric( $object ) ) {
			$post_id = (int) $object;
		} elseif ( is_array( $entry ) && ! empty( $entry['id'] ) ) {
			$post_id = (int) $entry['id'];
		}
		if ( $post_id > 0 && self::is_virtual_shadow_post( $post_id ) ) {
			return false;
		}
		if ( ! is_array( $entry ) ) {
			return $entry;
		}
		if ( ! empty( $entry['loc'] ) || ! empty( $entry['guid'] ) ) {
			$key = ! empty( $entry['loc'] ) ? 'loc' : 'guid';
			$vs  = Virtual_Site_Router::get_current_virtual_site();
			if ( $vs && class_exists( '\\WPTSALL\\Sites\\Services\\Url_Converter' ) ) {
				$entry[ $key ] = \WPTSALL\Sites\Services\Url_Converter::virtualize( (string) $entry[ $key ], $vs );
			}
		}
		if ( $post_id > 0 ) {
			$alts = self::build_sitemap_alternates( $post_id );
			if ( ! empty( $alts ) ) {
				$entry['alternates'] = $alts;
			}
		}
		return $entry;
	}

	/**
	 * SEOPress canonical bridge.
	 *
	 * @since 2.3.0
	 * @param string $canonical Canonical URL.
	 * @return string
	 */
	public static function filter_seopress_canonical( $canonical ) {
		return self::filter_rankmath_canonical( $canonical );
	}

	/**
	 * SEOPress sitemap URL XML / entry — inject xhtml:link via shared Rank Math helper.
	 *
	 * @since 2.3.0
	 * @param mixed $output URL XML string or entry.
	 * @param mixed $url    Entry context.
	 * @return mixed
	 */
	public static function filter_seopress_sitemaps_url( $output, $url = null ) {
		if ( is_array( $output ) ) {
			return self::filter_yoast_sitemap_entry( $output, 'post', $url );
		}
		return self::filter_rankmath_sitemap_url_xml( $output, $url );
	}

	/**
	 * Attach alternateLangs to Rank Math sitemap entries (WPML RankMathSEO AlternateLangHooks key).
	 *
	 * @since 2.2.0
	 *
	 * @param array|null $url    Entry.
	 * @param string     $type   post|term|user.
	 * @param object     $object Object.
	 * @return array|null
	 */
	public static function filter_rankmath_sitemap_entry( $url, $type = '', $object = null ) {
		if ( ! is_array( $url ) || empty( $url['loc'] ) ) {
			return $url;
		}
		$post_id = 0;
		if ( 'post' === $type && is_object( $object ) && isset( $object->ID ) ) {
			$post_id = (int) $object->ID;
		} elseif ( is_object( $object ) && isset( $object->ID ) ) {
			$post_id = (int) $object->ID;
		}
		if ( $post_id > 0 && self::is_virtual_shadow_post( $post_id ) ) {
			return false;
		}
		$vs = Virtual_Site_Router::get_current_virtual_site();
		if ( $vs && class_exists( '\\WPTSALL\\Sites\\Services\\Url_Converter' ) ) {
			$url['loc'] = \WPTSALL\Sites\Services\Url_Converter::virtualize( (string) $url['loc'], $vs );
		}
		if ( $post_id > 0 ) {
			$alts = self::build_sitemap_alternates( $post_id );
			$map  = array();
			foreach ( $alts as $row ) {
				if ( empty( $row['hreflang'] ) || empty( $row['href'] ) ) {
					continue;
				}
				$map[ (string) $row['hreflang'] ] = (string) $row['href'];
			}
			if ( ! empty( $map ) ) {
				// WPML Rank Math key consumed by filter_rankmath_sitemap_url_xml.
				$url['alternateLangs'] = $map;
				$url['alternates']     = $alts;
			}
		}
		return $url;
	}

	/**
	 * Inject xhtml:link alternates into Rank Math sitemap URL XML (WPML insertAlternateLinks).
	 *
	 * @since 2.2.0
	 *
	 * @param string     $output XML snippet or loc string.
	 * @param array|null $url    Entry array when available.
	 * @return string
	 */
	public static function filter_rankmath_sitemap_url_xml( $output, $url = null ) {
		if ( ! is_string( $output ) || '' === $output ) {
			return $output;
		}
		$alts = array();
		if ( is_array( $url ) && ! empty( $url['alternateLangs'] ) && is_array( $url['alternateLangs'] ) ) {
			$alts = $url['alternateLangs'];
		} elseif ( is_array( $url ) && ! empty( $url['alternates'] ) && is_array( $url['alternates'] ) ) {
			foreach ( $url['alternates'] as $row ) {
				if ( ! empty( $row['hreflang'] ) && ! empty( $row['href'] ) ) {
					$alts[ (string) $row['hreflang'] ] = (string) $row['href'];
				}
			}
		}
		if ( empty( $alts ) ) {
			return $output;
		}
		$links = '';
		foreach ( $alts as $lang => $href ) {
			$links .= '<xhtml:link rel="alternate" hreflang="' . esc_attr( (string) $lang ) . '" href="' . esc_url( (string) $href ) . '" />';
		}
		if ( false !== strpos( $output, '</loc>' ) ) {
			return str_replace( '</loc>', '</loc>' . $links, $output );
		}
		return $output;
	}

	/**
	 * Treat virtual-prefix paths as internal for Rank Math sitemap (WPML DirectoryHooks).
	 *
	 * @since 2.2.0
	 *
	 * @param bool|null $override Existing override.
	 * @param array     $url_parts Parsed URL parts.
	 * @return bool|null
	 */
	public static function filter_rankmath_is_external( $override, $url_parts = array() ) {
		if ( ! is_array( $url_parts ) ) {
			return $override;
		}
		$host = (string) ( $url_parts['host'] ?? '' );
		$path = (string) ( $url_parts['path'] ?? '' );
		$home_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		if ( '' !== $host && $host !== $home_host ) {
			return $override;
		}
		$prefixes = self::get_virtual_path_prefixes();
		if ( empty( $prefixes ) ) {
			return $override;
		}
		$trim = trim( $path, '/' );
		$first = '' === $trim ? '' : explode( '/', $trim )[0];
		if ( '' === $first || in_array( $first, $prefixes, true ) ) {
			// false = internal (include in sitemap).
			return false;
		}
		return $override;
	}

	/**
	 * 404 Rank Math sitemap requests under a virtual path prefix (WPML DirectoryHooks).
	 *
	 * @since 2.2.0
	 *
	 * @param \WP_Query $query Query.
	 * @return void
	 */
	public static function rankmath_catch_sitemap_on_virtual_prefix( $query ) {
		if ( ! $query instanceof \WP_Query || ! $query->is_main_query() ) {
			return;
		}
		if ( ! $query->get( 'sitemap' ) && ! $query->get( 'sitemap_n' ) ) {
			// Rank Math may use 'sitemap' query var; also check request path.
			$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
			if ( ! preg_match( '#/(?:sitemap_index\.xml|[\w-]+-sitemap\d*\.xml)(?:$|\?)#i', $uri ) ) {
				return;
			}
		}
		$vs = Virtual_Site_Router::get_current_virtual_site();
		if ( ! $vs ) {
			return;
		}
		$query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * Active virtual site path prefixes (no slashes).
	 *
	 * @since 2.2.0
	 * @return string[]
	 */
	private static function get_virtual_path_prefixes(): array {
		$out = array();
		if ( class_exists( '\\WPTSALL\\Sites\\Services\\Virtual_Site_Service' ) ) {
			$sites = \WPTSALL\Sites\Services\Virtual_Site_Service::get_all();
			foreach ( (array) $sites as $site ) {
				$p = trim( (string) ( $site['path_prefix'] ?? '' ), '/' );
				if ( '' !== $p ) {
					$out[] = $p;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Inject lang attribute(s) for the virtual-site locale.
	 *
	 * @since 1.5.0
	 *
	 * @param string $output language_attributes() output.
	 * @param string $doctype Doctype (unused).
	 * @return string
	 */
	public static function filter_language_attributes( $output, $doctype = 'html' ) {
		$vs = Virtual_Site_Router::get_current_virtual_site();
		if ( ! $vs ) {
			return $output;
		}
		$lang = self::normalize_lang( $vs['lang'] ?? get_locale() );
		if ( '' === $lang ) {
			return $output;
		}
		if ( false !== strpos( $output, 'lang="' ) ) {
			return (string) preg_replace( '/lang="[^"]*"/', 'lang="' . esc_attr( $lang ) . '"', $output, 1 );
		}
		return rtrim( $output ) . ' lang="' . esc_attr( $lang ) . '"';
	}

	/**
	 * Rewrite the post permalink in core WP sitemaps to the virtual-site URL.
	 *
	 * @since 1.5.0
	 *
	 * @param array  $entry     Sitemap entry.
	 * @param string $post_type Post type.
	 * @param object $post      Post object.
	 * @return array
	 */
	public static function filter_sitemap_post_entry( $entry, $post_type, $post ) {
		$post_id = 0;
		if ( is_object( $post ) && isset( $post->ID ) ) {
			$post_id = (int) $post->ID;
		} elseif ( is_numeric( $post ) ) {
			$post_id = (int) $post;
		}
		if ( $post_id > 0 && self::is_virtual_shadow_post( $post_id ) ) {
			return false;
		}
		if ( ! is_array( $entry ) ) {
			return $entry;
		}
		// Source posts: attach alternates for SEO-plugin bridges / custom consumers.
		// Core WP sitemap XML ignores this key — production should use Yoast/RM/AIOSEO/SEOPress
		// for xhtml:link (see SEO settings notice).
		if ( $post_id > 0 && ! self::is_virtual_shadow_post( $post_id ) ) {
			$alts = self::build_sitemap_alternates( $post_id );
			if ( ! empty( $alts ) ) {
				$entry['alternates'] = $alts;
			}
		}
		$vs = Virtual_Site_Router::get_current_virtual_site();
		if ( ! $vs || empty( $entry['loc'] ) ) {
			return $entry;
		}
		// Single URL mutator (TranslatePress Url_Converter pattern) — avoids
		// double-prefix from manual path concat when loc is already virtualized.
		if ( class_exists( '\\WPTSALL\\Sites\\Services\\Url_Converter' ) ) {
			$entry['loc'] = \WPTSALL\Sites\Services\Url_Converter::virtualize( (string) $entry['loc'], $vs );
			return $entry;
		}
		$host   = wp_parse_url( home_url(), PHP_URL_HOST );
		$path   = (string) wp_parse_url( $entry['loc'], PHP_URL_PATH );
		$prefix = trim( (string) ( $vs['path_prefix'] ?? '' ), '/' );
		$entry['loc'] = ( is_ssl() ? 'https://' : 'http://' ) . $host . '/' . $prefix . '/' . ltrim( $path, '/' );
		return $entry;
	}

	/**
	 * Rewrite the term permalink in core WP sitemaps to the virtual-site URL.
	 *
	 * @since 1.5.0
	 *
	 * @param array  $entry    Sitemap entry.
	 * @param string $taxonomy Taxonomy name.
	 * @param object $term     Term object.
	 * @return array
	 */
	public static function filter_sitemap_tax_entry( $entry, $taxonomy, $term ) {
		return self::filter_sitemap_post_entry( $entry, $taxonomy, $term );
	}

	/**
	 * Filter Yoast's frontend presenters (OpenGraph, Twitter, schema).
	 *
	 * When Yoast is the canonical source of head tags, wptsall only
	 * translates the title via document_title_parts (already wired).
	 * Presenter-level intervention is left to future versions.
	 *
	 * @since 1.5.0
	 *
	 * @param array  $presenters Yoast presenter instances.
	 * @param object $context    Yoast context.
	 * @return array
	 */
	/**
	 * Compatibility shim. The real Yoast bridge now runs on `wpseo_head`
	 * (see init()). Kept as a no-op so third-party code calling it does not
	 * fatal.
	 *
	 * @since 1.5.0
	 *
	 * @param array  $presenters Existing Yoast presenter instances.
	 * @param object $context    Yoast context.
	 * @return array
	 */
	public static function filter_yoast_presenters( $presenters, $context = null ) {
		return $presenters;
	}

	/**
	 * Output a small HTML comment marker at the end of the page so that
	 * operators can confirm the virtual-site context is active.
	 *
	 * Themes that want a real switcher can use the
	 * `[wptsall_language_switcher]` shortcode.
	 *
	 * @since 1.5.0
	 *
	 * @return void
	 */
	public static function output_language_switcher_marker() {
		$vs = Virtual_Site_Router::get_current_virtual_site();
		if ( ! $vs ) {
			return;
		}
		$prefix = trim( (string) ( $vs['path_prefix'] ?? '' ), '/' );
		$lang   = self::normalize_lang( (string) ( $vs['lang'] ?? '' ) );
		echo "\n<!-- wptsall virtual site: lang="
			. esc_html( $lang )
			. ' prefix='
			. esc_html( $prefix )
			. " -->\n";
	}
}

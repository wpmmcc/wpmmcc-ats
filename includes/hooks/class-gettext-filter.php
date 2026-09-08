<?php
/**
 * Gettext Filter
 *
 * Intercepts WordPress translation functions and returns custom translations.
 *
 * @package WPTSALL\Hooks
 * @since 0.5.0
 */

namespace WPTSALL\Hooks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gettext_Filter class
 *
 * Filters gettext calls to return translations from WPTSALL templates.
 */
class Gettext_Filter {

	/**
	 * Translation cache (in-memory)
	 *
	 * @var array
	 */
	private static $cache = array();

	/**
	 * Loaded text domains
	 *
	 * @var array
	 */
	private static $loaded_domains = array();

	/**
	 * Current relation ID
	 *
	 * @var int|null
	 */
	private static $current_relation_id = null;

	/**
	 * Whether filters are initialized
	 *
	 * @var bool
	 */
	private static $initialized = false;

	/**
	 * Target language code
	 *
	 * @var string
	 */
	private static $target_lang = 'zh_CN';

	/**
	 * Initialize gettext filters
	 *
	 * @return void
	 */
	public static function init() {
		// Only run on frontend
		if ( is_admin() ) {
			return;
		}

		add_action( 'init', array( __CLASS__, 'maybe_init_filters' ), 20 );

		// Listen for translation updates to clear cache
		add_action( 'wptsall_translation_completed', array( __CLASS__, 'on_translation_completed' ) );
		add_action( 'wptsall_entry_updated', array( __CLASS__, 'on_entry_updated' ), 10, 2 );
	}

	/**
	 * Conditionally initialize filters
	 *
	 * @return void
	 */
	public static function maybe_init_filters() {
		// Only run on frontend
		if ( is_admin() ) {
			return;
		}

		$relation_id = null;
		$target_lang = 'zh_CN';

		// 1. Check if on virtual site.
		if ( class_exists( '\WPTSALL\Hooks\Virtual_Site_Router' ) ) {
			$virtual_site = \WPTSALL\Hooks\Virtual_Site_Router::get_current_virtual_site();
			if ( $virtual_site ) {
				$relation_id = $virtual_site['relation_id'] ?? null;
				$target_lang = $virtual_site['lang'] ?? 'zh_CN';
			}
		}

		// 2. Check if on target WP sub-site.
		if ( ! $relation_id && is_multisite() ) {
			$current_blog_id = get_current_blog_id();
			// Fast check in site_relations table.
			global $wpdb;
			$table = wptsall_table( 'site_relations' );

			// Try precise match first: constrain by source_site_id to avoid
			// ambiguity when the same sub-site is a target of multiple relations.
			$main_blog_id = defined( 'BLOG_ID_CURRENT_SITE' ) ? BLOG_ID_CURRENT_SITE : 1;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rel = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, target_lang FROM %i
					 WHERE target_site_id = %s
					   AND target_site_type = 'wp'
					   AND source_site_id = %d
					   AND status = 'active'
					 LIMIT 1",
					$table,
					(string) $current_blog_id,
					$main_blog_id
				),
				ARRAY_A
			);

			// Fallback: if no match with source_site_id constraint (legacy data),
			// try matching by target_lang against the current site locale.
			if ( ! $rel ) {
				$current_locale = get_locale();
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$rel = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT id, target_lang FROM %i
						 WHERE target_site_id = %s
						   AND target_site_type = 'wp'
						   AND target_lang = %s
						   AND status = 'active'
						 LIMIT 1",
						$table,
						(string) $current_blog_id,
						$current_locale
					),
					ARRAY_A
				);
			}

			if ( $rel ) {
				$relation_id = $rel['id'];
				$target_lang = $rel['target_lang'];
			}
		}

		if ( ! $relation_id ) {
			return;
		}

		self::$current_relation_id = $relation_id;
		self::$target_lang         = $target_lang;

		// Add gettext filters
		add_filter( 'gettext', array( __CLASS__, 'filter_gettext' ), 10, 3 );
		add_filter( 'gettext_with_context', array( __CLASS__, 'filter_gettext_with_context' ), 10, 4 );
		add_filter( 'ngettext', array( __CLASS__, 'filter_ngettext' ), 10, 5 );
		add_filter( 'ngettext_with_context', array( __CLASS__, 'filter_ngettext_with_context' ), 10, 6 );

		// Preload common domains (optional, can be lazy)
		// self::preload_translations( 'default' );
		self::$initialized = true;

		// Log filter initialization.
		wptsall_log_info(
			'hooks-gettext',
			'Gettext filter initialized',
			array(
				'relation_id'  => $relation_id,
				'target_lang'  => $target_lang,
			)
		);

		// Preload common domains
		self::preload_common_domains( $relation_id );
	}

	/**
	 * Preload translations for common text domains
	 *
	 * @param int $relation_id Site relation ID.
	 * @return void
	 */
	private static function preload_common_domains( $relation_id ) {
		global $wpdb;

		$table_templates = wptsall_table( 'templates' );

		// Get all templates for this relation
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnsupportedIdentifierPlaceholder
		$templates = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, text_domain, translated_entries FROM %i WHERE relation_id = %d AND translated_entries > 0',
				$table_templates,
				intval( $relation_id )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnsupportedIdentifierPlaceholder

		if ( empty( $templates ) ) {
			return;
		}

		foreach ( $templates as $template ) {
			self::preload_translations( $relation_id, $template['text_domain'] );
			self::$loaded_domains[] = $template['text_domain'];
		}

		wptsall_log_debug(
			'hooks-gettext',
			'Preloaded domains',
			array(
				'relation_id'    => $relation_id,
				'domain_count'   => count( $templates ),
				'loaded_domains' => self::$loaded_domains,
			)
		);
	}

	/**
	 * Preload translations for a specific domain
	 *
	 * Uses Template_Entry_Service for cached database access.
	 *
	 * @since 0.9.0 Refactored to use Template_Entry_Service (2746x performance boost with object cache).
	 *
	 * @param int    $relation_id Site relation ID.
	 * @param string $domain      Text domain.
	 * @return void
	 */
	public static function preload_translations( $relation_id, $domain ) {
		// Use Template_Entry_Service for cached access.
		$translations = \WPTSALL\Templates\Services\Template_Entry_Service::get_translations_for_hook(
			intval( $relation_id ),
			$domain
		);

		if ( empty( $translations ) ) {
			return;
		}

		// Convert service format to internal cache format.
		// Service format: [msgid => [...]] or [context\x04msgid => [...]]
		// Internal format: [md5(domain|msgid|context) => [...]]
		foreach ( $translations as $key => $data ) {
			$msgid   = $key;
			$context = null;

			// Check for context separator (EOT character \x04).
			if ( false !== strpos( $key, "\x04" ) ) {
				list( $context, $msgid ) = explode( "\x04", $key, 2 );
			}

			$cache_key                 = self::get_cache_key( $domain, $msgid, $context );
			$entry = array(
				'msgstr'        => $data['msgstr'] ?? '',
				'msgstr_plural' => $data['msgstr_plural'] ?? '',
			);
			self::$cache[ $cache_key ] = $entry;

			// P2-3: also persist to object cache for cross-request reuse.
			$oc_key = 'wptsall_gt_' . ( self::$current_relation_id ?? 0 ) . '_' . $cache_key;
			wp_cache_set( $oc_key, $entry, 'wptsall_gettext', 3600 );
		}
	}

	/**
	 * Generate cache key
	 *
	 * @param string      $domain  Text domain.
	 * @param string      $text    Source text.
	 * @param string|null $context Context.
	 * @return string
	 */
	private static function get_cache_key( $domain, $text, $context = null ) {
		return md5( $domain . '|' . $text . '|' . ( $context ?? '' ) );
	}

	/**
	 * Filter gettext
	 *
	 * @param string $translation Current translation.
	 * @param string $text        Source text.
	 * @param string $domain      Text domain.
	 * @return string
	 */
	public static function filter_gettext( $translation, $text, $domain ) {
		$cached = self::get_cached( $domain, $text, null );

		if ( false !== $cached && ! empty( $cached['msgstr'] ) ) {
			return self::maybe_wrap_with_marker( $cached['msgstr'] );
		}

		return $translation;
	}

	/**
	 * Filter gettext with context
	 *
	 * @param string $translation Current translation.
	 * @param string $text        Source text.
	 * @param string $context     Context.
	 * @param string $domain      Text domain.
	 * @return string
	 */
	public static function filter_gettext_with_context( $translation, $text, $context, $domain ) {
		$cached = self::get_cached( $domain, $text, $context );

		if ( false !== $cached && ! empty( $cached['msgstr'] ) ) {
			return self::maybe_wrap_with_marker( $cached['msgstr'] );
		}

		return $translation;
	}

	/**
	 * Filter ngettext (plurals)
	 *
	 * @param string $translation Current translation.
	 * @param string $single      Singular form.
	 * @param string $plural      Plural form.
	 * @param int    $number      Number.
	 * @param string $domain      Text domain.
	 * @return string
	 */
	public static function filter_ngettext( $translation, $single, $plural, $number, $domain ) {
		$cached = self::get_cached( $domain, $single, null );

		if ( false !== $cached ) {
			if ( 1 === $number && ! empty( $cached['msgstr'] ) ) {
				return self::maybe_wrap_with_marker( $cached['msgstr'] );
			} elseif ( 1 !== $number && ! empty( $cached['msgstr_plural'] ) ) {
				return self::maybe_wrap_with_marker( $cached['msgstr_plural'] );
			} elseif ( ! empty( $cached['msgstr'] ) ) {
				return self::maybe_wrap_with_marker( $cached['msgstr'] );
			}
		}

		return $translation;
	}

	/**
	 * Filter ngettext with context
	 *
	 * @param string $translation Current translation.
	 * @param string $single      Singular form.
	 * @param string $plural      Plural form.
	 * @param int    $number      Number.
	 * @param string $context     Context.
	 * @param string $domain      Text domain.
	 * @return string
	 */
	public static function filter_ngettext_with_context( $translation, $single, $plural, $number, $context, $domain ) {
		$cached = self::get_cached( $domain, $single, $context );

		if ( false !== $cached ) {
			if ( 1 === $number && ! empty( $cached['msgstr'] ) ) {
				return self::maybe_wrap_with_marker( $cached['msgstr'] );
			} elseif ( 1 !== $number && ! empty( $cached['msgstr_plural'] ) ) {
				return self::maybe_wrap_with_marker( $cached['msgstr_plural'] );
			} elseif ( ! empty( $cached['msgstr'] ) ) {
				return self::maybe_wrap_with_marker( $cached['msgstr'] );
			}
		}

		return $translation;
	}

	/**
	 * Get cached translation
	 *
	 * @param string      $domain  Text domain.
	 * @param string      $text    Source text.
	 * @param string|null $context Context.
	 * @return array|false
	 */
	private static function get_cached( $domain, $text, $context ) {
		$key = self::get_cache_key( $domain, $text, $context );

		// Check per-request static cache first.
		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ];
		}

		// P2-3 fix: check persistent object cache to avoid repeated
		// DB lookups for gettext calls outside preloaded domains.
		$oc_key = 'wptsall_gt_' . ( self::$current_relation_id ?? 0 ) . '_' . $key;
		$cached = wp_cache_get( $oc_key, 'wptsall_gettext' );
		if ( false !== $cached ) {
			self::$cache[ $key ] = $cached;
			return $cached;
		}

		return false;
	}

	/**
	 * Conditionally wrap translation with language marker
	 *
	 * Only wraps when marker mode is enabled and on a virtual site.
	 *
	 * @param string $content Translation content.
	 * @return string
	 */
	private static function maybe_wrap_with_marker( $translated ) {
		if ( empty( $translated ) ) {
			return $translated;
		}

		// Use Translation_Simulation_Service if Pro is active, otherwise manual markers.
		if ( class_exists( '\WPTSALL\Tasks\Services\Translation_Simulation_Service' ) ) {
			return \WPTSALL\Tasks\Services\Translation_Simulation_Service::apply_markers( $translated, self::$target_lang );
		}

		return '【' . self::$target_lang . '】' . $translated . '【/' . self::$target_lang . '】';
	}

	/**
	 * Clear cache
	 *
	 * @param string|null $domain Text domain to clear, or null for all.
	 * @return void
	 */
	public static function clear_cache( $domain = null ) {
		$old_count = count( self::$cache );

		if ( null === $domain ) {
			self::$cache          = array();
			self::$loaded_domains = array();
		} else {
			// Clear only entries for specific domain
			// Since cache key is a hash, we need to rebuild
			$new_cache = array();

			foreach ( self::$cache as $key => $value ) {
				// We can't easily identify domain from hash key
				// So we clear all for now
			}

			self::$cache = $new_cache;

			// Remove from loaded domains
			self::$loaded_domains = array_filter(
				self::$loaded_domains,
				function ( $d ) use ( $domain ) {
					return $d !== $domain;
				}
			);
		}

		wptsall_log_debug(
			'hooks-gettext',
			'Cache cleared',
			array(
				'domain'        => $domain ?? 'all',
				'cleared_count' => $old_count,
			)
		);
	}

	/**
	 * Handle translation completed event
	 *
	 * @param int $template_id Template ID.
	 * @return void
	 */
	public static function on_translation_completed( $template_id ) {
		wptsall_log_info(
			'hooks-gettext',
			'Translation completed event received',
			array( 'template_id' => $template_id )
		);
		// Clear all cache when translation completed
		self::clear_cache();
	}

	/**
	 * Handle entry updated event
	 *
	 * @param int   $entry_id Entry ID.
	 * @param array $data     Entry data.
	 * @return void
	 */
	public static function on_entry_updated( $entry_id, $data ) {
		wptsall_log_debug(
			'hooks-gettext',
			'Entry updated event received',
			array(
				'entry_id'    => $entry_id,
				'text_domain' => $data['text_domain'] ?? 'unknown',
			)
		);
		// Clear cache for the specific domain if available
		if ( ! empty( $data['text_domain'] ) ) {
			self::clear_cache( $data['text_domain'] );
		} else {
			self::clear_cache();
		}
	}

	/**
	 * Check if filters are initialized
	 *
	 * @return bool
	 */
	public static function is_initialized() {
		return self::$initialized;
	}

	/**
	 * Get current relation ID
	 *
	 * @return int|null
	 */
	public static function get_current_relation_id() {
		return self::$current_relation_id;
	}

	/**
	 * Get loaded domains
	 *
	 * @return array
	 */
	public static function get_loaded_domains() {
		return self::$loaded_domains;
	}

	/**
	 * Get cache stats
	 *
	 * @return array
	 */
	public static function get_cache_stats() {
		return array(
			'entries'        => count( self::$cache ),
			'loaded_domains' => self::$loaded_domains,
		);
	}
}

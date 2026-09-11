<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and intentional meta/tax lookups; all dynamic values go through $wpdb->prepare() / wptsall_db_* helpers (no unprepared user input).
/**
 * Absolute path to the WordPress languages directory (no WP_LANG_DIR constant).
 *
 * Derived from content directory + "languages" (standard layout).
 *
 * @since 1.9.0
 * @return string Normalized path without trailing slash.
 */
function wptsall_get_lang_dir() {
	static $dir = null;
	if ( null !== $dir ) {
		return $dir;
	}
	$dir = wp_normalize_path( wptsall_get_content_dir() . '/languages' );
	return $dir;
}

/**
 * Sanitize current request URI for path/query routing (not a full URL).
 *
 * Prefer this over esc_url_raw( $_SERVER['REQUEST_URI'] ): REQUEST_URI is a path,
 * not a scheme/host URL.
 *
 * @since 1.9.0
 * @return string Sanitized path and optional query string (e.g. "/en/page/?x=1").
 */
function wptsall_get_request_uri() {
	if ( empty( $_SERVER['REQUEST_URI'] ) ) {
		return '';
	}

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below.
	$raw = (string) wp_unslash( $_SERVER['REQUEST_URI'] );
	// Parse as URL path using a dummy host so relative paths work.
	$parts = wp_parse_url( 'http://wptsall.local' . ( isset( $raw[0] ) && '/' === $raw[0] ? $raw : '/' . $raw ) );
	$path  = isset( $parts['path'] ) ? (string) $parts['path'] : '';
	$query = isset( $parts['query'] ) ? (string) $parts['query'] : '';

	$path = sanitize_text_field( $path );
	if ( '' !== $query ) {
		// Keep query string but strip control characters.
		$query = sanitize_text_field( $query );
		return $path . '?' . $query;
	}

	return $path;
}

/**
 * Current virtual-site / request language (locale), or '' on the source site.
 *
 * @since 2.0.1
 * @return string
 */
function wptsall_current_language() {
	if ( class_exists( '\\WPTSALL\\Core\\Language_Context' ) ) {
		return \WPTSALL\Core\Language_Context::current_language();
	}
	/**
	 * Filter current language when Language_Context is unavailable.
	 *
	 * @param string $lang Language code.
	 */
	return (string) apply_filters( 'wptsall_current_language', '' );
}

/**
 * Whether the current stack is an internal WPTSALL write-back.
 *
 * @since 2.0.1
 * @return bool
 */
function wptsall_is_internal_write() {
	return class_exists( '\\WPTSALL\\Hooks\\Content_Change_Dispatcher' )
		&& \WPTSALL\Hooks\Content_Change_Dispatcher::is_internal_write();
}


/**
 * Absolute path to the WordPress plugins directory (no WP_PLUGIN_DIR constant).
 *
 * Derived from this plugin's main file so custom content/plugin layouts still work.
 *
 * @since 1.9.0
 * @return string Normalized path without trailing slash.
 */
function wptsall_get_plugins_dir() {
	static $dir = null;
	if ( null !== $dir ) {
		return $dir;
	}
	if ( defined( 'WPTSALL_FILE' ) ) {
		$dir = wp_normalize_path( dirname( plugin_dir_path( WPTSALL_FILE ) ) );
	} elseif ( defined( 'WPTSALL_PATH' ) ) {
		$dir = wp_normalize_path( dirname( WPTSALL_PATH ) );
	} else {
		$dir = wp_normalize_path( dirname( dirname( dirname( __DIR__ ) ) ) );
	}
	return $dir;
}

/**
 * Absolute path to wp-content (no WP_CONTENT_DIR constant).
 *
 * @since 1.9.0
 * @return string Normalized path without trailing slash.
 */
function wptsall_get_content_dir() {
	static $dir = null;
	if ( null !== $dir ) {
		return $dir;
	}
	// plugins live under wp-content/plugins; parent of plugins dir is content.
	$dir = wp_normalize_path( dirname( wptsall_get_plugins_dir() ) );
	return $dir;
}

/**
 * Resolve an installed plugin's absolute directory or file path without WP_PLUGIN_DIR.
 *
 * @since 1.9.0
 * @param string $plugin_file Plugin basename (e.g. akismet/akismet.php) or single-file plugin.
 * @param bool   $as_file     When true, return the main plugin file path; otherwise the directory.
 * @return string Absolute normalized path.
 */
function wptsall_resolve_plugin_path( $plugin_file, $as_file = false ) {
	$plugin_file = ltrim( str_replace( '\\', '/', (string) $plugin_file ), '/' );
	$base        = wptsall_get_plugins_dir() . '/' . $plugin_file;
	if ( $as_file || false === strpos( $plugin_file, '/' ) ) {
		return wp_normalize_path( $base );
	}
	// Directory for multi-file plugins: parent of main file.
	return wp_normalize_path( dirname( $base ) );
}

/**
 * Resolve a plugin directory by directory slug (e.g. "akismet").
 *
 * @since 1.9.0
 * @param string $plugin_slug Plugin directory slug or single-file name without .php.
 * @return string Absolute path to plugin directory or single-file path parent intent.
 */
function wptsall_resolve_plugin_dir_by_slug( $plugin_slug ) {
	$plugin_slug = sanitize_file_name( (string) $plugin_slug );
	return wp_normalize_path( wptsall_get_plugins_dir() . '/' . $plugin_slug );
}

/**
 * Whether a plugin directory slug refers to this plugin.
 *
 * @param string $plugin_slug Plugin directory slug.
 * @return bool
 */
function wptsall_is_self_plugin_slug( $plugin_slug ) {
	if ( defined( 'WPTSALL_BASENAME' ) ) {
		$self_dir = explode( '/', (string) WPTSALL_BASENAME, 2 )[0];
		if ( (string) $plugin_slug === $self_dir ) {
			return true;
		}
	}

	return in_array( (string) $plugin_slug, array( 'wptsall', 'wpmmcc-ats' ), true );
}


/**
 * Core functions for WPTSALL plugin.
 *
 * Note: Log functions have been moved to includes/log/logger.php
 * Use wptsall_log(), wptsall_log_info(), wptsall_log_debug(), etc.
 */

/**
 * Client REST route secret (URL path segment).
 *
 * ≥256-bit base64url; stored with autoload=no. Any legacy, malformed, or
 * weak value is upgraded in place on next read.  The route is part of the
 * security boundary, so accepting a merely "long enough" value is unsafe.
 *
 * @since 1.1.0
 * @since 2.0.1 Strengthened entropy + autoload=no (ISS S1).
 *
 * @return string Secret string (never log the full value).
 */
function wptsall_get_client_route_secret() {
	$secret = get_option( 'wptsall_client_route_secret', '' );
	if ( ! wptsall_is_valid_client_route_secret( $secret ) ) {
		$secret = wptsall_generate_client_route_secret();
		update_option( 'wptsall_client_route_secret', $secret, false );
		wptsall_set_option_autoload( 'wptsall_client_route_secret', false );
	}
	return (string) $secret;
}

/**
 * Validate the canonical route-secret representation.
 *
 * A 32-byte value encoded as unpadded base64url is exactly 43 characters.
 * Requiring the exact alphabet and length prevents weak/ambiguous legacy
 * values from remaining active and keeps URL matching deterministic.
 *
 * @since 2.1.1
 * @param mixed $secret Candidate value.
 * @return bool
 */
function wptsall_is_valid_client_route_secret( $secret ) {
	if ( ! is_string( $secret ) || 43 !== strlen( $secret ) ) {
		return false;
	}

	return 1 === preg_match( '/\A[A-Za-z0-9_-]{43}\z/', $secret );
}

/**
 * Generate a new route secret (≥256 bit).
 *
 * @since 2.0.1
 * @return string
 */
function wptsall_generate_client_route_secret() {
	$raw = random_bytes( 32 );
	return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
}

/**
 * Rotate route secret (hard cutover; no grace dual registration).
 *
 * Pre-release: `$grace_seconds` is ignored. Previous secret option is cleared.
 *
 * @since 2.0.1
 * @param int $grace_seconds Unused (kept for CLI flag signature).
 * @return array{new:string,previous:string}
 */
function wptsall_rotate_client_route_secret( $grace_seconds = 0 ) {
	unset( $grace_seconds );
	$previous = (string) get_option( 'wptsall_client_route_secret', '' );
	$new      = wptsall_generate_client_route_secret();
	update_option( 'wptsall_client_route_secret', $new, false );
	wptsall_set_option_autoload( 'wptsall_client_route_secret', false );
	delete_option( 'wptsall_client_route_secret_previous' );
	return array(
		'new'      => $new,
		'previous' => $previous,
	);
}

/**
 * Whether a candidate matches the current route secret.
 *
 * @since 2.0.1
 * @param string $candidate Candidate from URL.
 * @return bool
 */
function wptsall_route_secret_matches( $candidate ) {
	$candidate = (string) $candidate;
	if ( '' === $candidate ) {
		return false;
	}
	$current = (string) wptsall_get_client_route_secret();
	return wptsall_is_valid_client_route_secret( $candidate )
		&& hash_equals( $current, $candidate );
}

/**
 * Active route secrets (current only).
 *
 * @since 2.0.1
 * @return array<int,string>
 */
function wptsall_get_active_client_route_secrets() {
	$secrets = array();
	$current = function_exists( 'wptsall_get_client_route_secret' )
		? (string) wptsall_get_client_route_secret()
		: '';
	if ( '' !== $current ) {
		$secrets[] = $current;
	}
	return $secrets;
}

/**
 * Flip option autoload flag.
 *
 * @param string $option   Option name.
 * @param bool   $autoload Autoload.
 * @return void
 */
function wptsall_set_option_autoload( $option, $autoload ) {
	global $wpdb;
	$autoload_val = $autoload ? 'yes' : 'no';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->update(
		$wpdb->options,
		array( 'autoload' => $autoload_val ),
		array( 'option_name' => $option ),
		array( '%s' ),
		array( '%s' )
	);
}

/**
 * Check whether Pro capabilities are available.
 *
 * Unified single-plugin mode should rely on license state when available.
 * Keep legacy constant check as fallback for older deployments.
 *
 * @since 1.1.0
 *
 * @return bool True (all features are free in the wp.org version).
 */
function wptsall_is_pro_active() {
	return true;
}

/**
 * Get the configurable client content claim lease timeout.
 *
 * @since 2.1.0
 *
 * @return int Timeout in seconds.
 */
function wptsall_get_client_claim_timeout_seconds() {
	$default = defined( 'WPTSALL_CLIENT_CLAIM_TIMEOUT_SECONDS' )
		? absint( WPTSALL_CLIENT_CLAIM_TIMEOUT_SECONDS )
		: 1800;
	$timeout    = absint( apply_filters( 'wptsall_client_claim_timeout_seconds', $default ) );
	$max_window = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
	return max( 60, min( $max_window, $timeout ?: 1800 ) );
}

/**
 * Derive the non-secret owner identifier used by client claim leases.
 *
 * A device id is an authentication identifier, not durable claim data. Claim
 * rows and transient/object-cache keys must contain only this deterministic
 * digest. Relation/language/scope are included so a lease cannot be replayed
 * against a different relation or business lane.
 *
 * @since 2.1.0
 *
 * @param string $device_id    Device id from the authenticated request.
 * @param int    $relation_id  Site relation id.
 * @param string $target_lang  Target language, when applicable.
 * @param string $scope        Object/lane scope (post type, option name, etc.).
 * @return string 64-character SHA-256 digest, or empty when no device exists.
 */
function wptsall_client_claim_owner_hash( $device_id, $relation_id = 0, $target_lang = '', $scope = '' ) {
	$device_id = sanitize_key( (string) $device_id );
	if ( '' === $device_id ) {
		return '';
	}

	return hash(
		'sha256',
		'wptsall-claim-owner-v1|' . $device_id . '|' . absint( $relation_id ) . '|' . sanitize_text_field( (string) $target_lang ) . '|' . sanitize_key( (string) $scope )
	);
}

/**
 * Whether a deliberately enabled test fixture may omit source_revision.
 *
 * Production callbacks must always carry the immutable discovery snapshot.
 * This constant is not defined by the plugin; a test harness must opt in
 * explicitly before WordPress/plugin bootstrap when exercising legacy-shaped
 * fixtures. It is intentionally not an environment-variable escape hatch.
 *
 * @since 2.1.0
 * @return bool
 */
function wptsall_is_test_only_source_revision_fallback_enabled() {
	return defined( 'WPTSALL_TEST_ONLY_SOURCE_REVISION_FALLBACK' )
		&& true === WPTSALL_TEST_ONLY_SOURCE_REVISION_FALLBACK;
}

/**
 * Whether a deliberately enabled test harness may skip Protocol v2
 * request-signature validation.
 *
 * Production requests are always signature-verified by
 * Transport_Middleware. The in-process integration harness issues
 * business-logic requests without replaying the Rust client's signing
 * path; before 2026-09-09 it only passed because an unrelated lab plugin
 * accidentally swallowed the middleware's WP_Error on the shared test
 * site — clean installs (e.g. the module-ci runner) enforced the
 * signatures and correctly rejected it. Like the source-revision
 * fallback, this constant is not defined by the plugin; a harness must
 * opt in explicitly before WordPress/plugin bootstrap. Signature
 * enforcement itself is covered by the protocol-v2 security unit tests
 * and the signed Rust-client e2e lanes.
 *
 * @since 2.1.2
 * @return bool
 */
function wptsall_is_test_only_transport_signature_fallback_enabled() {
	return defined( 'WPTSALL_TEST_ONLY_TRANSPORT_SIGNATURE_FALLBACK' )
		&& true === WPTSALL_TEST_ONLY_TRANSPORT_SIGNATURE_FALLBACK;
}

/**
 * Whether an option name is on the deny list for sync/read.
 *
 * @since 1.7.0
 * @since 1.9.0 Renamed from wpmmcc_ats_is_denied_option (prefix unified to wptsall_).
 *
 * @param string $name Option name.
 * @return bool True if denied.
 */
function wptsall_is_denied_option( $name ) {
	$name = sanitize_key( (string) $name );
	if ( '' === $name ) {
		return true;
	}

	$deny_exact = array(
		'siteurl',
		'home',
		'admin_email',
		'users_can_register',
		'default_role',
		'secret_key',
		'auth_key',
		'auth_salt',
		'logged_in_key',
		'logged_in_salt',
		'nonce_key',
		'nonce_salt',
		'db_version',
		'cron',
		'active_plugins',
		'uninstall_plugins',
		'recently_activated',
	);

	if ( in_array( $name, $deny_exact, true ) ) {
		return true;
	}

	// Intentionally blocks wptsall_client_api_token and other credential-like options from sync/REST reads.
	return (bool) preg_match( '/(_api_key|_secret|_token|_password|auth|private|credential)/i', $name );
}

/**
 * Collect option object names enabled in translation rules for a relation.
 *
 * @since 1.7.0
 * @since 1.9.0 Renamed from wpmmcc_ats_get_relation_option_names.
 *
 * @param int $relation_id Site relation ID.
 * @return string[]
 */
function wptsall_get_relation_option_names( $relation_id ) {
	$relation_id = (int) $relation_id;
	if ( $relation_id <= 0 || ! class_exists( '\WPTSALL\Sites\Services\Relation_Model_Service' ) || ! class_exists( '\WPTSALL\Models\Services\Translation_Rule_Service' ) ) {
		return array();
	}

	$option_names = array();
	$models       = \WPTSALL\Sites\Services\Relation_Model_Service::get_models_by_relation( $relation_id );
	foreach ( $models as $model ) {
		$rules = \WPTSALL\Models\Services\Translation_Rule_Service::get_model_rules( (int) $model['id'] );
		foreach ( $rules as $rule ) {
			$merged_config = \WPTSALL\Models\Services\Translation_Rule_Service::get_merged_config(
				(int) $rule['id'],
				$relation_id,
				array( 'suppress_warning_log' => true )
			);

			$rule_enabled = is_array( $merged_config )
				? (bool) ( $merged_config['enabled'] ?? ( $rule['is_active'] ?? true ) )
				: (bool) ( $rule['is_active'] ?? true );
			if ( ! $rule_enabled ) {
				continue;
			}

			$rule_data_type   = $rule['data_type'] ?? '';
			$rule_object_name = $rule['object_name'] ?? '';
			if ( is_array( $merged_config ) ) {
				$rule_data_type   = $merged_config['data_type'] ?? $rule_data_type;
				$rule_object_name = $merged_config['post_type'] ?? $rule_object_name;
			}

			if ( 'option' !== sanitize_key( (string) $rule_data_type ) || '' === $rule_object_name ) {
				continue;
			}

			$option_names[] = sanitize_key( (string) $rule_object_name );
		}
	}

	return array_values( array_unique( array_filter( $option_names ) ) );
}

/**
 * Whether an option name is allowed for cross-site sync or client read.
 *
 * @since 1.7.0
 * @since 1.9.0 Renamed from wpmmcc_ats_is_syncable_option; filter hooks unified to wptsall_*.
 *
 * @param string $name        Option name.
 * @param int    $relation_id Optional relation ID for rule-based allowlist.
 * @return bool
 */
function wptsall_is_syncable_option( $name, $relation_id = 0 ) {
	$name = sanitize_key( (string) $name );
	if ( '' === $name || wptsall_is_denied_option( $name ) ) {
		return false;
	}

	$allowed = apply_filters(
		'wptsall_syncable_options',
		array(
			'blogname',
			'blogdescription',
		)
	);

	if ( in_array( $name, (array) $allowed, true ) ) {
		return true;
	}

	// Plugin-owned options (settings, non-secret state).
	if ( 0 === strpos( $name, 'wptsall_' ) ) {
		return true;
	}

	// Core widget options only — not every widget_* from third-party plugins.
	// Custom widgets must be declared via relation rules or the
	// wptsall_syncable_options / wptsall_is_syncable_option filters.
	if ( 0 === strpos( $name, 'widget_' ) && wptsall_is_core_widget_option( $name ) ) {
		return true;
	}

	if ( $relation_id > 0 && in_array( $name, wptsall_get_relation_option_names( $relation_id ), true ) ) {
		return true;
	}

	return (bool) apply_filters( 'wptsall_is_syncable_option', false, $name, $relation_id );
}

/**
 * Whether an option name is a core WordPress widget option allowed for sync/read.
 *
 * Intentionally does not allow arbitrary third-party `widget_*` options.
 *
 * @since 1.9.0
 *
 * @param string $name Option name.
 * @return bool
 */
function wptsall_is_core_widget_option( $name ) {
	$name = sanitize_key( (string) $name );
	if ( '' === $name || 0 !== strpos( $name, 'widget_' ) ) {
		return false;
	}

	$core = array(
		'widget_text',
		'widget_block',
		'widget_custom_html',
		'widget_media_image',
		'widget_media_video',
		'widget_media_audio',
		'widget_media_gallery',
		'widget_nav_menu',
		'widget_pages',
		'widget_categories',
		'widget_recent_posts',
		'widget_recent_comments',
		'widget_rss',
		'widget_tag_cloud',
		'widget_search',
		'widget_archives',
		'widget_meta',
		'widget_calendar',
	);

	/**
	 * Filters the allowlist of core widget option names for sync/client read.
	 *
	 * @since 1.9.0
	 *
	 * @param string[] $core Allowlisted option names.
	 * @param string   $name Candidate option name.
	 */
	$core = apply_filters( 'wptsall_core_widget_options', $core, $name );

	return in_array( $name, (array) $core, true );
}

/**
 * Execute a callback while suppressing recursive sync side effects.
 *
 * Sync flows may trigger WordPress save hooks that would otherwise enqueue
 * another sync. The suppression scope is reference-counted to remain safe
 * across nested calls.
 *
 * @param callable $callback Callback executed inside suppression scope.
 * @return mixed
 */
function wptsall_with_sync_suppression( callable $callback ) {
	$depth = isset( $GLOBALS['wptsall_sync_suppression_depth'] ) ? (int) $GLOBALS['wptsall_sync_suppression_depth'] : 0;
	$GLOBALS['wptsall_sync_suppression_depth'] = $depth + 1;

	try {
		return $callback();
	} finally {
		$next_depth = (int) $GLOBALS['wptsall_sync_suppression_depth'] - 1;
		if ( $next_depth > 0 ) {
			$GLOBALS['wptsall_sync_suppression_depth'] = $next_depth;
		} else {
			unset( $GLOBALS['wptsall_sync_suppression_depth'] );
		}
	}
}

function wptsall_active_plugins() {
    if ( ! function_exists( 'get_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $active_plugins = array();
    foreach ( get_plugins() as $file => $data ) {
        if ( is_plugin_active( $file ) ) {
            $slug = dirname( $file );
            if ( '.' === $slug ) {
                $slug = basename( $file, '.php' );
            }
            $active_plugins[ $slug ] = $data['Name'];
        }
    }
    return $active_plugins;
}

/**
 * Known content plugins whitelist
 *
 * Content plugin definition: Plugins that produce publicly accessible new content
 * beyond WordPress default blog functionality.
 * These plugins register public custom post types or taxonomies.
 *
 * @return array Plugin slug list
 */
function wptsall_known_content_plugins() {
    return array(
        // ========== E-commerce ==========
        'woocommerce',
        'easy-digital-downloads',
        'ecwid-shopping-cart',
        'wp-easycart',

        // ========== Forum/Community ==========
        'bbpress',
        'buddypress',
        'wpforo',
        'asgaros-forum',
        'forumwp',

        // ========== Learning Management System (LMS) ==========
        'learnpress',
        'sensei-lms',
        'tutor',
        'lifterlms',
        'masterstudy-lms-learning-management-system',
        'academy',

        // ========== Events/Calendar/Booking ==========
        'the-events-calendar',
        'events-manager',
        'ameliabooking',
        'bookly-responsive-appointment-booking-tool',
        'simply-schedule-appointments',
        'fluent-booking',
        'easy-appointments',
        'booking',

        // ========== Donations ==========
        'give',

        // ========== Directory/Listings ==========
        'directorist',
        'geodirectory',
        'hivepress',
        'classified-listing',

        // ========== Jobs/Recruitment ==========
        'wp-job-manager',
        'wp-job-openings',
        'simple-job-board',

        // ========== Real Estate ==========
        'estatik',
        'essential-real-estate',
        'easy-property-listings',
        'propertyhive',

        // ========== Gallery/Portfolio ==========
        'envira-gallery-lite',
        'foogallery',
        'visual-portfolio',
        'portfolio-post-type',

        // ========== Podcasts ==========
        'powerpress',
        'seriously-simple-podcasting',
        'podlove-podcasting-plugin-for-wordpress',
        'podcast-player',

        // ========== Recipes ==========
        'wp-recipe-maker',
        'delicious-recipes',
        'cooked',

        // ========== Reviews/Testimonials ==========
        'reviews-feed',
        'site-reviews',
        'testimonial-free',
        'wp-customer-reviews',

        // ========== FAQ/Knowledge Base ==========
        'ultimate-faq',
        'heroic-faqs',

        // ========== Membership/Subscription ==========
        'restrict-content',
        'paid-memberships-pro',

        // ========== Custom Post Type Tools ==========
        'custom-post-type-ui',
        'pods',
        'meta-box',
        'advanced-custom-fields',
    );
}

/**
 * Get all content plugins list (for model scan dropdown).
 *
 * Prioritizes dynamically discovered plugins while retaining whitelisted plugins.
 *
 * @return array Plugin slug => plugin name.
 */
function wptsall_content_plugins() {
    // Fixed item: always include default blog template.
    $filtered = array(
        'wordpress-blog' => 'WordPress Blog (default)',
    );

    // Get all active plugins.
    $active_plugins = wptsall_active_plugins();

    // Get known content plugins whitelist.
    $known_content_plugins = wptsall_known_content_plugins();

    foreach ( $active_plugins as $slug => $name ) {
        // Exclude our own plugin.
        if ( 'wpmmcc-ats' === $slug || 'wptsall-dev' === $slug ) {
            continue;
        }

        // Include if plugin is in the known content plugins list.
        if ( in_array( $slug, $known_content_plugins, true ) ) {
            $filtered[ $slug ] = $name;
        }
    }

    return $filtered;
}

function wptsall_saved_templates() {
    global $wpdb;
    $rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name FROM %i WHERE option_name LIKE %s", $wpdb->options, 'wptsall_template_%' ), ARRAY_A );
    $list = array();
    foreach ( $rows as $row ) {
        $slug          = str_replace( 'wptsall_template_', '', $row['option_name'] );
        $template      = get_option( $row['option_name'] );
        $plugin_name   = is_array( $template ) && isset( $template['plugin'] ) ? $template['plugin'] : $slug;
        $list[ $slug ] = $plugin_name;
    }
    ksort( $list );
    return $list;
}



/**
 * Get the source/target URL from a site relation (WP site uses home_url, virtual site uses configured URL).
 */
function wptsall_get_site_url_from_relation( $site_rel, $role = 'source' ) {
    $role = ( 'target' === $role ) ? 'targets' : 'source';
    $entry = ( 'targets' === $role ) ? ( $site_rel['targets'][0] ?? null ) : ( $site_rel['source'] ?? null );
    if ( ! $entry ) {
        return '';
    }
    if ( ( $entry['type'] ?? '' ) === 'virtual' ) {
        $v = wptsall_get_virtual_site( $entry['id'] ?? '' );
        return $v['url'] ?? '';
    }
    // WP site.
    $blog_id = intval( $entry['id'] ?? get_current_blog_id() );
    if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_blog_details' ) ) {
        $details = get_blog_details( $blog_id );
        if ( $details && isset( $details->siteurl ) ) {
            return $details->siteurl;
        }
    }
    if ( function_exists( 'home_url' ) ) {
        if ( function_exists( 'switch_to_blog' ) && function_exists( 'restore_current_blog' ) && $blog_id !== get_current_blog_id() ) {
            switch_to_blog( $blog_id );
            $url = home_url();
            restore_current_blog();
            return $url;
        }
        return home_url();
    }
    return '';
}

/**
 * Get plugin custom table name (with prefix).
 * Uses whitelist to ensure table name safety.
 * The mappings table uses base_prefix for cross-site sharing.
 */
	function wptsall_table( $key ) {
		global $wpdb;
		$tables = array(
			'hooks'                      => 'wptsall_hooks',
			'virtual_sites'              => 'wptsall_virtual_sites',
			'virtual_site_content'       => 'wptsall_virtual_site_content',
			'mappings'                   => 'wptsall_mappings',
			'site_relations'             => 'wptsall_site_relations',
			'models'                     => 'wptsall_models',
			// Plugin template objects (B: plugin -> objects -> fields).
			'model_objects'              => 'wptsall_model_objects',
			'model_object_fields'        => 'wptsall_model_object_fields',
			'model_url_rules'            => 'wptsall_model_url_rules', // Legacy (V1).
			'translation_rules'          => 'wptsall_translation_rules',
			'plugin_mappings'            => 'wptsall_plugin_mappings',
			'model_link_chains'          => 'wptsall_model_link_chains',
			'templates'                  => 'wptsall_templates',
			'template_entries'           => 'wptsall_template_entries',
			'languages'                  => 'wptsall_languages',
			'strings'                    => 'wptsall_strings',
			'term_mappings'              => 'wptsall_term_mappings',
			'media_mappings'             => 'wptsall_media_mappings',
			'post_mappings'              => 'wptsall_post_mappings',
			'content_change_outbox'      => 'wptsall_content_change_outbox',
			'menu_mappings'              => 'wptsall_menu_mappings',
			'user_mappings'              => 'wptsall_user_mappings',
			'relation_models'            => 'wptsall_relation_models',
			'relation_post_type_configs' => 'wptsall_relation_post_type_configs',
			'sync_meta'                  => 'wptsall_sync_meta',
			'conflicts'                  => 'wptsall_conflicts',
			'snapshots'                  => 'wptsall_snapshots',
			'option_sync_state'          => 'wptsall_option_sync_state',
			'client_rate_limits'         => 'wptsall_client_rate_limits',
			'translation_memory'         => 'wptsall_translation_memory',
			'terminology'                => 'wptsall_terminology',
			// Deprecated: 'virtual' - v0.7.0 unified to use virtual_site_content table.
			// Deprecated: 'model_url_rules' - replaced by translation_rules.
		);

		// Allow extensions to register additional table names.
		$tables = apply_filters( 'wptsall_table_names', $tables );

		$suffix = isset( $tables[ $key ] ) ? $tables[ $key ] : $key;
		/*
		 * Cross-site identity mapping tables resolve via base_prefix so a
		 * claim (recorded on the source blog) and the callback write-back
		 * (recorded while switched to the target blog) hit the same shared
		 * table. Without this, wp-target syncs registered their mapping rows
		 * on the target blog's per-blog copy while the claim placeholder
		 * lived on the main copy — a split-brain that left
		 * post_mappings.target_post_id stuck at 0 for automatic write-backs
		 * (found by the T1 subsite-translation gate, 2026-09-09).
		 */
		$base_prefixed_keys = array(
			'mappings',
			'post_mappings',
			'term_mappings',
			'menu_mappings',
			'media_mappings',
			'user_mappings',
		);
		$prefix = in_array( $key, $base_prefixed_keys, true ) ? $wpdb->base_prefix : $wpdb->prefix;
		// Escape for SQL safety, even though we use whitelist.
		return esc_sql( $prefix . $suffix );
	}

/**
 * Normalize positive integer IDs for SQL IN (...).
 *
 * @param array|int $ids IDs.
 * @return int[]
 */
function wptsall_db_int_ids( $ids ) {
	return array_values(
		array_filter(
			array_map( 'absint', (array) $ids ),
			static function ( $id ) {
				return $id > 0;
			}
		)
	);
}

/**
 * Build prepare-safe IN (...) placeholders and args for integer IDs.
 *
 * WordPress.org review rejects interpolating raw values into SQL (even absint lists).
 * Use: list( $in_sql, $in_args ) = wptsall_db_prepare_int_in( $ids );
 * then: $wpdb->prepare( "… WHERE id IN ($in_sql)", …$leading, …$in_args, …$trailing )
 *
 * Empty input yields literal "0" (matches nothing useful) with no prepare args.
 *
 * @param array|int $ids IDs.
 * @return array{0:string,1:int[]} [ placeholder SQL fragment, prepare args ]
 */
function wptsall_db_prepare_int_in( $ids ) {
	$ids = wptsall_db_int_ids( $ids );
	if ( empty( $ids ) ) {
		return array( '0', array() );
	}
	return array( implode( ',', array_fill( 0, count( $ids ), '%d' ) ), $ids );
}

/**
 * Build prepare-safe IN (...) placeholders for string literals.
 *
 * @param array $values String values.
 * @return array{0:string,1:string[]}
 */
function wptsall_db_prepare_string_in( $values ) {
	$clean = array();
	foreach ( (array) $values as $v ) {
		$s = (string) $v;
		if ( '' !== $s ) {
			$clean[] = $s;
		}
	}
	if ( empty( $clean ) ) {
		return array( "''", array() );
	}
	return array( implode( ',', array_fill( 0, count( $clean ), '%s' ) ), $clean );
}


/**
 * Prepare and run $wpdb->get_results for SQL with placeholders.
 *
 * Always runs through $wpdb->prepare(). Callers must pass at least one
 * prepare argument (use %i for table/column identifiers). Fully static SQL
 * with no values should call $wpdb->get_results( 'literal…' ) at the call site.
 *
 * @param string     $sql    SQL with % placeholders (and optional %i identifiers).
 * @param array      $args   Prepare arguments (identifiers and values). Required non-empty.
 * @param string|int $output Output type (ARRAY_A, OBJECT, …).
 * @return array|object|null
 */
function wptsall_db_get_results( $sql, $args = array(), $output = OBJECT ) {
	global $wpdb;
	$args = array_values( (array) $args );
	if ( empty( $args ) ) {
		_doing_it_wrong( __FUNCTION__, 'Pass prepare() placeholders/args, or use a literal SQL call site.', '1.9.2' );
		return array();
	}
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	return $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), $output );
}

/**
 * Prepare and run $wpdb->get_var.
 *
 * @param string $sql  SQL with placeholders.
 * @param array  $args Prepare arguments. Required non-empty.
 * @return string|null
 */
function wptsall_db_get_var( $sql, $args = array() ) {
	global $wpdb;
	$args = array_values( (array) $args );
	if ( empty( $args ) ) {
		_doing_it_wrong( __FUNCTION__, 'Pass prepare() placeholders/args, or use a literal SQL call site.', '1.9.2' );
		return null;
	}
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	return $wpdb->get_var( $wpdb->prepare( $sql, ...$args ) );
}

/**
 * Prepare and run $wpdb->get_row.
 *
 * @param string     $sql    SQL with placeholders.
 * @param array      $args   Prepare arguments. Required non-empty.
 * @param string|int $output Output type.
 * @return array|object|null
 */
function wptsall_db_get_row( $sql, $args = array(), $output = OBJECT ) {
	global $wpdb;
	$args = array_values( (array) $args );
	if ( empty( $args ) ) {
		_doing_it_wrong( __FUNCTION__, 'Pass prepare() placeholders/args, or use a literal SQL call site.', '1.9.2' );
		return null;
	}
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	return $wpdb->get_row( $wpdb->prepare( $sql, ...$args ), $output );
}

/**
 * Prepare and run $wpdb->get_col.
 *
 * @param string $sql  SQL with placeholders.
 * @param array  $args Prepare arguments. Required non-empty.
 * @return array
 */
function wptsall_db_get_col( $sql, $args = array() ) {
	global $wpdb;
	$args = array_values( (array) $args );
	if ( empty( $args ) ) {
		_doing_it_wrong( __FUNCTION__, 'Pass prepare() placeholders/args, or use a literal SQL call site.', '1.9.2' );
		return array();
	}
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	return (array) $wpdb->get_col( $wpdb->prepare( $sql, ...$args ) );
}

/**
 * Prepare and run $wpdb->query.
 *
 * @param string $sql  SQL with placeholders.
 * @param array  $args Prepare arguments. Required non-empty.
 * @return int|bool
 */
function wptsall_db_query( $sql, $args = array() ) {
	global $wpdb;
	$args = array_values( (array) $args );
	if ( empty( $args ) ) {
		_doing_it_wrong( __FUNCTION__, 'Pass prepare() placeholders/args, or use a literal SQL call site.', '1.9.2' );
		return false;
	}
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	return $wpdb->query( $wpdb->prepare( $sql, ...$args ) );
}

/**
 * Run ALTER TABLE with a prepared table identifier and a hardcoded DDL fragment.
 *
 * WordPress.org review rejects: $wpdb->query( "ALTER TABLE {$table} {$fragment}" ).
 * Use this helper so the table is always bound via %i. $fragment must be a
 * developer-controlled literal (ADD COLUMN…, ADD INDEX…, DROP INDEX…), never
 * user input.
 *
 * @param string $table    Table name (e.g. from wptsall_table()).
 * @param string $fragment Hardcoded DDL after the table name (no leading ALTER TABLE).
 * @return int|bool Query result.
 */
function wptsall_db_alter_table( $table, $fragment ) {
	global $wpdb;

	$fragment = trim( (string) $fragment );
	// Allow only known DDL verbs so a mistaken user-controlled fragment cannot run.
	if ( ! preg_match( '/^(ADD|DROP|MODIFY|CHANGE|ALTER)\s/i', $fragment ) ) {
		return false;
	}

	// %i prepares the identifier; fragment is a fixed migration string.
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
	return $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ' . $fragment, $table ) );
}

/**
 * Get complete post data including meta and taxonomies.
 *
 * Returns comprehensive post data for sync/translation tasks.
 *
 * @since 0.5.0
 *
 * @param string $post_type Post type slug.
 * @param int    $post_id   Post ID.
 * @return array|false Array with 'post', 'meta', 'taxonomies' keys, or false on failure.
 */
function wptsall_get_complete_post_data( $post_type, $post_id ) {
    $post = get_post( $post_id );

    if ( ! $post || $post->post_type !== $post_type ) {
        return false;
    }

    // Core post fields.
    $post_data = array(
        'ID'             => $post->ID,
        'post_title'     => $post->post_title,
        'post_content'   => $post->post_content,
        'post_excerpt'   => $post->post_excerpt,
        'post_status'    => $post->post_status,
        'post_type'      => $post->post_type,
        'post_name'      => $post->post_name,
        'post_parent'    => $post->post_parent,
        'post_author'    => $post->post_author,
        'post_date'      => $post->post_date,
        'post_modified'  => $post->post_modified,
        'menu_order'     => $post->menu_order,
        'comment_status' => $post->comment_status,
        'ping_status'    => $post->ping_status,
    );

    // Post meta.
    $meta = get_post_meta( $post_id );
    $filtered_meta = array();
    if ( is_array( $meta ) ) {
        foreach ( $meta as $key => $values ) {
            // Skip internal WordPress meta, but allow translatable WP fields.
            $wp_translatable_meta = array( '_wp_attachment_image_alt' );
            if ( ( strpos( $key, '_wp_' ) === 0 || strpos( $key, '_edit_' ) === 0 )
                && ! in_array( $key, $wp_translatable_meta, true ) ) {
                continue;
            }
            $filtered_meta[ $key ] = is_array( $values ) && count( $values ) === 1
                ? $values[0]
                : $values;
        }
    }

    // Taxonomies.
    $taxonomies = get_object_taxonomies( $post_type, 'names' );
    $tax_data = array();
    foreach ( $taxonomies as $taxonomy ) {
        $terms = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'all' ) );
        if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
            $tax_data[ $taxonomy ] = array_map(
                function ( $term ) {
                    return array(
                        'term_id' => $term->term_id,
                        'name'    => $term->name,
                        'slug'    => $term->slug,
                    );
                },
                $terms
            );
        }
    }

    // Featured image.
    $thumbnail_id = get_post_thumbnail_id( $post_id );
    if ( $thumbnail_id ) {
        $post_data['_thumbnail_id'] = $thumbnail_id;
        $post_data['_thumbnail_url'] = wp_get_attachment_url( $thumbnail_id );
    }

    // Attachments are posts, but their binary is not represented by
    // post_content.  Expose a narrowly scoped source reference so the Client
    // can execute an attachment lifecycle event as a binary copy/upload rather
    // than acknowledging it as an empty text-only post.  The reference is
    // generated by WordPress; the Client additionally requires it to be on the
    // configured WordPress origin before downloading it.
    if ( 'attachment' === $post->post_type ) {
        $post_data['attachment_url']       = (string) ( wp_get_attachment_url( $post_id ) ?: '' );
        $post_data['attachment_mime_type'] = (string) $post->post_mime_type;
        $post_data['attachment_filename']  = (string) basename( (string) get_attached_file( $post_id ) );
    }

    return array(
        'object_type' => 'post_type',
        'subtype'     => $post_type,
        'object_id'   => $post_id,
        'post'        => $post_data,
        'meta'        => $filtered_meta,
        'taxonomies'  => $tax_data,
    );
}

/**
 * Get complete term data including meta.
 *
 * Returns comprehensive term data for sync/translation tasks.
 *
 * @since 0.5.0
 *
 * @param string $taxonomy Taxonomy slug.
 * @param int    $term_id  Term ID.
 * @return array|false Array with 'term', 'meta' keys, or false on failure.
 */
function wptsall_get_complete_term_data( $taxonomy, $term_id ) {
    $term = get_term( $term_id, $taxonomy );

    if ( ! $term || is_wp_error( $term ) ) {
        return false;
    }

    // Core term fields.
    $term_data = array(
        'term_id'     => $term->term_id,
        'name'        => $term->name,
        'slug'        => $term->slug,
        'description' => $term->description,
        'parent'      => $term->parent,
        'count'       => $term->count,
        'taxonomy'    => $term->taxonomy,
    );

    // Term meta.
    $meta = get_term_meta( $term_id );
    $filtered_meta = array();
    if ( is_array( $meta ) ) {
        foreach ( $meta as $key => $values ) {
            $filtered_meta[ $key ] = is_array( $values ) && count( $values ) === 1
                ? $values[0]
                : $values;
        }
    }

    return array(
        'object_type' => 'taxonomy',
        'subtype'     => $taxonomy,
        'object_id'   => $term_id,
        'term'        => $term_data,
        'meta'        => $filtered_meta,
    );
}

/**
 * Get complete object data for any object type.
 *
 * This is a wrapper function that dispatches to the appropriate
 * data retrieval function based on object type.
 *
 * @since 0.5.1
 *
 * @param string $object_type Object type (post_type, taxonomy, etc).
 * @param string $subtype     Subtype (post, page, category, etc).
 * @param int    $object_id   Object ID.
 * @return array|false Complete object data or false on failure.
 */
function wptsall_get_complete_object_data( $object_type, $subtype, $object_id ) {
    switch ( $object_type ) {
        case 'post_type':
            return wptsall_get_complete_post_data( $subtype, $object_id );

        case 'taxonomy':
            return wptsall_get_complete_term_data( $subtype, $object_id );

        default:
            // Log unknown object type.
            if ( function_exists( 'wptsall_log' ) ) {
                wptsall_log( 'data', 'warning', "Unknown object type: {$object_type}" );
            }
            return false;
    }
}

/*
 * =============================================================================
 * Marker Mode Functions (TM-001, TM-002)
 * Marker mode functions for test validation
 * =============================================================================
 */

/**
 * Check if marker mode is enabled.
 *
 * Marker mode adds language markers to synced content for validation.
 * Default is disabled for production environments.
 *
 * @since 0.5.1
 *
 * @return bool True if marker mode is enabled.
 */
function wptsall_is_marker_mode_enabled() {
    // Constant takes priority (for development/testing).
    if ( defined( 'WPTSALL_MARKER_MODE' ) ) {
        return (bool) WPTSALL_MARKER_MODE;
    }

    // Check settings option.
    $settings = get_option( 'wptsall_settings', array() );
    return ! empty( $settings['marker_mode'] );
}


/**
 * Wrap content with language marker for testing/validation.
 *
 * Format: 【{lang}】{content}【/{lang}】
 *
 * Only applies when marker mode is enabled.
 * Empty content is not marked.
 * Already marked content is not double-marked.
 *
 * @since 0.5.1
 *
 * @param string $content     The content to wrap.
 * @param string $target_lang Target language code (e.g., 'en_US', 'zh_CN').
 * @return string Wrapped content or original content if marker mode disabled.
 */
function wptsall_wrap_with_marker( $content, $target_lang ) {
    // Only wrap if marker mode is enabled.
    if ( ! wptsall_is_marker_mode_enabled() ) {
        return $content;
    }

    // Don't wrap empty content.
    if ( empty( $content ) || ! is_string( $content ) ) {
        return $content;
    }

    $content = trim( $content );
    if ( '' === $content ) {
        return '';
    }

    // Normalize language code.
    $lang = sanitize_text_field( $target_lang );
    if ( empty( $lang ) ) {
        return $content;
    }

    // Check if already marked with this language.
    $open_tag  = '【' . $lang . '】';
    $close_tag = '【/' . $lang . '】';

    if ( strpos( $content, $open_tag ) === 0 && substr( $content, -strlen( $close_tag ) ) === $close_tag ) {
        // Already marked, don't double-mark.
        return $content;
    }

    return $open_tag . $content . $close_tag;
}

/**
 * Remove language marker from content.
 *
 * @since 0.5.1
 *
 * @param string $content The marked content.
 * @param string $lang    Language code to remove (optional, removes any if empty).
 * @return string Content without marker.
 */
function wptsall_unwrap_marker( $content, $lang = '' ) {
    if ( empty( $content ) || ! is_string( $content ) ) {
        return $content;
    }

    if ( ! empty( $lang ) ) {
        // Remove specific language marker.
        $pattern = '/^【' . preg_quote( $lang, '/' ) . '】(.*)【\/' . preg_quote( $lang, '/' ) . '】$/su';
        if ( preg_match( $pattern, $content, $matches ) ) {
            return $matches[1];
        }
    } else {
        // Remove any language marker.
        $pattern = '/^【([a-zA-Z_-]+)】(.*)【\/\1】$/su';
        if ( preg_match( $pattern, $content, $matches ) ) {
            return $matches[2];
        }
    }

    return $content;
}




/**
 * Get Schema definition for a Post Type.
 *
 * Returns the field structure for a given post type, including core fields,
 * meta fields, taxonomies, and attachments.
 *
 * @since 0.5.1
 *
 * @param string $post_type Post type slug (post, page, etc.).
 * @return array Schema definition with keys: core, meta, taxonomies, attachments.
 */
function wptsall_get_post_type_schema( $post_type ) {
    $post_type = sanitize_key( $post_type );

    // Core post fields (wp_posts table columns).
    $core_fields = array(
        'ID',
        'post_author',
        'post_date',
        'post_date_gmt',
        'post_content',
        'post_title',
        'post_excerpt',
        'post_status',
        'comment_status',
        'ping_status',
        'post_password',
        'post_name',
        'to_ping',
        'pinged',
        'post_modified',
        'post_modified_gmt',
        'post_content_filtered',
        'post_parent',
        'guid',
        'menu_order',
        'post_type',
        'post_mime_type',
        'comment_count',
    );

    // Get taxonomies for this post type.
    $taxonomies = get_object_taxonomies( $post_type, 'names' );

    // Get common meta fields based on post type.
    $meta_fields = array( '_thumbnail_id' );

    if ( 'page' === $post_type ) {
        $meta_fields[] = '_wp_page_template';
    }

    if ( 'attachment' === $post_type ) {
        $meta_fields = array_merge( $meta_fields, array(
            '_wp_attachment_metadata',
            '_wp_attached_file',
            '_wp_attachment_image_alt',
        ) );
    }

    // Add common SEO meta fields.
    $seo_meta = array(
        '_yoast_wpseo_title',
        '_yoast_wpseo_metadesc',
        '_yoast_wpseo_focuskw',
        'rank_math_title',
        'rank_math_description',
    );
    $meta_fields = array_merge( $meta_fields, $seo_meta );

    $schema = array(
        'core'        => $core_fields,
        'meta'        => array_values( array_unique( $meta_fields ) ),
        'taxonomies'  => array_values( $taxonomies ),
        'attachments' => array( 'featured_image' ),
    );

    /**
     * Filter the post type schema definition.
     *
     * @param array  $schema    Schema definition.
     * @param string $post_type Post type slug.
     */
    return apply_filters( 'wptsall_post_type_schema', $schema, $post_type );
}

/**
 * Get Schema definition for a Taxonomy.
 *
 * Returns the field structure for a given taxonomy, including core fields,
 * meta fields, and term_taxonomy fields.
 *
 * @since 0.5.1
 *
 * @param string $taxonomy Taxonomy slug (category, post_tag, etc.).
 * @return array Schema definition with keys: core, meta, term_taxonomy.
 */
function wptsall_get_taxonomy_schema( $taxonomy ) {
    $taxonomy = sanitize_key( $taxonomy );

    // Core term fields (wp_terms table columns).
    $core_fields = array(
        'term_id',
        'name',
        'slug',
        'term_group',
    );

    // Term taxonomy fields (wp_term_taxonomy table columns).
    $term_taxonomy_fields = array(
        'term_taxonomy_id',
        'taxonomy',
        'description',
        'parent',
        'count',
    );

    // Meta fields - most taxonomies don't have default meta.
    $meta_fields = array();

    // Add SEO meta fields if taxonomy supports them.
    $tax_obj = get_taxonomy( $taxonomy );
    if ( $tax_obj && $tax_obj->public ) {
        $meta_fields = array(
            '_yoast_wpseo_title',
            '_yoast_wpseo_metadesc',
            'rank_math_title',
            'rank_math_description',
        );
    }

    $schema = array(
        'core'          => $core_fields,
        'meta'          => $meta_fields,
        'term_taxonomy' => $term_taxonomy_fields,
    );

    /**
     * Filter the taxonomy schema definition.
     *
     * @param array  $schema   Schema definition.
     * @param string $taxonomy Taxonomy slug.
     */
    return apply_filters( 'wptsall_taxonomy_schema', $schema, $taxonomy );
}

/**
 * Get sample posts for a post type.
 *
 * @param string $post_type Post type slug.
 * @param int    $limit     Number of posts to retrieve (-1 for all).
 * @return array Array with 'ids' and 'urls' keys.
 */
function wptsall_sample_posts( $post_type, $limit = 10 ) {
    $posts_per_page = ( $limit < 1 ) ? -1 : $limit;

    $query = new \WP_Query( array(
        'post_type'      => $post_type,
        'posts_per_page' => $posts_per_page,
        'post_status'    => 'publish',
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ) );

    $ids  = $query->posts;
    $urls = array();

    foreach ( $ids as $id ) {
        $permalink = get_permalink( $id );
        if ( $permalink ) {
            $urls[] = $permalink;
        }
    }

    return array( 'ids' => $ids, 'urls' => $urls );
}

/**
 * Get sample terms for a taxonomy.
 *
 * @param string $taxonomy Taxonomy slug.
 * @param int    $limit    Number of terms to retrieve (0 for all).
 * @return array Array with 'ids' and 'urls' keys.
 */
function wptsall_sample_terms( $taxonomy, $limit = 10 ) {
    $number = ( $limit < 1 ) ? 0 : $limit;

    $terms = get_terms( array(
        'taxonomy'   => $taxonomy,
        'hide_empty' => false,
        'number'     => $number,
        'fields'     => 'ids',
    ) );

    if ( is_wp_error( $terms ) ) {
        return array( 'ids' => array(), 'urls' => array() );
    }

    $urls = array();
    foreach ( $terms as $term_id ) {
        $link = get_term_link( (int) $term_id );
        if ( ! is_wp_error( $link ) ) {
            $urls[] = $link;
        }
    }

    return array( 'ids' => $terms, 'urls' => $urls );
}

/**
 * Get available languages for site/relation configuration.
 *
 * Uses WordPress translation API to get all available languages dynamically.
 * Always includes en_US (English) which is not in the translations API.
 *
 * @since 0.8.1
 *
 * @param bool $include_installed_only Whether to only include installed languages. Default false.
 * @return array Associative array of locale => native_name, sorted by name.
 */
function wptsall_get_available_languages( $include_installed_only = false ) {
	// Ensure translation functions are available.
	if ( ! function_exists( 'wp_get_available_translations' ) ) {
		require_once ABSPATH . 'wp-admin/includes/translation-install.php';
	}

	$languages = array();

	// English (US) is always available but not in translations API.
	$languages['en_US'] = 'English (United States)';

	if ( $include_installed_only ) {
		// Get only installed languages.
		$installed = get_available_languages();
		foreach ( $installed as $locale ) {
			if ( 'en_US' === $locale ) {
				continue;
			}
			// Get language name from WordPress.
			$translations = wp_get_available_translations();
			if ( isset( $translations[ $locale ] ) ) {
				$languages[ $locale ] = $translations[ $locale ]['native_name'];
			} else {
				$languages[ $locale ] = $locale;
			}
		}
	} else {
		// Get all available translations from WordPress.org.
		$translations = wp_get_available_translations();
		if ( ! empty( $translations ) ) {
			foreach ( $translations as $locale => $data ) {
				$languages[ $locale ] = $data['native_name'];
			}
		}
	}

	// Offline / blocked-lab fallback: never let the language list collapse to
	// en_US just because the translations API is unreachable. Built-in core
	// locales keep the product usable and the tests deterministic.
	if ( count( $languages ) < 5 ) {
		$fallback = array(
			'en_US' => 'English (United States)',
			'zh_CN' => '简体中文',
			'ja'    => '日本語',
			'fr_FR' => 'Français',
			'de_DE' => 'Deutsch',
			'es_ES' => 'Español',
			'ru_RU' => 'Русский',
		);
		foreach ( $fallback as $locale => $name ) {
			if ( ! isset( $languages[ $locale ] ) ) {
				$languages[ $locale ] = $name;
			}
		}
	}

	// Sort by native name (preserving keys).
	uasort(
		$languages,
		function ( $a, $b ) {
			return strcasecmp( $a, $b );
		}
	);

	/**
	 * Filter available languages for site configuration.
	 *
	 * @since 0.8.1
	 *
	 * @param array $languages Associative array of locale => native_name.
	 * @param bool  $include_installed_only Whether only installed languages are included.
	 */
	return apply_filters( 'wptsall_available_languages', $languages, $include_installed_only );
}

/**
 * Get language display name from locale code.
 *
 * @since 0.8.1
 *
 * @param string $locale Language locale code (e.g., 'zh_CN', 'en_US').
 * @return string Native language name or locale code if not found.
 */
function wptsall_get_language_name( $locale ) {
	if ( empty( $locale ) ) {
		return '';
	}

	// Special case for English.
	if ( 'en_US' === $locale ) {
		return 'English (United States)';
	}

	// Ensure translation functions are available.
	if ( ! function_exists( 'wp_get_available_translations' ) ) {
		require_once ABSPATH . 'wp-admin/includes/translation-install.php';
	}

	$translations = wp_get_available_translations();
	if ( isset( $translations[ $locale ] ) ) {
		return $translations[ $locale ]['native_name'];
	}

	// Return locale code if name not found.
	return $locale;
}

/**
 * Validate that a domain string is a proper domain name (not IP, not localhost).
 *
 * @since 1.1.0
 *
 * @param string $domain Domain string to validate.
 * @return bool True if valid domain name format.
 */
function wptsall_validate_domain_format( $domain ) {
	$domain = strtolower( trim( (string) $domain ) );

	if ( empty( $domain ) ) {
		return false;
	}

	// Reject IP addresses (IPv4 and IPv6).
	if ( filter_var( $domain, FILTER_VALIDATE_IP ) ) {
		return false;
	}

	// Reject localhost.
	if ( 'localhost' === $domain ) {
		return false;
	}

	// Must contain at least one dot (TLD required).
	if ( false === strpos( $domain, '.' ) ) {
		return false;
	}

	// Basic RFC-compliant domain name check: labels separated by dots,
	// each label alphanumeric + hyphens, no leading/trailing hyphens.
	$labels = explode( '.', $domain );
	foreach ( $labels as $label ) {
		if ( empty( $label ) || strlen( $label ) > 63 ) {
			return false;
		}
		if ( ! preg_match( '/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/', $label ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Get the current site's domain from home_url().
 *
 * @since 1.1.0
 *
 * @return string Lowercase domain.
 */
function wptsall_get_current_site_domain() {
	return strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
}

/**
 * Check if the current site's domain is a valid domain name (not IP).
 *
 * @since 1.1.0
 *
 * @return bool True if current site uses a valid domain name.
 */
function wptsall_is_current_site_domain_valid() {
	return wptsall_validate_domain_format( wptsall_get_current_site_domain() );
}

/**
 * Get current SSOT read source configuration.
 *
 * Controls where create_rules_for_model() reads field data from:
 * - 'scan_result': Legacy path, reads from models.scan_result JSON.
 * - 'model_objects': SSOT path, reads from model_object_fields table (default).
 * - 'dual': Both paths execute, diff logged, model_objects used.
 *
 * @since 1.2.0
 * @return string 'scan_result' | 'model_objects' | 'dual'
 */
function wptsall_get_ssot_read_source(): string {
	$value   = get_option( 'wptsall_ssot_read_source', 'model_objects' );
	$allowed = array( 'scan_result', 'model_objects', 'dual' );
	return in_array( $value, $allowed, true ) ? $value : 'model_objects';
}
/**
 * Supported client protocol versions (newest first).
 *
 * The Rust client-wpplugin sends `X-WPTSALL-Protocol-Version: 2` on every
 * request. This constant lists the versions this WP plugin understands.
 * On mismatch, the request is rejected with HTTP 400.
 *
 * @since 1.6.0
 * @return array<int, int> Sorted list of supported versions (descending).
 */
function wptsall_get_supported_client_protocol_versions(): array {
	// '2' is the current production protocol. Future versions prepend here.
	return array( 2 );
}

/**
 * Require X-WPTSALL-Protocol-Version: 2 on all client REST routes.
 *
 * Pre-release policy (docs/architecture/current/PRE-RELEASE-SINGLE-TRUTH.md):
 * missing or unsupported versions are rejected — no empty-header compat.
 *
 * @since 1.6.0
 *
 * @param \WP_REST_Request $request Request object.
 * @return true|\WP_Error True if allowed, WP_Error with status=400 if not.
 */
function wptsall_check_client_protocol_version( $request ) {
	$header = (string) $request->get_header( 'X-WPTSALL-Protocol-Version' );
	if ( '' === $header ) {
		return new \WP_Error(
			'missing_protocol_version',
			__( 'X-WPTSALL-Protocol-Version: 2 is required.', 'wpmmcc-ats' ),
			array(
				'status'             => 400,
				'received'           => '',
				'supported_versions' => wptsall_get_supported_client_protocol_versions(),
			)
		);
	}
	if ( ! ctype_digit( $header ) ) {
		return new \WP_Error(
			'unsupported_protocol_version',
			__( 'X-WPTSALL-Protocol-Version header must be an integer.', 'wpmmcc-ats' ),
			array(
				'status'             => 400,
				'received'           => $header,
				'supported_versions' => wptsall_get_supported_client_protocol_versions(),
			)
		);
	}
	$version = (int) $header;
	if ( ! in_array( $version, wptsall_get_supported_client_protocol_versions(), true ) ) {
		return new \WP_Error(
			'unsupported_protocol_version',
			__( 'Client protocol version is not supported by this WP plugin. Please upgrade one side.', 'wpmmcc-ats' ),
			array(
				'status'             => 400,
				'received'           => $version,
				'supported_versions' => wptsall_get_supported_client_protocol_versions(),
			)
		);
	}
	return true;
}

/**
 * Supported multi-axis contract capabilities (libs/wptsall-contracts/versions.json).
 *
 * Clients may send `X-WPTSALL-Contract-Capabilities` as compact JSON. Missing
 * header is currently allowed (capabilities negotiation is additive). Unknown axes are ignored.
 *
 * @since 2.1.0
 * @return array<string, int|string>
 */
function wptsall_get_supported_contract_capabilities(): array {
	return array(
		'wp_client_protocol'          => 2,
		'content_formats'             => 'content-formats-v1',
		'component_client_contract'   => 'component-client-contract-v1',
		'callback'                    => 'task-callback-v1',
		'workflow_policy'             => 'workflow-policy-v1',
		'workflow_dsl'                => 'workflow-dsl-v1',
	);
}

/**
 * Validate client contract capabilities header when present.
 *
 * @since 2.1.0
 *
 * @param \WP_REST_Request $request Request object.
 * @return true|\WP_Error
 */
function wptsall_check_client_contract_capabilities( $request ) {
	$header = (string) $request->get_header( 'X-WPTSALL-Contract-Capabilities' );
	if ( '' === $header ) {
		return true;
	}
	$client_caps = json_decode( $header, true );
	if ( ! is_array( $client_caps ) ) {
		return new \WP_Error(
			'invalid_contract_capabilities',
			__( 'X-WPTSALL-Contract-Capabilities must be valid JSON.', 'wpmmcc-ats' ),
			array( 'status' => 400 )
		);
	}
	$supported = wptsall_get_supported_contract_capabilities();
	foreach ( $client_caps as $axis => $value ) {
		if ( ! array_key_exists( $axis, $supported ) ) {
			continue;
		}
		$expected = $supported[ $axis ];
		if ( is_int( $expected ) ) {
			if ( (int) $value !== $expected ) {
				return new \WP_Error(
					'unsupported_contract_capability',
					sprintf(
						/* translators: 1: axis name, 2: client value, 3: supported value */
						__( 'Contract capability "%1$s" value %2$s is not supported (need %3$s).', 'wpmmcc-ats' ),
						(string) $axis,
						(string) $value,
						(string) $expected
					),
					array(
						'status'              => 400,
						'axis'                => $axis,
						'supported'           => $supported,
						'client_capabilities' => $client_caps,
					)
				);
			}
		} elseif ( (string) $value !== (string) $expected ) {
			return new \WP_Error(
				'unsupported_contract_capability',
				sprintf(
					/* translators: 1: axis name, 2: client value, 3: supported value */
					__( 'Contract capability "%1$s" value %2$s is not supported (need %3$s).', 'wpmmcc-ats' ),
					(string) $axis,
					(string) $value,
					(string) $expected
				),
				array(
					'status'              => 400,
					'axis'                => $axis,
					'supported'           => $supported,
					'client_capabilities' => $client_caps,
				)
			);
		}
	}
	return true;
}

// ── P1-2: Public Translation API ─────────────────────────────────────

/**
 * Get the translated value of a specific field for a post.
 *
 * Allows theme/plugin developers to retrieve a translated field value
 * outside the normal virtual-site request cycle.
 *
 * @since 1.5.1
 *
 * @param int    $post_id Source post ID.
 * @param string $field   Field name (post_title/post_content/post_excerpt or meta key).
 * @param string $lang    Target language code. Empty = current virtual site lang.
 * @return string|null Translated value or null.
 */
function wptsall_get_translated_field( int $post_id, string $field, string $lang = '' ): ?string {
	if ( '' === $lang ) {
		if ( isset( $GLOBALS['wptsall_current_virtual_site']['lang'] ) ) {
			$lang = (string) $GLOBALS['wptsall_current_virtual_site']['lang'];
		} else {
			return null;
		}
	}

	if ( class_exists( '\\WPTSALL\\Sites\\Services\\Manual_Content_Service' ) ) {
		$relations = \WPTSALL\Sites\Services\Site_Relation_Service::get_all( array( 'status' => 'active' ) );
		foreach ( $relations as $relation ) {
			$relation_id = (int) $relation['id'];
			$existing    = \WPTSALL\Sites\Services\Manual_Content_Service::find_existing_translation( $post_id, $relation_id );
			if ( $existing ) {
				$target_post = get_post( $existing );
				if ( $target_post ) {
					if ( property_exists( $target_post, $field ) ) {
						return (string) $target_post->$field;
					}
					$meta_val = get_post_meta( $existing, $field, true );
					if ( '' !== $meta_val && is_scalar( $meta_val ) ) {
						return (string) $meta_val;
					}
				}
			}
		}
	}

	return null;
}

/**
 * P2-2: Register a custom string for translation.
 *
 * @since 1.5.1
 *
 * @param string $string  Original string.
 * @param string $name    Unique identifier.
 * @param string $context Translation context.
 * @param string $domain  Text domain.
 * @return void
 */
function wptsall_register_string( string $string, string $name, string $context = 'custom', string $domain = 'wptsall_custom' ): void {
	if ( '' === trim( $string ) ) {
		return;
	}
	if ( class_exists( '\\WPTSALL\\Strings\\Services\\String_Translation_Service' ) ) {
		\WPTSALL\Strings\Services\String_Translation_Service::register( $context, $name, $string, $domain );
	}
}

/**
 * P2-2: Translate a registered custom string.
 *
 * @since 1.5.1
 *
 * @param string $string  Original string.
 * @param string $name    Unique identifier.
 * @param string $lang    Target language. Empty = current virtual site lang.
 * @param string $context Translation context.
 * @return string
 */
function wptsall_translate_string( string $string, string $name, string $lang = '', string $context = 'custom' ): string {
	if ( '' === $lang ) {
		if ( isset( $GLOBALS['wptsall_current_virtual_site']['lang'] ) ) {
			$lang = (string) $GLOBALS['wptsall_current_virtual_site']['lang'];
		} else {
			return $string;
		}
	}

	if ( class_exists( '\\WPTSALL\\Strings\\Services\\String_Translation_Service' ) ) {
		$translated = \WPTSALL\Strings\Services\String_Translation_Service::translate( $context, $name, $string, $lang );
		if ( '' !== $translated ) {
			return $translated;
		}
	}

	return $string;
}

if ( function_exists( 'add_filter' ) && ! has_filter( 'wptsall_translate_string', 'wptsall_translate_string' ) ) {
	add_filter( 'wptsall_translate_string', 'wptsall_translate_string', 10, 4 );
}


/**
 * Resolve a post/term/attachment ID to its counterpart in a language / virtual site.
 *
 * Public API modeled on WPML's `wpml_object_id` / `apply_filters( 'wpml_object_id', ... )`.
 *
 * @since 2.2.0
 *
 * @param int         $element_id                Element ID.
 * @param string      $element_type              post|page|{cpt}|category|post_tag|attachment|any.
 * @param bool        $return_original_if_missing Return original when unmapped.
 * @param string|null $language_code             Locale or null = current virtual site.
 * @return int
 */
function wptsall_object_id( $element_id, $element_type = 'post', $return_original_if_missing = false, $language_code = null ) {
	if ( class_exists( '\\WPTSALL\\API\\Object_Id' ) ) {
		return (int) \WPTSALL\API\Object_Id::resolve( $element_id, $element_type, $return_original_if_missing, $language_code );
	}
	return $return_original_if_missing ? (int) $element_id : 0;
}

/**
 * Read term meta via raw SQL (bypass get_term_meta filters for hostile plugins).
 *
 * @param int    $term_id  Term ID.
 * @param string $meta_key Meta key.
 * @return mixed
 */
function wptsall_raw_term_meta( $term_id, $meta_key ) {
	$term_id  = (int) $term_id;
	$meta_key = (string) $meta_key;
	if ( $term_id <= 0 || '' === $meta_key ) {
		return '';
	}
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$raw = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->termmeta} WHERE term_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1",
			$term_id,
			$meta_key
		)
	);
	if ( null === $raw ) {
		return '';
	}
	return maybe_unserialize( $raw );
}

/**
 * Cached "does this table exist" check (non-persistent object cache).
 *
 * Several independent paths verify the same schema tables within one
 * request: activation runs create_tables() twice by design (P1-TEST-03),
 * the admin_init migration pass re-ensures the always-on family, and
 * module plugins_loaded hooks check their own tables. This collapses those
 * duplicate SHOW TABLES round-trips to one per table per request (audit
 * remediation: dedupe repeated create_tables passes). The result lives
 * only in the request-scoped object cache, and the key hashes the full
 * (prefixed) table name so multisite switch_to_blog never crosses blogs.
 *
 * @param string $table_name Full (prefixed) table name.
 * @return bool True when the table exists.
 */
function wptsall_schema_table_exists( $table_name ) {
	global $wpdb;

	$cache_key = 'wptsall_schema_table_exists_' . md5( (string) $table_name );
	$cached    = wp_cache_get( $cache_key, 'wptsall_schema' );
	if ( false !== $cached ) {
		return '1' === $cached;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;
	wp_cache_set( $cache_key, $exists ? '1' : '0', 'wptsall_schema' );

	return $exists;
}

/**
 * Run a named schema-ensure registrar at most once per request per blog.
 *
 * Companion to wptsall_schema_table_exists() for registrars that do not
 * check existence internally (unconditional dbDelta passes). The stamp is
 * written only AFTER the function actually ran, so a registrar that was
 * not loaded during an early pass (activation calls create_tables()
 * before wptsall_load_modules()) still runs in a later pass; and nothing
 * persists beyond the request.
 *
 * @param string $name          Stable ensure-pass name (caller-chosen).
 * @param string $function_name Registrar function to call when unstamped.
 * @return bool True when the registrar ran during this call.
 */
function wptsall_schema_ensure_once( $name, $function_name ) {
	global $wpdb;

	if ( ! function_exists( $function_name ) ) {
		return false;
	}

	$cache_key = 'wptsall_schema_ensure_' . md5( $wpdb->prefix . '|' . $name );
	if ( wp_cache_get( $cache_key, 'wptsall_schema' ) ) {
		return false;
	}

	call_user_func( $function_name );
	wp_cache_set( $cache_key, '1', 'wptsall_schema' );

	return true;
}


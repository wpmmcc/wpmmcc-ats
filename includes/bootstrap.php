<?php
/**
 * Bootstrap file - initializes plugin
 *
 * @package WPTSALL
 * @since 0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
// ISS-COR-011: canonical version is in wpmmcc-ats.php plugin header. Keep in sync.
if ( ! defined( 'WPTSALL_VERSION' ) ) {
	define( 'WPTSALL_VERSION', '2.1.4' );
}

if ( ! defined( 'WPTSALL_PATH' ) ) {
	define( 'WPTSALL_PATH', plugin_dir_path( dirname( __FILE__ ) ) );
}

if ( ! defined( 'WPTSALL_URL' ) ) {
	define( 'WPTSALL_URL', plugin_dir_url( dirname( __FILE__ ) ) );
}

if ( ! defined( 'WPTSALL_BASENAME' ) ) {
	// Prefer WPTSALL_FILE (set by wpmmcc-ats.php) so basename matches the active bootstrap file.
	$wptsall_main = defined( 'WPTSALL_FILE' ) ? WPTSALL_FILE : dirname( __FILE__, 2 ) . '/wpmmcc-ats.php';
	define( 'WPTSALL_BASENAME', plugin_basename( $wptsall_main ) );
}

/**
 * Log configuration
 *
 * Development: Enable logging with debug level
 * Production: Disable logging or set to error level only
 *
 * WPTSALL_LOG_ENABLED  - Enable/disable logging (default: false)
 * WPTSALL_LOG_LEVEL    - Minimum log level: debug, info, warning, error (default: error)
 * WPTSALL_LOG_CHANNELS - Array of channels to log, null for all channels
 */
if ( ! defined( 'WPTSALL_LOG_ENABLED' ) ) {
	define( 'WPTSALL_LOG_ENABLED', false ); // Production default: disabled
}

if ( ! defined( 'WPTSALL_LOG_LEVEL' ) ) {
	define( 'WPTSALL_LOG_LEVEL', 'error' ); // Production default: errors only
}

// Load autoloader
require_once WPTSALL_PATH . 'includes/class-autoloader.php';

// Register autoloader
$wptsall_autoloader = new WPTSALL\Autoloader( WPTSALL_PATH . 'includes/' );
$wptsall_autoloader->register();

/**
 * Text domain is `wpmmcc-ats` (matches plugin slug).
 *
 * WordPress core auto-loads translations from WP_LANG_DIR only (language packs
 * for WordPress.org-hosted plugins, WP 4.6+). It does not scan a plugin's own
 * Domain Path, so bundled languages/wpmmcc-ats-*.mo files need an explicit
 * load_plugin_textdomain() call to register the directory with
 * WP_Textdomain_Registry. Verified 2026-09-08: without this call a zh_CN site
 * keeps rendering English while the bundled .mo sits unused.
 *
 * Legacy override: Langpack_Service::load_uploads_textdomain() (init, priority 1)
 * re-loads wp-content/uploads/wpmmcc-ats/languages/*.mo last and keeps its
 * historical precedence over the bundled pack.
 *
 * @since 1.9.0
 */
add_action(
	'init',
	function () {
		// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- bundled languages/*.mo require this call: WP 4.6+ JIT loading only scans WP_LANG_DIR, never a plugin's own Domain Path (Lab-verified 2026-09-08; see docblock above).
		load_plugin_textdomain( 'wpmmcc-ats', false, dirname( WPTSALL_BASENAME ) . '/languages' );
	},
	0
);

/**
 * Early initialization of Runtime_Tracker (Approach B)
 *
 * Initializes Runtime_Tracker at the earliest opportunity during plugins_loaded,
 * ensuring it starts tracking post_type registrations before other plugins' init hooks.
 *
 * Timeline:
 * 1. plugins_loaded (priority -9999) - Runtime_Tracker::init() runs here
 * 2. init (priority 10) - Most plugins register post_types here
 * 3. registered_post_type action - Runtime_Tracker captures each registration
 *
 * @since 0.6.0
 */
add_action(
	'plugins_loaded',
	function () {
		if ( class_exists( 'WPTSALL\Models\Scanners\Runtime_Tracker' ) ) {
			\WPTSALL\Models\Scanners\Runtime_Tracker::init();
		}
	},
	-9999  // Highest priority to ensure earliest execution.
);

// Load main controller class
require_once WPTSALL_PATH . 'includes/class-wptsall.php';

/**
 * Get main plugin instance
 *
 * @return WPTSALL\WPTSALL
 */
function wptsall() {
	return WPTSALL\WPTSALL::instance();
}

/**
 * Load plugin modules
 *
 * Initializes all modular components of the plugin.
 */
function wptsall_load_modules() {
	// Log module - load first to enable logging for other modules.
	require_once WPTSALL_PATH . 'includes/log/module.php';

	// Core - load functions and cache first.
	require_once WPTSALL_PATH . 'includes/core/functions.php';
	require_once WPTSALL_PATH . 'includes/core/cache.php';
	require_once WPTSALL_PATH . 'includes/core/database/migrations.php';

	// Transport security middleware — auto-encrypt/decrypt on HTTP for client routes.
	require_once WPTSALL_PATH . 'includes/core/class-transport-crypto.php';
	require_once WPTSALL_PATH . 'includes/core/class-transport-middleware.php';
	require_once WPTSALL_PATH . 'includes/core/class-content-format-registry.php';
	require_once WPTSALL_PATH . 'includes/core/class-content-data-type-map.php';
	require_once WPTSALL_PATH . 'includes/core/class-language-context.php';
	require_once WPTSALL_PATH . 'includes/core/class-translation-object-graph.php';
	require_once WPTSALL_PATH . 'includes/core/class-capabilities.php';
	require_once WPTSALL_PATH . 'includes/core/class-egress-guard.php';
	require_once WPTSALL_PATH . 'includes/core/class-component-trust.php';
	require_once WPTSALL_PATH . 'includes/core/class-job-snapshot.php';
	require_once WPTSALL_PATH . 'includes/core/capabilities-functions.php';
	\WPTSALL\Core\Transport_Middleware::init();
	\WPTSALL\Core\Language_Context::init();
	if ( class_exists( '\\WPTSALL\\Core\\Capabilities' ) ) {
		\WPTSALL\Core\Capabilities::init();
	}
	if ( class_exists( '\\WPTSALL\\Core\\Egress_Guard' ) ) {
		add_filter( 'pre_http_request', array( '\\WPTSALL\\Core\\Egress_Guard', 'filter_pre_http_request' ), 5, 3 );
	}

	// Client pairing module (client API token + pairing codes; all features free).
	require_once WPTSALL_PATH . 'includes/client-pairing/module.php';
	\WPTSALL\Client_Pairing\Module::init();

	// Models module
	require_once WPTSALL_PATH . 'includes/models/module.php';
	\WPTSALL\Models\Module::init();

	// Sites module
	require_once WPTSALL_PATH . 'includes/sites/module.php';
	\WPTSALL\Sites\Module::init();

	// Languages module (1.2.0 — decoupled from site_relations.target_lang)
	require_once WPTSALL_PATH . 'includes/languages/module.php';
	if ( class_exists( '\\WPTSALL\\Languages\\Module' ) ) {
		\WPTSALL\Languages\Module::init();
	}

	// Settings module (1.2.0 — full schema for URL form, default language, etc.)
	require_once WPTSALL_PATH . 'includes/settings/module.php';
	if ( class_exists( '\\WPTSALL\\Settings\\Module' ) ) {
		\WPTSALL\Settings\Module::init();
	}

	// Strings module (1.2.0 — site title/widget/menu string translation)
	require_once WPTSALL_PATH . 'includes/strings/module.php';
	if ( class_exists( '\\WPTSALL\\Strings\\Module' ) ) {
		\WPTSALL\Strings\Module::init();
	}

	// Translation status column (1.2.0 — Polylang-style post list column)
	require_once WPTSALL_PATH . 'includes/translation-status/class-translation-status-column.php';
	\WPTSALL\TranslationStatus\Translation_Status_Column::init();
	\WPTSALL\TranslationStatus\Translation_Status_Term_Column::init();

	// Translation memory (1.2.0)
	require_once WPTSALL_PATH . 'includes/translation-memory/module.php';
	if ( class_exists( '\\WPTSALL\\TranslationMemory\\Module' ) ) {
		\WPTSALL\TranslationMemory\Module::init();
	}

	// Media translation (1.3.0 — image src + alt swap on virtual-site requests)
	require_once WPTSALL_PATH . 'includes/media-translation/module.php';
	if ( class_exists( '\\WPTSALL\\MediaTranslation\\Module' ) ) {
		\WPTSALL\MediaTranslation\Module::init();
	}

	// Menu translation (1.3.0 — nav menu title/description/attr_title translation)
	require_once WPTSALL_PATH . 'includes/menu-translation/module.php';
	if ( class_exists( '\\WPTSALL\\MenuTranslation\\Module' ) ) {
		\WPTSALL\MenuTranslation\Module::init();
	}

	// Widget translation (1.3.0 — widget title + text fields)
	require_once WPTSALL_PATH . 'includes/widget-translation/module.php';
	if ( class_exists( '\\WPTSALL\\WidgetTranslation\\Module' ) ) {
		\WPTSALL\WidgetTranslation\Module::init();
	}

	// Theme & plugin localization (1.3.0 — locale filter for .mo files)
	require_once WPTSALL_PATH . 'includes/theme-localization/module.php';
	if ( class_exists( '\\WPTSALL\\ThemeLocalization\\Module' ) ) {
		\WPTSALL\ThemeLocalization\Module::init();
	}

	// FSE / block theme content adapter (templates, navigation, global styles)
	require_once WPTSALL_PATH . 'includes/fse/module.php';
	if ( class_exists( '\\WPTSALL\\Fse\\Module' ) ) {
		\WPTSALL\Fse\Module::init();
	}

	// Custom field translation (1.3.0 — CPT meta values, frontend swap)
	require_once WPTSALL_PATH . 'includes/custom-fields/module.php';
	if ( class_exists( '\\WPTSALL\\CustomFields\\Module' ) ) {
		\WPTSALL\CustomFields\Module::init();
	}

	// User translation (1.3.0 — user_mappings + display_name frontend swap)
	require_once WPTSALL_PATH . 'includes/user-translation/module.php';
	if ( class_exists( '\\WPTSALL\\UserTranslation\\Module' ) ) {
		\WPTSALL\UserTranslation\Module::init();
	}

	// Sync service (1.3.0 — bidirectional meta/taxonomy sync, only fires when sync_mode=full)
	require_once WPTSALL_PATH . 'includes/sync/module.php';
	if ( class_exists( '\\WPTSALL\\Sync\\Module' ) ) {
		\WPTSALL\Sync\Module::init();
	}
	add_action( 'admin_enqueue_scripts', function ( $hook ) {
		if ( 'edit.php' !== $hook ) { return; }
		wp_enqueue_style( 'wptsall-translation-flags', WPTSALL_URL . 'assets/css/translation-flags.css', array(), WPTSALL_VERSION );
	} );

	// Manual translation management (1.4.0 — URL discovery, pending, taxonomy/field)
	require_once WPTSALL_PATH . 'includes/manual-translation/module.php';
	if ( class_exists( '\WPTSALL\ManualTranslation\Module' ) ) {
		\WPTSALL\ManualTranslation\Module::init();
	}

	// P5-9 Language meta saver for Quick Edit / Bulk Edit (1.4.0)
	require_once WPTSALL_PATH . 'includes/manual-translation/hooks/class-language-meta-saver.php';
	if ( class_exists( '\WPTSALL\ManualTranslation\Hooks\Language_Meta_Saver' ) ) {
		\WPTSALL\ManualTranslation\Hooks\Language_Meta_Saver::init();
	}


	// Tasks module (task creation, monitoring, cron; client REST via token auth).
	require_once WPTSALL_PATH . 'includes/tasks/module.php';
	\WPTSALL\Tasks\Module::init();

	// Hooks module
	require_once WPTSALL_PATH . 'includes/hooks/module.php';
	\WPTSALL\Hooks\Module::init();

	// Templates module (language pack management)
	require_once WPTSALL_PATH . 'includes/templates/module.php';
	\WPTSALL\Templates\Module::init();

	// WP-CLI module (P6-1) — registers wptsall translate/tm/strings/tasks commands
	require_once WPTSALL_PATH . 'includes/cli/module.php';
	if ( class_exists( '\\WPTSALL\\CLI\\Module' ) ) {
		\WPTSALL\CLI\Module::init();
	}

	// Admin common (only in admin context).
	if ( is_admin() ) {
		require_once WPTSALL_PATH . 'includes/admin/menu.php';
		require_once WPTSALL_PATH . 'includes/admin/dashboard-widget.php';
		require_once WPTSALL_PATH . 'includes/admin/class-compatibility-notices.php';
		if ( class_exists( '\\WPTSALL\\Admin\\Compatibility_Notices' ) ) {
			\WPTSALL\Admin\Compatibility_Notices::init();
		}
	}
}

/**
 * Initialize plugin
 *
 * Wrapped in a function to ensure all dependencies are loaded
 */
function wptsall_init() {
	// Load modules.
	wptsall_load_modules();

	// Start plugin
	wptsall();
}

// Initialize on plugins_loaded hook
add_action( 'plugins_loaded', 'wptsall_init', 0 );

// Load translations via WordPress.org (translate.wordpress.org). No remote download,
// and no bundled .po/.mo in the plugin zip. Legacy uploads under
// wp-content/uploads/wpmmcc-ats/languages/ are still loaded if present.
add_action(
	'init',
	function () {
		if ( class_exists( '\\WPTSALL\\Core\\Langpack_Service' ) ) {
			\WPTSALL\Core\Langpack_Service::load_uploads_textdomain();
		}
	},
	1
);

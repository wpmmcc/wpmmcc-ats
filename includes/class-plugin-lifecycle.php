<?php
/**
 * Plugin Lifecycle Management
 *
 * Handles plugin activation, deactivation, and uninstallation.
 * Follows WordPress Plugin Guidelines for proper data handling.
 *
 * @package WPTSALL
 * @since 0.3.0
 */

namespace WPTSALL;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin Lifecycle class
 *
 * Manages all plugin lifecycle events including:
 * - Activation: Create database tables, set default options, flush rewrite rules
 * - Deactivation: Clean up temporary data, flush rewrite rules
 * - Uninstallation: Remove all plugin data (tables, options, transients)
 */
class Plugin_Lifecycle {

	/**
	 * Plugin database tables (without prefix)
	 *
	 * Keep this list in sync with uninstall.php!
	 *
	 * @var array
	 */
	private static $tables = array(
		// Hooks module (table deprecated in v0.9.0, kept for cleanup).
		'wptsall_hooks',

		// Mappings.
		'wptsall_mappings',

		// Models module.
		'wptsall_models',
		'wptsall_model_objects',           // Plugin template objects (v1.0.1).
		'wptsall_model_object_fields',     // Plugin template object fields (v1.0.1).
		'wptsall_model_url_rules',         // Legacy table (V1).
		'wptsall_translation_rules',       // V2 table.
		'wptsall_plugin_mappings',         // V2 table.
		'wptsall_model_link_chains',       // Link chains (v0.7.0+).
		'wptsall_link_chains',             // Legacy name (kept for cleanup).

		// Sites module.
		'wptsall_site_relations',
		'wptsall_relation_models',         // Relation-model many-to-many (v0.6.0).
		'wptsall_site_groups',             // Legacy site groups (removed, kept for cleanup).
		'wptsall_site_group_models',       // Legacy site group models (removed, kept for cleanup).
		'wptsall_relation_post_type_configs', // Relation configs (v0.8.0).
		'wptsall_virtual_sites',
		'wptsall_virtual_site_content',    // Virtual site content (v0.5.0).
		'wptsall_virtual_content',         // Deprecated in 0.5.0, kept for cleanup.

		// Field mappings (v0.5.0).
		'wptsall_term_mappings',
		'wptsall_media_mappings',
		'wptsall_post_mappings',
		'wptsall_content_change_outbox',
		'wptsall_user_mappings',

		// Sync module.
		'wptsall_sync_meta',
		'wptsall_conflicts',
		'wptsall_snapshots',

		// Tasks module (Free edition task engine; shared with Pro).
		'wptsall_tasks',
		'wptsall_task_logs',
		'wptsall_task_items',
		'wptsall_task_jobs',
		'wptsall_translation_results',
		'wptsall_origin_visits',
		'wptsall_manual_queue',

		// Client API / language packs / site strings (rate limits, per-relation
		// languages, menu mappings, option sync leases; v1.0+ modules).
		'wptsall_client_rate_limits',
		'wptsall_languages',
		'wptsall_strings',
		'wptsall_menu_mappings',
		'wptsall_option_sync_state',

		// Translation memory module.
		'wptsall_translation_memory',
		'wptsall_terminology',

		// Templates module.
		'wptsall_templates',
		'wptsall_template_entries',
	);

	/**
	 * Plugin options (stored in wp_options)
	 *
	 * @var array
	 */
	private static $options = array(
		'wptsall_db_version',
		'wptsall_initialized',         // Initialization status flag.
		'wptsall_activated_version',   // Version that was last activated.
		'wptsall_settings',
		'wptsall_hooks',
		'wptsall_sites',
		'wptsall_virtual_sites',       // Virtual sites stored as option (v0.5.0+).
		'wptsall_advanced_config',
		'wptsall_license_key',
		'wptsall_license_status',
		'wptsall_client_route_secret', // Client API route secret (v0.9.0+).
		'wptsall_ssot_read_source',    // SSOT read source toggle (v1.2.0+).
		'wptsall_task_parameters',     // Task processing parameters (tasks module).
	);

	/**
	 * Plugin transients prefix
	 *
	 * @var string
	 */
	private static $transient_prefix = 'wptsall_';

	/**
	 * Plugin cron hooks
	 *
	 * Keep this list in sync with uninstall.php and all cron registrations!
	 *
	 * @var array
	 */
	private static $cron_hooks = array(
		// Maintenance.
		'wptsall_daily_cleanup',
		'wptsall_weekly_maintenance',

		// i18n scan.
		'wptsall_run_i18n_scan',

		// Tasks module monitoring & retry (registered by wptsall_setup_cron_jobs).
		'wptsall_retry_failed_tasks',
		'wptsall_process_monitoring_tasks',
	);

	/**
	 * Run activation tasks
	 *
	 * Called when plugin is activated.
	 *
	 * @param bool $network_wide Whether the plugin is being activated network-wide.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		// Check minimum requirements.
		self::check_requirements();

		// Create tables BEFORE loading modules. P1-TEST-01 (2026-09-02):
		// module init (e.g. Translation_Status_Column →
		// Plugin_Mapping_Service::get_all) queries wptsall_models during
		// activation; with modules loaded first those queries ran against a
		// database where the tables did not exist yet, emitting DB errors
		// (wp-cli then fails activation with "unexpected output").
		if ( is_multisite() && $network_wide ) {
			// Network activation.
			self::network_activate();
		} else {
			// Single site activation.
			self::single_site_activate();
		}

		// Activation runs before (or outside) the normal plugins_loaded flow in some contexts.
		// Load modules eagerly so schema/cron registration functions are available during activation.
		if ( function_exists( 'wptsall_load_modules' ) ) {
			wptsall_load_modules();
		}

		// P1-TEST-03 (2026-09-03): the pre-module create_tables() pass above only
		// installs tables whose registrars are already loaded (templates legacy trio).
		// Module schema helpers (models, sites, field mappings, tasks, …) register
		// during wptsall_load_modules(); run create_tables again so fresh slot
		// activations reach the full >=24-table baseline expected by E2E Stage 1.
		self::create_tables();

		// Set activation flag for admin notice.
		set_transient( 'wptsall_activated', true, 60 );
	}

	/**
	 * Check if first-time activation (needs initialization)
	 *
	 * Sets a transient to trigger redirect to init page on first admin visit.
	 * Uses wptsall_activated_version to track if this version has been set up.
	 *
	 * @return void
	 */
	private static function maybe_set_init_redirect() {
		// Check if this version has already been activated and set up.
		// This is more reliable than checking wptsall_initialized which can be
		// set/unset by user actions.
		$activated_version = get_option( 'wptsall_activated_version', '' );
		$current_version   = defined( 'WPTSALL_VERSION' ) ? WPTSALL_VERSION : '1.0.0';

		// If same version was already activated, skip redirect.
		if ( $activated_version === $current_version && get_option( 'wptsall_initialized', false ) ) {
			return;
		}

		// Mark this version as activated.
		update_option( 'wptsall_activated_version', $current_version );

		// If already initialized, skip redirect.
		if ( get_option( 'wptsall_initialized', false ) ) {
			return;
		}

		// Set transient to trigger redirect on next admin page load.
		// Use current user ID to avoid redirecting other admins.
		$user_id = get_current_user_id();
		if ( $user_id ) {
			set_transient( 'wptsall_activation_redirect_' . $user_id, true, 300 );
			wptsall_log_debug( 'core', 'Set activation redirect transient', array( 'user_id' => $user_id ) );
		}
	}

	/**
	 * Run deactivation tasks
	 *
	 * Called when plugin is deactivated.
	 * Note: Does NOT delete data - only cleans up temporary items.
	 *
	 * @param bool $network_wide Whether the plugin is being deactivated network-wide.
	 * @return void
	 */
	public static function deactivate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			// Network deactivation.
			self::network_deactivate();
		} else {
			// Single site deactivation.
			self::single_site_deactivate();
		}
	}

	/**
	 * Run uninstall tasks
	 *
	 * Called when plugin is deleted.
	 * Removes ALL plugin data including database tables and options.
	 *
	 * @return void
	 */
	public static function uninstall() {
		// Security check - only allow uninstall through WordPress.
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			exit;
		}

		// Check user capability.
		// WP-CLI may run without a logged-in user context.
		if ( ! current_user_can( 'delete_plugins' ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		if ( is_multisite() ) {
			// Network uninstall.
			self::network_uninstall();
		} else {
			// Single site uninstall.
			self::single_site_uninstall();
		}
	}

	/**
	 * Check minimum requirements
	 *
	 * @return void
	 */
	private static function check_requirements() {
		$errors = array();

		// Check PHP version.
		if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
			$errors[] = sprintf(
				/* translators: %s: Required PHP version */
				__( 'WPTSALL requires PHP %s or higher.', 'wpmmcc-ats' ),
				'7.4'
			);
		}

		// Check WordPress version (must match "Requires at least" in wpmmcc-ats.php).
		if ( version_compare( get_bloginfo( 'version' ), '6.2', '<' ) ) {
			$errors[] = sprintf(
				/* translators: %s: Required WordPress version */
				__( 'WPTSALL requires WordPress %s or higher.', 'wpmmcc-ats' ),
				'6.2'
			);
		}

		// If there are errors, deactivate and show message.
		if ( ! empty( $errors ) ) {
			deactivate_plugins( plugin_basename( WPTSALL_FILE ) );
			wp_die(
				wp_kses_post( implode( '<br>', $errors ) ),
				esc_html__( 'Plugin Activation Error', 'wpmmcc-ats' ),
				array( 'back_link' => true )
			);
		}
	}

	/**
	 * Single site activation
	 *
	 * @return void
	 */
	private static function single_site_activate() {
		// Create database tables.
		self::create_tables();

		// Set default options.
		self::set_default_options();

		// Register rewrite rules.
		self::register_rewrite_rules();

		// Flush rewrite rules.
		flush_rewrite_rules();

		// Schedule cron events.
		self::schedule_cron_events();

		// Generate client route secret if not exists.
		if ( function_exists( 'wptsall_get_client_route_secret' ) ) {
			wptsall_get_client_route_secret();
		}

		// Set redirect transient if first-time activation.
		self::maybe_set_init_redirect();

		// Fire activation actions.
		// Back-compat: some modules register activation callbacks on 'wptsall_activate'.
		do_action( 'wptsall_activate' );
		do_action( 'wptsall_activated' );
	}

	/**
	 * Network activation (multisite)
	 *
	 * @return void
	 */
	private static function network_activate() {
		global $wpdb;

		// Get all blog IDs.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$blog_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT blog_id FROM %i', $wpdb->blogs ) );

		foreach ( $blog_ids as $blog_id ) {
			switch_to_blog( $blog_id );
			self::single_site_activate();
			restore_current_blog();
		}
	}

	/**
	 * Single site deactivation
	 *
	 * @return void
	 */
	private static function single_site_deactivate() {
		// Clear scheduled cron events.
		self::clear_cron_events();

		// Clear transients.
		self::clear_transients();

		// Flush rewrite rules.
		flush_rewrite_rules();

		// Fire deactivation action.
		do_action( 'wptsall_deactivated' );
	}

	/**
	 * Network deactivation (multisite)
	 *
	 * @return void
	 */
	private static function network_deactivate() {
		global $wpdb;

		// Get all blog IDs.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$blog_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT blog_id FROM %i', $wpdb->blogs ) );

		foreach ( $blog_ids as $blog_id ) {
			switch_to_blog( $blog_id );
			self::single_site_deactivate();
			restore_current_blog();
		}
	}

	/**
	 * Single site uninstall
	 *
	 * @return void
	 */
	private static function single_site_uninstall() {
		// Drop database tables.
		self::drop_tables();

		// Delete options.
		self::delete_options();

		// Delete transients.
		self::delete_all_transients();

		// Clear cron events.
		self::clear_cron_events();

		// Delete user meta.
		self::delete_user_meta();

		// Delete virtual site content (posts and terms).
		self::delete_virtual_content();

		// Delete post meta and term meta.
		self::delete_content_meta();

		// Fire uninstall action.
		do_action( 'wptsall_uninstalled' );
	}

	/**
	 * Network uninstall (multisite)
	 *
	 * @return void
	 */
	private static function network_uninstall() {
		global $wpdb;

		// Get all blog IDs.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$blog_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT blog_id FROM %i', $wpdb->blogs ) );

		foreach ( $blog_ids as $blog_id ) {
			switch_to_blog( $blog_id );
			self::single_site_uninstall();
			restore_current_blog();
		}

		// Clean up any remaining wptsall tables with non-standard prefixes (e.g., Plugin Check sandbox wp_pc_).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$all_wptsall_tables = $wpdb->get_col(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				'%' . $wpdb->esc_like( 'wptsall' ) . '%'
			)
		);
		foreach ( (array) $all_wptsall_tables as $table_name ) {
			// Sanitize table name - only allow alphanumeric and underscore.
			$safe_table = preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $table_name );
			if ( $safe_table === $table_name && '' !== $safe_table ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
				$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $safe_table ) );
			}
		}
	}

	/**
	 * Create database tables
	 *
	 * Creates all plugin database tables in the correct order.
	 * Uses function_exists() checks to ensure graceful handling
	 * if some modules are not loaded.
	 *
	 * @return void
	 */
	private static function create_tables() {
		// Activation can call create_tables() before wptsall_load_modules().
		// Translation-memory schema (and other helpers) need wptsall_table().
		if ( ! function_exists( 'wptsall_table' ) ) {
			$functions = ( defined( 'WPTSALL_PATH' ) ? WPTSALL_PATH : '' ) . 'includes/core/functions.php';
			if ( is_readable( $functions ) ) {
				require_once $functions;
			}
		}

		// If plugin tables were previously removed (uninstall, manual cleanup, etc.)
		// but init-related options survived, reset them so the admin will go
		// through the initialization flow again after activation.
		if ( get_option( 'wptsall_initialized', false ) && ! self::has_base_tables() ) {
			delete_option( 'wptsall_initialized' );
			delete_option( 'wptsall_activated_version' );
			wptsall_log_warning( 'core', 'Stale initialization flag detected (tables missing); reset init-related options', array() );
		}

		// ═══════════════════════════════════════════════════════════════════
		// 1. Core tables
		// ═══════════════════════════════════════════════════════════════════
		if ( function_exists( 'wptsall_ensure_mapping_table' ) ) {
			wptsall_ensure_mapping_table();
		}

		// ═══════════════════════════════════════════════════════════════════
		// 2. Hooks module - removed in v0.9.0 (hooks table deprecated since v0.7.0)
		// Hooks are now auto-generated by Hook_Manager, no database table needed.
		// ═══════════════════════════════════════════════════════════════════

		// ═══════════════════════════════════════════════════════════════════
		// 3. Models module
		// ═══════════════════════════════════════════════════════════════════
		if ( function_exists( 'wptsall_create_model_tables' ) ) {
			wptsall_create_model_tables();
		}

		// ═══════════════════════════════════════════════════════════════════
		// 4. Sites module
		// ═══════════════════════════════════════════════════════════════════
		if ( function_exists( 'wptsall_create_site_relations_table' ) ) {
			wptsall_create_site_relations_table();
		}
		if ( function_exists( 'wptsall_create_relation_models_table' ) ) {
			wptsall_create_relation_models_table();
		}
		if ( function_exists( 'wptsall_create_virtual_sites_table' ) ) {
			wptsall_create_virtual_sites_table();
		}
		if ( function_exists( 'wptsall_create_relation_post_type_configs_table' ) ) {
			wptsall_create_relation_post_type_configs_table();
		}
		// user_mappings is part of Sites module (schema-user-mappings.php).
		if ( function_exists( 'wptsall_create_user_mappings_table' ) ) {
			wptsall_create_user_mappings_table();
		}

		// ═══════════════════════════════════════════════════════════════════
		// 5. Field mappings (v0.5.0) — term/media/post; user_mappings above
		// ═══════════════════════════════════════════════════════════════════
		if ( function_exists( 'wptsall_create_field_mapping_tables' ) ) {
			wptsall_create_field_mapping_tables();
		}

		// ═══════════════════════════════════════════════════════════════════
		// 6. Templates module
		// ═══════════════════════════════════════════════════════════════════
		if ( function_exists( 'wptsall_ensure_templates_tables' ) ) {
			wptsall_ensure_templates_tables();
		}

		// ═══════════════════════════════════════════════════════════════════
		// 7. Tasks module (also registered via wptsall_activate action hook,
		//    but calling explicitly here ensures correct ordering).
		// ═══════════════════════════════════════════════════════════════════
		if ( function_exists( 'wptsall_init_tasks_tables' ) ) {
			wptsall_init_tasks_tables();
		}

		// ═══════════════════════════════════════════════════════════════════
		// 8. Translation Memory
		// ═══════════════════════════════════════════════════════════════════
		$tm_schema = ( defined( 'WPTSALL_PATH' ) ? WPTSALL_PATH : '' ) . 'includes/translation-memory/database/schema-translation-memory.php';
		if ( ! function_exists( 'wptsall_create_translation_memory_table' ) && is_readable( $tm_schema ) ) {
			require_once $tm_schema;
		}
		if ( function_exists( 'wptsall_create_translation_memory_table' ) ) {
			wptsall_create_translation_memory_table();
		}

		// ═══════════════════════════════════════════════════════════════════
		// 9. Run migrations
		// ═══════════════════════════════════════════════════════════════════
		if ( function_exists( 'wptsall_run_migrations' ) ) {
			wptsall_run_migrations();
		}

		// Run v0.8.x specific migrations.
		if ( function_exists( 'wptsall_migrate_site_relations_080' ) ) {
			wptsall_migrate_site_relations_080();
		}
		if ( function_exists( 'wptsall_migrate_site_relations_081' ) ) {
			wptsall_migrate_site_relations_081();
		}
	}

	/**
	 * Check whether this site already has WPTSALL base tables.
	 *
	 * Activation logic uses this to differentiate:
	 * - re-activation (tables exist) vs
	 * - fresh install/reinstall (tables missing).
	 *
	 * @return bool
	 */
	private static function has_base_tables() {
		global $wpdb;

		$tables = array();
		if ( function_exists( 'wptsall_table' ) ) {
			// Newer installs use the unified models table; keep plugin_mappings for legacy installs.
			$tables[] = wptsall_table( 'models' );
			$tables[] = wptsall_table( 'plugin_mappings' );
		} else {
			$tables[] = $wpdb->prefix . 'wptsall_models';
			$tables[] = $wpdb->prefix . 'wptsall_plugin_mappings';
		}

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $exists === $table ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Drop database tables
	 *
	 * @return void
	 */
	private static function drop_tables() {
		global $wpdb;

		foreach ( self::$tables as $table ) {
			$table_name = $wpdb->prefix . $table;
			// Sanitize table name - only allow alphanumeric and underscore.
			$table_name = preg_replace( '/[^a-zA-Z0-9_]/', '', $table_name );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table_name ) );
		}
	}

	/**
	 * Set default options
	 *
	 * @return void
	 */
	private static function set_default_options() {
		// Set default settings if not exist.
		if ( false === get_option( 'wptsall_settings' ) ) {
			$default_settings = array(
				'enable_virtual_sites' => true,
				'enable_auto_sync'     => false,
				'default_language'     => get_locale(),
				'cache_ttl'            => 3600,
				'debug_mode'           => false,
			);
			add_option( 'wptsall_settings', $default_settings );
		}

		// Set database version.
		if ( false === get_option( 'wptsall_db_version' ) ) {
			add_option( 'wptsall_db_version', '0.0.0' );
		}
	}

	/**
	 * Delete options
	 *
	 * @return void
	 */
	private static function delete_options() {
		foreach ( self::$options as $option ) {
			delete_option( $option );
		}

		// Delete template options (dynamic names).
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name LIKE %s',
				$wpdb->options,
				$wpdb->esc_like( 'wptsall_template_' ) . '%'
			)
		);
	}

	/**
	 * Register rewrite rules
	 *
	 * @return void
	 */
	private static function register_rewrite_rules() {
		if ( function_exists( 'wptsall_register_virtual_rewrite' ) ) {
			wptsall_register_virtual_rewrite();
		}
	}

	/**
	 * Schedule cron events
	 *
	 * @return void
	 */
	private static function schedule_cron_events() {
		// Schedule daily cleanup.
		if ( ! wp_next_scheduled( 'wptsall_daily_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'wptsall_daily_cleanup' );
		}
	}

	/**
	 * Clear cron events
	 *
	 * @return void
	 */
	private static function clear_cron_events() {
		foreach ( self::$cron_hooks as $hook ) {
			$timestamp = wp_next_scheduled( $hook );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
			}
			// Clear all instances of the hook.
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Clear transients (temporary)
	 *
	 * @return void
	 */
	private static function clear_transients() {
		// Only clear cache transients, keep important ones.
		delete_transient( 'wptsall_cache_scan_results' );
		delete_transient( 'wptsall_cache_templates' );
	}

	/**
	 * Delete all transients (full cleanup)
	 *
	 * @return void
	 */
	private static function delete_all_transients() {
		global $wpdb;

		// Delete transients.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
				$wpdb->options,
				$wpdb->esc_like( '_transient_' . self::$transient_prefix ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . self::$transient_prefix ) . '%'
			)
		);

		// Delete site transients (multisite).
		if ( is_multisite() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					'DELETE FROM %i WHERE meta_key LIKE %s OR meta_key LIKE %s',
					$wpdb->sitemeta,
					$wpdb->esc_like( '_site_transient_' . self::$transient_prefix ) . '%',
					$wpdb->esc_like( '_site_transient_timeout_' . self::$transient_prefix ) . '%'
				)
			);
		}
	}

	/**
	 * Delete user meta
	 *
	 * @return void
	 */
	private static function delete_user_meta() {
		global $wpdb;

		// Delete user meta related to plugin.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE meta_key LIKE %s',
				$wpdb->usermeta,
				$wpdb->esc_like( 'wptsall_' ) . '%'
			)
		);
	}

	/**
	 * Delete virtual site content (posts and terms)
	 *
	 * Virtual site content is stored in wp_posts/wp_terms with meta markers.
	 * This must be called BEFORE delete_content_meta() to find virtual copies.
	 *
	 * @since 0.5.0
	 * @return void
	 */
	private static function delete_virtual_content() {
		global $wpdb;

		// Delete virtual site posts.
		// Use '_wptsall_virtual_site_id' which is the actual meta key set by sync code.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$virtual_post_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT p.ID
				 FROM %i p
				 INNER JOIN %i pm ON p.ID = pm.post_id
				 	AND pm.meta_key = %s',
				$wpdb->posts,
				$wpdb->postmeta,
				'_wptsall_virtual_site_id'
			)
		);

		foreach ( $virtual_post_ids as $post_id ) {
			wp_delete_post( $post_id, true ); // Force delete, bypass trash.
		}

		// Delete virtual site terms.
		// Use '_wptsall_virtual_site_id' which is the actual meta key set by sync code.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$virtual_term_data = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT t.term_id, tt.taxonomy
				 FROM %i t
				 INNER JOIN %i tt ON t.term_id = tt.term_id
				 INNER JOIN %i tm ON t.term_id = tm.term_id
				 	AND tm.meta_key = %s',
				$wpdb->terms,
				$wpdb->term_taxonomy,
				$wpdb->termmeta,
				'_wptsall_virtual_site_id'
			),
			ARRAY_A
		);

		foreach ( $virtual_term_data as $term ) {
			wp_delete_term( $term['term_id'], $term['taxonomy'] );
		}
	}

	/**
	 * Delete content meta (post meta, term meta, comment meta)
	 *
	 * This must be called AFTER delete_virtual_content() to ensure
	 * virtual posts/terms are deleted first.
	 *
	 * @since 0.5.0
	 * @return void
	 */
	private static function delete_content_meta() {
		global $wpdb;

		// Delete post meta.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE meta_key LIKE %s',
				$wpdb->postmeta,
				$wpdb->esc_like( '_wptsall_' ) . '%'
			)
		);

		// Delete term meta.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE meta_key LIKE %s',
				$wpdb->termmeta,
				$wpdb->esc_like( '_wptsall_' ) . '%'
			)
		);

		// Delete comment meta.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE meta_key LIKE %s',
				$wpdb->commentmeta,
				$wpdb->esc_like( '_wptsall_' ) . '%'
			)
		);
	}

	/**
	 * Get plugin tables status
	 *
	 * @return array Table status information.
	 */
	public static function get_tables_status() {
		global $wpdb;

		$status = array();

		foreach ( self::$tables as $table ) {
			$table_name = $wpdb->prefix . $table;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
			);

			$row_count = 0;
			if ( $exists ) {
				// Sanitize table name for query.
				$safe_table = preg_replace( '/[^a-zA-Z0-9_]/', '', $table_name );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$row_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $safe_table ) );
			}

			$status[ $table ] = array(
				'exists'    => (bool) $exists,
				'row_count' => $row_count,
			);
		}

		return $status;
	}

	/**
	 * Get plugin options status
	 *
	 * @return array Options status information.
	 */
	public static function get_options_status() {
		$status = array();

		foreach ( self::$options as $option ) {
			$value            = get_option( $option, null );
			$status[ $option ] = array(
				'exists' => null !== $value,
				'value'  => $value,
			);
		}

		return $status;
	}
}

<?php
/**
 * WPTSALL Admin Menu Registration
 *
 * Register admin menus: Models, Sites, Tasks
 *
 * @package WPTSALL
 * @since 0.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Initialize pages
add_action( 'plugins_loaded', 'wptsall_init_admin_pages' );
add_action( 'admin_menu', 'wptsall_register_admin_page', 5 );
add_action( 'admin_enqueue_scripts', 'wptsall_enqueue_admin_common_styles' );

/**
 * Enqueue common admin styles on all WPTSALL pages
 *
 * @param string $hook Current admin page hook.
 */
function wptsall_enqueue_admin_common_styles( $hook ) {
    // Only load on WPTSALL pages
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';

    if ( strpos( $page, 'wpmmcc-ats' ) !== 0 ) {
        return;
    }

    wp_enqueue_style(
        'wptsall-admin-common',
        WPTSALL_URL . 'assets/css/admin-common.css',
        array(),
        WPTSALL_VERSION
    );
}

/**
 * Initialize admin pages
 */
function wptsall_init_admin_pages() {
    // Initialize the initialization page (handles redirect if not initialized)
    \WPTSALL\Admin\Initialization_Page::init();

    // Initialize Languages page (1.2.0 — top-level submenu)
    \WPTSALL\Languages\Admin\Languages_Page::init();

    // Initialize Model Backup page (1.2.0 — server-side JSON backup/restore)
    \WPTSALL\Models\Admin\Model_Backup_Handler::init();

    // Initialize Setup Wizard (1.2.0 — 5-step onboarding)
    \WPTSALL\Wizard\Setup_Wizard::init();

    // Initialize Model Editor page (registers script enqueue hooks)
    \WPTSALL\Models\Admin\Model_Editor_Page::init();

    // Initialize Sites page (registers script enqueue hooks)
    \WPTSALL\Sites\Admin\Sites_Page::init();

    // Initialize Conflict page (registers script enqueue hooks for Sites conflicts tab)
    \WPTSALL\Sites\Admin\Conflict_Page::init();

    // Initialize Translation Editor page (hidden page for manual translation)
    \WPTSALL\Sites\Admin\Translation_Editor_Page::init();

    // Initialize Tasks page
    \WPTSALL\Tasks\Admin\Tasks_Page::init();
}

/**
 * Register admin menus
 *
 * Menu structure:
 * - WPTSALL (main menu)
 *   - Models (wptsall) - Model management, import/export, field configurator
 *   - Sites (wptsall-sites) - Virtual sites, conflicts, add relation/virtual
 *   - Tasks (wptsall-tasks) - Relations overview, sync records, language packs, authorization, features
 */
function wptsall_register_admin_page() {
    // Main menu: WPTSALL - redirects to Models page
    add_menu_page(
        __( 'WPTSALL', 'wpmmcc-ats' ),
        __( 'WPTSALL', 'wpmmcc-ats' ),
        'manage_wptsall',
        'wpmmcc-ats',
        '__return_null', // Will be replaced by first submenu
        'dashicons-networking',
        59
    );

    // Submenu 1: Languages (1.2.0 — top-level concept for language CRUD)
    add_submenu_page(
        'wpmmcc-ats',
        __( 'Languages', 'wpmmcc-ats' ),
        __( 'Languages', 'wpmmcc-ats' ),
        'manage_wptsall_settings',
        'wptsall-languages',
        array( 'WPTSALL\\Languages\\Admin\\Languages_Page', 'render_page' )
    );

    // Submenu 2: Models (Model Editor)
    // Note: First submenu with same slug as parent replaces main menu callback
    add_submenu_page(
        'wpmmcc-ats',
        __( 'Model Management', 'wpmmcc-ats' ),
        __( 'Models', 'wpmmcc-ats' ),
        'manage_wptsall_settings',
        'wpmmcc-ats',
        array( 'WPTSALL\Models\Admin\Model_Editor_Page', 'render_page' )
    );

    // Submenu: Models Backup (1.2.0 — server-side JSON export/import)
    add_submenu_page(
        'wpmmcc-ats',
        __( 'Models Backup', 'wpmmcc-ats' ),
        __( 'Models Backup', 'wpmmcc-ats' ),
        'manage_wptsall_settings',
        'wptsall-models-backup',
        array( 'WPTSALL\\Models\\Admin\\Model_Backup_Handler', 'render_page' )
    );

    // Submenu 3: Sites (Sites - includes site relations, hooks, language packs)
    add_submenu_page(
        'wpmmcc-ats',
        __( 'Site Management', 'wpmmcc-ats' ),
        __( 'Sites', 'wpmmcc-ats' ),
        'manage_wptsall_settings',
        'wptsall-sites',
        array( 'WPTSALL\Sites\Admin\Sites_Page', 'render_page' )
    );

    // Submenu 4: Tasks (monitoring, jobs, relations, language packs, client auth)
    add_submenu_page(
        'wpmmcc-ats',
        __( 'Task Management', 'wpmmcc-ats' ),
        __( 'Tasks', 'wpmmcc-ats' ),
        'manage_wptsall_sync',
        'wptsall-tasks',
        array( 'WPTSALL\Tasks\Admin\Tasks_Page', 'render_page' )
    );

    // Hidden page: Translation Editor (accessed via direct link from post editor/list)
    add_submenu_page(
        null,
        __( 'Translation Editor', 'wpmmcc-ats' ),
        __( 'Translation Editor', 'wpmmcc-ats' ),
        'manage_wptsall_translations',
        'wptsall-translate',
        array( 'WPTSALL\\Sites\\Admin\\Translation_Editor_Page', 'render_page' )
    );

    // Note: Conflict management has been integrated into the Sites page tabs (v0.9.1)
}

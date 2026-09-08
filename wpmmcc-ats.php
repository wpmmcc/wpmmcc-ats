<?php
/**
 * Plugin Name: WPMMCC ATS
 * Plugin URI: https://www.wpmm.cc
 * Description: Scan content plugins for models and fields, build translation templates, manage site relations, and provide manual post translation for multilingual WordPress sites.
 * Version: 2.1.0
 * Requires at least: 6.2
 * Tested up to: 7.1
 * Requires PHP: 7.4
 * Author: wpmmcc
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wpmmcc-ats
 * Domain Path: /languages
 *
 * @package WPTSALL
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin file constant for activation/deactivation hooks.
if ( ! defined( 'WPTSALL_FILE' ) ) {
	define( 'WPTSALL_FILE', __FILE__ );
}

/**
 * WPMMCC ATS / WPTSALL plugin bootstrap.
 *
 * Canonical entry: this file only. Lab mounts wp-plugin/source as plugins/wpmmcc-ats.
 * See docs/architecture/current/PRE-RELEASE-SINGLE-TRUTH.md.
 */

// Load bootstrap file.
require_once plugin_dir_path( __FILE__ ) . 'includes/bootstrap.php';

// Load plugin lifecycle management.
require_once plugin_dir_path( __FILE__ ) . 'includes/class-plugin-lifecycle.php';

// Register activation hook.
register_activation_hook( __FILE__, array( 'WPTSALL\\Plugin_Lifecycle', 'activate' ) );

// Register deactivation hook.
register_deactivation_hook( __FILE__, array( 'WPTSALL\\Plugin_Lifecycle', 'deactivate' ) );

// Note: Uninstall is handled by uninstall.php in plugin root directory.

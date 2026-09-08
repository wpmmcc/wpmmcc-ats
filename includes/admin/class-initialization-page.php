<?php
/**
 * WPTSALL Initialization Page
 *
 * Handles first-time plugin setup and plugin scanning.
 *
 * @package WPTSALL
 * @since 0.3.1
 * @since 0.9.0 Added V4 scan consent flow
 */

namespace WPTSALL\Admin;

use WPTSALL\Models\Services\Plugin_Mapping_Service;
use WPTSALL\Models\Services\Plugin_Scanner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Initialization Page Class
 */
class Initialization_Page {

	/**
	 * Page slug
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'wptsall-init';

	/**
	 * Initialize the page
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect_to_init' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
		// Note: AJAX handlers removed in v0.5.0, using REST API exclusively
		// See: /wptsall/v2/initialize and /wptsall/v2/plugins/scan-all
	}

	/**
	 * Enqueue scripts for initialization page
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_scripts( $hook ) {
		// Only load on init page
		if ( 'admin_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'wptsall-initialization',
			WPTSALL_URL . 'assets/css/initialization.css',
			array(),
			WPTSALL_VERSION
		);

		wp_enqueue_script(
			'wptsall-initialization',
			WPTSALL_URL . 'assets/js/initialization.js',
			array( 'jquery' ),
			WPTSALL_VERSION,
			true
		);
	}

	/**
	 * Get all available active plugins (excluding wptsall)
	 *
	 * @return array
	 */
	protected static function get_available_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins = get_plugins();
		$active      = get_option( 'active_plugins', array() );

		$active             = array_unique( $active );
		$available_plugins  = array();

		foreach ( $active as $plugin_file ) {
			if ( ! isset( $all_plugins[ $plugin_file ] ) ) {
				continue;
			}

			if ( strpos( $plugin_file, 'wpmmcc-ats' ) !== false ) {
				continue;
			}

			$slug = Plugin_Scanner::get_plugin_slug( $plugin_file );
			if ( empty( $slug ) ) {
				continue;
			}

			$available_plugins[] = array(
				'file' => $plugin_file,
				'slug' => $slug,
				'name' => $all_plugins[ $plugin_file ]['Name'] ?? $slug,
			);
		}

		usort(
			$available_plugins,
			function ( $a, $b ) {
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		return $available_plugins;
	}

	/**
	 * Add hidden menu page
	 */
	public static function add_menu_page() {
		add_submenu_page(
			null, // Hidden page
			__( 'WPTSALL Initialization', 'wpmmcc-ats' ),
			__( 'Initialization', 'wpmmcc-ats' ),
			'manage_wptsall_settings',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Redirect to initialization page if not initialized
	 */
	public static function maybe_redirect_to_init() {
		// Skip for AJAX requests.
		if ( wp_doing_ajax() ) {
			return;
		}

		// Skip if user can't access admin pages.
		// Note: In multisite/network-admin, capability checks differ from single-site admin.
		$can_manage = wptsall_user_can_manage_translations();
		if ( ! $can_manage && is_multisite() && is_network_admin() ) {
			$can_manage = current_user_can( 'manage_network_options' ) || is_super_admin();
		}
		if ( ! $can_manage ) {
			return;
		}

		// Skip if already on init page.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::PAGE_SLUG === $current_page ) {
			return;
		}

		// Check for activation redirect transient (first-time activation).
		$user_id          = get_current_user_id();
		$transient_key    = 'wptsall_activation_redirect_' . $user_id;
		$should_redirect  = get_transient( $transient_key );

		// Debug: explain why init page did/didn't redirect.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && strpos( $current_page, 'wpmmcc-ats' ) === 0 ) {
			wptsall_log_debug(
				'admin',
				'Init redirect check',
				array(
					'blog_id'          => function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : null,
					'user_id'          => $user_id,
					'current_page'     => $current_page,
					'transient_key'    => $transient_key,
					'has_transient'    => (bool) $should_redirect,
					'is_initialized'   => Plugin_Mapping_Service::is_initialized(),
					'is_network_admin' => function_exists( 'is_network_admin' ) ? is_network_admin() : null,
				)
			);
		}

		if ( $should_redirect ) {
			// Delete transient to prevent redirect loop.
			delete_transient( $transient_key );

			// Redirect to init page.
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
			exit;
		}

		// Skip if not on WPTSALL pages (for regular init check).
		if ( strpos( $current_page, 'wpmmcc-ats' ) !== 0 ) {
			return;
		}

		// Check if initialized (redirect when accessing WPTSALL pages without init).
		if ( ! Plugin_Mapping_Service::is_initialized() ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
			exit;
		}
	}

	/**
	 * Check if REST API is available for wptsall
	 *
	 * @since 0.9.3
	 * @return bool
	 */
	protected static function is_rest_api_available() {
		if ( ! function_exists( 'rest_get_server' ) ) {
			return false;
		}

		$did_rest_api_init = (int) did_action( 'rest_api_init' );

		// Verify our routes are registered by checking the REST server.
		$server = rest_get_server();
		if ( ! is_object( $server ) || ! method_exists( $server, 'get_routes' ) ) {
			return false;
		}

		// WP core has changed route storage/lookup a few times across versions.
		// Some installs return full keys (including namespace), others return
		// namespace-filtered keys without the namespace prefix. Check both.
		$routes_all = $server->get_routes();
		$routes_ns  = $server->get_routes( 'wptsall/v2' );

		$critical_full = array(
			'/wptsall/v2/plugins/scan-all',
			'/wptsall/v2/initialize',
			'/wptsall/v2/admin/complete-initialization',
		);
		$critical_ns = array(
			'/plugins/scan-all',
			'/initialize',
			'/admin/complete-initialization',
		);

		$available = false;
		foreach ( $critical_full as $k ) {
			if ( isset( $routes_all[ $k ] ) ) {
				$available = true;
				break;
			}
		}
		if ( ! $available ) {
			foreach ( $critical_ns as $k ) {
				if ( isset( $routes_ns[ $k ] ) ) {
					$available = true;
					break;
				}
			}
		}

		// Debug: dump REST availability context (use debug.log).
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && ! $available ) {
			$wptsall_routes = array();
			foreach ( array_keys( $routes_all ) as $route_key ) {
				if ( strpos( $route_key, '/wptsall/v2' ) === 0 ) {
					$wptsall_routes[] = $route_key;
				}
				if ( count( $wptsall_routes ) >= 30 ) {
					break;
				}
			}
			wptsall_log_warning(
				'admin',
				'REST availability check failed',
				array(
					'did_rest_api_init' => $did_rest_api_init,
					'routes_all_count'  => is_array( $routes_all ) ? count( $routes_all ) : null,
					'routes_ns_count'   => is_array( $routes_ns ) ? count( $routes_ns ) : null,
					'wptsall_routes'    => $wptsall_routes,
				)
			);
		}

		return $available;
	}

	/**
	 * Render the initialization page
	 */
	public static function render_page() {
		$can_manage = wptsall_user_can_manage_translations();
		if ( ! $can_manage && is_multisite() && is_network_admin() ) {
			$can_manage = current_user_can( 'manage_network_options' ) || is_super_admin();
		}
		if ( ! $can_manage ) {
			return;
		}

		// Check REST API availability before rendering.
		$rest_api_available = self::is_rest_api_available();

		$is_initialized    = Plugin_Mapping_Service::is_initialized();
		$pending_consent   = Plugin_Mapping_Service::get_plugins_needing_consent();
		$pending_scans     = Plugin_Mapping_Service::get_pending_scans();
		$has_pending_scans = ! empty( $pending_scans );
		$available_plugins = self::get_available_plugins();

		wp_localize_script( 'wptsall-initialization', 'wptsallInitConfig', array(
			'restUrl'          => rest_url( 'wptsall/v2' ),
			'restNonce'        => wp_create_nonce( 'wp_rest' ),
			'restApiAvailable' => $rest_api_available,
			'redirectUrl'      => admin_url( 'admin.php?page=wptsall&tab=templates' ),
			'homeUrl'          => admin_url( 'admin.php?page=wptsall' ),
			'plugins'          => $available_plugins,
			'i18n'        => array(
				'scanSummary'       => __( 'Scan Results:', 'wpmmcc-ats' ),
				'totalScanned'      => __( 'Plugins Scanned:', 'wpmmcc-ats' ),
				'contentPlugins'    => __( 'Content Plugins:', 'wpmmcc-ats' ),
				'savedMappings'     => __( 'Saved Mappings:', 'wpmmcc-ats' ),
				'noPluginsSelected' => __( 'Please select at least one plugin', 'wpmmcc-ats' ),
				'scanning'          => __( 'Scanning...', 'wpmmcc-ats' ),
				'completed'         => __( 'Completed!', 'wpmmcc-ats' ),
				'scanFailed'        => __( 'Scan failed:', 'wpmmcc-ats' ),
				'skipFailed'        => __( 'Operation failed:', 'wpmmcc-ats' ),
				'confirmRescan'      => __( 'Are you sure you want to rescan all plugins? This will overwrite existing mapping data.', 'wpmmcc-ats' ),
				'processing'         => __( 'Processing...', 'wpmmcc-ats' ),
				'v4ScanCompleted'    => __( 'Deep scan completed!', 'wpmmcc-ats' ),
				'skipInit'           => __( 'Skip Initialization', 'wpmmcc-ats' ),
				'grantConsent'       => __( 'Authorize and Scan', 'wpmmcc-ats' ),
				'v4Scan'             => __( 'Start Deep Scan', 'wpmmcc-ats' ),
				'v4Scanned'          => __( 'Scanned successfully:', 'wpmmcc-ats' ),
				'v4Skipped'          => __( 'Skipped:', 'wpmmcc-ats' ),
				'v4Failed'           => __( 'Failed:', 'wpmmcc-ats' ),
			),
		) );
		Admin_Page_Helper::render_header(
			__( 'WPTSALL Initialization', 'wpmmcc-ats' ),
			'init',
			array(),
			array( 'wptsall-init-page' )
		);
		?>
		<div class="wptsall-page-content">

			<?php if ( ! $rest_api_available ) : ?>
				<div class="notice notice-error">
					<p>
						<strong><?php esc_html_e( 'REST API Unavailable', 'wpmmcc-ats' ); ?></strong><br>
						<?php esc_html_e( 'The plugin REST API endpoints are not properly registered. This may be because:', 'wpmmcc-ats' ); ?>
					</p>
					<ul style="list-style: disc; margin-left: 20px;">
						<li><?php esc_html_e( 'The plugin is not fully activated', 'wpmmcc-ats' ); ?></li>
						<li><?php esc_html_e( 'An error occurred during plugin loading', 'wpmmcc-ats' ); ?></li>
						<li><?php esc_html_e( 'There is a conflict with another plugin', 'wpmmcc-ats' ); ?></li>
					</ul>
					<p>
						<?php esc_html_e( 'Please try deactivating and reactivating the plugin, or check the error logs.', 'wpmmcc-ats' ); ?>
					</p>
				</div>
			<?php elseif ( $is_initialized ) : ?>
				<div class="notice notice-success">
					<p><?php esc_html_e( 'Plugin initialization is complete.', 'wpmmcc-ats' ); ?></p>
				</div>

				<?php if ( $has_pending_scans ) : ?>
					<!-- V4 Deep Scan Step -->
					<div class="wptsall-v4-scan-section">
						<h2><?php esc_html_e( 'Deep Field Scan', 'wpmmcc-ats' ); ?></h2>
						<p><?php esc_html_e( 'The following plugins are authorized for deep field scanning to obtain more complete configurations:', 'wpmmcc-ats' ); ?></p>

						<div class="wptsall-pending-scans">
							<?php foreach ( $pending_scans as $plugin ) : ?>
								<div class="wptsall-plugin-item">
									<span class="plugin-name"><?php echo esc_html( $plugin['plugin_name'] ); ?></span>
									<span class="plugin-types">
										<?php
										$types = array_map(
											function ( $pt ) {
												return is_array( $pt ) ? ( $pt['name'] ?? '' ) : $pt;
											},
											$plugin['post_types'] ?? array()
										);
										echo esc_html( implode( ', ', array_filter( $types ) ) );
										?>
									</span>
								</div>
							<?php endforeach; ?>
						</div>

						<p class="wptsall-init-actions">
							<button type="button" id="wptsall-v4-scan-btn" class="button button-primary">
								<?php esc_html_e( 'Start Deep Scan', 'wpmmcc-ats' ); ?>
							</button>
						</p>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $pending_consent ) ) : ?>
					<!-- Plugins Requiring Authorization -->
					<div class="wptsall-consent-section">
						<h2><?php esc_html_e( 'Plugin Scan Authorization', 'wpmmcc-ats' ); ?></h2>
						<p><?php esc_html_e( 'The following content plugins require your authorization for deep field scanning:', 'wpmmcc-ats' ); ?></p>

						<div class="wptsall-consent-list">
							<?php foreach ( $pending_consent as $plugin ) : ?>
								<label class="wptsall-consent-item">
									<input type="checkbox" name="consent_plugins[]" value="<?php echo esc_attr( $plugin['plugin_slug'] ); ?>" checked>
									<span class="plugin-name"><?php echo esc_html( $plugin['plugin_name'] ); ?></span>
								</label>
							<?php endforeach; ?>
						</div>

						<p class="wptsall-consent-note">
							<?php esc_html_e( 'Deep scanning will analyze plugin field structures, URL patterns, and data types to provide more accurate sync configurations.', 'wpmmcc-ats' ); ?>
						</p>

						<p class="wptsall-init-actions">
							<button type="button" id="wptsall-grant-consent-btn" class="button button-primary">
								<?php esc_html_e( 'Authorize and Scan', 'wpmmcc-ats' ); ?>
							</button>
							<button type="button" id="wptsall-skip-consent-btn" class="button">
								<?php esc_html_e( 'Maybe Later', 'wpmmcc-ats' ); ?>
							</button>
						</p>
					</div>
				<?php endif; ?>

				<p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wptsall' ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'Enter Plugin', 'wpmmcc-ats' ); ?>
					</a>
					<button type="button" id="wptsall-rescan-btn" class="button">
						<?php esc_html_e( 'Rescan Plugins', 'wpmmcc-ats' ); ?>
					</button>
				</p>
			<?php else : ?>
				<div class="wptsall-init-container">
					<div class="wptsall-init-intro">
						<p><?php esc_html_e( 'First-time setup requires selecting plugins to scan. All active plugins are selected by default.', 'wpmmcc-ats' ); ?></p>
					</div>

					<div class="wptsall-init-plugins">
						<h2><?php esc_html_e( 'Select Plugins to Scan', 'wpmmcc-ats' ); ?></h2>
						<p><?php esc_html_e( 'The following active plugins were detected. Please select the plugins to scan:', 'wpmmcc-ats' ); ?></p>

						<?php if ( empty( $available_plugins ) ) : ?>
							<div class="notice notice-warning">
								<p><?php esc_html_e( 'No scannable active plugins detected.', 'wpmmcc-ats' ); ?></p>
							</div>
						<?php else : ?>
							<div class="wptsall-init-plugin-list">
								<?php foreach ( $available_plugins as $plugin ) : ?>
									<label class="wptsall-init-plugin">
										<input type="checkbox" name="wptsall_init_plugins[]" value="<?php echo esc_attr( $plugin['slug'] ); ?>" checked>
										<span class="plugin-name"><?php echo esc_html( $plugin['name'] ); ?></span>
									</label>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>

						<div class="wptsall-init-plugin-actions">
							<button type="button" id="wptsall-select-all-btn" class="button">
								<?php esc_html_e( 'Select All', 'wpmmcc-ats' ); ?>
							</button>
							<button type="button" id="wptsall-deselect-all-btn" class="button">
								<?php esc_html_e( 'Deselect All', 'wpmmcc-ats' ); ?>
							</button>
						</div>
					</div>

					<div id="wptsall-init-progress" style="display: none;">
						<div class="wptsall-progress-bar">
							<div class="wptsall-progress-fill"></div>
						</div>
						<p class="wptsall-progress-text"><?php esc_html_e( 'Scanning...', 'wpmmcc-ats' ); ?></p>
					</div>

					<div id="wptsall-init-result" style="display: none;">
						<div class="notice notice-success">
							<p><?php esc_html_e( 'Scan complete!', 'wpmmcc-ats' ); ?></p>
						</div>
						<div class="wptsall-init-stats"></div>
					</div>

					<p class="wptsall-init-actions">
						<button type="button" id="wptsall-start-init-btn" class="button button-primary button-hero" <?php echo empty( $available_plugins ) ? 'disabled' : ''; ?>>
							<?php esc_html_e( 'Start Scan', 'wpmmcc-ats' ); ?>
						</button>
						<button type="button" id="wptsall-skip-init-btn" class="button">
							<?php esc_html_e( 'Skip Initialization', 'wpmmcc-ats' ); ?>
						</button>
					</p>
				</div>
			<?php endif; ?>
		</div>
		<?php Admin_Page_Helper::render_footer(); ?>
		<?php
	}

	// Note: AJAX handlers removed in v0.5.0
	// Functionality migrated to REST API endpoints:
	// - POST /wptsall/v2/initialize
	// - POST /wptsall/v2/plugins/scan-all
}

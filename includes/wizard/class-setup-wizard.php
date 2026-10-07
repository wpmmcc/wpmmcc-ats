<?php
/**
 * WPTSALL Setup Wizard
 *
 * 5-step onboarding wizard that runs once for new installations.
 * Mirrors the Polylang / TranslatePress wizard pattern.
 *
 * Steps:
 *   1. Welcome + sanity check
 *   2. Default language (pick from existing or add new)
 *   3. Target languages (multi-select)
 *   4. Models to enable (multi-select from registered content plugins)
 *   5. Done + summary
 *
 * State is stored in option `wptsall_wizard_state` and the wizard completes
 * when the user reaches step 5 (or skips).
 *
 * @package WPTSALL
 * @since 1.2.0
 */

namespace WPTSALL\Wizard;

use WPTSALL\Languages\Services\Language_Service;
use WPTSALL\Settings\Services\Settings_Service;
use WPTSALL\Admin\Admin_Page_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables; values prepared via $wpdb->prepare().

class Setup_Wizard {

	const PAGE_SLUG = 'wptsall-wizard';
	const OPTION    = 'wptsall_wizard_state';
	const CAP       = 'manage_wptsall_settings';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ) );
		add_action( 'admin_post_wptsall_wizard_step', array( __CLASS__, 'handle_step' ) );
		add_action( 'admin_post_wptsall_wizard_skip', array( __CLASS__, 'handle_skip' ) );
		add_action( 'wp_ajax_wptsall_wizard_scan_models', array( __CLASS__, 'ajax_scan_models' ) );
		// Show a dashboard widget nudge if wizard not completed.
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'maybe_add_dashboard_widget' ) );
	}

	public static function add_menu_page() {
		// Hidden page — entry is from Settings or from the dashboard widget.
		add_submenu_page(
			null,
			__( 'WPTSALL Setup Wizard', 'wpmmcc-ats' ),
			__( 'Setup Wizard', 'wpmmcc-ats' ),
			self::CAP,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
		// Visible entry: "Run Setup Wizard" link under Settings.
		add_submenu_page(
			'wpmmcc-ats',
			__( 'Setup Wizard', 'wpmmcc-ats' ),
			__( 'Setup Wizard', 'wpmmcc-ats' ),
			self::CAP,
			self::PAGE_SLUG . '-redirect',
			array( __CLASS__, 'redirect_to_wizard' )
		);
	}

	public static function redirect_to_wizard() {
		wp_safe_redirect( add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Returns true if the user has reached the wizard Done step.
	 */
	public static function is_completed() {
		$state = get_option( self::OPTION, array() );
		return ! empty( $state['completed'] );
	}

	private static function get_state() {
		$state = get_option( self::OPTION, array() );
		if ( ! is_array( $state ) ) {
			$state = array();
		}
		return $state + array( 'step' => 1, 'completed' => false );
	}

	private static function set_state( $state ) {
		update_option( self::OPTION, $state );
	}

	/**
	 * Execute model scan for Setup Wizard.
	 *
	 * @return array
	 */
	public static function execute_model_scan(): array {
		$scanner_file = defined( 'WPTSALL_PATH' ) ? WPTSALL_PATH . 'includes/models/scanners/class-model-scanner-v2.php' : '';
		$tracker_file = defined( 'WPTSALL_PATH' ) ? WPTSALL_PATH . 'includes/models/scanners/class-runtime-tracker.php' : '';
		if ( $scanner_file && file_exists( $scanner_file ) && $tracker_file && file_exists( $tracker_file ) ) {
			require_once $scanner_file;
			require_once $tracker_file;
			if ( class_exists( '\\WPTSALL\\Models\\Scanners\\Runtime_Tracker' ) ) {
				\WPTSALL\Models\Scanners\Runtime_Tracker::init();
			}
			if ( class_exists( '\\WPTSALL\\Models\\Scanners\\Model_Scanner_V2' ) ) {
				$scanner = new \WPTSALL\Models\Scanners\Model_Scanner_V2();
				$scanner->set_mode( 'incremental' );
				$scanner->scan_all_plugins();
			}
		}

		global $wpdb;
		$results = wptsall_db_get_results(
			'SELECT id, plugin_slug, plugin_name, status FROM %i ORDER BY id ASC',
			array( $wpdb->prefix . 'wptsall_models' ),
			ARRAY_A
		);
		return is_array( $results ) ? $results : array();
	}

	/**
	 * AJAX handler for scanning content models within Setup Wizard.
	 *
	 * @return void
	 */
	public static function ajax_scan_models(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'wpmmcc-ats' ) ), 403 );
		}
		check_ajax_referer( 'wptsall_wizard_scan', 'nonce' );

		$models = self::execute_model_scan();
		wp_send_json_success( array( 'models' => $models ) );
	}

	public static function maybe_add_dashboard_widget() {
		if ( self::is_completed() || ! current_user_can( self::CAP ) ) {
			return;
		}
		wp_add_dashboard_widget(
			'wptsall-wizard-nudge',
			__( 'WPTSALL Setup', 'wpmmcc-ats' ),
			array( __CLASS__, 'render_dashboard_widget' )
		);
	}

	public static function render_dashboard_widget() {
		$url = add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) );
		echo '<p>' . esc_html__( 'Run the 5-step setup wizard to configure languages and select content models.', 'wpmmcc-ats' ) . '</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Start Setup Wizard', 'wpmmcc-ats' ) . '</a></p>';
	}

	public static function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$state = self::get_state();
		$step  = isset( $_GET['step'] ) ? max( 1, (int) $_GET['step'] ) : (int) $state['step']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$state['step'] = $step;
		self::set_state( $state );

		$base_url = add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) );
		?>
		<div class="wrap wptsall-wizard">
			<h1><?php esc_html_e( 'WPTSALL Setup Wizard', 'wpmmcc-ats' ); ?></h1>
			<ol class="wptsall-wizard-steps">
				<?php foreach ( array( 1 => __( 'Welcome', 'wpmmcc-ats' ), 2 => __( 'Default Language', 'wpmmcc-ats' ), 3 => __( 'Target Languages', 'wpmmcc-ats' ), 4 => __( 'Models', 'wpmmcc-ats' ), 5 => __( 'Done', 'wpmmcc-ats' ) ) as $i => $label ) : ?>
					<li class="<?php echo $i === $step ? 'active' : ( $i < $step ? 'done' : '' ); ?>"><?php echo esc_html( $i . '. ' . $label ); ?></li>
				<?php endforeach; ?>
			</ol>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wptsall_wizard_step' ); ?>
				<input type="hidden" name="action" value="wptsall_wizard_step">
				<input type="hidden" name="step" value="<?php echo (int) $step; ?>">
				<?php self::render_step( $step ); ?>
				<p>
					<?php if ( $step > 1 ) : ?>
						<a class="button" href="<?php echo esc_url( add_query_arg( 'step', $step - 1, $base_url ) ); ?>">&larr; <?php esc_html_e( 'Back', 'wpmmcc-ats' ); ?></a>
					<?php endif; ?>
					<button class="button button-primary" type="submit">
						<?php echo 5 === $step ? esc_html__( 'Finish', 'wpmmcc-ats' ) : esc_html__( 'Next →', 'wpmmcc-ats' ); ?>
					</button>
				</p>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-top:8px;">
				<?php wp_nonce_field( 'wptsall_wizard_skip' ); ?>
				<input type="hidden" name="action" value="wptsall_wizard_skip">
				<button class="button-link" type="submit"><?php esc_html_e( 'Skip wizard', 'wpmmcc-ats' ); ?></button>
			</form>
		</div>
		<?php
	}

	private static function render_step( $step ) {
		$languages = Language_Service::get_all( array( 'status' => 'all' ) );
		$settings = Settings_Service::get_all();
		switch ( $step ) {
			case 1:
				echo '<h2>' . esc_html__( 'Welcome to WPTSALL', 'wpmmcc-ats' ) . '</h2>';
				echo '<p>' . esc_html__( 'This wizard configures the basics: default language, target languages, and which content models to translate.', 'wpmmcc-ats' ) . '</p>';
				echo '<p><strong>' . esc_html__( 'Detected languages', 'wpmmcc-ats' ) . ':</strong> ' . esc_html( (string) count( $languages ) ) . '</p>';
				echo '<p><strong>' . esc_html__( 'wp_wptsall_languages table', 'wpmmcc-ats' ) . ':</strong> ' . esc_html( self::table_exists_marker() ) . '</p>';
				break;
			case 2:
				echo '<h2>' . esc_html__( 'Default language', 'wpmmcc-ats' ) . '</h2>';
				echo '<p>' . esc_html__( 'This becomes the x-default hreflang target and the untranslated content fallback.', 'wpmmcc-ats' ) . '</p>';
				echo '<select name="default_language">';
				foreach ( $languages as $l ) {
					printf( '<option value="%s" %s>%s — %s</option>', esc_attr( $l['code'] ), selected( $settings['default_language'], $l['code'], false ), esc_html( $l['code'] ), esc_html( $l['name'] ) );
				}
				echo '</select>';
				break;
			case 3:
				echo '<h2>' . esc_html__( 'Target languages', 'wpmmcc-ats' ) . '</h2>';
				echo '<p>' . esc_html__( 'Pick the languages visitors should be able to switch to (excluding the default).', 'wpmmcc-ats' ) . '</p>';
				echo '<div style="column-count:2;max-width:520px;">';
				// 3.8flash 4.5-①: single source of truth — the REAL language
				// records (status), not a wizard-private option. The private
				// option made two truths: Languages-page edits never showed up
				// here, and the wizard's picks never landed in the real store.
				// The default language is checked + disabled: it is the
				// fallback base, untargeting it is meaningless.
				foreach ( $languages as $l ) {
					$is_default = $l['code'] === $settings['default_language'];
					$checked    = $is_default || 'active' === $l['status'];
					printf(
						'<label style="display:block;"><input type="checkbox" name="target_languages[]" value="%s"%s%s> %s — %s%s</label>',
						esc_attr( $l['code'] ),
						$checked ? ' checked' : '',
						$is_default ? ' disabled' : '',
						esc_html( $l['code'] ),
						esc_html( $l['name'] ),
						$is_default ? ' (' . esc_html__( 'default', 'wpmmcc-ats' ) . ')' : ''
					);
				}
				echo '</div>';
				break;
			case 4:
				echo '<h2>' . esc_html__( 'Models to enable', 'wpmmcc-ats' ) . '</h2>';
				echo '<p>' . esc_html__( 'Select the content plugins you want to translate. You can change this later on the Models page.', 'wpmmcc-ats' ) . '</p>';
				global $wpdb;
				$models = wptsall_db_get_results(
					'SELECT id, plugin_slug, plugin_name, status FROM %i',
					array( $wpdb->prefix . 'wptsall_models' ),
					ARRAY_A
				);
				$scan_nonce = wp_create_nonce( 'wptsall_wizard_scan' );
				?>
				<div id="wptsall-wizard-models-container">
					<?php if ( empty( $models ) ) : ?>
						<div class="notice notice-info inline" style="margin: 10px 0 15px 0;">
							<p><?php esc_html_e( 'No content models detected yet. Click the button below to scan installed plugins for translatable content.', 'wpmmcc-ats' ); ?></p>
						</div>
					<?php else : ?>
						<div id="wptsall-models-list" style="column-count:2;max-width:520px;margin-bottom:15px;">
							<?php foreach ( $models as $m ) : ?>
								<label style="display:block;">
									<input type="checkbox" name="models[]" value="<?php echo (int) $m['id']; ?>" <?php checked( $m['status'] === 'active', true ); ?>>
									<?php echo esc_html( $m['plugin_name'] ); ?> <small>(<?php echo esc_html( $m['plugin_slug'] ); ?>, <?php echo esc_html( $m['status'] ); ?>)</small>
								</label>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>

				<div style="margin: 15px 0;">
					<button type="button" id="wptsall-wizard-ajax-scan-btn" class="button button-secondary">
						<span class="dashicons dashicons-search" style="vertical-align:middle;"></span>
						<?php esc_html_e( 'Scan Installed Plugins Now', 'wpmmcc-ats' ); ?>
					</button>
					<button type="submit" name="wizard_scan_now" value="1" class="button button-secondary" style="margin-left: 5px;">
						<?php esc_html_e( 'Scan via Refresh', 'wpmmcc-ats' ); ?>
					</button>
					<span id="wptsall-wizard-scan-spinner" class="spinner" style="float:none;vertical-align:middle;margin-left:5px;"></span>
					<span id="wptsall-wizard-scan-status" style="margin-left:8px;font-size:12px;color:#555;"></span>
				</div>

				<script>
				document.addEventListener('DOMContentLoaded', function() {
					var btn = document.getElementById('wptsall-wizard-ajax-scan-btn');
					var spinner = document.getElementById('wptsall-wizard-scan-spinner');
					var statusSpan = document.getElementById('wptsall-wizard-scan-status');
					var container = document.getElementById('wptsall-wizard-models-container');
					if (!btn) return;
					btn.addEventListener('click', function() {
						btn.disabled = true;
						spinner.classList.add('is-active');
						statusSpan.textContent = '<?php echo esc_js( __( 'Scanning plugins...', 'wpmmcc-ats' ) ); ?>';
						fetch(ajaxurl, {
							method: 'POST',
							headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
							body: 'action=wptsall_wizard_scan_models&nonce=<?php echo esc_js( $scan_nonce ); ?>'
						})
						.then(function(res) { return res.json(); })
						.then(function(data) {
							btn.disabled = false;
							spinner.classList.remove('is-active');
							if (data.success && data.data && data.data.models && data.data.models.length > 0) {
								var models = data.data.models;
								statusSpan.textContent = models.length + ' <?php echo esc_js( __( 'models found.', 'wpmmcc-ats' ) ); ?>';
								var html = '<div id="wptsall-models-list" style="column-count:2;max-width:520px;margin-bottom:15px;">';
								models.forEach(function(m) {
									var checked = m.status === 'active' ? 'checked' : '';
									html += '<label style="display:block;"><input type="checkbox" name="models[]" value="' + m.id + '" ' + checked + '> ' + m.plugin_name + ' <small>(' + m.plugin_slug + ', ' + m.status + ')</small></label>';
								});
								html += '</div>';
								container.innerHTML = html;
							} else {
								statusSpan.textContent = '<?php echo esc_js( __( 'Scan completed or no plugins found.', 'wpmmcc-ats' ) ); ?>';
							}
						})
						.catch(function(err) {
							btn.disabled = false;
							spinner.classList.remove('is-active');
							statusSpan.textContent = '<?php echo esc_js( __( 'Scan failed, try Scan via Refresh.', 'wpmmcc-ats' ) ); ?>';
						});
					});
				});
				</script>
				<?php
				break;
			case 5:
				echo '<h2>' . esc_html__( 'Done', 'wpmmcc-ats' ) . '</h2>';
				echo '<p>' . esc_html__( 'Setup complete. The plugin is ready. You can revisit this wizard from the WPTSALL menu anytime.', 'wpmmcc-ats' ) . '</p>';
				echo '<ul>';
				/* translators: %s: <value> */
				echo '<li>' . esc_html( sprintf( __( 'Default language: %s', 'wpmmcc-ats' ), $settings['default_language'] ) ) . '</li>';
				/* translators: comma-separated list of target language codes */
				// 3.8flash 4.5-①: read the REAL store (active language records),
				// same single source of truth the step-3 form writes.
				$active_codes = array();
				foreach ( Language_Service::get_all( array( 'status' => 'active' ) ) as $row ) {
					$active_codes[] = (string) $row['code'];
				}
				/* translators: %s: <value> */
				echo '<li>' . esc_html( sprintf( __( 'Target languages: %s', 'wpmmcc-ats' ), implode( ', ', $active_codes ) ) ) . '</li>';
				echo '</ul>';

				if ( function_exists( 'wptsall_create_site_connection_pack' ) ) {
					$pack      = wptsall_create_site_connection_pack( '', __( 'Standalone Client', 'wpmmcc-ats' ) );
					$pack_json = wp_json_encode( $pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
					?>
					<div class="card" style="max-width: 600px; margin-top: 20px; padding: 15px; border: 1px solid #ccd0d4; background: #fff;">
						<h3><?php esc_html_e( 'Connect Standalone Translation Client', 'wpmmcc-ats' ); ?></h3>
						<p class="description">
							<?php esc_html_e( 'Import this Connection Pack into your standalone client (WebUI or Desktop) on the Sites page to connect immediately.', 'wpmmcc-ats' ); ?>
						</p>
						<textarea id="wptsall-wizard-conn-pack" readonly rows="7" style="width: 100%; font-family: monospace; font-size: 11px;"><?php echo esc_textarea( $pack_json ); ?></textarea>
						<div style="margin-top: 10px;">
							<button type="button" class="button button-secondary" onclick="navigator.clipboard.writeText(document.getElementById('wptsall-wizard-conn-pack').value); alert('Connection Pack copied to clipboard!');">
								<?php esc_html_e( 'Copy Connection Pack JSON', 'wpmmcc-ats' ); ?>
							</button>
						</div>
					</div>
					<?php
				}
				break;
		}
	}

	public static function handle_step() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Forbidden', 'wpmmcc-ats' ) );
		}
		check_admin_referer( 'wptsall_wizard_step' );
		$step = (int) ( isset( $_POST['step'] ) ? sanitize_text_field( wp_unslash( $_POST['step'] ) ) : 1 );

		// Handle manual refresh scan on Step 4
		if ( 4 === $step && ! empty( $_POST['wizard_scan_now'] ) ) {
			self::execute_model_scan();
			$base = add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) );
			wp_safe_redirect( add_query_arg( 'step', 4, $base ) );
			exit;
		}

		// Persist step-specific data.
		if ( 2 === $step && ! empty( $_POST['default_language'] ) ) {
			$picked = sanitize_text_field( wp_unslash( $_POST['default_language'] ) );
			Settings_Service::update( array( 'default_language' => $picked ) );
			// 3.8flash 4.5-① family: the wizard wrote ONLY the settings store,
			// so the languages records' is_default marker (the Languages
			// page's canonical default) went stale — two stores, one write.
			// Sync the record marker from the wizard's pick.
			foreach ( Language_Service::get_all( array( 'status' => 'all' ) ) as $row ) {
				if ( (string) $row['code'] === $picked ) {
					Language_Service::set_default( (int) $row['id'] );
					break;
				}
			}
		}
		if ( 3 === $step ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- map_deep sanitizes each value.
			$raw_tg = isset( $_POST['target_languages'] ) ? wp_unslash( $_POST['target_languages'] ) : array();
			$tg     = is_array( $raw_tg )
				? array_values( array_filter( map_deep( $raw_tg, 'sanitize_text_field' ) ) )
				: array();
			// 3.8flash 4.5-①: write the REAL store (the Languages records'
			// status), not a wizard-private option — the form carries the
			// full desired target set (checkboxes pre-populated from the
			// current records), so an unchecked box is an explicit untarget.
			// The default language stays active regardless (disabled input).
			$default_language = (string) ( Settings_Service::get_all()['default_language'] ?? '' );
			foreach ( Language_Service::get_all( array( 'status' => 'all' ) ) as $row ) {
				$is_target = in_array( (string) $row['code'], $tg, true ) || $row['code'] === $default_language;
				$status    = $is_target ? 'active' : 'inactive';
				if ( $status !== (string) $row['status'] ) {
					Language_Service::set_status( (int) $row['id'], $status );
				}
			}
		}
		if ( 4 === $step ) {
			// 3.8flash 4.5-②: the form is the full desired state (checkboxes
			// pre-populated from the current records), so unchecked is an
			// explicit disable — the previous enable-only branch made
			// deactivation unreachable from the wizard (asymmetric with the
			// Models page). An untouched submit is identity by construction.
			$checked = isset( $_POST['models'] )
				? array_map( 'intval', (array) wp_unslash( $_POST['models'] ) )
				: array();
			global $wpdb;
			$all_ids = wptsall_db_get_col( 'SELECT id FROM %i', array( $wpdb->prefix . 'wptsall_models' ) );
			$all_ids = is_array( $all_ids ) ? array_map( 'intval', $all_ids ) : array();
			if ( ! empty( $all_ids ) ) {
				$table = $wpdb->prefix . 'wptsall_models';
				if ( ! empty( $checked ) ) {
					list( $in_sql, $in_args ) = wptsall_db_prepare_int_in( $checked );
					wptsall_db_query(
						"UPDATE %i SET status = %s WHERE id IN ($in_sql)",
						array_merge( array( $table, 'active' ), $in_args )
					);
				}
				$to_deactivate = array_diff( array_map( 'intval', $all_ids ), $checked );
				if ( ! empty( $to_deactivate ) ) {
					list( $off_sql, $off_args ) = wptsall_db_prepare_int_in( $to_deactivate );
					wptsall_db_query(
						"UPDATE %i SET status = %s WHERE id IN ($off_sql)",
						array_merge( array( $table, 'inactive' ), $off_args )
					);
				}
			}
		}
		$next = $step + 1;
		$state = self::get_state();
		$state['step'] = $next;
		if ( 5 === $step ) {
			$state['completed'] = true;
		}
		self::set_state( $state );
		$base = add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) );
		wp_safe_redirect( add_query_arg( 'step', $next, $base ) );
		exit;
	}

	public static function handle_skip() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Forbidden', 'wpmmcc-ats' ) );
		}
		check_admin_referer( 'wptsall_wizard_skip' );
		$state = self::get_state();
		$state['completed'] = true;
		self::set_state( $state );
		wp_safe_redirect( admin_url( 'admin.php?page=wpmmcc-ats' ) );
		exit;
	}

	private static function table_exists_marker() {
		global $wpdb;
		$e = wptsall_db_get_var(
			'SHOW TABLES LIKE %s',
			array( $wpdb->prefix . 'wptsall_languages' )
		);
		return $e ? '✓ ready' : '✗ missing';
	}
}

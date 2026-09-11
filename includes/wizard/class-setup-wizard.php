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
				$current_targets = get_option( 'wptsall_wizard_target_languages', array() );
				if ( ! is_array( $current_targets ) ) { $current_targets = array(); }
				foreach ( $languages as $l ) {
					$checked = in_array( $l['code'], $current_targets, true ) || $l['code'] === $settings['default_language'] ? 'checked' : '';
					printf(
						'<label style="display:block;"><input type="checkbox" name="target_languages[]" value="%s" %s> %s — %s</label>',
						esc_attr( $l['code'] ),
						esc_attr( $checked ),
						esc_html( $l['code'] ),
						esc_html( $l['name'] )
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
				if ( empty( $models ) ) {
					echo '<p>' . esc_html__( 'No models yet. Run a plugin scan on the Models page first.', 'wpmmcc-ats' ) . '</p>';
				} else {
					echo '<div style="column-count:2;max-width:520px;">';
					foreach ( $models as $m ) {
						printf(
							'<label style="display:block;"><input type="checkbox" name="models[]" value="%d" %s> %s <small>(%s, %s)</small></label>',
							(int) $m['id'],
							checked( $m['status'] === 'active', true, false ),
							esc_html( $m['plugin_name'] ),
							esc_html( $m['plugin_slug'] ),
							esc_html( $m['status'] )
						);
					}
					echo '</div>';
				}
				break;
			case 5:
				echo '<h2>' . esc_html__( 'Done', 'wpmmcc-ats' ) . '</h2>';
				echo '<p>' . esc_html__( 'Setup complete. The plugin is ready. You can revisit this wizard from the WPTSALL menu anytime.', 'wpmmcc-ats' ) . '</p>';
				echo '<ul>';
				/* translators: %s: <value> */
				echo '<li>' . esc_html( sprintf( __( 'Default language: %s', 'wpmmcc-ats' ), $settings['default_language'] ) ) . '</li>';
				/* translators: comma-separated list of target language codes */
				$tg = get_option( 'wptsall_wizard_target_languages', array() );
				/* translators: %s: <value> */
				echo '<li>' . esc_html( sprintf( __( 'Target languages: %s', 'wpmmcc-ats' ), implode( ', ', (array) $tg ) ) ) . '</li>';
				echo '</ul>';
				break;
		}
	}

	public static function handle_step() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Forbidden', 'wpmmcc-ats' ) );
		}
		check_admin_referer( 'wptsall_wizard_step' );
		$step = (int) ( isset( $_POST['step'] ) ? sanitize_text_field( wp_unslash( $_POST['step'] ) ) : 1 );
		// Persist step-specific data.
		if ( 2 === $step && ! empty( $_POST['default_language'] ) ) {
			Settings_Service::update( array( 'default_language' => sanitize_text_field( wp_unslash( $_POST['default_language'] ) ) ) );
		}
		if ( 3 === $step ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- map_deep sanitizes each value.
			$raw_tg = isset( $_POST['target_languages'] ) ? wp_unslash( $_POST['target_languages'] ) : array();
			$tg     = is_array( $raw_tg )
				? array_values( array_filter( map_deep( $raw_tg, 'sanitize_text_field' ) ) )
				: array();
			update_option( 'wptsall_wizard_target_languages', $tg );
		}
		if ( 4 === $step && ! empty( $_POST['models'] ) ) {
			$ids = array_map( 'intval', (array) wp_unslash( $_POST['models'] ) );
			if ( ! empty( $ids ) ) {
				global $wpdb;
				$table = $wpdb->prefix . 'wptsall_models';
				list( $in_sql, $in_args ) = wptsall_db_prepare_int_in( $ids );
				wptsall_db_query(
					"UPDATE %i SET status = %s WHERE id IN ($in_sql)",
					array_merge( array( $table, 'active' ), $in_args )
				);
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

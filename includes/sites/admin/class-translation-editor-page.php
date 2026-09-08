<?php
/**
 * WPTSALL Translation Editor Page
 *
 * Manual translation editor with two-panel layout:
 * - Left panel: source content (read-only)
 * - Right panel: target content (editable)
 *
 * @package WPTSALL
 * @since 1.0.0
 */

namespace WPTSALL\Sites\Admin;

use WPTSALL\Admin\Admin_Page_Helper;
use WPTSALL\Sites\Services\Manual_Content_Service;
use WPTSALL\Sites\Services\Relation_Resolver;
use WPTSALL\Sites\Services\Site_Relation_Service;
use WPTSALL\Sites\Services\Virtual_Permalink;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translation Editor Page Class
 *
 * Renders the manual translation editor UI.
 * Accessed via: admin.php?page=wptsall-translate&source_post_id=X&relation_id=Y
 *
 * @since 1.0.0
 */
class Translation_Editor_Page {

	/**
	 * Page slug
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'wptsall-translate';

	/**
	 * Initialize
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue scripts and styles
	 *
	 * Only loads assets on the translation editor page.
	 *
	 * @since 1.0.0
	 * @param string $hook Page hook suffix.
	 */
	public static function enqueue_scripts( $hook ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';

		if ( self::PAGE_SLUG !== $page ) {
			return;
		}

		wp_enqueue_style(
			'wptsall-translation-editor',
			WPTSALL_URL . 'assets/css/translation-editor.css',
			array(),
			WPTSALL_VERSION
		);

		// Enqueue WordPress media library scripts for media field editing.
		wp_enqueue_media();

		// Enqueue TinyMCE editor support for rich text editing.
		wp_enqueue_editor();

		wp_enqueue_script(
			'wptsall-translation-editor',
			WPTSALL_URL . 'assets/js/translation-editor.js',
			array( 'jquery', 'wp-api-fetch', 'editor' ),
			WPTSALL_VERSION,
			true
		);

		$resolved       = self::resolve_editor_request_params();
		$source_post_id = (int) $resolved['source_post_id'];
		$relation_id    = (int) $resolved['relation_id'];

		// Get taxonomy data for the editor.
		$taxonomy_data = self::get_taxonomy_data_for_editor( $source_post_id, $relation_id );

		wp_localize_script( 'wptsall-translation-editor', 'wptsallTranslationEditor', array(
			'restUrl'       => rest_url( 'wptsall/v2' ),
			'restNonce'     => wp_create_nonce( 'wp_rest' ),
			'sourcePostId'  => $source_post_id,
			'relationId'    => $relation_id,
			'taxonomyData'  => $taxonomy_data,
			'strings'       => array(
				'saving'              => __( 'Saving...', 'wpmmcc-ats' ),
				'saved'               => __( 'Saved successfully', 'wpmmcc-ats' ),
				'saveFailed'          => __( 'Save failed', 'wpmmcc-ats' ),
				'loading'             => __( 'Loading...', 'wpmmcc-ats' ),
				'loadFailed'          => __( 'Load failed', 'wpmmcc-ats' ),
				/* translators: Shown when auto-translate has no handler (optional client). */
				'autoTranslateHint'   => __( 'Auto-translate is available via the optional translation client or a connected external translation service.', 'wpmmcc-ats' ),
				'translate'           => __( 'Translate', 'wpmmcc-ats' ),
				'sync'                => __( 'Sync', 'wpmmcc-ats' ),
				'compute'             => __( 'Compute', 'wpmmcc-ats' ),
				'idMapping'           => __( 'ID Mapping', 'wpmmcc-ats' ),
				'backToList'          => __( 'Back to List', 'wpmmcc-ats' ),
				'nonTextNotice'       => __( 'Non-text fields do not support manual translation yet', 'wpmmcc-ats' ),
				'selectImage'         => __( 'Select Image', 'wpmmcc-ats' ),
				'changeImage'         => __( 'Change Image', 'wpmmcc-ats' ),
				'removeImage'         => __( 'Remove', 'wpmmcc-ats' ),
				'noImage'             => __( 'No image selected', 'wpmmcc-ats' ),
				'featuredImage'       => __( 'Featured Image', 'wpmmcc-ats' ),
				'mediaFieldTitle'     => __( 'Select Media', 'wpmmcc-ats' ),
				'mediaFieldButton'    => __( 'Use this media', 'wpmmcc-ats' ),
				'translationMode'     => __( 'Translation Mode', 'wpmmcc-ats' ),
				'editMode'            => __( 'Edit Mode', 'wpmmcc-ats' ),
				'editModeDesc'        => __( 'All fields editable', 'wpmmcc-ats' ),
				'translationModeDesc' => __( 'Only translate fields editable', 'wpmmcc-ats' ),
				'taxonomies'          => __( 'Taxonomies', 'wpmmcc-ats' ),
				'addTag'              => __( 'Add', 'wpmmcc-ats' ),
				'edit'                => __( 'Edit', 'wpmmcc-ats' ),
			),
		) );
	}

	/**
	 * Render the translation editor page
	 *
	 * @since 1.0.0
	 */
	public static function render_page() {
		if ( ! wptsall_user_can_manage_translations() ) {
			return;
		}

		$resolved = self::resolve_editor_request_params();
		$source_post_id = (int) $resolved['source_post_id'];
		$relation_id    = (int) $resolved['relation_id'];

		// Canonicalize post_id/to_lang shortcuts to source_post_id/relation_id.
		if ( ! empty( $resolved['should_redirect'] ) && $source_post_id > 0 && $relation_id > 0 ) {
			$canonical = add_query_arg(
				array(
					'page'           => self::PAGE_SLUG,
					'source_post_id' => $source_post_id,
					'relation_id'    => $relation_id,
				),
				admin_url( 'admin.php' )
			);
			wp_safe_redirect( $canonical );
			exit;
		}

		// Validate parameters.
		if ( ! $source_post_id || ! $relation_id ) {
			Admin_Page_Helper::render_header( __( 'Translation Editor', 'wpmmcc-ats' ), 'translate' );
			$hint = ! empty( $resolved['error'] )
				? (string) $resolved['error']
				: __( 'Missing required parameters: source_post_id and relation_id', 'wpmmcc-ats' );
			Admin_Page_Helper::render_notice( $hint, 'error' );
			Admin_Page_Helper::render_footer();
			return;
		}

		// Validate post exists.
		$source_post = get_post( $source_post_id );
		if ( ! $source_post ) {
			Admin_Page_Helper::render_header( __( 'Translation Editor', 'wpmmcc-ats' ), 'translate' );
			Admin_Page_Helper::render_notice(
				sprintf(
					/* translators: %d: Post ID */
					__( 'Source post not found (ID: %d)', 'wpmmcc-ats' ),
					$source_post_id
				),
				'error'
			);
			Admin_Page_Helper::render_footer();
			return;
		}

		// Validate relation exists.
		$relation = Site_Relation_Service::get_relation( $relation_id );
		if ( ! $relation ) {
			Admin_Page_Helper::render_header( __( 'Translation Editor', 'wpmmcc-ats' ), 'translate' );
			Admin_Page_Helper::render_notice(
				sprintf(
					/* translators: %d: Relation ID */
					__( 'Site relation not found (ID: %d)', 'wpmmcc-ats' ),
					$relation_id
				),
				'error'
			);
			Admin_Page_Helper::render_footer();
			return;
		}

		// Get editor data from service.
		$editor_data = Manual_Content_Service::get_editor_data( $source_post_id, $relation_id );

		if ( is_wp_error( $editor_data ) ) {
			Admin_Page_Helper::render_header( __( 'Translation Editor', 'wpmmcc-ats' ), 'translate' );
			Admin_Page_Helper::render_notice( $editor_data->get_error_message(), 'error' );
			Admin_Page_Helper::render_footer();
			return;
		}

		// Auto-create translation skeleton if no existing target found.
		if ( empty( $editor_data['target'] ) ) {
			$skeleton_result = Manual_Content_Service::create_translation_skeleton( $source_post_id, $relation_id );

			if ( is_wp_error( $skeleton_result ) ) {
				Admin_Page_Helper::render_header( __( 'Translation Editor', 'wpmmcc-ats' ), 'translate' );
				Admin_Page_Helper::render_notice( $skeleton_result->get_error_message(), 'error' );
				Admin_Page_Helper::render_footer();
				return;
			}

			// Re-fetch editor data now that skeleton exists.
			$editor_data = Manual_Content_Service::get_editor_data( $source_post_id, $relation_id );

			if ( is_wp_error( $editor_data ) ) {
				Admin_Page_Helper::render_header( __( 'Translation Editor', 'wpmmcc-ats' ), 'translate' );
				Admin_Page_Helper::render_notice( $editor_data->get_error_message(), 'error' );
				Admin_Page_Helper::render_footer();
				return;
			}
		}

		// Build the back button action.
		$back_url = admin_url( 'admin.php?page=wptsall-sites' );
		$actions  = array(
			array(
				'label' => __( 'Back to List', 'wpmmcc-ats' ),
				'url'   => $back_url,
			),
		);

		// Add contextual links based on target type.
		$target_site_type = $relation['target_site_type'] ?? 'wp';
		$existing_target  = isset( $editor_data['target'] ) ? $editor_data['target'] : array();
		$target_id        = ! empty( $existing_target['ID'] ) ? (int) $existing_target['ID'] : 0;

		if ( $target_id > 0 && 'wp' === $target_site_type ) {
			// "Edit in WordPress" link for WP subsite targets.
			$edit_url = self::build_backend_edit_url( $relation_id, $source_post->post_type, $target_id, $relation );
			if ( $edit_url ) {
				$actions[] = array(
					'label' => __( 'Edit in WordPress', 'wpmmcc-ats' ),
					'url'   => $edit_url,
				);
			}
		} elseif ( $target_id > 0 && 'virtual' === $target_site_type ) {
			// "View on Frontend" link for virtual site targets.
			$frontend_url = self::build_virtual_frontend_url( $target_id, $relation );
			if ( $frontend_url ) {
				$actions[] = array(
					'label' => __( 'View on Frontend', 'wpmmcc-ats' ),
					'url'   => $frontend_url,
				);
			}
		}

		Admin_Page_Helper::render_header( __( 'Translation Editor', 'wpmmcc-ats' ), 'translate', $actions );

		// Extract data for rendering.
		$source        = isset( $editor_data['source'] ) ? $editor_data['source'] : array();
		$field_config  = isset( $editor_data['field_config'] ) ? $editor_data['field_config'] : array();
		$existing_target = isset( $editor_data['target'] ) ? $editor_data['target'] : array();
		$target_lang   = isset( $relation['target_lang'] ) ? $relation['target_lang'] : '';
		$target_name   = isset( $relation['target_site_name'] ) ? $relation['target_site_name'] : '';

		// Build relation display string.
		$source_label = get_bloginfo( 'name' );
		$target_label = $target_name ? $target_name : __( 'Target Site', 'wpmmcc-ats' );
		if ( $target_lang ) {
			$target_label .= ' (' . esc_html( $target_lang ) . ')';
		}

		// Build breadcrumb data.
		$post_title_display = $source_post->post_title ? $source_post->post_title : sprintf( '#%d', $source_post_id );
		$target_lang_display = $target_lang ? $target_lang : __( 'Target', 'wpmmcc-ats' );

		// Get taxonomy data for the taxonomy section.
		$taxonomy_section_data = self::get_taxonomy_section_data( $source_post_id, $relation_id, $relation );

		?>
		<div class="wptsall-translation-editor" id="wptsall-translation-editor"
			data-source-post-id="<?php echo esc_attr( $source_post_id ); ?>"
			data-relation-id="<?php echo esc_attr( $relation_id ); ?>">

			<!-- Breadcrumb navigation -->
			<nav class="wptsall-breadcrumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'wpmmcc-ats' ); ?>">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wptsall-sites' ) ); ?>"><?php esc_html_e( 'Sites', 'wpmmcc-ats' ); ?></a>
				<span class="wptsall-breadcrumb-sep">&rsaquo;</span>
				<span class="wptsall-breadcrumb-item"><?php echo esc_html( $post_title_display ); ?></span>
				<span class="wptsall-breadcrumb-sep">&rsaquo;</span>
				<span class="wptsall-breadcrumb-item"><?php echo esc_html( $target_lang_display ); ?></span>
				<span class="wptsall-breadcrumb-sep">&rsaquo;</span>
				<span class="wptsall-breadcrumb-current"><?php esc_html_e( 'Edit', 'wpmmcc-ats' ); ?></span>
			</nav>

			<!-- Info bar with mode toggle -->
			<div class="wptsall-editor-info">
				<span class="wptsall-editor-relation">
					<?php echo esc_html( $source_label ); ?> &rarr; <?php echo esc_html( $target_label ); ?>
				</span>
				<div class="wptsall-editor-info-right">
					<div class="wptsall-mode-toggle" id="wptsall-mode-toggle">
						<button type="button" class="wptsall-mode-btn wptsall-mode-btn-active" id="wptsall-mode-translation" data-mode="translation" title="<?php esc_attr_e( 'Only translate fields editable', 'wpmmcc-ats' ); ?>">
							<?php esc_html_e( 'Translation Mode', 'wpmmcc-ats' ); ?>
						</button>
						<button type="button" class="wptsall-mode-btn" id="wptsall-mode-edit" data-mode="edit" title="<?php esc_attr_e( 'All fields editable', 'wpmmcc-ats' ); ?>">
							<?php esc_html_e( 'Edit Mode', 'wpmmcc-ats' ); ?>
						</button>
					</div>
					<span class="wptsall-editor-status" id="wptsall-editor-status"></span>
				</div>
			</div>

			<!-- Two-panel layout -->
			<div class="wptsall-editor-panels">
				<!-- Source panel (left, read-only) -->
				<div class="wptsall-source-panel">
					<h3><?php esc_html_e( 'Source Content', 'wpmmcc-ats' ); ?></h3>
					<div id="wptsall-source-fields">
						<?php self::render_source_panel( $source, $field_config ); ?>
					</div>
				</div>

				<!-- Target panel (right, editable) -->
				<div class="wptsall-target-panel">
					<h3><?php esc_html_e( 'Target Content', 'wpmmcc-ats' ); ?></h3>
					<div id="wptsall-target-fields">
						<?php self::render_target_panel( $field_config, $existing_target ); ?>
					</div>

					<!-- TinyMCE containers for rich text editing (initialized by JS) -->
					<div id="wptsall-tinymce-content" class="wptsall-tinymce-container" style="display:none;">
						<?php
						// Render a hidden wp_editor instance for post_content.
						// JS will move/show this into the correct field row.
						wp_editor(
							isset( $existing_target['post_content'] ) ? $existing_target['post_content'] : '',
							'wptsall_tinymce_content',
							array(
								'textarea_name' => 'wptsall_tinymce_content',
								'media_buttons' => true,
								'textarea_rows' => 15,
								'tinymce'       => array(
									'toolbar1' => 'formatselect,bold,italic,bullist,numlist,blockquote,alignleft,aligncenter,alignright,link,unlink,wp_more,fullscreen',
									'toolbar2' => 'strikethrough,hr,forecolor,pastetext,removeformat,charmap,outdent,indent,undo,redo,wp_help',
								),
								'quicktags'     => true,
							)
						);
						?>
					</div>
					<div id="wptsall-tinymce-excerpt" class="wptsall-tinymce-container" style="display:none;">
						<?php
						// Render a hidden wp_editor instance for post_excerpt.
						wp_editor(
							isset( $existing_target['post_excerpt'] ) ? $existing_target['post_excerpt'] : '',
							'wptsall_tinymce_excerpt',
							array(
								'textarea_name' => 'wptsall_tinymce_excerpt',
								'media_buttons' => false,
								'textarea_rows' => 5,
								'tinymce'       => array(
									'toolbar1' => 'bold,italic,bullist,numlist,link,unlink',
								),
								'quicktags'     => true,
							)
						);
						?>
					</div>
				</div>
			</div>

			<?php if ( ! empty( $taxonomy_section_data ) ) : ?>
			<!-- Taxonomy term section -->
			<div class="wptsall-taxonomy-section" id="wptsall-taxonomy-section">
				<h3><?php esc_html_e( 'Taxonomy Terms', 'wpmmcc-ats' ); ?></h3>
				<?php self::render_taxonomy_section( $taxonomy_section_data ); ?>
			</div>
			<?php endif; ?>

			<!-- Action bar -->
			<div class="wptsall-editor-actions">
				<button type="button" class="button button-primary" id="wptsall-save-translation">
					<?php esc_html_e( 'Save Translation', 'wpmmcc-ats' ); ?>
				</button>
				<button type="button" class="button" id="wptsall-auto-translate">
					<?php esc_html_e( 'Auto Translate', 'wpmmcc-ats' ); ?>
				</button>
			</div>
		</div>
		<?php

		Admin_Page_Helper::render_footer();
	}

	/**
	 * Render the source panel (read-only)
	 *
	 * Displays each field from the source post with its classification badge.
	 * field_config is a grouped array: translate_fields, sync_fields, compute_fields, skip_fields.
	 *
	 * @since 1.0.0
	 * @since 1.1.0 Adapted to grouped array format from Translation_Rule_Service.
	 * @param array $source       Source post data keyed by field name.
	 * @param array $field_config Field configuration in grouped array format.
	 */
	private static function render_source_panel( $source, $field_config ) {
		if ( empty( $field_config ) || empty( $source ) ) {
			echo '<p class="description">' . esc_html__( 'No fields to display', 'wpmmcc-ats' ) . '</p>';
			return;
		}

		$field_labels = array(
			'post_title'   => __( 'Title', 'wpmmcc-ats' ),
			'post_content' => __( 'Content', 'wpmmcc-ats' ),
			'post_excerpt' => __( 'Excerpt', 'wpmmcc-ats' ),
			'post_date'    => __( 'Date', 'wpmmcc-ats' ),
			'post_status'  => __( 'Status', 'wpmmcc-ats' ),
			'post_name'    => __( 'Slug', 'wpmmcc-ats' ),
			'_thumbnail_id' => __( 'Featured Image', 'wpmmcc-ats' ),
		);

		$badge_labels = array(
			'translate'  => __( 'Translate', 'wpmmcc-ats' ),
			'sync'       => __( 'Sync', 'wpmmcc-ats' ),
			'id_mapping' => __( 'ID Mapping', 'wpmmcc-ats' ),
			'compute'    => __( 'Compute', 'wpmmcc-ats' ),
		);

		// Build ordered field list from grouped config.
		$ordered_fields = self::build_ordered_fields( $field_config );

		foreach ( $ordered_fields as $field_info ) {
			$field_name = $field_info['key'];
			$type       = $field_info['type'];
			$label       = isset( $field_labels[ $field_name ] ) ? $field_labels[ $field_name ] : $field_name;
			$value       = isset( $source[ $field_name ] ) ? $source[ $field_name ] : '';
			$badge_label = isset( $badge_labels[ $type ] ) ? $badge_labels[ $type ] : $type;

			?>
			<div class="wptsall-field wptsall-field-<?php echo esc_attr( $type ); ?>">
				<label>
					<?php echo esc_html( $label ); ?>
					<span class="wptsall-field-badge wptsall-badge-<?php echo esc_attr( $type ); ?>">
						<?php echo esc_html( $badge_label ); ?>
					</span>
				</label>
				<div class="wptsall-field-value">
					<?php
					if ( self::is_media_field( $field_name ) && ! empty( $value ) ) {
						// Render media preview for media/attachment fields.
						$attachment_id = absint( $value );
						if ( $attachment_id && wp_attachment_is_image( $attachment_id ) ) {
							echo wp_get_attachment_image( $attachment_id, 'medium', false, array( 'class' => 'wptsall-media-preview-img' ) );
						} elseif ( $attachment_id ) {
							// Non-image attachment: show filename.
							$filename = basename( get_attached_file( $attachment_id ) ?: '' );
							echo '<span class="dashicons dashicons-media-default"></span> ';
							echo esc_html( $filename ?: sprintf( 'Attachment #%d', $attachment_id ) );
						} else {
							echo esc_html( $value );
						}
					} elseif ( 'post_content' === $field_name ) {
						echo wp_kses_post( $value );
					} else {
						echo esc_html( $value );
					}
					?>
				</div>
			</div>
			<?php
		}
	}

	/**
	 * Render the target panel (editable)
	 *
	 * Renders form fields for each translatable/syncable/computable field.
	 * field_config is a grouped array: translate_fields, sync_fields, compute_fields.
	 *
	 * @since 1.0.0
	 * @since 1.1.0 Adapted to grouped array format from Translation_Rule_Service.
	 * @param array $field_config    Field configuration in grouped array format.
	 * @param array $existing_target Existing target translations keyed by field name.
	 */
	private static function render_target_panel( $field_config, $existing_target ) {
		if ( empty( $field_config ) ) {
			echo '<p class="description">' . esc_html__( 'No editable fields', 'wpmmcc-ats' ) . '</p>';
			return;
		}

		$field_labels = array(
			'post_title'    => __( 'Title', 'wpmmcc-ats' ),
			'post_content'  => __( 'Content', 'wpmmcc-ats' ),
			'post_excerpt'  => __( 'Excerpt', 'wpmmcc-ats' ),
			'post_date'     => __( 'Date', 'wpmmcc-ats' ),
			'post_status'   => __( 'Status', 'wpmmcc-ats' ),
			'post_name'     => __( 'Slug', 'wpmmcc-ats' ),
			'_thumbnail_id' => __( 'Featured Image', 'wpmmcc-ats' ),
		);

		$badge_labels = array(
			'translate'  => __( 'Translate', 'wpmmcc-ats' ),
			'sync'       => __( 'Sync', 'wpmmcc-ats' ),
			'id_mapping' => __( 'ID Mapping', 'wpmmcc-ats' ),
			'compute'    => __( 'Compute', 'wpmmcc-ats' ),
		);

		// Build ordered field list from grouped config.
		$ordered_fields = self::build_ordered_fields( $field_config );

		foreach ( $ordered_fields as $field_info ) {
			$field_name  = $field_info['key'];
			$type        = $field_info['type'];
			$label       = isset( $field_labels[ $field_name ] ) ? $field_labels[ $field_name ] : $field_name;
			$badge_label = isset( $badge_labels[ $type ] ) ? $badge_labels[ $type ] : $type;
			$value       = isset( $existing_target[ $field_name ] ) ? $existing_target[ $field_name ] : '';

			?>
			<div class="wptsall-field wptsall-field-<?php echo esc_attr( $type ); ?>">
				<label for="wptsall-target-<?php echo esc_attr( $field_name ); ?>">
					<?php echo esc_html( $label ); ?>
					<span class="wptsall-field-badge wptsall-badge-<?php echo esc_attr( $type ); ?>">
						<?php echo esc_html( $badge_label ); ?>
					</span>
				</label>
				<?php
				switch ( $type ) {
					case 'translate':
						if ( 'post_content' === $field_name ) {
							?>
							<textarea
								id="wptsall-target-<?php echo esc_attr( $field_name ); ?>"
								name="<?php echo esc_attr( $field_name ); ?>"
								class="large-text"
								data-field-type="translate"
							><?php echo esc_textarea( $value ); ?></textarea>
							<?php
						} else {
							?>
							<input
								type="text"
								id="wptsall-target-<?php echo esc_attr( $field_name ); ?>"
								name="<?php echo esc_attr( $field_name ); ?>"
								value="<?php echo esc_attr( $value ); ?>"
								class="regular-text"
								data-field-type="translate"
							/>
							<?php
						}
						break;

					case 'sync':
						?>
						<input
							type="text"
							id="wptsall-target-<?php echo esc_attr( $field_name ); ?>"
							name="<?php echo esc_attr( $field_name ); ?>"
							value="<?php echo esc_attr( $value ); ?>"
							class="regular-text"
							data-field-type="sync"
							disabled
							readonly
						/>
						<?php
						break;

					case 'id_mapping':
						if ( self::is_media_field( $field_name ) ) {
							// Media field: render media picker with preview.
							self::render_media_picker_field( $field_name, $value );
						} else {
							?>
							<div class="wptsall-nontext-notice">
								<span class="dashicons dashicons-info-outline wptsall-nontext-icon"></span>
								<span class="wptsall-nontext-message">
									<?php esc_html_e( 'Non-text fields do not support manual translation yet', 'wpmmcc-ats' ); ?>
								</span>
								<?php if ( $value ) : ?>
									<div class="wptsall-nontext-value">
										<?php echo esc_html( mb_strimwidth( (string) $value, 0, 100, '...' ) ); ?>
									</div>
								<?php endif; ?>
							</div>
							<?php
						}
						break;

					case 'compute':
						?>
						<input
							type="text"
							id="wptsall-target-<?php echo esc_attr( $field_name ); ?>"
							name="<?php echo esc_attr( $field_name ); ?>"
							value="<?php echo esc_attr( $value ); ?>"
							class="regular-text"
							data-field-type="compute"
						/>
						<?php
						break;
				}
				?>
			</div>
			<?php
		}
	}

	/**
	 * Build ordered field list from grouped field_config
	 *
	 * Converts grouped format (translate_fields => [...], sync_fields => [...], ...)
	 * into ordered array of {key, type} suitable for rendering.
	 *
	 * @since 1.1.0
	 * @param array $field_config Grouped field configuration.
	 * @return array Ordered array of ['key' => field_name, 'type' => field_type].
	 */
	private static function build_ordered_fields( $field_config ) {
		$fields = array();

		$groups = array(
			'translate_fields'  => 'translate',
			'sync_fields'       => 'sync',
			'id_mapping_fields' => 'id_mapping',
			'compute_fields'    => 'compute',
		);

		foreach ( $groups as $group_key => $type ) {
			if ( ! empty( $field_config[ $group_key ] ) && is_array( $field_config[ $group_key ] ) ) {
				foreach ( $field_config[ $group_key ] as $field_name ) {
					$fields[] = array(
						'key'  => $field_name,
						'type' => $type,
					);
				}
			}
		}

		return $fields;
	}

	/**
	 * Check if a field name represents a media/attachment field
	 *
	 * Detects fields that store attachment IDs (featured image, ACF image fields, etc.).
	 *
	 * @since 1.1.0
	 * @param string $field_name Field name to check.
	 * @return bool True if the field is a media/attachment field.
	 */
	private static function is_media_field( $field_name ) {
		// Known media fields.
		$media_fields = array(
			'_thumbnail_id',
		);

		if ( in_array( $field_name, $media_fields, true ) ) {
			return true;
		}

		// Pattern-based detection for media fields.
		$media_patterns = array(
			'/^_thumbnail/',
			'/_image_id$/',
			'/_image$/',
			'/_gallery$/',
			'/_attachment_id$/',
			'/^_wp_attached/',
		);

		foreach ( $media_patterns as $pattern ) {
			if ( preg_match( $pattern, $field_name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Render a media picker field for the target panel
	 *
	 * Shows a media preview (if an attachment ID is set) and a button
	 * to open the WordPress media library modal.
	 *
	 * @since 1.1.0
	 * @param string     $field_name Field name (e.g. '_thumbnail_id').
	 * @param string|int $value      Current attachment ID or empty.
	 */
	private static function render_media_picker_field( $field_name, $value ) {
		$attachment_id = absint( $value );
		$has_image     = $attachment_id && wp_attachment_is_image( $attachment_id );

		?>
		<div class="wptsall-media-picker" data-field="<?php echo esc_attr( $field_name ); ?>">
			<div class="wptsall-media-preview" id="wptsall-media-preview-<?php echo esc_attr( $field_name ); ?>">
				<?php if ( $has_image ) : ?>
					<?php echo wp_get_attachment_image( $attachment_id, 'medium', false, array( 'class' => 'wptsall-media-preview-img' ) ); ?>
				<?php elseif ( $attachment_id ) : ?>
					<span class="dashicons dashicons-media-default wptsall-media-placeholder-icon"></span>
					<span><?php echo esc_html( basename( get_attached_file( $attachment_id ) ?: '' ) ); ?></span>
				<?php else : ?>
					<span class="wptsall-media-no-image"><?php esc_html_e( 'No image selected', 'wpmmcc-ats' ); ?></span>
				<?php endif; ?>
			</div>
			<input
				type="hidden"
				id="wptsall-target-<?php echo esc_attr( $field_name ); ?>"
				name="<?php echo esc_attr( $field_name ); ?>"
				value="<?php echo esc_attr( $attachment_id ? $attachment_id : '' ); ?>"
				data-field-type="media"
			/>
			<div class="wptsall-media-buttons">
				<button
					type="button"
					class="button wptsall-media-select-btn"
					data-field="<?php echo esc_attr( $field_name ); ?>"
				>
					<?php echo $has_image || $attachment_id ? esc_html__( 'Change Image', 'wpmmcc-ats' ) : esc_html__( 'Select Image', 'wpmmcc-ats' ); ?>
				</button>
				<button
					type="button"
					class="button wptsall-media-remove-btn"
					data-field="<?php echo esc_attr( $field_name ); ?>"
					<?php echo $attachment_id ? '' : 'style="display:none;"'; ?>
				>
					<?php esc_html_e( 'Remove', 'wpmmcc-ats' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * Build backend edit URL for a WP subsite target post.
	 *
	 * Uses the backend_edit URL pattern from translation rules if available,
	 * with {id} placeholder replaced by the target post ID.
	 * Falls back to the standard WordPress edit post URL on the target subsite.
	 *
	 * @since 1.1.0
	 *
	 * @param int    $relation_id Site relation ID.
	 * @param string $post_type   Post type.
	 * @param int    $target_id   Target post ID.
	 * @param array  $relation    Relation data.
	 * @return string|null Edit URL or null if not buildable.
	 */
	private static function build_backend_edit_url( int $relation_id, string $post_type, int $target_id, array $relation ): ?string {
		// Try to get backend_edit pattern from translation rules.
		$backend_edit = '';

		if ( class_exists( '\\WPTSALL\\Models\\Services\\Translation_Rule_Service' ) ) {
			// Look up the rule for this post type via the relation's models.
			if ( class_exists( '\\WPTSALL\\Sites\\Services\\Relation_Model_Service' ) ) {
				$models = \WPTSALL\Sites\Services\Relation_Model_Service::get_models_by_relation( $relation_id );

				if ( ! empty( $models ) ) {
					foreach ( $models as $model ) {
						$rule = \WPTSALL\Models\Services\Translation_Rule_Service::get_rule_by_post_type(
							(int) $model['id'],
							$post_type
						);
						if ( $rule && ! empty( $rule['backend_edit'] ) ) {
							$backend_edit = $rule['backend_edit'];
							break;
						}
					}
				}
			}
		}

		if ( $backend_edit ) {
			// Replace {id} placeholder with target ID.
			$url = str_replace( '{id}', (string) $target_id, $backend_edit );

			// If the URL is relative, make it absolute using the target site's admin URL.
			if ( 0 === strpos( $url, '/' ) ) {
				$target_blog_id = (int) ( $relation['target_site_id'] ?? 0 );
				if ( $target_blog_id > 0 && is_multisite() ) {
					$url = get_admin_url( $target_blog_id ) . ltrim( $url, '/' );
				} else {
					$url = admin_url( ltrim( $url, '/' ) );
				}
			}

			return $url;
		}

		// Fallback: standard WordPress edit URL.
		$target_blog_id = (int) ( $relation['target_site_id'] ?? 0 );
		if ( $target_blog_id > 0 && is_multisite() ) {
			return get_admin_url( $target_blog_id, 'post.php?post=' . $target_id . '&action=edit' );
		}

		return admin_url( 'post.php?post=' . $target_id . '&action=edit' );
	}

	/**
	 * Build frontend URL for a virtual site target post (CPT-aware).
	 *
	 * @since 1.1.0
	 *
	 * @param int   $target_id Target post ID.
	 * @param array $relation  Relation data.
	 * @return string|null Frontend URL or null if not buildable.
	 */
	private static function build_virtual_frontend_url( int $target_id, array $relation ): ?string {
		$url = Virtual_Permalink::for_post( $target_id, $relation );
		return '' !== $url ? $url : null;
	}

	/**
	 * Get taxonomy data for the JS editor.
	 *
	 * Retrieves managed taxonomies and their terms for the translation editor JS.
	 *
	 * @since 1.2.0
	 *
	 * @param int $source_post_id Source post ID.
	 * @param int $relation_id    Site relation ID.
	 * @return array Taxonomy data array for wp_localize_script.
	 */
	private static function get_taxonomy_data_for_editor( int $source_post_id, int $relation_id ): array {
		if ( ! $source_post_id || ! $relation_id ) {
			return array();
		}

		$source_post = get_post( $source_post_id );
		if ( ! $source_post ) {
			return array();
		}

		$managed_taxonomies = self::get_managed_taxonomies( $relation_id, $source_post->post_type );

		if ( empty( $managed_taxonomies ) ) {
			return array();
		}

		$taxonomy_data = array();

		foreach ( $managed_taxonomies as $taxonomy_name ) {
			$taxonomy_obj = get_taxonomy( $taxonomy_name );
			if ( ! $taxonomy_obj ) {
				continue;
			}

			$taxonomy_data[] = array(
				'name'         => $taxonomy_name,
				'label'        => $taxonomy_obj->labels->name,
				'hierarchical' => $taxonomy_obj->hierarchical,
			);
		}

		return $taxonomy_data;
	}

	/**
	 * Get taxonomy section data for rendering.
	 *
	 * @since 1.2.0
	 *
	 * @param int   $source_post_id Source post ID.
	 * @param int   $relation_id    Site relation ID.
	 * @param array $relation       Relation data.
	 * @return array Taxonomy section data with terms.
	 */
	private static function get_taxonomy_section_data( int $source_post_id, int $relation_id, array $relation ): array {
		$source_post = get_post( $source_post_id );
		if ( ! $source_post ) {
			return array();
		}

		$managed_taxonomies = self::get_managed_taxonomies( $relation_id, $source_post->post_type );

		if ( empty( $managed_taxonomies ) ) {
			return array();
		}

		// Get target post ID for pre-selecting terms.
		$target_id = Manual_Content_Service::find_existing_translation( $source_post_id, $relation_id );

		$sections = array();

		foreach ( $managed_taxonomies as $taxonomy_name ) {
			$taxonomy_obj = get_taxonomy( $taxonomy_name );
			if ( ! $taxonomy_obj ) {
				continue;
			}

			// Get source post terms.
			$source_terms = wp_get_object_terms( $source_post_id, $taxonomy_name, array( 'fields' => 'all' ) );
			if ( is_wp_error( $source_terms ) ) {
				$source_terms = array();
			}

			// Get target post terms (if target exists).
			$target_term_ids = array();
			if ( $target_id ) {
				$target_site_type = $relation['target_site_type'] ?? 'wp';
				$switched         = false;

				if ( 'wp' === $target_site_type && is_multisite() ) {
					switch_to_blog( (int) $relation['target_site_id'] );
					$switched = true;
				}

				$target_terms = wp_get_object_terms( $target_id, $taxonomy_name, array( 'fields' => 'ids' ) );
				if ( ! is_wp_error( $target_terms ) ) {
					$target_term_ids = $target_terms;
				}

				if ( $switched ) {
					restore_current_blog();
				}
			}

			$sections[] = array(
				'name'            => $taxonomy_name,
				'label'           => $taxonomy_obj->labels->name,
				'hierarchical'    => $taxonomy_obj->hierarchical,
				'source_terms'    => $source_terms,
				'target_term_ids' => $target_term_ids,
				'target_id'       => $target_id,
			);
		}

		return $sections;
	}

	/**
	 * Get managed taxonomies for a relation and post type.
	 *
	 * @since 1.2.0
	 *
	 * @param int    $relation_id Site relation ID.
	 * @param string $post_type   Post type name.
	 * @return array Array of taxonomy names.
	 */
	private static function get_managed_taxonomies( int $relation_id, string $post_type ): array {
		$managed = array();

		if ( class_exists( '\\WPTSALL\\Sites\\Services\\Relation_Model_Service' ) &&
			 class_exists( '\\WPTSALL\\Models\\Services\\Translation_Rule_Service' ) ) {

			$models = \WPTSALL\Sites\Services\Relation_Model_Service::get_models_by_relation( $relation_id );

			if ( ! empty( $models ) ) {
				foreach ( $models as $model ) {
					$rule = \WPTSALL\Models\Services\Translation_Rule_Service::get_rule_by_post_type(
						(int) $model['id'],
						$post_type
					);
					if ( $rule && ! empty( $rule['related_taxonomies'] ) ) {
						$managed = $rule['related_taxonomies'];
						break;
					}
				}
			}
		}

		// Fallback: use post type's registered taxonomies if no explicit configuration.
		if ( empty( $managed ) ) {
			$all_taxonomies = get_object_taxonomies( $post_type, 'names' );
			// Filter to public taxonomies only.
			$managed = array();
			foreach ( $all_taxonomies as $tax_name ) {
				$tax_obj = get_taxonomy( $tax_name );
				if ( $tax_obj && $tax_obj->public ) {
					$managed[] = $tax_name;
				}
			}
		}

		return $managed;
	}

	/**
	 * Render the taxonomy section with term pickers.
	 *
	 * Renders hierarchical taxonomies with wp_terms_checklist() and
	 * flat taxonomies with a tag-style input.
	 *
	 * @since 1.2.0
	 *
	 * @param array $taxonomy_sections Taxonomy section data from get_taxonomy_section_data().
	 */
	private static function render_taxonomy_section( array $taxonomy_sections ): void {
		foreach ( $taxonomy_sections as $section ) {
			$taxonomy_name = $section['name'];
			$label         = $section['label'];
			$hierarchical  = $section['hierarchical'];
			$target_id     = $section['target_id'] ?? 0;
			$target_terms  = $section['target_term_ids'] ?? array();

			?>
			<div class="wptsall-taxonomy-picker" data-taxonomy="<?php echo esc_attr( $taxonomy_name ); ?>">
				<label class="wptsall-taxonomy-label">
					<?php echo esc_html( $label ); ?>
					<span class="wptsall-field-badge wptsall-badge-taxonomy"><?php esc_html_e( 'Taxonomy', 'wpmmcc-ats' ); ?></span>
				</label>

				<?php if ( $hierarchical ) : ?>
					<!-- Hierarchical taxonomy: checkbox tree -->
					<div class="wptsall-taxonomy-checklist" id="wptsall-taxonomy-<?php echo esc_attr( $taxonomy_name ); ?>">
						<ul class="categorychecklist">
						<?php
						wp_terms_checklist(
							$target_id ? $target_id : 0,
							array(
								'taxonomy'      => $taxonomy_name,
								'selected_cats' => $target_terms,
								'checked_ontop' => true,
								'walker'        => null,
							)
						);
						?>
						</ul>
					</div>
				<?php else : ?>
					<!-- Flat taxonomy: tag-style input -->
					<div class="wptsall-taxonomy-tags" id="wptsall-taxonomy-<?php echo esc_attr( $taxonomy_name ); ?>">
						<div class="wptsall-tag-list" id="wptsall-tag-list-<?php echo esc_attr( $taxonomy_name ); ?>">
							<?php
							if ( ! empty( $target_terms ) ) {
								$term_objects = get_terms(
									array(
										'taxonomy'   => $taxonomy_name,
										'include'    => $target_terms,
										'hide_empty' => false,
									)
								);
								if ( ! is_wp_error( $term_objects ) ) {
									foreach ( $term_objects as $term ) {
										printf(
											'<span class="wptsall-tag-item" data-term-id="%d">%s <button type="button" class="wptsall-tag-remove" data-term-id="%d">&times;</button></span>',
											(int) $term->term_id,
											esc_html( $term->name ),
											(int) $term->term_id
										);
									}
								}
							}
							?>
						</div>
						<div class="wptsall-tag-input-wrap">
							<input type="text" class="wptsall-tag-input" id="wptsall-tag-input-<?php echo esc_attr( $taxonomy_name ); ?>" placeholder="<?php esc_attr_e( 'Add term...', 'wpmmcc-ats' ); ?>" />
							<button type="button" class="button button-small wptsall-tag-add-btn" data-taxonomy="<?php echo esc_attr( $taxonomy_name ); ?>">
								<?php esc_html_e( 'Add', 'wpmmcc-ats' ); ?>
							</button>
						</div>
						<input type="hidden" id="wptsall-taxonomy-ids-<?php echo esc_attr( $taxonomy_name ); ?>" value="<?php echo esc_attr( implode( ',', array_map( 'intval', $target_terms ) ) ); ?>" />
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $section['source_terms'] ) ) : ?>
					<div class="wptsall-source-terms-hint">
						<span class="description">
							<?php
							printf(
								/* translators: %s: comma-separated list of source term names */
								esc_html__( 'Source terms: %s', 'wpmmcc-ats' ),
								esc_html( implode( ', ', wp_list_pluck( $section['source_terms'], 'name' ) ) )
							);
							?>
						</span>
					</div>
				<?php endif; ?>
			</div>
			<?php
		}
	}

	/**
	 * Resolve editor query args.
	 *
	 * Accepts the canonical pair (source_post_id + relation_id) and the
	 * list/meta-box shortcut (post_id + to_lang) used by translation flags.
	 *
	 * @return array{source_post_id:int,relation_id:int,should_redirect:bool,error:string}
	 */
	private static function resolve_editor_request_params(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$source_post_id = isset( $_GET['source_post_id'] ) ? absint( $_GET['source_post_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$relation_id = isset( $_GET['relation_id'] ) ? absint( $_GET['relation_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$to_lang = isset( $_GET['to_lang'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['to_lang'] ) ) : '';

		$out = array(
			'source_post_id'  => $source_post_id,
			'relation_id'     => $relation_id,
			'should_redirect' => false,
			'error'           => '',
		);

		if ( $source_post_id > 0 && $relation_id > 0 ) {
			return $out;
		}

		if ( $post_id > 0 ) {
			$out['source_post_id'] = $post_id;
		}

		if ( $out['source_post_id'] <= 0 ) {
			$out['error'] = __( 'Missing required parameters: source_post_id and relation_id', 'wpmmcc-ats' );
			return $out;
		}

		if ( $out['relation_id'] > 0 ) {
			$out['should_redirect'] = ( $post_id > 0 || '' !== $to_lang );
			return $out;
		}

		if ( '' === $to_lang ) {
			$resolved = Relation_Resolver::for_post_default( (int) $out['source_post_id'] );
			if ( $resolved <= 0 ) {
				$out['error'] = __( 'Missing required parameters: relation_id (or to_lang for flag shortcuts)', 'wpmmcc-ats' );
				return $out;
			}
			$out['relation_id']     = $resolved;
			$out['should_redirect'] = true;
			return $out;
		}

		$resolved = Relation_Resolver::for_post_lang( (int) $out['source_post_id'], $to_lang );
		if ( $resolved <= 0 ) {
			$out['error'] = sprintf(
				/* translators: %s: language code */
				__( 'No active site relation found for language %s', 'wpmmcc-ats' ),
				$to_lang
			);
			return $out;
		}

		$out['relation_id']     = $resolved;
		$out['should_redirect'] = true;
		return $out;
	}

	/**
	 * Find a usable active site relation for a source post (no target lang given).
	 *
	 * @deprecated 2.3.0 Use {@see Relation_Resolver::for_post_default()}.
	 * @param int $source_post_id Source post ID.
	 * @return int Relation ID or 0.
	 */
	public static function find_default_relation_id_for_post( int $source_post_id ): int {
		return Relation_Resolver::for_post_default( $source_post_id );
	}

	/**
	 * Find an active site relation for a source post + target language.
	 *
	 * @deprecated 2.3.0 Use {@see Relation_Resolver::for_post_lang()}.
	 * @param int    $source_post_id Source post ID.
	 * @param string $to_lang        Target language code.
	 * @return int Relation ID or 0.
	 */
	public static function find_relation_id_for_post_lang( int $source_post_id, string $to_lang ): int {
		return Relation_Resolver::for_post_lang( $source_post_id, $to_lang );
	}
}

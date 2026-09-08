<?php
/**
 * Template Edit Page
 *
 * Language pack edit page
 *
 * @package WPTSALL\Templates
 * @since 0.5.0
  * phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin list/render $_GET filters; state-changing handlers use check_admin_referer / check_ajax_referer.
 */
namespace WPTSALL\Templates\Admin;

use WPTSALL\Admin\Admin_Page_Helper;
use WPTSALL\Templates\Services\Template_Service;
use WPTSALL\Templates\Services\Template_Entry_Service;
use WPTSALL\Sites\Services\Site_Relation_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Template_Edit_Page class
 *
 * Provides language pack editing interface
 */
class Template_Edit_Page {

	/**
	 * Render edit page
	 */
	public static function render() {
		$template_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		if ( ! $template_id ) {
			self::render_error( __( 'Template ID not specified', 'wpmmcc-ats' ) );
			return;
		}

		$template = Template_Service::get_with_relation( $template_id );

		if ( ! $template ) {
			self::render_error( __( 'Template does not exist', 'wpmmcc-ats' ) );
			return;
		}

		// Get entry filter parameters
		$status   = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
		$source   = isset( $_GET['source'] ) ? sanitize_key( $_GET['source'] ) : '';
		$search   = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';
		$page     = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;

		// Get entries
		$entries_result = Template_Entry_Service::get_by_template( $template_id, array(
			'status'   => $status,
			'source'   => $source,
			'search'   => $search,
			'page'     => $page,
			'per_page' => 50,
		) );

		self::render_page( $template, $entries_result, array(
			'status' => $status,
			'source' => $source,
			'search' => $search,
			'page'   => $page,
		) );
	}

	/**
	 * Render error page
	 *
	 * @param string $message Error message
	 */
	private static function render_error( $message ) {
		Admin_Page_Helper::render_header(
			__( 'Language Pack Edit', 'wpmmcc-ats' ),
			'templates',
			array(
				array(
					'label' => __( 'Back to List', 'wpmmcc-ats' ),
					'url'   => admin_url( 'admin.php?page=wptsall-templates' ),
					'class' => 'button',
				),
			)
		);

		Admin_Page_Helper::render_notice( $message, 'error' );
		Admin_Page_Helper::render_footer();
	}

	/**
	 * Render edit page
	 *
	 * @param array $template       Template data
	 * @param array $entries_result Entries result
	 * @param array $filters        Filter parameters
	 */
	private static function render_page( $template, $entries_result, $filters ) {
		$list_url = admin_url( 'admin.php?page=wptsall-templates' );
		$edit_url = admin_url( 'admin.php?page=wptsall-templates&action=edit&id=' . $template['id'] );

		$status_labels = array(
			'pending'    => __( 'Pending', 'wpmmcc-ats' ),
			'translated' => __( 'Translated', 'wpmmcc-ats' ),
			'reviewed'   => __( 'Reviewed', 'wpmmcc-ats' ),
			'skipped'    => __( 'Skipped', 'wpmmcc-ats' ),
		);

		$source_labels = array(
			'scan'   => __( 'Scan', 'wpmmcc-ats' ),
			'auto'   => __( 'Auto', 'wpmmcc-ats' ),
			'manual' => __( 'Manual', 'wpmmcc-ats' ),
		);

		$page_title = sprintf(
			/* translators: %s: template name */
			esc_html__( 'Edit Language Pack: %s', 'wpmmcc-ats' ),
			esc_html( $template['source_name'] ?: $template['text_domain'] )
		);

		Admin_Page_Helper::render_header(
			$page_title,
			'templates',
			array(
				array(
					'label' => __( 'Back to List', 'wpmmcc-ats' ),
					'url'   => $list_url,
					'class' => 'button',
				),
			),
			array( 'wptsall-template-edit-page' )
		);
		?>
				<div class="wptsall-page-content">
					<?php self::render_template_info( $template ); ?>

			<div class="wptsall-template-actions">
				<button type="button" class="button wptsall-rescan-btn" data-id="<?php echo esc_attr( $template['id'] ); ?>">
					<?php esc_html_e( 'Rescan', 'wpmmcc-ats' ); ?>
				</button>
				<button type="button" class="button wptsall-export-btn" data-id="<?php echo esc_attr( $template['id'] ); ?>">
					<?php esc_html_e( 'Export PO', 'wpmmcc-ats' ); ?>
				</button>
				<button type="button" class="button wptsall-import-btn" data-id="<?php echo esc_attr( $template['id'] ); ?>">
					<?php esc_html_e( 'Import PO', 'wpmmcc-ats' ); ?>
				</button>
			</div>

			<?php self::render_filters( $template['id'], $filters, $status_labels, $source_labels ); ?>

			<?php if ( empty( $entries_result['items'] ) ) : ?>
				<div class="wptsall-no-items">
					<p><?php esc_html_e( 'No translation entries.', 'wpmmcc-ats' ); ?></p>
				</div>
			<?php else : ?>
				<form id="wptsall-entries-form" method="post">
					<input type="hidden" name="template_id" value="<?php echo esc_attr( $template['id'] ); ?>">

					<div class="wptsall-bulk-actions">
						<select name="bulk_action" id="bulk-action-selector">
							<option value=""><?php esc_html_e( 'Bulk Actions', 'wpmmcc-ats' ); ?></option>
							<option value="mark_reviewed"><?php esc_html_e( 'Mark as Reviewed', 'wpmmcc-ats' ); ?></option>
							<option value="mark_skipped"><?php esc_html_e( 'Mark as Skipped', 'wpmmcc-ats' ); ?></option>
							<option value="mark_pending"><?php esc_html_e( 'Mark as Pending', 'wpmmcc-ats' ); ?></option>
						</select>
						<button type="button" class="button wptsall-bulk-apply"><?php esc_html_e( 'Apply', 'wpmmcc-ats' ); ?></button>
						<span class="wptsall-selected-count"></span>
					</div>

					<?php self::render_entries_table( $entries_result['items'], $status_labels, $source_labels ); ?>

					<?php self::render_pagination( $template['id'], $entries_result['total'], $entries_result['pages'], $filters['page'], $filters ); ?>
				</form>
			<?php endif; ?>
				</div><!-- .wptsall-page-content -->

		<?php Admin_Page_Helper::render_footer(); ?>

		<?php self::render_edit_modal(); ?>
		<?php self::render_import_modal(); ?>
		<?php
	}

	/**
	 * Render template info
	 *
	 * @param array $template Template data
	 */
	private static function render_template_info( $template ) {
		$progress = $template['progress'] ?? 0;

		$type_labels = array(
			'theme'  => __( 'Theme', 'wpmmcc-ats' ),
			'plugin' => __( 'Plugin', 'wpmmcc-ats' ),
		);

		$status_labels = array(
			'pending'     => __( 'Pending Scan', 'wpmmcc-ats' ),
			'scanned'     => __( 'Scanned', 'wpmmcc-ats' ),
			'translating' => __( 'Translating', 'wpmmcc-ats' ),
			'completed'   => __( 'Completed', 'wpmmcc-ats' ),
		);
		?>
		<div class="wptsall-template-info">
			<div class="wptsall-info-row">
				<div class="wptsall-info-item">
					<span class="wptsall-info-label"><?php esc_html_e( 'Type', 'wpmmcc-ats' ); ?></span>
					<span class="wptsall-info-value">
						<?php echo esc_html( $type_labels[ $template['source_type'] ] ?? $template['source_type'] ); ?>
					</span>
				</div>
				<div class="wptsall-info-item">
					<span class="wptsall-info-label"><?php esc_html_e( 'Text Domain', 'wpmmcc-ats' ); ?></span>
					<span class="wptsall-info-value"><?php echo esc_html( $template['text_domain'] ); ?></span>
				</div>
				<div class="wptsall-info-item">
					<span class="wptsall-info-label"><?php esc_html_e( 'Version', 'wpmmcc-ats' ); ?></span>
					<span class="wptsall-info-value"><?php echo esc_html( $template['source_version'] ?: '-' ); ?></span>
				</div>
				<div class="wptsall-info-item">
					<span class="wptsall-info-label"><?php esc_html_e( 'Status', 'wpmmcc-ats' ); ?></span>
					<span class="wptsall-info-value wptsall-status wptsall-status-<?php echo esc_attr( $template['status'] ); ?>">
						<?php echo esc_html( $status_labels[ $template['status'] ] ?? $template['status'] ); ?>
					</span>
				</div>
			</div>
			<div class="wptsall-info-row">
				<div class="wptsall-info-item wptsall-info-progress">
					<span class="wptsall-info-label"><?php esc_html_e( 'Translation Progress', 'wpmmcc-ats' ); ?></span>
					<div class="wptsall-progress-bar">
						<div class="wptsall-progress-fill" style="width: <?php echo esc_attr( $progress ); ?>%;"></div>
					</div>
					<span class="wptsall-progress-text">
						<?php
						printf(
							/* translators: 1: translated, 2: total, 3: reviewed */
							esc_html__( '%1$d / %2$d translated, %3$d reviewed', 'wpmmcc-ats' ),
							intval( $template['translated_entries'] ),
							intval( $template['total_entries'] ),
							intval( $template['reviewed_entries'] )
						);
						?>
					</span>
				</div>
			</div>
			<?php if ( ! empty( $template['last_scanned_at'] ) ) : ?>
				<div class="wptsall-info-row">
					<div class="wptsall-info-item">
						<span class="wptsall-info-label"><?php esc_html_e( 'Last Scanned', 'wpmmcc-ats' ); ?></span>
						<span class="wptsall-info-value"><?php echo esc_html( $template['last_scanned_at'] ); ?></span>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render filters
	 *
	 * @param int    $template_id   Template ID
	 * @param array  $filters       Filter parameters
	 * @param array  $status_labels Status labels
	 * @param array  $source_labels Source labels
	 */
	private static function render_filters( $template_id, $filters, $status_labels, $source_labels ) {
		$base_url = admin_url( 'admin.php?page=wptsall-templates&action=edit&id=' . $template_id );
		?>
		<div class="wptsall-entries-filters">
			<form method="get" action="">
				<input type="hidden" name="page" value="wptsall-templates">
				<input type="hidden" name="action" value="edit">
				<input type="hidden" name="id" value="<?php echo esc_attr( $template_id ); ?>">

				<select name="status">
					<option value=""><?php esc_html_e( 'All Statuses', 'wpmmcc-ats' ); ?></option>
					<?php foreach ( $status_labels as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['status'], $value ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<select name="source">
					<option value=""><?php esc_html_e( 'All Sources', 'wpmmcc-ats' ); ?></option>
					<?php foreach ( $source_labels as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['source'], $value ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<input type="search" name="search" value="<?php echo esc_attr( $filters['search'] ); ?>"
				       placeholder="<?php esc_attr_e( 'Search...', 'wpmmcc-ats' ); ?>">

				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'wpmmcc-ats' ); ?></button>

				<?php if ( $filters['status'] || $filters['source'] || $filters['search'] ) : ?>
					<a href="<?php echo esc_url( $base_url ); ?>" class="button"><?php esc_html_e( 'Clear', 'wpmmcc-ats' ); ?></a>
				<?php endif; ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render entries table
	 *
	 * @param array $entries       Entry list
	 * @param array $status_labels Status labels
	 * @param array $source_labels Source labels
	 */
	private static function render_entries_table( $entries, $status_labels, $source_labels ) {
		?>
		<table class="wp-list-table widefat fixed striped wptsall-entries-table">
			<thead>
				<tr>
					<th class="check-column">
						<input type="checkbox" id="cb-select-all-1">
					</th>
					<th class="column-msgid"><?php esc_html_e( 'Source Text', 'wpmmcc-ats' ); ?></th>
					<th class="column-msgstr"><?php esc_html_e( 'Translation', 'wpmmcc-ats' ); ?></th>
					<th class="column-status"><?php esc_html_e( 'Status', 'wpmmcc-ats' ); ?></th>
					<th class="column-source"><?php esc_html_e( 'Source', 'wpmmcc-ats' ); ?></th>
					<th class="column-actions"><?php esc_html_e( 'Actions', 'wpmmcc-ats' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $entries as $entry ) : ?>
					<tr data-entry-id="<?php echo esc_attr( $entry['id'] ); ?>">
						<th scope="row" class="check-column">
							<input type="checkbox" name="entry_ids[]" value="<?php echo esc_attr( $entry['id'] ); ?>">
						</th>
						<td class="column-msgid">
							<div class="wptsall-msgid">
								<?php if ( ! empty( $entry['msgctxt'] ) ) : ?>
									<span class="wptsall-msgctxt" title="<?php esc_attr_e( 'Context', 'wpmmcc-ats' ); ?>">
										[<?php echo esc_html( $entry['msgctxt'] ); ?>]
									</span>
								<?php endif; ?>
								<span class="wptsall-msgid-text"><?php echo esc_html( self::truncate( $entry['msgid'], 100 ) ); ?></span>
								<?php if ( ! empty( $entry['msgid_plural'] ) ) : ?>
									<span class="wptsall-msgid-plural" title="<?php esc_attr_e( 'Plural form', 'wpmmcc-ats' ); ?>">
										(<?php echo esc_html( self::truncate( $entry['msgid_plural'], 50 ) ); ?>)
									</span>
								<?php endif; ?>
							</div>
							<?php if ( ! empty( $entry['reference'] ) ) : ?>
								<div class="wptsall-reference" title="<?php echo esc_attr( $entry['reference'] ); ?>">
									<?php echo esc_html( self::truncate( $entry['reference'], 50 ) ); ?>
								</div>
							<?php endif; ?>
						</td>
						<td class="column-msgstr">
							<div class="wptsall-msgstr">
								<?php if ( ! empty( $entry['msgstr'] ) ) : ?>
									<span class="wptsall-msgstr-text"><?php echo esc_html( self::truncate( $entry['msgstr'], 100 ) ); ?></span>
								<?php else : ?>
									<span class="wptsall-no-translation"><?php esc_html_e( 'Not translated', 'wpmmcc-ats' ); ?></span>
								<?php endif; ?>
							</div>
						</td>
						<td class="column-status">
							<span class="wptsall-entry-status wptsall-entry-status-<?php echo esc_attr( $entry['status'] ); ?>">
								<?php echo esc_html( $status_labels[ $entry['status'] ] ?? $entry['status'] ); ?>
							</span>
						</td>
						<td class="column-source">
							<span class="wptsall-entry-source">
								<?php echo esc_html( $source_labels[ $entry['source'] ] ?? $entry['source'] ); ?>
							</span>
						</td>
						<td class="column-actions">
							<button type="button" class="button button-small wptsall-edit-entry-btn"
							        data-entry='<?php echo esc_attr( wp_json_encode( $entry ) ); ?>'>
								<?php esc_html_e( 'Edit', 'wpmmcc-ats' ); ?>
							</button>
							<button type="button" class="button button-small button-link-delete wptsall-delete-entry-btn"
							        data-id="<?php echo intval( $entry['id'] ); ?>">
								<?php esc_html_e( 'Delete', 'wpmmcc-ats' ); ?>
							</button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render pagination
	 *
	 * @param int   $template_id Template ID
	 * @param int   $total       Total count
	 * @param int   $pages       Total pages
	 * @param int   $current     Current page
	 * @param array $filters     Filter parameters
	 */
	private static function render_pagination( $template_id, $total, $pages, $current, $filters ) {
		if ( $pages <= 1 ) {
			return;
		}

		$base_url = admin_url( 'admin.php?page=wptsall-templates&action=edit&id=' . $template_id );
		if ( ! empty( $filters['status'] ) ) {
			$base_url = add_query_arg( 'status', $filters['status'], $base_url );
		}
		if ( ! empty( $filters['source'] ) ) {
			$base_url = add_query_arg( 'source', $filters['source'], $base_url );
		}
		if ( ! empty( $filters['search'] ) ) {
			$base_url = add_query_arg( 'search', $filters['search'], $base_url );
		}
		?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<span class="displaying-num">
					<?php
					printf(
						/* translators: %s: number of items */
						esc_html( _n( '%s entry', '%s entries', $total, 'wpmmcc-ats' ) ),
						esc_html( number_format_i18n( $total ) )
					);
					?>
				</span>
				<span class="pagination-links">
					<?php if ( $current > 1 ) : ?>
						<a class="first-page button" href="<?php echo esc_url( add_query_arg( 'paged', 1, $base_url ) ); ?>">
							<span aria-hidden="true">&laquo;</span>
						</a>
						<a class="prev-page button" href="<?php echo esc_url( add_query_arg( 'paged', $current - 1, $base_url ) ); ?>">
							<span aria-hidden="true">&lsaquo;</span>
						</a>
					<?php endif; ?>

					<span class="paging-input">
						<?php echo esc_html( $current ); ?> / <?php echo esc_html( $pages ); ?>
					</span>

					<?php if ( $current < $pages ) : ?>
						<a class="next-page button" href="<?php echo esc_url( add_query_arg( 'paged', $current + 1, $base_url ) ); ?>">
							<span aria-hidden="true">&rsaquo;</span>
						</a>
						<a class="last-page button" href="<?php echo esc_url( add_query_arg( 'paged', $pages, $base_url ) ); ?>">
							<span aria-hidden="true">&raquo;</span>
						</a>
					<?php endif; ?>
				</span>
			</div>
		</div>
		<?php
	}

	/**
	 * Render edit modal
	 */
	private static function render_edit_modal() {
		?>
		<div id="wptsall-entry-modal" class="wptsall-modal" style="display:none;">
			<div class="wptsall-modal-content wptsall-modal-large">
				<span class="wptsall-modal-close">&times;</span>
				<h2><?php esc_html_e( 'Edit Translation Entry', 'wpmmcc-ats' ); ?></h2>

				<form id="wptsall-entry-form">
					<input type="hidden" name="entry_id" id="entry-id">

					<div class="wptsall-form-row">
						<label><?php esc_html_e( 'Context', 'wpmmcc-ats' ); ?></label>
						<input type="text" id="entry-msgctxt" readonly class="regular-text">
					</div>

					<div class="wptsall-form-row">
						<label><?php esc_html_e( 'Source Text', 'wpmmcc-ats' ); ?></label>
						<textarea id="entry-msgid" rows="3" readonly class="large-text"></textarea>
					</div>

					<div class="wptsall-form-row" id="entry-msgid-plural-row" style="display:none;">
						<label><?php esc_html_e( 'Source Text (Plural)', 'wpmmcc-ats' ); ?></label>
						<textarea id="entry-msgid-plural" rows="2" readonly class="large-text"></textarea>
					</div>

					<div class="wptsall-form-row">
						<label for="entry-msgstr"><?php esc_html_e( 'Translation', 'wpmmcc-ats' ); ?></label>
						<textarea name="msgstr" id="entry-msgstr" rows="3" class="large-text"></textarea>
					</div>

					<div class="wptsall-form-row" id="entry-msgstr-plural-row" style="display:none;">
						<label for="entry-msgstr-plural"><?php esc_html_e( 'Translation (Plural)', 'wpmmcc-ats' ); ?></label>
						<textarea name="msgstr_plural" id="entry-msgstr-plural" rows="2" class="large-text"></textarea>
					</div>

					<div class="wptsall-form-row">
						<label for="entry-status"><?php esc_html_e( 'Status', 'wpmmcc-ats' ); ?></label>
						<select name="status" id="entry-status">
							<option value="pending"><?php esc_html_e( 'Pending', 'wpmmcc-ats' ); ?></option>
							<option value="translated"><?php esc_html_e( 'Translated', 'wpmmcc-ats' ); ?></option>
							<option value="reviewed"><?php esc_html_e( 'Reviewed', 'wpmmcc-ats' ); ?></option>
							<option value="skipped"><?php esc_html_e( 'Skipped', 'wpmmcc-ats' ); ?></option>
						</select>
					</div>

					<div class="wptsall-form-row">
						<label for="entry-note"><?php esc_html_e( 'Note', 'wpmmcc-ats' ); ?></label>
						<textarea name="note" id="entry-note" rows="2" class="large-text"></textarea>
					</div>

					<div class="wptsall-form-row" id="entry-reference-row">
						<label><?php esc_html_e( 'Reference', 'wpmmcc-ats' ); ?></label>
						<div id="entry-reference" class="wptsall-reference-list"></div>
					</div>

					<div class="wptsall-form-actions">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'wpmmcc-ats' ); ?></button>
						<button type="button" class="button wptsall-modal-cancel"><?php esc_html_e( 'Cancel', 'wpmmcc-ats' ); ?></button>
					</div>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Render import modal
	 */
	private static function render_import_modal() {
		?>
		<div id="wptsall-import-modal" class="wptsall-modal" style="display:none;">
			<div class="wptsall-modal-content">
				<span class="wptsall-modal-close">&times;</span>
				<h2><?php esc_html_e( 'Import PO File', 'wpmmcc-ats' ); ?></h2>

				<form id="wptsall-import-form">
					<div class="wptsall-form-row">
						<label for="import-file"><?php esc_html_e( 'Choose File', 'wpmmcc-ats' ); ?></label>
						<input type="file" id="import-file" accept=".po,.pot">
					</div>

					<div class="wptsall-form-row">
						<p class="description">
							<?php esc_html_e( 'Import will update existing entry translations and add new entries.', 'wpmmcc-ats' ); ?>
						</p>
					</div>

					<div class="wptsall-form-actions">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Import', 'wpmmcc-ats' ); ?></button>
						<button type="button" class="button wptsall-modal-cancel"><?php esc_html_e( 'Cancel', 'wpmmcc-ats' ); ?></button>
					</div>
				</form>

				<div id="wptsall-import-result" style="display:none;">
					<h3><?php esc_html_e( 'Import Result', 'wpmmcc-ats' ); ?></h3>
					<p class="wptsall-import-stats"></p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Truncate text
	 *
	 * @param string $text   Text
	 * @param int    $length Length
	 * @return string Truncated text
	 */
	private static function truncate( $text, $length = 100 ) {
		if ( mb_strlen( $text ) <= $length ) {
			return $text;
		}
		return mb_substr( $text, 0, $length ) . '...';
	}
}

<?php
/**
 * Taxonomy Translation Page (P5-5)
 *
 * Admin page where users link category / tag / custom-taxonomy terms
 * to their translation counterparts across languages.
 *
 * @package WPTSALL\ManualTranslation\Admin
 * @since 1.4.0
 */

namespace WPTSALL\ManualTranslation\Admin;

use WPTSALL\Admin\Admin_Page_Helper;
use WPTSALL\Languages\Services\Language_Service;
use WPTSALL\ManualTranslation\Services\Taxonomy_Translation_Service;
use WPTSALL\Sites\Services\Site_Relation_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Taxonomy_Translation_Page {

	const PAGE_SLUG = 'wptsall-tax-translations';
	const CAP       = 'manage_wptsall_translations';
	const NONCE_LINK   = 'wptsall_tax_link';
	const NONCE_UNLINK = 'wptsall_tax_unlink';

	public static function init() {
		add_action( 'admin_post_wptsall_tax_link',   array( __CLASS__, 'handle_link' ) );
		add_action( 'admin_post_wptsall_tax_unlink', array( __CLASS__, 'handle_unlink' ) );
	}

	public static function add_menu_page() {
		add_submenu_page(
			'wptsall-manual',
			__( 'Taxonomy Translations', 'wpmmcc-ats' ),
			__( 'Taxonomies', 'wpmmcc-ats' ),
			self::CAP,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$languages = Language_Service::get_all( array( 'status' => 'active' ) );
		$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$taxonomy   = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : 'category';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$target_lang = isset( $_GET['target_lang'] ) ? sanitize_text_field( wp_unslash( $_GET['target_lang'] ) ) : ( $languages[0]['code'] ?? '' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$relation_id = isset( $_GET['relation_id'] ) ? absint( wp_unslash( $_GET['relation_id'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filter = isset( $_GET['filter'] ) ? sanitize_text_field( wp_unslash( $_GET['filter'] ) ) : 'pending';

		$relations = class_exists( Site_Relation_Service::class ) ? Site_Relation_Service::get_all_relations( array( 'status' => 'active' ), false ) : array();
		$relation_options = array();
		$selected_relation = null;
		foreach ( (array) $relations as $relation ) {
			$rel_id = (int) ( $relation['id'] ?? 0 );
			$rel_lang = (string) ( $relation['target_lang'] ?? '' );
			if ( $rel_id <= 0 || '' === $rel_lang ) {
				continue;
			}
			if ( $relation_id > 0 && $rel_id === $relation_id ) {
				$selected_relation = $relation;
				$target_lang = $rel_lang;
			}
			if ( '' === $target_lang || strtolower( str_replace( '-', '_', $rel_lang ) ) === strtolower( str_replace( '-', '_', $target_lang ) ) ) {
				$relation_options[] = $relation;
			}
		}
		if ( $relation_id > 0 && ! $selected_relation ) {
			$relation_id = 0;
		}

		if ( ! taxonomy_exists( $taxonomy ) ) {
			$taxonomy = 'category';
		}

		$pending = ( $filter === 'pending' )
			? Taxonomy_Translation_Service::pending_terms( $taxonomy, $target_lang, 500, $relation_id )
			: array();

		// For "all" view, list every term in the taxonomy.
		$all_terms = ( $filter === 'all' ) ? get_terms( array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'number'     => 500,
		) ) : array();
		if ( is_wp_error( $all_terms ) ) {
			$all_terms = array();
		}

		Admin_Page_Helper::render_header(
			__( 'Taxonomy Translations', 'wpmmcc-ats' ),
			__( 'Link category, tag, or custom-taxonomy terms to their translation counterparts.', 'wpmmcc-ats' )
		);
		?>
		<form method="get" style="margin-bottom:12px;">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
			<label><?php esc_html_e( 'Taxonomy', 'wpmmcc-ats' ); ?>:
				<select name="taxonomy" onchange="this.form.submit()">
					<?php foreach ( $taxonomies as $tax ) : ?>
						<option value="<?php echo esc_attr( $tax->name ); ?>" <?php selected( $taxonomy, $tax->name ); ?>><?php echo esc_html( $tax->labels->name ?? $tax->name ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label style="margin-left:12px;"><?php esc_html_e( 'Target language', 'wpmmcc-ats' ); ?>:
				<select name="target_lang" onchange="if (this.form.relation_id) { this.form.relation_id.value='0'; } this.form.submit()">
					<?php foreach ( $languages as $lang ) : ?>
						<option value="<?php echo esc_attr( $lang['code'] ); ?>" <?php selected( $target_lang, $lang['code'] ); ?>><?php echo esc_html( $lang['code'] ); ?> — <?php echo esc_html( $lang['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label style="margin-left:12px;"><?php esc_html_e( 'Target relation', 'wpmmcc-ats' ); ?>:
				<select name="relation_id" onchange="this.form.submit()">
					<option value="0" <?php selected( $relation_id, 0 ); ?>><?php esc_html_e( 'Auto by language', 'wpmmcc-ats' ); ?></option>
					<?php foreach ( $relation_options as $rel ) : ?>
						<?php
						$rel_id = (int) ( $rel['id'] ?? 0 );
						$rel_label = sprintf(
							'#%d %s → %s (%s:%s)',
							$rel_id,
							(string) ( $rel['source_lang'] ?? '' ),
							(string) ( $rel['target_lang'] ?? '' ),
							(string) ( $rel['target_site_type'] ?? '' ),
							(string) ( $rel['target_site_id'] ?? '' )
						);
						?>
						<option value="<?php echo esc_attr( $rel_id ); ?>" <?php selected( $relation_id, $rel_id ); ?>><?php echo esc_html( $rel_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label style="margin-left:12px;"><?php esc_html_e( 'Show', 'wpmmcc-ats' ); ?>:
				<select name="filter" onchange="this.form.submit()">
					<option value="pending" <?php selected( $filter, 'pending' ); ?>><?php esc_html_e( 'Pending (not yet linked)', 'wpmmcc-ats' ); ?></option>
					<option value="all" <?php selected( $filter, 'all' ); ?>><?php esc_html_e( 'All terms', 'wpmmcc-ats' ); ?></option>
				</select>
			</label>
		</form>

		<?php if ( 'pending' === $filter ) : ?>
			<p><strong><?php echo count( $pending ); ?></strong> <?php esc_html_e( 'pending term(s)', 'wpmmcc-ats' ); ?> <?php esc_html_e( 'for', 'wpmmcc-ats' ); ?> <code><?php echo esc_html( $taxonomy ); ?></code> → <code><?php echo esc_html( $target_lang ); ?></code><?php if ( $relation_id > 0 ) : ?> · <?php esc_html_e( 'relation', 'wpmmcc-ats' ); ?> <code>#<?php echo (int) $relation_id; ?></code><?php endif; ?></p>
			<?php if ( empty( $pending ) ) : ?>
				<p><?php esc_html_e( 'All terms are already linked. Switch to "All terms" to manage existing links.', 'wpmmcc-ats' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Source term', 'wpmmcc-ats' ); ?></th>
							<th><?php esc_html_e( 'Used in posts', 'wpmmcc-ats' ); ?></th>
							<th style="width:50%;"><?php esc_html_e( 'Link to existing target term', 'wpmmcc-ats' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $pending as $t ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( $t['name'] ); ?></strong><br>
								<small><code><?php echo esc_html( $t['slug'] ); ?></code> · id=<?php echo (int) $t['term_id']; ?></small>
							</td>
							<td><?php echo (int) $t['count']; ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:6px;">
									<?php wp_nonce_field( self::NONCE_LINK ); ?>
									<input type="hidden" name="action" value="wptsall_tax_link">
									<input type="hidden" name="source_term_id" value="<?php echo (int) $t['term_id']; ?>">
									<input type="hidden" name="source_taxonomy" value="<?php echo esc_attr( $taxonomy ); ?>">
									<input type="hidden" name="target_lang" value="<?php echo esc_attr( $target_lang ); ?>">
									<input type="hidden" name="relation_id" value="<?php echo (int) $relation_id; ?>">
									<input type="text" name="target_term_search" placeholder="<?php esc_attr_e( 'Search target term name or slug…', 'wpmmcc-ats' ); ?>" style="flex:1;">
									<button class="button" type="submit"><?php esc_html_e( 'Link', 'wpmmcc-ats' ); ?></button>
								</form>
								<small><?php esc_html_e( 'Tip: paste the target term slug (e.g. "my-term") for exact match.', 'wpmmcc-ats' ); ?></small>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		<?php else : ?>
			<table class="wp-list-table widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Source term', 'wpmmcc-ats' ); ?></th>
						<th><?php esc_html_e( 'Translations', 'wpmmcc-ats' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( (array) $all_terms as $t ) :
					$trs = Taxonomy_Translation_Service::get_translations( (int) $t->term_id, $taxonomy, $relation_id );
				?>
					<tr>
						<td>
							<strong><?php echo esc_html( $t->name ); ?></strong>
							<small>(id=<?php echo (int) $t->term_id; ?>)</small>
						</td>
						<td>
							<?php if ( empty( $trs ) ) : ?>
								<em style="color:#999;"><?php esc_html_e( 'No translations', 'wpmmcc-ats' ); ?></em>
							<?php else : ?>
								<?php foreach ( $trs as $code => $info ) : ?>
									<div style="display:flex;gap:8px;align-items:center;margin-bottom:4px;">
										<code><?php echo esc_html( $code ); ?></code>
										<span>→ #<?php echo (int) $info['target_term_id']; ?><?php if ( ! empty( $info['relation_id'] ) ) : ?> · relation #<?php echo (int) $info['relation_id']; ?><?php endif; ?></span>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
											<?php wp_nonce_field( self::NONCE_UNLINK ); ?>
											<input type="hidden" name="action" value="wptsall_tax_unlink">
											<input type="hidden" name="source_term_id" value="<?php echo (int) $t->term_id; ?>">
											<input type="hidden" name="source_taxonomy" value="<?php echo esc_attr( $taxonomy ); ?>">
											<input type="hidden" name="target_lang" value="<?php echo esc_attr( (string) ( $info['target_lang'] ?? $code ) ); ?>">
											<input type="hidden" name="relation_id" value="<?php echo (int) ( $info['relation_id'] ?? $relation_id ); ?>">
											<button class="button-link" type="submit" style="color:#dc3232;"><?php esc_html_e( 'Unlink', 'wpmmcc-ats' ); ?></button>
										</form>
									</div>
								<?php endforeach; ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif;
		Admin_Page_Helper::render_footer();
	}

	/**
	 * Handle "Link source term to target term" form submit.
	 *
	 * The target term is resolved from a free-text input — admin can paste
	 * the target slug, term id, or term name.
	 */
	public static function handle_link() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Forbidden', 'wpmmcc-ats' ) );
		}
		check_admin_referer( self::NONCE_LINK );
		$source_term_id  = isset( $_POST['source_term_id'] )  ? (int) $_POST['source_term_id']  : 0; // phpcs:ignore
		$source_taxonomy = isset( $_POST['source_taxonomy'] ) ? sanitize_key( (string) $_POST['source_taxonomy'] ) : ''; // phpcs:ignore
		$target_lang     = isset( $_POST['target_lang'] )     ? sanitize_text_field( (string) $_POST['target_lang'] ) : ''; // phpcs:ignore
		$relation_id     = isset( $_POST['relation_id'] )     ? absint( $_POST['relation_id'] ) : 0; // phpcs:ignore
		$search          = isset( $_POST['target_term_search'] ) ? sanitize_text_field( (string) $_POST['target_term_search'] ) : ''; // phpcs:ignore

		$target_term_id = self::resolve_target_term( $search, $source_taxonomy );
		if ( $target_term_id <= 0 ) {
			wp_safe_redirect( add_query_arg( array(
				'page'         => self::PAGE_SLUG,
				'taxonomy'     => $source_taxonomy,
				'target_lang'  => $target_lang,
				'relation_id'  => $relation_id,
				'wptsall_msg'  => 'term_not_found',
			), admin_url( 'admin.php' ) ) );
			exit;
		}
		Taxonomy_Translation_Service::link( $source_term_id, $source_taxonomy, $target_term_id, $target_lang, $relation_id );

		wp_safe_redirect( add_query_arg( array(
			'page'        => self::PAGE_SLUG,
			'taxonomy'    => $source_taxonomy,
			'target_lang' => $target_lang,
			'relation_id' => $relation_id,
			'wptsall_msg' => 'linked',
		), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_unlink() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Forbidden', 'wpmmcc-ats' ) );
		}
		check_admin_referer( self::NONCE_UNLINK );
		$source_term_id  = isset( $_POST['source_term_id'] )  ? (int) $_POST['source_term_id']  : 0; // phpcs:ignore
		$source_taxonomy = isset( $_POST['source_taxonomy'] ) ? sanitize_key( (string) $_POST['source_taxonomy'] ) : ''; // phpcs:ignore
		$target_lang     = isset( $_POST['target_lang'] )     ? sanitize_text_field( (string) $_POST['target_lang'] ) : ''; // phpcs:ignore
		$relation_id     = isset( $_POST['relation_id'] )     ? absint( $_POST['relation_id'] ) : 0; // phpcs:ignore
		Taxonomy_Translation_Service::unlink( $source_term_id, $source_taxonomy, $target_lang, $relation_id );
		wp_safe_redirect( add_query_arg( array(
			'page'        => self::PAGE_SLUG,
			'taxonomy'    => $source_taxonomy,
			'target_lang' => $target_lang,
			'relation_id' => $relation_id,
			'filter'      => 'all',
			'wptsall_msg' => 'unlinked',
		), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Resolve target term id from a free-text input (slug, name, or id).
	 */
	private static function resolve_target_term( string $search, string $taxonomy ): int {
		$search = trim( $search );
		if ( '' === $search ) {
			return 0;
		}
		// Try id first.
		if ( ctype_digit( $search ) ) {
			$term = get_term( (int) $search, $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				return (int) $term->term_id;
			}
		}
		// Try slug.
		$term = get_term_by( 'slug', $search, $taxonomy );
		if ( $term && ! is_wp_error( $term ) ) {
			return (int) $term->term_id;
		}
		// Try name.
		$term = get_term_by( 'name', $search, $taxonomy );
		if ( $term && ! is_wp_error( $term ) ) {
			return (int) $term->term_id;
		}
		return 0;
	}
}

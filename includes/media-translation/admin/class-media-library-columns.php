<?php
/**
 * Media library columns + attachment / featured mapping UI (B5).
 *
 * Polylang media_support / WPML Media language column pattern:
 * show virtual-site / mapping context on the Media list and edit screens.
 *
 * @package WPTSALL\MediaTranslation\Admin
 * @since 2.2.0
 */

namespace WPTSALL\MediaTranslation\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Media_Library_Columns class.
 */
class Media_Library_Columns {

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! is_admin() ) {
			return;
		}
		add_filter( 'manage_upload_columns', array( __CLASS__, 'add_columns' ) );
		add_action( 'manage_media_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_filter( 'manage_upload_sortable_columns', array( __CLASS__, 'sortable_columns' ) );
		add_action( 'add_meta_boxes_attachment', array( __CLASS__, 'add_attachment_metabox' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_featured_metabox' ) );
	}

	/**
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function add_columns( $columns ) {
		$columns['wptsall_site'] = __( 'WPTSALL site', 'wpmmcc-ats' );
		$columns['wptsall_map']  = __( 'Translation map', 'wpmmcc-ats' );
		return $columns;
	}

	/**
	 * @param array $columns Sortable.
	 * @return array
	 */
	public static function sortable_columns( $columns ) {
		return $columns;
	}

	/**
	 * @param string $column_name Column.
	 * @param int    $post_id     Attachment ID.
	 * @return void
	 */
	public static function render_column( $column_name, $post_id ) {
		$post_id = (int) $post_id;
		if ( 'wptsall_site' === $column_name ) {
			$vs = \WPTSALL\Sites\Services\Translation_Identity::raw_meta( (int) $post_id, \WPTSALL\Sites\Services\Translation_Identity::META_VIRTUAL_SITE_ID );
			$src = \WPTSALL\Sites\Services\Translation_Identity::raw_meta( (int) $post_id, \WPTSALL\Sites\Services\Translation_Identity::META_SOURCE_POST_ID );
			if ( $vs ) {
				echo '<code>' . esc_html( (string) $vs ) . '</code>';
				if ( $src ) {
					echo '<br><span class="description">' . esc_html(
						sprintf(
							/* translators: %d: source attachment ID */
							__( 'from #%d', 'wpmmcc-ats' ),
							(int) $src
						)
					) . '</span>';
				}
			} else {
				echo '<span class="description">—</span>';
			}
			return;
		}
		if ( 'wptsall_map' === $column_name ) {
			$rows = self::lookup_mappings( $post_id );
			if ( empty( $rows ) ) {
				echo '<span class="description">—</span>';
				return;
			}
			$bits = array();
			foreach ( array_slice( $rows, 0, 3 ) as $row ) {
				$tgt_site = (string) ( $row['target_site_id'] ?? '' );
				$tgt_id   = (int) ( $row['target_media_id'] ?? 0 );
				$src_id   = (int) ( $row['source_media_id'] ?? 0 );
				if ( $src_id === $post_id && $tgt_id > 0 ) {
					$bits[] = esc_html( $tgt_site . ' → #' . $tgt_id );
				} elseif ( $tgt_id === $post_id && $src_id > 0 ) {
					$bits[] = esc_html( '#' . $src_id . ' → ' . $tgt_site );
				}
			}
			echo $bits ? implode( '<br>', $bits ) : '<span class="description">—</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * @return void
	 */
	public static function add_attachment_metabox() {
		add_meta_box(
			'wptsall-media-mapping',
			__( 'WPTSALL media mapping', 'wpmmcc-ats' ),
			array( __CLASS__, 'render_attachment_metabox' ),
			'attachment',
			'side',
			'default'
		);
	}

	/**
	 * Featured image mapping on post types that support thumbnails.
	 *
	 * @return void
	 */
	public static function add_featured_metabox() {
		$types = get_post_types_by_support( 'thumbnail' );
		foreach ( (array) $types as $type ) {
			if ( 'attachment' === $type ) {
				continue;
			}
			add_meta_box(
				'wptsall-featured-mapping',
				__( 'WPTSALL featured image map', 'wpmmcc-ats' ),
				array( __CLASS__, 'render_featured_metabox' ),
				$type,
				'side',
				'low'
			);
		}
	}

	/**
	 * @param \WP_Post $post Attachment.
	 * @return void
	 */
	public static function render_attachment_metabox( $post ) {
		$id  = (int) $post->ID;
		$vs  = \WPTSALL\Sites\Services\Translation_Identity::raw_meta( (int) $id, \WPTSALL\Sites\Services\Translation_Identity::META_VIRTUAL_SITE_ID );
		$src = \WPTSALL\Sites\Services\Translation_Identity::raw_meta( (int) $id, \WPTSALL\Sites\Services\Translation_Identity::META_SOURCE_POST_ID );
		echo '<p><strong>' . esc_html__( 'Virtual site', 'wpmmcc-ats' ) . ':</strong> ';
		echo $vs ? '<code>' . esc_html( (string) $vs ) . '</code>' : '—';
		echo '</p>';
		if ( $src ) {
			$edit = get_edit_post_link( (int) $src );
			echo '<p><strong>' . esc_html__( 'Source attachment', 'wpmmcc-ats' ) . ':</strong> ';
			if ( $edit ) {
				echo '<a href="' . esc_url( $edit ) . '">#' . (int) $src . '</a>';
			} else {
				echo '#' . (int) $src;
			}
			echo '</p>';
		}
		$rows = self::lookup_mappings( $id );
		if ( empty( $rows ) ) {
			echo '<p class="description">' . esc_html__( 'No rows in media_mappings for this attachment.', 'wpmmcc-ats' ) . '</p>';
			return;
		}
		echo '<ul style="margin:0;padding-left:1.2em;">';
		foreach ( $rows as $row ) {
			printf(
				'<li><code>%s</code> #%d → #%d</li>',
				esc_html( (string) ( $row['target_site_id'] ?? '' ) ),
				(int) ( $row['source_media_id'] ?? 0 ),
				(int) ( $row['target_media_id'] ?? 0 )
			);
		}
		echo '</ul>';
		$media_page = admin_url( 'admin.php?page=wptsall-media&src=' . $id );
		echo '<p><a href="' . esc_url( $media_page ) . '">' . esc_html__( 'Open Media Translations', 'wpmmcc-ats' ) . '</a></p>';
	}

	/**
	 * @param \WP_Post $post Post.
	 * @return void
	 */
	public static function render_featured_metabox( $post ) {
		$thumb = (int) get_post_thumbnail_id( $post );
		if ( $thumb <= 0 ) {
			echo '<p class="description">' . esc_html__( 'No featured image set.', 'wpmmcc-ats' ) . '</p>';
			return;
		}
		$edit = get_edit_post_link( $thumb );
		echo '<p><strong>' . esc_html__( 'Featured attachment', 'wpmmcc-ats' ) . ':</strong> ';
		if ( $edit ) {
			echo '<a href="' . esc_url( $edit ) . '">' . esc_html( '#' . (string) $thumb ) . '</a>';
		} else {
			echo esc_html( '#' . (string) $thumb );
		}
		echo '</p>';
		$vs  = \WPTSALL\Sites\Services\Translation_Identity::raw_meta( (int) $thumb, \WPTSALL\Sites\Services\Translation_Identity::META_VIRTUAL_SITE_ID );
		$src = \WPTSALL\Sites\Services\Translation_Identity::raw_meta( (int) $thumb, \WPTSALL\Sites\Services\Translation_Identity::META_SOURCE_POST_ID );
		if ( $vs || $src ) {
			echo '<p class="description">';
			if ( $vs ) {
				echo esc_html__( 'Site', 'wpmmcc-ats' ) . ': <code>' . esc_html( (string) $vs ) . '</code> ';
			}
			if ( $src ) {
				echo esc_html__( 'Source', 'wpmmcc-ats' ) . ': ' . esc_html( '#' . (string) (int) $src );
			}
			echo '</p>';
		}
		$rows = self::lookup_mappings( $thumb );
		if ( ! empty( $rows ) ) {
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: %d: mapping count */
					_n( '%d media mapping', '%d media mappings', count( $rows ), 'wpmmcc-ats' ),
					count( $rows )
				)
			) . '</p>';
		}
	}

	/**
	 * Lookup media_mappings rows where this ID is source or target.
	 *
	 * @param int $media_id Attachment ID.
	 * @return array<int,array>
	 */
	public static function lookup_mappings( int $media_id ): array {
		if ( $media_id <= 0 || ! function_exists( 'wptsall_table' ) ) {
			return array();
		}
		global $wpdb;
		$table = wptsall_table( 'media_mappings' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT source_media_id, target_media_id, target_site_id, source_site_id
				 FROM %i
				 WHERE source_media_id = %d OR target_media_id = %d
				 ORDER BY id DESC
				 LIMIT 20',
				$table,
				$media_id,
				$media_id
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}
}

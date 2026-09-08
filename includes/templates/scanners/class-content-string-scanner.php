<?php
/**
 * Content String Scanner
 *
 * Scans translatable strings from database content
 *
 * @package WPTSALL\Templates
 * @since 0.7.0
 */

namespace WPTSALL\Templates\Scanners;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Content_String_Scanner class
 *
 * Scans translatable strings from database
 *
 * Two scanning modes:
 * - scan_config_content(): Scans non-user-published config content (widgets, menus, site identity, reusable blocks)
 * - scan_site_content():   Scans user-published content (post titles, term names, etc.), handled by sync task path
 */
class Content_String_Scanner {

	/**
	 * Database object
	 *
	 * @var \wpdb
	 */
	private $wpdb;

	/**
	 * Constructor
	 */
	public function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;
	}

	/**
	 * Scan site content
	 *
	 * @param array $post_types Post types to scan
	 * @param array $taxonomies Taxonomies to scan
	 * @return array Scanned translatable strings
	 */
	public function scan_site_content( $post_types = array(), $taxonomies = array() ) {
		$entries = array();

		// If not specified, use default public types
		if ( empty( $post_types ) ) {
			$post_types = get_post_types( array( 'public' => true ), 'names' );
		}

		if ( empty( $taxonomies ) ) {
			$taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
		}

		wptsall_log_info(
			'templates-scanner',
			'Starting content string scan',
			array(
				'post_types' => array_values( $post_types ),
				'taxonomies' => array_values( $taxonomies ),
			)
		);

		// Scan post content
		$post_entries = $this->scan_posts( $post_types );
		$entries      = array_merge( $entries, $post_entries );

		// Scan taxonomies
		$term_entries = $this->scan_terms( $taxonomies );
		$entries      = array_merge( $entries, $term_entries );

		// Scan menu items
		$menu_entries = $this->scan_menus();
		$entries      = array_merge( $entries, $menu_entries );

		// Scan widgets
		$widget_entries = $this->scan_widgets();
		$entries        = array_merge( $entries, $widget_entries );

		wptsall_log_info(
			'templates-scanner',
			'Content string scan completed',
			array(
				'total_entries'  => count( $entries ),
				'post_entries'   => count( $post_entries ),
				'term_entries'   => count( $term_entries ),
				'menu_entries'   => count( $menu_entries ),
				'widget_entries' => count( $widget_entries ),
			)
		);

		return $this->deduplicate_entries( $entries );
	}

	/**
	 * Scan post content
	 *
	 * @param array $post_types Post types to scan
	 * @return array
	 */
	private function scan_posts( $post_types ) {
		$entries = array();

		if ( empty( $post_types ) ) {
			return $entries;
		}

		$post_types = array_values( array_filter( array_map( 'strval', (array) $post_types ) ) );
		if ( empty( $post_types ) ) {
			return $entries;
		}

		$batch_size   = 500;
		$last_id      = 0;
		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$posts_table  = $this->wpdb->posts;

		do {
			$prepare_args = array_merge(
				array( $posts_table ),
				array_values( $post_types ),
				array( (int) $last_id, (int) $batch_size )
			);
			// Dynamic IN() uses only %s placeholders; table via %i.
			$sql   = "SELECT ID, post_type, post_title, post_excerpt
				FROM %i
				WHERE post_type IN ($placeholders)
				AND post_status = 'publish'
				AND (post_title != '' OR post_excerpt != '')
				AND ID > %d
				ORDER BY ID ASC
				LIMIT %d";
			$posts = wptsall_db_get_results( $sql, $prepare_args );

			if ( empty( $posts ) ) {
				break;
			}

			foreach ( $posts as $post ) {
				$last_id = $post->ID;

				// Post title
				if ( ! empty( $post->post_title ) && self::is_translatable( $post->post_title ) ) {
					$entries[] = array(
						'msgid'        => $post->post_title,
						'msgid_plural' => '',
						'msgctxt'      => 'post_title',
						'reference'    => "post:{$post->post_type}:{$post->ID}",
						'source'       => 'content_scan',
						'content_type' => 'post_title',
						'object_id'    => $post->ID,
					);
				}

				// Post excerpt
				if ( ! empty( $post->post_excerpt ) && self::is_translatable( $post->post_excerpt ) ) {
					$entries[] = array(
						'msgid'        => $post->post_excerpt,
						'msgid_plural' => '',
						'msgctxt'      => 'post_excerpt',
						'reference'    => "post:{$post->post_type}:{$post->ID}",
						'source'       => 'content_scan',
						'content_type' => 'post_excerpt',
						'object_id'    => $post->ID,
					);
				}
			}
		} while ( count( $posts ) >= $batch_size );

		return $entries;
	}

	/**
	 * Scan taxonomy terms
	 *
	 * @param array $taxonomies Taxonomies to scan
	 * @return array
	 */
	private function scan_terms( $taxonomies ) {
		$entries = array();

		if ( empty( $taxonomies ) ) {
			return $entries;
		}

		foreach ( $taxonomies as $taxonomy ) {
			$terms = get_terms( array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 1000,
			) );

			if ( is_wp_error( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				// Term name
				if ( ! empty( $term->name ) && self::is_translatable( $term->name ) ) {
					$entries[] = array(
						'msgid'        => $term->name,
						'msgid_plural' => '',
						'msgctxt'      => 'term_name',
						'reference'    => "term:{$taxonomy}:{$term->term_id}",
						'source'       => 'content_scan',
						'content_type' => 'term_name',
						'object_id'    => $term->term_id,
					);
				}

				// Term description
				if ( ! empty( $term->description ) && self::is_translatable( $term->description ) ) {
					$entries[] = array(
						'msgid'        => $term->description,
						'msgid_plural' => '',
						'msgctxt'      => 'term_description',
						'reference'    => "term:{$taxonomy}:{$term->term_id}",
						'source'       => 'content_scan',
						'content_type' => 'term_description',
						'object_id'    => $term->term_id,
					);
				}
			}
		}

		return $entries;
	}

	/**
	 * Scan navigation menus
	 *
	 * @return array
	 */
	private function scan_menus() {
		$entries = array();

		$menus = wp_get_nav_menus();

		foreach ( $menus as $menu ) {
			// Menu name
			if ( ! empty( $menu->name ) && self::is_translatable( $menu->name ) ) {
				$entries[] = array(
					'msgid'        => $menu->name,
					'msgid_plural' => '',
					'msgctxt'      => 'menu_name',
					'reference'    => "menu:{$menu->term_id}",
					'source'       => 'content_scan',
					'content_type' => 'menu_name',
					'object_id'    => $menu->term_id,
				);
			}

			// Menu items
			$menu_items = wp_get_nav_menu_items( $menu->term_id );

			if ( $menu_items ) {
				foreach ( $menu_items as $item ) {
					// Custom title
					if ( ! empty( $item->title ) && self::is_translatable( $item->title ) ) {
						$entries[] = array(
							'msgid'        => $item->title,
							'msgid_plural' => '',
							'msgctxt'      => 'menu_item_title',
							'reference'    => "menu_item:{$menu->term_id}:{$item->ID}",
							'source'       => 'content_scan',
							'content_type' => 'menu_item_title',
							'object_id'    => $item->ID,
						);
					}

					// Custom attribute title
					if ( ! empty( $item->attr_title ) && self::is_translatable( $item->attr_title ) ) {
						$entries[] = array(
							'msgid'        => $item->attr_title,
							'msgid_plural' => '',
							'msgctxt'      => 'menu_item_attr_title',
							'reference'    => "menu_item:{$menu->term_id}:{$item->ID}",
							'source'       => 'content_scan',
							'content_type' => 'menu_item_attr_title',
							'object_id'    => $item->ID,
						);
					}
				}
			}
		}

		return $entries;
	}

	/**
	 * Scan widgets
	 *
	 * @return array
	 */
	private function scan_widgets() {
		$entries = array();

		$sidebars_widgets = get_option( 'sidebars_widgets', array() );

		foreach ( $sidebars_widgets as $sidebar_id => $widgets ) {
			if ( 'wp_inactive_widgets' === $sidebar_id || ! is_array( $widgets ) ) {
				continue;
			}

			foreach ( $widgets as $widget_id ) {
				// Parse widget ID to get widget type and instance number
				if ( preg_match( '/^(.+)-(\d+)$/', $widget_id, $matches ) ) {
					$widget_type     = $matches[1];
					$widget_instance = (int) $matches[2];

					$widget_options = get_option( 'widget_' . $widget_type );

					if ( isset( $widget_options[ $widget_instance ] ) ) {
						$instance = $widget_options[ $widget_instance ];

						// Widget title
						if ( ! empty( $instance['title'] ) && self::is_translatable( $instance['title'] ) ) {
							$entries[] = array(
								'msgid'        => $instance['title'],
								'msgid_plural' => '',
								'msgctxt'      => 'widget_title',
								'reference'    => "widget:{$widget_type}:{$widget_instance}",
								'source'       => 'content_scan',
								'content_type' => 'widget_title',
								'object_id'    => $widget_id,
							);
						}

						// Text widget content
						if ( 'text' === $widget_type && ! empty( $instance['text'] ) ) {
							if ( self::is_translatable( $instance['text'] ) ) {
								$entries[] = array(
									'msgid'        => $instance['text'],
									'msgid_plural' => '',
									'msgctxt'      => 'widget_text',
									'reference'    => "widget:{$widget_type}:{$widget_instance}",
									'source'       => 'content_scan',
									'content_type' => 'widget_text',
									'object_id'    => $widget_id,
								);
							}
						}
					}
				}
			}
		}

		return $entries;
	}

	/**
	 * Scan site configuration content (non-user-published)
	 *
	 * Scans admin-configured database content displayed on the frontend:
	 * - Site identity (site name, tagline)
	 * - Widget titles and text content
	 * - Navigation menu names and menu item titles
	 * - Reusable block titles
	 *
	 * These are not in .pot files and do not go through the sync task path;
	 * they need to be translated via the i18n template system.
	 *
	 * @since 2.1.0
	 * @return array Scanned translatable strings
	 */
	public function scan_config_content() {
		$entries = array();

		// Layer B (menus, widgets, site identity) → wptsall_strings via Site_String_Scanner.
		// Here we only scan reusable blocks for config_i18n (Layer C option-like content).
		$block_entries = $this->scan_reusable_blocks();
		$entries       = array_merge( $entries, $block_entries );

		wptsall_log_info(
			'templates-scanner',
			'Config content scan completed (reusable blocks only; Layer B uses wptsall_strings)',
			array(
				'total_entries'  => count( $entries ),
				'block_entries'  => count( $block_entries ),
			)
		);

		return $this->deduplicate_entries( $entries );
	}

	/**
	 * Scan site identity (site name, tagline)
	 *
	 * @since 2.1.0
	 * @return array
	 */
	private function scan_site_identity() {
		$entries = array();

		$blogname = get_option( 'blogname' );
		if ( ! empty( $blogname ) && self::is_translatable( $blogname ) ) {
			$entries[] = array(
				'msgid'        => $blogname,
				'msgid_plural' => '',
				'msgctxt'      => 'site_title',
				'reference'    => 'option:blogname',
				'source'       => 'config_scan',
				'content_type' => 'site_title',
			);
		}

		$blogdescription = get_option( 'blogdescription' );
		if ( ! empty( $blogdescription ) && self::is_translatable( $blogdescription ) ) {
			$entries[] = array(
				'msgid'        => $blogdescription,
				'msgid_plural' => '',
				'msgctxt'      => 'site_tagline',
				'reference'    => 'option:blogdescription',
				'source'       => 'config_scan',
				'content_type' => 'site_tagline',
			);
		}

		return $entries;
	}

	/**
	 * Scan reusable block titles
	 *
	 * wp_block is the WordPress reusable block post type,
	 * created by admins and reusable across multiple pages; this is config-type content.
	 *
	 * @since 2.1.0
	 * @return array
	 */
	private function scan_reusable_blocks() {
		$entries = array();

		$blocks = get_posts( array(
			'post_type'   => 'wp_block',
			'post_status' => 'publish',
			'numberposts' => 200,
		) );

		foreach ( $blocks as $block ) {
			if ( ! empty( $block->post_title ) && self::is_translatable( $block->post_title ) ) {
				$entries[] = array(
					'msgid'        => $block->post_title,
					'msgid_plural' => '',
					'msgctxt'      => 'reusable_block_title',
					'reference'    => "wp_block:{$block->ID}",
					'source'       => 'config_scan',
					'content_type' => 'reusable_block_title',
				);
			}
		}

		return $entries;
	}

	/**
	 * Check whether a string is worth translating
	 *
	 * @param string $str String to check
	 * @return bool
	 */
	public static function is_translatable( $str ) {
		// Empty strings do not need translation
		if ( empty( $str ) ) {
			return false;
		}

		// Too short (fewer than 2 characters)
		if ( mb_strlen( $str ) < 2 ) {
			return false;
		}

		// Already contains translation markers; no need to translate again
		if ( preg_match( '/【[a-zA-Z_]+】/u', $str ) ) {
			return false;
		}

		// Pure printf format placeholders (%s, %d, %1$s, %2$d, etc.)
		$trimmed = trim( $str );
		if ( preg_match( '/^%[-+0 \']*[0-9]*\.?[0-9]*(\d+\$)?[bcdeEfFgGhHosuxX%lL]$/', $trimmed ) ) {
			return false;
		}

		// Pure numbers do not need translation
		if ( is_numeric( $str ) ) {
			return false;
		}

		// Pure URLs do not need translation
		if ( filter_var( $str, FILTER_VALIDATE_URL ) ) {
			return false;
		}

		// Email addresses do not need translation
		if ( filter_var( $str, FILTER_VALIDATE_EMAIL ) ) {
			return false;
		}

		// Code/technical identifiers do not need translation
		if ( preg_match( '/^[a-z_][a-z0-9_]*$/i', $str ) && strlen( $str ) < 50 ) {
			return false;
		}

		// Strings containing only special characters/digits/currency symbols do not need translation (e.g. 0, 20/month, #stories)
		if ( preg_match( '/^[\s\d\-_\.\/\\\\@#$%^&*()+=\[\]{}|;:\'",<>?`~€£¥₹₩₫฿\x{00a2}-\x{00a5}\x{20a0}-\x{20cf}]+$/u', $str ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Deduplicate entries
	 *
	 * @param array $entries Entries to deduplicate
	 * @return array
	 */
	private function deduplicate_entries( $entries ) {
		$unique = array();
		$keys   = array();

		foreach ( $entries as $entry ) {
			$key = md5( $entry['msgid'] . '|' . ( $entry['msgctxt'] ?? '' ) );

			if ( ! isset( $keys[ $key ] ) ) {
				$keys[ $key ] = count( $unique );
				$unique[]     = $entry;
			} else {
				// Merge reference locations
				$idx = $keys[ $key ];
				if ( ! empty( $entry['reference'] ) ) {
					$existing_refs = explode( ', ', $unique[ $idx ]['reference'] );
					if ( count( $existing_refs ) < 5 ) { // Limit number of references
						$unique[ $idx ]['reference'] = trim(
							$unique[ $idx ]['reference'] . ', ' . $entry['reference'],
							', '
						);
					}
				}
			}
		}

		return $unique;
	}

	/**
	 * Static shortcut method
	 *
	 * @param array $post_types Post types to scan
	 * @param array $taxonomies Taxonomies to scan
	 * @return array
	 */
	public static function scan( $post_types = array(), $taxonomies = array() ) {
		$scanner = new self();
		return $scanner->scan_site_content( $post_types, $taxonomies );
	}
}

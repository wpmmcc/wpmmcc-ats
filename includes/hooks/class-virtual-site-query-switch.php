<?php
/**
 * Virtual-site query switch.
 *
 * Binds a virtual-site request to the translated shadow post/term so
 * content plugins that read the queried object (WooCommerce, Elementor,
 * ACF, …) see translated postmeta rather than source-language overlay.
 *
 * Listen/dirty-mark stays in Hook_Manager. This class is delivery only.
 *
 * @package WPTSALL\Hooks
 * @since 2.1.0
 */

namespace WPTSALL\Hooks;

use WPTSALL\Sites\Services\Virtual_Site_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Virtual_Site_Query_Switch {

	const SOURCE_POST_META = '_wptsall_source_post_id';
	const VIRTUAL_SITE_META = '_wptsall_virtual_site_id';
	const SOURCE_TERM_META = '_wptsall_source_term_id';

	/**
	 * Hooks that must never be registered as dirty-mark listen events.
	 *
	 * @return string[]
	 */
	public static function frontend_delivery_hooks() {
		return array(
			'template_include',
			'template_redirect',
			'pre_get_posts',
			'parse_request',
			'the_title',
			'the_content',
			'the_excerpt',
			'home_url',
			'wp_nav_menu_objects',
			'nav_menu_link_attributes',
			'get_search_form',
			'option_page_on_front',
			'option_page_for_posts',
			'widget_display_callback',
		);
	}

	/**
	 * Whether a hook name is a frontend delivery hook (not a listen event).
	 *
	 * @param string $hook_name Hook name.
	 * @return bool
	 */
	public static function is_frontend_delivery_hook( $hook_name ) {
		return in_array( (string) $hook_name, self::frontend_delivery_hooks(), true );
	}

	/**
	 * True only when the relation target is a virtual site.
	 * Real WP subsites (`target_site_type=wp`) are served natively.
	 *
	 * @param string $target_site_type Relation target type.
	 * @return bool
	 */
	public static function requires_virtual_router_to_serve( $target_site_type ) {
		return 'virtual' === sanitize_key( (string) $target_site_type );
	}

	/**
	 * Bind WP query vars to the translated (shadow) object when one exists.
	 *
	 * Does not look up the database: callers pass the already-resolved
	 * queried_id. Safe to call with a fake $wp in unit tests.
	 *
	 * @param object $wp       WordPress environment (needs ->query_vars).
	 * @param array  $resolved Resolver payload (type, subtype, source_id, queried_id?).
	 * @return array Applied resolution (queried_id, source_id, type, subtype, switched).
	 */
	public static function bind_query_vars( $wp, array $resolved ) {
		$type      = (string) ( $resolved['type'] ?? 'post_type' );
		$subtype   = (string) ( $resolved['subtype'] ?? '' );
		$source_id = (int) ( $resolved['source_id'] ?? 0 );
		$queried   = isset( $resolved['queried_id'] ) ? (int) $resolved['queried_id'] : $source_id;
		if ( $queried <= 0 ) {
			$queried = $source_id;
		}

		if ( in_array( $type, array( 'home', 'paged' ), true ) ) {
			// Home/paged requests must stay archive queries. The catch-all
			// `pagename` rule maps `en-us/` to a page lookup that fails and
			// forces a 404 — drop every URL-derived single-object var so the
			// main query falls back to a (paginated) home archive.
			self::clear_single_object_query_vars( $wp );
			$paged_num = (int) ( $resolved['paged'] ?? 0 );
			if ( 'paged' === $type && is_object( $wp ) && $paged_num > 0 ) {
				$wp->query_vars['paged'] = $paged_num;
			}
			return array(
				'type'       => $type,
				'subtype'    => $subtype,
				'source_id'  => $paged_num,
				'queried_id' => 0,
				'switched'   => false,
			);
		}

		if ( ! is_object( $wp ) ) {
			return array(
				'type'       => $type,
				'subtype'    => $subtype,
				'source_id'  => $source_id,
				'queried_id' => $queried,
				'switched'   => ( $queried > 0 && $queried !== $source_id ),
			);
		}

		if ( ! isset( $wp->query_vars ) || ! is_array( $wp->query_vars ) ) {
			$wp->query_vars = array();
		}

		// URL-derived single-object vars (pagename/name/page...) must not
		// survive into the main query. WP_Query ANDs a failed pagename
		// lookup (ID = '0') with p = {shadow}, producing a 0-row 404,
		// which forces the late template_redirect fallback and confuses
		// SEO/title plugins that snapshot the query at `wp`.
		self::clear_single_object_query_vars( $wp );

		if ( 'taxonomy' === $type ) {
			if ( $subtype ) {
				$wp->query_vars['taxonomy'] = $subtype;
			}
			if ( ! empty( $resolved['term_slug'] ) ) {
				$wp->query_vars['term'] = (string) $resolved['term_slug'];
			}
			if ( ! empty( $resolved['tax_query_var'] ) ) {
				$wp->query_vars[ (string) $resolved['tax_query_var'] ] = (string) ( $resolved['term_slug'] ?? '' );
			}
		} else {
			$wp->query_vars['p']         = $queried;
			$wp->query_vars['post_type'] = $subtype ? $subtype : 'post';
		}

		if ( ! empty( $resolved['paged'] ) ) {
			$wp->query_vars['paged'] = (int) $resolved['paged'];
		}

		return array(
			'type'       => $type,
			'subtype'    => $subtype,
			'source_id'  => $source_id,
			'queried_id' => $queried,
			'switched'   => ( $queried > 0 && $source_id > 0 && $queried !== $source_id ),
		);
	}

	/**
	 * Remove query vars that force WP_Query down a single-object (page/post)
	 * lookup. Leftover from WordPress' catch-all `pagename` rule when the
	 * virtual site has no dedicated rewrite match, they AND with the bound
	 * vars and turn the main query into a 404.
	 *
	 * @param object $wp WordPress environment (needs ->query_vars).
	 */
	private static function clear_single_object_query_vars( $wp ) {
		if ( ! is_object( $wp ) || ! isset( $wp->query_vars ) || ! is_array( $wp->query_vars ) ) {
			return;
		}
		unset(
			$wp->query_vars['pagename'],
			$wp->query_vars['name'],
			$wp->query_vars['page'],
			$wp->query_vars['page_id'],
			$wp->query_vars['attachment_id'],
			$wp->query_vars['year'],
			$wp->query_vars['monthnum'],
			$wp->query_vars['day'],
			$wp->query_vars['w'],
			$wp->query_vars['error'],
			$wp->query_vars['wptsall_virtual_path']
		);
	}

	/**
	 * Resolve the object that should be queried for a virtual-site request.
	 *
	 * If a shadow copy exists, queried_id is the shadow. Otherwise the
	 * source id is kept so unpublished translations still resolve.
	 *
	 * @param array $resolved     Path resolver output (type, subtype, source_id).
	 * @param array $virtual_site Current virtual site row.
	 * @return array Resolved payload including queried_id.
	 */
	public static function resolve_queried_object( array $resolved, array $virtual_site ) {
		$type      = (string) ( $resolved['type'] ?? 'post_type' );
		$subtype   = (string) ( $resolved['subtype'] ?? '' );
		$source_id = (int) ( $resolved['source_id'] ?? 0 );
		$queried   = $source_id;

		if ( $source_id > 0 ) {
			if ( 'taxonomy' === $type ) {
				$shadow = self::find_shadow_term_id( $source_id, $virtual_site, $subtype );
			} else {
				$shadow = self::find_shadow_post_id( $source_id, $virtual_site, $subtype );
			}
			if ( $shadow > 0 ) {
				$queried = $shadow;
			}
		}

		$resolved['queried_id'] = $queried;
		$resolved['switched']   = ( $queried > 0 && $source_id > 0 && $queried !== $source_id );
		return $resolved;
	}

	/**
	 * Find the shadow post id for a source post on this virtual site.
	 *
	 * If $source_id is already a shadow post (has _wptsall_virtual_site_id),
	 * it is returned unchanged.
	 *
	 * @param int    $source_id    Source (or already-shadow) post id.
	 * @param array  $virtual_site Virtual site row.
	 * @param string $post_type    Optional post type hint.
	 * @return int Shadow post id or 0.
	 */
	public static function find_shadow_post_id( $source_id, array $virtual_site, $post_type = '' ) {
		$source_id = (int) $source_id;
		if ( $source_id <= 0 ) {
			return 0;
		}

		$existing_vs = self::raw_post_meta( $source_id, self::VIRTUAL_SITE_META );
		if ( '' !== (string) $existing_vs ) {
			return $source_id;
		}

		if ( class_exists( Virtual_Site_Service::class ) && method_exists( Virtual_Site_Service::class, 'find_shadow_post_id_by_source' ) ) {
			$found = (int) Virtual_Site_Service::find_shadow_post_id_by_source(
				self::virtual_site_id_candidates( $virtual_site ),
				$source_id,
				(string) $post_type
			);
			if ( $found > 0 ) {
				return $found;
			}
		}

		return 0;
	}

	/**
	 * Find the shadow term id for a source term on this virtual site.
	 *
	 * @param int    $source_term_id Source term id.
	 * @param array  $virtual_site   Virtual site row.
	 * @param string $taxonomy       Taxonomy name.
	 * @return int
	 */
	public static function find_shadow_term_id( $source_term_id, array $virtual_site, $taxonomy = '' ) {
		$source_term_id = (int) $source_term_id;
		if ( $source_term_id <= 0 ) {
			return 0;
		}

		if ( function_exists( 'get_term_meta' ) ) {
			$existing_vs = get_term_meta( $source_term_id, self::VIRTUAL_SITE_META, true );
			if ( '' !== (string) $existing_vs ) {
				return $source_term_id;
			}
		}

		if ( class_exists( Virtual_Site_Service::class ) && method_exists( Virtual_Site_Service::class, 'find_shadow_term_id_by_source' ) ) {
			$found = (int) Virtual_Site_Service::find_shadow_term_id_by_source(
				self::virtual_site_id_candidates( $virtual_site ),
				$source_term_id,
				(string) $taxonomy
			);
			if ( $found > 0 ) {
				return $found;
			}
		}

		return 0;
	}

	/**
	 * Source post id for a (possibly shadow) post.
	 *
	 * @param int $post_id Post id.
	 * @return int
	 */
	public static function resolve_source_post_id( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return $post_id;
		}
		$source = (int) self::raw_post_meta( $post_id, self::SOURCE_POST_META );
		return $source > 0 ? $source : $post_id;
	}

	/**
	 * Read internal post meta from wp_postmeta directly.
	 *
	 * Virtual shadow routing/status markers are stored in wp_postmeta and some
	 * content plugins hide or virtualize these keys through get_post_meta() for
	 * their CPTs. Delivery code must use the same raw store as our SQL lookups.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $meta_key Meta key.
	 * @return mixed
	 */
	public static function raw_post_meta( int $post_id, string $meta_key ) {
		if ( $post_id <= 0 || '' === $meta_key ) {
			return '';
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$raw = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT meta_value FROM %i WHERE post_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1',
				$wpdb->postmeta,
				$post_id,
				$meta_key
			)
		);
		if ( null === $raw ) {
			return '';
		}
		return maybe_unserialize( $raw );
	}

	/**
	 * Candidate virtual-site id strings used in post/term meta.
	 *
	 * @param array $virtual_site Virtual site row.
	 * @return string[]
	 */
	public static function virtual_site_id_candidates( array $virtual_site ) {
		$ids = array();
		$raw = isset( $virtual_site['id'] ) ? (string) $virtual_site['id'] : '';
		if ( '' !== $raw ) {
			$ids[] = $raw;
			if ( 0 !== strpos( $raw, 'v_' ) ) {
				$ids[] = 'v_' . $raw;
			}
		}
		if ( ! empty( $virtual_site['relation_id'] ) ) {
			$ids[] = (string) $virtual_site['relation_id'];
			$ids[] = 'v_' . (string) $virtual_site['relation_id'];
		}
		$path = isset( $virtual_site['path_prefix'] ) ? trim( (string) $virtual_site['path_prefix'], '/' ) : '';
		if ( '' !== $path ) {
			$ids[] = $path;
			$ids[] = 'v_' . $path;
		}
		if ( ! empty( $virtual_site['lang'] ) ) {
			$lang_parts = explode( '_', (string) $virtual_site['lang'] );
			$ids[]      = 'v_' . strtolower( $lang_parts[0] );
		}
		return array_values( array_unique( array_filter( $ids, 'strlen' ) ) );
	}

	/**
	 * Meta query clause that includes only this virtual site's copies.
	 *
	 * @param array $virtual_site Virtual site row.
	 * @return array
	 */
	public static function include_virtual_site_meta_query( array $virtual_site ) {
		return array(
			'key'     => self::VIRTUAL_SITE_META,
			'value'   => self::virtual_site_id_candidates( $virtual_site ),
			'compare' => 'IN',
		);
	}

	/**
	 * Meta query clause that excludes all virtual-site copies.
	 *
	 * @return array
	 */
	public static function exclude_virtual_site_meta_query() {
		return array(
			'key'     => self::VIRTUAL_SITE_META,
			'compare' => 'NOT EXISTS',
		);
	}

	/**
	 * Frontend get_terms isolation (mirror pre_get_posts for posts).
	 *
	 * On a virtual site: only terms tagged for this VS (shadow categories/tags).
	 * On the source site: exclude shadow terms so tag clouds stay clean.
	 * Admin list filtering stays in Admin_Virtual_Site_Manager (screen + nonce).
	 * Registered only when virtual sites exist (see Router::maybe_register_frontend_hooks).
	 *
	 * @param array $args       get_terms arguments.
	 * @param array $taxonomies Taxonomies (unused; isolation is VS-scoped).
	 * @return array
	 */
	public static function filter_get_terms_args( $args, $taxonomies = array() ) {
		unset( $taxonomies );
		if ( ! is_array( $args ) ) {
			return $args;
		}
		if ( ! empty( $args['wptsall_skip_virtual_filter'] ) ) {
			return $args;
		}
		// Identity / attachment lookups must see shadow terms. Without this,
		// term_exists() + wp_set_object_terms() silently drop mapped terms and
		// wp_get_object_terms() hides associations already stored on shadow posts.
		if ( ! empty( $args['include'] )
			|| ! empty( $args['slug'] )
			|| ! empty( $args['name'] )
			|| ! empty( $args['term_taxonomy_id'] )
			|| ! empty( $args['object_ids'] ) ) {
			return $args;
		}
		if ( function_exists( 'is_admin' ) && is_admin() && ! ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) ) {
			return $args;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return $args;
		}
		if ( function_exists( 'pll_current_language' ) && pll_current_language() ) {
			return $args;
		}

		$vs = class_exists( '\\WPTSALL\\Hooks\\Virtual_Site_Router' )
			? Virtual_Site_Router::get_current_virtual_site()
			: null;

		$meta_query = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();
		if ( $vs ) {
			$meta_query[] = self::include_virtual_site_meta_query( $vs );
		} else {
			$meta_query[] = self::exclude_virtual_site_meta_query();
		}
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- single clause on plugin-owned meta key '_wptsall_virtual_site_id' (bounded cardinality, core meta_key index); the standard virtual-site isolation pattern.
		$args['meta_query'] = $meta_query;
		return $args;
	}

	/**
	 * Whether a WP_Query should be rewritten for virtual-site archives.
	 *
	 * @param object $query WP_Query-like object.
	 * @return bool
	 */
	public static function should_filter_archive_query( $query ) {
		if ( ! is_object( $query ) ) {
			return false;
		}
		if ( method_exists( $query, 'is_main_query' ) && ! $query->is_main_query() ) {
			// Still filter typical frontend secondary loops, but skip named exceptions below.
		}
		if ( function_exists( 'is_admin' ) && is_admin() && ! ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		$vars = isset( $query->query_vars ) && is_array( $query->query_vars ) ? $query->query_vars : array();
		if ( ! empty( $vars['wptsall_skip_virtual_filter'] ) ) {
			return false;
		}
		if ( ! empty( $vars['p'] ) || ! empty( $vars['page_id'] ) || ! empty( $vars['name'] ) || ! empty( $vars['pagename'] ) ) {
			return false;
		}
		$post_type = $vars['post_type'] ?? '';
		if ( 'nav_menu_item' === $post_type || 'revision' === $post_type || 'customize_changeset' === $post_type ) {
			return false;
		}
		if ( is_array( $post_type ) && array_intersect( $post_type, array( 'nav_menu_item', 'revision' ) ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Apply include/exclude meta_query to a WP_Query.
	 *
	 * @param object $query        WP_Query-like.
	 * @param array  $clause       One meta_query clause.
	 * @return array The resulting meta_query.
	 */
	public static function merge_meta_query( $query, array $clause ) {
		$existing = array();
		if ( is_object( $query ) && method_exists( $query, 'get' ) ) {
			$raw = $query->get( 'meta_query' );
			if ( is_array( $raw ) ) {
				$existing = $raw;
			}
		} elseif ( is_object( $query ) && isset( $query->query_vars['meta_query'] ) && is_array( $query->query_vars['meta_query'] ) ) {
			$existing = $query->query_vars['meta_query'];
		}
		$existing[] = $clause;
		if ( is_object( $query ) && method_exists( $query, 'set' ) ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- merges one plugin-owned '_wptsall_virtual_site_id' clause (bounded cardinality, core meta_key index).
			$query->set( 'meta_query', $existing );
		} elseif ( is_object( $query ) ) {
			if ( ! isset( $query->query_vars ) || ! is_array( $query->query_vars ) ) {
				$query->query_vars = array();
			}
			$query->query_vars['meta_query'] = $existing; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- same single plugin-owned clause as above.
		}
		return $existing;
	}
}

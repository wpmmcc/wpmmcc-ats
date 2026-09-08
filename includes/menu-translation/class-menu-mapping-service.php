<?php
/**
 * Classic menu language mapping + theme location swap.
 *
 * @package WPTSALL\MenuTranslation
 * @since 2.0.1
 */

namespace WPTSALL\MenuTranslation;

use WPTSALL\MenuTranslation\Admin\Menu_Sync_Page;
use WPTSALL\Sites\Services\Virtual_Site_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Menu_Mapping_Service class.
 */
class Menu_Mapping_Service {

	/**
	 * Re-entrancy guard for the create-time auto clone. wp_create_nav_menu()
	 * fires its action synchronously for every clone we create, before the
	 * clone carries the virtual-site term meta, so a static flag is required.
	 *
	 * @var bool
	 */
	private static $auto_cloning = false;

	/**
	 * @return void
	 */
	public static function init() {
		add_filter( 'wp_nav_menu_args', array( __CLASS__, 'filter_nav_menu_args' ), 20 );
		add_filter( 'theme_mod_nav_menu_locations', array( __CLASS__, 'filter_nav_menu_locations' ), 20 );
		add_action( 'wp_update_nav_menu', array( __CLASS__, 'on_update_nav_menu' ), 20, 1 );
		add_action( 'wp_delete_nav_menu', array( __CLASS__, 'on_delete_nav_menu' ), 20, 1 );
		add_action( 'wp_update_nav_menu_item', array( __CLASS__, 'on_update_nav_menu_item' ), 30, 3 );
		// Registered unconditionally; the handler checks the menu_auto_clone
		// setting at call time so toggling the option takes effect immediately.
		add_action( 'wp_create_nav_menu', array( __CLASS__, 'on_create_nav_menu' ), 20, 2 );
	}

	/**
	 * Whether newly created nav menus are cloned for active virtual sites.
	 *
	 * Default is off: manual Menu Sync stays the only clone path unless the
	 * site opts in via the menu_auto_clone setting (or the
	 * wptsall_menu_auto_clone_enabled filter for tests/integrations).
	 *
	 * @return bool
	 */
	public static function menu_auto_clone_enabled() {
		$enabled = false;
		if ( class_exists( '\\WPTSALL\\Settings\\Services\\Settings_Service' ) ) {
			$enabled = (bool) \WPTSALL\Settings\Services\Settings_Service::get( 'menu_auto_clone', false );
		}
		/**
		 * Filters the create-time menu auto clone behaviour.
		 *
		 * @param bool $enabled Whether auto clone on menu creation is enabled.
		 */
		return (bool) apply_filters( 'wptsall_menu_auto_clone_enabled', $enabled );
	}

	/**
	 * Clone a newly created nav menu for every active virtual site.
	 *
	 * Runs the existing Menu_Sync_Page::sync_menu() clone logic (target
	 * creation, item copy, mapping upsert, string registration) when the
	 * menu_auto_clone setting is enabled. Default (off) keeps manual Menu
	 * Sync as the only clone path.
	 *
	 * @param int   $menu_id   New menu term ID.
	 * @param array $menu_data Menu data (unused).
	 * @return void
	 */
	public static function on_create_nav_menu( $menu_id, $menu_data = null ) {
		unset( $menu_data );
		$menu_id = (int) $menu_id;
		if ( $menu_id <= 0 || self::$auto_cloning ) {
			return;
		}
		if ( function_exists( 'wptsall_is_internal_write' ) && wptsall_is_internal_write() ) {
			return;
		}
		if ( ! self::menu_auto_clone_enabled() ) {
			return;
		}
		// Never re-clone our own language clones.
		if ( get_term_meta( $menu_id, '_wptsall_virtual_site_id', true ) ) {
			return;
		}
		if ( ! class_exists( '\\WPTSALL\\Sites\\Services\\Virtual_Site_Service' )
			|| ! class_exists( '\\WPTSALL\\MenuTranslation\\Admin\\Menu_Sync_Page' ) ) {
			return;
		}
		$sites = \WPTSALL\Sites\Services\Virtual_Site_Service::get_all();
		if ( ! is_array( $sites ) || empty( $sites ) ) {
			return;
		}

		// Prefer virtual sites that are targets of a site_relation. Lab/dev DBs
		// can accumulate orphan VS rows; cloning into every orphan is wrong and
		// makes create-time auto-clone flaky (duplicate "[lang]" menu names).
		$related_ids = array();
		if ( class_exists( '\\WPTSALL\\Sites\\Services\\Site_Relation_Service' ) ) {
			$relations = \WPTSALL\Sites\Services\Site_Relation_Service::get_all_relations( array(), false );
			if ( is_array( $relations ) ) {
				foreach ( $relations as $rel ) {
					if ( ( $rel['target_site_type'] ?? '' ) !== 'virtual' ) {
						continue;
					}
					$tid = (string) ( $rel['target_site_id'] ?? '' );
					if ( '' === $tid ) {
						continue;
					}
					$related_ids[ $tid ] = true;
					if ( 0 === strpos( $tid, 'v_' ) ) {
						$related_ids[ substr( $tid, 2 ) ] = true;
					} else {
						$related_ids[ 'v_' . $tid ] = true;
					}
				}
			}
		}

		self::$auto_cloning = true;
		try {
			foreach ( $sites as $site ) {
				$vs_id = (string) ( $site['id'] ?? '' );
				if ( '' === $vs_id ) {
					continue;
				}
				if ( ! empty( $related_ids ) && empty( $related_ids[ $vs_id ] ) ) {
					continue;
				}
				// A single failed clone must not abort the remaining sites.
				\WPTSALL\MenuTranslation\Admin\Menu_Sync_Page::sync_menu( $menu_id, $vs_id );
			}
		} finally {
			self::$auto_cloning = false;
		}
	}

	/**
	 * Ensure schema exists.
	 *
	 * @return void
	 */
	public static function ensure_schema() {
		require_once dirname( __FILE__ ) . '/database/schema-menu-mappings.php';
		if ( function_exists( 'wptsall_create_menu_mappings_table' ) ) {
			wptsall_create_menu_mappings_table();
		}
	}

	/**
	 * Swap menu ID when a language mapping exists for current VS.
	 *
	 * @param array $args Nav menu args.
	 * @return array
	 */
	public static function filter_nav_menu_args( $args ) {
		if ( ! is_array( $args ) ) {
			return $args;
		}
		$vs = self::current_virtual_site();
		if ( empty( $vs['id'] ) ) {
			return $args;
		}
		$source_menu = 0;
		if ( ! empty( $args['menu'] ) ) {
			if ( is_numeric( $args['menu'] ) ) {
				$source_menu = (int) $args['menu'];
			} elseif ( is_object( $args['menu'] ) && isset( $args['menu']->term_id ) ) {
				$source_menu = (int) $args['menu']->term_id;
			} elseif ( is_string( $args['menu'] ) ) {
				$obj = wp_get_nav_menu_object( $args['menu'] );
				$source_menu = $obj ? (int) $obj->term_id : 0;
			}
		}
		if ( $source_menu <= 0 && ! empty( $args['theme_location'] ) ) {
			$locs = get_nav_menu_locations();
			$loc  = (string) $args['theme_location'];
			if ( isset( $locs[ $loc ] ) ) {
				$source_menu = (int) $locs[ $loc ];
			}
			// Prefer location-scoped mapping.
			$by_loc = self::get_target_menu_for_location( $loc, (string) $vs['id'] );
			if ( $by_loc > 0 ) {
				$args['menu'] = $by_loc;
				return $args;
			}
		}
		if ( $source_menu > 0 ) {
			$target = self::get_target_menu( $source_menu, (string) $vs['id'] );
			if ( $target > 0 ) {
				$args['menu'] = $target;
			}
		}
		return $args;
	}

	/**
	 * Rewrite theme locations map for the current virtual site.
	 *
	 * @param array $locations Location => menu term id.
	 * @return array
	 */
	public static function filter_nav_menu_locations( $locations ) {
		if ( ! is_array( $locations ) || empty( $locations ) ) {
			return $locations;
		}
		$vs = self::current_virtual_site();
		if ( empty( $vs['id'] ) ) {
			return $locations;
		}
		$out = $locations;
		foreach ( $locations as $loc => $menu_id ) {
			$menu_id = (int) $menu_id;
			$by_loc  = self::get_target_menu_for_location( (string) $loc, (string) $vs['id'] );
			if ( $by_loc > 0 ) {
				$out[ $loc ] = $by_loc;
				continue;
			}
			if ( $menu_id > 0 ) {
				$target = self::get_target_menu( $menu_id, (string) $vs['id'] );
				if ( $target > 0 ) {
					$out[ $loc ] = $target;
				}
			}
		}
		return $out;
	}

	/**
	 * Persist mapping after Menu Sync clone (called from sync page).
	 *
	 * @param int    $source_menu_id Source menu term ID.
	 * @param int    $target_menu_id Target menu term ID.
	 * @param string $vs_id          Virtual site id.
	 * @param string $location       Optional theme location slug.
	 * @return bool
	 */
	public static function upsert_mapping( $source_menu_id, $target_menu_id, $vs_id, $location = '' ) {
		global $wpdb;
		self::ensure_schema();
		$table = wptsall_table( 'menu_mappings' );
		$vs    = Virtual_Site_Service::get( $vs_id );
		$lang  = is_array( $vs ) ? (string) ( $vs['lang'] ?? '' ) : '';
		$hash  = self::menu_content_hash( (int) $source_menu_id );
		$now   = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE source_menu_term_id = %d AND virtual_site_id = %s LIMIT 1',
				$table,
				(int) $source_menu_id,
				(string) $vs_id
			)
		);
		$data = array(
			'source_menu_term_id' => (int) $source_menu_id,
			'target_menu_term_id' => (int) $target_menu_id,
			'virtual_site_id'     => (string) $vs_id,
			'target_lang'         => $lang,
			'location_slug'       => (string) $location,
			'source_hash'         => $hash,
			'sync_status'         => 'synced',
			'updated_at'          => $now,
		);
		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			return false !== $wpdb->update( $table, $data, array( 'id' => (int) $existing ) );
		}
		$data['created_at'] = $now;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return false !== $wpdb->insert( $table, $data );
	}

	/**
	 * Mark mapped targets as needing sync when source menu changes.
	 *
	 * @param int $menu_id Menu term ID.
	 * @return void
	 */
	public static function on_update_nav_menu( $menu_id ) {
		$menu_id = (int) $menu_id;
		if ( $menu_id <= 0 || ( function_exists( 'wptsall_is_internal_write' ) && wptsall_is_internal_write() ) ) {
			return;
		}
		// Skip if this menu is itself a language clone.
		if ( get_term_meta( $menu_id, '_wptsall_virtual_site_id', true ) ) {
			return;
		}
		global $wpdb;
		self::ensure_schema();
		$table = wptsall_table( 'menu_mappings' );
		$hash  = self::menu_content_hash( $menu_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET sync_status = 'pending', source_hash = %s, updated_at = %s WHERE source_menu_term_id = %d",
				$table,
				$hash,
				current_time( 'mysql' ),
				$menu_id
			)
		);
	}

	/**
	 * @param int               $menu_id Menu id.
	 * @param int               $item_id Item id.
	 * @param array|object|null $args    Args.
	 * @return void
	 */
	public static function on_update_nav_menu_item( $menu_id, $item_id, $args ) {
		unset( $item_id, $args );
		self::on_update_nav_menu( (int) $menu_id );
	}

	/**
	 * Delete mappings when source or target menu is deleted.
	 *
	 * @param int $menu_id Menu term ID.
	 * @return void
	 */
	public static function on_delete_nav_menu( $menu_id ) {
		$menu_id = (int) $menu_id;
		if ( $menu_id <= 0 ) {
			return;
		}
		global $wpdb;
		self::ensure_schema();
		$table = wptsall_table( 'menu_mappings' );
		// If deleting a source: trash/delete target clones that we own.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$targets = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT target_menu_term_id FROM %i WHERE source_menu_term_id = %d AND target_menu_term_id > 0',
				$table,
				$menu_id
			)
		);
		if ( ! empty( $targets ) ) {
			$run = static function () use ( $targets ) {
				foreach ( $targets as $tid ) {
					$tid = (int) $tid;
					if ( $tid > 0 && term_exists( $tid, 'nav_menu' ) ) {
						wp_delete_nav_menu( $tid );
					}
				}
			};
			if ( class_exists( '\\WPTSALL\\Hooks\\Content_Change_Dispatcher' ) ) {
				\WPTSALL\Hooks\Content_Change_Dispatcher::with_internal_write( $run );
			} else {
				$run();
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE source_menu_term_id = %d OR target_menu_term_id = %d',
				$table,
				$menu_id,
				$menu_id
			)
		);
	}

	/**
	 * Incremental re-sync for pending mappings of a source menu.
	 *
	 * @param int $source_menu_id Source menu.
	 * @return int Number of menus refreshed.
	 */
	public static function resync_pending_for_source( $source_menu_id ) {
		global $wpdb;
		self::ensure_schema();
		$table = wptsall_table( 'menu_mappings' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE source_menu_term_id = %d AND sync_status = 'pending'",
				$table,
				(int) $source_menu_id
			),
			ARRAY_A
		);
		$count = 0;
		foreach ( (array) $rows as $row ) {
			$vs_id = (string) ( $row['virtual_site_id'] ?? '' );
			$old_target = (int) ( $row['target_menu_term_id'] ?? 0 );
			$result = Menu_Sync_Page::sync_menu( (int) $source_menu_id, $vs_id, '' );
			if ( is_wp_error( $result ) ) {
				continue;
			}
			if ( $old_target > 0 && $old_target !== (int) $result && term_exists( $old_target, 'nav_menu' ) ) {
				if ( class_exists( '\\WPTSALL\\Hooks\\Content_Change_Dispatcher' ) ) {
					\WPTSALL\Hooks\Content_Change_Dispatcher::with_internal_write(
						static function () use ( $old_target ) {
							wp_delete_nav_menu( $old_target );
						}
					);
				} else {
					wp_delete_nav_menu( $old_target );
				}
			}
			self::upsert_mapping( (int) $source_menu_id, (int) $result, $vs_id, (string) ( $row['location_slug'] ?? '' ) );
			++$count;
		}
		return $count;
	}

	/**
	 * @param int    $source_menu_id Source menu.
	 * @param string $vs_id          VS id.
	 * @return int
	 */
	public static function get_target_menu( $source_menu_id, $vs_id ) {
		global $wpdb;
		self::ensure_schema();
		$table = wptsall_table( 'menu_mappings' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$tid = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT target_menu_term_id FROM %i WHERE source_menu_term_id = %d AND virtual_site_id = %s LIMIT 1',
				$table,
				(int) $source_menu_id,
				(string) $vs_id
			)
		);
		return $tid > 0 ? $tid : 0;
	}

	/**
	 * @param string $location Location slug.
	 * @param string $vs_id    VS id.
	 * @return int
	 */
	public static function get_target_menu_for_location( $location, $vs_id ) {
		$location = (string) $location;
		if ( '' === $location ) {
			return 0;
		}
		global $wpdb;
		self::ensure_schema();
		$table = wptsall_table( 'menu_mappings' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$tid = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT target_menu_term_id FROM %i WHERE location_slug = %s AND virtual_site_id = %s AND target_menu_term_id > 0 LIMIT 1',
				$table,
				$location,
				(string) $vs_id
			)
		);
		return $tid > 0 ? $tid : 0;
	}

	/**
	 * @param int $menu_id Menu term ID.
	 * @return string
	 */
	public static function menu_content_hash( $menu_id ) {
		$items = wp_get_nav_menu_items( (int) $menu_id );
		$parts = array();
		foreach ( (array) $items as $item ) {
			$parts[] = implode(
				'|',
				array(
					(int) $item->ID,
					(string) $item->title,
					(string) $item->url,
					(int) $item->menu_item_parent,
					(string) $item->type,
					(int) $item->object_id,
				)
			);
		}
		return hash( 'sha256', implode( "\n", $parts ) );
	}

	/**
	 * @return array
	 */
	private static function current_virtual_site() {
		if ( ! empty( $GLOBALS['wptsall_current_virtual_site'] ) && is_array( $GLOBALS['wptsall_current_virtual_site'] ) ) {
			return $GLOBALS['wptsall_current_virtual_site'];
		}
		if ( class_exists( '\\WPTSALL\\Hooks\\Virtual_Site_Router' ) ) {
			$vs = \WPTSALL\Hooks\Virtual_Site_Router::get_current_virtual_site();
			return is_array( $vs ) ? $vs : array();
		}
		return array();
	}
}

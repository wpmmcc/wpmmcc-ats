<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function wptsall_get_virtual_sites() {
    $sites = get_option( 'wptsall_virtual_sites', array() );
    // ensure base path fallback
    foreach ( $sites as &$v ) {
        if ( empty( $v['base'] ) && ! empty( $v['id'] ) ) {
            $v['base'] = 'virtual/' . $v['id'];
        }
    }
    unset( $v );

    // SM-001: Also check site_relations table for v0.4.0 virtual sites
    global $wpdb;
    $table = $wpdb->prefix . 'wptsall_site_relations';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    if ( $table_exists === $table ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $virtuals = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT DISTINCT target_site_id, target_lang, target_theme_path, target_theme_name FROM %i WHERE target_site_type = %s AND status = %s',
                $table,
                'virtual',
                'active'
            ),
            ARRAY_A
        );
        foreach ( $virtuals as $v ) {
            $site_id = $v['target_site_id'];
            // Skip if already exists in option storage
            $exists = false;
            foreach ( $sites as $existing ) {
                if ( ( $existing['id'] ?? '' ) === $site_id ) {
                    $exists = true;
                    break;
                }
            }
            if ( ! $exists ) {
                // Build virtual site entry from site_relations data
                // path_prefix: use target_theme_path, or target_lang, or fallback to virtual/{id}
                $path_prefix = $v['target_theme_path'];
                if ( empty( $path_prefix ) && ! empty( $v['target_lang'] ) ) {
                    $path_prefix = $v['target_lang'];
                }
                if ( empty( $path_prefix ) ) {
                    $path_prefix = 'virtual/' . $site_id;
                }
                $sites[] = array(
                    'id'          => $site_id,
                    'name'        => $v['target_theme_name'] ?: $site_id,
                    'base'        => trim( $path_prefix, '/' ),
                    'path_prefix' => trim( $path_prefix, '/' ),
                    'lang'        => $v['target_lang'],
                );
            }
        }
    }

    return is_array( $sites ) ? $sites : array();
}

function wptsall_get_virtual_site( $id ) {
    foreach ( wptsall_get_virtual_sites() as $v ) {
        if ( (string) $v['id'] === (string) $id ) {
            return $v;
        }
    }
    return null;
}

function wptsall_save_virtual_sites( $sites ) {
    update_option( 'wptsall_virtual_sites', array_values( $sites ) );
}


// ============================================================================
// Site Relations API Functions
// ============================================================================

/**
 * Get all site relations
 *
 * Read from database table, fallback to option if table is empty
 *
 * @param array $args Query parameters:
 *   - template    (string) Filter by template
 *   - status      (string) Filter by status (active/inactive)
 *   - source_type (string) Filter by source site type (wp/virtual)
 *   - limit       (int)    Limit
 *   - offset      (int)    Offset
 * @return array Site relations array.
 */
function wptsall_get_site_relations( $args = array() ) {
    global $wpdb;
    $table = wptsall_table( 'site_relations' );

    // Check if database table has data
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $db_count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );

    if ( $db_count > 0 ) {
        // Read from database table
        return wptsall_get_site_relations_from_db( $args );
    }

    return array();
}

/**
 * Get site relations from database table
 *
 * @param array $args Query parameters.
 * @return array
 */
function wptsall_get_site_relations_from_db( $args = array() ) {
    global $wpdb;
    $table = wptsall_table( 'site_relations' );

    $defaults = array(
        'template'    => '',
        'status'      => '',
        'source_type' => '',
        'limit'       => 100,
        'offset'      => 0,
    );
    $args = wp_parse_args( $args, $defaults );

    $limit  = absint( $args['limit'] );
    $offset = absint( $args['offset'] );

    // Build SQL from fixed placeholder fragments only.
    $where  = array( '1=1' );
    $params = array( $table );
    if ( $args['template'] ) {
        $where[]  = 'template = %s';
        $params[] = sanitize_key( $args['template'] );
    }
    if ( $args['status'] ) {
        $where[]  = 'status = %s';
        $params[] = sanitize_key( $args['status'] );
    }
    if ( $args['source_type'] ) {
        $where[]  = 'source_site_type = %s';
        $params[] = sanitize_key( $args['source_type'] );
    }
    $where_sql = implode( ' AND ', $where );
    $params[]  = $limit;
    $params[]  = $offset;

    $rows = wptsall_db_get_results(
        'SELECT * FROM %i WHERE ' . $where_sql . ' ORDER BY id DESC LIMIT %d OFFSET %d',
        $params,
        ARRAY_A
    );
    $rows = is_array( $rows ) ? $rows : array();

    // Convert to unified format
    $relations = array();
    foreach ( $rows as $row ) {
        $relations[] = wptsall_db_row_to_relation( $row );
    }

    return $relations;
}

/**
 * Convert database row to unified relation format
 *
 * Legacy compatibility note: The `target_sites` JSON column dates from the pre-v0.4.0
 * multi-target structure. Since v0.4.0, site_relations uses a one-to-one model with
 * separate `target_site_id` and `target_site_type` columns. The `target_sites` JSON
 * column is still read here for backward compatibility with old data, but new relations
 * are created using the one-to-one columns via Site_Relation_Service.
 *
 * @deprecated target_sites JSON column — use target_site_id / target_site_type instead.
 *
 * @param array $row Database row.
 * @return array Relation data in unified format.
 */
function wptsall_db_row_to_relation( $row ) {
    // Legacy: read target_sites JSON for pre-v0.4.0 data.
    // New relations should use target_site_id / target_site_type columns directly.
    $targets = array();
    if ( ! empty( $row['target_sites'] ) ) {
        $decoded = json_decode( $row['target_sites'], true );
        if ( is_array( $decoded ) ) {
            $targets = $decoded;
        }
    }

    return array(
        'id'            => intval( $row['id'] ),
        'template'      => $row['template'] ?? '',
        'source'        => array(
            'type' => $row['source_site_type'] ?? 'wp',
            'id'   => $row['source_site_id'] ?? 0,
            'lang' => '', // Can be extended via table structure if language support is needed
        ),
        'targets'       => $targets,
        'status'        => $row['status'] ?? 'active',
        'plugin_status' => ! empty( $row['plugin_status'] ) ? json_decode( $row['plugin_status'], true ) : array(),
        'url_match'     => array(
            'mode'        => 'auto',
            'host_strict' => false,
        ),
        'created_at'    => $row['created_at'] ?? '',
        'updated_at'    => $row['updated_at'] ?? '',
    );
}

/**
 * Get a single site relation
 *
 * @param int $id Relation ID.
 * @return array|null Site relation or null.
 */
function wptsall_get_site_relation( $id ) {
    global $wpdb;
    $table = wptsall_table( 'site_relations' );

    // Query from database first
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $row = $wpdb->get_row(
        $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, intval( $id ) ),
        ARRAY_A
    );

    if ( $row ) {
        return wptsall_db_row_to_relation( $row );
    }

    // Fallback to option
    $sites = get_option( 'wptsall_sites', array() );
    foreach ( $sites as $s ) {
        if ( (string) ( $s['id'] ?? '' ) === (string) $id ) {
            return $s;
        }
    }

    return null;
}








/**
 * Check if a specific plugin is activated on a specific site
 *
 * Supports two input formats:
 * - Plugin slug: 'woocommerce'
 * - Full path: 'woocommerce/woocommerce.php'
 *
 * @param int    $site_id     Site ID.
 * @param string $plugin_slug Plugin identifier.
 * @return bool Whether activated.
 */
function wptsall_check_plugin_active_on_site( $site_id, $plugin_slug ) {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	// wordpress-blog doesn't require plugin
	if ( 'wordpress-blog' === $plugin_slug ) {
		return true;
	}

	// Convert plugin slug to full path
	$plugin_file = wptsall_get_plugin_file_from_slug( $plugin_slug );

	if ( ! is_multisite() ) {
		return is_plugin_active( $plugin_file );
	}

	// Check if network-activated
	if ( is_plugin_active_for_network( $plugin_file ) ) {
		return true;
	}

	// Switch to target site to check
	switch_to_blog( $site_id );
	$is_active = is_plugin_active( $plugin_file );
	restore_current_blog();

	return $is_active;
}

/**
 * Get the full plugin file path from plugin slug
 *
 * Lookup order:
 * 1. If already a full path (contains /), return directly
 * 2. Query from plugin_mappings table
 * 3. Use common mapping table
 * 4. Fallback to $slug/$slug.php format
 *
 * @param string $plugin_slug Plugin slug.
 * @return string Full plugin file path.
 */
function wptsall_get_plugin_file_from_slug( $plugin_slug ) {
	// If already in full path format, return directly
	if ( strpos( $plugin_slug, '/' ) !== false ) {
		return $plugin_slug;
	}

	// Try to get from plugin_mappings table
	if ( function_exists( 'wptsall_table' ) && class_exists( 'WPTSALL\\Models\\Services\\Plugin_Mapping_Service' ) ) {
		$mapping = \WPTSALL\Models\Services\Plugin_Mapping_Service::get_by_slug( $plugin_slug );
		if ( $mapping && ! empty( $mapping['plugin_file'] ) ) {
			return $mapping['plugin_file'];
		}
	}

	// Common plugin mapping table (fallback when plugin_mappings table is not initialized)
	$known_plugins = array(
		'woocommerce'            => 'woocommerce/woocommerce.php',
		'bbpress'                => 'bbpress/bbpress.php',
		'buddypress'             => 'buddypress/bp-loader.php',
		'easy-digital-downloads' => 'easy-digital-downloads/easy-digital-downloads.php',
		'academy'                => 'academy/academy.php',
		'classified-listing'     => 'classified-listing/classified-listing.php',
		'cooked'                 => 'cooked/cooked.php',
		'easy-property-listings' => 'easy-property-listings/easy-property-listings.php',
		'testimonial-free'       => 'testimonial-free/developer-starter.php',
		'site-reviews'           => 'site-reviews/site-reviews.php',
		'tablepress'             => 'tablepress/tablepress.php',
		'envira-gallery-lite'    => 'envira-gallery-lite/envira-gallery-lite.php',
		'portfolio-post-type'    => 'portfolio-post-type/portfolio-post-type.php',
	);

	if ( isset( $known_plugins[ $plugin_slug ] ) ) {
		return $known_plugins[ $plugin_slug ];
	}

	// Fallback: use $slug/$slug.php format
	return $plugin_slug . '/' . $plugin_slug . '.php';
}

<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function wptsall_generate_task_for_object( $template, $site_rel, $object_type, $subtype, $object_id ) {
	$object_type = sanitize_key( (string) $object_type );
	if ( 'post' === $object_type ) {
		$object_type = 'post_type';
	} elseif ( 'term' === $object_type ) {
		$object_type = 'taxonomy';
	}

    $site_id   = $site_rel['id'] ?? 0;
    $template_slug = $site_rel['template'] ?? ( $template['plugin'] ?? '' );
    $source_type = $site_rel['source']['type'] ?? 'wp';
    $source_id   = isset( $site_rel['source']['id'] ) ? $site_rel['source']['id'] : get_current_blog_id();
    $source_blog = ( 'wp' === $source_type ) ? intval( $source_id ) : get_current_blog_id();
    // ISS-TSK-077: prefer source_language, fall back to lang.
    $lang_from   = $site_rel['source']['source_language'] ?? $site_rel['source']['lang'] ?? '';
    $targets     = isset( $site_rel['targets'] ) ? (array) $site_rel['targets'] : array();
    if ( empty( $targets ) ) {
        return new WP_Error(
            'no_targets',
            sprintf( 'Site relation (ID: %d) has no target sites configured', $site_id ),
            array( 'status' => 400, 'site_id' => $site_id )
        );
    }

	$target       = $targets[0];
	$target_type  = isset( $target['type'] ) ? $target['type'] : 'wp';
	$target_identifier = isset( $target['id'] ) ? $target['id'] : $source_blog;
	$target_blog  = ( 'wp' === $target_type ) ? intval( $target_identifier ) : 0;
	$job_id       = function_exists( 'wptsall_generate_task_job_id' )
		? wptsall_generate_task_job_id( 'single_' . $site_id . '_' . $object_type . '_' . $object_id )
		: '';
	$fields       = array();
	$complete_data = null;

    if ( 'post_type' === $object_type ) {
        $post = get_post( $object_id );
        if ( ! $post ) {
            return new WP_Error(
                'post_not_found',
                sprintf( '%s not found (ID: %d)', $subtype, $object_id ),
                array( 'status' => 404, 'object_type' => $object_type, 'subtype' => $subtype, 'object_id' => $object_id )
            );
        }
        $fields['post_title']   = $post->post_title;
        $fields['post_content'] = $post->post_content;
        $fields['post_excerpt'] = $post->post_excerpt;
		if ( function_exists( 'wptsall_get_complete_post_data' ) ) {
			$complete_data = wptsall_get_complete_post_data( $subtype, $object_id );
			if ( is_array( $complete_data ) && ! empty( $complete_data['post'] ) && is_array( $complete_data['post'] ) ) {
				$fields['post_title']   = (string) ( $complete_data['post']['post_title'] ?? $fields['post_title'] );
				$fields['post_content'] = (string) ( $complete_data['post']['post_content'] ?? $fields['post_content'] );
				$fields['post_excerpt'] = (string) ( $complete_data['post']['post_excerpt'] ?? $fields['post_excerpt'] );
			}
		}
        $meta  = get_post_meta( $object_id );
        $count = 0;
        foreach ( $meta as $k => $vals ) {
            if ( $count >= 5 ) {
                break;
            }
            if ( empty( $vals ) ) {
                continue;
            }
            $val = is_array( $vals ) ? reset( $vals ) : $vals;
            if ( is_array( $val ) || is_object( $val ) ) {
                continue;
            }
            $fields[ $k ] = $val;
            $count++;
        }
    } elseif ( 'taxonomy' === $object_type ) {
        $term = get_term( $object_id, $subtype );
        if ( ! $term || is_wp_error( $term ) ) {
            $error_msg = is_wp_error( $term ) ? $term->get_error_message() : 'Term does not exist';
            return new WP_Error(
                'term_not_found',
                sprintf( '%s not found (ID: %d): %s', $subtype, $object_id, $error_msg ),
                array( 'status' => 404, 'object_type' => $object_type, 'subtype' => $subtype, 'object_id' => $object_id )
            );
        }
        $fields['name'] = $term->name;
        $fields['description'] = $term->description;
		if ( function_exists( 'wptsall_get_complete_term_data' ) ) {
			$complete_data = wptsall_get_complete_term_data( $subtype, $object_id );
			if ( is_array( $complete_data ) && ! empty( $complete_data['term'] ) && is_array( $complete_data['term'] ) ) {
				$fields['name']        = (string) ( $complete_data['term']['name'] ?? $fields['name'] );
				$fields['description'] = (string) ( $complete_data['term']['description'] ?? $fields['description'] );
			}
		}
    } else {
        return new WP_Error(
            'invalid_object_type',
            sprintf( 'Unsupported object type: %s (supported: post_type, taxonomy)', $object_type ),
            array( 'status' => 400, 'object_type' => $object_type )
        );
    }

	$task = array(
		'job_id'      => $job_id,
		'blog_id'      => $source_blog,
        'target_blog'  => $target_blog,
        'target_type'  => $target_type,
        'target_identifier' => $target_identifier,
        'site_mode'    => '',
        'site_id'      => $site_id,
        'template'     => $template_slug,
        'lang_from'    => $lang_from,
        'lang_to'      => $target['target_language'] ?? $target['lang_to'] ?? '',
        'object_type'  => $object_type,
        'subtype'      => $subtype,
        'object_id'    => $object_id,
        'fields'       => $fields,
    );
	if ( is_array( $complete_data ) ) {
		$task['complete_data'] = $complete_data;
	}

    if ( function_exists( 'wptsall_prepare_task_for_client_payload' ) ) {
        $task = wptsall_prepare_task_for_client_payload( $task, 'text' );
    }

    return $task;
}

/**
 * Insert a single task into the tasks table
 *
 * @param string $object_type  Object type (post/taxonomy)
 * @param string $subtype      Subtype (post/page/product/category, etc.)
 * @param int    $object_id    Object ID
 * @param int    $source_blog  Source site ID
 * @param int    $target_blog  Target site ID
 * @param string $status       Status
 * @param string $template     Template name
 * @return int|false Task ID or false
 */
function wptsall_insert_task( $object_type, $subtype, $object_id, $source_blog, $target_blog, $status = 'pending', $template = '' ) {
    global $wpdb;
    $table = wptsall_task_table_name();

    // Prepare field data - store key fields for reference, full data fetched during processing
    $fields = array();
    if ( 'post' === $object_type || 'post_type' === $object_type ) {
        $complete_data = wptsall_get_complete_post_data( $subtype, $object_id );
        if ( $complete_data ) {
            // wptsall_get_complete_post_data returns 'post' fields instead of 'core'
            $fields = array(
                'post_title'   => $complete_data['post']['post_title'] ?? '',
                'post_content' => $complete_data['post']['post_content'] ?? '',
                'post_excerpt' => $complete_data['post']['post_excerpt'] ?? '',
            );
        }
    } elseif ( 'taxonomy' === $object_type ) {
        $complete_data = wptsall_get_complete_term_data( $subtype, $object_id );
        if ( $complete_data ) {
            $fields = array(
                'name'        => $complete_data['term']['name'] ?? '',
                'description' => $complete_data['term']['description'] ?? '',
            );
        }
    }

	$job_id = function_exists( 'wptsall_generate_task_job_id' )
		? wptsall_generate_task_job_id( 'insert_single_' . $object_type . '_' . $object_id )
		: '';
	$payload = array(
		'job_id'        => $job_id,
		'business_line' => function_exists( 'wptsall_infer_business_line_from_task' )
			? wptsall_infer_business_line_from_task(
				array(
					'object_type' => ( $object_type === 'post' ? 'post_type' : $object_type ),
					'subtype'     => $subtype,
					'object_id'   => $object_id,
					'blog_id'     => $source_blog,
					'target_blog' => $target_blog,
				)
			)
			: 'post_content',
		'object_ref'    => function_exists( 'wptsall_build_task_object_ref' )
			? wptsall_build_task_object_ref(
				array(
					'object_type' => ( $object_type === 'post' ? 'post_type' : $object_type ),
					'subtype'     => $subtype,
					'object_id'   => $object_id,
					'blog_id'     => $source_blog,
					'target_blog' => $target_blog,
				)
			)
			: array(),
		'source'        => 'insert_task_single',
		'fields'        => $fields,
	);
	if ( function_exists( 'wptsall_build_client_task_payload' ) ) {
		$payload = wptsall_build_client_task_payload( $payload, 'text' );
	}

	$object_type_for_storage = $object_type === 'post' ? 'post_type' : $object_type;
	$task_type               = sanitize_key( (string) ( $payload['task_type'] ?? 'text' ) );
	if ( function_exists( 'wptsall_find_open_task_for_insert' ) && in_array( sanitize_key( $status ), wptsall_get_open_task_statuses_for_insert(), true ) ) {
		$existing = wptsall_find_open_task_for_insert(
			$table,
			0,
			sanitize_key( $template ),
			$object_type_for_storage,
			sanitize_key( $subtype ),
			(int) $object_id,
			$task_type
		);
		if ( $existing ) {
			return (int) $existing['id'];
		}
	}

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $inserted = $wpdb->insert(
        $table,
        array(
            'blog_id'           => $source_blog,
            'target_blog'       => $target_blog,
            'target_type'       => 'wp',
            'target_identifier' => (string) $target_blog,
            'site_id'           => 0,
            'relation_id'       => 0,
            'site_mode'         => '',
            'template'          => $template,
            'object_type'       => $object_type_for_storage,
            'subtype'           => $subtype,
            'object_id'         => $object_id,
            'payload'           => wp_json_encode( $payload ),
            'lang_from'         => '',
            'lang_to'           => '',
            'status'            => $status,
            'status_note'       => '',
            'retry_count'       => 0,
            'created_at'        => current_time( 'mysql' ),
            'updated_at'        => current_time( 'mysql' ),
        ),
        array( '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
    );

    if ( $inserted ) {
        return $wpdb->insert_id;
    }
    return false;
}

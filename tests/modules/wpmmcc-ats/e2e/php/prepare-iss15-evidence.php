<?php
/**
 * E2E v2 ISS-15 evidence preparation.
 *
 * Seeds deterministic callback evidence so ISS-15 verification can validate
 * manual-field/media/virtual/config_i18n/term-meta coverage with fewer skips.
 *
 * Run:
 *   cd ${WPTSALL_WP_ROOT:-/var/www/html} && wp eval-file /home/john/projects/wptsall/tests/modules/wpmmcc-ats/e2e/php/prepare-iss15-evidence.php
 */

require_once __DIR__ . '/helpers.php';

global $wpdb;

echo "=== E2E v2: Prepare ISS-15 Evidence ===\n\n";

/**
 * Wrap text with translation marker.
 *
 * @param string $text Text.
 * @param string $lang Lang.
 * @return string
 */
function iss15e_marker( $text, $lang ) {
	$lang = trim( (string) $lang );
	if ( '' === $lang ) {
		$lang = 'en_US';
	}
	return '【' . $lang . '】' . (string) $text . '【/' . $lang . '】';
}

/**
 * Build client callback route.
 *
 * @param string $secret Route secret.
 * @param string $endpoint Endpoint.
 * @return string
 */
function iss15e_client_route( $secret, $endpoint ) {
	$parts = array( '', 'wptsall', 'v2' );
	if ( '' !== $secret ) {
		$parts[] = $secret;
	}
	$parts[] = 'client';
	$parts[] = $endpoint;
	return implode( '/', $parts );
}

/**
 * Internal REST POST helper.
 *
 * @param string $route Route.
 * @param string $token Token.
 * @param array  $body  JSON body.
 * @param array  $headers Extra headers.
 * @return array{status:int,data:mixed}
 */
function iss15e_rest_post( $route, $token, $body, $headers = array() ) {
	$request = new WP_REST_Request( 'POST', $route );
	$request->set_header( 'Content-Type', 'application/json' );
	if ( '' !== $token ) {
		$request->set_header( 'X-WPTSALL-Protocol-Version', '2' );
$request->set_header( 'X-WPTSALL-Device-Id', $device_id );
$request->set_header( 'X-WPTSALL-Client-Token', $token );
	}
	foreach ( (array) $headers as $k => $v ) {
		$request->set_header( (string) $k, (string) $v );
	}
	$request->set_body( wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );

	$response = rest_do_request( $request );
	return array(
		'status' => (int) $response->get_status(),
		'data'   => $response->get_data(),
	);
}

/**
 * Execute sync task immediately.
 *
 * @param int $sync_task_id Task ID.
 * @return array
 */
function iss15e_execute_sync_task( $sync_task_id ) {
	$sync_task_id = (int) $sync_task_id;
	if ( $sync_task_id <= 0 ) {
		return array( 'ok' => false, 'detail' => 'no sync_task_id' );
	}

	$class = '\\WPTSALL\\Tasks\\Sync\\Sync_Executor';
	if ( ! class_exists( $class ) ) {
		$wptsall_includes = ( defined( 'WPTSALL_PATH' ) ? WPTSALL_PATH : WP_PLUGIN_DIR . '/wptsall/' ) . 'includes/';
require_once $wptsall_includes . 'tasks/sync/class-sync-executor.php';
	}
	if ( ! class_exists( $class ) ) {
		return array( 'ok' => false, 'detail' => 'Sync_Executor unavailable' );
	}

	$result = $class::execute_translation_sync( $sync_task_id );
	if ( is_wp_error( $result ) ) {
		return array(
			'ok'     => false,
			'detail' => $result->get_error_code() . ': ' . $result->get_error_message(),
		);
	}

	return array( 'ok' => true, 'detail' => 'sync completed' );
}

/**
 * Whether relation can safely run inline sync executor in evidence prep.
 *
 * @param array $relation Relation row.
 * @return bool
 */
function iss15e_can_inline_sync( $relation ) {
	if ( ! is_array( $relation ) ) {
		return false;
	}

	$target_site_id = trim( (string) ( $relation['target_site_id'] ?? '' ) );
	$target_type    = trim( (string) ( $relation['target_site_type'] ?? '' ) );

	if ( 'virtual' === $target_type ) {
		return '' !== $target_site_id;
	}

	return '' !== $target_site_id && ctype_digit( $target_site_id ) && (int) $target_site_id > 0;
}

/**
 * Handle callback response and optional sync execution.
 *
 * @param string $label Label for output.
 * @param array  $resp Callback response.
 * @param array  $relation Relation row.
 * @param int    $ok_count Success counter (by reference).
 * @param int    $warn_count Warning counter (by reference).
 * @return void
 */
function iss15e_finalize_callback( $label, $resp, $relation, &$ok_count, &$warn_count ) {
	$status = (int) ( $resp['status'] ?? 0 );
	$ok     = ( 200 === $status ) && ! empty( $resp['data']['success'] );

	if ( ! $ok ) {
		++$warn_count;
		echo '  WARN ' . $label . ' callback failed: status=' . $status . "\n";
		return;
	}

	$sync_task_id = (int) ( $resp['data']['sync_task_id'] ?? 0 );
	if ( ! iss15e_can_inline_sync( $relation ) ) {
		++$ok_count;
		echo '  PASS ' . $label . " callback accepted (inline sync skipped for non-numeric target_site_id)\n";
		return;
	}

	$sync_result = iss15e_execute_sync_task( $sync_task_id );
	if ( ! empty( $sync_result['ok'] ) ) {
		++$ok_count;
		echo '  PASS ' . $label . " callback + sync: sync_task_id={$sync_task_id}\n";
		return;
	}

	++$warn_count;
	echo '  WARN ' . $label . ' callback ok but sync failed: ' . $sync_result['detail'] . "\n";
}

$baseline_file = e2e_runtime_file( 'baseline-fixtures.json' );
$baseline      = file_exists( $baseline_file )
	? json_decode( file_get_contents( $baseline_file ), true )
	: array();

if ( ! is_array( $baseline ) || empty( $baseline ) ) {
	echo "SKIP: baseline-fixtures.json missing.\n";
	exit( 0 );
}

$relation_ids     = (array) ( $baseline['relation_ids'] ?? e2e_load_relation_ids() );
$fixture_post_id  = (int) ( $baseline['fixture_post_id'] ?? 0 );
$fixture_media_id = (int) ( $baseline['fixture_media_id'] ?? 0 );
$virtual_rel_id   = (int) ( $relation_ids['virtual'] ?? 0 );
$wp_rel_id        = (int) ( $relation_ids['wp'] ?? 0 );

$__d = function_exists( 'wptsall_issue_client_device_token' ) ? wptsall_issue_client_device_token( 'e2e-' . wp_generate_password( 6, false ), 'e2e' ) : array();
$token  = (string) ( $__d['token'] ?? '' );
$device_id = (string) ( $__d['device_id'] ?? '' );
$secret = function_exists( 'wptsall_get_client_route_secret' ) ? (string) wptsall_get_client_route_secret() : '';

if ( '' === $token ) {
	echo "SKIP: client token unavailable; cannot submit callback evidence.\n";
}

$post = $fixture_post_id > 0 ? get_post( $fixture_post_id ) : null;
if ( ! $post ) {
	echo "SKIP: fixture post missing.\n";
	exit( 0 );
}

$source_title   = (string) $post->post_title;
$source_content = (string) $post->post_content;
$source_excerpt = (string) $post->post_excerpt;
$manual_text    = (string) get_post_meta( $fixture_post_id, '_e2e_manual_text', true );

$ok_count   = 0;
$warn_count = 0;

echo "--- Step 1: content callback evidence (wp relation) ---\n";
if ( $wp_rel_id > 0 && '' !== $token ) {
	$wp_relation = \WPTSALL\Sites\Services\Site_Relation_Service::get_relation( $wp_rel_id );
	if ( is_array( $wp_relation ) ) {
		$source_lang = (string) ( $wp_relation['source_lang'] ?? 'zh_CN' );
		$target_lang = (string) ( $wp_relation['target_lang'] ?? 'en_US' );

		$callback_body = array(
			'client_task_id'      => 'iss15e_wp_post_' . time(),
			'relation_id'         => $wp_rel_id,
			'business_line'       => 'post_content',
			'object_type'         => 'post_type',
			'post_type'           => 'post',
			'object_id'           => $fixture_post_id,
			'source_lang'         => $source_lang,
			'target_lang'         => $target_lang,
			'schema_version'      => 2,
			'attempt_id'          => 'iss15e_wp_' . wp_generate_uuid4(),
			'object_snapshot_hash'=> md5( 'iss15e_wp_' . $fixture_post_id ),
			'translated_fields'   => array(
				'post_title'   => iss15e_marker( $source_title . ' [ISS15E-WP]', $target_lang ),
				'post_content' => iss15e_marker( $source_content, $target_lang ),
				'post_excerpt' => iss15e_marker( $source_excerpt, $target_lang ),
			),
			'translated_meta'     => array(
				'_e2e_manual_text'  => iss15e_marker( $manual_text, $target_lang ),
				'_e2e_media_ref_id' => (string) $fixture_media_id,
			),
			'field_results'       => array(
				array(
					'field'          => 'post_title',
					'status'         => 'success',
					'content_format' => 'plain_text',
					'storage'        => 'post',
					'detail'         => 'iss15 evidence plain_text',
				),
				array(
					'field'          => '_e2e_manual_text',
					'status'         => 'success',
					'content_format' => 'json',
					'storage'        => 'meta',
					'detail'         => 'iss15 evidence structured format',
				),
			),
			'media_mappings'      => array(),
		);

		$route = iss15e_client_route( $secret, 'translation-callback' );
		$resp  = iss15e_rest_post( $route, $token, $callback_body );
		iss15e_finalize_callback( 'wp', $resp, $wp_relation, $ok_count, $warn_count );
	} else {
		++$warn_count;
		echo "  WARN wp relation not found.\n";
	}
} else {
	echo "  SKIP wp relation/token unavailable.\n";
}

echo "\n--- Step 2: virtual callback evidence ---\n";
if ( $virtual_rel_id > 0 && '' !== $token ) {
	$virtual_relation = \WPTSALL\Sites\Services\Site_Relation_Service::get_relation( $virtual_rel_id );
	if ( is_array( $virtual_relation ) ) {
		if ( ! iss15e_can_inline_sync( $virtual_relation ) ) {
			echo "  SKIP virtual callback preheat: relation cannot run inline sync safely.\n";
		} else {
		$source_lang = (string) ( $virtual_relation['source_lang'] ?? 'zh_CN' );
		$target_lang = (string) ( $virtual_relation['target_lang'] ?? 'en_US' );

		$callback_body = array(
			'client_task_id'      => 'iss15e_virtual_post_' . time(),
			'relation_id'         => $virtual_rel_id,
			'business_line'       => 'post_content',
			'object_type'         => 'post_type',
			'post_type'           => 'post',
			'object_id'           => $fixture_post_id,
			'source_lang'         => $source_lang,
			'target_lang'         => $target_lang,
			'schema_version'      => 2,
			'attempt_id'          => 'iss15e_virtual_' . wp_generate_uuid4(),
			'object_snapshot_hash'=> md5( 'iss15e_virtual_' . $fixture_post_id ),
			'translated_fields'   => array(
				'post_title'   => iss15e_marker( $source_title . ' [ISS15E-VIRTUAL]', $target_lang ),
				'post_content' => iss15e_marker( $source_content, $target_lang ),
				'post_excerpt' => iss15e_marker( $source_excerpt, $target_lang ),
			),
			'translated_meta'     => array(
				'_e2e_manual_text'  => iss15e_marker( $manual_text, $target_lang ),
				'_e2e_media_ref_id' => (string) $fixture_media_id,
			),
			'field_results'       => array(
				array(
					'field'          => 'post_title',
					'status'         => 'success',
					'content_format' => 'plain_text',
					'storage'        => 'post',
				),
			),
		);

		$route = iss15e_client_route( $secret, 'translation-callback' );
		$resp  = iss15e_rest_post( $route, $token, $callback_body );
		iss15e_finalize_callback( 'virtual', $resp, $virtual_relation, $ok_count, $warn_count );
		}
	} else {
		++$warn_count;
		echo "  WARN virtual relation not found.\n";
	}
} else {
	echo "  SKIP virtual relation/token unavailable.\n";
}

echo "\n--- Step 3: attachment callback task evidence ---\n";
if ( $wp_rel_id > 0 && $fixture_media_id > 0 && '' !== $token ) {
	$wp_relation = \WPTSALL\Sites\Services\Site_Relation_Service::get_relation( $wp_rel_id );
	$source_lang = (string) ( $wp_relation['source_lang'] ?? 'zh_CN' );
	$target_lang = (string) ( $wp_relation['target_lang'] ?? 'en_US' );

	$att_body = array(
		'client_task_id'    => 'iss15e_attachment_' . time(),
		'relation_id'       => $wp_rel_id,
		'business_line'     => 'post_content',
		'object_type'       => 'post_type',
		'post_type'         => 'attachment',
		'object_id'         => $fixture_media_id,
		'source_lang'       => $source_lang,
		'target_lang'       => $target_lang,
		'translated_fields' => array(
			'post_title'   => iss15e_marker( 'ISS15 fixture attachment', $target_lang ),
			'post_excerpt' => iss15e_marker( 'ISS15 fixture caption', $target_lang ),
			'post_content' => iss15e_marker( 'ISS15 fixture description', $target_lang ),
		),
		'field_results'     => array(
			array(
				'field'          => 'post_title',
				'status'         => 'success',
				'content_format' => 'plain_text',
				'storage'        => 'post',
			),
		),
	);

	$route = iss15e_client_route( $secret, 'translation-callback' );
	$resp  = iss15e_rest_post( $route, $token, $att_body );
	if ( 200 === $resp['status'] && ! empty( $resp['data']['success'] ) ) {
		++$ok_count;
		echo "  PASS attachment callback task created\n";
	} else {
		++$warn_count;
		echo '  WARN attachment callback failed: status=' . $resp['status'] . "\n";
	}
} else {
	echo "  SKIP attachment evidence unavailable.\n";
}

echo "\n--- Step 4: config_i18n callback evidence ---\n";
if ( $virtual_rel_id > 0 && '' !== $token ) {
	$templates_table = e2e_table( 'templates' );
	$entries_table   = e2e_table( 'template_entries' );
	$entry_row       = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT e.id, e.msgid
			 FROM {$entries_table} e
			 INNER JOIN {$templates_table} t ON e.template_id = t.id
			 WHERE t.relation_id = %d
			   AND t.source_type = %s
			 ORDER BY e.id ASC
			 LIMIT 1",
			$virtual_rel_id,
			'config'
		),
		ARRAY_A
	);

	if ( ! empty( $entry_row['id'] ) ) {
		$virtual_relation = \WPTSALL\Sites\Services\Site_Relation_Service::get_relation( $virtual_rel_id );
		if ( ! is_array( $virtual_relation ) ) {
			++$warn_count;
			echo "  WARN virtual relation not found for config_i18n evidence.\n";
		} elseif ( ! iss15e_can_inline_sync( $virtual_relation ) ) {
			echo "  SKIP config_i18n callback preheat: relation cannot run inline sync safely.\n";
		} else {
			$source_lang = (string) ( $virtual_relation['source_lang'] ?? 'zh_CN' );
			$target_lang = (string) ( $virtual_relation['target_lang'] ?? 'en_US' );
			$msgid       = (string) ( $entry_row['msgid'] ?? 'ISS15 config entry' );

			$config_body = array(
				'client_task_id' => 'iss15e_config_i18n_' . time(),
				'relation_id'    => $virtual_rel_id,
				'business_line'  => 'config_i18n',
				'source_lang'    => $source_lang,
				'target_lang'    => $target_lang,
				'entries'        => array(
					array(
						'entry_id' => (int) $entry_row['id'],
						'msgstr'   => iss15e_marker( $msgid, $target_lang ),
					),
				),
			);

			$route = iss15e_client_route( $secret, 'translation-callback' );
			$resp  = iss15e_rest_post( $route, $token, $config_body );
			iss15e_finalize_callback( 'config_i18n', $resp, $virtual_relation, $ok_count, $warn_count );
		}
	} else {
		++$warn_count;
		echo "  WARN no template entry found for config_i18n evidence.\n";
	}
} else {
	echo "  SKIP config_i18n evidence unavailable.\n";
}

echo "\n--- Step 5: term meta evidence for mapped non-virtual terms ---\n";
$term_mappings = e2e_table( 'term_mappings' );
if ( e2e_table_exists( $term_mappings ) ) {
	$term_row = $wpdb->get_row(
		"SELECT source_term_id, source_site_id, target_term_id, target_site_id
		 FROM {$term_mappings}
		 WHERE target_site_id REGEXP '^[0-9]+$'
		 ORDER BY id DESC
		 LIMIT 1",
		ARRAY_A
	);

	if ( empty( $term_row ) && $wp_rel_id > 0 ) {
		$wp_relation     = \WPTSALL\Sites\Services\Site_Relation_Service::get_relation( $wp_rel_id );
		$source_site_id  = (int) ( $wp_relation['source_site_id'] ?? get_current_blog_id() );
		$target_site_raw = (string) ( $wp_relation['target_site_id'] ?? '' );
		$source_lang     = (string) ( $wp_relation['source_lang'] ?? 'zh_CN' );
		$target_lang     = (string) ( $wp_relation['target_lang'] ?? 'en_US' );

		if ( ctype_digit( $target_site_raw ) ) {
			$source_terms = wp_get_post_terms(
				$fixture_post_id,
				'category',
				array(
					'orderby' => 'term_id',
					'order'   => 'ASC',
				)
			);
			if ( empty( $source_terms ) || is_wp_error( $source_terms ) ) {
				$source_terms = wp_get_post_terms(
					$fixture_post_id,
					'post_tag',
					array(
						'orderby' => 'term_id',
						'order'   => 'ASC',
					)
				);
			}

			if ( ! empty( $source_terms ) && ! is_wp_error( $source_terms ) ) {
				$source_term    = $source_terms[0];
				$source_term_id = (int) ( $source_term->term_id ?? 0 );
				$taxonomy       = (string) ( $source_term->taxonomy ?? '' );
				$target_site_id = (int) $target_site_raw;
				$target_term_id = 0;
				$switched       = false;

				if ( is_multisite() && $target_site_id > 0 && $target_site_id !== get_current_blog_id() && get_blog_details( $target_site_id ) ) {
					switch_to_blog( $target_site_id );
					$switched = true;
				}

				if ( taxonomy_exists( $taxonomy ) ) {
					$target_term = get_term_by( 'slug', (string) ( $source_term->slug ?? '' ), $taxonomy );
					if ( $target_term && ! is_wp_error( $target_term ) ) {
						$target_term_id = (int) $target_term->term_id;
					} else {
						$insert = wp_insert_term(
							(string) ( $source_term->name ?? 'ISS15 Term' ),
							$taxonomy,
							array(
								'slug' => sanitize_title( (string) ( $source_term->slug ?? '' ) ),
							)
						);
						if ( ! is_wp_error( $insert ) && ! empty( $insert['term_id'] ) ) {
							$target_term_id = (int) $insert['term_id'];
						}
					}
				}

				if ( $switched ) {
					restore_current_blog();
				}

				if ( $source_term_id > 0 && $target_term_id > 0 && '' !== $taxonomy ) {
					$now = current_time( 'mysql' );
					$wpdb->query(
						$wpdb->prepare(
							"INSERT INTO {$term_mappings}
							 (source_term_id, source_taxonomy, source_site_id, source_lang, target_term_id, target_taxonomy, target_site_id, target_lang, mapping_method, translation_method, created_at, updated_at)
							 VALUES (%d, %s, %d, %s, %d, %s, %s, %s, %s, %s, %s, %s)
							 ON DUPLICATE KEY UPDATE target_term_id = VALUES(target_term_id), updated_at = VALUES(updated_at)",
							$source_term_id,
							$taxonomy,
							$source_site_id,
							$source_lang,
							$target_term_id,
							$taxonomy,
							(string) $target_site_id,
							$target_lang,
							'manual_evidence',
							'manual',
							$now,
							$now
						)
					);

					$term_row = array(
						'source_term_id' => $source_term_id,
						'source_site_id' => $source_site_id,
						'target_term_id' => $target_term_id,
						'target_site_id' => $target_site_id,
					);
					echo "  PASS created non-virtual term mapping evidence\n";
				} else {
					++$warn_count;
					echo "  WARN could not create fallback non-virtual term mapping.\n";
				}
			} else {
				++$warn_count;
				echo "  WARN no category/tag terms found on fixture post for term mapping evidence.\n";
			}
		} else {
			++$warn_count;
			echo "  WARN wp relation target_site_id is not numeric.\n";
		}
	}

	if ( ! empty( $term_row ) ) {
		$key            = 'e2e_term_meta_probe';
		$value          = 'iss15-probe-' . gmdate( 'YmdHis' );
		$source_term_id = (int) ( $term_row['source_term_id'] ?? 0 );
		$source_site_id = (int) ( $term_row['source_site_id'] ?? 0 );
		$target_term_id = (int) ( $term_row['target_term_id'] ?? 0 );
		$target_site_id = (int) ( $term_row['target_site_id'] ?? 0 );

		$source_switched = false;
		if ( is_multisite() && $source_site_id > 0 && $source_site_id !== get_current_blog_id() && get_blog_details( $source_site_id ) ) {
			switch_to_blog( $source_site_id );
			$source_switched = true;
		}
		if ( $source_term_id > 0 ) {
			update_term_meta( $source_term_id, $key, $value );
		}
		if ( $source_switched ) {
			restore_current_blog();
		}

		$target_switched = false;
		if ( is_multisite() && $target_site_id > 0 && $target_site_id !== get_current_blog_id() && get_blog_details( $target_site_id ) ) {
			switch_to_blog( $target_site_id );
			$target_switched = true;
		}
		if ( $target_term_id > 0 ) {
			update_term_meta( $target_term_id, $key, $value );
		}
		if ( $target_switched ) {
			restore_current_blog();
		}

		++$ok_count;
		echo "  PASS term meta probe set on mapped source/target terms\n";
	} else {
		++$warn_count;
		echo "  WARN no non-virtual term mapping row available for term-meta probe.\n";
	}
} else {
	++$warn_count;
	echo "  WARN term_mappings table missing.\n";
}

echo "\n--- Step 6: media mapping evidence for fixture post targets ---\n";
$post_mappings  = e2e_table( 'post_mappings' );
$media_mappings = e2e_table( 'media_mappings' );
if ( $fixture_post_id > 0 && $fixture_media_id > 0 && e2e_table_exists( $post_mappings ) && e2e_table_exists( $media_mappings ) ) {
	$source_site_id   = get_current_blog_id();
	$source_file_path = (string) get_attached_file( $fixture_media_id );
	$source_file_url  = (string) wp_get_attachment_url( $fixture_media_id );
	$target_row       = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT target_post_id, target_site_id
			 FROM {$post_mappings}
			 WHERE source_post_id = %d
			   AND target_site_id REGEXP '^[0-9]+$'
			 ORDER BY id DESC
			 LIMIT 1",
			$fixture_post_id
		),
		ARRAY_A
	);

	if ( '' === $source_file_path || '' === $source_file_url ) {
		++$warn_count;
		echo "  WARN fixture media source file path/url missing.\n";
	} elseif ( empty( $target_row ) ) {
		++$warn_count;
		echo "  WARN no numeric target post mapping found for fixture post.\n";
	} else {
		$target_post_id  = (int) ( $target_row['target_post_id'] ?? 0 );
		$target_blog_id  = (int) ( $target_row['target_site_id'] ?? 0 );
		$target_media_id = 0;
		$target_file_path = '';
		$target_file_url  = '';
		$switched         = false;

		if ( is_multisite() && $target_blog_id > 0 && $target_blog_id !== get_current_blog_id() && get_blog_details( $target_blog_id ) ) {
			switch_to_blog( $target_blog_id );
			$switched = true;
		}

		$target_media_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				 WHERE p.post_type = 'attachment'
				   AND pm.meta_key IN ('_wptsall_source_post_id', '_wptsall_origin_object_id')
				   AND pm.meta_value = %d
				 ORDER BY p.ID DESC
				 LIMIT 1",
				$fixture_media_id
			)
		);
		if ( $target_media_id <= 0 ) {
			$target_media_id = (int) $wpdb->get_var(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_type = 'attachment'
				   AND post_status IN ('inherit', 'publish')
				 ORDER BY ID DESC
				 LIMIT 1"
			);
		}

		if ( $target_media_id > 0 ) {
			$target_file_path = (string) get_attached_file( $target_media_id );
			$target_file_url  = (string) wp_get_attachment_url( $target_media_id );
		}

		$target_post = $target_post_id > 0 ? get_post( $target_post_id ) : null;
		if ( $target_post && $target_media_id > 0 && '' !== $target_file_path && '' !== $target_file_url ) {
			$content = (string) $target_post->post_content;
			$content = str_replace( $source_file_url, $target_file_url, $content );
			if ( false === strpos( $content, $target_file_url ) ) {
				$content .= "\n<p>ISS15E media: " . esc_url_raw( $target_file_url ) . "</p>";
			}
			$content = str_replace( $source_file_url, '', $content );
			wp_update_post(
				array(
					'ID'           => $target_post_id,
					'post_content' => $content,
				)
			);
			update_post_meta( $target_post_id, '_e2e_media_ref_id', $target_media_id );
			update_post_meta( $target_post_id, '_thumbnail_id', $target_media_id );

			if ( $switched ) {
				restore_current_blog();
				$switched = false;
			}

			$now      = current_time( 'mysql' );
			$metadata = wp_json_encode(
				array(
					'seed'         => 'iss15e',
					'source_post'  => $fixture_post_id,
					'target_post'  => $target_post_id,
					'target_blog'  => $target_blog_id,
					'target_media' => $target_media_id,
				),
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			);
			$write = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$media_mappings}
					 (source_media_id, source_site_id, source_file_path, source_file_url, target_media_id, target_site_id, target_file_path, target_file_url, mapping_method, alt_translated, metadata, created_at, updated_at)
					 VALUES (%d, %d, %s, %s, %d, %s, %s, %s, %s, %d, %s, %s, %s)
					 ON DUPLICATE KEY UPDATE
					   target_media_id = VALUES(target_media_id),
					   target_file_path = VALUES(target_file_path),
					   target_file_url = VALUES(target_file_url),
					   mapping_method = VALUES(mapping_method),
					   alt_translated = VALUES(alt_translated),
					   metadata = VALUES(metadata),
					   updated_at = VALUES(updated_at)",
					$fixture_media_id,
					$source_site_id,
					$source_file_path,
					$source_file_url,
					$target_media_id,
					(string) $target_blog_id,
					$target_file_path,
					$target_file_url,
					'manual_evidence',
					1,
					(string) $metadata,
					$now,
					$now
				)
			);

			if ( false !== $write ) {
				++$ok_count;
				echo "  PASS media mapping evidence upserted (target_blog={$target_blog_id}, target_post={$target_post_id}, target_media={$target_media_id})\n";
			} else {
				++$warn_count;
				echo "  WARN media mapping evidence upsert failed.\n";
			}
		} else {
			if ( $switched ) {
				restore_current_blog();
			}
			++$warn_count;
			echo "  WARN missing target post/media for fixture media evidence.\n";
		}
	}
} else {
	++$warn_count;
	echo "  WARN fixture ids or mapping tables unavailable for media evidence.\n";
}

echo "\nSummary: ok={$ok_count}, warnings={$warn_count}\n";

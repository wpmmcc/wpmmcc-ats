<?php
/**
 * Client Data REST Controller claim trait.
 *
 * Extracted from Client_Data_REST_Controller to isolate content claim flows
 * from discovery and callback handling.
 *
 * @package WPTSALL\Tasks\API
 */

namespace WPTSALL\Tasks\API;

use WPTSALL\Sites\Services\Site_Relation_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and intentional meta/tax lookups; all dynamic values go through $wpdb->prepare() / wptsall_db_* helpers (no unprepared user input).
trait Client_Data_REST_Controller_Claim_Trait {

	/**
	 * POST /client/content/claim
	 *
	 * Client declares it is processing a batch of content items.
	 * Uses a simple time lock: sets `claimed_at` in post_mappings.
	 * Claims expire after 30 minutes (unclaimed items reappear in content discovery).
	 *
	 * Body: { "relation_id": int, "items": [ { "object_id": int, "post_type": string } ] }
	 *
	 * @since 1.2.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function claim_content( $request ) {
		$rate_error = $this->check_rate_limit( 'content_claim' );
		if ( $rate_error ) {
			return $rate_error;
		}

		$body        = $request->get_json_params();
		$relation_id = absint( $body['relation_id'] ?? 0 );
		$items       = $body['items'] ?? array();

		if ( empty( $relation_id ) ) {
			return new \WP_REST_Response(
				array( 'success' => false, 'error' => 'missing_relation_id', 'message' => 'relation_id is required.' ),
				400
			);
		}

		if ( empty( $items ) || ! is_array( $items ) ) {
			return new \WP_REST_Response(
				array( 'success' => false, 'error' => 'missing_items', 'message' => 'items array is required.' ),
				400
			);
		}

		$relation = Site_Relation_Service::get_relation( $relation_id );
		if ( ! $relation ) {
			return new \WP_REST_Response(
				array( 'success' => false, 'error' => 'relation_not_found', 'message' => 'Site relation not found.' ),
				404
			);
		}

		if ( 'active' !== sanitize_key( (string) ( $relation['status'] ?? '' ) ) ) {
			return new \WP_REST_Response(
				array( 'success' => false, 'error' => 'relation_inactive', 'message' => 'Site relation is not active.' ),
				409
			);
		}

		$data_type = $this->normalize_content_data_type( (string) ( $body['data_type'] ?? 'post' ) );

		if ( 'language_pack' === $data_type ) {
			return $this->claim_language_pack_entries( $relation_id, $items, $request, $relation );
		}

		if ( 'site_string' === $data_type ) {
			return $this->claim_site_string_entries( $relation_id, $items );
		}

		if ( 'option' === $data_type ) {
			$claimed_items = array();
			foreach ( $items as $item ) {
				$object_id = absint( $item['object_id'] ?? 0 );
				$subtype   = sanitize_key( (string) ( $item['subtype'] ?? $item['post_type'] ?? '' ) );
				if ( $object_id <= 0 || '' === $subtype ) {
					continue;
				}
				$claimed_items[] = array(
					'object_id' => $object_id,
					'post_type' => $subtype,
				);
			}

			return new \WP_REST_Response(
				array(
					'success'       => true,
					'claimed_count' => count( $claimed_items ),
					'claimed_items' => $claimed_items,
				),
				200
			);
		}

		if ( 'term' === $data_type ) {
			return $this->claim_term_content( $relation, $items );
		}

		global $wpdb;
		if ( function_exists( 'wptsall_ensure_relation_scoped_mapping_tables' ) ) {
			wptsall_ensure_relation_scoped_mapping_tables();
		}
		$mappings_table = wptsall_table( 'post_mappings' );
		$source_site_id = (int) ( $relation['source_site_id'] ?? get_current_blog_id() );
		$target_site_id = (string) ( $relation['target_site_id'] ?? '' );
		$now            = current_time( 'mysql', true );
		$claimed_count  = 0;
		$claimed_items  = array();

		foreach ( $items as $item ) {
			$object_id = absint( $item['object_id'] ?? 0 );
			$post_type = sanitize_key( $item['post_type'] ?? 'post' );

			if ( empty( $object_id ) ) {
				continue;
			}

			$claim_cutoff = gmdate( 'Y-m-d H:i:s', time() - self::get_claim_timeout_seconds() );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->query( $wpdb->prepare(
				"UPDATE %i SET claimed_at = %s
				 WHERE relation_id = %d
				 AND source_post_id = %d AND source_post_type = %s
				 AND source_site_id = %d AND target_site_id = %s
				 AND (claimed_at IS NULL OR claimed_at < %s)",
				$mappings_table, $now, $relation_id, $object_id, $post_type, $source_site_id, $target_site_id, $claim_cutoff
			) );

			if ( false !== $result && $result > 0 ) {
				++$claimed_count;
				$claimed_items[] = array(
					'object_id' => $object_id,
					'post_type' => $post_type,
				);
			} elseif ( 0 === $result ) {
				// No existing mapping row — insert a placeholder so the claim is tracked.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$inserted = $wpdb->insert(
					$mappings_table,
					array(
						'source_post_id'    => $object_id,
						'source_post_type'  => $post_type,
						'source_site_id'    => $source_site_id,
						'relation_id'       => $relation_id,
						'target_post_id'    => 0,
						'target_post_type'  => $post_type,
						'target_site_id'    => $target_site_id,
						'relationship_type' => 'claim_placeholder',
						'claimed_at'        => $now,
						'created_at'        => $now,
						'updated_at'        => $now,
					),
					array( '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
				);
				if ( $inserted ) {
					++$claimed_count;
					$claimed_items[] = array(
						'object_id' => $object_id,
						'post_type' => $post_type,
					);
				}
			}
		}

		wptsall_log_info(
			'client-api',
			'Content claimed by client',
			array(
				'relation_id'   => $relation_id,
				'items_sent'    => count( $items ),
				'claimed_count' => $claimed_count,
				'claimed_items' => count( $claimed_items ),
			)
		);

		return new \WP_REST_Response(
			array(
				'success'       => true,
				'claimed_count' => $claimed_count,
				'claimed_items' => $claimed_items,
			),
			200
		);
	}

	/**
	 * Claim taxonomy content items using term_mappings placeholders.
	 *
	 * Uses `target_term_id = 0` rows as claim placeholders and `updated_at`
	 * as the lock timestamp. Expired claims are reclaimable after timeout.
	 *
	 * @param array $relation Relation row.
	 * @param array $items    Items to claim.
	 * @return \WP_REST_Response
	 */
	private function claim_term_content( array $relation, array $items ) {
		global $wpdb;

		$table          = wptsall_table( 'term_mappings' );
		$source_site_id = (int) ( $relation['source_site_id'] ?? get_current_blog_id() );
		$target_site_id = (string) ( $relation['target_site_id'] ?? '' );
		$source_lang    = sanitize_text_field( (string) ( $relation['source_lang'] ?? '' ) );
		$target_lang    = sanitize_text_field( (string) ( $relation['target_lang'] ?? '' ) );
		$now            = current_time( 'mysql', true );
		$claim_cutoff   = gmdate( 'Y-m-d H:i:s', time() - self::get_claim_timeout_seconds() );
		$claimed_count  = 0;
		$claimed_items  = array();

		foreach ( $items as $item ) {
			$term_id  = absint( $item['object_id'] ?? 0 );
			$taxonomy = sanitize_key( (string) ( $item['taxonomy'] ?? $item['post_type'] ?? $item['subtype'] ?? '' ) );

			if ( $term_id <= 0 || '' === $taxonomy ) {
				continue;
			}

			// 1) Reclaim stale placeholder rows.
			$reclaimed = $wpdb->query(
				$wpdb->prepare(
					"UPDATE %i
					 SET claimed_at = %s,
					     updated_at = %s
					 WHERE source_term_id = %d
					   AND source_taxonomy = %s
					   AND source_site_id = %d
					   AND relation_id = %d
					   AND target_site_id = %s
					   AND target_lang = %s
					   AND target_term_id = 0
					   AND (claimed_at IS NULL OR claimed_at < %s)",
					$table,
					$now,
					$now,
					$term_id,
					$taxonomy,
					$source_site_id,
					$relation['id'],
					$target_site_id,
					$target_lang,
					$claim_cutoff
				)
			);
			if ( false !== $reclaimed && $reclaimed > 0 ) {
				++$claimed_count;
				$claimed_items[] = array(
					'object_id' => $term_id,
					'taxonomy'  => $taxonomy,
				);
				continue;
			}

			// 2) Check if there is an active mapping/claim.
				$existing = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT id, target_term_id, updated_at, claimed_at, needs_resync
						 FROM %i
					 WHERE source_term_id = %d
					   AND source_taxonomy = %s
					   AND source_site_id = %d
					   AND relation_id = %d
					   AND target_site_id = %s
					   AND target_lang = %s
					 LIMIT 1",
					$table,
					$term_id,
					$taxonomy,
					$source_site_id,
					$relation['id'],
					$target_site_id,
					$target_lang
				),
				ARRAY_A
			);
				if ( $existing ) {
					$mapped_target_id = (int) ( $existing['target_term_id'] ?? 0 );
					$needs_resync = ! empty( $existing['needs_resync'] );
					if ( $mapped_target_id > 0 && ! $needs_resync ) {
						continue;
					}
					if ( $mapped_target_id > 0 && $needs_resync ) {
						$claimed_at = (string) ( $existing['claimed_at'] ?? '' );
						if ( '' !== $claimed_at && strtotime( $claimed_at ) >= strtotime( $claim_cutoff ) ) {
							continue;
						}
						$claimed = $wpdb->update(
							$table,
							array(
								'claimed_at' => $now,
								'updated_at' => $now,
							),
							array( 'id' => (int) $existing['id'] ),
							array( '%s', '%s' ),
							array( '%d' )
						);
						if ( false !== $claimed ) {
							++$claimed_count;
							$claimed_items[] = array(
								'object_id' => $term_id,
								'taxonomy'  => $taxonomy,
							);
						}
						continue;
					}
					$claimed_at = (string) ( $existing['claimed_at'] ?? '' );
					if ( '' !== $claimed_at && strtotime( $claimed_at ) >= strtotime( $claim_cutoff ) ) {
						continue;
				}
				// Stale placeholder but reclaim UPDATE missed due race; retry without cutoff.
				$forced_reclaim = $wpdb->update(
					$table,
					array(
						'claimed_at' => $now,
						'updated_at' => $now,
					),
					array(
						'source_term_id' => $term_id,
						'source_taxonomy'=> $taxonomy,
						'source_site_id' => $source_site_id,
						'relation_id'    => (int) $relation['id'],
						'target_site_id' => $target_site_id,
						'target_lang'    => $target_lang,
						'target_term_id' => 0,
					),
					array( '%s', '%s' ),
					array( '%d', '%s', '%d', '%s', '%s', '%d' )
				);
				if ( false !== $forced_reclaim && $forced_reclaim > 0 ) {
					++$claimed_count;
					$claimed_items[] = array(
						'object_id' => $term_id,
						'taxonomy'  => $taxonomy,
					);
				}
				continue;
			}

			// 3) Insert a new placeholder claim row.
			$inserted = $wpdb->insert(
				$table,
				array(
					'source_term_id'     => $term_id,
					'source_taxonomy'    => $taxonomy,
					'source_site_id'     => $source_site_id,
					'relation_id'        => (int) $relation['id'],
						'source_lang'        => $source_lang,
						'target_term_id'     => 0,
						'target_taxonomy'    => $taxonomy,
						'target_site_id'     => $target_site_id,
						'target_lang'        => $target_lang,
						'needs_resync'       => 0,
						'claimed_at'         => $now,
						'mapping_method'     => 'claim_placeholder',
						'translation_method' => null,
						'created_at'         => $now,
						'updated_at'         => $now,
					),
					array( '%d', '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
				);
			if ( $inserted ) {
				++$claimed_count;
				$claimed_items[] = array(
					'object_id' => $term_id,
					'taxonomy'  => $taxonomy,
				);
			}
		}

		wptsall_log_info(
			'client-api',
			'Taxonomy content claimed by client',
			array(
				'relation_id'   => (int) ( $relation['id'] ?? 0 ),
				'items_sent'    => count( $items ),
				'claimed_count' => $claimed_count,
				'claimed_items' => count( $claimed_items ),
			)
		);

		return new \WP_REST_Response(
			array(
				'success'       => true,
				'claimed_count' => $claimed_count,
				'claimed_items' => $claimed_items,
			),
			200
		);
	}

	/**
	 * Claim language pack entries for client processing.
	 *
	 * @since 1.3.0
	 *
	 * @param int              $relation_id Relation ID.
	 * @param array            $items       Items to claim.
	 * @param \WP_REST_Request $request     Current request (used for claim ownership).
	 * @param array            $relation    Relation row.
	 * @return \WP_REST_Response
	 */
	private function claim_language_pack_entries( $relation_id, $items, $request = null, $relation = array() ) {
		global $wpdb;
		$entries_table   = wptsall_table( 'template_entries' );
		$templates_table = wptsall_table( 'templates' );
		$now             = current_time( 'mysql', true );
		$claim_cutoff    = gmdate( 'Y-m-d H:i:s', time() - self::get_claim_timeout_seconds() );
		$claimed_count   = 0;
		$claimed_items   = array();

		foreach ( $items as $item ) {
			$entry_id = absint( $item['entry_id'] ?? $item['object_id'] ?? 0 );
			if ( empty( $entry_id ) ) {
				continue;
			}

			// The i18n callback validates entry ownership against this digest, so
			// stamp it at claim time with the template's own source_type scope; a
			// later plugin_i18n/theme_i18n callback proves the same device claimed it.
			$entry_row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT e.id, t.source_type FROM %i e INNER JOIN %i t ON e.template_id = t.id
					 WHERE e.id = %d AND t.relation_id = %d LIMIT 1",
					$entries_table,
					$templates_table,
					$entry_id,
					$relation_id
				),
				ARRAY_A
			);
			if ( ! is_array( $entry_row ) ) {
				continue;
			}
			$entry_scope      = sanitize_key( (string) ( $entry_row['source_type'] ?? '' ) );
			$claim_owner_hash = $request instanceof \WP_REST_Request
				? $this->get_claim_owner_hash( $request, $relation, $entry_scope, '' )
				: '';

			$result = $wpdb->query( $wpdb->prepare(
				"UPDATE %i e INNER JOIN %i t ON e.template_id = t.id
				 SET e.claimed_at = %s, e.claim_owner_hash = %s
				 WHERE e.id = %d
				 AND t.relation_id = %d
				 AND e.status = 'pending'
				 AND (e.claimed_at IS NULL OR e.claimed_at < %s)",
				$entries_table, $templates_table, $now, $claim_owner_hash, $entry_id, $relation_id, $claim_cutoff
			) );

			if ( false !== $result && $result > 0 ) {
				++$claimed_count;
				$claimed_items[] = array(
					'entry_id' => $entry_id,
				);
			}
		}

		wptsall_log_info( 'client-api', 'Language pack entries claimed', array(
			'relation_id'   => $relation_id,
			'items_sent'    => count( $items ),
			'claimed_count' => $claimed_count,
			'claimed_items' => count( $claimed_items ),
		) );

		return new \WP_REST_Response( array(
			'success'       => true,
			'claimed_count' => $claimed_count,
			'claimed_items' => $claimed_items,
		), 200 );
	}

	/**
	 * Claim Layer B site strings for client processing.
	 *
	 * @param int    $relation_id Relation ID (used for active-relation validation by the caller).
	 * @param array  $items       Items with string_id or object_id.
	 * @param string $subtype         site|menu|widget context group.
	 * @param string $claim_owner_hash Device/relation/language owner digest.
	 * @param string $target_lang      Relation target language.
	 * @return \WP_REST_Response
	 */
	private function claim_site_string_entries( $relation_id, $items, $subtype = '', $claim_owner_hash = '', $target_lang = '' ) {
		unset( $relation_id );
		$string_ids = array();
		foreach ( (array) $items as $item ) {
			$id = absint( $item['string_id'] ?? $item['entry_id'] ?? $item['object_id'] ?? 0 );
			if ( $id > 0 ) {
				$string_ids[] = $id;
			}
		}
		$contexts = array();
		if ( class_exists( '\\WPTSALL\\Strings\\Services\\String_Translation_Service' ) ) {
			$contexts = \WPTSALL\Strings\Services\String_Translation_Service::contexts_for_subtype( $subtype );
		}
		$claimed_ids = array();
		if ( class_exists( '\\WPTSALL\\Strings\\Services\\String_Translation_Service' ) && ! empty( $contexts ) ) {
			$claimed_ids = \WPTSALL\Strings\Services\String_Translation_Service::claim_string_ids( $string_ids, $contexts, $claim_owner_hash, $target_lang );
		}
		$claimed_items = array();
		foreach ( $claimed_ids as $id ) {
			$claimed_items[] = array(
				'entry_id'  => (int) $id,
				'string_id' => (int) $id,
			);
		}
		return new \WP_REST_Response(
			array(
				'success'       => true,
				'claimed_count' => count( $claimed_ids ),
				'claimed_items' => $claimed_items,
			),
			200
		);
	}
}

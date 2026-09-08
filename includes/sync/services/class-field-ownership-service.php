<?php
/**
 * Field ownership + conflict recording for write-back CAS.
 *
 * When a human edits a translated (shadow) field in WP, that field becomes
 * `wp_manual`. Subsequent Client machine results for the same field are not
 * applied; a conflict row is stored instead.
 *
 * @package WPTSALL\Sync
 * @since 2.0.1
 */

namespace WPTSALL\Sync\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Field_Ownership_Service class.
 */
class Field_Ownership_Service {

	const META_KEY = '_wptsall_field_owners';

	/**
	 * @return void
	 */
	public static function init() {
		self::ensure_schema();
		// post_updated provides both before and after values, which is essential
		// for field-level ownership. save_post only tells us that an object was
		// saved, and previously caused one edited field to lock every text field.
		add_action( 'post_updated', array( __CLASS__, 'on_post_updated' ), 30, 3 );
		add_action( 'edited_term', array( __CLASS__, 'on_edited_term' ), 30, 3 );
		// Meta edits on shadow/mapped targets become wp_manual (machine must not clobber).
		add_action( 'updated_post_meta', array( __CLASS__, 'on_updated_post_meta' ), 30, 4 );
		add_action( 'added_post_meta', array( __CLASS__, 'on_updated_post_meta' ), 30, 4 );
	}

	/**
	 * Ensure conflicts table exists.
	 *
	 * @return void
	 */
	public static function ensure_schema() {
		require_once dirname( __DIR__, 2 ) . '/sync/database/schema-conflicts.php';
		if ( function_exists( 'wptsall_create_conflicts_table' ) ) {
			wptsall_create_conflicts_table();
		}
	}

	/**
	 * Capture manual edits on shadow / target posts.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 * @param bool     $update  Update.
	 * @return void
	 */
	public static function on_save_shadow_post( $post_id, $post, $update ) {
		// Retained as a public compatibility entry point for integrations that
		// called it directly. Core registration uses on_post_updated() below so
		// normal WordPress edits can identify the field that actually changed.
		if ( ! $update || ! ( $post instanceof \WP_Post ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( function_exists( 'wptsall_is_internal_write' ) && wptsall_is_internal_write() ) {
			return;
		}
		// Shadow copies carry virtual_site_id; also accept mapped targets.
		// Use Translation_Identity raw reads — Give-class filters hide get_post_meta.
		if ( ! \WPTSALL\Sites\Services\Translation_Identity::has_identity_markers( (int) $post_id ) ) {
			return;
		}
		foreach ( array( 'post_title', 'post_content', 'post_excerpt' ) as $field ) {
			self::claim_manual( 'post', (int) $post_id, $field, (string) $post->$field );
		}
	}

	/**
	 * Mark only fields actually changed by a human editor as wp_manual.
	 *
	 * @param int      $post_id     Post ID.
	 * @param \WP_Post $post_after  Saved post.
	 * @param \WP_Post $post_before Previous post.
	 * @return void
	 */
	public static function on_post_updated( $post_id, $post_after, $post_before ) {
		if ( ! ( $post_after instanceof \WP_Post ) || ! ( $post_before instanceof \WP_Post ) ) {
			return;
		}
		if ( function_exists( 'wptsall_is_internal_write' ) && wptsall_is_internal_write() ) {
			return;
		}
		if ( ! \WPTSALL\Sites\Services\Translation_Identity::has_identity_markers( (int) $post_id ) ) {
			return;
		}
		foreach ( array( 'post_title', 'post_content', 'post_excerpt' ) as $field ) {
			if ( (string) $post_after->$field !== (string) $post_before->$field ) {
				self::claim_manual( 'post', (int) $post_id, $field, (string) $post_after->$field );
			}
		}
	}

	/**
	 * Claim manually edited post meta on shadow / mapped targets.
	 *
	 * @param int    $meta_id    Meta row ID.
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @return void
	 */
	public static function on_updated_post_meta( $meta_id, $object_id, $meta_key, $meta_value ) {
		unset( $meta_id );
		if ( function_exists( 'wptsall_is_internal_write' ) && wptsall_is_internal_write() ) {
			return;
		}
		$meta_key = (string) $meta_key;
		if ( '' === $meta_key || 0 === strpos( $meta_key, '_wptsall_' ) ) {
			return;
		}
		$object_id = (int) $object_id;
		if ( $object_id <= 0 || ! class_exists( '\\WPTSALL\\Sites\\Services\\Translation_Identity' ) ) {
			return;
		}
		if ( ! \WPTSALL\Sites\Services\Translation_Identity::has_identity_markers( $object_id ) ) {
			return;
		}
		self::claim_manual( 'post', $object_id, 'meta:' . $meta_key, is_scalar( $meta_value ) ? (string) $meta_value : wp_json_encode( $meta_value ) );
	}

	/**
	 * @param int    $term_id Term ID.
	 * @param int    $tt_id   Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	public static function on_edited_term( $term_id, $tt_id, $taxonomy ) {
		unset( $tt_id, $taxonomy );
		if ( function_exists( 'wptsall_is_internal_write' ) && wptsall_is_internal_write() ) {
			return;
		}
		$term = get_term( (int) $term_id );
		if ( ! $term || is_wp_error( $term ) ) {
			return;
		}
		// Only mapped terms (have mapping row as target).
		global $wpdb;
		$table = wptsall_table( 'term_mappings' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$src = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT source_term_id FROM %i WHERE target_term_id = %d LIMIT 1',
				$table,
				(int) $term_id
			)
		);
		if ( $src <= 0 ) {
			return;
		}
		self::claim_manual( 'term', (int) $term_id, 'name', (string) $term->name );
		self::claim_manual( 'term', (int) $term_id, 'description', (string) $term->description );
	}

	/**
	 * Mark a field as manually owned.
	 *
	 * @param string $object_type post|term.
	 * @param int    $object_id   Object ID.
	 * @param string $field_path  Field.
	 * @param string $value       Current value.
	 * @return void
	 */
	public static function claim_manual( $object_type, $object_id, $field_path, $value ) {
		$owners = self::get_owners( $object_type, $object_id );
		$owners[ $field_path ] = array(
			'value_origin'  => 'wp_manual',
			'review_status' => 'accepted',
			'content_hash'  => hash( 'sha256', (string) $value ),
			'locked_at'     => current_time( 'mysql' ),
			'actor'         => get_current_user_id(),
		);
		self::set_owners( $object_type, $object_id, $owners );
	}

	/**
	 * Filter translated fields: drop machine results that conflict with wp_manual.
	 *
	 * @param int    $target_object_id Target post/term ID (0 = resolve later).
	 * @param string $object_type      post_type|taxonomy.
	 * @param array  $translated_fields Fields from client.
	 * @param array  $translated_meta   Meta from client.
	 * @param int    $relation_id       Relation.
	 * @param int    $source_object_id  Source id.
	 * @param string $client_task_id    Task id.
	 * @return array{fields:array,meta:array,conflicts:int}
	 */
	public static function filter_machine_writeback(
		$target_object_id,
		$object_type,
		$translated_fields,
		$translated_meta,
		$relation_id,
		$source_object_id,
		$client_task_id = ''
	) {
		$type_key = ( 'taxonomy' === $object_type ) ? 'term' : 'post';
		$owners   = $target_object_id > 0 ? self::get_owners( $type_key, $target_object_id ) : array();
		$conflicts = 0;
		$fields_out = array();
		foreach ( (array) $translated_fields as $key => $value ) {
			$owner = $owners[ $key ] ?? null;
			if ( is_array( $owner ) && 'wp_manual' === ( $owner['value_origin'] ?? '' ) ) {
				self::record_conflict(
					$relation_id,
					$type_key,
					$source_object_id,
					$target_object_id,
					(string) $key,
					(string) ( $owner['content_hash'] ?? '' ),
					hash( 'sha256', (string) wp_json_encode( $value ) ),
					$client_task_id
				);
				++$conflicts;
				continue;
			}
			$fields_out[ $key ] = $value;
		}
		$meta_out = array();
		foreach ( (array) $translated_meta as $key => $value ) {
			$path = 'meta:' . $key;
			$owner = $owners[ $path ] ?? null;
			if ( is_array( $owner ) && 'wp_manual' === ( $owner['value_origin'] ?? '' ) ) {
				self::record_conflict(
					$relation_id,
					$type_key,
					$source_object_id,
					$target_object_id,
					$path,
					(string) ( $owner['content_hash'] ?? '' ),
					hash( 'sha256', (string) wp_json_encode( $value ) ),
					$client_task_id
				);
				++$conflicts;
				continue;
			}
			$meta_out[ $key ] = $value;
		}
		return array(
			'fields'    => $fields_out,
			'meta'      => $meta_out,
			'conflicts' => $conflicts,
		);
	}

	/**
	 * After successful machine apply, stamp fields as machine-owned.
	 *
	 * @param string $object_type post|term.
	 * @param int    $object_id   Target id.
	 * @param array  $fields      Applied field map.
	 * @return void
	 */
	public static function stamp_machine_applied( $object_type, $object_id, array $fields ) {
		$owners = self::get_owners( $object_type, $object_id );
		foreach ( $fields as $key => $value ) {
			$existing = $owners[ $key ] ?? null;
			if ( is_array( $existing ) && 'wp_manual' === ( $existing['value_origin'] ?? '' ) ) {
				continue;
			}
			$owners[ $key ] = array(
				'value_origin'  => 'machine',
				'review_status' => 'accepted',
				'content_hash'  => hash( 'sha256', (string) wp_json_encode( $value ) ),
				'locked_at'     => current_time( 'mysql' ),
				'actor'         => 0,
			);
		}
		self::set_owners( $object_type, $object_id, $owners );
	}

	/**
	 * Read ownership map via Direct-DB / raw SQL (Give-class filters hide WP meta APIs).
	 *
	 * @param string $object_type post|term.
	 * @param int    $object_id   ID.
	 * @return array
	 */
	public static function get_owners( $object_type, $object_id ) {
		$object_id = (int) $object_id;
		if ( $object_id <= 0 ) {
			return array();
		}
		if ( 'term' === $object_type ) {
			$raw = self::raw_term_meta( $object_id, self::META_KEY );
		} elseif ( class_exists( '\\WPTSALL\\Sites\\Services\\Translation_Identity' ) ) {
			$raw = \WPTSALL\Sites\Services\Translation_Identity::raw_meta( $object_id, self::META_KEY );
		} else {
			$raw = get_post_meta( $object_id, self::META_KEY, true );
		}
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			return is_array( $decoded ) ? $decoded : array();
		}
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Persist ownership map via Direct-DB.
	 *
	 * @param string $object_type post|term.
	 * @param int    $object_id   ID.
	 * @param array  $owners      Owners map.
	 * @return void
	 */
	public static function set_owners( $object_type, $object_id, array $owners ) {
		$object_id = (int) $object_id;
		if ( $object_id <= 0 ) {
			return;
		}
		$json = wp_json_encode( $owners );
		if ( 'term' === $object_type ) {
			if ( class_exists( '\\WPTSALL\\Tasks\\Services\\Direct_DB_Service' ) ) {
				\WPTSALL\Tasks\Services\Direct_DB_Service::update_term_meta( $object_id, self::META_KEY, $json );
			} else {
				update_term_meta( $object_id, self::META_KEY, $json );
			}
			return;
		}
		if ( class_exists( '\\WPTSALL\\Sites\\Services\\Translation_Identity' ) ) {
			\WPTSALL\Sites\Services\Translation_Identity::write_meta( $object_id, self::META_KEY, $json );
			return;
		}
		update_post_meta( $object_id, self::META_KEY, $json );
	}

	/**
	 * @param int    $term_id  Term ID.
	 * @param string $meta_key Meta key.
	 * @return mixed
	 */
	private static function raw_term_meta( int $term_id, string $meta_key ) {
		if ( $term_id <= 0 || '' === $meta_key ) {
			return '';
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$raw = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->termmeta} WHERE term_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1",
				$term_id,
				$meta_key
			)
		);
		if ( null === $raw ) {
			return '';
		}
		return maybe_unserialize( $raw );
	}

	/**
	 * @return void
	 */
	public static function record_conflict(
		$relation_id,
		$object_type,
		$source_id,
		$target_id,
		$field_path,
		$manual_hash,
		$machine_hash,
		$client_task_id
	) {
		self::ensure_schema();
		global $wpdb;
		$table = wptsall_table( 'conflicts' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'relation_id'      => (int) $relation_id,
				'object_type'      => (string) $object_type,
				'source_object_id' => (int) $source_id,
				'target_object_id' => (int) $target_id,
				'field_path'       => (string) $field_path,
				'manual_hash'      => (string) $manual_hash,
				'machine_hash'     => (string) $machine_hash,
				'client_task_id'   => (string) $client_task_id,
				'status'           => 'open',
				'created_at'       => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}
}

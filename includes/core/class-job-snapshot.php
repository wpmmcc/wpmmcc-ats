<?php
/**
 * Immutable translation job snapshots (ISS P-A10).
 *
 * source_revision fingerprints source object content at claim time.
 * policy_version fingerprints translation rules / formats at claim time.
 * Stale callbacks (revision mismatch) are rejected — not blindly applied.
 *
 * @package WPTSALL\Core
 * @since 2.0.1
 */

namespace WPTSALL\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Job_Snapshot class.
 */
class Job_Snapshot {

	const POLICY_OPTION = 'wptsall_translation_policy_version';

	/**
	 * Current policy version (bumped when rules/formats change materially).
	 *
	 * @return string
	 */
	public static function current_policy_version() {
		$v = (string) get_option( self::POLICY_OPTION, '' );
		if ( '' === $v ) {
			$v = self::bump_policy_version( 'init' );
		}
		return $v;
	}

	/**
	 * @param string $reason Reason for bump.
	 * @return string New version.
	 */
	public static function bump_policy_version( $reason = '' ) {
		$v = hash( 'sha256', wp_json_encode( array( microtime( true ), $reason, wp_generate_uuid4() ) ) );
		$v = substr( $v, 0, 32 );
		update_option( self::POLICY_OPTION, $v, false );
		if ( function_exists( 'wptsall_set_option_autoload' ) ) {
			wptsall_set_option_autoload( self::POLICY_OPTION, false );
		}
		return $v;
	}

	/**
	 * Compute source revision for a post or term.
	 *
	 * @param string      $object_type post_type|taxonomy|post|term|option.
	 * @param int         $object_id   ID (or the stable synthetic id for options).
	 * @param string|null $context     Option name when object_type is option.
	 * @return string Hex revision (empty if object missing).
	 */
	public static function compute_source_revision( $object_type, $object_id, $context = null ) {
		$object_id = (int) $object_id;
		$type      = (string) $object_type;
		if ( 'option' === $type ) {
			$option_name = sanitize_key( (string) $context );
			if ( '' === $option_name ) {
				return '';
			}
			$missing = '__WPTSALL_OPTION_MISSING__';
			$value   = get_option( $option_name, $missing );
			if ( $missing === $value ) {
				return '';
			}
			return hash( 'sha256', (string) wp_json_encode( array(
				'type'        => 'option',
				'id'          => $object_id,
				'option_name' => $option_name,
				'option_value' => $value,
			) ) );
		}
		if ( in_array( $type, array( 'taxonomy', 'term' ), true ) ) {
			$term = get_term( $object_id );
			if ( ! $term || is_wp_error( $term ) ) {
				return '';
			}
			$payload = array(
				'type'        => 'term',
				'id'          => $object_id,
				'taxonomy'    => $term->taxonomy,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'description' => $term->description,
				'parent'      => (int) $term->parent,
			);
		} else {
			$post = get_post( $object_id );
			if ( ! $post ) {
				return '';
			}
			$payload = array(
				'type'         => 'post',
				'id'           => $object_id,
				'post_type'    => $post->post_type,
				'post_title'   => $post->post_title,
				'post_content' => $post->post_content,
				'post_excerpt' => $post->post_excerpt,
				'post_status'  => $post->post_status,
				'post_name'    => $post->post_name,
				'modified_gmt' => $post->post_modified_gmt,
			);
		}
		return hash( 'sha256', (string) wp_json_encode( $payload ) );
	}

	/**
	 * Whether a callback snapshot is still valid to apply.
	 *
	 * @param string $object_type      Object type.
	 * @param int    $object_id        Source object id.
	 * @param string $source_revision  Snapshot from claim/callback.
	 * @param string      $policy_version Policy at claim time.
	 * @param string|null $context       Option name when object_type is option.
	 * @return true|\WP_Error
	 */
	public static function assert_fresh( $object_type, $object_id, $source_revision, $policy_version, $context = null ) {
		$source_revision = (string) $source_revision;
		$policy_version  = (string) $policy_version;
		// Empty revision = legacy row / pre-snapshot client; allowed.
		if ( '' === $source_revision ) {
			return true;
		}
		$current = self::compute_source_revision( $object_type, $object_id, $context );
		// A non-empty snapshot for a missing source object must never be treated
		// as fresh.  The previous implementation only compared when $current was
		// non-empty, which allowed callbacks for deleted/non-existent IDs to pass
		// the CAS check and reach persistence.  Deletion lifecycle events are
		// represented by the durable outbox and do not use this write-back path.
		if ( '' === $current ) {
			return new \WP_Error(
				'source_object_not_found',
				'Source object no longer exists; refuse blind apply',
				array(
					'object_type' => (string) $object_type,
					'object_id'   => (int) $object_id,
				)
			);
		}
		if ( ! hash_equals( $current, $source_revision ) ) {
			return new \WP_Error(
				'stale_source_revision',
				'Source changed since claim; refuse blind apply',
				array(
					'expected' => $source_revision,
					'current'  => $current,
				)
			);
		}
		if ( '' !== $policy_version ) {
			$pol = self::current_policy_version();
			if ( '' !== $pol && ! hash_equals( $pol, $policy_version ) ) {
				return new \WP_Error(
					'stale_policy_version',
					'Translation policy changed since claim; refuse blind apply',
					array(
						'expected' => $policy_version,
						'current'  => $pol,
					)
				);
			}
		}
		return true;
	}

	/**
	 * Hash canonical JSON body for idempotency conflict detection.
	 *
	 * @param array $body Request body.
	 * @return string
	 */
	public static function request_body_hash( array $body ) {
		$copy = $body;
		unset( $copy['timestamp'], $copy['nonce'], $copy['signature'] );
		ksort( $copy );
		return hash( 'sha256', (string) wp_json_encode( $copy ) );
	}
}

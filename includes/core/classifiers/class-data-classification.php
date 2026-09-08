<?php
/**
 * Data Classification Result
 *
 * Represents a data classification result object
 *
 * @package WPTSALL
 * @since 0.3.0
 */

namespace WPTSALL\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data Classification Result Class
 */
class Data_Classification {

	/**
	 * Data object (WP_Post, WP_Comment, WP_User, etc.)
	 *
	 * @var mixed
	 */
	public $object;

	/**
	 * Object type (post_type, 'comment', 'user', etc.)
	 *
	 * @var string
	 */
	public $object_type;

	/**
	 * Data content type
	 *
	 * @var string content|attachment|comment|user|order|term|meta
	 */
	public $data_type;

	/**
	 * Privacy level
	 *
	 * @var string public|internal|private|pii
	 */
	public $privacy;

	/**
	 * Sync policy
	 *
	 * @var string always|configurable|never|conditional
	 */
	public $sync_policy;

	/**
	 * Whether syncable
	 *
	 * @var bool
	 */
	public $syncable;

	/**
	 * Reason for not being syncable
	 *
	 * @var string|null
	 */
	public $reason;

	/**
	 * Condition check results
	 *
	 * @var array
	 */
	public $conditions_met;

	/**
	 * Constructor
	 *
	 * @param mixed  $object        Data object
	 * @param string $object_type   Object type
	 * @param array  $classification Classification result array
	 */
	public function __construct( $object, $object_type, $classification = array() ) {
		$this->object         = $object;
		$this->object_type    = $object_type;
		$this->data_type      = $classification['data_type'] ?? Classification_Constants::DATA_TYPE_CONTENT;
		$this->privacy        = $classification['privacy'] ?? Classification_Constants::DATA_PRIVACY_PUBLIC;
		$this->sync_policy    = $classification['sync_policy'] ?? Classification_Constants::SYNC_CONFIGURABLE;
		$this->syncable       = $classification['syncable'] ?? false;
		$this->reason         = $classification['reason'] ?? null;
		$this->conditions_met = $classification['conditions_met'] ?? array();
	}

	/**
	 * Check if data is PII
	 *
	 * @return bool
	 */
	public function is_pii() {
		return Classification_Constants::DATA_PRIVACY_PII === $this->privacy;
	}

	/**
	 * Check if data is public
	 *
	 * @return bool
	 */
	public function is_public() {
		return Classification_Constants::DATA_PRIVACY_PUBLIC === $this->privacy;
	}

	/**
	 * Check if always synced
	 *
	 * @return bool
	 */
	public function is_always_sync() {
		return Classification_Constants::SYNC_ALWAYS === $this->sync_policy;
	}

	/**
	 * Check if never synced
	 *
	 * @return bool
	 */
	public function is_never_sync() {
		return Classification_Constants::SYNC_NEVER === $this->sync_policy;
	}

	/**
	 * Check if sync is configurable
	 *
	 * @return bool
	 */
	public function is_configurable_sync() {
		return Classification_Constants::SYNC_CONFIGURABLE === $this->sync_policy;
	}

	/**
	 * Convert to array
	 *
	 * @return array
	 */
	public function to_array() {
		return array(
			'object_type'    => $this->object_type,
			'data_type'      => $this->data_type,
			'privacy'        => $this->privacy,
			'sync_policy'    => $this->sync_policy,
			'syncable'       => $this->syncable,
			'reason'         => $this->reason,
			'conditions_met' => $this->conditions_met,
		);
	}

	/**
	 * Convert to JSON
	 *
	 * @return string
	 */
	public function to_json() {
		return wp_json_encode( $this->to_array() );
	}
}

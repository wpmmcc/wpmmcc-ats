<?php
/**
 * URL Classification Result
 *
 * Represents the URL classification result object.
 *
 * @package WPTSALL
 * @since 0.3.0
 */

namespace WPTSALL\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * URL Classification Result Class
 */
class URL_Classification {

	/**
	 * Original URL.
	 *
	 * @var string
	 */
	public $url;

	/**
	 * URL location type.
	 *
	 * @var string frontend|backend|api|ajax
	 */
	public $location;

	/**
	 * URL access permission level.
	 *
	 * @var string public|login|role|admin|restricted
	 */
	public $access;

	/**
	 * Whether syncable.
	 *
	 * @var bool
	 */
	public $syncable;

	/**
	 * Matched rule name.
	 *
	 * @var string
	 */
	public $matched_rule;

	/**
	 * Reason for not being syncable.
	 *
	 * @var string|null
	 */
	public $reason;

	/**
	 * Constructor.
	 *
	 * @param string $url            Original URL.
	 * @param array  $classification Classification result array.
	 */
	public function __construct( $url, $classification = array() ) {
		$this->url           = $url;
		$this->location      = $classification['location'] ?? Classification_Constants::URL_LOCATION_FRONTEND;
		$this->access        = $classification['access'] ?? Classification_Constants::URL_ACCESS_PUBLIC;
		$this->syncable      = $classification['syncable'] ?? true;
		$this->matched_rule  = $classification['matched_rule'] ?? 'default';
		$this->reason        = $classification['reason'] ?? null;
	}

	/**
	 * Check if this is a frontend URL.
	 *
	 * @return bool
	 */
	public function is_frontend() {
		return Classification_Constants::URL_LOCATION_FRONTEND === $this->location;
	}

	/**
	 * Check if this is a backend URL.
	 *
	 * @return bool
	 */
	public function is_backend() {
		return Classification_Constants::URL_LOCATION_BACKEND === $this->location;
	}

	/**
	 * Check if this is an API URL.
	 *
	 * @return bool
	 */
	public function is_api() {
		return Classification_Constants::URL_LOCATION_API === $this->location;
	}

	/**
	 * Check if this is publicly accessible.
	 *
	 * @return bool
	 */
	public function is_public() {
		return Classification_Constants::URL_ACCESS_PUBLIC === $this->access;
	}

	/**
	 * Check if login is required.
	 *
	 * @return bool
	 */
	public function requires_login() {
		return in_array(
			$this->access,
			array(
				Classification_Constants::URL_ACCESS_LOGIN,
				Classification_Constants::URL_ACCESS_ROLE,
				Classification_Constants::URL_ACCESS_ADMIN,
			),
			true
		);
	}

	/**
	 * Convert to array.
	 *
	 * @return array
	 */
	public function to_array() {
		return array(
			'url'          => $this->url,
			'location'     => $this->location,
			'access'       => $this->access,
			'syncable'     => $this->syncable,
			'matched_rule' => $this->matched_rule,
			'reason'       => $this->reason,
		);
	}

	/**
	 * Convert to JSON.
	 *
	 * @return string
	 */
	public function to_json() {
		return wp_json_encode( $this->to_array() );
	}
}

<?php
/**
 * Sync Access Validator
 *
 * Validates sync access permissions.
 *
 * @package WPTSALL
 * @since 0.3.0
 */

namespace WPTSALL\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sync Access Validator Class
 */
class Sync_Access_Validator {

	/**
	 * URL classifier.
	 *
	 * @var URL_Classifier
	 */
	private $url_classifier;

	/**
	 * Data classifier.
	 *
	 * @var Data_Classifier
	 */
	private $data_classifier;

	/**
	 * Sync configuration.
	 *
	 * @var array
	 */
	private $sync_config;

	/**
	 * Constructor.
	 *
	 * @param array $sync_config Sync configuration.
	 */
	public function __construct( $sync_config = array() ) {
		$this->sync_config     = $sync_config;
		$this->url_classifier  = new URL_Classifier();
		$this->data_classifier = new Data_Classifier( $sync_config );
	}

	/**
	 * Validate whether sync is allowed.
	 *
	 * @param mixed  $object    Data object.
	 * @param string $url       Related URL (optional).
	 * @param array  $meta_data Metadata (optional).
	 * @return array Validation result.
	 */
	public function validate( $object, $url = null, $meta_data = array() ) {
		$result = array(
			'allowed'      => false,
			'reason'       => null,
			'url_check'    => null,
			'data_check'   => null,
			'meta_check'   => null,
			'blocked_meta' => array(),
		);

		// 1. URL check.
		if ( $url ) {
			$url_classification = $this->url_classifier->classify( $url );
			$result['url_check'] = $url_classification->to_array();

			if ( ! $url_classification->syncable ) {
				$result['reason'] = 'URL not syncable: ' . $url_classification->reason;

				wptsall_log_error( 'core', 'Access validation failed - URL not syncable', array(
					'url'    => $url,
					'reason' => $result['reason'],
				) );

				return $result;
			}
		}

		// 2. Data object check.
		$data_classification  = $this->data_classifier->classify( $object );
		$result['data_check'] = $data_classification->to_array();

		// PII data is directly rejected.
		if ( $data_classification->is_pii() ) {
			$result['reason'] = 'PII data not syncable: ' . $data_classification->reason;

			wptsall_log_error( 'core', 'Access validation failed - PII data blocked', array(
				'object_type' => $data_classification->object_type,
				'reason'      => $result['reason'],
			) );

			return $result;
		}

		// Never-sync policy.
		if ( $data_classification->is_never_sync() ) {
			$result['reason'] = $data_classification->reason ?? 'Not syncable per policy';

			wptsall_log_error( 'core', 'Access validation failed - never sync policy', array(
				'object_type' => $data_classification->object_type,
				'reason'      => $result['reason'],
			) );

			return $result;
		}

		// Data object not syncable.
		if ( ! $data_classification->syncable ) {
			$result['reason'] = $data_classification->reason ?? 'Data classification check failed';

			wptsall_log_error( 'core', 'Access validation failed - data not syncable', array(
				'object_type' => $data_classification->object_type,
				'reason'      => $result['reason'],
			) );

			return $result;
		}

		// 3. Metadata check.
		if ( ! empty( $meta_data ) ) {
			$filtered_meta     = array();
			$blocked_meta      = array();

			foreach ( $meta_data as $meta_key => $meta_value ) {
				$meta_classification = $this->data_classifier->classify_meta_field( $meta_key );

				if ( $meta_classification['syncable'] ) {
					$filtered_meta[ $meta_key ] = $meta_value;
				} else {
					$blocked_meta[ $meta_key ] = $meta_classification['reason'];
				}
			}

			$result['meta_check']   = array(
				'total'    => count( $meta_data ),
				'allowed'  => count( $filtered_meta ),
				'blocked'  => count( $blocked_meta ),
			);
			$result['blocked_meta'] = $blocked_meta;
		}

		// 4. Privacy protection check.
		if ( ! $this->check_privacy_protection( $data_classification ) ) {
			$result['reason'] = 'Privacy protection check failed';

			wptsall_log_error( 'core', 'Access validation failed - privacy protection', array(
				'object_type' => $data_classification->object_type,
				'reason'      => $result['reason'],
			) );

			return $result;
		}

		// All checks passed.
		$result['allowed'] = true;

		wptsall_log_info( 'core', 'Access validation passed', array(
			'object_type'  => $data_classification->object_type,
			'url'          => $url,
			'meta_allowed' => $result['meta_check']['allowed'] ?? 0,
		) );

		return $result;
	}

	/**
	 * Validate whether a URL is syncable.
	 *
	 * @param string $url URL address.
	 * @return array Validation result.
	 */
	public function validate_url( $url ) {
		$classification = $this->url_classifier->classify( $url );

		return array(
			'allowed'        => $classification->syncable,
			'reason'         => $classification->reason,
			'classification' => $classification->to_array(),
		);
	}

	/**
	 * Validate whether a data object is syncable.
	 *
	 * @param mixed  $object      Data object.
	 * @param string $object_type Object type (optional).
	 * @return array Validation result.
	 */
	public function validate_data( $object, $object_type = null ) {
		$classification = $this->data_classifier->classify( $object, $object_type );

		$allowed = $classification->syncable
			&& ! $classification->is_pii()
			&& ! $classification->is_never_sync();

		return array(
			'allowed'        => $allowed,
			'reason'         => $classification->reason,
			'classification' => $classification->to_array(),
		);
	}

	/**
	 * Validate whether a metadata field is syncable.
	 *
	 * @param string $meta_key Metadata key name.
	 * @return array Validation result.
	 */
	public function validate_meta_field( $meta_key ) {
		$classification = $this->data_classifier->classify_meta_field( $meta_key );

		return array(
			'allowed'        => $classification['syncable'],
			'reason'         => $classification['reason'],
			'classification' => $classification,
		);
	}

	/**
	 * Filter and return syncable metadata.
	 *
	 * @param array  $meta_data   Metadata array.
	 * @param string $object_type Object type.
	 * @return array Filtered metadata.
	 */
	public function filter_meta_data( $meta_data, $object_type = 'post' ) {
		return $this->data_classifier->filter_meta_fields( $meta_data, $object_type );
	}

	/**
	 * Batch validate data objects.
	 *
	 * @param array $objects Array of data objects.
	 * @return array Array of validation results.
	 */
	public function validate_batch( $objects ) {
		$results = array(
			'total'   => count( $objects ),
			'allowed' => array(),
			'blocked' => array(),
		);

		foreach ( $objects as $key => $object ) {
			$validation = $this->validate_data( $object );

			if ( $validation['allowed'] ) {
				$results['allowed'][ $key ] = $object;
			} else {
				$results['blocked'][ $key ] = array(
					'object' => $object,
					'reason' => $validation['reason'],
				);
			}
		}

		return $results;
	}

	/**
	 * Check privacy protection settings.
	 *
	 * @param Data_Classification $classification Data classification.
	 * @return bool
	 */
	private function check_privacy_protection( $classification ) {
		$privacy_config = $this->sync_config['privacy_protection'] ?? array();

		// Block PII data.
		if ( $classification->is_pii() ) {
			$block_pii = $privacy_config['block_pii'] ?? true;
			if ( $block_pii ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get validation statistics.
	 *
	 * @param array $objects Array of data objects.
	 * @return array Statistics result.
	 */
	public function get_validation_stats( $objects ) {
		$stats = array(
			'total'           => count( $objects ),
			'allowed'         => 0,
			'blocked'         => 0,
			'blocked_reasons' => array(),
		);

		foreach ( $objects as $object ) {
			$validation = $this->validate_data( $object );

			if ( $validation['allowed'] ) {
				++$stats['allowed'];
			} else {
				++$stats['blocked'];

				$reason = $validation['reason'] ?? 'Unknown';
				if ( ! isset( $stats['blocked_reasons'][ $reason ] ) ) {
					$stats['blocked_reasons'][ $reason ] = 0;
				}
				++$stats['blocked_reasons'][ $reason ];
			}
		}

		return $stats;
	}

	/**
	 * Check if syncing a specific data type is allowed.
	 *
	 * @param string $data_type Data type.
	 * @return bool
	 */
	public function is_data_type_allowed( $data_type ) {
		// PII types are never allowed.
		if ( in_array(
			$data_type,
			array(
				Classification_Constants::DATA_TYPE_USER,
				Classification_Constants::DATA_TYPE_ORDER,
			),
			true
		) ) {
			return false;
		}

		$data_types = $this->sync_config['data_types'] ?? array();
		return $data_types[ $data_type ] ?? false;
	}

	/**
	 * Check if syncing URLs of a specific access level is allowed.
	 *
	 * @param string $access_level Access level.
	 * @return bool
	 */
	public function is_url_access_allowed( $access_level ) {
		$url_access = $this->sync_config['url_access'] ?? array();
		return $url_access[ $access_level ] ?? false;
	}

	/**
	 * Generate validation report.
	 *
	 * @param array $validation_result Validation result.
	 * @return string HTML formatted report.
	 */
	public function generate_report( $validation_result ) {
		$report = '<div class="wptsall-validation-report">';

		// Overall status.
		$status_class = $validation_result['allowed'] ? 'success' : 'error';
		$status_text  = $validation_result['allowed'] ? 'Sync allowed' : 'Sync denied';

		$report .= sprintf(
			'<div class="status %s"><strong>%s</strong></div>',
			esc_attr( $status_class ),
			esc_html( $status_text )
		);

		// Denial reason.
		if ( ! $validation_result['allowed'] && $validation_result['reason'] ) {
			$report .= sprintf(
				'<div class="reason"><strong>Reason:</strong> %s</div>',
				esc_html( $validation_result['reason'] )
			);
		}

		// URL check.
		if ( $validation_result['url_check'] ) {
			$report .= '<div class="url-check"><strong>URL check:</strong> ';
			$report .= $validation_result['url_check']['syncable'] ? 'Passed' : 'Failed';
			$report .= '</div>';
		}

		// Data check.
		if ( $validation_result['data_check'] ) {
			$report .= '<div class="data-check"><strong>Data check:</strong> ';
			$report .= $validation_result['data_check']['syncable'] ? 'Passed' : 'Failed';
			$report .= '</div>';
		}

		// Metadata check.
		if ( $validation_result['meta_check'] ) {
			$meta = $validation_result['meta_check'];
			$report .= sprintf(
				'<div class="meta-check"><strong>Metadata:</strong> %d fields, %d allowed, %d blocked</div>',
				$meta['total'],
				$meta['allowed'],
				$meta['blocked']
			);
		}

		$report .= '</div>';

		return $report;
	}
}

<?php
/**
 * URL Classifier
 *
 * Classifies URLs based on rules.
 *
 * @package WPTSALL
 * @since 0.3.0
 */

namespace WPTSALL\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * URL Classifier Class
 */
class URL_Classifier {

	/**
	 * URL classification rules.
	 *
	 * @var array
	 */
	private $rules;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->rules = $this->load_rules();
	}

	/**
	 * Load classification rules.
	 *
	 * @return array
	 */
	private function load_rules() {
		$rules = Classification_Rules::get_url_rules();

		// Apply filters to allow customization.
		$rules = Classification_Rules::apply_filters( 'url', $rules );

		// Sort by priority (lower number = higher priority).
		usort(
			$rules,
			function( $a, $b ) {
				$priority_a = $a['priority'] ?? 100;
				$priority_b = $b['priority'] ?? 100;
				return $priority_a <=> $priority_b;
			}
		);

		return $rules;
	}

	/**
	 * Classify a single URL.
	 *
	 * @param string $url URL address (can be a full URL or path).
	 * @return URL_Classification
	 */
	public function classify( $url ) {
		// Extract URL path component.
		$path = $this->extract_path( $url );

		// Iterate rules for matching.
		foreach ( $this->rules as $rule ) {
			if ( $this->match_pattern( $path, $rule['pattern'] ) ) {
				wptsall_log_debug( 'core', 'URL classified by rule', array(
					'url'          => $url,
					'path'         => $path,
					'matched_rule' => $rule['name'],
					'location'     => $rule['location'],
					'syncable'     => $rule['syncable'],
				) );

				return new URL_Classification(
					$url,
					array(
						'location'     => $rule['location'],
						'access'       => $rule['access'],
						'syncable'     => $rule['syncable'],
						'matched_rule' => $rule['name'],
						'reason'       => $rule['reason'] ?? null,
					)
				);
			}
		}

		// If no rules matched, return default classification.
		wptsall_log_debug( 'core', 'URL classified as default (no rule matched)', array(
			'url'      => $url,
			'path'     => $path,
			'syncable' => true,
		) );

		return new URL_Classification(
			$url,
			array(
				'location'     => Classification_Constants::URL_LOCATION_FRONTEND,
				'access'       => Classification_Constants::URL_ACCESS_PUBLIC,
				'syncable'     => true,
				'matched_rule' => 'default',
			)
		);
	}

	/**
	 * Batch classify URLs.
	 *
	 * @param array $urls Array of URLs.
	 * @return array Array of URL_Classification objects.
	 */
	public function classify_batch( $urls ) {
		$results = array();

		foreach ( $urls as $url ) {
			$results[ $url ] = $this->classify( $url );
		}

		return $results;
	}

	/**
	 * Extract URL path component.
	 *
	 * @param string $url Full URL or path.
	 * @return string
	 */
	private function extract_path( $url ) {
		// If full URL, parse out the path.
		if ( preg_match( '/^https?:\/\//i', $url ) ) {
			$parsed = wp_parse_url( $url );
			$path   = $parsed['path'] ?? '/';
		} else {
			// Already a path.
			$path = $url;
		}

		// Ensure path starts with /.
		if ( ! str_starts_with( $path, '/' ) ) {
			$path = '/' . $path;
		}

		return $path;
	}

	/**
	 * Match regex pattern.
	 *
	 * @param string $path    Path.
	 * @param string $pattern Regex pattern.
	 * @return bool
	 */
	private function match_pattern( $path, $pattern ) {
		return (bool) preg_match( $pattern, $path );
	}

	/**
	 * Get all rules.
	 *
	 * @return array
	 */
	public function get_rules() {
		return $this->rules;
	}

	/**
	 * Filter syncable URLs.
	 *
	 * @param array $urls Array of URLs.
	 * @return array Syncable URLs.
	 */
	public function filter_syncable( $urls ) {
		$syncable = array();

		foreach ( $urls as $url ) {
			$classification = $this->classify( $url );
			if ( $classification->syncable ) {
				$syncable[] = $url;
			}
		}

		return $syncable;
	}

	/**
	 * Get URL classification statistics.
	 *
	 * @param array $urls Array of URLs.
	 * @return array Statistics result.
	 */
	public function get_statistics( $urls ) {
		$stats = array(
			'total'    => count( $urls ),
			'syncable' => 0,
			'blocked'  => 0,
			'by_location' => array(),
			'by_access'   => array(),
			'by_rule'     => array(),
		);

		foreach ( $urls as $url ) {
			$classification = $this->classify( $url );

			if ( $classification->syncable ) {
				++$stats['syncable'];
			} else {
				++$stats['blocked'];
			}

			// Count by location.
			if ( ! isset( $stats['by_location'][ $classification->location ] ) ) {
				$stats['by_location'][ $classification->location ] = 0;
			}
			++$stats['by_location'][ $classification->location ];

			// Count by access level.
			if ( ! isset( $stats['by_access'][ $classification->access ] ) ) {
				$stats['by_access'][ $classification->access ] = 0;
			}
			++$stats['by_access'][ $classification->access ];

			// Count by rule.
			if ( ! isset( $stats['by_rule'][ $classification->matched_rule ] ) ) {
				$stats['by_rule'][ $classification->matched_rule ] = 0;
			}
			++$stats['by_rule'][ $classification->matched_rule ];
		}

		return $stats;
	}
}

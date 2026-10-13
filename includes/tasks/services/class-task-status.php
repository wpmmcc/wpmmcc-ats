<?php
/**
 * Task Status
 *
 * Task status vocabulary — single source of truth (opus5 A-02).
 *
 * Before 2.1.4 the vocabulary drifted across 7+ sites: the retry cron only
 * picked up 'retry' (so 'error' rows written by the sync orchestrator stalled
 * forever with no dead-letter cap), the wptsall tasks list table had no
 * badge colors for error/paused/active/skipped, and the two admin task
 * renderers used different palettes. This class is the one place every task
 * status string is defined; validation, retry eligibility, REST filter
 * enums and admin badge rendering all read from here.
 *
 * @package WPTSALL\Tasks\Services
 * @since 2.1.4
 */

namespace WPTSALL\Tasks\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Task status vocabulary
 *
 * Final class: no extension, the vocabulary is a closed set.
 */
final class Task_Status {

	const PENDING    = 'pending';
	const PROCESSING = 'processing';
	const ACTIVE     = 'active';
	const PAUSED     = 'paused';
	const COMPLETED  = 'completed';
	const SKIPPED    = 'skipped';
	const RETRY      = 'retry';
	const FAILED     = 'failed';
	const ERROR      = 'error';

	/**
	 * All valid task status values (canonical order for admin filters).
	 *
	 * @return array<int,string>
	 */
	public static function all() {
		return array(
			self::PENDING,
			self::PROCESSING,
			self::ACTIVE,
			self::PAUSED,
			self::COMPLETED,
			self::SKIPPED,
			self::RETRY,
			self::FAILED,
			self::ERROR,
		);
	}

	/**
	 * Whether a status value belongs to the vocabulary.
	 *
	 * @param string $status Status value.
	 * @return bool
	 */
	public static function is_valid( $status ) {
		return in_array( (string) $status, self::all(), true );
	}

	/**
	 * Statuses eligible for automatic re-dispatch by the retry cron.
	 *
	 * 'error' rows are written when sync processing throws; treating them as
	 * retry-eligible keeps poison tasks from stalling silently. The
	 * retry_count dead-letter cap applies to both statuses equally, so an
	 * 'error' task that keeps failing eventually lands in 'failed' like a
	 * 'retry' task does.
	 *
	 * @return array<int,string>
	 */
	public static function retry_eligible() {
		return array( self::RETRY, self::ERROR );
	}

	/**
	 * Statuses meaning a task is still open (used for insert dedupe).
	 *
	 * Completed/failed/skipped rows are historical evidence and must not
	 * block a new pending task for the same business object.
	 *
	 * @return array<int,string>
	 */
	public static function open_states() {
		return array( self::PENDING, self::RETRY, self::PROCESSING, self::ACTIVE );
	}

	/**
	 * Badge colors shared by both admin task renderers.
	 *
	 * Modern WP admin palette. Both the tasks list table and the task log
	 * status card read this map so a status can never be colored in one
	 * screen and gray in another.
	 *
	 * @return array<string,string> status => hex color.
	 */
	public static function badge_colors() {
		return array(
			self::PENDING    => '#0073aa',
			self::PROCESSING => '#dba617',
			self::ACTIVE     => '#72aee6',
			self::PAUSED     => '#dba617',
			self::COMPLETED  => '#00a32a',
			self::SKIPPED    => '#646970',
			self::RETRY      => '#d63638',
			self::FAILED     => '#d63638',
			self::ERROR      => '#d63638',
		);
	}

	/**
	 * Badge labels shared by both admin task renderers.
	 *
	 * @return array<string,string> status => translated label.
	 */
	public static function badge_labels() {
		return array(
			self::PENDING    => __( 'Pending', 'wpmmcc-ats' ),
			self::PROCESSING => __( 'Processing', 'wpmmcc-ats' ),
			self::ACTIVE     => __( 'Active', 'wpmmcc-ats' ),
			self::PAUSED     => __( 'Paused', 'wpmmcc-ats' ),
			self::COMPLETED  => __( 'Completed', 'wpmmcc-ats' ),
			self::SKIPPED    => __( 'Skipped', 'wpmmcc-ats' ),
			self::RETRY      => __( 'Retry', 'wpmmcc-ats' ),
			self::FAILED     => __( 'Failed', 'wpmmcc-ats' ),
			self::ERROR      => __( 'Error', 'wpmmcc-ats' ),
		);
	}
}

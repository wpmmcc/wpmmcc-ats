<?php
/**
 * Model Object Dependency Service
 *
 * Stub service: rebuilds and queries object-level dependencies for a
 * translation model. Full dependency graph (source → target objects,
 * external references, status) is built in a follow-up; current
 * implementation returns safe empty results so dependent REST routes
 * stay operational.
 *
 * @package WPTSALL\Models\Services
 * @since 1.2.0
 */

namespace WPTSALL\Models\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Model Object Dependency Service
 */
class Model_Object_Dependency_Service {

	/**
	 * Rebuild dependencies for a model.
	 *
	 * @param int $model_id Model ID.
	 * @return array Stats with total/resolved/unresolved/external/invalid counts.
	 */
	public static function rebuild_for_model( $model_id ) {
		unset( $model_id );
		return array(
			'total'      => 0,
			'resolved'   => 0,
			'unresolved' => 0,
			'external'   => 0,
			'invalid'    => 0,
		);
	}

	/**
	 * Get dependencies for a model.
	 *
	 * @param int $model_id Model ID.
	 * @return array List of dependency rows (empty stub).
	 */
	public static function get_model_dependencies( $model_id ) {
		unset( $model_id );
		return array();
	}
}

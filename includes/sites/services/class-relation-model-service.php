<?php
/**
 * Relation Model Service
 *
 * Manage many-to-many associations between site relations and models
 *
 * @package WPTSALL\Sites\Services
 * @since 0.6.0
  * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table/column identifiers from internal helpers (wptsall_table / \$wpdb->prefix . 'wptsall_*'); user values use prepare placeholders.
 */
namespace WPTSALL\Sites\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and intentional meta/tax lookups; all dynamic values go through $wpdb->prepare() / wptsall_db_* helpers (no unprepared user input).
/**
 * Site relation-model association service class
 *
 * Process many-to-many association relationships between site relations and models (plugins)
 */
class Relation_Model_Service {

	/**
	 * Get all models associated with a site relation
	 *
	 * @param int $relation_id site relation ID.
	 * @return array List of associated models.
	 */
	public static function get_models_by_relation( $relation_id ) {
		global $wpdb;
		$rm_table     = wptsall_table( 'relation_models' );
		$models_table = wptsall_table( 'models' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$models = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT m.*, rm.created_at as associated_at
				FROM %i m
				INNER JOIN %i rm ON m.id = rm.model_id
				WHERE rm.relation_id = %d
				ORDER BY m.plugin_name ASC',
				$models_table,
				$rm_table,
				$relation_id
			),
			ARRAY_A
		);

		return $models ? $models : array();
	}

	/**
	 * Get all site relations associated with a model
	 *
	 * @param int $model_id model ID.
	 * @return array List of associated site relations.
	 */
	public static function get_relations_by_model( $model_id ) {
		global $wpdb;
		$rm_table        = wptsall_table( 'relation_models' );
		$relations_table = wptsall_table( 'site_relations' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$relations = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT sr.*, rm.created_at as associated_at
				FROM %i sr
				INNER JOIN %i rm ON sr.id = rm.relation_id
				WHERE rm.model_id = %d
				ORDER BY sr.id ASC',
				$relations_table,
				$rm_table,
				$model_id
			),
			ARRAY_A
		);

		return $relations ? $relations : array();
	}

	/**
	 * Add a model association to a site relation
	 *
	 * @param int $relation_id site relation ID.
	 * @param int $model_id    model ID.
	 * @return bool|int On success returns insert ID, on failure returns false.
	 */
	public static function add_model_to_relation( $relation_id, $model_id ) {
		global $wpdb;
		$rm_table = wptsall_table( 'relation_models' );

		// Check if association already exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE relation_id = %d AND model_id = %d',
				$rm_table,
				$relation_id,
				$model_id
			)
		);

		if ( $exists ) {
			return (int) $exists; // Already exists; return existing ID.
		}

		// Insert new association.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$rm_table,
			array(
				'relation_id' => $relation_id,
				'model_id'    => $model_id,
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s' )
		);

		if ( false === $result ) {
			wptsall_log_error(
				'sites-relations',
				'Failed to add model to relation',
				array(
					'relation_id' => $relation_id,
					'model_id'    => $model_id,
					'error'       => $wpdb->last_error,
				)
			);
			return false;
		}

		// update models_count cache.
		self::update_models_count( $relation_id );

		wptsall_log_info(
			'sites-relations',
			'Model added to relation',
			array(
				'relation_id' => $relation_id,
				'model_id'    => $model_id,
			)
		);

		/**
		 * Fires when relation models are updated.
		 *
		 * @since 1.0.0
		 * @param int $relation_id The relation ID.
		 */
		do_action( 'wptsall_relation_models_updated', $relation_id );

		return $wpdb->insert_id;
	}

	/**
	 * Remove a model association from a site relation
	 *
	 * @param int $relation_id site relation ID.
	 * @param int $model_id    model ID.
	 * @return bool Returns true on success.
	 */
	public static function remove_model_from_relation( $relation_id, $model_id ) {
		global $wpdb;
		$rm_table = wptsall_table( 'relation_models' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete(
			$rm_table,
			array(
				'relation_id' => $relation_id,
				'model_id'    => $model_id,
			),
			array( '%d', '%d' )
		);

		if ( false === $result ) {
			wptsall_log_error(
				'sites-relations',
				'Failed to remove model from relation',
				array(
					'relation_id' => $relation_id,
					'model_id'    => $model_id,
					'error'       => $wpdb->last_error,
				)
			);
			return false;
		}

		// update models_count cache.
		self::update_models_count( $relation_id );

		wptsall_log_info(
			'sites-relations',
			'Model removed from relation',
			array(
				'relation_id' => $relation_id,
				'model_id'    => $model_id,
			)
		);

		/**
		 * Fires when relation models are updated.
		 *
		 * @since 1.0.0
		 * @param int $relation_id The relation ID.
		 */
		do_action( 'wptsall_relation_models_updated', $relation_id );

		return true;
	}

	/**
	 * Bulk set model associations for a site relation
	 *
	 * Replaces all existing associations
	 *
	 * @param int   $relation_id site relation ID.
	 * @param array $model_ids   Array of model IDs.
	 * @return bool Returns true on success.
	 */
	public static function set_relation_models( $relation_id, $model_ids ) {
		global $wpdb;
		$rm_table = wptsall_table( 'relation_models' );

		// Start transaction.
		$wpdb->query( 'START TRANSACTION' );

		try {
			// Delete existing associations.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete(
				$rm_table,
				array( 'relation_id' => $relation_id ),
				array( '%d' )
			);

			// Insert new association.
			$now = current_time( 'mysql' );
			foreach ( $model_ids as $model_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->insert(
					$rm_table,
					array(
						'relation_id' => $relation_id,
						'model_id'    => (int) $model_id,
						'created_at'  => $now,
					),
					array( '%d', '%d', '%s' )
				);
			}

			// Commit transaction.
			$wpdb->query( 'COMMIT' );

			// update models_count cache.
			self::update_models_count( $relation_id );

			wptsall_log_info(
				'sites-relations',
				'Relation models updated',
				array(
					'relation_id' => $relation_id,
					'model_ids'   => $model_ids,
					'count'       => count( $model_ids ),
				)
			);

			/**
			 * Fires when relation models are updated.
			 *
			 * @since 1.0.0
			 * @param int $relation_id The relation ID.
			 */
			do_action( 'wptsall_relation_models_updated', $relation_id );

			return true;
		} catch ( \Exception $e ) {
			$wpdb->query( 'ROLLBACK' );
			wptsall_log_error(
				'sites-relations',
				'Failed to set relation models',
				array(
					'relation_id' => $relation_id,
					'error'       => $e->getMessage(),
				)
			);
			return false;
		}
	}

	/**
	 * Update the models_count cache field for a site relation
	 *
	 * @param int $relation_id site relation ID.
	 */
	public static function update_models_count( $relation_id ) {
		global $wpdb;
		$rm_table        = wptsall_table( 'relation_models' );
		$relations_table = wptsall_table( 'site_relations' );

		// Get association count.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE relation_id = %d',
				$rm_table,
				$relation_id
			)
		);

		// Update cache field.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$relations_table,
			array( 'models_count' => (int) $count ),
			array( 'id' => $relation_id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Get model count for a site relation
	 *
	 * @param int $relation_id site relation ID.
	 * @return int Model count.
	 */
	public static function get_models_count( $relation_id ) {
		global $wpdb;
		$rm_table = wptsall_table( 'relation_models' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE relation_id = %d',
				$rm_table,
				$relation_id
			)
		);

		return (int) $count;
	}

	/**
	 * Check if a model is already associated with a site relation
	 *
	 * @param int $relation_id site relation ID.
	 * @param int $model_id    model ID.
	 * @return bool Whether the model is already associated.
	 */
	public static function is_model_associated( $relation_id, $model_id ) {
		global $wpdb;
		$rm_table = wptsall_table( 'relation_models' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE relation_id = %d AND model_id = %d',
				$rm_table,
				$relation_id,
				$model_id
			)
		);

		return (bool) $exists;
	}

	/**
	 * Delete all model associations for a site relation
	 *
	 * Usually called when deleting a site relation
	 *
	 * @param int $relation_id site relation ID.
	 * @return bool Returns true on success.
	 */
	public static function delete_relation_models( $relation_id ) {
		global $wpdb;
		$rm_table = wptsall_table( 'relation_models' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete(
			$rm_table,
			array( 'relation_id' => $relation_id ),
			array( '%d' )
		);

		if ( false === $result ) {
			return false;
		}

		wptsall_log_info(
			'sites-relations',
			'All models removed from relation',
			array( 'relation_id' => $relation_id )
		);

		return true;
	}

	/**
	 * Get model details for models associated with a relation (includes language pack match status)
	 *
	 * @param int    $relation_id site relation ID.
	 * @param string $target_lang Target language code.
	 * @return array List of model details.
	 */
	public static function get_models_with_language_pack_status( $relation_id, $target_lang ) {
		$models = self::get_models_by_relation( $relation_id );

		if ( empty( $models ) ) {
			return array();
		}

		$templates_table = wptsall_table( 'templates' );
		global $wpdb;

		foreach ( $models as &$model ) {
			// Find matching language pack.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$template = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, status, total_entries, translated_entries
					FROM %i
					WHERE source_type = 'plugin'
					AND source_identifier = %s
					AND target_language = %s
					LIMIT 1",
					$templates_table,
					$model['plugin_slug'],
					$target_lang
				),
				ARRAY_A
			);

			if ( $template ) {
				$model['language_pack'] = array(
					'matched'            => true,
					'template_id'        => $template['id'],
					'status'             => $template['status'],
					'total_entries'      => (int) $template['total_entries'],
					'translated_entries' => (int) $template['translated_entries'],
					'progress'           => $template['total_entries'] > 0
						? round( ( $template['translated_entries'] / $template['total_entries'] ) * 100, 1 )
						: 0,
				);
			} else {
				$model['language_pack'] = array(
					'matched' => false,
					'message' => sprintf(
						/* translators: %s: target language code */
						__( 'Missing %s language pack', 'wpmmcc-ats' ),
						$target_lang
					),
				);
			}
		}

		return $models;
	}

	/**
	 * Bulk add models to a site relation (attach alias)
	 *
	 * Conforms to the MODULE-CHAINS.md Chain 7 method signature.
	 *
	 * @since 1.0.0
	 * @param int   $relation_id site relation ID.
	 * @param array $model_ids   Array of model IDs.
	 * @return bool Returns true on success.
	 */
	public static function attach( $relation_id, $model_ids ) {
		if ( ! is_array( $model_ids ) || empty( $model_ids ) ) {
			return false;
		}

		foreach ( $model_ids as $model_id ) {
			$result = self::add_model_to_relation( $relation_id, (int) $model_id );
			if ( false === $result ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Bulk remove models from a site relation (detach alias)
	 *
	 * Conforms to the MODULE-CHAINS.md Chain 7 method signature.
	 *
	 * @since 1.0.0
	 * @param int   $relation_id site relation ID.
	 * @param array $model_ids   Array of model IDs.
	 * @return bool Returns true on success.
	 */
	public static function detach( $relation_id, $model_ids ) {
		if ( ! is_array( $model_ids ) || empty( $model_ids ) ) {
			return false;
		}

		foreach ( $model_ids as $model_id ) {
			$result = self::remove_model_from_relation( $relation_id, (int) $model_id );
			if ( false === $result ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get all models associated with a site relation (get_models alias)
	 *
	 * Conforms to the MODULE-CHAINS.md Chain 7 method signature.
	 * This method is an alias of get_models_by_relation().
	 *
	 * @since 1.0.0
	 * @param int $relation_id site relation ID.
	 * @return array List of associated models.
	 */
	public static function get_models( $relation_id ) {
		return self::get_models_by_relation( $relation_id );
	}
}

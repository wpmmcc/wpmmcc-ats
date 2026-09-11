<?php
/**
 * Unified content-change dispatcher for virtual-site mappings.
 *
 * Captures WordPress content lifecycle (REST/Gutenberg/CLI/classic) and:
 *  - marks post_mappings.needs_resync (idempotent with Hook_Manager)
 *  - propagates status / trash / delete to mapped target posts
 *  - guards against write-back re-entry via request-scoped internal write flag
 *
 * Classic admin "Sync on save" checkbox remains an explicit full-sync command;
 * this dispatcher does not replace that UI path.
 *
 * @package WPTSALL\Hooks
 * @since 2.0.1
 */

namespace WPTSALL\Hooks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Content_Change_Dispatcher class.
 */
class Content_Change_Dispatcher {

	/**
	 * Nested internal-write depth.
	 *
	 * @var int
	 */
	private static $internal_write_depth = 0;

	/** @var array<int,bool> Media events emitted in this request. */
	private static $media_events_emitted = array();

	/** @var string Request-scoped id used to coalesce duplicate WP hooks. */
	private static $outbox_request_id = '';

	/**
	 * Bootstrap lifecycle hooks.
	 *
	 * @return void
	 */
	public static function init() {
		self::$outbox_request_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'wptsall-', true );
		add_action( 'wp_after_insert_post', array( __CLASS__, 'on_after_insert_post' ), 100, 4 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition_post_status' ), 20, 3 );
		add_action( 'wp_trash_post', array( __CLASS__, 'on_trash_post' ), 20, 1 );
		add_action( 'untrashed_post', array( __CLASS__, 'on_untrash_post' ), 20, 1 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_before_delete_post' ), 20, 1 );

		// Media is stored as an attachment post, but its binary/alt/metadata
		// lifecycle has additional hooks. Keep all of them in the dispatcher so
		// an existing translation mapping becomes one idempotent resync command.
		add_action( 'add_attachment', array( __CLASS__, 'on_attachment_created' ), 100, 1 );
		add_action( 'edit_attachment', array( __CLASS__, 'on_attachment_edited' ), 100, 1 );
		add_action( 'delete_attachment', array( __CLASS__, 'on_attachment_deleted' ), 20, 1 );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_attachment_meta_changed' ), 100, 4 );
		add_action( 'added_post_meta', array( __CLASS__, 'on_attachment_meta_changed' ), 100, 4 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'on_attachment_meta_changed' ), 100, 4 );

		// Term lifecycle (ISS P-A1 / W1).
		add_action( 'created_term', array( __CLASS__, 'on_term_created' ), 20, 3 );
		add_action( 'edited_term', array( __CLASS__, 'on_term_edited' ), 20, 3 );
		add_action( 'delete_term', array( __CLASS__, 'on_term_deleted' ), 20, 4 );

		// Option-backed translation sources do not pass through post/term hooks.
		// Keep their relation-scoped state dirty when the source option changes;
		// internal target writes are covered by the suppression guard above.
		add_action( 'added_option', array( __CLASS__, 'on_option_changed' ), 100, 2 );
		add_action( 'updated_option', array( __CLASS__, 'on_option_changed' ), 100, 3 );
		add_action( 'deleted_option', array( __CLASS__, 'on_option_changed' ), 100, 1 );
	}

	/**
	 * Whether the current stack is an internal WPTSALL write-back.
	 *
	 * @return bool
	 */
	public static function is_internal_write() {
		return self::$internal_write_depth > 0
			|| ! empty( $GLOBALS['wptsall_sync_suppression_depth'] );
	}

	/**
	 * Run a callable with internal-write protection.
	 *
	 * @param callable $callback Callback.
	 * @return mixed
	 */
	public static function with_internal_write( callable $callback ) {
		self::$internal_write_depth++;
		try {
			return $callback();
		} finally {
			self::$internal_write_depth = max( 0, self::$internal_write_depth - 1 );
		}
	}

	/**
	 * @param int           $post_id     Post ID.
	 * @param \WP_Post      $post        Post object.
	 * @param bool          $update      Whether update.
	 * @param null|\WP_Post $post_before Previous post or null.
	 * @return void
	 */
	public static function on_after_insert_post( $post_id, $post, $update, $post_before = null ) {
		unset( $post_before );
		// Attachments are excluded from the generic scanner, but still have a
		// first-class media lifecycle and must reach this dispatcher.
		if ( $post instanceof \WP_Post && 'attachment' === $post->post_type ) {
			self::dispatch_media_change( (int) $post_id, $update ? 'attachment_updated' : 'attachment_created' );
			return;
		}
		if ( self::should_skip_post( $post_id, $post ) ) {
			return;
		}

		self::mark_needs_resync( (int) $post_id );
		self::enqueue_change( 'post', (int) $post_id, $update ? 'post_updated' : 'post_created', array( 'post_type' => $post->post_type ) );

		/**
		 * Fires after a managed source post is inserted/updated via any WP entry
		 * (classic, Gutenberg REST, WP-CLI, programmatic updates).
		 *
		 * @param int      $post_id Post ID.
		 * @param \WP_Post $post    Post object.
		 * @param bool     $update  Whether this is an update.
		 */
		do_action( 'wptsall_content_changed', (int) $post_id, $post, (bool) $update );
	}

	/**
	 * Propagate publish/private/draft status to mapped targets.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 * @return void
	 */
	public static function on_transition_post_status( $new_status, $old_status, $post ) {
		if ( self::is_internal_write() || ! ( $post instanceof \WP_Post ) ) {
			return;
		}
		if ( $new_status === $old_status ) {
			return;
		}
		if ( self::should_skip_post( (int) $post->ID, $post ) ) {
			return;
		}
		// Trash handled by wp_trash_post / untrashed_post for clearer semantics.
		if ( in_array( $new_status, array( 'trash', 'auto-draft' ), true ) || 'trash' === $old_status ) {
			return;
		}

		$targets = self::mapped_target_ids( (int) $post->ID );
		if ( empty( $targets ) ) {
			return;
		}

		self::with_internal_write(
			static function () use ( $targets, $new_status ) {
				foreach ( $targets as $target_mapping ) {
					self::with_target_mapping(
						$target_mapping,
						static function ( $tid, $mapping ) use ( $new_status ) {
							$target = get_post( $tid );
							if ( ! $target || $target->post_status === $new_status ) {
								return;
							}
							if ( ! empty( $mapping['target_post_type'] ) && $target->post_type !== $mapping['target_post_type'] ) {
								return;
							}
							wp_update_post(
								array(
									'ID'          => $tid,
									'post_status' => $new_status,
								),
								true
							);
						}
					);
				}
			}
		);
	}

	/**
	 * @param int $post_id Source post ID.
	 * @return void
	 */
	public static function on_trash_post( $post_id ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );
		if ( self::should_skip_post( $post_id, $post ) ) {
			return;
		}
		$targets = self::mapped_target_ids( $post_id );
		self::with_internal_write(
			static function () use ( $targets ) {
				foreach ( $targets as $target_mapping ) {
					self::with_target_mapping(
						$target_mapping,
						static function ( $tid ) {
							if ( 'trash' !== get_post_status( $tid ) ) {
								wp_trash_post( $tid );
						}
						}
					);
				}
			}
		);
		self::mark_needs_resync( $post_id );
		self::enqueue_change( 'post', $post_id, 'post_trashed' );
	}

	/**
	 * @param int $post_id Source post ID.
	 * @return void
	 */
	public static function on_untrash_post( $post_id ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );
		if ( self::should_skip_post( $post_id, $post ) ) {
			return;
		}
		$targets = self::mapped_target_ids( $post_id );
		self::with_internal_write(
			static function () use ( $targets ) {
				foreach ( $targets as $target_mapping ) {
					self::with_target_mapping(
						$target_mapping,
						static function ( $tid ) {
							if ( 'trash' === get_post_status( $tid ) ) {
								wp_untrash_post( $tid );
							}
						}
					);
				}
			}
		);
		self::mark_needs_resync( $post_id );
		self::enqueue_change( 'post', $post_id, 'post_untrashed' );
	}

	/**
	 * @param int $post_id Source post ID.
	 * @return void
	 */
	public static function on_before_delete_post( $post_id ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );
		if ( self::should_skip_post( $post_id, $post ) ) {
			return;
		}
		$targets = self::mapped_target_ids( $post_id );
		self::with_internal_write(
			static function () use ( $targets ) {
				foreach ( $targets as $target_mapping ) {
					self::with_target_mapping(
						$target_mapping,
						static function ( $tid ) {
							wp_delete_post( $tid, true );
						}
					);
				}
			}
		);
		self::enqueue_change( 'post', $post_id, 'post_deleted' );
	}

	/**
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public static function on_attachment_created( $attachment_id ) {
		self::dispatch_media_change( (int) $attachment_id, 'attachment_created' );
	}

	/**
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public static function on_attachment_edited( $attachment_id ) {
		self::dispatch_media_change( (int) $attachment_id, 'attachment_updated' );
	}

	/**
	 * Remove mappings where an attachment is a source or a local target.
	 * Target deletion intentionally removes the mapping instead of making the
	 * source look translated; a later source change can create a fresh mapping.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public static function on_attachment_deleted( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 || self::is_internal_write() ) {
			return;
		}
		$source_attachment_id = (int) \WPTSALL\Sites\Services\Translation_Identity::raw_meta( (int) $attachment_id, '_wptsall_source_attachment_id' );
		global $wpdb;
		$table = function_exists( 'wptsall_table' ) ? wptsall_table( 'media_mappings' ) : $wpdb->prefix . 'wptsall_media_mappings';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return;
		}
		// Keep a tombstone for source deletion so the Client can propagate the
		// delete to each target. Removing the row would make the object appear
		// merely “never translated” and lose the target relationship.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $source_attachment_id > 0 ) {
			// A translated target was removed. Keep the source mapping as a
			// tombstone so the next client run can recreate the target media.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- tombstone write path; caching is not applicable.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE %i
					 SET target_media_id = 0, target_file_path = '', target_file_url = '',
					     mapping_method = 'target_deleted', needs_resync = 1, claimed_at = NULL, updated_at = %s
					 WHERE source_media_id = %d AND target_media_id = %d",
					$table,
					current_time( 'mysql', true ),
					$source_attachment_id,
					$attachment_id
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- tombstone write path; caching is not applicable.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE %i
					 SET target_media_id = 0, target_file_path = '', target_file_url = '',
					     mapping_method = 'deleted', needs_resync = 1, claimed_at = NULL, updated_at = %s
					 WHERE source_media_id = %d AND source_site_id = %d",
					$table,
					current_time( 'mysql', true ),
					$attachment_id,
					(int) get_current_blog_id()
				)
			);
		}
		do_action( 'wptsall_media_changed', $attachment_id, null, 'attachment_deleted', '', 1 );
		self::enqueue_change( 'media', $attachment_id, 'attachment_deleted' );
		do_action( 'wptsall_media_deleted', $attachment_id, (int) get_current_blog_id() );
	}

	/**
	 * Only metadata that materially changes a media translation should mark
	 * mappings dirty. Internal marker/ownership writes must never self-trigger.
	 *
	 * @param int    $meta_id    Meta row ID.
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @return void
	 */
	public static function on_attachment_meta_changed( $meta_id, $object_id, $meta_key, $meta_value = null ) {
		unset( $meta_id, $meta_value );
		$meta_key = (string) $meta_key;
		if ( '' === $meta_key || 0 === strpos( $meta_key, '_wptsall_' ) || in_array( $meta_key, array( '_edit_lock', '_edit_last' ), true ) ) {
			return;
		}
		$attachment = get_post( (int) $object_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return;
		}
		self::dispatch_media_change( (int) $object_id, 'attachment_meta_changed', $meta_key );
	}

	/**
	 * Mark every mapped target for an attachment as needing re-sync and emit a
	 * normalized media domain event. The UPDATE is deliberately idempotent:
	 * WordPress often fires edit_attachment and post-meta hooks in one request.
	 *
	 * This is the dispatcher boundary. Actual binary transfer/write-back remains
	 * a Client command, not a direct side effect of a WordPress hook.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $event         Normalized lifecycle event.
	 * @param string $meta_key      Changed meta key, if any.
	 * @return void
	 */
	private static function dispatch_media_change( $attachment_id, $event, $meta_key = '' ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 || self::is_internal_write() ) {
			return;
		}
		$attachment = get_post( $attachment_id );
		$is_shadow  = '' !== (string) \WPTSALL\Sites\Services\Translation_Identity::raw_meta( $attachment_id, \WPTSALL\Sites\Services\Translation_Identity::META_VIRTUAL_SITE_ID );
		if ( ! $attachment || 'attachment' !== $attachment->post_type || $is_shadow ) {
			return;
		}
		global $wpdb;
		$table = function_exists( 'wptsall_table' ) ? wptsall_table( 'media_mappings' ) : $wpdb->prefix . 'wptsall_media_mappings';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return;
		}
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = (int) $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET needs_resync = 1, claimed_at = NULL, updated_at = %s WHERE source_media_id = %d AND source_site_id = %d AND needs_resync = 0',
				$table,
				$now,
				$attachment_id,
				(int) get_current_blog_id()
			)
		);
		// A single WP save commonly emits wp_after_insert_post, edit_attachment,
		// and updated_post_meta. Marking state is idempotent on every hook, while
		// the domain event itself is coalesced to one per attachment/request.
		if ( ! isset( self::$media_events_emitted[ $attachment_id ] ) ) {
			self::$media_events_emitted[ $attachment_id ] = true;
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- 'meta_key' below is an event payload array key, not a meta_key DB query.
			self::enqueue_change( 'media', $attachment_id, (string) $event, array( 'meta_key' => (string) $meta_key ) );
			do_action(
				'wptsall_media_changed',
				$attachment_id,
				$attachment,
				(string) $event,
				(string) $meta_key,
				$updated
			);
		}
	}

	/**
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    Post object.
	 * @return bool
	 */
	private static function should_skip_post( $post_id, $post ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 || self::is_internal_write() ) {
			return true;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return true;
		}
		if ( ! ( $post instanceof \WP_Post ) ) {
			$post = get_post( $post_id );
		}
		if ( ! ( $post instanceof \WP_Post ) ) {
			return true;
		}
		if ( in_array( $post->post_type, array( 'revision', 'nav_menu_item', 'customize_changeset', 'oembed_cache' ), true ) ) {
			return true;
		}
		if ( 'auto-draft' === $post->post_status ) {
			return true;
		}
		// Shadow / translated copies must not re-dispatch.
		$is_shadow = '' !== (string) \WPTSALL\Sites\Services\Translation_Identity::raw_meta( (int) $post_id, \WPTSALL\Sites\Services\Translation_Identity::META_VIRTUAL_SITE_ID );
		if ( $is_shadow ) {
			return true;
		}
		if ( class_exists( '\\WPTSALL\\Models\\Services\\Plugin_Scanner' )
			&& method_exists( '\\WPTSALL\\Models\\Services\\Plugin_Scanner', 'is_excluded_post_type' )
			&& \WPTSALL\Models\Services\Plugin_Scanner::is_excluded_post_type( $post->post_type ) ) {
			return true;
		}
		return false;
	}

	/**
	 * @param int $source_post_id Source post ID.
	 * @return array<int>
	 */
	private static function legacy_mapped_target_ids( $source_post_id ) {
		if ( class_exists( '\\WPTSALL\\Sync\\Services\\Sync_Service' ) ) {
			return \WPTSALL\Sync\Services\Sync_Service::find_target_posts_for( (int) $source_post_id );
		}
		return array();
	}

	/**
	 * Mark active relation mappings for a source post dirty.
	 *
	 * @param int $source_post_id Source post ID.
	 * @return void
	 */
	private static function mark_needs_resync( $source_post_id ) {
		global $wpdb;
		$table          = function_exists( 'wptsall_table' ) ? wptsall_table( 'post_mappings' ) : $wpdb->prefix . 'wptsall_post_mappings';
		$relations      = function_exists( 'wptsall_table' ) ? wptsall_table( 'site_relations' ) : $wpdb->prefix . 'wptsall_site_relations';
		$source_site_id = (int) get_current_blog_id();
		$now            = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- dirty-mark write path; caching is not applicable.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i pm
				 INNER JOIN %i r ON r.id = pm.relation_id
					AND r.source_site_id = pm.source_site_id
					AND r.target_site_id = pm.target_site_id
					AND r.status = %s
				 SET pm.needs_resync = 1, pm.updated_at = %s
				 WHERE pm.source_post_id = %d
				 AND pm.source_site_id = %d
				 AND pm.relation_id > 0
				 AND pm.needs_resync = 0',
				$table,
				$relations,
				'active',
				$now,
				(int) $source_post_id,
				$source_site_id
			)
		);
	}

	/**
	 * Mark every active relation's option state dirty after a source option
	 * changes. The option name is checked against the existing sync allowlist
	 * for each relation, so an arbitrary wp_options value is never persisted as
	 * client-discoverable work.
	 *
	 * @param string $option    Option name.
	 * @param mixed  $old_value Previous value or omitted for add/delete hooks.
	 * @param mixed  $value     New value or omitted for add/delete hooks.
	 * @return void
	 */
	public static function on_option_changed( $option, $old_value = null, $value = null ) {
		unset( $old_value, $value );
		if ( self::is_internal_write() || ! function_exists( 'wptsall_is_syncable_option' ) ) {
			return;
		}

		$option = sanitize_key( (string) $option );
		if ( '' === $option || wptsall_is_denied_option( $option ) || ! class_exists( '\WPTSALL\\Sites\\Services\\Site_Relation_Service' ) ) {
			return;
		}
		if ( ! class_exists( '\WPTSALL\\Models\\Services\\Option_Sync_State_Service' ) ) {
			return;
		}

		// A brand-new subsite fires option updates during role population
		// (wp_initialize_site -> populate_roles) before Plugin_Lifecycle::
		// handle_new_site() provisions its table family; without this guard
		// every such update queries a not-yet-existing table and logs a
		// database error (surfaced by the public compat-matrix multisite
		// lane). The cached existence check keeps the hot path cheap.
		if ( ! function_exists( 'wptsall_schema_table_exists' ) || ! function_exists( 'wptsall_table' ) ) {
			return;
		}
		if ( ! wptsall_schema_table_exists( wptsall_table( 'site_relations' ) ) ) {
			return;
		}

		$source_site_id = (int) get_current_blog_id();
		$relations = (array) \WPTSALL\Sites\Services\Site_Relation_Service::get_all_relations(
			array(
				'status'         => 'active',
				'source_site_id' => $source_site_id,
			),
			false
		);
		foreach ( $relations as $relation ) {
			$relation_id = absint( $relation['id'] ?? 0 );
			if ( $relation_id <= 0 || ! wptsall_is_syncable_option( $option, $relation_id ) ) {
				continue;
			}
			\WPTSALL\Models\Services\Option_Sync_State_Service::mark_needs_resync(
				$relation_id,
				$source_site_id,
				(string) ( $relation['target_site_id'] ?? '' ),
				$option
			);
		}
	}

	/**
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	public static function on_term_created( $term_id, $tt_id, $taxonomy ) {
		unset( $tt_id );
		self::on_term_changed( (int) $term_id, (string) $taxonomy, false );
	}

	/**
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	public static function on_term_edited( $term_id, $tt_id, $taxonomy ) {
		unset( $tt_id );
		self::on_term_changed( (int) $term_id, (string) $taxonomy, true );
	}

	/**
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 * @param mixed  $deleted  Deleted term object (WP_Term|array|null).
	 * @return void
	 */
	public static function on_term_deleted( $term_id, $tt_id, $taxonomy, $deleted = null ) {
		unset( $tt_id, $deleted );
		$term_id = (int) $term_id;
		if ( $term_id <= 0 || self::is_internal_write() ) {
			return;
		}
		if ( self::should_skip_taxonomy( (string) $taxonomy ) ) {
			return;
		}
		$targets = self::mapped_target_term_ids( $term_id, (string) $taxonomy );
		self::with_internal_write(
			static function () use ( $targets, $taxonomy ) {
				foreach ( $targets as $target_mapping ) {
					self::with_target_mapping(
						$target_mapping,
						static function ( $tid, $mapping ) use ( $taxonomy ) {
							$target_taxonomy = sanitize_key( (string) ( $mapping['target_taxonomy'] ?? $taxonomy ) );
							if ( '' !== $target_taxonomy ) {
								wp_delete_term( (int) $tid, $target_taxonomy );
							}
						}
					);
				}
			}
		);
		self::mark_term_needs_resync( $term_id, (string) $taxonomy );
		self::enqueue_change( 'term', $term_id, 'term_deleted', array( 'taxonomy' => (string) $taxonomy ) );
	}

	/**
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy.
	 * @param bool   $update   Whether update.
	 * @return void
	 */
	private static function on_term_changed( $term_id, $taxonomy, $update ) {
		$term_id = (int) $term_id;
		if ( $term_id <= 0 || self::is_internal_write() ) {
			return;
		}
		if ( self::should_skip_taxonomy( $taxonomy ) ) {
			return;
		}
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return;
		}
		// Mapped targets must not re-dispatch.
		if ( self::is_mapped_target_term( $term_id, $taxonomy ) ) {
			return;
		}
		self::mark_term_needs_resync( $term_id, $taxonomy );
		self::enqueue_change( 'term', $term_id, $update ? 'term_updated' : 'term_created', array( 'taxonomy' => (string) $taxonomy ) );
		/**
		 * Fires after a managed source term is created/updated.
		 *
		 * @param int    $term_id  Term ID.
		 * @param string $taxonomy Taxonomy.
		 * @param bool   $update   Whether update.
		 */
		do_action( 'wptsall_term_changed', $term_id, $taxonomy, (bool) $update );
	}

	/**
	 * @param string $taxonomy Taxonomy slug.
	 * @return bool
	 */
	private static function should_skip_taxonomy( $taxonomy ) {
		$taxonomy = (string) $taxonomy;
		if ( '' === $taxonomy || 'nav_menu' === $taxonomy ) {
			return true;
		}
		if ( class_exists( '\\WPTSALL\\Models\\Services\\Plugin_Scanner' )
			&& method_exists( '\\WPTSALL\\Models\\Services\\Plugin_Scanner', 'is_excluded_taxonomy' )
			&& \WPTSALL\Models\Services\Plugin_Scanner::is_excluded_taxonomy( $taxonomy ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Return active relation-scoped target mappings for a source post.
	 *
	 * The mapping tables are site-local in multisite. Always bind the lookup to
	 * the current source blog and to an active relation; a source object ID by
	 * itself is not an authorization key.
	 *
	 * @param int $source_post_id Source post ID.
	 * @return array<int,array<string,mixed>>
	 */
	private static function mapped_target_ids( $source_post_id ) {
		global $wpdb;
		$table          = function_exists( 'wptsall_table' ) ? wptsall_table( 'post_mappings' ) : $wpdb->prefix . 'wptsall_post_mappings';
		$relations      = function_exists( 'wptsall_table' ) ? wptsall_table( 'site_relations' ) : $wpdb->prefix . 'wptsall_site_relations';
		$source_site_id = (int) get_current_blog_id();
		if ( $source_post_id <= 0 ) {
			return array();
		}

		// Do not fall back to relation_id=0. Legacy rows without a relation
		// cannot prove which target site/relation owns the object.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- mapping read; freshness required, caches would go stale on mapping writes.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT pm.target_post_id, pm.target_post_type, pm.target_site_id,
						pm.relation_id, r.target_site_type
				 FROM %i pm
				 INNER JOIN %i r ON r.id = pm.relation_id
					AND r.source_site_id = pm.source_site_id
					AND r.target_site_id = pm.target_site_id
					AND r.status = %s
				 WHERE pm.source_post_id = %d
				 AND pm.source_site_id = %d
				 AND pm.relation_id > 0
				 AND pm.target_post_id > 0
				 AND pm.target_site_id <> %s',
				$table,
				$relations,
				'active',
				(int) $source_post_id,
				$source_site_id,
				''
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Run a target operation in the target relation's blog context.
	 *
	 * @param array    $mapping Mapping row returned by mapped_target_ids().
	 * @param callable $callback Callback receiving target ID and mapping row.
	 * @return mixed
	 */
	private static function with_target_mapping( array $mapping, callable $callback ) {
		$target_id = absint( $mapping['target_post_id'] ?? $mapping['target_term_id'] ?? 0 );
		if ( $target_id <= 0 ) {
			return null;
		}
		$target_type = sanitize_key( (string) ( $mapping['target_site_type'] ?? 'wp' ) );
		$target_site = (string) ( $mapping['target_site_id'] ?? '' );
		$switched    = false;
		if ( 'wp' === $target_type && is_multisite() && ctype_digit( $target_site ) ) {
			$target_blog_id = (int) $target_site;
			if ( $target_blog_id > 0 && $target_blog_id !== (int) get_current_blog_id() ) {
				switch_to_blog( $target_blog_id );
				$switched = true;
			}
		}
		try {
			return $callback( $target_id, $mapping );
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Return active relation-scoped target mappings for a source term.
	 *
	 * @param int    $source_term_id Source term ID.
	 * @param string $taxonomy      Source taxonomy.
	 * @return array<int,array<string,mixed>>
	 */
	private static function mapped_target_term_ids( $source_term_id, $taxonomy = '' ) {
		global $wpdb;
		$table          = function_exists( 'wptsall_table' ) ? wptsall_table( 'term_mappings' ) : $wpdb->prefix . 'wptsall_term_mappings';
		$relations      = function_exists( 'wptsall_table' ) ? wptsall_table( 'site_relations' ) : $wpdb->prefix . 'wptsall_site_relations';
		$source_site_id = (int) get_current_blog_id();
		if ( $source_term_id <= 0 ) {
			return array();
		}
		$taxonomy = sanitize_key( (string) $taxonomy );
		if ( '' !== $taxonomy ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- mapping read; freshness required, caches would go stale on mapping writes.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT tm.target_term_id, tm.target_taxonomy, tm.target_site_id,
							tm.relation_id, r.target_site_type
					 FROM %i tm
					 INNER JOIN %i r ON r.id = tm.relation_id
						AND r.source_site_id = tm.source_site_id
						AND r.target_site_id = tm.target_site_id
						AND r.status = %s
					 WHERE tm.source_term_id = %d AND tm.source_site_id = %d
						AND tm.relation_id > 0 AND tm.target_term_id > 0
						AND tm.target_site_id <> %s AND tm.source_taxonomy = %s',
					$table,
					$relations,
					'active',
					(int) $source_term_id,
					$source_site_id,
					'',
					$taxonomy
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- mapping read; freshness required, caches would go stale on mapping writes.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT tm.target_term_id, tm.target_taxonomy, tm.target_site_id,
							tm.relation_id, r.target_site_type
					 FROM %i tm
					 INNER JOIN %i r ON r.id = tm.relation_id
						AND r.source_site_id = tm.source_site_id
						AND r.target_site_id = tm.target_site_id
						AND r.status = %s
					 WHERE tm.source_term_id = %d AND tm.source_site_id = %d
						AND tm.relation_id > 0 AND tm.target_term_id > 0
						AND tm.target_site_id <> %s',
					$table,
					$relations,
					'active',
					(int) $source_term_id,
					$source_site_id,
					''
				),
				ARRAY_A
			);
		}
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param int    $term_id Term ID on the current blog.
	 * @param string $taxonomy Taxonomy.
	 * @return bool
	 */
	private static function is_mapped_target_term( $term_id, $taxonomy = '' ) {
		if ( get_term_meta( (int) $term_id, '_wptsall_virtual_site_id', true ) ) {
			return true;
		}
		global $wpdb;
		$table          = function_exists( 'wptsall_table' ) ? wptsall_table( 'term_mappings' ) : $wpdb->prefix . 'wptsall_term_mappings';
		$relations      = function_exists( 'wptsall_table' ) ? wptsall_table( 'site_relations' ) : $wpdb->prefix . 'wptsall_site_relations';
		$current_site_id = (int) get_current_blog_id();
		$taxonomy        = sanitize_key( (string) $taxonomy );
		$source_id       = 0;
		if ( '' !== $taxonomy ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- mapping read; freshness required, caches would go stale on mapping writes.
			$source_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT tm.source_term_id
					 FROM %i tm
					 INNER JOIN %i r ON r.id = tm.relation_id
						AND r.target_site_id = tm.target_site_id
						AND r.target_site_type = %s
						AND r.status = %s
					 WHERE tm.target_term_id = %d AND tm.target_site_id = %s
						AND tm.relation_id > 0 AND tm.target_taxonomy = %s LIMIT 1',
					$table,
					$relations,
					'wp',
					'active',
					(int) $term_id,
					(string) $current_site_id,
					$taxonomy
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- mapping read; freshness required, caches would go stale on mapping writes.
			$source_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT tm.source_term_id
					 FROM %i tm
					 INNER JOIN %i r ON r.id = tm.relation_id
						AND r.target_site_id = tm.target_site_id
						AND r.target_site_type = %s
						AND r.status = %s
					 WHERE tm.target_term_id = %d AND tm.target_site_id = %s
						AND tm.relation_id > 0 LIMIT 1',
					$table,
					$relations,
					'wp',
					'active',
					(int) $term_id,
					(string) $current_site_id
				)
			);
		}
		return $source_id > 0;
	}

	/**
	 * Mark active relation mappings for a source term dirty.
	 *
	 * @param int    $source_term_id Source term ID.
	 * @param string $taxonomy      Source taxonomy.
	 * @return void
	 */
	private static function mark_term_needs_resync( $source_term_id, $taxonomy = '' ) {
		global $wpdb;
		$table          = function_exists( 'wptsall_table' ) ? wptsall_table( 'term_mappings' ) : $wpdb->prefix . 'wptsall_term_mappings';
		$relations      = function_exists( 'wptsall_table' ) ? wptsall_table( 'site_relations' ) : $wpdb->prefix . 'wptsall_site_relations';
		$source_site_id = (int) get_current_blog_id();
		$now            = current_time( 'mysql', true );
		$taxonomy       = sanitize_key( (string) $taxonomy );
		if ( '' !== $taxonomy ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- dirty-mark write path; caching is not applicable.
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE %i tm
					 INNER JOIN %i r ON r.id = tm.relation_id
						AND r.source_site_id = tm.source_site_id
						AND r.target_site_id = tm.target_site_id
						AND r.status = %s
					 SET tm.needs_resync = 1, tm.updated_at = %s
					 WHERE tm.source_term_id = %d AND tm.source_site_id = %d
						AND tm.relation_id > 0 AND tm.needs_resync = 0
						AND tm.source_taxonomy = %s',
					$table,
					$relations,
					'active',
					$now,
					(int) $source_term_id,
					$source_site_id,
					$taxonomy
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- dirty-mark write path; caching is not applicable.
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE %i tm
					 INNER JOIN %i r ON r.id = tm.relation_id
						AND r.source_site_id = tm.source_site_id
						AND r.target_site_id = tm.target_site_id
						AND r.status = %s
					 SET tm.needs_resync = 1, tm.updated_at = %s
					 WHERE tm.source_term_id = %d AND tm.source_site_id = %d
						AND tm.relation_id > 0 AND tm.needs_resync = 0',
					$table,
					$relations,
					'active',
					$now,
					(int) $source_term_id,
					$source_site_id
				)
			);
		}
	}

	/**
	 * Append a durable, idempotent outbox event for later client/task delivery.
	 *
	 * @param string $source_type Source object type.
	 * @param int    $source_id   Source object ID.
	 * @param string $event_name Lifecycle event.
	 * @param array  $payload     Event payload.
	 * @return int|false Outbox row ID.
	 */
	private static function enqueue_change( $source_type, $source_id, $event_name, array $payload = array() ) {
		global $wpdb;
		$table = function_exists( 'wptsall_table' ) ? wptsall_table( 'content_change_outbox' ) : '';
		if ( '' === $table ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return false;
		}
		if ( '' === self::$outbox_request_id ) {
			self::$outbox_request_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'wptsall-', true );
		}
		// A source change can fan out to more than one active relation. Store one
		// durable row per relation so each target has an independent lease and
		// callback lifecycle. Keep relation_id=0 for sites without relations.
		$relations = array();
		if ( class_exists( '\\WPTSALL\\Sites\\Services\\Site_Relation_Service' ) ) {
			$relations = (array) \WPTSALL\Sites\Services\Site_Relation_Service::get_all_relations(
				array( 'status' => 'active', 'source_site_id' => get_current_blog_id() ),
				false
			);
		}
		if ( empty( $relations ) ) {
			$relations = array( array( 'id' => 0 ) );
		}
		$now       = current_time( 'mysql', true );
		$first_id = false;
		foreach ( $relations as $relation ) {
			$relation_id = absint( $relation['id'] ?? 0 );
			$event_key   = hash( 'sha256', self::$outbox_request_id . '|' . $source_type . '|' . (int) $source_id . '|' . $event_name . '|' . $relation_id );
			$row_payload = array_merge( $payload, array( 'relation_id' => $relation_id ) );
			// A DB-level unique key makes repeated WP hooks safe even across retries.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$ok = $wpdb->query(
				$wpdb->prepare(
					'INSERT IGNORE INTO %i (event_key, source_type, source_id, source_site_id, relation_id, event_name, payload, status, available_at, created_at, updated_at) VALUES (%s, %s, %d, %d, %d, %s, %s, %s, %s, %s, %s)',
					$table, $event_key, (string) $source_type, (int) $source_id,
					(int) get_current_blog_id(), $relation_id, (string) $event_name,
					wp_json_encode( $row_payload ), 'pending', $now, $now, $now
				)
			);
			if ( false !== $ok && $ok > 0 && false === $first_id ) {
				$first_id = (int) $wpdb->insert_id;
			}
		}
		return $first_id;
	}

	/**
	 * Claim pending outbox rows with a lease for one worker.
	 *
	 * @param int $limit Maximum rows.
	 * @param int $lease_secs Lease duration.
	 * @param int $relation_id Relation filter (optional).
	 * @param string $claim_owner Stable client/device id (optional).
	 * @return array<int,array<string,mixed>>
	 */
	public static function claim_outbox( $limit = 50, $lease_secs = 900, $relation_id = 0, $claim_owner = '' ) {
		global $wpdb;
		$table      = wptsall_table( 'content_change_outbox' );
		$limit      = max( 1, min( 500, (int) $limit ) );
		$claim_owner = sanitize_key( (string) $claim_owner );
		$now         = current_time( 'mysql', true );
		$cutoff     = gmdate( 'Y-m-d H:i:s', time() - max( 60, (int) $lease_secs ) );
		// Scan a wider FIFO window than the return cap so a freshly-created
		// event is not starved behind historical backlog during health checks.
		$query_limit = max( $limit, 1000 );
		if ( $relation_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$candidates = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE (status = %s OR (status = %s AND claimed_at < %s)) AND available_at <= %s AND relation_id = %d ORDER BY id DESC LIMIT %d',
					$table,
					'pending',
					'processing',
					$cutoff,
					$now,
					(int) $relation_id,
					$query_limit
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$candidates = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE (status = %s OR (status = %s AND claimed_at < %s)) AND available_at <= %s ORDER BY id DESC LIMIT %d',
					$table,
					'pending',
					'processing',
					$cutoff,
					$now,
					$query_limit
				),
				ARRAY_A
			);
		}
		$claimed = array();
		foreach ( (array) $candidates as $row ) {
			// The SQL window is intentionally wider than the requested batch to
			// avoid starving fresh events, but only the caller's limit may be
			// leased. Without this guard a limit=1 health check leased the entire
			// backlog and caused duplicate task materialization pressure.
			if ( count( $claimed ) >= $limit ) {
				break;
			}
			// Conditional update is the claim CAS: concurrent workers can select
			// the same row but only one acquires its lease.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- queue claim CAS; caching is not applicable to a compare-and-set write.
			$updated = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET status = %s, attempts = attempts + 1, claimed_at = %s, updated_at = %s WHERE id = %d AND (status = %s OR (status = %s AND claimed_at < %s))',
					$table,
					'processing',
					$now,
					$now,
					(int) $row['id'],
					'pending',
					'processing',
					$cutoff
				)
			);
			if ( 1 === (int) $updated ) {
				$row['status']     = 'processing';
				$row['claimed_at'] = $now;
				$row['attempts']   = (int) $row['attempts'] + 1;
				if ( '' !== $claim_owner ) {
					$payload = json_decode( (string) ( $row['payload'] ?? '' ), true );
					if ( ! is_array( $payload ) ) {
						$payload = array();
					}
					$owner_hash = function_exists( 'wptsall_client_claim_owner_hash' )
						? wptsall_client_claim_owner_hash( $claim_owner, (int) $row['relation_id'], '', 'outbox' )
						: hash( 'sha256', 'wptsall-claim-owner-v1|' . $claim_owner . '|' . (int) $row['relation_id'] . '||outbox' );
					$payload['_wptsall_claim_owner_hash'] = $owner_hash;
					unset( $payload['_wptsall_claim_device_id'] );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- claim-owner hash projection on a just-claimed row; caching is not applicable.
					$wpdb->query(
						$wpdb->prepare(
							'UPDATE %i SET payload = %s, updated_at = %s WHERE id = %d AND status = %s',
							$table,
							wp_json_encode( $payload ),
							$now,
							(int) $row['id'],
							'processing'
						)
					);
					$row['payload'] = wp_json_encode( $payload );
				}
				$claimed[]         = $row;
			}
		}
		return $claimed;
	}

	/**
	 * @param int $id Outbox row ID.
	 * @return bool
	 */
	public static function complete_outbox( $id, $claim_owner_hash = '' ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$outbox_table = wptsall_table( 'content_change_outbox' );
		$claim_owner_hash = strtolower( trim( (string) $claim_owner_hash ) );
		if ( '' !== $claim_owner_hash && ! preg_match( '/^[a-f0-9]{64}$/', $claim_owner_hash ) ) {
			return false;
		}
		// Read the task association before closing the lease. A task is only a
		// compatibility/audit projection of this outbox row, but it must reach a
		// terminal state too or an older task-pull worker can run it again.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- lease-state read; caching is not applicable.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT payload FROM %i WHERE id = %d AND status = %s', $outbox_table, (int) $id, 'processing' ),
			ARRAY_A
		);
		if ( '' !== $claim_owner_hash ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- lease completion CAS; caching is not applicable.
			$completed = 1 === (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET status = %s, completed_at = %s, claimed_at = NULL, updated_at = %s
					 WHERE id = %d AND status = %s
					 AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$._wptsall_claim_owner_hash')) = %s",
					$outbox_table,
					'completed',
					$now,
					$now,
					(int) $id,
					'processing',
					$claim_owner_hash
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- lease completion CAS; caching is not applicable.
			$completed = 1 === (int) $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET status = %s, completed_at = %s, claimed_at = NULL, updated_at = %s WHERE id = %d AND status = %s',
					$outbox_table,
					'completed',
					$now,
					$now,
					(int) $id,
					'processing'
				)
			);
		}
		if ( ! $completed ) {
			return false;
		}
		$payload = json_decode( (string) ( $row['payload'] ?? '' ), true );
		$task_id = absint( is_array( $payload ) ? ( $payload['task_id'] ?? 0 ) : 0 );
		if ( $task_id > 0 && function_exists( 'wptsall_table' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET status = %s, status_note = %s, updated_at = %s WHERE id = %d AND status IN (%s, %s, %s, %s)',
					wptsall_table( 'tasks' ), 'completed', 'Completed through durable content outbox', $now, $task_id,
					'pending', 'retry', 'processing', 'active'
				)
			);
		}
		return true;
	}

	/**
	 * @param int    $id Outbox row ID.
	 * @param string $error Error detail.
	 * @return bool
	 */
	public static function fail_outbox( $id, $error = '', $claim_owner_hash = '' ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$claim_owner_hash = strtolower( trim( (string) $claim_owner_hash ) );
		if ( '' !== $claim_owner_hash && ! preg_match( '/^[a-f0-9]{64}$/', $claim_owner_hash ) ) {
			return false;
		}
		$outbox_table = wptsall_table( 'content_change_outbox' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- lease-state read; caching is not applicable.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT payload FROM %i WHERE id = %d AND status = %s', $outbox_table, (int) $id, 'processing' ),
			ARRAY_A
		);
		if ( '' !== $claim_owner_hash ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- lease failure CAS; caching is not applicable.
			$failed = 1 === (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET status = %s, claimed_at = NULL, available_at = %s, last_error = %s, updated_at = %s
					 WHERE id = %d AND status = %s
					 AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$._wptsall_claim_owner_hash')) = %s",
					$outbox_table,
					'pending',
					$now,
					sanitize_text_field( $error ),
					$now,
					(int) $id,
					'processing',
					$claim_owner_hash
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- lease failure CAS; caching is not applicable.
			$failed = 1 === (int) $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET status = %s, claimed_at = NULL, available_at = %s, last_error = %s, updated_at = %s WHERE id = %d AND status = %s',
					$outbox_table,
					'pending',
					$now,
					sanitize_text_field( $error ),
					$now,
					(int) $id,
					'processing'
				)
			);
		}
		if ( ! $failed ) {
			return false;
		}
		$payload = json_decode( (string) ( $row['payload'] ?? '' ), true );
		$task_id = absint( is_array( $payload ) ? ( $payload['task_id'] ?? 0 ) : 0 );
		if ( $task_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- task projection write; caching is not applicable.
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET status = %s, status_note = %s, retry_at = %s, updated_at = %s WHERE id = %d AND status IN (%s, %s, %s, %s)',
					wptsall_table( 'tasks' ),
					'retry',
					'Content outbox callback failed: ' . sanitize_text_field( $error ),
					$now,
					$now,
					$task_id,
					'pending',
					'retry',
					'processing',
					'active'
				)
			);
		}
		return true;
	}
}

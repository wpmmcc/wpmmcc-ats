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
	 * Leases an outbox row gets before it is retired to `dead` (ATS-01).
	 * Filterable through `wptsall_outbox_max_attempts`.
	 */
	const DEFAULT_MAX_ATTEMPTS = 8;

	/** First server-side retry delay and its ceiling, in seconds. */
	const RETRY_BACKOFF_BASE_SECONDS = 30;
	const RETRY_BACKOFF_CAP_SECONDS  = 1800;

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
		// Translation products (client /media-upload sideloads, write-back
		// adapters) carry the source-attachment marker: they are mapping
		// TARGETS, never translation sources. Without this guard every
		// uploaded translation re-enters the media change pipeline as a new
		// claimable source — the attachment copy self-feed chain (WP-side
		// dedupe then stacks -1/-1-1 filename suffixes each round).
		if ( (int) \WPTSALL\Sites\Services\Translation_Identity::raw_meta( $attachment_id, '_wptsall_source_attachment_id' ) > 0 ) {
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
	 * Lease attempts an outbox row gets before it is retired to `dead`.
	 *
	 * `attempts` counts leases (claim_outbox bumps it), so the cap covers both a
	 * client that keeps reporting failure and one that crashes on the row and
	 * never reports anything (the lease simply expires).
	 *
	 * @since 2.3.0
	 * @return int At least 1.
	 */
	public static function max_attempts() {
		/**
		 * Filters how many leases an outbox row gets before it is retired to the
		 * terminal `dead` state.
		 *
		 * @since 2.3.0
		 * @param int $max_attempts Default 8.
		 */
		return max( 1, (int) apply_filters( 'wptsall_outbox_max_attempts', self::DEFAULT_MAX_ATTEMPTS ) );
	}

	/**
	 * Server-side retry delay after a failed lease: 30s doubling per attempt,
	 * capped at 30 minutes (the same ceiling as a client-supplied hint).
	 *
	 * @since 2.3.0
	 * @param int $attempts Leases the row has used so far.
	 * @return int Seconds.
	 */
	public static function retry_backoff_seconds( $attempts ) {
		$doublings = min( 6, max( 0, (int) $attempts - 1 ) );
		return min( self::RETRY_BACKOFF_CAP_SECONDS, self::RETRY_BACKOFF_BASE_SECONDS * ( 1 << $doublings ) );
	}

	/**
	 * Claim pending outbox rows with a lease for one worker.
	 *
	 * Rows that used up their attempts are retired to `dead` here (see
	 * retire_exhausted_outbox_rows) and are never leased again.
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
		$max_attempts = self::max_attempts();
		self::retire_exhausted_outbox_rows( (int) $relation_id, $cutoff, $now );
		// Recover dead leases first: rows still processing past the lease
		// window (crashed or stopped client) are flipped back to pending so
		// any worker can claim them and admin views do not show zombies. The
		// candidate OR-clause below would still re-claim them, but the rows
		// would otherwise linger as 'processing' forever.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- dead-lease recovery; compare-and-set write, caching not applicable.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, claimed_at = NULL, updated_at = %s WHERE status = %s AND claimed_at < %s',
				$table,
				'pending',
				$now,
				'processing',
				$cutoff
			)
		);
		// Scan a wider window than the return cap, oldest-first (FIFO), so
		// the batch is the front of the queue: historical backlog drains in
		// id order and is never starved outside the window. (批D X-12①:
		// this used to be ORDER BY id DESC — newest-first meant a backlog
		// larger than the window kept the oldest rows permanently
		// unreachable while fresh events kept the CPU busy claiming newer
		// ones; the ASC order also rides the claim_queue index's trailing
		// id column.) The window is wider than the lease cap so a
		// health-check claim (limit=1) still sees queue depth without
		// leasing it.
		$query_limit = max( $limit, 1000 );
		if ( $relation_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$candidates = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE (status = %s OR (status = %s AND claimed_at < %s)) AND attempts < %d AND available_at <= %s AND relation_id = %d ORDER BY id ASC LIMIT %d',
					$table,
					'pending',
					'processing',
					$cutoff,
					$max_attempts,
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
					'SELECT * FROM %i WHERE (status = %s OR (status = %s AND claimed_at < %s)) AND attempts < %d AND available_at <= %s ORDER BY id ASC LIMIT %d',
					$table,
					'pending',
					'processing',
					$cutoff,
					$max_attempts,
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
					'UPDATE %i SET status = %s, attempts = attempts + 1, claimed_at = %s, updated_at = %s WHERE id = %d AND attempts < %d AND (status = %s OR (status = %s AND claimed_at < %s))',
					$table,
					'processing',
					$now,
					$now,
					(int) $row['id'],
					$max_attempts,
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
	 * 批 Q (事件驱动): non-mutating claimability peek for the events/wait
	 * long-poll endpoint. Mirrors claim_outbox()'s candidate predicate
	 * (pending now-claimable, or a dead lease past the default 900s window)
	 * WITHOUT any write — no lease, no dead-lease recovery, no attempts
	 * bump. The claim itself stays in claim_outbox() so the CAS/lease/
	 * backoff semantics are unchanged for every caller.
	 *
	 * Rows claim_outbox() would not lease do not count: `dead` rows and rows
	 * that used up their attempts (ATS-01). Without a relation filter only rows
	 * of an active relation of this site count - the content-changes endpoint
	 * claims per active relation, so a row of a deleted relation (or a
	 * relation_id=0 row written before any relation existed) can never be
	 * leased and must not wake the long-poll on every tick.
	 *
	 * @param int $relation_id Relation filter (optional).
	 * @return bool True when at least one row is claimable right now.
	 */
	public static function has_claimable_outbox( $relation_id = 0 ) {
		global $wpdb;
		$table        = wptsall_table( 'content_change_outbox' );
		$now          = current_time( 'mysql', true );
		$cutoff       = gmdate( 'Y-m-d H:i:s', time() - 900 );
		$max_attempts = self::max_attempts();
		if ( (int) $relation_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only peek for the long-poll gate.
			$found = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM %i WHERE (status = %s OR (status = %s AND claimed_at < %s)) AND attempts < %d AND available_at <= %s AND relation_id = %d LIMIT 1',
					$table,
					'pending',
					'processing',
					$cutoff,
					$max_attempts,
					$now,
					(int) $relation_id
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only peek for the long-poll gate.
			$found = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT o.id FROM %i o INNER JOIN %i r ON r.id = o.relation_id AND r.status = %s AND r.source_site_id = %d WHERE (o.status = %s OR (o.status = %s AND o.claimed_at < %s)) AND o.attempts < %d AND o.available_at <= %s LIMIT 1',
					$table,
					wptsall_table( 'site_relations' ),
					'active',
					(int) get_current_blog_id(),
					'pending',
					'processing',
					$cutoff,
					$max_attempts,
					$now
				)
			);
		}
		return null !== $found;
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
		if ( (int) $id <= 0 || ( '' !== $claim_owner_hash && ! preg_match( '/^[a-f0-9]{64}$/', $claim_owner_hash ) ) ) {
			return false;
		}
		// Callers finish their application transaction before this boundary.
		// Closing the lifecycle row and its task projection is one unit; a
		// storage error must leave both replayable, never a runnable orphan task.
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return false;
		}
		$committed = false;
		try {
			$row = $wpdb->get_row(
				$wpdb->prepare( 'SELECT payload, status, relation_id FROM %i WHERE id = %d FOR UPDATE', $outbox_table, (int) $id ),
				ARRAY_A
			);
			if ( ! is_array( $row ) || '' !== $wpdb->last_error || ! in_array( $row['status'], array( 'processing', 'completed' ), true ) ) {
				return false;
			}
			$payload = json_decode( (string) $row['payload'], true );
			if ( ! is_array( $payload ) || ( '' !== $claim_owner_hash && ! hash_equals( $claim_owner_hash, (string) ( $payload['_wptsall_claim_owner_hash'] ?? '' ) ) ) ) {
				return false;
			}
			$task_id = absint( $payload['task_id'] ?? 0 );
			if ( $task_id > 0 ) {
				$task = $wpdb->get_row(
					$wpdb->prepare( 'SELECT relation_id, status FROM %i WHERE id = %d FOR UPDATE', wptsall_table( 'tasks' ), $task_id ),
					ARRAY_A
				);
				if ( ! is_array( $task ) || '' !== $wpdb->last_error || (int) $task['relation_id'] !== (int) $row['relation_id'] ) {
					return false;
				}
				if ( in_array( $task['status'], array( 'pending', 'retry', 'processing', 'active' ), true ) ) {
					$written = $wpdb->query(
						$wpdb->prepare(
							'UPDATE %i SET status = %s, status_note = %s, updated_at = %s WHERE id = %d AND relation_id = %d AND status IN (%s, %s, %s, %s)',
							wptsall_table( 'tasks' ), 'completed', 'Completed through durable content outbox', $now, $task_id, (int) $row['relation_id'],
							'pending', 'retry', 'processing', 'active'
						)
					);
					if ( 1 !== $written ) {
						return false;
					}
				} elseif ( ! in_array( $task['status'], array( 'completed', 'partial', 'cancelled', 'failed' ), true ) ) {
					return false;
				}
			}
			if ( 'processing' === $row['status'] ) {
				$written = $wpdb->query(
					$wpdb->prepare(
						'UPDATE %i SET status = %s, completed_at = %s, claimed_at = NULL, updated_at = %s WHERE id = %d AND status = %s',
						$outbox_table, 'completed', $now, $now, (int) $id, 'processing'
					)
				);
				if ( 1 !== $written ) {
					return false;
				}
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				return false;
			}
			$committed = true;
			return true;
		} finally {
			if ( ! $committed ) {
				$wpdb->query( 'ROLLBACK' );
			}
		}
	}

	/**
	 * Return a leased row to the queue after a failed attempt.
	 *
	 * Below the attempt cap the row goes back to `pending` behind a backoff:
	 * the caller's explicit hint when it gives one (the ack endpoint always
	 * does, `0` meaning retry now), otherwise the server schedule
	 * (retry_backoff_seconds) so an internal failure cannot be re-leased in a
	 * tight loop. At the cap the row is retired to the terminal `dead` state
	 * (ATS-01) and its task projection is failed.
	 *
	 * @param int      $id               Outbox row ID.
	 * @param string   $error            Error detail.
	 * @param string   $claim_owner_hash Lease owner digest (optional).
	 * @param int|null $backoff_secs     Explicit backoff hint; null = server schedule.
	 * @return bool True when this call closed the caller's lease.
	 */
	public static function fail_outbox( $id, $error = '', $claim_owner_hash = '', $backoff_secs = null ) {
		return self::release_outbox_lease( $id, $error, $claim_owner_hash, false, $backoff_secs );
	}

	/**
	 * Give up on a leased row for a permanent reason (a source that does not
	 * exist, an event type no client can process): terminal `dead` at once
	 * instead of burning the remaining attempts. Owner-bound like fail_outbox.
	 *
	 * @since 2.3.0
	 * @param int    $id               Outbox row ID.
	 * @param string $error            Why the row can never succeed.
	 * @param string $claim_owner_hash Lease owner digest (optional).
	 * @return bool True when this call closed the caller's lease.
	 */
	public static function abandon_outbox( $id, $error = '', $claim_owner_hash = '' ) {
		return self::release_outbox_lease( $id, $error, $claim_owner_hash, true, null );
	}

	/**
	 * Shared close of a failed lease (fail_outbox / abandon_outbox).
	 *
	 * The attempts value read here rides in the compare-and-set: if the lease
	 * expired and another worker re-leased the row in between, this call no
	 * longer owns it and changes nothing.
	 *
	 * @param int      $id               Outbox row ID.
	 * @param string   $error            Error detail.
	 * @param string   $claim_owner_hash Lease owner digest (optional).
	 * @param bool     $permanent        Retire at once regardless of attempts.
	 * @param int|null $backoff_secs     Explicit backoff hint; null = server schedule.
	 * @return bool
	 */
	private static function release_outbox_lease( $id, $error, $claim_owner_hash, $permanent, $backoff_secs ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$claim_owner_hash = strtolower( trim( (string) $claim_owner_hash ) );
		if ( '' !== $claim_owner_hash && ! preg_match( '/^[a-f0-9]{64}$/', $claim_owner_hash ) ) {
			return false;
		}
		$outbox_table = wptsall_table( 'content_change_outbox' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- lease-state read; caching is not applicable.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT payload, attempts FROM %i WHERE id = %d AND status = %s', $outbox_table, (int) $id, 'processing' ),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return false;
		}
		$attempts = (int) $row['attempts'];
		$error    = sanitize_text_field( (string) $error );
		$dead     = $permanent || $attempts >= self::max_attempts();

		$where = 'id = %d AND status = %s AND attempts = %d';
		$args  = array( (int) $id, 'processing', $attempts );
		if ( '' !== $claim_owner_hash ) {
			$where .= " AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$._wptsall_claim_owner_hash')) = %s";
			$args[] = $claim_owner_hash;
		}
		if ( $dead ) {
			$sql  = "UPDATE %i SET status = %s, claimed_at = NULL, last_error = %s, updated_at = %s WHERE {$where}";
			$args = array_merge( array( $outbox_table, 'dead', $error, $now ), $args );
		} else {
			// Client backoff hint (P8): pushing available_at into the future makes
			// the cooldown durable for every worker, not only the process that
			// reported the failure. Capped at 30 minutes either way.
			$delay        = null === $backoff_secs
				? self::retry_backoff_seconds( $attempts )
				: min( self::RETRY_BACKOFF_CAP_SECONDS, max( 0, (int) $backoff_secs ) );
			$available_at = $delay > 0 ? gmdate( 'Y-m-d H:i:s', time() + $delay ) : $now;
			$sql          = "UPDATE %i SET status = %s, claimed_at = NULL, available_at = %s, last_error = %s, updated_at = %s WHERE {$where}";
			$args         = array_merge( array( $outbox_table, 'pending', $available_at, $error, $now ), $args );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- lease failure CAS; the fragments are fixed literals and every value is a prepared argument.
		$changed = 1 === (int) $wpdb->query( $wpdb->prepare( $sql, $args ) );
		if ( ! $changed ) {
			return false;
		}
		if ( $dead ) {
			self::project_task_failed(
				$row['payload'] ?? '',
				( $permanent ? 'Content outbox abandoned: ' : "Content outbox retired after {$attempts} attempts: " ) . $error,
				$now
			);
			self::log_outbox_dead( (int) $id, $error, $attempts, $permanent ? 'permanent_error' : 'attempts_exhausted' );
			return true;
		}
		$task_id = self::outbox_payload_task_id( $row['payload'] ?? '' );
		if ( $task_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- task projection write; caching is not applicable.
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET status = %s, status_note = %s, retry_at = %s, updated_at = %s WHERE id = %d AND status IN (%s, %s, %s, %s)',
					wptsall_table( 'tasks' ),
					'retry',
					'Content outbox callback failed: ' . $error,
					$available_at,
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

	/**
	 * Retire rows that used up their attempts: `pending` rows over the cap (the
	 * cap was lowered, or the row predates it) and rows whose lease expired at
	 * the cap - a client that crashes on the row never calls fail_outbox, so
	 * lease expiry is the only signal a poison pill ever gives.
	 *
	 * Runs at the start of every claim, scoped like the claim itself.
	 *
	 * @param int    $relation_id Relation scope (0 = every relation).
	 * @param string $cutoff      Lease-expiry cutoff (GMT mysql datetime).
	 * @param string $now         Current time (GMT mysql datetime).
	 * @return void
	 */
	private static function retire_exhausted_outbox_rows( $relation_id, $cutoff, $now ) {
		global $wpdb;
		$table        = wptsall_table( 'content_change_outbox' );
		$max_attempts = self::max_attempts();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- exhausted-row scan; caching is not applicable.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, attempts, payload FROM %i WHERE attempts >= %d AND (status = %s OR (status = %s AND claimed_at < %s)) AND (%d = 0 OR relation_id = %d) ORDER BY id ASC LIMIT 200',
				$table,
				$max_attempts,
				'pending',
				'processing',
				$cutoff,
				(int) $relation_id,
				(int) $relation_id
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- retirement CAS; caching is not applicable.
			$retired = 1 === (int) $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET status = %s, claimed_at = NULL, last_error = IF(last_error IS NULL OR last_error = %s, %s, last_error), updated_at = %s WHERE id = %d AND attempts >= %d AND (status = %s OR (status = %s AND claimed_at < %s))',
					$table,
					'dead',
					'',
					'max_attempts_exceeded',
					$now,
					(int) $row['id'],
					$max_attempts,
					'pending',
					'processing',
					$cutoff
				)
			);
			if ( $retired ) {
				self::project_task_failed( $row['payload'] ?? '', 'Content outbox retired: max_attempts_exceeded', $now );
				self::log_outbox_dead( (int) $row['id'], 'max_attempts_exceeded', (int) $row['attempts'], 'attempts_exhausted' );
			}
		}
	}

	/**
	 * @param string $payload_json Outbox payload JSON.
	 * @return int Task id recorded by the content-changes endpoint, 0 when none.
	 */
	private static function outbox_payload_task_id( $payload_json ) {
		$payload = json_decode( (string) $payload_json, true );
		return absint( is_array( $payload ) ? ( $payload['task_id'] ?? 0 ) : 0 );
	}

	/**
	 * Fail the task that projects a dead outbox row, so an older task-pull
	 * worker cannot run it again and both views end in the same state.
	 *
	 * @param string $payload_json Outbox payload JSON.
	 * @param string $note         Task status note.
	 * @param string $now          Current time (GMT mysql datetime).
	 * @return void
	 */
	private static function project_task_failed( $payload_json, $note, $now ) {
		global $wpdb;
		$task_id = self::outbox_payload_task_id( $payload_json );
		if ( $task_id <= 0 ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- task projection write; caching is not applicable.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, status_note = %s, updated_at = %s WHERE id = %d AND status IN (%s, %s, %s, %s)',
				wptsall_table( 'tasks' ),
				'failed',
				$note,
				$now,
				$task_id,
				'pending',
				'retry',
				'processing',
				'active'
			)
		);
	}

	/**
	 * A dead row is the one place the queue drops work, so say so in the log.
	 *
	 * @param int    $id       Outbox row id.
	 * @param string $error    Last error.
	 * @param int    $attempts Leases used.
	 * @param string $reason   permanent_error | attempts_exhausted.
	 * @return void
	 */
	private static function log_outbox_dead( $id, $error, $attempts, $reason ) {
		if ( function_exists( 'wptsall_log_warning' ) ) {
			wptsall_log_warning(
				'hooks',
				'Content outbox row retired to dead letter',
				array(
					'outbox_id' => $id,
					'reason'    => $reason,
					'attempts'  => $attempts,
					'error'     => $error,
				)
			);
		}
	}
}

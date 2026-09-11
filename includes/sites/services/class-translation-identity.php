<?php
/**
 * Translation Identity — single contract for source↔target post identity.
 *
 * Admin flags historically read post_mappings; the editor/sync path read
 * `_wptsall_*` meta. This service dual-writes markers + mappings and resolves
 * with mapping-first, meta fallback (+ optional heal).
 *
 * @package WPTSALL\Sites\Services
 * @since 2.3.0
 */

namespace WPTSALL\Sites\Services;

use WPTSALL\Models\Services\Post_Mapping_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translation_Identity class.
 */
class Translation_Identity {

	/** Identity meta keys dual-written onto target/shadow posts. */
	public const META_VIRTUAL_SITE_ID = '_wptsall_virtual_site_id';
	public const META_SOURCE_POST_ID  = '_wptsall_source_post_id';
	public const META_SOURCE_BLOG_ID  = '_wptsall_source_blog_id';
	public const META_RELATION_ID     = '_wptsall_relation_id';

	/**
	 * Read an identity meta value bypassing get_post_meta filters.
	 *
	 * GiveWP and similar content plugins can hide or virtualize `_wptsall_*`
	 * keys through the WP meta API. Competitors store language identity outside
	 * hostile CPT meta (WPML icl_translations / Polylang taxonomy); our dual-write
	 * model therefore requires Direct-DB / raw SQL reads for these keys.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 * @return mixed Unserialized value or '' when missing.
	 */
	public static function raw_meta( int $post_id, string $meta_key ) {
		if ( $post_id <= 0 || '' === $meta_key ) {
			return '';
		}
		if ( class_exists( '\\WPTSALL\\Hooks\\Virtual_Site_Query_Switch' ) ) {
			return \WPTSALL\Hooks\Virtual_Site_Query_Switch::raw_post_meta( $post_id, $meta_key );
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$raw = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT meta_value FROM %i WHERE post_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1',
				$wpdb->postmeta,
				$post_id,
				$meta_key
			)
		);
		if ( null === $raw ) {
			return '';
		}
		return maybe_unserialize( $raw );
	}

	/**
	 * Whether a post is a virtual-site shadow (translated copy), not the source.
	 *
	 * Generic rule: both virtual_site_id and source_post_id markers present.
	 * Uses {@see raw_meta()} so hostile CPT meta filters cannot hide shadows
	 * from sitemap exclusion / ownership / permalink resolution.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_shadow_post( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}
		$vs  = (string) self::raw_meta( $post_id, self::META_VIRTUAL_SITE_ID );
		$src = (int) self::raw_meta( $post_id, self::META_SOURCE_POST_ID );
		return '' !== $vs && $src > 0;
	}

	/**
	 * Whether a post carries any translation identity marker (shadow or mapped target).
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function has_identity_markers( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}
		if ( '' !== (string) self::raw_meta( $post_id, self::META_VIRTUAL_SITE_ID ) ) {
			return true;
		}
		return (int) self::raw_meta( $post_id, self::META_SOURCE_POST_ID ) > 0;
	}

	/**
	 * Whether a meta key is a translation-identity marker.
	 *
	 * @param string $meta_key Meta key.
	 * @return bool
	 */
	public static function is_identity_meta_key( string $meta_key ): bool {
		return in_array(
			$meta_key,
			array(
				self::META_VIRTUAL_SITE_ID,
				self::META_SOURCE_POST_ID,
				self::META_SOURCE_BLOG_ID,
				self::META_RELATION_ID,
			),
			true
		);
	}

	/**
	 * Write an identity meta value bypassing update_post_meta filters.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @return bool
	 */
	public static function write_meta( int $post_id, string $meta_key, $meta_value ): bool {
		if ( $post_id <= 0 || '' === $meta_key ) {
			return false;
		}
		if ( class_exists( '\\WPTSALL\\Tasks\\Services\\Direct_DB_Service' ) ) {
			return (bool) \WPTSALL\Tasks\Services\Direct_DB_Service::update_post_meta( $post_id, $meta_key, $meta_value );
		}
		return (bool) update_post_meta( $post_id, $meta_key, $meta_value );
	}

	/**
	 * Delete an identity meta key via Direct DB (hostile CPT filters may block delete_post_meta).
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 * @return void
	 */
	public static function delete_meta( int $post_id, string $meta_key ): void {
		if ( $post_id <= 0 || '' === $meta_key ) {
			return;
		}
		if ( class_exists( '\\WPTSALL\\Tasks\\Services\\Direct_DB_Service' ) ) {
			\WPTSALL\Tasks\Services\Direct_DB_Service::delete_post_meta( $post_id, $meta_key );
			return;
		}
		delete_post_meta( $post_id, $meta_key );
	}

	/**
	 * Find the target post for a source post under a site relation.
	 *
	 * @param int  $source_post_id Source post ID.
	 * @param int  $relation_id    Site relation ID.
	 * @param bool $heal           When meta hits but mapping misses, upsert both.
	 * @return int|null Target post ID or null.
	 */
	public static function find_target( int $source_post_id, int $relation_id, bool $heal = true ): ?int {
		if ( $source_post_id <= 0 || $relation_id <= 0 ) {
			return null;
		}

		$mapped = null;
		if ( class_exists( Post_Mapping_Service::class ) ) {
			$mapped = Post_Mapping_Service::get_mapped_id( $relation_id, $source_post_id );
		}
		if ( $mapped && (int) $mapped > 0 ) {
			return (int) $mapped;
		}

		$meta_id = self::find_target_via_meta( $source_post_id, $relation_id );
		if ( ! $meta_id ) {
			return null;
		}

		if ( $heal ) {
			self::ensure_markers( $meta_id, $source_post_id, $relation_id );
		}

		return $meta_id;
	}

	/**
	 * Translations of a post keyed by target language code.
	 *
	 * Prefer post_mappings JOIN site_relations (admin chrome contract).
	 * Pseudo-key `_current_lang` is the post's own language when it is a target,
	 * otherwise the site default language.
	 *
	 * @param int $post_id Source or target post ID.
	 * @return array<string,array|string> lang => row; `_current_lang` => string.
	 */
	public static function get_translations( int $post_id ): array {
		global $wpdb;

		$out = array();
		if ( $post_id <= 0 ) {
			return $out;
		}

		$table     = wptsall_table( 'post_mappings' );
		$relations = wptsall_table( 'site_relations' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT r.target_lang, r.id AS relation_id, m.target_post_id, m.source_post_id, m.target_site_id
				 FROM %i m
				 LEFT JOIN %i r ON r.id = m.relation_id
				 WHERE ( m.source_post_id = %d OR m.target_post_id = %d )
				   AND m.target_post_id > 0',
				$table,
				$relations,
				$post_id,
				$post_id
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $r ) {
			$code = (string) ( $r['target_lang'] ?? '' );
			if ( '' === $code ) {
				continue;
			}
			$row = array(
				'target_lang'     => $code,
				'target_post_id'  => (int) $r['target_post_id'],
				'source_post_id'  => (int) $r['source_post_id'],
				'relation_id'     => (int) ( $r['relation_id'] ?? 0 ),
				'target_site_id'  => (string) ( $r['target_site_id'] ?? '' ),
			);
			if ( (int) $r['source_post_id'] === $post_id ) {
				$out[ $code ] = $row;
			}
			if ( (int) $r['target_post_id'] === $post_id ) {
				$out['_current_lang'] = $code;
			}
		}

		if ( ! isset( $out['_current_lang'] ) ) {
			$default = class_exists( '\\WPTSALL\\Languages\\Services\\Language_Service' )
				? \WPTSALL\Languages\Services\Language_Service::get_default()
				: null;
			$out['_current_lang'] = $default ? (string) $default['code'] : 'zh_CN';
		}

		return $out;
	}

	/**
	 * Language → target_post_id map (plus `_current_lang`) for flag UIs.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,int|string>
	 */
	public static function get_translation_ids_by_lang( int $post_id ): array {
		$full = self::get_translations( $post_id );
		$out  = array(
			'_current_lang' => (string) ( $full['_current_lang'] ?? '' ),
		);
		foreach ( $full as $code => $row ) {
			if ( '_current_lang' === $code || ! is_array( $row ) ) {
				continue;
			}
			$out[ $code ] = (int) ( $row['target_post_id'] ?? 0 );
		}
		return $out;
	}

	/**
	 * Persist meta markers and upsert post_mappings (dual-write).
	 *
	 * @param int   $target_id      Target (shadow) post ID in the blog where it lives.
	 * @param int   $source_post_id Source post ID.
	 * @param int   $relation_id    Site relation ID.
	 * @param array $opts {
	 *     Optional. Extra context when relation row is not loaded yet.
	 *
	 *     @type string|null $virtual_site_id Virtual site id for virtual targets.
	 *     @type int|null    $source_blog_id  Source blog id.
	 *     @type string|null $post_type       Post type for mapping row.
	 *     @type array|null  $relation        Full relation row.
	 * }
	 * @return bool True when mapping upsert attempted successfully (or skipped cleanly).
	 */
	public static function ensure_markers( int $target_id, int $source_post_id, int $relation_id, array $opts = array() ): bool {
		if ( $target_id <= 0 || $source_post_id <= 0 || $relation_id <= 0 ) {
			return false;
		}

		$relation = isset( $opts['relation'] ) && is_array( $opts['relation'] )
			? $opts['relation']
			: Site_Relation_Service::get_relation( $relation_id );
		if ( ! $relation ) {
			return false;
		}

		$source_blog_id = isset( $opts['source_blog_id'] )
			? (int) $opts['source_blog_id']
			: (int) ( $relation['source_site_id'] ?? get_current_blog_id() );

		$virtual_site_id = array_key_exists( 'virtual_site_id', $opts )
			? $opts['virtual_site_id']
			: ( ( ( $relation['target_site_type'] ?? '' ) === 'virtual' ) ? (string) ( $relation['target_site_id'] ?? '' ) : null );

		self::persist_meta_markers( $target_id, $source_post_id, $source_blog_id, $relation_id, $virtual_site_id );

		if ( ! class_exists( Post_Mapping_Service::class ) ) {
			return true;
		}

		$post_type = (string) ( $opts['post_type'] ?? '' );
		if ( '' === $post_type ) {
			$source = get_post( $source_post_id );
			$post_type = $source ? (string) $source->post_type : '';
		}
		if ( '' === $post_type ) {
			$target = get_post( $target_id );
			$post_type = $target ? (string) $target->post_type : 'post';
		}

		$mapping_id = Post_Mapping_Service::create_mapping(
			array(
				'source_post_id'    => $source_post_id,
				'source_post_type'  => $post_type,
				'source_site_id'    => $source_blog_id,
				'relation_id'       => $relation_id,
				'target_post_id'    => $target_id,
				'target_post_type'  => $post_type,
				'target_site_id'    => (string) ( $relation['target_site_id'] ?? '' ),
				'relationship_type' => 'translation',
			)
		);

		return false !== $mapping_id;
	}

	/**
	 * Meta-only lookup (legacy editor/sync pattern).
	 *
	 * @param int $source_post_id Source post ID.
	 * @param int $relation_id    Relation ID.
	 * @return int|null
	 */
	private static function find_target_via_meta( int $source_post_id, int $relation_id ): ?int {
		$relation = Site_Relation_Service::get_relation( $relation_id );
		if ( ! $relation ) {
			return null;
		}

		$target_site_type = $relation['target_site_type'] ?? 'wp';
		if ( 'virtual' === $target_site_type ) {
			return self::find_virtual_meta( $source_post_id, (string) $relation['target_site_id'], $relation_id );
		}

		return self::find_wp_meta( $source_post_id, (int) $relation['target_site_id'], $relation_id );
	}

	/**
	 * @param int    $source_post_id  Source.
	 * @param string $virtual_site_id VS id.
	 * @param int    $relation_id     Relation.
	 * @return int|null
	 */
	private static function find_virtual_meta( int $source_post_id, string $virtual_site_id, int $relation_id ): ?int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID FROM %i p
				INNER JOIN %i pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_wptsall_virtual_site_id'
				INNER JOIN %i pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_wptsall_source_post_id'
				INNER JOIN %i pm3 ON p.ID = pm3.post_id AND pm3.meta_key = '_wptsall_relation_id'
				WHERE pm1.meta_value = %s AND pm2.meta_value = %d AND pm3.meta_value = %d
				LIMIT 1",
				$wpdb->posts,
				$wpdb->postmeta,
				$wpdb->postmeta,
				$wpdb->postmeta,
				$virtual_site_id,
				$source_post_id,
				$relation_id
			)
		);

		if ( $existing_id ) {
			return (int) $existing_id;
		}

		// Soft fallback: older writers omitted relation_id meta.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID FROM %i p
				INNER JOIN %i pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_wptsall_virtual_site_id'
				INNER JOIN %i pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_wptsall_source_post_id'
				WHERE pm1.meta_value = %s AND pm2.meta_value = %d
				LIMIT 1",
				$wpdb->posts,
				$wpdb->postmeta,
				$wpdb->postmeta,
				$virtual_site_id,
				$source_post_id
			)
		);

		return $existing_id ? (int) $existing_id : null;
	}

	/**
	 * @param int $source_post_id Source.
	 * @param int $target_blog_id Target blog.
	 * @param int $relation_id    Relation.
	 * @return int|null
	 */
	private static function find_wp_meta( int $source_post_id, int $target_blog_id, int $relation_id ): ?int {
		global $wpdb;

		$source_blog_id = get_current_blog_id();
		$switched       = false;
		if ( is_multisite() && $target_blog_id > 0 && $target_blog_id !== $source_blog_id ) {
			switch_to_blog( $target_blog_id );
			$switched = true;
		}

		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$existing_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT p.ID FROM %i p
					INNER JOIN %i pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_wptsall_source_post_id'
					INNER JOIN %i pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_wptsall_relation_id'
					WHERE pm1.meta_value = %d AND pm2.meta_value = %d
					LIMIT 1",
					$wpdb->posts,
					$wpdb->postmeta,
					$wpdb->postmeta,
					$source_post_id,
					$relation_id
				)
			);
			if ( $existing_id ) {
				return (int) $existing_id;
			}

			// Soft fallback without relation meta (legacy Sync WP path).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$existing_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT p.ID FROM %i p
					INNER JOIN %i pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_wptsall_source_post_id'
					INNER JOIN %i pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_wptsall_source_blog_id'
					WHERE pm1.meta_value = %d AND pm2.meta_value = %d
					LIMIT 1",
					$wpdb->posts,
					$wpdb->postmeta,
					$wpdb->postmeta,
					$source_post_id,
					$source_blog_id
				)
			);

			return $existing_id ? (int) $existing_id : null;
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Write `_wptsall_*` markers on the target post (current blog context).
	 *
	 * @param int         $target_id       Target ID.
	 * @param int         $source_post_id  Source ID.
	 * @param int         $source_blog_id  Source blog.
	 * @param int         $relation_id     Relation.
	 * @param string|null $virtual_site_id Optional VS id.
	 * @return void
	 */
	private static function persist_meta_markers( int $target_id, int $source_post_id, int $source_blog_id, int $relation_id, $virtual_site_id ): void {
		if ( null !== $virtual_site_id && '' !== (string) $virtual_site_id ) {
			self::write_meta( $target_id, self::META_VIRTUAL_SITE_ID, (string) $virtual_site_id );
		}
		self::write_meta( $target_id, self::META_SOURCE_POST_ID, $source_post_id );
		self::write_meta( $target_id, self::META_SOURCE_BLOG_ID, $source_blog_id );
		self::write_meta( $target_id, self::META_RELATION_ID, $relation_id );
	}

	/**
	 * Scan mapping rows whose target posts lack relation-scoped markers.
	 *
	 * @param array $args {
	 *     @type int  $limit       Max rows to inspect. Default 200.
	 *     @type int  $offset      Offset into post_mappings. Default 0.
	 *     @type int  $relation_id Optional filter.
	 * }
	 * @return array{scanned:int,diverged:array,ok:int,missing_target:int}
	 */
	public static function scan_divergences( array $args = array() ): array {
		global $wpdb;

		$limit       = max( 1, min( 2000, (int) ( $args['limit'] ?? 200 ) ) );
		$offset      = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$relation_id = (int) ( $args['relation_id'] ?? 0 );

		$table     = wptsall_table( 'post_mappings' );
		$relations = wptsall_table( 'site_relations' );

		if ( $relation_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT m.id AS mapping_id, m.source_post_id, m.target_post_id, m.relation_id,
					        m.target_site_id, m.source_site_id, m.source_post_type,
					        r.target_site_type, r.target_lang
					 FROM %i m
					 LEFT JOIN %i r ON r.id = m.relation_id
					 WHERE m.target_post_id > 0 AND m.relation_id = %d
					 ORDER BY m.id ASC
					 LIMIT %d OFFSET %d",
					$table,
					$relations,
					$relation_id,
					$limit,
					$offset
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT m.id AS mapping_id, m.source_post_id, m.target_post_id, m.relation_id,
					        m.target_site_id, m.source_site_id, m.source_post_type,
					        r.target_site_type, r.target_lang
					 FROM %i m
					 LEFT JOIN %i r ON r.id = m.relation_id
					 WHERE m.target_post_id > 0 AND m.relation_id > 0
					 ORDER BY m.id ASC
					 LIMIT %d OFFSET %d",
					$table,
					$relations,
					$limit,
					$offset
				),
				ARRAY_A
			);
		}

		$out = array(
			'scanned'          => 0,
			'ok'               => 0,
			'missing_target'   => 0,
			'skipped_conflict' => 0,
			'diverged'         => array(),
		);

		foreach ( (array) $rows as $row ) {
			++$out['scanned'];
			$diagnosis = self::diagnose_mapping_row( $row );
			if ( 'ok' === $diagnosis['status'] ) {
				++$out['ok'];
				continue;
			}
			if ( 'missing_target' === $diagnosis['status'] ) {
				++$out['missing_target'];
				continue;
			}
			if ( 'skipped_conflict' === $diagnosis['status'] ) {
				++$out['skipped_conflict'];
				continue;
			}
			$out['diverged'][] = $diagnosis;
		}

		return $out;
	}

	/**
	 * Heal diverged mapping→meta pairs (and re-upsert mapping).
	 *
	 * @param array $args {
	 *     @type bool $dry_run     When true, only report. Default true.
	 *     @type int  $limit       Max rows to inspect. Default 200.
	 *     @type int  $offset      Offset. Default 0.
	 *     @type int  $relation_id Optional filter.
	 *     @type int  $sample_max  Max sample rows in report. Default 25.
	 * }
	 * @return array Report counters + samples.
	 */
	public static function heal_batch( array $args = array() ): array {
		$dry_run    = ! isset( $args['dry_run'] ) || (bool) $args['dry_run'];
		$sample_max = max( 0, min( 100, (int) ( $args['sample_max'] ?? 25 ) ) );

		$scan = self::scan_divergences( $args );
		$report = array(
			'scanned'          => (int) $scan['scanned'],
			'ok'               => (int) $scan['ok'],
			'missing_target'   => (int) $scan['missing_target'],
			'skipped_conflict' => (int) ( $scan['skipped_conflict'] ?? 0 ),
			'diverged'         => count( $scan['diverged'] ),
			'healed'           => 0,
			'failed'           => 0,
			'dry_run'          => $dry_run,
			'samples'          => array(),
			'errors'           => array(),
		);

		foreach ( $scan['diverged'] as $item ) {
			if ( count( $report['samples'] ) < $sample_max ) {
				$report['samples'][] = $item;
			}
			if ( $dry_run ) {
				continue;
			}

			$ok = self::heal_one_mapping( $item );
			if ( $ok ) {
				++$report['healed'];
			} else {
				++$report['failed'];
				$report['errors'][] = array(
					'mapping_id'     => (int) ( $item['mapping_id'] ?? 0 ),
					'target_post_id' => (int) ( $item['target_post_id'] ?? 0 ),
					'relation_id'    => (int) ( $item['relation_id'] ?? 0 ),
					'reason'         => (string) ( $item['reason'] ?? 'heal_failed' ),
				);
			}
		}

		return $report;
	}

	/**
	 * Diagnose one mapping row against target post meta.
	 *
	 * @param array $row Mapping + relation join row.
	 * @return array
	 */
	private static function diagnose_mapping_row( array $row ): array {
		$target_id   = (int) ( $row['target_post_id'] ?? 0 );
		$source_id   = (int) ( $row['source_post_id'] ?? 0 );
		$relation_id = (int) ( $row['relation_id'] ?? 0 );
		$site_type   = (string) ( $row['target_site_type'] ?? 'virtual' );
		$base        = array(
			'mapping_id'      => (int) ( $row['mapping_id'] ?? 0 ),
			'source_post_id'  => $source_id,
			'target_post_id'  => $target_id,
			'relation_id'     => $relation_id,
			'target_site_id'  => (string) ( $row['target_site_id'] ?? '' ),
			'target_site_type'=> $site_type,
			'target_lang'     => (string) ( $row['target_lang'] ?? '' ),
			'source_post_type'=> (string) ( $row['source_post_type'] ?? '' ),
			'source_site_id'  => (int) ( $row['source_site_id'] ?? get_current_blog_id() ),
		);

		if ( $target_id <= 0 || $relation_id <= 0 ) {
			return array_merge( $base, array( 'status' => 'missing_target', 'reason' => 'invalid_ids' ) );
		}

		$switched = false;
		$blog_id  = get_current_blog_id();
		if ( 'wp' === $site_type && is_multisite() ) {
			$target_blog = (int) ( $row['target_site_id'] ?? 0 );
			if ( $target_blog > 0 && $target_blog !== $blog_id ) {
				switch_to_blog( $target_blog );
				$switched = true;
			}
		}

		try {
			$post = get_post( $target_id );
			if ( ! $post ) {
				return array_merge( $base, array( 'status' => 'missing_target', 'reason' => 'post_not_found' ) );
			}

			$meta_source   = (int) self::raw_meta( $target_id, self::META_SOURCE_POST_ID );
			$meta_relation = (int) self::raw_meta( $target_id, self::META_RELATION_ID );
			$meta_vs       = (string) self::raw_meta( $target_id, self::META_VIRTUAL_SITE_ID );
			$meta_blog     = (int) self::raw_meta( $target_id, self::META_SOURCE_BLOG_ID );

			// Self-translation rows often share a target with a real virtual/wp mapping.
			// Meta can only store one relation_id — prefer the non-self owner.
			if ( in_array( $site_type, array( 'self', 'self_translation' ), true ) ) {
				$other = self::find_non_self_mapping_for_target( $target_id, $relation_id );
				if ( $other ) {
					return array_merge(
						$base,
						array(
							'status' => 'skipped_conflict',
							'reason' => 'self_mapping_shares_target_with_relation_' . (int) ( $other['relation_id'] ?? 0 ),
						)
					);
				}
			}

			$needs = array();
			if ( $meta_source !== $source_id ) {
				$needs[] = 'source_post_id';
			}
			if ( $meta_relation !== $relation_id ) {
				$needs[] = 'relation_id';
			}
			if ( 'virtual' === $site_type ) {
				$expected_vs = (string) ( $row['target_site_id'] ?? '' );
				if ( '' !== $expected_vs && $meta_vs !== $expected_vs ) {
					// Accept v_ / bare id equivalence.
					$norm_meta = ( 0 === strpos( $meta_vs, 'v_' ) ) ? substr( $meta_vs, 2 ) : $meta_vs;
					$norm_exp  = ( 0 === strpos( $expected_vs, 'v_' ) ) ? substr( $expected_vs, 2 ) : $expected_vs;
					if ( (string) $norm_meta !== (string) $norm_exp ) {
						$needs[] = 'virtual_site_id';
					}
				}
			}
			$expected_blog = (int) ( $row['source_site_id'] ?? get_current_blog_id() );
			if ( $meta_blog > 0 && $meta_blog !== $expected_blog ) {
				$needs[] = 'source_blog_id';
			} elseif ( $meta_blog <= 0 ) {
				$needs[] = 'source_blog_id';
			}

			if ( empty( $needs ) ) {
				return array_merge( $base, array( 'status' => 'ok', 'reason' => '' ) );
			}

			return array_merge(
				$base,
				array(
					'status' => 'diverged',
					'reason' => 'missing_or_mismatch:' . implode( ',', array_unique( $needs ) ),
					'needs'  => array_values( array_unique( $needs ) ),
				)
			);
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Find a non-self mapping that also points at this target post.
	 *
	 * @param int $target_post_id Target post.
	 * @param int $except_relation_id Relation to ignore.
	 * @return array|null
	 */
	private static function find_non_self_mapping_for_target( int $target_post_id, int $except_relation_id ): ?array {
		global $wpdb;
		$table     = wptsall_table( 'post_mappings' );
		$relations = wptsall_table( 'site_relations' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT m.*, r.target_site_type
				 FROM %i m
				 LEFT JOIN %i r ON r.id = m.relation_id
				 WHERE m.target_post_id = %d
				   AND m.relation_id <> %d
				   AND ( r.target_site_type IS NULL OR r.target_site_type NOT IN ('self','self_translation') )
				 ORDER BY m.id DESC
				 LIMIT 1",
				$table,
				$relations,
				$target_post_id,
				$except_relation_id
			),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Apply ensure_markers for one diverged diagnosis row.
	 *
	 * @param array $item Diagnosis row.
	 * @return bool
	 */
	private static function heal_one_mapping( array $item ): bool {
		$target_id   = (int) ( $item['target_post_id'] ?? 0 );
		$source_id   = (int) ( $item['source_post_id'] ?? 0 );
		$relation_id = (int) ( $item['relation_id'] ?? 0 );
		$site_type   = (string) ( $item['target_site_type'] ?? 'virtual' );
		if ( $target_id <= 0 || $source_id <= 0 || $relation_id <= 0 ) {
			return false;
		}

		$switched = false;
		if ( 'wp' === $site_type && is_multisite() ) {
			$target_blog = (int) ( $item['target_site_id'] ?? 0 );
			if ( $target_blog > 0 && $target_blog !== get_current_blog_id() ) {
				switch_to_blog( $target_blog );
				$switched = true;
			}
		}

		try {
			$virtual_site_id = ( 'virtual' === $site_type ) ? (string) ( $item['target_site_id'] ?? '' ) : null;
			return self::ensure_markers(
				$target_id,
				$source_id,
				$relation_id,
				array(
					'virtual_site_id' => $virtual_site_id,
					'source_blog_id'  => (int) ( $item['source_site_id'] ?? get_current_blog_id() ),
					'post_type'       => (string) ( $item['source_post_type'] ?? '' ),
				)
			);
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}
}

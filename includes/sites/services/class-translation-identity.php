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
	 * Per-request warm cache of pre-resolved wp/self meta-leg answers,
	 * "source_post_id:relation_id" => target post ID (or null = resolved miss).
	 *
	 * Render-level prefetch (prefetch_wp_meta_targets(), hooked by
	 * Admin_Virtual_Site_Manager::prefetch_translation_targets() on admin
	 * post-list screens) resolves every list row x every wp/self bucket in
	 * ONE switch + two batched queries per target blog, so the per-row
	 * find_wp_meta_batch() consults this map instead of re-switching per
	 * row — measured 2026-09-27 on the Lab product list: 50 row switches +
	 * 50 restores (100 wp_user_roles reloads) collapse to one switch pair
	 * per target blog. find_wp_meta_batch() itself back-fills this map, so
	 * repeated pairs (unprefetched callers, second render hooks) hit it too.
	 *
	 * @var array<string,int|null>
	 */
	private static $wp_meta_warm = array();

	/**
	 * Prefetch the wp/self meta-leg answers for many source posts at once.
	 *
	 * One switch_to_blog + one primary batch (source IN x relation IN, first
	 * row per pair wins — the per-pair LIMIT 1 semantics) + at most one soft
	 * fallback batch per DISTINCT target blog, exactly the find_wp_meta_batch()
	 * resolution per pair. Warms {@see self::$wp_meta_warm}.
	 *
	 * @param int[] $source_post_ids Source post IDs (the admin list's rows).
	 * @param array $relations       Site-relation rows (same list the
	 *                               per-row path passes to find_targets_batch()).
	 * @return void
	 */
	public static function prefetch_wp_meta_targets( array $source_post_ids, array $relations ): void {
		global $wpdb;

		if ( empty( $source_post_ids ) || empty( $relations ) ) {
			return;
		}

		// Dedupe relations by id, mirroring find_targets_batch().
		$relations_by_id = array();
		foreach ( $relations as $relation ) {
			if ( ! is_array( $relation ) || empty( $relation['id'] ) ) {
				continue;
			}
			$relation_id = (int) $relation['id'];
			if ( $relation_id <= 0 || isset( $relations_by_id[ $relation_id ] ) ) {
				continue;
			}
			$relations_by_id[ $relation_id ] = $relation;
		}
		if ( empty( $relations_by_id ) ) {
			return;
		}

		// Bucket the wp/self (non-virtual) relations by target blog — the
		// find_wp_meta_batch() grouping.
		$groups = array();
		foreach ( $relations_by_id as $relation_id => $relation ) {
			if ( 'virtual' === (string) ( $relation['target_site_type'] ?? 'wp' ) ) {
				continue;
			}
			$target_blog = (int) ( $relation['target_site_id'] ?? 0 );
			$groups[ $target_blog ][ $relation_id ] = $relation;
		}
		if ( empty( $groups ) ) {
			return;
		}

		$sources = array();
		foreach ( $source_post_ids as $source_post_id ) {
			$source_post_id = (int) $source_post_id;
			if ( $source_post_id > 0 && ! in_array( $source_post_id, $sources, true ) ) {
				$sources[] = $source_post_id;
			}
		}
		if ( empty( $sources ) ) {
			return;
		}
		list( $src_in_sql, $src_in_ids ) = wptsall_db_prepare_int_in( $sources );
		if ( empty( $src_in_ids ) ) {
			return;
		}

		$source_blog_id = get_current_blog_id();

		foreach ( $groups as $target_blog => $group ) {
			$group_relation_ids = array_map( 'intval', array_keys( $group ) );
			list( $rel_in_sql, $rel_in_ids ) = wptsall_db_prepare_int_in( $group_relation_ids );
			if ( empty( $rel_in_ids ) ) {
				continue;
			}

			$switched = false;
			if ( is_multisite() && $target_blog > 0 && $target_blog !== $source_blog_id ) {
				switch_to_blog( $target_blog );
				$switched = true;
			}

			try {
				$warm = array();

				// Primary batch: first row per (source, relation) wins.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT p.ID AS target_id, pm1.meta_value AS src, pm2.meta_value AS rel
						FROM %i p
						INNER JOIN %i pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_wptsall_source_post_id'
						INNER JOIN %i pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_wptsall_relation_id'
						WHERE pm1.meta_value IN ($src_in_sql) AND pm2.meta_value IN ($rel_in_sql)",
						$wpdb->posts,
						$wpdb->postmeta,
						$wpdb->postmeta,
						...$src_in_ids,
						...$rel_in_ids
					),
					ARRAY_A
				);
				foreach ( (array) $rows as $row ) {
					$key = (int) $row['src'] . ':' . (int) $row['rel'];
					if ( ! array_key_exists( $key, $warm ) ) {
						$warm[ $key ] = (int) $row['target_id'];
					}
				}

				// Soft fallback batch: first row per source wins; the per-pair
				// soft query carries no relation condition, so the same row
				// answers every pending relation of that source in this bucket.
				$pending_sources = array();
				foreach ( $sources as $source ) {
					foreach ( $group as $relation_id => $relation ) {
						if ( ! array_key_exists( $source . ':' . $relation_id, $warm ) ) {
							$pending_sources[ $source ] = true;
							break;
						}
					}
				}
				if ( ! empty( $pending_sources ) ) {
					$pending_ids = array_keys( $pending_sources );
					list( $psrc_in_sql, $psrc_in_ids ) = wptsall_db_prepare_int_in( $pending_ids );
					if ( ! empty( $psrc_in_ids ) ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
						$soft_rows = $wpdb->get_results(
							$wpdb->prepare(
								"SELECT p.ID AS target_id, pm1.meta_value AS src
								FROM %i p
								INNER JOIN %i pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_wptsall_source_post_id'
								INNER JOIN %i pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_wptsall_source_blog_id'
								WHERE pm2.meta_value = %d AND pm1.meta_value IN ($psrc_in_sql)",
								$wpdb->posts,
								$wpdb->postmeta,
								$wpdb->postmeta,
								$source_blog_id,
								...$psrc_in_ids
							),
							ARRAY_A
						);
						$soft_by_source = array();
						foreach ( (array) $soft_rows as $row ) {
							$src = (int) $row['src'];
							if ( ! array_key_exists( $src, $soft_by_source ) ) {
								$soft_by_source[ $src ] = (int) $row['target_id'];
							}
						}
						foreach ( $pending_sources as $source => $_ ) {
							$soft_target = array_key_exists( $source, $soft_by_source ) ? $soft_by_source[ $source ] : null;
							foreach ( $group as $relation_id => $relation ) {
								$key = $source . ':' . $relation_id;
								if ( ! array_key_exists( $key, $warm ) ) {
									$warm[ $key ] = $soft_target;
								}
							}
						}
					}
				}
			} finally {
				if ( $switched ) {
					restore_current_blog();
				}
			}

			foreach ( $warm as $key => $target_id ) {
				if ( ! array_key_exists( $key, self::$wp_meta_warm ) ) {
					self::$wp_meta_warm[ $key ] = $target_id;
				}
			}
		}
	}

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
	 * Batched find_target() for one source post across many relations.
	 *
	 * Render-path hot aggregate (admin translation-status hooks): the
	 * per-relation loop resolved each pair with its own mapping lookup plus
	 * one or two postmeta JOINs — measured 2026-09-27 on the Lab product
	 * list, ~3 queries x 440 pairs per render. This resolves the same pairs
	 * with the exact find_target() semantics — mapping-table leg first
	 * (one batched query, see Post_Mapping_Service::get_mapped_targets_for_relations()),
	 * then the meta leg for misses (one batched virtual JOIN pair; wp/self
	 * relations keep the per-pair meta leg) — plus the same heal-on-meta-hit
	 * upsert when $heal is true.
	 *
	 * @since 2.1.5
	 *
	 * @param int   $source_post_id Source post ID.
	 * @param array $relations     List of site-relation rows (as returned by
	 *                              Site_Relation_Service::get_all_relations()).
	 * @param bool  $heal           When meta hits but mapping misses, upsert both.
	 * @return array<int,int|null> relation_id => target post ID or null.
	 */
	public static function find_targets_batch( int $source_post_id, array $relations, bool $heal = true ): array {
		$out = array();
		if ( $source_post_id <= 0 || empty( $relations ) ) {
			return $out;
		}

		$relations_by_id = array();
		$virtual_by_id   = array();
		$meta_by_id      = array();
		foreach ( $relations as $relation ) {
			if ( ! is_array( $relation ) || empty( $relation['id'] ) ) {
				continue;
			}
			$relation_id = (int) $relation['id'];
			if ( $relation_id <= 0 || isset( $relations_by_id[ $relation_id ] ) ) {
				continue;
			}
			$relations_by_id[ $relation_id ] = $relation;
			$meta_by_id[ $relation_id ]      = null;
		}
		if ( empty( $relations_by_id ) ) {
			return $out;
		}

		// 1. Mapping-table leg, one batched query for every pair. Like
		//    get_mapped_id(), the mapping leg needs the source post (its
		//    type); without it every pair is a mapping miss and falls to
		//    the meta leg, exactly as the per-pair path does.
		$source = get_post( $source_post_id );
		$source_post_type = $source ? (string) $source->post_type : '';
		if ( $source && '' !== $source_post_type && class_exists( Post_Mapping_Service::class ) ) {
			$mapped = Post_Mapping_Service::get_mapped_targets_for_relations( $source_post_id, $source_post_type, $relations_by_id );
			foreach ( $mapped as $relation_id => $target_post_id ) {
				if ( (int) $target_post_id > 0 ) {
					$out[ (int) $relation_id ] = (int) $target_post_id;
				}
			}
		}

		// Partition the still-missing relations for the meta leg.
		foreach ( $relations_by_id as $relation_id => $relation ) {
			if ( array_key_exists( $relation_id, $out ) ) {
				continue;
			}
			if ( 'virtual' === (string) ( $relation['target_site_type'] ?? 'wp' ) ) {
				$virtual_by_id[ $relation_id ] = $relation;
			}
		}

		// 2. Virtual meta leg: one batched JOIN pair (primary + soft
		//    fallback) on the current blog, exact per-pair semantics.
		$virtual_meta = array();
		if ( ! empty( $virtual_by_id ) ) {
			$virtual_meta = self::find_virtual_meta_batch( $source_post_id, $virtual_by_id );
		}

		// 3. Batched wp/self meta leg, grouped by target blog: one switch +
		//    one primary JOIN batch (relation IN) + at most one soft
		//    fallback query per DISTINCT target blog. The per-pair path ran
		//    a switch/restore pair plus two queries per relation — measured
		//    2026-09-27 on the Lab product list, its switch_to_blog()
		//    reloads drove the wp_user_roles storm (365 role re-reads per
		//    render: 190 source-blog restores + 175 target-blog reloads).
		$meta_groups = array();
		foreach ( $relations_by_id as $relation_id => $relation ) {
			if ( array_key_exists( $relation_id, $out ) || isset( $virtual_by_id[ $relation_id ] ) ) {
				continue;
			}
			$target_site_type = (string) ( $relation['target_site_type'] ?? 'wp' );
			if ( 'virtual' === $target_site_type ) {
				// Defensive: virtual relations were partitioned to the
				// virtual leg above; never resolve them against a blog.
				continue;
			}
			$target_blog = (int) ( $relation['target_site_id'] ?? 0 );
			$meta_groups[ $target_blog ][ $relation_id ] = $relation;
		}
		foreach ( $meta_groups as $target_blog => $group ) {
			$found = self::find_wp_meta_batch( $source_post_id, $target_blog, $group );
			foreach ( $group as $relation_id => $relation ) {
				$meta_by_id[ $relation_id ] = $found[ $relation_id ] ?? null;
			}
		}

		foreach ( $virtual_meta as $relation_id => $target_id ) {
			$meta_by_id[ $relation_id ] = $target_id;
		}

		// 4. Heal on meta hits (mapping miss + meta hit), exactly like
		//    find_target()'s tail, with the relation row already in hand.
		//    Mapping-leg hits already sit in $out and stay untouched
		//    (find_target() returns early on a mapping hit — no heal, no
		//    meta leg — so they must not be stomped by this loop).
		foreach ( $meta_by_id as $relation_id => $meta_id ) {
			if ( array_key_exists( $relation_id, $out ) ) {
				continue;
			}
			if ( empty( $meta_id ) ) {
				$out[ $relation_id ] = null;
				continue;
			}
			if ( $heal ) {
				self::ensure_markers(
					(int) $meta_id,
					$source_post_id,
					$relation_id,
					array(
						'relation'       => $relations_by_id[ $relation_id ],
						'post_type'      => $source_post_type,
					)
				);
			}
			$out[ $relation_id ] = (int) $meta_id;
		}

		return $out;
	}

	/**
	 * Batched virtual meta lookup: primary + soft fallback in two queries.
	 *
	 * Primary: source + relation markers (relation IN batch), vsid marker
	 * JOINed then cross-checked in PHP against the relation's virtual site
	 * (per-pair primary requires the exact vsid value; a divergent marker
	 * row is rejected there and here).
	 * Soft fallback: legacy writers omitted the relation marker, so pairs
	 * whose primary missed resolve by (virtual site, source) with no
	 * relation marker — first hit wins, like the per-pair LIMIT 1.
	 *
	 * @param int   $source_post_id Source post ID.
	 * @param array $virtual_by_id  relation_id => relation row (virtual targets).
	 * @return array<int,int> relation_id => target post ID (hits only).
	 */
	private static function find_virtual_meta_batch( int $source_post_id, array $virtual_by_id ): array {
		global $wpdb;

		$out = array();
		if ( empty( $virtual_by_id ) ) {
			return $out;
		}

		$relation_ids = array_map( 'intval', array_keys( $virtual_by_id ) );
		list( $rel_in_sql, $rel_in_ids ) = wptsall_db_prepare_int_in( $relation_ids );
		if ( empty( $rel_in_ids ) ) {
			return $out;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID AS target_id, pm1.meta_value AS vsid, pm3.meta_value AS rel
				FROM %i p
				INNER JOIN %i pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_wptsall_virtual_site_id'
				INNER JOIN %i pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_wptsall_source_post_id'
				INNER JOIN %i pm3 ON p.ID = pm3.post_id AND pm3.meta_key = '_wptsall_relation_id'
				WHERE pm2.meta_value = %d AND pm3.meta_value IN ($rel_in_sql)",
				$wpdb->posts,
				$wpdb->postmeta,
				$wpdb->postmeta,
				$wpdb->postmeta,
				$source_post_id,
				...$rel_in_ids
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			$relation_id = (int) $row['rel'];
			if ( isset( $out[ $relation_id ] ) || ! isset( $virtual_by_id[ $relation_id ] ) ) {
				continue;
			}
			// Exact per-pair primary semantics: the shadow's vsid marker must
			// be this relation's virtual site.
			if ( (string) $row['vsid'] !== (string) ( $virtual_by_id[ $relation_id ]['target_site_id'] ?? '' ) ) {
				continue;
			}
			$out[ $relation_id ] = (int) $row['target_id'];
		}

		// Soft fallback for the pairs whose primary missed.
		$pending = array();
		foreach ( $virtual_by_id as $relation_id => $relation ) {
			if ( ! array_key_exists( $relation_id, $out ) ) {
				$pending[ $relation_id ] = $relation;
			}
		}
		if ( empty( $pending ) ) {
			return $out;
		}

		$vsids = array();
		foreach ( $pending as $relation ) {
			$vsid = (string) ( $relation['target_site_id'] ?? '' );
			if ( '' !== $vsid ) {
				$vsids[] = $vsid;
			}
		}
		if ( empty( $vsids ) ) {
			return $out;
		}
		$vsids = array_values( array_unique( $vsids ) );

		$vsid_placeholders = implode( ',', array_fill( 0, count( $vsids ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$soft_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID AS target_id, pm1.meta_value AS vsid
				FROM %i p
				INNER JOIN %i pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_wptsall_virtual_site_id'
				INNER JOIN %i pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_wptsall_source_post_id'
				WHERE pm2.meta_value = %d AND pm1.meta_value IN ($vsid_placeholders)",
				$wpdb->posts,
				$wpdb->postmeta,
				$wpdb->postmeta,
				$source_post_id,
				...$vsids
			),
			ARRAY_A
		);
		$soft_by_vsid = array();
		foreach ( (array) $soft_rows as $row ) {
			$vsid = (string) $row['vsid'];
			if ( ! isset( $soft_by_vsid[ $vsid ] ) ) {
				$soft_by_vsid[ $vsid ] = (int) $row['target_id'];
			}
		}
		foreach ( $pending as $relation_id => $relation ) {
			$vsid = (string) ( $relation['target_site_id'] ?? '' );
			if ( '' !== $vsid && isset( $soft_by_vsid[ $vsid ] ) ) {
				$out[ $relation_id ] = $soft_by_vsid[ $vsid ];
			}
		}

		return $out;
	}

	/**
	 * Batched wp/self meta lookup for one target blog: primary batch + soft fallback.
	 *
	 * Primary: source + relation markers, one batched query (relation IN);
	 * first row per relation wins, mirroring the per-pair LIMIT 1.
	 * Soft fallback: legacy Sync WP rows omitted the relation marker — the
	 * per-pair query matches source + source_blog markers with NO relation
	 * condition, so every relation whose primary missed resolves to the same
	 * first row; the batch runs it once and assigns it to all pending
	 * relations. Semantics match find_wp_meta() pair-for-pair.
	 *
	 * @param int   $source_post_id Source post ID.
	 * @param int   $target_blog_id Target blog ID (0/current = no switch).
	 * @param array $group          relation_id => relation row (wp/self targets).
	 * @return array<int,int> relation_id => target post ID (hits only).
	 */
	private static function find_wp_meta_batch( int $source_post_id, int $target_blog_id, array $group ): array {
		global $wpdb;

		$out = array();
		if ( empty( $group ) ) {
			return $out;
		}

		// Warm consult: a render-level prefetch (prefetch_wp_meta_targets())
		// may have already resolved this whole group for this source — or an
		// earlier call in this request back-filled it below. Resolve every
		// pair from the warm map with no switch and no queries.
		$warm_hit = true;
		$warm_out = array();
		foreach ( $group as $relation_id => $relation ) {
			$warm_key = $source_post_id . ':' . $relation_id;
			if ( ! array_key_exists( $warm_key, self::$wp_meta_warm ) ) {
				$warm_hit = false;
				break;
			}
			$warm_out[ $relation_id ] = self::$wp_meta_warm[ $warm_key ];
		}
		if ( $warm_hit ) {
			foreach ( $warm_out as $relation_id => $target_id ) {
				if ( null !== $target_id ) {
					$out[ $relation_id ] = $target_id;
				}
			}
			return $out;
		}

		$source_blog_id = get_current_blog_id();
		$switched       = false;
		if ( is_multisite() && $target_blog_id > 0 && $target_blog_id !== $source_blog_id ) {
			switch_to_blog( $target_blog_id );
			$switched = true;
		}

		try {
			$relation_ids = array_map( 'intval', array_keys( $group ) );
			list( $rel_in_sql, $rel_in_ids ) = wptsall_db_prepare_int_in( $relation_ids );
			if ( empty( $rel_in_ids ) ) {
				return $out;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.ID AS target_id, pm2.meta_value AS rel
					FROM %i p
					INNER JOIN %i pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_wptsall_source_post_id'
					INNER JOIN %i pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_wptsall_relation_id'
					WHERE pm1.meta_value = %d AND pm2.meta_value IN ($rel_in_sql)",
					$wpdb->posts,
					$wpdb->postmeta,
					$wpdb->postmeta,
					$source_post_id,
					...$rel_in_ids
				),
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				$relation_id = (int) $row['rel'];
				if ( isset( $out[ $relation_id ] ) || ! isset( $group[ $relation_id ] ) ) {
					continue;
				}
				$out[ $relation_id ] = (int) $row['target_id'];
			}

			$pending = array();
			foreach ( $group as $relation_id => $relation ) {
				if ( ! array_key_exists( $relation_id, $out ) ) {
					$pending[ $relation_id ] = $relation;
				}
			}
			if ( ! empty( $pending ) ) {
				// Soft fallback (legacy Sync WP path): no relation condition, so
				// one query answers every pending relation of this blog.
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
				if ( $existing_id ) {
					$target_id = (int) $existing_id;
					foreach ( $pending as $relation_id => $relation ) {
						$out[ $relation_id ] = $target_id;
					}
				}
			}

			// Back-fill the warm map so repeat calls for this source x this
			// bucket (within the request) resolve with no switch, no queries.
			// Resolved misses warm as null — the per-pair re-query would
			// return the same empty answer.
			foreach ( $group as $relation_id => $relation ) {
				$warm_key = $source_post_id . ':' . $relation_id;
				if ( ! array_key_exists( $warm_key, self::$wp_meta_warm ) ) {
					self::$wp_meta_warm[ $warm_key ] = array_key_exists( $relation_id, $out )
						? (int) $out[ $relation_id ]
						: null;
				}
			}
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}

		return $out;
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

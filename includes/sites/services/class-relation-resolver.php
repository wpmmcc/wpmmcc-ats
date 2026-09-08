<?php
/**
 * Relation Resolver — single contract for source post → site relation.
 *
 * Admin chrome (flags, pending, admin-bar), the translate editor, and REST
 * shortcuts must all resolve `(source_post_id, to_lang?)` the same way:
 * prefer relations whose bound models cover the post type; never treat every
 * model as matching `post`/`page`.
 *
 * @package WPTSALL\Sites\Services
 * @since 2.3.0
 */

namespace WPTSALL\Sites\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Relation_Resolver class.
 */
class Relation_Resolver {

	/**
	 * Core CMS types covered by wordpress-blog / wordpress models when the
	 * model JSON does not list them explicitly.
	 *
	 * @var string[]
	 */
	const CORE_POST_TYPES = array( 'post', 'page' );

	/**
	 * Model plugin_slug / template values that imply core CMS coverage.
	 *
	 * @var string[]
	 */
	const CORE_MODEL_SLUGS = array( 'wordpress-blog', 'wordpress' );

	/**
	 * Resolve an active site relation for a source post.
	 *
	 * @param int         $source_post_id Source post ID.
	 * @param string|null $to_lang        Target language code, or null/'' for default.
	 * @param string|null $post_type      Optional override; default from the post.
	 * @return int Relation ID or 0 when none / ambiguous without language.
	 */
	public static function for_post( int $source_post_id, $to_lang = null, $post_type = null ): int {
		$post = get_post( $source_post_id );
		if ( ! $post ) {
			return 0;
		}

		$post_type = ( null !== $post_type && '' !== (string) $post_type )
			? (string) $post_type
			: (string) $post->post_type;
		$to_lang   = null === $to_lang ? '' : trim( (string) $to_lang );

		$relations = Site_Relation_Service::get_all_relations(
			array(
				'source_site_id' => get_current_blog_id(),
				'status'         => 'active',
			)
		);
		if ( empty( $relations ) || ! is_array( $relations ) ) {
			return 0;
		}

		$candidates = array();
		foreach ( $relations as $relation ) {
			$rid = (int) ( $relation['id'] ?? 0 );
			if ( $rid <= 0 ) {
				continue;
			}
			if ( '' !== $to_lang ) {
				$rel_lang = (string) ( $relation['target_lang'] ?? $relation['target_language'] ?? '' );
				if ( $rel_lang !== $to_lang ) {
					continue;
				}
			}
			$candidates[] = $relation;
		}

		if ( empty( $candidates ) ) {
			return 0;
		}

		$model_hits = array();
		foreach ( $candidates as $relation ) {
			$rid = (int) ( $relation['id'] ?? 0 );
			if ( self::relation_covers_post_type( $rid, $post_type ) ) {
				$model_hits[] = $rid;
			}
		}

		if ( ! empty( $model_hits ) ) {
			return (int) $model_hits[0];
		}

		// Language was provided: first matching-lang relation is acceptable when
		// no model lists the CPT (relation may still be usable for translate).
		if ( '' !== $to_lang ) {
			return (int) ( $candidates[0]['id'] ?? 0 );
		}

		// No language: only unambiguous when a single active relation exists.
		if ( 1 === count( $candidates ) ) {
			return (int) ( $candidates[0]['id'] ?? 0 );
		}

		return 0;
	}

	/**
	 * Resolve for a known target language (flag / pending shortcuts).
	 *
	 * @param int    $source_post_id Source post ID.
	 * @param string $to_lang        Target language code.
	 * @return int Relation ID or 0.
	 */
	public static function for_post_lang( int $source_post_id, string $to_lang ): int {
		return self::for_post( $source_post_id, $to_lang );
	}

	/**
	 * Resolve when no target language is known (admin-bar "+ Add").
	 *
	 * Returns 0 if multiple active relations exist and none prefer the CPT.
	 *
	 * @param int $source_post_id Source post ID.
	 * @return int Relation ID or 0.
	 */
	public static function for_post_default( int $source_post_id ): int {
		return self::for_post( $source_post_id, null );
	}

	/**
	 * Whether a relation's bound models cover a post type.
	 *
	 * @param int    $relation_id Relation ID.
	 * @param string $post_type   Post type name.
	 * @return bool
	 */
	public static function relation_covers_post_type( int $relation_id, string $post_type ): bool {
		if ( $relation_id <= 0 || '' === $post_type ) {
			return false;
		}
		if ( ! class_exists( Relation_Model_Service::class ) ) {
			return false;
		}

		$models = Relation_Model_Service::get_models_by_relation( $relation_id );
		if ( empty( $models ) || ! is_array( $models ) ) {
			return false;
		}

		foreach ( $models as $model ) {
			if ( self::model_covers_post_type( $model, $post_type ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a single model row covers a post type.
	 *
	 * Exact post_types JSON match, or wordpress-blog/wordpress for core types.
	 * Does NOT treat every model as matching post/page.
	 *
	 * @param array  $model     Model row.
	 * @param string $post_type Post type name.
	 * @return bool
	 */
	public static function model_covers_post_type( array $model, string $post_type ): bool {
		$pts = $model['post_types'] ?? array();
		if ( is_string( $pts ) ) {
			$decoded = json_decode( $pts, true );
			$pts     = is_array( $decoded ) ? $decoded : array( $pts );
		}
		foreach ( (array) $pts as $entry ) {
			$name = is_array( $entry ) ? (string) ( $entry['name'] ?? '' ) : (string) $entry;
			if ( $name === $post_type ) {
				return true;
			}
		}

		$slug = (string) ( $model['plugin_slug'] ?? $model['template'] ?? '' );
		if ( in_array( $slug, self::CORE_MODEL_SLUGS, true ) && in_array( $post_type, self::CORE_POST_TYPES, true ) ) {
			return true;
		}

		return false;
	}
}

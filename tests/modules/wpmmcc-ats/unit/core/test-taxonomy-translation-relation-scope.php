<?php
/**
 * Unit tests for relation-scoped manual taxonomy mappings.
 *
 * @package WPTSALL\Tests\Unit\Core
 */

class Test_Taxonomy_Translation_Relation_Scope extends SimpleTestCase {

	/**
	 * A source term can have separate manual translations for the same language
	 * when they belong to different virtual-site relations.
	 */
	public function test_pending_get_and_unlink_are_relation_scoped() {
		global $wpdb;

		$this->assertTrue( class_exists( '\WPTSALL\ManualTranslation\Services\Taxonomy_Translation_Service' ) );
		$this->assertTrue( class_exists( '\WPTSALL\Models\Services\Term_Mapping_Service' ) );

		$table = function_exists( 'wptsall_table' ) ? wptsall_table( 'term_mappings' ) : $wpdb->prefix . 'wptsall_term_mappings';
		$this->assertNotEquals( '', (string) $table );

		$stamp = strtolower( substr( md5( uniqid( 'tax-relation-scope-', true ) ), 0, 8 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.md5_md5
		$source = wp_insert_term( 'Tax Scope Source ' . $stamp, 'category', array( 'slug' => 'tax-scope-source-' . $stamp ) );
		$target_a = wp_insert_term( 'Tax Scope FR A ' . $stamp, 'category', array( 'slug' => 'tax-scope-fr-a-' . $stamp ) );
		$target_b = wp_insert_term( 'Tax Scope FR B ' . $stamp, 'category', array( 'slug' => 'tax-scope-fr-b-' . $stamp ) );

		$this->assertFalse( is_wp_error( $source ), is_wp_error( $source ) ? $source->get_error_message() : '' );
		$this->assertFalse( is_wp_error( $target_a ), is_wp_error( $target_a ) ? $target_a->get_error_message() : '' );
		$this->assertFalse( is_wp_error( $target_b ), is_wp_error( $target_b ) ? $target_b->get_error_message() : '' );

		$source_id = (int) $source['term_id'];
		$target_a_id = (int) $target_a['term_id'];
		$target_b_id = (int) $target_b['term_id'];
		$relation_a = 910001;
		$relation_b = 910002;
		$mapping_a = 0;
		$mapping_b = 0;

		try {
			$mapping_a = \WPTSALL\Models\Services\Term_Mapping_Service::create_mapping(
				array(
					'relation_id'      => $relation_a,
					'source_term_id'  => $source_id,
					'source_taxonomy' => 'category',
					'source_site_id'  => get_current_blog_id(),
					'source_lang'     => 'en_US',
					'target_term_id'  => $target_a_id,
					'target_taxonomy' => 'category',
					'target_site_id'  => 'v_tax_scope_a_' . $stamp,
					'target_lang'     => 'fr_FR',
					'mapping_method'  => 'manual',
				)
			);
			$mapping_b = \WPTSALL\Models\Services\Term_Mapping_Service::create_mapping(
				array(
					'relation_id'      => $relation_b,
					'source_term_id'  => $source_id,
					'source_taxonomy' => 'category',
					'source_site_id'  => get_current_blog_id(),
					'source_lang'     => 'en_US',
					'target_term_id'  => $target_b_id,
					'target_taxonomy' => 'category',
					'target_site_id'  => 'v_tax_scope_b_' . $stamp,
					'target_lang'     => 'fr_FR',
					'mapping_method'  => 'manual',
				)
			);
			$this->assertGreaterThan( 0, (int) $mapping_a );
			$this->assertGreaterThan( 0, (int) $mapping_b );

			$translations_a = \WPTSALL\ManualTranslation\Services\Taxonomy_Translation_Service::get_translations( $source_id, 'category', $relation_a );
			$translations_b = \WPTSALL\ManualTranslation\Services\Taxonomy_Translation_Service::get_translations( $source_id, 'category', $relation_b );
			$this->assertEquals( $target_a_id, (int) ( $translations_a['fr_FR']['target_term_id'] ?? 0 ) );
			$this->assertEquals( $target_b_id, (int) ( $translations_b['fr_FR']['target_term_id'] ?? 0 ) );
			$this->assertEquals( $relation_a, (int) ( $translations_a['fr_FR']['relation_id'] ?? 0 ) );
			$this->assertEquals( $relation_b, (int) ( $translations_b['fr_FR']['relation_id'] ?? 0 ) );

			$pending_a = \WPTSALL\ManualTranslation\Services\Taxonomy_Translation_Service::pending_terms( 'category', 'fr_FR', 500, $relation_a );
			$pending_missing = \WPTSALL\ManualTranslation\Services\Taxonomy_Translation_Service::pending_terms( 'category', 'fr_FR', 500, 910099 );
			$this->assertFalse( $this->term_id_in_pending( $source_id, $pending_a ), 'source term must not be pending for mapped relation A' );
			$this->assertTrue( $this->term_id_in_pending( $source_id, $pending_missing ), 'source term must be pending for an unmapped relation' );

			$deleted = \WPTSALL\ManualTranslation\Services\Taxonomy_Translation_Service::unlink( $source_id, 'category', 'fr_FR', $relation_a );
			$this->assertEquals( 1, (int) $deleted );
			$this->assertEmpty( \WPTSALL\ManualTranslation\Services\Taxonomy_Translation_Service::get_translations( $source_id, 'category', $relation_a ) );
			$remaining_b = \WPTSALL\ManualTranslation\Services\Taxonomy_Translation_Service::get_translations( $source_id, 'category', $relation_b );
			$this->assertEquals( $target_b_id, (int) ( $remaining_b['fr_FR']['target_term_id'] ?? 0 ), 'unlinking relation A must keep relation B intact' );
		} finally {
			$ids = array_filter( array( (int) $mapping_a, (int) $mapping_b ) );
			if ( ! empty( $ids ) ) {
				$wpdb->query( 'DELETE FROM ' . $table . ' WHERE id IN (' . implode( ',', array_map( 'absint', $ids ) ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			}
			$wpdb->delete( $table, array( 'source_term_id' => $source_id, 'source_taxonomy' => 'category' ), array( '%d', '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			foreach ( array( $source_id, $target_a_id, $target_b_id ) as $term_id ) {
				if ( $term_id > 0 ) {
					wp_delete_term( $term_id, 'category' );
				}
			}
		}
	}

	/**
	 * @param int   $term_id Term ID.
	 * @param array $rows Pending rows.
	 * @return bool
	 */
	private function term_id_in_pending( int $term_id, array $rows ): bool {
		foreach ( $rows as $row ) {
			if ( (int) ( $row['term_id'] ?? 0 ) === $term_id ) {
				return true;
			}
		}
		return false;
	}
}

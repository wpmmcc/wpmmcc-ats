<?php
/**
 * Data Seeder Test
 *
 * Tests data seeding for various plugins.
 * Uses WPTSALL_TEST_ prefix to avoid conflicts with real plugin data.
 *
 * @package WPTSALL
 * @since 0.5.0
 */

use PHPUnit\Framework\TestCase;

class DataSeederTest extends TestCase {

	/**
	 * Test data prefix to avoid conflicts
	 */
	const TEST_PREFIX = 'WPTSALL_TEST_';

	/**
	 * Created post IDs for cleanup
	 *
	 * @var array
	 */
	private $created_posts = array();

	/**
	 * Created term IDs for cleanup
	 *
	 * @var array
	 */
	private $created_terms = array();

	/**
	 * Clean up test data after each test
	 */
	public function tearDown(): void {
		// Delete created posts
		foreach ( $this->created_posts as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		$this->created_posts = array();

		// Delete created terms
		foreach ( $this->created_terms as $term_data ) {
			wp_delete_term( $term_data['term_id'], $term_data['taxonomy'] );
		}
		$this->created_terms = array();

		parent::tearDown();
	}

	/**
	 * Test seeding content for various plugins
	 */
	public function test_seed_content_plugins_data() {
		$map = array(
			'woocommerce'            => array(
				'post_types' => array( 'product' ),
				'taxonomies' => array( 'product_cat' ),
			),
			'bbpress'                => array(
				'post_types' => array( 'forum', 'topic', 'reply' ),
				'taxonomies' => array( 'topic-tag' ),
			),
			'easy-digital-downloads' => array(
				'post_types' => array( 'download' ),
				'taxonomies' => array( 'download_category' ),
			),
			'buddypress'             => array(
				'post_types' => array( 'buddypress' ),
				'taxonomies' => array(),
			),
		);

		foreach ( $map as $plugin => $objects ) {
			foreach ( $objects['post_types'] as $pt ) {
				if ( ! post_type_exists( $pt ) ) {
					continue;
				}
				$post_id = wp_insert_post(
					array(
						'post_type'    => $pt,
						'post_title'   => self::TEST_PREFIX . $plugin . '_' . $pt . '_' . uniqid(),
						'post_status'  => 'publish',
						'post_content' => self::TEST_PREFIX . 'Seeded content for ' . $pt,
					),
					true
				);
				$this->assertIsInt( $post_id, 'Failed seeding post for ' . $pt );
				$this->created_posts[] = $post_id;
			}

			foreach ( $objects['taxonomies'] as $tax ) {
				if ( ! taxonomy_exists( $tax ) ) {
					continue;
				}
				$term_name = self::TEST_PREFIX . $tax . '_' . uniqid();
				$term      = wp_insert_term( $term_name, $tax );
				if ( is_wp_error( $term ) && 'term_exists' === $term->get_error_code() ) {
					$this->assertNotEmpty( $term->get_error_data(), 'Existing term data missing for ' . $tax );
				} else {
					$this->assertIsArray( $term, 'Failed seeding term for ' . $tax );
					$this->created_terms[] = array(
						'term_id'  => $term['term_id'],
						'taxonomy' => $tax,
					);
				}
			}
		}

		$this->assertTrue( true, 'Seeder completed without fatal errors.' );
	}

	/**
	 * Helper to clean up all test data matching our prefix
	 *
	 * Can be called manually: DataSeederTest::cleanup_test_data()
	 */
	public static function cleanup_test_data() {
		global $wpdb;

		// Delete posts with our prefix
		$posts = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_title LIKE %s OR post_content LIKE %s",
				self::TEST_PREFIX . '%',
				self::TEST_PREFIX . '%'
			)
		);

		foreach ( $posts as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		// Delete terms with our prefix
		$terms = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.term_id, tt.taxonomy FROM {$wpdb->terms} t
				 JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
				 WHERE t.name LIKE %s",
				self::TEST_PREFIX . '%'
			)
		);

		foreach ( $terms as $term ) {
			wp_delete_term( $term->term_id, $term->taxonomy );
		}

		return array(
			'posts_deleted' => count( $posts ),
			'terms_deleted' => count( $terms ),
		);
	}
}


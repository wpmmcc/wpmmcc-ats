<?php
/**
 * Lane minimums for isolated matrix slots (core-only smoke).
 * Runs before verify-seeding.php when counts are thin after Plan A.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tag_count = (int) wp_count_terms(
	array(
		'taxonomy'   => 'post_tag',
		'hide_empty' => false,
	)
);
for ( $i = $tag_count; $i < 2; $i++ ) {
	wp_insert_term( 'e2e-matrix-tag-' . $i, 'post_tag' );
}

if ( class_exists( 'WooCommerce' ) ) {
	$product_count = (int) wp_count_posts( 'product' )->publish;
	$need          = max( 0, 10 - $product_count );
	for ( $i = 0; $i < $need; $i++ ) {
		$id = wp_insert_post(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
				'post_title'  => 'E2E Matrix Product ' . ( $product_count + $i + 1 ),
			),
			true
		);
		if ( is_wp_error( $id ) || ! $id ) {
			continue;
		}
		if ( function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $id );
			if ( $product ) {
				$product->set_regular_price( '9.99' );
				$product->save();
			}
		}
	}

	$serialized_attrs = (int) $GLOBALS['wpdb']->get_var(
		"SELECT COUNT(*) FROM {$GLOBALS['wpdb']->postmeta}
		 WHERE meta_key = '_product_attributes' AND meta_value LIKE 'a:%'"
	);
	if ( $serialized_attrs < 1 ) {
		$product_id = (int) $GLOBALS['wpdb']->get_var(
			"SELECT ID FROM {$GLOBALS['wpdb']->posts}
			 WHERE post_type = 'product' AND post_status = 'publish'
			 ORDER BY ID ASC LIMIT 1"
		);
		if ( $product_id > 0 ) {
			$attrs = array(
				'pa_color' => array(
					'name'         => 'pa_color',
					'value'        => 'blue | red',
					'position'     => 0,
					'is_visible'   => 1,
					'is_variation' => 0,
					'is_taxonomy'  => 1,
				),
			);
			update_post_meta( $product_id, '_product_attributes', $attrs );
		}
	}
}

if ( post_type_exists( 'at_biz_dir' ) ) {
	$listing_count = (int) wp_count_posts( 'at_biz_dir' )->publish;
	$need          = max( 0, 5 - $listing_count );
	for ( $i = 0; $i < $need; $i++ ) {
		$id = wp_insert_post(
			array(
				'post_type'    => 'at_biz_dir',
				'post_status'  => 'publish',
				'post_title'   => 'E2E Matrix Directorist Listing ' . ( $listing_count + $i + 1 ),
				'post_content' => 'Directory listing fixture for matrix minimum coverage.',
				'post_excerpt' => 'Directory listing fixture for matrix minimum coverage.',
			),
			true
		);
		if ( is_wp_error( $id ) || ! $id ) {
			continue;
		}
		update_post_meta( $id, '_address', '100 Matrix Avenue' );
		update_post_meta( $id, '_phone', '+1 555 0100' );
		update_post_meta( $id, '_website', home_url( '/' ) );
	}
}

echo "matrix minimum fixups applied\n";

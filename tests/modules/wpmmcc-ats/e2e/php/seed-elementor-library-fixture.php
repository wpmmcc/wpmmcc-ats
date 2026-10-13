<?php
/**
 * Ensure at least one published elementor_library item carries _elementor_data.
 *
 * Elementor's default kit posts often exist without JSON payload; elementor-content
 * hard gates require translatable _elementor_data for seed + project verification.
 *
 * Run:
 *   wp eval-file tests/modules/wpmmcc-ats/e2e/php/seed-elementor-library-fixture.php
 */

require_once __DIR__ . '/helpers.php';

global $wpdb;

/**
 * Minimal Elementor JSON with translatable heading text.
 *
 * @return string
 */
function e2e_elementor_library_fixture_json(): string {
	$payload = array(
		array(
			'id'       => 'wptsall-e2e-section',
			'elType'   => 'section',
			'elements' => array(
				array(
					'id'       => 'wptsall-e2e-column',
					'elType'   => 'column',
					'elements' => array(
						array(
							'id'         => 'wptsall-e2e-heading',
							'elType'     => 'widget',
							'widgetType' => 'heading',
							'settings'   => array(
								'title' => 'E2E Elementor library heading for translation routing',
							),
						),
					),
				),
			),
		),
	);

	return wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
}

/**
 * Count published elementor_library posts with non-empty JSON _elementor_data.
 *
 * @return int
 */
function e2e_elementor_library_json_count(): int {
	global $wpdb;

	return (int) $wpdb->get_var(
		"SELECT COUNT(DISTINCT p.ID)
		 FROM {$wpdb->posts} p
		 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
		 WHERE p.post_type = 'elementor_library'
		   AND p.post_status = 'publish'
		   AND pm.meta_key = '_elementor_data'
		   AND pm.meta_value <> ''
		   AND pm.meta_value LIKE '[%'"
	);
}

/**
 * Ensure elementor_library fixture exists.
 *
 * @return array{post_id:int,action:string,json_count:int}
 */
function e2e_seed_elementor_library_fixture(): array {
	global $wpdb;

	$result = array(
		'post_id'    => 0,
		'action'     => 'skipped',
		'json_count' => e2e_elementor_library_json_count(),
	);

	if ( ! post_type_exists( 'elementor_library' ) ) {
		return $result;
	}

	if ( $result['json_count'] >= 1 ) {
		$result['action']  = 'kept';
		$result['post_id'] = (int) $wpdb->get_var(
			"SELECT p.ID
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
			 WHERE p.post_type = 'elementor_library'
			   AND p.post_status = 'publish'
			   AND pm.meta_key = '_elementor_data'
			   AND pm.meta_value <> ''
			   AND pm.meta_value LIKE '[%'
			 ORDER BY p.ID ASC
			 LIMIT 1"
		);
		return $result;
	}

	$slug    = 'wptsall-e2e-elementor-library-template';
	$post_id = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_name = %s
			   AND post_type = 'elementor_library'
			   AND post_status != 'trash'
			 ORDER BY ID DESC
			 LIMIT 1",
			$slug
		)
	);

	$now_local = current_time( 'mysql' );
	$now_gmt   = current_time( 'mysql', true );

	if ( $post_id <= 0 ) {
		$inserted = $wpdb->insert(
			$wpdb->posts,
			array(
				'post_author'           => 1,
				'post_date'             => $now_local,
				'post_date_gmt'         => $now_gmt,
				'post_content'          => '',
				'post_title'            => 'WPTSALL E2E Elementor Library Template',
				'post_excerpt'          => 'E2E Elementor library excerpt for plain text routing.',
				'post_status'           => 'publish',
				'comment_status'        => 'closed',
				'ping_status'           => 'closed',
				'post_password'         => '',
				'post_name'             => $slug,
				'to_ping'               => '',
				'pinged'                => '',
				'post_modified'         => $now_local,
				'post_modified_gmt'     => $now_gmt,
				'post_content_filtered' => '',
				'post_parent'           => 0,
				'guid'                  => '',
				'menu_order'            => 0,
				'post_type'             => 'elementor_library',
				'post_mime_type'        => '',
				'comment_count'         => 0,
			),
			array(
				'%d', '%s', '%s', '%s', '%s', '%s',
				'%s', '%s', '%s', '%s', '%s', '%s',
				'%s', '%s', '%s', '%d', '%s', '%d',
				'%s', '%s', '%d',
			)
		);
		if ( false === $inserted || (int) $wpdb->insert_id <= 0 ) {
			echo "ERROR: failed to insert elementor_library fixture\n";
			exit( 1 );
		}
		$post_id         = (int) $wpdb->insert_id;
		$result['action'] = 'created';
		$wpdb->update(
			$wpdb->posts,
			array( 'guid' => home_url( '/?p=' . $post_id ) ),
			array( 'ID' => $post_id ),
			array( '%s' ),
			array( '%d' )
		);
	} else {
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_title'        => 'WPTSALL E2E Elementor Library Template',
				'post_excerpt'      => 'E2E Elementor library excerpt for plain text routing.',
				'post_status'       => 'publish',
				'post_modified'     => $now_local,
				'post_modified_gmt' => $now_gmt,
			),
			array( 'ID' => $post_id ),
			array( '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
		$result['action'] = 'updated';
	}

	clean_post_cache( $post_id );

	update_post_meta( $post_id, '_wptsall_e2e_elementor_library_fixture', '1' );
	update_post_meta( $post_id, '_elementor_data', e2e_elementor_library_fixture_json() );
	update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
	update_post_meta( $post_id, '_elementor_template_type', 'page' );
	update_post_meta( $post_id, '_elementor_version', '3.0.0' );

	$result['post_id']    = $post_id;
	$result['json_count'] = e2e_elementor_library_json_count();

	return $result;
}

if ( ! defined( 'ABSPATH' ) ) {
	echo "Must be run via wp eval-file\n";
	exit( 1 );
}

// When required by fix-url-access-config.php, only register helpers — do not hard-fail.
if ( defined( 'E2E_URL_ACCESS_CONFIG_RUNNING' ) ) {
	return;
}

echo "=== E2E v2: Seed Elementor Library Fixture ===\n\n";

$summary = e2e_seed_elementor_library_fixture();
echo '  action=' . (string) $summary['action'] . "\n";
echo '  post_id=' . (int) $summary['post_id'] . "\n";
echo '  json_count=' . (int) $summary['json_count'] . "\n";

if ( (int) $summary['json_count'] < 1 ) {
	echo "\nERROR: elementor_library _elementor_data fixture missing after seed.\n";
	exit( 1 );
}

echo "\nDone.\n";

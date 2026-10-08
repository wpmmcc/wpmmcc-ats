<?php
/**
 * Deterministic LearnPress seed data for E2E.
 *
 * The LearnPress admin sample importer is UI-driven and can repeatedly append
 * large sample datasets. This script keeps the E2E lane bounded and repeatable.
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Access denied.' );
}

if ( ! post_type_exists( 'lp_course' ) ) {
	echo "LearnPress post types are not available.\n";
	return;
}

$post_types = array( 'lp_course', 'lp_lesson', 'lp_quiz', 'lp_question' );
$deleted    = 0;

foreach ( $post_types as $post_type ) {
	$ids = get_posts(
		array(
			'post_type'      => $post_type,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);

	foreach ( $ids as $id ) {
		if ( wp_delete_post( (int) $id, true ) ) {
			$deleted++;
		}
	}
}

$created = array(
	'lp_course'   => 0,
	'lp_lesson'   => 0,
	'lp_quiz'     => 0,
	'lp_question' => 0,
);

$courses = array(
	array(
		'title' => 'LearnPress Business Translation Essentials',
		'level' => 'beginner',
	),
	array(
		'title' => 'LearnPress Multilingual Course Operations',
		'level' => 'intermediate',
	),
	array(
		'title' => 'LearnPress Global LMS Launch Checklist',
		'level' => 'advanced',
	),
	array(
		'title' => 'LearnPress Student Onboarding Workflow',
		'level' => 'beginner',
	),
	array(
		'title' => 'LearnPress Content Localization Review',
		'level' => 'intermediate',
	),
);

foreach ( $courses as $index => $course ) {
	$course_id = wp_insert_post(
		array(
			'post_type'    => 'lp_course',
			'post_status'  => 'publish',
			'post_title'   => $course['title'],
			'post_excerpt' => 'A deterministic LearnPress course fixture for WPTSALL E2E validation.',
			'post_content' => 'This course validates LearnPress course translation, metadata discovery, and frontend content handling in the WPTSALL E2E lane.',
			'post_date'    => gmdate( 'Y-m-d H:i:s', time() - ( ( 20 + $index ) * DAY_IN_SECONDS ) ),
		)
	);

	if ( is_wp_error( $course_id ) || ! $course_id ) {
		echo 'Failed to create LearnPress course: ' . $course['title'] . "\n";
		continue;
	}

	$course_id = (int) $course_id;
	$created['lp_course']++;
	update_post_meta( $course_id, '_wptsall_seed_source', 'learnpress-deterministic' );
	update_post_meta( $course_id, '_lp_duration', ( 4 + $index ) . ' weeks' );
	update_post_meta( $course_id, '_lp_level', $course['level'] );
	update_post_meta( $course_id, '_lp_students', (string) ( 25 + ( $index * 7 ) ) );

	for ( $lesson_index = 1; $lesson_index <= 2; $lesson_index++ ) {
		$lesson_id = wp_insert_post(
			array(
				'post_type'    => 'lp_lesson',
				'post_status'  => 'publish',
				'post_title'   => $course['title'] . ' - Lesson ' . $lesson_index,
				'post_excerpt' => 'LearnPress lesson fixture for translation coverage.',
				'post_content' => 'This lesson provides realistic LearnPress lesson body content for WPTSALL scanning and translation tests.',
				'post_parent'  => $course_id,
				'post_date'    => gmdate( 'Y-m-d H:i:s', time() - ( ( 10 + $lesson_index + $index ) * DAY_IN_SECONDS ) ),
			)
		);

		if ( ! is_wp_error( $lesson_id ) && $lesson_id ) {
			$created['lp_lesson']++;
			update_post_meta( (int) $lesson_id, '_wptsall_seed_source', 'learnpress-deterministic' );
			update_post_meta( (int) $lesson_id, '_lp_course', $course_id );
		}
	}

	$quiz_id = wp_insert_post(
		array(
			'post_type'    => 'lp_quiz',
			'post_status'  => 'publish',
			'post_title'   => $course['title'] . ' - Readiness Quiz',
			'post_excerpt' => 'LearnPress quiz fixture for translation coverage.',
			'post_content' => 'This quiz validates whether course translation setup is ready for multilingual delivery.',
			'post_parent'  => $course_id,
			'post_date'    => gmdate( 'Y-m-d H:i:s', time() - ( ( 5 + $index ) * DAY_IN_SECONDS ) ),
		)
	);

	if ( ! is_wp_error( $quiz_id ) && $quiz_id ) {
		$created['lp_quiz']++;
		update_post_meta( (int) $quiz_id, '_wptsall_seed_source', 'learnpress-deterministic' );
		update_post_meta( (int) $quiz_id, '_lp_course', $course_id );
	}
}

echo "Deleted old LearnPress content: {$deleted}\n";
foreach ( $created as $post_type => $count ) {
	echo "Created {$post_type}: {$count}\n";
}


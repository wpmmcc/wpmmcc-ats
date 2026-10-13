<?php
/**
 * LifterLMS JSON Import Script
 *
 * Usage: wp eval-file llms-json-import.php --user=admin --path=/usr/local/var/www
 */

if ( ! class_exists( 'LifterLMS' ) ) {
    WP_CLI::error( 'LifterLMS is not active' );
}

$json_file = dirname( __DIR__ ) . '/lifterlms/sample-course.json';

if ( ! file_exists( $json_file ) ) {
    WP_CLI::error( "JSON file not found: $json_file" );
}

WP_CLI::log( "LifterLMS Import" );
WP_CLI::log( "================" );
WP_CLI::log( "Importing from: $json_file" );

// Count before
$courses_before = wp_count_posts( 'course' )->publish;
$lessons_before = wp_count_posts( 'lesson' )->publish;
WP_CLI::log( "Before: $courses_before courses, $lessons_before lessons" );

// Read JSON
$raw = file_get_contents( $json_file );
$data = json_decode( $raw, true );

if ( ! $data ) {
    WP_CLI::error( "Failed to parse JSON file" );
}

WP_CLI::log( "Generator type: " . ( isset( $data['_generator'] ) ? $data['_generator'] : 'auto' ) );

try {
    // Include template functions (required by generator)
    llms()->include_template_functions();

    // Create generator
    $generator = new LLMS_Generator( $raw );

    // Set generator type if specified in JSON
    if ( isset( $data['_generator'] ) ) {
        $generator->set_generator( $data['_generator'] );
    }

    // Generate content
    $result = $generator->generate();

    if ( is_wp_error( $result ) ) {
        WP_CLI::warning( "Import had errors:" );
        foreach ( $result->get_error_codes() as $code ) {
            WP_CLI::log( "  - $code: " . $result->get_error_message( $code ) );
        }
    }

    // Get stats
    $stats = $generator->get_stats();
    if ( ! empty( $stats ) ) {
        WP_CLI::log( "Imported:" );
        if ( ! empty( $stats['course'] ) || ! empty( $stats['courses'] ) ) {
            $c = $stats['course'] ?? $stats['courses'] ?? 0;
            WP_CLI::log( "  - Courses: $c" );
        }
        if ( ! empty( $stats['lesson'] ) || ! empty( $stats['lessons'] ) ) {
            $l = $stats['lesson'] ?? $stats['lessons'] ?? 0;
            WP_CLI::log( "  - Lessons: $l" );
        }
        if ( ! empty( $stats['quiz'] ) || ! empty( $stats['quizzes'] ) ) {
            $q = $stats['quiz'] ?? $stats['quizzes'] ?? 0;
            WP_CLI::log( "  - Quizzes: $q" );
        }
        if ( ! empty( $stats['question'] ) || ! empty( $stats['questions'] ) ) {
            $qu = $stats['question'] ?? $stats['questions'] ?? 0;
            WP_CLI::log( "  - Questions: $qu" );
        }
    }

    // Count after
    $courses_after = wp_count_posts( 'course' )->publish;
    $lessons_after = wp_count_posts( 'lesson' )->publish;
    WP_CLI::log( "After: $courses_after courses, $lessons_after lessons" );

    $new_courses = $courses_after - $courses_before;
    $new_lessons = $lessons_after - $lessons_before;

    if ( $new_courses > 0 || $new_lessons > 0 ) {
        WP_CLI::success( "Successfully imported $new_courses courses and $new_lessons lessons" );
    } else {
        WP_CLI::warning( "No new content imported (may already exist)" );
    }

} catch ( Exception $e ) {
    WP_CLI::error( "Exception: " . $e->getMessage() );
} catch ( Error $e ) {
    WP_CLI::error( "Error: " . $e->getMessage() );
}

<?php
/**
 * Class WPTSALL_Test_Tasks
 *
 * @package WPTSALL
 */

/**
 * Test task functions
 *
 * Note: The Tasks module is gated behind wptsall_is_current_site_domain_valid()
 * which rejects localhost. All tests guard on function availability.
 */
class WPTSALL_Test_Tasks extends WP_UnitTestCase {

    /**
     * Track task IDs created during tests for targeted cleanup.
     *
     * @var array
     */
    private $test_task_ids = array();

    /**
     * Set up test environment.
     */
    public function setUp(): void {
        parent::setUp();

        if ( ! function_exists( 'wptsall_ensure_task_table' ) ) {
            $this->markTestSkipped( 'Tasks module not loaded (domain validation gate on localhost)' );
        }

        // Ensure task table exists.
        wptsall_ensure_task_table();

        // Reset tracked IDs for each test.
        $this->test_task_ids = array();
    }

    /**
     * Test task table exists.
     */
    public function test_task_table_exists() {
        global $wpdb;
        $table = wptsall_table('tasks');

        $result = $wpdb->get_var($wpdb->prepare(
            'SHOW TABLES LIKE %s',
            $table
        ));

        $this->assertEquals($table, $result);
    }

    /**
     * Test task table structure.
     */
    public function test_task_table_structure() {
        global $wpdb;
        $table = wptsall_table('tasks');

        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$table}");
        $column_names = wp_list_pluck($columns, 'Field');

        $expected_columns = [
            'id',
            'blog_id',
            'target_blog',
            'target_type',
            'target_identifier',
            'site_id',
            'template',
            'site_mode',
            'lang_from',
            'lang_to',
            'object_type',
            'subtype',
            'object_id',
            'status',
            'status_note',
            'retry_count',
            'created_at',
            'updated_at',
        ];

        foreach ($expected_columns as $column) {
            $this->assertContains($column, $column_names,
                "Table should have '$column' column");
        }
    }

    /**
     * Test wptsall_insert_tasks() function.
     */
    public function test_insert_tasks() {
        $tasks = [
            [
                'blog_id' => 1,
                'site_id' => 1,
                'template' => 'test_insert',
                'object_type' => 'test_post_type',
                'subtype' => 'product',
                'object_id' => 123,
                'status' => 'pending',
            ],
        ];

        wptsall_insert_tasks($tasks, 'zh_CN', 'en_US');

        global $wpdb;
        $table = wptsall_table('tasks');

        // Track the inserted task ID for cleanup.
        $inserted_id = $wpdb->get_var(
            "SELECT id FROM {$table} WHERE object_type = 'test_post_type' AND object_id = 123 ORDER BY id DESC LIMIT 1"
        );
        if ( $inserted_id ) {
            $this->test_task_ids[] = (int) $inserted_id;
        }

        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE object_type = 'test_post_type' AND object_id = 123");

        $this->assertGreaterThan(0, $count);
    }

    /**
     * Test task status values.
     * Note: wptsall_insert_tasks() always inserts with 'pending' status by design.
     * This test verifies the valid status values in the database ENUM/column.
     */
    public function test_task_status_values() {
        global $wpdb;
        $table = wptsall_table('tasks');
        $valid_statuses = ['pending', 'processing', 'completed', 'retry', 'failed'];

        // Insert a task first with test-identifiable object_type
        $object_id = rand(10000, 99999);
        $tasks = [
            [
                'blog_id' => 1,
                'site_id' => 1,
                'template' => 'test_status',
                'object_type' => 'test_post_type',
                'subtype' => 'post',
                'object_id' => $object_id,
            ],
        ];

        wptsall_insert_tasks($tasks);

        // Track the inserted task ID for cleanup.
        $inserted_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE object_type = 'test_post_type' AND object_id = %d ORDER BY id DESC LIMIT 1",
            $object_id
        ));
        if ( $inserted_id ) {
            $this->test_task_ids[] = (int) $inserted_id;
        }

        // Verify initial status is 'pending'
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$table} WHERE object_type = 'test_post_type' AND object_id = %d ORDER BY id DESC LIMIT 1",
            $object_id
        ));
        $this->assertEquals('pending', $result, 'Initial status should be pending');

        // Test that we can update to all valid status values
        foreach ($valid_statuses as $status) {
            $wpdb->update(
                $table,
                array('status' => $status),
                array('object_type' => 'test_post_type', 'object_id' => $object_id)
            );

            $result = $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$table} WHERE object_type = 'test_post_type' AND object_id = %d ORDER BY id DESC LIMIT 1",
                $object_id
            ));

            $this->assertEquals($status, $result, "Status should be {$status}");
        }
    }

    /**
     * Clean up test data.
     *
     * Only deletes tasks created by this test class:
     * 1. Tracked IDs captured during test execution
     * 2. Safety net: rows with test-identifiable object_type pattern
     */
    public function tearDown(): void {
        if ( ! function_exists( 'wptsall_table' ) ) {
            parent::tearDown();
            return;
        }

        global $wpdb;
        $table = wptsall_table('tasks');
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists ) {
            // Delete tracked task IDs.
            if ( ! empty( $this->test_task_ids ) ) {
                $ids = implode( ',', array_map( 'intval', $this->test_task_ids ) );
                $wpdb->query( "DELETE FROM {$table} WHERE id IN ({$ids})" );
            }

            // Safety net: delete any rows with test-identifiable patterns.
            $wpdb->query( "DELETE FROM {$table} WHERE object_type LIKE 'test_%'" );
            $wpdb->query( "DELETE FROM {$table} WHERE template LIKE 'test_%'" );
        }

        parent::tearDown();
    }
}

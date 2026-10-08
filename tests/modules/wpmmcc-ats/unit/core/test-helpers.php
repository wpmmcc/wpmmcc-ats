<?php
/**
 * Class WPTSALL_Test_Helpers
 *
 * @package WPTSALL
 */

/**
 * Test helper functions
 */
class WPTSALL_Test_Helpers extends SimpleTestCase {

    /**
     * Test wptsall_table() function returns correct table name.
     */
    public function test_wptsall_table() {
        global $wpdb;

        $table = wptsall_table('tasks');
        $expected = $wpdb->prefix . 'wptsall_tasks';

        $this->assertIsString($table);
        $this->assertEquals($expected, $table);
        $this->assertStringContainsString($wpdb->prefix, $table);
    }

    /**
     * Test wptsall_table() with different table keys.
     */
    public function test_wptsall_table_various_keys() {
        global $wpdb;

        // Only test currently supported table keys
        // Deprecated: 'virtual_sites' - 虚拟站点使用 option 存储
        // Deprecated: 'model_url_rules' - 已被 translation_rules 替代
        // v0.7.0: 'virtual' 已删除，统一使用 'virtual_site_content'
        $tables = [
            'tasks'                => 'wptsall_tasks',
            'task_logs'            => 'wptsall_task_logs',
            'hooks'                => 'wptsall_hooks',
            'virtual_site_content' => 'wptsall_virtual_site_content',
            'virtual_sites'        => 'wptsall_virtual_sites',
            'site_relations'       => 'wptsall_site_relations',
            'models'               => 'wptsall_models',
            'translation_rules'    => 'wptsall_translation_rules',
            'plugin_mappings'      => 'wptsall_plugin_mappings',
            'templates'            => 'wptsall_templates',
            'template_entries'     => 'wptsall_template_entries',
        ];

        foreach ($tables as $key => $suffix) {
            $table = wptsall_table($key);
            $expected = $wpdb->prefix . $suffix;
            $this->assertEquals($expected, $table, "Table name for '$key' should be $expected");
        }
    }

    /**
     * Test wptsall_table() uses base_prefix for mappings table.
     */
    public function test_wptsall_table_mappings_uses_base_prefix() {
        global $wpdb;

        $table = wptsall_table('mappings');
        $expected = $wpdb->base_prefix . 'wptsall_mappings';

        $this->assertEquals($expected, $table, "Mappings table should use base_prefix");
    }

    /**
     * Test wptsall_saved_templates() returns array.
     */
    public function test_wptsall_saved_templates() {
        $templates = wptsall_saved_templates();

        $this->assertIsArray($templates);
    }

    /**
     * Test wptsall_saved_templates() with mock template.
     */
    public function test_wptsall_saved_templates_with_data() {
        // Create test template.
        $template_data = [
            'plugin' => 'Test Plugin',
            'objects' => [],
        ];
        update_option('wptsall_template_testplugin', $template_data);

        $templates = wptsall_saved_templates();

        $this->assertArrayHasKey('testplugin', $templates);
        $this->assertEquals('Test Plugin', $templates['testplugin']);

        // Clean up.
        delete_option('wptsall_template_testplugin');
    }

    /**
     * Test wptsall_get_virtual_sites() returns array.
     */
    public function test_wptsall_get_virtual_sites() {
        $sites = wptsall_get_virtual_sites();

        $this->assertIsArray($sites);
    }

    /**
     * Test wptsall_get_virtual_site() with mock data.
     */
    public function test_wptsall_get_virtual_site() {
        // Create test virtual site.
        $test_sites = [
            [
                'id' => 'test-site',
                'name' => 'Test Site',
                'url' => 'https://test.example.com',
                'base' => 'virtual/test-site',
            ],
        ];
        update_option('wptsall_virtual_sites', $test_sites);

        $site = wptsall_get_virtual_site('test-site');

        $this->assertIsArray($site);
        $this->assertEquals('test-site', $site['id']);
        $this->assertEquals('Test Site', $site['name']);

        // Test non-existent site.
        $null_site = wptsall_get_virtual_site('non-existent');
        $this->assertNull($null_site);

        // Clean up.
        delete_option('wptsall_virtual_sites');
    }
}

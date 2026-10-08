<?php

use PHPUnit\Framework\TestCase;

class WPTSALLTest extends TestCase {
    public function test_generate_template_woocommerce_has_urls() {
        $tpl = WPTSALL_Scanner::generate_plugin_template( 'woocommerce', -1 );
        $this->assertIsArray( $tpl );
        $this->assertEquals( 'woocommerce', $tpl['plugin'] );
        $this->assertNotEmpty( $tpl['urls'] );
        $this->assertArrayHasKey( 'objects', $tpl );
    }

    public function test_tasks_generated_from_template() {
        $tpl   = WPTSALL_Scanner::generate_plugin_template( 'woocommerce', -1 );
        $tasks = wptsall_generate_tasks_from_template( $tpl, 1, null );
        $this->assertIsArray( $tasks );
        $this->assertNotEmpty( $tasks );
        $task = $tasks[0];
        $this->assertArrayHasKey( 'object_type', $task );
        $this->assertArrayHasKey( 'object_id', $task );
    }
}

          <?php
          // A new subsite under a network-active install must get its own
          // wptsall table family (wp_initialize_site -> handle_new_site).
          // The subsite id is derived as MAX(blog_id) on this fresh network
          // (main = 1, subsite = 2); wp eval-file exposes no $argv.
          global $wpdb;
          $blog_id = (int) $wpdb->get_var( "SELECT MAX(blog_id) FROM {$wpdb->blogs}" );
          if ( $blog_id < 2 ) {
            fwrite( STDERR, "no subsite was created (max blog_id=$blog_id)\n" );
            exit( 1 );
          }
          file_put_contents( '/tmp/ms-site-id.txt', (string) $blog_id );
          switch_to_blog( $blog_id );
          $pattern = $wpdb->esc_like( $wpdb->prefix ) . 'wptsall%';
          $tables  = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pattern ) );
          restore_current_blog();
          if ( empty( $tables ) ) {
            fwrite( STDERR, "subsite $blog_id did not get its wptsall tables\n" );
            exit( 1 );
          }
          echo "ms-subsite-provisioned-ok blog=$blog_id tables=" . count( $tables ) . "\n";

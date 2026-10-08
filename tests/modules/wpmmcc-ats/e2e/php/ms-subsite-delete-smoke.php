          <?php
          // Deleting a subsite must drop that subsite's wptsall tables
          // (wpmu_drop_tables filter) while the main-site family survives.
          $blog_id = (int) file_get_contents( '/tmp/ms-site-id.txt' );
          if ( $blog_id < 2 ) {
            fwrite( STDERR, "no subsite id on record\n" );
            exit( 1 );
          }
          global $wpdb;
          $pattern = '%' . $wpdb->esc_like( 'wptsall' ) . '%';
          $tables  = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pattern ) );
          $infix   = '_' . $blog_id . '_wptsall_';
          foreach ( $tables as $table ) {
            if ( false !== strpos( (string) $table, $infix ) ) {
              fwrite( STDERR, "deleted subsite table survived: $table\n" );
              exit( 1 );
            }
          }
          $main = preg_grep( '/^' . preg_quote( $wpdb->prefix, '/' ) . 'wptsall_/', $tables );
          if ( empty( $main ) ) {
            fwrite( STDERR, "main-site wptsall tables disappeared on subsite delete\n" );
            exit( 1 );
          }
          echo 'ms-subsite-delete-ok remaining=' . count( $tables ) . "\n";

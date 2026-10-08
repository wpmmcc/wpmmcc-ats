          <?php
          // With delete_data_on_uninstall enabled, the uninstall must drop
          // the whole wptsall table family.
          global $wpdb;
          $pattern = $wpdb->esc_like( $wpdb->prefix ) . 'wptsall%';
          $tables  = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pattern ) );
          if ( ! empty( $tables ) ) {
            fwrite( STDERR, "wptsall tables survived opt-in full uninstall: " . implode( ',', $tables ) . "\n" );
            exit( 1 );
          }
          echo "uninstall-full-delete-ok\n";

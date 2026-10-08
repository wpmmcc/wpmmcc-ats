          <?php
          // With delete_data_on_uninstall enabled, the multisite uninstall
          // must leave no wptsall table anywhere in the network.
          global $wpdb;
          $pattern = '%' . $wpdb->esc_like( 'wptsall' ) . '%';
          $tables  = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pattern ) );
          if ( ! empty( $tables ) ) {
            fwrite( STDERR, "wptsall tables survived opt-in full multisite uninstall: " . implode( ',', $tables ) . "\n" );
            exit( 1 );
          }
          echo "ms-uninstall-full-delete-ok\n";

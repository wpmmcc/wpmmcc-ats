          <?php
          // Since 2.1.3 the default uninstall keeps translation data: the
          // wptsall table family must survive, while plugin settings,
          // transients and cron events are cleaned up either way.
          global $wpdb;
          $pattern = $wpdb->esc_like( $wpdb->prefix ) . 'wptsall%';
          $tables  = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pattern ) );
          if ( empty( $tables ) ) {
            fwrite( STDERR, "wptsall tables were dropped by a default (keep-data) uninstall\n" );
            exit( 1 );
          }
          if ( get_option( 'wptsall_settings' ) !== false ) {
            fwrite( STDERR, "wptsall_settings option survived uninstall\n" );
            exit( 1 );
          }
          echo 'uninstall-retention-ok tables=' . count( $tables ) . "\n";

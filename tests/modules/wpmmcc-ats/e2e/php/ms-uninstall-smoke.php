          <?php
          // Default multisite uninstall: main-site tables survive (keep-data
          // contract since 2.1.3) while per-site settings are removed.
          global $wpdb;
          $pattern = $wpdb->esc_like( $wpdb->prefix ) . 'wptsall%';
          $tables  = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pattern ) );
          if ( empty( $tables ) ) {
            fwrite( STDERR, "wptsall tables were dropped by a default (keep-data) multisite uninstall\n" );
            exit( 1 );
          }
          if ( get_option( 'wptsall_settings' ) !== false ) {
            fwrite( STDERR, "wptsall_settings option survived multisite uninstall\n" );
            exit( 1 );
          }
          echo 'ms-uninstall-retention-ok tables=' . count( $tables ) . "\n";

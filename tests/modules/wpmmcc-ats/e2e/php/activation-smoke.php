          <?php
          // Activation smoke: version constant, REST namespace, core tables.
          if ( ! defined( 'WPTSALL_VERSION' ) ) {
            fwrite( STDERR, "WPTSALL_VERSION is not defined\n" );
            exit( 1 );
          }
          global $wpdb;
          $pattern = $wpdb->esc_like( $wpdb->prefix ) . 'wptsall%';
          $tables  = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pattern ) );
          if ( empty( $tables ) ) {
            fwrite( STDERR, "no wptsall tables exist after activation\n" );
            exit( 1 );
          }
          $routes = rest_get_server()->get_routes();
          if ( empty( $routes['/wptsall/v2'] ) ) {
            fwrite( STDERR, "wptsall/v2 REST namespace is not registered\n" );
            exit( 1 );
          }
          echo 'activation-smoke-ok version=' . WPTSALL_VERSION
            . ' tables=' . count( $tables )
            . ' routes=' . count( $routes['/wptsall/v2'] ) . "\n";

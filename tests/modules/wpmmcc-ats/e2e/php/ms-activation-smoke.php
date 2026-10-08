          <?php
          // Network activation smoke: multisite bootstrap, version constant,
          // main-site table family, REST namespace.
          if ( ! is_multisite() ) {
            fwrite( STDERR, "multisite constants are not in effect\n" );
            exit( 1 );
          }
          if ( ! defined( 'WPTSALL_VERSION' ) ) {
            fwrite( STDERR, "WPTSALL_VERSION is not defined\n" );
            exit( 1 );
          }
          global $wpdb;
          $pattern = $wpdb->esc_like( $wpdb->prefix ) . 'wptsall%';
          $tables  = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pattern ) );
          if ( empty( $tables ) ) {
            fwrite( STDERR, "no wptsall tables exist after network activation\n" );
            exit( 1 );
          }
          $routes = rest_get_server()->get_routes();
          if ( empty( $routes['/wptsall/v2'] ) ) {
            fwrite( STDERR, "wptsall/v2 REST namespace is not registered\n" );
            exit( 1 );
          }
          echo 'ms-activation-smoke-ok version=' . WPTSALL_VERSION
            . ' tables=' . count( $tables )
            . ' routes=' . count( $routes['/wptsall/v2'] ) . "\n";

<?php
/**
 * Virtual-site link filters (WP core link surface).
 *
 * Completes the WordPress request/link lifecycle for virtual sites when
 * only WPTSALL is the multilingual plugin:
 *  - home_url (with admin/REST/sitemap skip list)
 *  - nav menu URLs + lang attributes
 *  - search form
 *  - static front / posts page → shadow copies
 *  - widget display gate
 *
 * Modeled on Polylang's PLL_Frontend_Filters_Links + frontend-static-pages,
 * without peer-plugin coexistence concerns.
 *
 * @package WPTSALL\Hooks
 * @since 2.2.0
 */

namespace WPTSALL\Hooks;

use WPTSALL\Sites\Services\Url_Converter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Virtual_Site_Link_Filters class.
 */
class Virtual_Site_Link_Filters {

	/**
	 * Register frontend link filters (call from Router after VS list known).
	 *
	 * @return void
	 */
	public static function init() {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		add_filter( 'home_url', array( __CLASS__, 'filter_home_url' ), 10, 2 );

		// Menu: rewrite item URLs + lang attribute (Polylang 5-piece subset).
		add_filter( 'wp_nav_menu_objects', array( __CLASS__, 'filter_nav_menu_objects' ), 20, 2 );
		add_filter( 'nav_menu_link_attributes', array( __CLASS__, 'filter_nav_menu_link_attributes' ), 10, 3 );
		add_filter( 'wp_nav_menu_args', array( __CLASS__, 'filter_nav_menu_args' ), 10 );
		add_filter( 'get_search_form', array( __CLASS__, 'filter_search_form' ), 99 );
		// Block themes (TT5): core/search often ships action="#" — localize via render_block.
		add_filter( 'render_block_core/search', array( __CLASS__, 'filter_search_block' ), 99, 2 );
		// WooCommerce product search form — thin third-party adapter (Polylang-style form rewrite).
		add_filter( 'get_product_search_form', array( __CLASS__, 'filter_search_form' ), 99 );
		add_filter( 'render_block_woocommerce/product-search', array( __CLASS__, 'filter_search_block' ), 99, 2 );

		// Static front / blog page → shadow IDs when available.
		add_filter( 'option_page_on_front', array( __CLASS__, 'filter_page_on_front' ) );
		add_filter( 'option_page_for_posts', array( __CLASS__, 'filter_page_for_posts' ) );

		// Widgets: skip instances marked for a different virtual site.
		add_filter( 'widget_display_callback', array( __CLASS__, 'filter_widget_display' ), 10, 3 );
	}

	/**
	 * Prefix home_url when serving a virtual site.
	 *
	 * @param string $url  Home URL.
	 * @param string $path Relative path.
	 * @return string
	 */
	public static function filter_home_url( $url, $path = '' ) {
		$vs = Virtual_Site_Router::get_current_virtual_site();
		if ( ! $vs ) {
			return $url;
		}

		/**
		 * Escape hatch: return false to skip home_url virtualization.
		 *
		 * @since 2.2.0
		 * @param bool   $do_filter Whether to filter.
		 * @param string $url       Current URL.
		 * @param string $path      Path argument.
		 */
		if ( ! apply_filters( 'wptsall_filter_home_url', true, $url, $path ) ) {
			return $url;
		}

		if ( self::should_skip_home_url( $url, $path ) ) {
			return $url;
		}

		// Polylang pattern: only rewrite when the result is the bare home URL.
		// home_url( '/foo' ) and SEO helpers that append a path must stay unprefixed
		// here so callers can virtualize once via Url_Converter / permalink filters.
		$bare_home = untrailingslashit( (string) get_option( 'home' ) );
		if ( untrailingslashit( (string) $url ) !== $bare_home ) {
			return $url;
		}

		// Prefer after main query is settled (avoids early bootstrap side effects).
		if ( ! did_action( 'template_redirect' ) && ! did_action( 'login_init' ) ) {
			return $url;
		}

		$prefix = trim( (string) ( $vs['path_prefix'] ?? '' ), '/' );
		if ( '' === $prefix ) {
			return $url;
		}
		return trailingslashit( $bare_home . '/' . $prefix );
	}

	/**
	 * Whether home_url must not be rewritten (admin, REST, sitemap, files).
	 *
	 * @param string $url  URL.
	 * @param string $path Path.
	 * @return bool
	 */
	public static function should_skip_home_url( $url, $path = '' ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return true;
		}
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( defined( 'DOING_CRON' ) && DOING_CRON )
			|| ( defined( 'WP_CLI' ) && WP_CLI )
			|| ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
			return true;
		}

		$check = (string) $path;
		if ( '' === $check ) {
			$check = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		}
		$check = strtolower( $check );

		if ( false !== strpos( $check, 'sitemap' ) && ( false !== strpos( $check, '.xml' ) || false !== strpos( $check, '.xsl' ) ) ) {
			return true;
		}
		if ( preg_match( '/\.(css|js|png|jpe?g|gif|svg|webp|ico|woff2?|ttf|eot|map)(\?|$)/i', $check ) ) {
			return true;
		}

		/**
		 * Filter whether to skip home_url rewriting.
		 *
		 * @since 2.2.0
		 * @param bool   $skip Skip.
		 * @param string $url  URL.
		 * @param string $path Path.
		 */
		return (bool) apply_filters( 'wptsall_skip_home_url_filter', false, $url, $path );
	}

	/**
	 * Rewrite menu item URLs to the current virtual prefix.
	 *
	 * @param array    $items Menu items.
	 * @param \stdClass $args Menu args.
	 * @return array
	 */
	public static function filter_nav_menu_objects( $items, $args ) {
		$vs = Virtual_Site_Router::get_current_virtual_site();
		if ( ! $vs || empty( $items ) ) {
			return $items;
		}
		$home_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		foreach ( $items as &$item ) {
			if ( empty( $item->url ) ) {
				continue;
			}
			$url_host = (string) wp_parse_url( (string) $item->url, PHP_URL_HOST );
			if ( '' !== $url_host && $url_host !== $home_host ) {
				continue;
			}
			$item->url = Url_Converter::virtualize( (string) $item->url, $vs );
		}
		unset( $item );
		return $items;
	}

	/**
	 * Add hreflang/lang on menu anchors.
	 *
	 * @param array    $atts Link attributes.
	 * @param \WP_Post $item Menu item.
	 * @param \stdClass $args Args.
	 * @return array
	 */
	public static function filter_nav_menu_link_attributes( $atts, $item, $args ) {
		$vs = Virtual_Site_Router::get_current_virtual_site();
		if ( ! $vs ) {
			return $atts;
		}
		$lang = str_replace( '_', '-', (string) ( $vs['lang'] ?? '' ) );
		if ( '' !== $lang ) {
			$atts['hreflang'] = $lang;
			$atts['lang']     = $lang;
		}
		return $atts;
	}

	/**
	 * Ensure menu container can carry a language class.
	 *
	 * @param array $args Menu args.
	 * @return array
	 */
	public static function filter_nav_menu_args( $args ) {
		$vs = Virtual_Site_Router::get_current_virtual_site();
		if ( ! $vs ) {
			return $args;
		}
		$lang = sanitize_html_class( str_replace( '_', '-', (string) ( $vs['lang'] ?? '' ) ) );
		if ( '' === $lang ) {
			return $args;
		}
		$extra = ' wptsall-menu-lang-' . $lang;
		if ( empty( $args['menu_class'] ) ) {
			$args['menu_class'] = 'menu' . $extra;
		} elseif ( false === strpos( (string) $args['menu_class'], 'wptsall-menu-lang-' ) ) {
			$args['menu_class'] .= $extra;
		}
		return $args;
	}

	/**
	 * Keep searches inside the virtual site (hidden input + action URL).
	 *
	 * @param string $form Search form HTML.
	 * @return string
	 */
	public static function filter_search_form( $form ) {
		$vs = Virtual_Site_Router::get_current_virtual_site();
		if ( ! $vs || ! is_string( $form ) || '' === $form ) {
			return $form;
		}
		return self::localize_search_form_html( $form, $vs );
	}

	/**
	 * Localize core/search block markup under the current virtual site.
	 *
	 * @param string $block_content Block HTML.
	 * @param array  $block         Parsed block.
	 * @return string
	 */
	public static function filter_search_block( $block_content, $block = null ) {
		unset( $block );
		$vs = Virtual_Site_Router::get_current_virtual_site();
		if ( ! $vs || ! is_string( $block_content ) || '' === $block_content ) {
			return $block_content;
		}
		return self::localize_search_form_html( $block_content, $vs );
	}

	/**
	 * Localize every WP search form in a HTML document (output-buffer safety net).
	 *
	 * Themes that omit get_search_form() but embed core/search (or classic forms)
	 * still need action + wptsall_vs under the virtual prefix. Matches forms that
	 * look like WordPress search — not arbitrary site forms.
	 *
	 * @param string $html Full HTML.
	 * @param array  $vs   Virtual site row.
	 * @return string
	 */
	public static function localize_search_forms_in_html( $html, array $vs ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}
		return (string) preg_replace_callback(
			'/<form\b[^>]*>[\s\S]*?<\/form>/i',
			static function ( $m ) use ( $vs ) {
				$form = $m[0];
				if (
					! preg_match( '/role=["\']search["\']/i', $form )
					&& ! preg_match( '/name=["\']s["\']/i', $form )
					&& ! preg_match( '/wp-block-search/i', $form )
					&& ! preg_match( '/woocommerce-product-search/i', $form )
					&& ! preg_match( '/wc-block-product-search/i', $form )
					&& ! preg_match( '/class=["\'][^"\']*search-form/i', $form )
				) {
					return $form;
				}
				return self::localize_search_form_html( $form, $vs );
			},
			$html
		);
	}

	/**
	 * Rewrite search form action + inject VS hidden field.
	 *
	 * @param string $html Form HTML.
	 * @param array  $vs   Virtual site row.
	 * @return string
	 */
	public static function localize_search_form_html( $html, array $vs ) {
		$home = esc_url( Url_Converter::home_for_virtual_site( $vs ) );
		// Replace empty / "#" / relative actions; leave absolute off-site alone.
		$html = preg_replace_callback(
			'/action=(["\'])([^"\']*)\1/i',
			static function ( $m ) use ( $home ) {
				$current = trim( (string) $m[2] );
				if ( '' === $current || '#' === $current || 0 === strpos( $current, '?' ) ) {
					return 'action=' . $m[1] . $home . $m[1];
				}
				// Same-host relative or already virtualized — force VS home so queries stay scoped.
				if ( 0 === strpos( $current, '/' ) || false === strpos( $current, '://' ) ) {
					return 'action=' . $m[1] . $home . $m[1];
				}
				$home_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
				$url_host  = (string) wp_parse_url( $current, PHP_URL_HOST );
				if ( '' === $url_host || $url_host === $home_host ) {
					return 'action=' . $m[1] . $home . $m[1];
				}
				return $m[0];
			},
			$html,
			1
		);
		$prefix = esc_attr( trim( (string) ( $vs['path_prefix'] ?? '' ), '/' ) );
		if ( '' !== $prefix && false === strpos( $html, 'name="wptsall_vs"' ) && false !== strpos( $html, '</form>' ) ) {
			$html = str_replace( '</form>', '<input type="hidden" name="wptsall_vs" value="' . $prefix . '" /></form>', $html );
		}
		return $html;
	}

	/**
	 * Map static front page option to shadow copy on virtual requests.
	 *
	 * @param mixed $value Page ID.
	 * @return mixed
	 */
	public static function filter_page_on_front( $value ) {
		return self::map_static_page_id( $value );
	}

	/**
	 * Map posts page option to shadow copy on virtual requests.
	 *
	 * @param mixed $value Page ID.
	 * @return mixed
	 */
	public static function filter_page_for_posts( $value ) {
		return self::map_static_page_id( $value );
	}

	/**
	 * Resolve source page ID → shadow post ID for current virtual site.
	 *
	 * @param mixed $page_id Page ID.
	 * @return mixed
	 */
	protected static function map_static_page_id( $page_id ) {
		$vs = Virtual_Site_Router::get_current_virtual_site();
		$page_id = (int) $page_id;
		if ( ! $vs || $page_id <= 0 ) {
			return $page_id;
		}
		$shadow = Virtual_Site_Query_Switch::find_shadow_post_id( $page_id, $vs );
		return $shadow > 0 ? $shadow : $page_id;
	}

	/**
	 * Hide widgets tagged for a different virtual site.
	 *
	 * Instance key `_wptsall_virtual_site_id`: empty = all sites; else must match.
	 *
	 * @param array     $instance Widget instance.
	 * @param \WP_Widget $widget  Widget object.
	 * @param array     $args     Sidebar args.
	 * @return array|false
	 */
	public static function filter_widget_display( $instance, $widget, $args ) {
		$vs = Virtual_Site_Router::get_current_virtual_site();
		if ( ! $vs || ! is_array( $instance ) ) {
			return $instance;
		}
		$bound = isset( $instance['_wptsall_virtual_site_id'] ) ? (string) $instance['_wptsall_virtual_site_id'] : '';
		if ( '' === $bound ) {
			return $instance;
		}
		$current = (string) ( $vs['id'] ?? '' );
		if ( $bound !== $current ) {
			return false;
		}
		return $instance;
	}
}

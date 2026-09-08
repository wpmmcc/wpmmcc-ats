<?php
/**
 * WPTSALL Admin Page Helper
 *
 * Provides unified page structure for all admin pages.
 * Ensures consistent HTML structure for WordPress admin notices to work correctly.
 *
 * WordPress Admin Page Pattern:
 * <div class="wrap">
 *     <h1 class="wp-heading-inline">Title</h1>
 *     <a href="#" class="page-title-action">Action</a>
 *     <hr class="wp-header-end">
 *     <!-- WordPress injects admin notices HERE -->
 *     <!-- Page content -->
 * </div>
 *
 * The `<hr class="wp-header-end">` is CRITICAL - without it, admin notices
 * from WordPress core and other plugins will appear in unpredictable locations.
 *
 * @package WPTSALL
 * @since 0.9.3
 */

namespace WPTSALL\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin Page Helper Class
 *
 * Usage:
 *
 * // Simple page
 * Admin_Page_Helper::render_header( 'Page Title', 'module-name' );
 * // ... page content ...
 * Admin_Page_Helper::render_footer();
 *
 * // With action buttons
 * Admin_Page_Helper::render_header( 'Page Title', 'module-name', array(
 *     array(
 *         'label' => 'Add New',
 *         'url'   => admin_url( 'admin.php?page=xxx&action=new' ),
 *     ),
 *     array(
 *         'label' => 'Import',
 *         'url'   => '#',
 *         'id'    => 'import-btn',
 *     ),
 * ) );
 *
 * // With tabs
 * Admin_Page_Helper::render_header( 'Page Title', 'module-name', $actions );
 * Admin_Page_Helper::render_tabs( $tabs, $active_tab, $base_url );
 * // ... tab content ...
 * Admin_Page_Helper::render_footer();
 */
class Admin_Page_Helper {
	/**
	 * Track whether page box wrapper is open.
	 *
	 * @var bool
	 */
	private static $page_box_open = false;

	/**
	 * Render page header
	 *
	 * Outputs the standard WordPress admin page header structure:
	 * - wrap div with module-specific class
	 * - h1 with wp-heading-inline class
	 * - action buttons (page-title-action)
	 * - hr.wp-header-end (critical for admin notices placement)
	 *
	 * @param string $title       Page title.
	 * @param string $module      Module identifier (e.g., 'models', 'sites', 'tasks').
	 * @param array  $actions     Optional action buttons. Each item: array( 'label' => '', 'url' => '', 'id' => '', 'class' => '' ).
	 * @param array  $extra_class Optional extra CSS classes for wrap div.
	 */
	public static function render_header( $title, $module = '', $actions = array(), $extra_class = array(), $options = array() ) {
		$wrap_classes = array( 'wrap', 'wptsall-admin-page' );
		$defaults     = array(
			'use_wp_heading' => true,  // WordPress standard: h1 outside content area.
			'page_box'       => false, // WordPress standard: no box wrapper.
			'page_header'    => false, // WordPress standard: no custom header.
			'page_title'     => $title,
			'actions_style'  => 'wp',  // WordPress standard: page-title-action buttons.
		);
		$options = wp_parse_args( $options, $defaults );

		if ( ! empty( $module ) ) {
			$wrap_classes[] = 'wptsall-' . sanitize_html_class( $module ) . '-page';
		}

		if ( ! empty( $extra_class ) ) {
			$wrap_classes = array_merge( $wrap_classes, (array) $extra_class );
		}

		echo '<div class="' . esc_attr( implode( ' ', $wrap_classes ) ) . '">';

		if ( $options['use_wp_heading'] ) {
			echo '<h1 class="wp-heading-inline">' . esc_html( $title ) . '</h1>';

			if ( 'wp' === $options['actions_style'] && ! empty( $actions ) ) {
				foreach ( $actions as $action ) {
					$label = $action['label'] ?? '';
					$url   = $action['url'] ?? '#';
					$id    = isset( $action['id'] ) ? ' id="' . esc_attr( $action['id'] ) . '"' : '';
					$class = 'page-title-action';
					if ( ! empty( $action['class'] ) ) {
						$class .= ' ' . $action['class'];
					}
					$attrs = '';
					if ( ! empty( $action['attrs'] ) && is_array( $action['attrs'] ) ) {
						foreach ( $action['attrs'] as $attr_key => $attr_value ) {
							$attrs .= ' ' . esc_attr( $attr_key ) . '="' . esc_attr( $attr_value ) . '"';
						}
					}

					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $id and $attrs are pre-escaped via esc_attr()
					echo '<a href="' . esc_url( $url ) . '"' . $id . ' class="' . esc_attr( $class ) . '"' . $attrs . '>';
					echo esc_html( $label );
					echo '</a>';
				}
			}
		}

		// CRITICAL: This hr element tells WordPress where to inject admin notices
		echo '<hr class="wp-header-end">';

		if ( $options['page_box'] ) {
			echo '<div class="wptsall-page-box">';

			if ( $options['page_header'] ) {
				echo '<div class="wptsall-page-header">';
				echo '<div class="wptsall-page-title">' . esc_html( $options['page_title'] ) . '</div>';

				if ( ! empty( $actions ) ) {
					echo '<div class="wptsall-header-actions">';
					foreach ( $actions as $action ) {
						$label = $action['label'] ?? '';
						$url   = $action['url'] ?? '#';
						$id    = isset( $action['id'] ) ? ' id="' . esc_attr( $action['id'] ) . '"' : '';
						$class = $action['class'] ?? 'button';
						$attrs = '';
						if ( ! empty( $action['attrs'] ) && is_array( $action['attrs'] ) ) {
							foreach ( $action['attrs'] as $attr_key => $attr_value ) {
								$attrs .= ' ' . esc_attr( $attr_key ) . '="' . esc_attr( $attr_value ) . '"';
							}
						}
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $id and $attrs are pre-escaped via esc_attr()
						echo '<a href="' . esc_url( $url ) . '"' . $id . ' class="' . esc_attr( $class ) . '"' . $attrs . '>';
						echo esc_html( $label );
						echo '</a>';
					}
					echo '</div>';
				}

				echo '</div>';
			}

			self::$page_box_open = true;
		}
	}

	/**
	 * Render page footer
	 *
	 * Closes the wrap div opened by render_header().
	 */
	public static function render_footer() {
		if ( self::$page_box_open ) {
			echo '</div>'; // .wptsall-page-box
			self::$page_box_open = false;
		}
		echo '</div>'; // .wrap
	}

	/**
	 * Render tab navigation
	 *
	 * Outputs WordPress-style nav-tab-wrapper.
	 *
	 * @param array  $tabs       Array of tabs: array( 'tab_id' => 'Tab Label', ... ).
	 * @param string $active_tab Currently active tab ID.
	 * @param string $base_url   Base URL for tab links (without 'tab' parameter).
	 */
	public static function render_tabs( $tabs, $active_tab, $base_url, $style = 'wpmmcc-ats' ) {
		if ( empty( $tabs ) ) {
			return;
		}

		if ( 'wp' === $style ) {
			echo '<nav class="nav-tab-wrapper wptsall-tabs">';
			foreach ( $tabs as $tab_id => $tab_label ) {
				$url   = add_query_arg( 'tab', $tab_id, $base_url );
				$class = ( $active_tab === $tab_id ) ? 'nav-tab nav-tab-active' : 'nav-tab';

				echo '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $class ) . '">';
				echo esc_html( $tab_label );
				echo '</a>';
			}
			echo '</nav>';
			return;
		}

		echo '<nav class="wptsall-page-tabs">';

		foreach ( $tabs as $tab_id => $tab_label ) {
			$url   = add_query_arg( 'tab', $tab_id, $base_url );
			$class = ( $active_tab === $tab_id ) ? 'wptsall-tab active' : 'wptsall-tab';

			echo '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $class ) . '">';
			echo esc_html( $tab_label );
			echo '</a>';
		}
		echo '</nav>';
	}

	/**
	 * Render tab content wrapper opening tag
	 *
	 * @param string $tab_id Optional tab ID for CSS class.
	 */
	public static function render_tab_content_start( $tab_id = '' ) {
		$class = 'wptsall-tab-content';
		if ( ! empty( $tab_id ) ) {
			$class .= ' wptsall-tab-' . sanitize_html_class( $tab_id );
		}
		echo '<div class="' . esc_attr( $class ) . '">';
	}

	/**
	 * Render tab content wrapper closing tag
	 */
	public static function render_tab_content_end() {
		echo '</div>';
	}

	/**
	 * Render a notice
	 *
	 * @param string $message Notice message.
	 * @param string $type    Notice type: 'success', 'error', 'warning', 'info'.
	 * @param bool   $dismissible Whether the notice is dismissible.
	 */
	public static function render_notice( $message, $type = 'info', $dismissible = true ) {
		$class = 'notice notice-' . sanitize_html_class( $type );
		if ( $dismissible ) {
			$class .= ' is-dismissible';
		}

		echo '<div class="' . esc_attr( $class ) . '">';
		echo '<p>' . esc_html( $message ) . '</p>';
		echo '</div>';
	}

	/**
	 * Render empty state
	 *
	 * Shows a message when there's no data to display.
	 *
	 * @param string $message     Primary message.
	 * @param string $description Optional secondary description.
	 * @param array  $actions     Optional action buttons.
	 */
	public static function render_empty_state( $message, $description = '', $actions = array() ) {
		echo '<div class="wptsall-empty-state">';
		echo '<p class="wptsall-empty-message">' . esc_html( $message ) . '</p>';

		if ( ! empty( $description ) ) {
			echo '<p class="wptsall-empty-description">' . esc_html( $description ) . '</p>';
		}

		if ( ! empty( $actions ) ) {
			echo '<p class="wptsall-empty-actions">';
			foreach ( $actions as $action ) {
				$label = $action['label'] ?? '';
				$url   = $action['url'] ?? '#';
				$class = $action['class'] ?? 'button';
				$id    = isset( $action['id'] ) ? ' id="' . esc_attr( $action['id'] ) . '"' : '';

				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $id is pre-escaped via esc_attr()
				echo '<a href="' . esc_url( $url ) . '"' . $id . ' class="' . esc_attr( $class ) . '">';
				echo esc_html( $label );
				echo '</a> ';
			}
			echo '</p>';
		}

		echo '</div>';
	}

	/**
	 * Render page with standard structure
	 *
	 * Convenience method that wraps header, content callback, and footer.
	 *
	 * @param string   $title    Page title.
	 * @param string   $module   Module identifier.
	 * @param callable $callback Content rendering callback.
	 * @param array    $actions  Optional action buttons.
	 */
	public static function render_page( $title, $module, $callback, $actions = array() ) {
		if ( ! wptsall_user_can_manage_translations() ) {
			return;
		}

		self::render_header( $title, $module, $actions );

		if ( is_callable( $callback ) ) {
			call_user_func( $callback );
		}

		self::render_footer();
	}

	/**
	 * Render tabbed page with standard structure
	 *
	 * @param string $title      Page title.
	 * @param string $module     Module identifier.
	 * @param array  $tabs       Tabs definition.
	 * @param string $active_tab Active tab ID.
	 * @param string $base_url   Base URL for tabs.
	 * @param array  $callbacks  Array of tab_id => callable.
	 * @param array  $actions    Optional action buttons.
	 */
	public static function render_tabbed_page( $title, $module, $tabs, $active_tab, $base_url, $callbacks, $actions = array() ) {
		if ( ! wptsall_user_can_manage_translations() ) {
			return;
		}

		self::render_header( $title, $module, $actions );
		self::render_tabs( $tabs, $active_tab, $base_url );
		self::render_tab_content_start( $active_tab );

		if ( isset( $callbacks[ $active_tab ] ) && is_callable( $callbacks[ $active_tab ] ) ) {
			call_user_func( $callbacks[ $active_tab ] );
		}

		self::render_tab_content_end();
		self::render_footer();
	}
}

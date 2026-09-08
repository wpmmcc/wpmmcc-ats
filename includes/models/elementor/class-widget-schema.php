<?php
/**
 * Elementor Widget Translation Schema
 *
 * P1-1: Defines which fields inside Elementor's _elementor_data JSON
 * are translatable, per widget type. This mirrors WPML's per-widget
 * integration approach (11 widget module classes) but uses a declarative
 * schema table instead of one class per widget.
 *
 * Usage by Field_Processor when content_format === 'json_structured'
 * and the field is _elementor_data:
 *   1. Parse JSON
 *   2. Walk elements recursively
 *   3. For each element, check widget type against schema
 *   4. Extract/translate only the declared translatable paths
 *   5. Rebuild JSON with translated values
 *
 * @package WPTSALL\Models\Elementor
 * @since 1.5.1
 */

namespace WPTSALL\Models\Elementor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Widget_Schema {

	/**
	 * Translatable field paths per Elementor widget type.
	 *
	 * Format: widget_type => [ path_in_settings => content_format ]
	 *
	 * Paths use dot notation to navigate the settings object:
	 *   'title'         → $settings['title']
	 *   'description'   → $settings['description']
	 *   'tabs.tab_title' → $settings['tabs'][*]['tab_title'] (repeatable)
	 *
	 * @var array<string,array<string,string>>
	 */
	private static $schema = array(
		// Basic widgets
		'heading'       => array( 'title' => 'plain_text' ),
		'text-editor'   => array( 'editor' => 'rich_html' ),
		'button'        => array( 'text' => 'plain_text' ),

		// Image widgets (alt text, caption — image URL is id_mapping)
		'image'         => array( 'caption' => 'plain_text', 'alt' => 'plain_text' ),
		'gallery'       => array(),

		// Accordion / Tabs / Toggle (repeater fields)
		'accordion'     => array( 'tabs.tab_title' => 'plain_text', 'tabs.tab_content' => 'rich_html' ),
		'tabs'          => array( 'tabs.tab_title' => 'plain_text', 'tabs.tab_content' => 'rich_html' ),
		'toggle'        => array( 'tabs.tab_title' => 'plain_text', 'tabs.tab_content' => 'rich_html' ),

		// Icon list
		'icon-list'     => array( 'icon_list.text' => 'plain_text' ),

		// Slides / Carousel
		'slides'        => array( 'slides.title' => 'plain_text', 'slides.description' => 'plain_text', 'slides.button_text' => 'plain_text' ),
		'testimonial-carousel' => array( 'slides.testimonial_name' => 'plain_text', 'slides.testimonial_job' => 'plain_text', 'slides.testimonial_content' => 'rich_html' ),

		// Price table
		'price-table'   => array(
			'heading'       => 'plain_text',
			'sub_heading'   => 'plain_text',
			'price_description' => 'plain_text',
			'button_text'   => 'plain_text',
			'price_list.title'  => 'plain_text',  // repeater items
			'price_list.item_description' => 'plain_text',
		),

		// Counter / Progress
		'counter'       => array( 'suffix' => 'plain_text', 'title' => 'plain_text' ),
		'progress'      => array( 'title' => 'plain_text', 'inner_text' => 'plain_text' ),

		// Alert
		'alert'         => array( 'alert_title' => 'plain_text', 'alert_description' => 'rich_html' ),

		// Video (no translatable text besides caption)
		'video'         => array(),

		// Divider / Spacer / Google Maps (no text)
		'divider'       => array(),
		'spacer'        => array(),
		'google_maps'   => array(),

		// Social icons
		'social-icons'  => array( 'social_icon_list.text' => 'plain_text' ),

		// Star rating
		'star-rating'   => array( 'title' => 'plain_text' ),
	);

	/**
	 * Check if a widget type has a schema entry.
	 *
	 * @param string $widget_type Elementor widget type (elType=widget, widgetType=X).
	 * @return bool
	 */
	public static function has_schema( string $widget_type ): bool {
		return isset( self::$schema[ $widget_type ] );
	}

	/**
	 * Get the translatable field paths for a widget type.
	 *
	 * @param string $widget_type Elementor widget type.
	 * @return array<string,string> path => content_format.
	 */
	public static function get_translatable_paths( string $widget_type ): array {
		return self::$schema[ $widget_type ] ?? array();
	}

	/**
	 * Extract translatable strings from an Elementor data JSON.
	 *
	 * Walks the element tree, finds widgets with known schema, and
	 * extracts text values from the declared paths.
	 *
	 * @param string $json_raw Raw _elementor_data JSON string.
	 * @return array<array{path:string,text:string,format:string}> List of translatable strings.
	 */
	public static function extract_translatable_strings( string $json_raw ): array {
		$data = json_decode( $json_raw, true );
		if ( ! is_array( $data ) ) {
			return array();
		}

		$strings = array();
		self::walk_elements( $data, '', $strings );
		return $strings;
	}

	/**
	 * Recursively walk Elementor elements and extract translatable text.
	 *
	 * @param array  $elements Element array (can contain 'elements' children).
	 * @param string $prefix   Path prefix for nested context.
	 * @param array  $strings  Output collection.
	 */
	private static function walk_elements( array $elements, string $prefix, array &$strings ): void {
		foreach ( $elements as $index => $element ) {
			$el_type = $element['elType'] ?? '';
			$widget_type = $element['widgetType'] ?? '';
			$settings = $element['settings'] ?? array();
			$children = $element['elements'] ?? array();

			// If this is a widget with a known schema, extract text.
			if ( 'widget' === $el_type && self::has_schema( $widget_type ) ) {
				$paths = self::get_translatable_paths( $widget_type );
				foreach ( $paths as $path => $format ) {
					self::extract_path( $settings, $path, $format, "{$prefix}{$widget_type}[{$index}].", $strings );
				}
			}

			// Recurse into container/column/section children.
			if ( ! empty( $children ) ) {
				self::walk_elements( $children, "{$prefix}{$el_type}[{$index}].", $strings );
			}
		}
	}

	/**
	 * Extract a single field path from settings.
	 *
	 * Handles dot notation for repeater fields:
	 *   'tabs.tab_title' → $settings['tabs'][*]['tab_title']
	 *
	 * @param array  $settings Widget settings array.
	 * @param string $path     Dot-separated path.
	 * @param string $format   Content format (plain_text/rich_html).
	 * @param string $prefix   Path prefix for context.
	 * @param array  $strings  Output collection.
	 */
	private static function extract_path( array $settings, string $path, string $format, string $prefix, array &$strings ): void {
		$parts = explode( '.', $path );
		$first = $parts[0];

		if ( count( $parts ) === 1 ) {
			// Simple path: $settings['title']
			if ( isset( $settings[ $first ] ) && is_string( $settings[ $first ] ) && '' !== trim( $settings[ $first ] ) ) {
				$strings[] = array(
					'path'   => $prefix . $path,
					'text'   => $settings[ $first ],
					'format' => $format,
				);
			}
			return;
		}

		// Repeater path: 'tabs.tab_title' → $settings['tabs'][*]['tab_title']
		if ( isset( $settings[ $first ] ) && is_array( $settings[ $first ] ) ) {
			$sub_path = implode( '.', array_slice( $parts, 1 ) );
			foreach ( $settings[ $first ] as $i => $item ) {
				if ( is_array( $item ) ) {
					self::extract_path( $item, $sub_path, $format, "{$prefix}{$first}[{$i}].", $strings );
				}
			}
		}
	}

	/**
	 * Apply translations back into Elementor data JSON.
	 *
	 * Takes the original JSON and a map of path => translated_text,
	 * walks the tree, and replaces text at matching paths.
	 *
	 * @param string                $json_raw    Original _elementor_data JSON.
	 * @param array<string,string>  $translations Map of path => translated text.
	 * @return string Modified JSON.
	 */
	public static function apply_translations( string $json_raw, array $translations ): string {
		$data = json_decode( $json_raw, true );
		if ( ! is_array( $data ) ) {
			return $json_raw;
		}

		self::apply_to_elements( $data, $translations );
		return wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Recursively apply translations to elements.
	 *
	 * @param array               $elements     Element array.
	 * @param array<string,string> $translations Path => translated text map.
	 */
	private static function apply_to_elements( array &$elements, array $translations ): void {
		foreach ( $elements as &$element ) {
			$el_type     = $element['elType'] ?? '';
			$widget_type = $element['widgetType'] ?? '';
			$settings    = &$element['settings'];
			$children    = $element['elements'] ?? array();

			if ( 'widget' === $el_type && self::has_schema( $widget_type ) && is_array( $settings ) ) {
				$paths = self::get_translatable_paths( $widget_type );
				foreach ( $paths as $path => $format ) {
					self::apply_path( $settings, $path, $translations );
				}
			}

			if ( ! empty( $children ) ) {
				self::apply_to_elements( $element['elements'], $translations );
			}
		}
	}

	/**
	 * Apply translation to a single path in settings.
	 *
	 * @param array               $settings     Widget settings (passed by reference).
	 * @param string              $path         Dot-separated path.
	 * @param array<string,string> $translations Path => translated text map.
	 */
	private static function apply_path( array &$settings, string $path, array $translations ): void {
		$parts = explode( '.', $path );
		$first = $parts[0];

		if ( count( $parts ) === 1 ) {
			if ( isset( $settings[ $first ] ) && is_string( $settings[ $first ] ) ) {
				$current = $settings[ $first ];
				// Match by value (the translation map keys are original text).
				if ( isset( $translations[ $current ] ) ) {
					$settings[ $first ] = $translations[ $current ];
				}
			}
			return;
		}

		if ( isset( $settings[ $first ] ) && is_array( $settings[ $first ] ) ) {
			$sub_path = implode( '.', array_slice( $parts, 1 ) );
			foreach ( $settings[ $first ] as &$item ) {
				if ( is_array( $item ) ) {
					self::apply_path( $item, $sub_path, $translations );
				}
			}
		}
	}

	/**
	 * Allow external code to register additional widget schemas.
	 *
	 * @param string              $widget_type Elementor widget type.
	 * @param array<string,string> $paths      Path => content_format map.
	 * @return void
	 */
	public static function register_schema( string $widget_type, array $paths ): void {
		if ( ! isset( self::$schema[ $widget_type ] ) ) {
			self::$schema[ $widget_type ] = array();
		}
		self::$schema[ $widget_type ] = array_merge( self::$schema[ $widget_type ], $paths );
	}
}

<?php
/**
 * Peer language-config reader (WPML XML + WP Multilang JSON + bundled hints).
 *
 * Industry plugins declare translatable fields in wpml-config.xml (WPML)
 * or wpm-config.json (WP Multilang). This reader normalizes those files
 * plus our bundled peer hints so scan / classify / rule generation can
 * suggest mappings without stacking per-plugin frontend hooks.
 *
 * @package WPTSALL\Core
 * @since 2.1.0
 */

namespace WPTSALL\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPML_Config_Reader {

	/**
	 * Cached normalized configs keyed by provider slug.
	 *
	 * @var array<string,array>|null
	 */
	private static $configs = null;

	/**
	 * Reset cache (tests).
	 *
	 * @return void
	 */
	public static function reset_cache() {
		self::$configs = null;
	}

	/**
	 * Get all normalized peer configs.
	 *
	 * Each entry:
	 * - custom-types: name => translate|ignore
	 * - custom-fields: key => translate|sync|skip
	 * - taxonomies: name => translate|ignore
	 * - source: wpml_config_xml|wpm_config_json|bundled_peer
	 *
	 * @return array<string,array>
	 */
	public static function get_all_configs() {
		if ( null !== self::$configs ) {
			return self::$configs;
		}

		$configs = array();

		foreach ( self::discover_config_files() as $provider => $file ) {
			$parsed = self::parse_config_file( $file );
			if ( empty( $parsed ) ) {
				continue;
			}
			$parsed['source'] = self::source_label( $file );
			$configs[ $provider ] = self::merge_config( $configs[ $provider ] ?? array(), $parsed );
		}

		/**
		 * Filter normalized peer language configs.
		 *
		 * @param array $configs Provider-keyed configs.
		 */
		self::$configs = apply_filters( 'wptsall_peer_language_configs', $configs );
		return self::$configs;
	}

	/**
	 * Flatten custom-field actions across all providers.
	 *
	 * Later providers do not override an earlier explicit action unless
	 * the later source is a live plugin XML (higher authority than bundled).
	 *
	 * @return array<string,string> Field key => action.
	 */
	public static function get_all_custom_fields() {
		$fields = array();
		foreach ( self::get_all_configs() as $config ) {
			$source = (string) ( $config['source'] ?? '' );
			foreach ( (array) ( $config['custom-fields'] ?? array() ) as $key => $action ) {
				$key    = (string) $key;
				$action = self::normalize_action( $action );
				if ( '' === $key || '' === $action ) {
					continue;
				}
				if ( ! isset( $fields[ $key ] ) || 'wpml_config_xml' === $source ) {
					$fields[ $key ] = $action;
				}
			}
		}
		return $fields;
	}

	/**
	 * Action for one meta key, or null.
	 *
	 * @param string $field_name Meta key.
	 * @return string|null
	 */
	public static function get_field_action( $field_name ) {
		$field_name = (string) $field_name;
		$all        = self::get_all_custom_fields();
		return $all[ $field_name ] ?? null;
	}

	/**
	 * Config for one plugin slug.
	 *
	 * @param string $plugin_slug Plugin slug.
	 * @return array
	 */
	public static function get_config_for_plugin( $plugin_slug ) {
		$plugin_slug = sanitize_key( (string) $plugin_slug );
		$configs     = self::get_all_configs();
		if ( isset( $configs[ $plugin_slug ] ) ) {
			return $configs[ $plugin_slug ];
		}
		foreach ( $configs as $key => $config ) {
			if ( 0 === strpos( (string) $key, $plugin_slug ) || 0 === strpos( $plugin_slug, (string) $key ) ) {
				return $config;
			}
		}
		return array();
	}

	/**
	 * Discover config files: live plugins/themes, then bundled hints.
	 *
	 * @return array<string,string> Provider => path.
	 */
	public static function discover_config_files() {
		$files = array();

		if ( ! function_exists( 'get_plugins' ) && defined( 'ABSPATH' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( function_exists( 'get_plugins' ) && function_exists( 'wptsall_resolve_plugin_dir_by_slug' ) ) {
			foreach ( get_plugins() as $plugin_file => $unused ) {
				$slug = dirname( $plugin_file );
				if ( '.' === $slug || '' === $slug ) {
					$slug = basename( $plugin_file, '.php' );
				}
				$dir = wptsall_resolve_plugin_dir_by_slug( $slug );
				foreach ( array( 'wpml-config.xml', 'wpm-config.json' ) as $name ) {
					$path = $dir . '/' . $name;
					if ( is_readable( $path ) ) {
						$files[ $slug ] = $path;
						break;
					}
				}
			}
		}

		if ( function_exists( 'get_stylesheet_directory' ) ) {
			foreach ( array( get_stylesheet_directory(), get_template_directory() ) as $dir ) {
				$dir = (string) $dir;
				if ( '' === $dir ) {
					continue;
				}
				foreach ( array( 'wpml-config.xml', 'wpm-config.json' ) as $name ) {
					$path = $dir . '/' . $name;
					if ( is_readable( $path ) ) {
						$files[ 'theme-' . basename( $dir ) ] = $path;
						break;
					}
				}
			}
		}

		$bundled = self::bundled_dir();
		if ( is_dir( $bundled ) ) {
			$found = array_merge(
				glob( $bundled . '*.xml' ) ? glob( $bundled . '*.xml' ) : array(),
				glob( $bundled . '*.json' ) ? glob( $bundled . '*.json' ) : array()
			);
			foreach ( $found as $path ) {
				$slug = pathinfo( $path, PATHINFO_FILENAME );
				if ( ! isset( $files[ $slug ] ) ) {
					$files[ $slug ] = $path;
				}
			}
		}

		return $files;
	}

	/**
	 * Parse one XML or JSON peer config file.
	 *
	 * @param string $file Path.
	 * @return array
	 */
	public static function parse_config_file( $file ) {
		$file = (string) $file;
		if ( '' === $file || ! is_readable( $file ) ) {
			return array();
		}
		$ext = strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) );
		if ( 'json' === $ext ) {
			return self::parse_wpm_json( (string) file_get_contents( $file ) );
		}
		return self::parse_wpml_xml( (string) file_get_contents( $file ) );
	}

	/**
	 * Parse WPML language configuration XML.
	 *
	 * @param string $xml Raw XML.
	 * @return array
	 */
	public static function parse_wpml_xml( $xml ) {
		$xml = (string) $xml;
		if ( '' === trim( $xml ) || ! function_exists( 'simplexml_load_string' ) ) {
			return array();
		}
		$prev = libxml_use_internal_errors( true );
		$root = simplexml_load_string( $xml );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( ! $root ) {
			return array();
		}

		$out = array(
			'custom-types'  => array(),
			'custom-fields' => array(),
			'taxonomies'    => array(),
			'admin-texts'   => array(),
			'shortcodes'    => array(),
			'gutenberg'     => array(),
		);

		if ( isset( $root->{'custom-fields'}->{'custom-field'} ) ) {
			foreach ( $root->{'custom-fields'}->{'custom-field'} as $node ) {
				$key = sanitize_text_field( (string) $node );
				if ( '' === $key ) {
					continue;
				}
				$attrs  = $node->attributes();
				$action = isset( $attrs['action'] ) ? (string) $attrs['action'] : 'translate';
				$out['custom-fields'][ $key ] = self::normalize_action( $action );
			}
		}

		if ( isset( $root->{'custom-types'}->{'custom-type'} ) ) {
			foreach ( $root->{'custom-types'}->{'custom-type'} as $node ) {
				$name = sanitize_key( (string) $node );
				if ( '' === $name ) {
					continue;
				}
				$attrs     = $node->attributes();
				$translate = ! isset( $attrs['translate'] ) || '0' !== (string) $attrs['translate'];
				$out['custom-types'][ $name ] = $translate ? 'translate' : 'ignore';
			}
		}

		if ( isset( $root->taxonomies->taxonomy ) ) {
			foreach ( $root->taxonomies->taxonomy as $node ) {
				$name = sanitize_key( (string) $node );
				if ( '' === $name ) {
					continue;
				}
				$attrs     = $node->attributes();
				$translate = ! isset( $attrs['translate'] ) || '0' !== (string) $attrs['translate'];
				$out['taxonomies'][ $name ] = $translate ? 'translate' : 'ignore';
			}
		}

		if ( isset( $root->{'admin-texts'}->key ) ) {
			// P1-XML-01: nested <key name> trees are flattened to "a/b/c"
			// option paths so the strings/option lane addresses subkeys.
			foreach ( $root->{'admin-texts'}->key as $node ) {
				foreach ( self::flatten_admin_text_keys( $node, '' ) as $path ) {
					$out['admin-texts'][] = $path;
				}
			}
			$out['admin-texts'] = array_values( array_unique( $out['admin-texts'] ) );
		}

		// P1-XML-01: shortcodes segment — attribute-level translation
		// declarations. <shortcodes><shortcode name="x"><attribute name="y"/>
		// </shortcode></shortcodes>. An empty attribute set means the whole
		// enclosing content is translatable.
		if ( isset( $root->shortcodes->shortcode ) ) {
			foreach ( $root->shortcodes->shortcode as $node ) {
				$attrs = $node->attributes();
				$name  = isset( $attrs['name'] ) ? sanitize_text_field( (string) $attrs['name'] ) : '';
				if ( '' === $name ) {
					continue;
				}
				$attributes = array();
				if ( isset( $node->attribute ) ) {
					foreach ( $node->attribute as $attr_node ) {
						$attr_attrs = $attr_node->attributes();
						$attr_name  = isset( $attr_attrs['name'] ) ? sanitize_text_field( (string) $attr_attrs['name'] ) : '';
						if ( '' !== $attr_name ) {
							$attributes[] = $attr_name;
						}
					}
				}
				$out['shortcodes'][ $name ] = array(
					'attributes'    => array_values( array_unique( $attributes ) ),
					'whole_content' => empty( $attributes ),
				);
			}
		}

		// P1-XML-01: gutenberg segment — block attribute keys.
		// <gutenberg><block name="core/paragraph"><attribute name="content"/>
		// </block></gutenberg>.
		if ( isset( $root->gutenberg->block ) ) {
			foreach ( $root->gutenberg->block as $node ) {
				$attrs = $node->attributes();
				$name  = isset( $attrs['name'] ) ? sanitize_text_field( (string) $attrs['name'] ) : '';
				if ( '' === $name ) {
					continue;
				}
				$attributes = array();
				if ( isset( $node->attribute ) ) {
					foreach ( $node->attribute as $attr_node ) {
						$attr_attrs = $attr_node->attributes();
						$attr_name  = isset( $attr_attrs['name'] ) ? sanitize_text_field( (string) $attr_attrs['name'] ) : '';
						if ( '' !== $attr_name ) {
							$attributes[] = $attr_name;
						}
					}
				}
				$out['gutenberg'][ $name ] = array_values( array_unique( $attributes ) );
			}
		}

		return $out;
	}

	/**
	 * Recursively flatten WPML admin-texts <key name> trees into
	 * slash-separated option paths ("option/subkey/subsub").
	 *
	 * @param SimpleXMLElement $node      Current <key> node.
	 * @param string           $prefix    Path accumulated so far.
	 * @return string[] Option paths declared by this subtree.
	 */
	private static function flatten_admin_text_keys( $node, $prefix ) {
		$attrs = $node->attributes();
		$name  = isset( $attrs['name'] ) ? sanitize_text_field( (string) $attrs['name'] ) : '';
		if ( '' === $name ) {
			return array();
		}
		$path = '' === $prefix ? $name : $prefix . '/' . $name;
		// Leaf or explicit type marker: record the path.
		if ( ! isset( $node->key ) ) {
			return array( $path );
		}
		$paths = array();
		foreach ( $node->key as $child ) {
			$paths = array_merge( $paths, self::flatten_admin_text_keys( $child, $path ) );
		}
		// A node with children but no leaf action still registers the parent
		// option path itself (whole-option translatable) unless children exist.
		if ( empty( $paths ) ) {
			$paths[] = $path;
		}
		return $paths;
	}

	/**
	 * Parse WP Multilang-style JSON (empty object = translatable).
	 *
	 * @param string $json Raw JSON.
	 * @return array
	 */
	public static function parse_wpm_json( $json ) {
		$data = json_decode( (string) $json, true );
		if ( ! is_array( $data ) ) {
			return array();
		}

		$out = array(
			'custom-types'  => array(),
			'custom-fields' => array(),
			'taxonomies'    => array(),
			'admin-texts'   => array(),
		);

		foreach ( (array) ( $data['post_types'] ?? $data['custom-types'] ?? array() ) as $name => $spec ) {
			$name = sanitize_key( (string) $name );
			if ( '' !== $name ) {
				$out['custom-types'][ $name ] = self::wpm_spec_to_action( $spec );
			}
		}

		$field_bags = array( $data['post_fields'] ?? array(), $data['custom-fields'] ?? array() );
		foreach ( $field_bags as $bag ) {
			if ( ! is_array( $bag ) ) {
				continue;
			}
			foreach ( $bag as $key => $spec ) {
				$key = (string) $key;
				if ( '' === $key ) {
					continue;
				}
				$out['custom-fields'][ $key ] = self::wpm_spec_to_action( $spec );
			}
		}

		foreach ( (array) ( $data['taxonomies'] ?? array() ) as $name => $spec ) {
			$name = sanitize_key( (string) $name );
			if ( '' !== $name ) {
				$out['taxonomies'][ $name ] = self::wpm_spec_to_action( $spec );
			}
		}

		foreach ( (array) ( $data['options'] ?? $data['admin-texts'] ?? array() ) as $name => $spec ) {
			unset( $spec );
			$name = (string) $name;
			if ( '' !== $name && ! is_numeric( $name ) ) {
				$out['admin-texts'][] = $name;
			}
		}

		return $out;
	}

	/**
	 * Map WPML/WPM action names onto translate|sync|skip.
	 *
	 * @param mixed $action Raw action.
	 * @return string
	 */
	public static function normalize_action( $action ) {
		if ( is_array( $action ) ) {
			if ( isset( $action['action'] ) ) {
				$action = $action['action'];
			} elseif ( isset( $action['type'] ) ) {
				$action = $action['type'];
			} else {
				$action = 'translate';
			}
		}
		$action = sanitize_key( (string) $action );
		if ( in_array( $action, array( 'copy', 'sync' ), true ) ) {
			return 'sync';
		}
		if ( in_array( $action, array( 'copy-once', 'copy_once', 'copyonce' ), true ) ) {
			return 'copy_once';
		}
		if ( in_array( $action, array( 'ignore', 'skip', 'no_sync' ), true ) ) {
			return 'skip';
		}
		if ( in_array( $action, array( 'id_map', 'id_mapping', 'mapping' ), true ) ) {
			return 'id_mapping';
		}
		if ( '' === $action || 'translate' === $action ) {
			return 'translate';
		}
		return $action;
	}

	/**
	 * Bundled peer-config directory.
	 *
	 * @return string
	 */
	public static function bundled_dir() {
		if ( defined( 'WPTSALL_PATH' ) ) {
			return WPTSALL_PATH . 'includes/models/peer-configs/';
		}
		return dirname( __DIR__ ) . '/models/peer-configs/';
	}

	/**
	 * @param mixed $spec WPM field spec.
	 * @return string
	 */
	private static function wpm_spec_to_action( $spec ) {
		if ( is_string( $spec ) && '' !== $spec ) {
			return self::normalize_action( $spec );
		}
		if ( is_array( $spec ) && isset( $spec['action'] ) ) {
			return self::normalize_action( $spec['action'] );
		}
		return 'translate';
	}

	/**
	 * @param string $file Path.
	 * @return string
	 */
	private static function source_label( $file ) {
		$dir = self::bundled_dir();
		if ( $dir && 0 === strpos( $file, $dir ) ) {
			return 'bundled_peer';
		}
		if ( 'json' === strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) ) ) {
			return 'wpm_config_json';
		}
		return 'wpml_config_xml';
	}

	/**
	 * @param array $base Existing.
	 * @param array $add  Incoming.
	 * @return array
	 */
	private static function merge_config( array $base, array $add ) {
		foreach ( array( 'custom-types', 'custom-fields', 'taxonomies' ) as $bag ) {
			$base[ $bag ] = array_merge( (array) ( $base[ $bag ] ?? array() ), (array) ( $add[ $bag ] ?? array() ) );
		}
		$base['admin-texts'] = array_values( array_unique( array_merge( (array) ( $base['admin-texts'] ?? array() ), (array) ( $add['admin-texts'] ?? array() ) ) ) );
		// P1-XML-01: shortcodes/gutenberg declarations merge by name; the
		// union of declared attributes is kept per shortcode/block.
		foreach ( array( 'shortcodes', 'gutenberg' ) as $seg ) {
			if ( empty( $add[ $seg ] ) ) {
				continue;
			}
			foreach ( (array) $add[ $seg ] as $name => $spec ) {
				if ( ! isset( $base[ $seg ][ $name ] ) ) {
					$base[ $seg ][ $name ] = $spec;
					continue;
				}
				if ( 'shortcodes' === $seg && is_array( $spec ) ) {
					$base[ $seg ][ $name ]['attributes'] = array_values( array_unique( array_merge(
						(array) ( $base[ $seg ][ $name ]['attributes'] ?? array() ),
						(array) ( $spec['attributes'] ?? array() )
					) ) );
					$base[ $seg ][ $name ]['whole_content'] = ! empty( $base[ $seg ][ $name ]['whole_content'] ) || ! empty( $spec['whole_content'] );
				} elseif ( 'gutenberg' === $seg && is_array( $spec ) ) {
					$base[ $seg ][ $name ] = array_values( array_unique( array_merge(
						(array) $base[ $seg ][ $name ],
						(array) $spec
					) ) );
				}
			}
		}
		if ( ! empty( $add['source'] ) ) {
			$base['source'] = $add['source'];
		}
		return $base;
	}
}

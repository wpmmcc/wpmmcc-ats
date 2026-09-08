<?php
/**
 * Template REST Controller
 *
 * Templates module REST API
 *
 * @package WPTSALL\Templates
 * @since 0.5.0
 */

namespace WPTSALL\Templates\API;

use WPTSALL\Templates\Services\Template_Service;
use WPTSALL\Templates\Services\Template_Entry_Service;
use WPTSALL\Templates\Scanners\Language_Pack_Scanner;
use WPTSALL\Templates\Scanners\POT_Parser;
use WPTSALL\Sites\Services\Site_Relation_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Template_REST_Controller class
 *
 * Provides REST API endpoints for the Templates module
 */
class Template_REST_Controller {

	/**
	 * Namespace
	 *
	 * @var string
	 */
	protected $namespace = 'wptsall/v2';

	/**
	 * Register routes
	 */
	public function register_routes() {
		// Templates collection
		register_rest_route(
			$this->namespace,
			'/templates',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_templates' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'relation_id' => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
							'source_type' => array(
								'type'              => 'string',
								'enum'              => array( 'theme', 'plugin' ),
							),
							'target_language' => array(
								'type'              => 'string',
								'sanitize_callback' => 'sanitize_text_field',
							),
							'status'      => array(
								'type'              => 'string',
								'enum'              => array( 'pending', 'scanned', 'translating', 'completed' ),
							),
						'page'        => array(
							'type'              => 'integer',
							'default'           => 1,
							'sanitize_callback' => 'absint',
						),
						'per_page'    => array(
							'type'              => 'integer',
							'default'           => 20,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_template' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						// Per-relation scope (ISS-TPL-011): relation_id is the site relation the template belongs to.
						'relation_id'       => array(
							'required'          => false,
							'type'              => 'integer',
							'default'           => 0,
							'sanitize_callback' => 'absint',
							'description'       => __( 'Site relation ID (per-relation scope)', 'wpmmcc-ats' ),
						),
						'slug'              => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'source_type'       => array(
							'required' => true,
							'type'     => 'string',
							'enum'     => array( 'core', 'theme', 'plugin' ),
						),
						// v0.7.0: Added source identifier
						'source_identifier' => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'description'       => __( 'Source identifier (theme/plugin slug)', 'wpmmcc-ats' ),
						),
						'text_domain'       => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						// v0.7.0: Added language fields
						'source_language'   => array(
							'required'          => false,
							'type'              => 'string',
							'default'           => 'en_US',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'target_language'   => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'source_name'       => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'source_version'    => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		// Scan language packs
		register_rest_route(
			$this->namespace,
			'/templates/scan',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'scan_templates' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					// Per-relation scope (ISS-TPL-011): Scan language packs for a specific relation
					'relation_id'       => array(
						'required'          => false,
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
						'description'       => __( 'Site relation ID, 0 for global scan', 'wpmmcc-ats' ),
					),
					// v0.7.0: Support specifying scan target directly
					'source_type'       => array(
						'required'          => false,
						'type'              => 'string',
						'enum'              => array( 'core', 'theme', 'plugin' ),
					),
					'source_identifier' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'target_language'   => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// v0.6.0: Match language packs for a site relation
		register_rest_route(
			$this->namespace,
			'/templates/match',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'match_language_packs' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'relation_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'description'       => __( 'Site relation ID', 'wpmmcc-ats' ),
					),
				),
			)
		);

		// Single template
		register_rest_route(
			$this->namespace,
			'/templates/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_template' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_template' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'source_name'     => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'source_version'  => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'target_language' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'status'          => array(
							'type' => 'string',
							'enum' => array( 'pending', 'scanned', 'translating', 'completed' ),
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_template' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Rescan template
		register_rest_route(
			$this->namespace,
			'/templates/(?P<id>\d+)/rescan',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rescan_template' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Export PO file
		register_rest_route(
			$this->namespace,
			'/templates/(?P<id>\d+)/export',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export_template' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Import PO file
		register_rest_route(
			$this->namespace,
			'/templates/(?P<id>\d+)/import',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import_template' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Template entries list
		register_rest_route(
			$this->namespace,
			'/templates/(?P<id>\d+)/entries',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_entries' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'status'   => array(
						'type'              => 'string',
						'enum'              => array( 'pending', 'translated', 'reviewed', 'skipped' ),
					),
					'source'   => array(
						'type'              => 'string',
						'enum'              => array( 'scan', 'auto', 'manual' ),
					),
					'search'   => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 50,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Single entry
		register_rest_route(
			$this->namespace,
			'/template-entries/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_entry' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_entry' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'msgstr'       => array(
							'type' => 'string',
						),
						'msgstr_plural'=> array(
							'type' => 'string',
						),
						'status'       => array(
							'type' => 'string',
							'enum' => array( 'pending', 'translated', 'reviewed', 'skipped' ),
						),
						'note'         => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_entry' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Bulk update entries
		register_rest_route(
			$this->namespace,
			'/template-entries/bulk-update',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulk_update_entries' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'ids'  => array(
						'required' => true,
						'type'     => 'array',
						'items'    => array( 'type' => 'integer' ),
					),
					'data' => array(
						'required' => true,
						'type'     => 'object',
					),
				),
			)
		);
	}

	/**
	 * Check permission
	 *
	 * @param \WP_REST_Request|null $request Request object.
	 * @return bool|\WP_Error
	 */
	public function check_permission( $request = null ) {
		if ( ! wptsall_user_can_manage_translations() ) {
			return false;
		}
		if ( ! $request instanceof \WP_REST_Request ) {
			return true;
		}

		$route = (string) $request->get_route();

		// Collection/scan/match/create routes accept a relation_id directly.
		if ( $request->has_param( 'relation_id' ) ) {
			$relation_id = absint( $request->get_param( 'relation_id' ) );
			if ( $relation_id > 0 && ! Site_Relation_Service::get_relation( $relation_id ) ) {
				return $this->rest_object_not_found( __( 'Site relation does not exist.', 'wpmmcc-ats' ) );
			}
		}

		// A single template (including entries/export/import/rescan) must exist
		// before its callback can read or mutate any language-pack data.
		if ( preg_match( '#/templates/(\d+)(?:/|$)#', $route, $match ) ) {
			$template = Template_Service::get( absint( $match[1] ) );
			if ( ! $template ) {
				return $this->rest_object_not_found( __( 'Template does not exist.', 'wpmmcc-ats' ) );
			}
			if ( $request->has_param( 'relation_id' )
				&& absint( $request->get_param( 'relation_id' ) ) !== (int) $template['relation_id'] ) {
				return $this->rest_object_forbidden( __( 'Template does not belong to the requested site relation.', 'wpmmcc-ats' ) );
			}
			return $this->assert_template_relation_exists( $template );
		}

		// Entries are addressed by their own ID, so resolve the parent template
		// here rather than trusting a caller-supplied template/relation parameter.
		if ( strpos( $route, '/template-entries/bulk-update' ) !== false ) {
			$ids = $request->get_param( 'ids' );
			if ( ! is_array( $ids ) || empty( $ids ) || count( $ids ) > 100 ) {
				return $this->rest_object_not_found( __( 'Template entry list is invalid.', 'wpmmcc-ats' ) );
			}
			foreach ( $ids as $entry_id ) {
				$entry = Template_Entry_Service::get( absint( $entry_id ) );
				if ( ! $entry ) {
					return $this->rest_object_not_found( __( 'Template entry does not exist.', 'wpmmcc-ats' ) );
				}
				$template = Template_Service::get( absint( $entry['template_id'] ) );
				if ( ! $template ) {
					return $this->rest_object_not_found( __( 'Parent template does not exist.', 'wpmmcc-ats' ) );
				}
				$relation_check = $this->assert_template_relation_exists( $template );
				if ( is_wp_error( $relation_check ) ) {
					return $relation_check;
				}
			}
			return true;
		}
		if ( preg_match( '#/template-entries/(\d+)$#', $route, $match ) ) {
			$entry = Template_Entry_Service::get( absint( $match[1] ) );
			if ( ! $entry ) {
				return $this->rest_object_not_found( __( 'Template entry does not exist.', 'wpmmcc-ats' ) );
			}
			$template = Template_Service::get( absint( $entry['template_id'] ) );
			if ( ! $template ) {
				return $this->rest_object_not_found( __( 'Parent template does not exist.', 'wpmmcc-ats' ) );
			}
			return $this->assert_template_relation_exists( $template );
		}

		return true;
	}

	/**
	 * Ensure a relation-backed template cannot reference a deleted relation.
	 * Global/system templates use relation_id=0 and remain valid.
	 *
	 * @param array $template Template row.
	 * @return true|\WP_Error
	 */
	private function assert_template_relation_exists( $template ) {
		$relation_id = absint( $template['relation_id'] ?? 0 );
		if ( $relation_id > 0 && ! Site_Relation_Service::get_relation( $relation_id ) ) {
			return $this->rest_object_not_found( __( 'Template site relation does not exist.', 'wpmmcc-ats' ) );
		}
		return true;
	}

	/**
	 * Build a consistent object-not-found error for permission callbacks.
	 *
	 * @param string $message Error message.
	 * @return \WP_Error
	 */
	private function rest_object_not_found( $message ) {
		return new \WP_Error( 'rest_object_not_found', $message, array( 'status' => 404 ) );
	}

	/**
	 * Build a consistent object-ownership error for permission callbacks.
	 *
	 * @param string $message Error message.
	 * @return \WP_Error
	 */
	private function rest_object_forbidden( $message ) {
		return new \WP_Error( 'rest_object_forbidden', $message, array( 'status' => 403 ) );
	}

	/**
	 * Validate an inbound component/template manifest before apply (ISS S4).
	 *
	 * @param array $manifest Manifest.
	 * @return true|\WP_Error
	 */
	public function assert_template_trust( array $manifest ) {
		if ( class_exists( '\\WPTSALL\\Core\\Component_Trust' ) ) {
			return \WPTSALL\Core\Component_Trust::assert_component_trusted( $manifest );
		}
		return true;
	}

	/**
	 * Get template list
	 *
	 * @param \WP_REST_Request $request Request object
	 * @return \WP_REST_Response
	 */
	public function get_templates( $request ) {
		$args = array(
			'relation_id'     => $request->get_param( 'relation_id' ),
			'source_type'     => $request->get_param( 'source_type' ),
			'target_language' => $request->get_param( 'target_language' ),
			'status'          => $request->get_param( 'status' ),
			'page'            => $request->get_param( 'page' ),
			'per_page'        => $request->get_param( 'per_page' ),
		);

		$result = Template_Service::get_all( array_filter( $args ) );

		// Enhance data
		foreach ( $result['items'] as &$template ) {
			$template['progress'] = 0;
			if ( $template['total_entries'] > 0 ) {
				$template['progress'] = round(
					( $template['translated_entries'] / $template['total_entries'] ) * 100,
					1
				);
			}
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Create template
	 *
	 * @param \WP_REST_Request $request Request object
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_template( $request ) {
		$data = array(
			'relation_id'    => absint( $request->get_param( 'relation_id' ) ),
			'slug'           => sanitize_key( $request->get_param( 'slug' ) ),
			'source_type'    => sanitize_key( $request->get_param( 'source_type' ) ),
			'text_domain'    => sanitize_text_field( $request->get_param( 'text_domain' ) ),
			'source_name'    => sanitize_text_field( $request->get_param( 'source_name' ) ?: $request->get_param( 'text_domain' ) ),
			'source_version' => sanitize_text_field( $request->get_param( 'source_version' ) ?: '1.0.0' ),
			'status'         => 'pending',
		);

		// Check if slug already exists (any status)
		$existing = Template_Service::get_by_slug( $data['slug'], true, false );
		if ( $existing ) {
			return new \WP_Error(
				'template_exists',
				__( 'A template with this slug already exists', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		$template_id = Template_Service::create( $data );

		if ( ! $template_id ) {
			wptsall_log_error( 'templates-api', 'create_template failed', array( 'data' => $data ) );
			return new \WP_Error(
				'create_failed',
				__( 'Failed to create template', 'wpmmcc-ats' ),
				array( 'status' => 500 )
			);
		}

		$template = Template_Service::get_with_relation( $template_id );

		wptsall_log(
			'templates',
			'info',
			'Template created via API',
			array(
				'template_id' => $template_id,
				'slug'        => $data['slug'],
			)
		);

		return rest_ensure_response(
			array(
				'success'  => true,
				'template' => $template,
			)
		);
	}

	/**
	 * Get single template
	 *
	 * @param \WP_REST_Request $request Request object
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_template( $request ) {
		$id       = absint( $request->get_param( 'id' ) );
		$template = Template_Service::get_with_relation( $id );

		if ( ! $template ) {
			return new \WP_Error(
				'not_found',
				__( 'Template does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( $template );
	}

	/**
	 * Update template
	 *
	 * @since 0.7.0
	 *
	 * @param \WP_REST_Request $request Request object
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_template( $request ) {
		$id = absint( $request->get_param( 'id' ) );

		$template = Template_Service::get( $id );
		if ( ! $template ) {
			return new \WP_Error(
				'not_found',
				__( 'Template does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		$data = array();

		if ( $request->has_param( 'source_name' ) ) {
			$data['source_name'] = $request->get_param( 'source_name' );
		}
		if ( $request->has_param( 'source_version' ) ) {
			$data['source_version'] = $request->get_param( 'source_version' );
		}
		if ( $request->has_param( 'target_language' ) ) {
			$data['target_language'] = $request->get_param( 'target_language' );
		}
		if ( $request->has_param( 'status' ) ) {
			$data['status'] = $request->get_param( 'status' );
		}

		if ( empty( $data ) ) {
			return new \WP_Error(
				'no_data',
				__( 'No data to update', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		$result = Template_Service::update( $id, $data );

		if ( ! $result ) {
			wptsall_log_error( 'templates-api', 'update_template failed', array( 'template_id' => $id ) );
			return new \WP_Error(
				'update_failed',
				__( 'Update failed', 'wpmmcc-ats' ),
				array( 'status' => 500 )
			);
		}

		$updated = Template_Service::get_with_relation( $id );

		return rest_ensure_response(
			array(
				'success'  => true,
				'template' => $updated,
			)
		);
	}

	/**
	 * Delete template
	 *
	 * @param \WP_REST_Request $request Request object
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_template( $request ) {
		$id = absint( $request->get_param( 'id' ) );

		$template = Template_Service::get( $id );
		if ( ! $template ) {
			return new \WP_Error(
				'not_found',
				__( 'Template does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		$result = Template_Service::delete( $id );

		if ( ! $result ) {
			wptsall_log_error( 'templates-api', 'delete_template failed', array( 'template_id' => $id ) );
			return new \WP_Error(
				'delete_failed',
				__( 'Delete failed', 'wpmmcc-ats' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * Match language packs for a site relation
	 *
	 * @since 0.6.0
	 *
	 * @param \WP_REST_Request $request Request object
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function match_language_packs( $request ) {
		$relation_id = absint( $request->get_param( 'relation_id' ) );

		$result = Template_Service::match_language_packs( $relation_id );

		if ( empty( $result ) || ( is_null( $result['theme'] ) && empty( $result['plugins'] ) ) ) {
			// Return empty result with status 200
			return rest_ensure_response( array(
				'success'     => true,
				'relation_id' => $relation_id,
				'theme'       => null,
				'plugins'     => array(),
				'summary'     => array(
					'total'   => 0,
					'matched' => 0,
					'missing' => 0,
				),
			) );
		}

		return rest_ensure_response( array(
			'success'     => true,
			'relation_id' => $relation_id,
			'theme'       => $result['theme'],
			'plugins'     => $result['plugins'],
			'summary'     => $result['summary'],
		) );
	}

	/**
	 * Scan language packs
	 *
	 * @param \WP_REST_Request $request Request object
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function scan_templates( $request ) {
		$relation_id = absint( $request->get_param( 'relation_id' ) );

		$result = Language_Pack_Scanner::scan_relation( $relation_id );

		if ( ! $result['success'] ) {
			wptsall_log_error(
				'templates-api',
				'scan_templates failed',
				array( 'relation_id' => $relation_id, 'error' => $result['error'] ?? '' )
			);
			return new \WP_Error(
				'scan_failed',
				$result['error'] ?? __( 'Scan failed', 'wpmmcc-ats' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Rescan template
	 *
	 * @param \WP_REST_Request $request Request object
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rescan_template( $request ) {
		$id = absint( $request->get_param( 'id' ) );

		$result = Language_Pack_Scanner::rescan_template( $id );

		if ( ! $result['success'] ) {
			wptsall_log_error(
				'templates-api',
				'rescan_template failed',
				array( 'template_id' => $id, 'error' => $result['error'] ?? '' )
			);
			// Missing on-disk language packs is a client/input condition, not a
			// server fault — return 422 so UI/e2e can treat it as structured failure.
			return new \WP_Error(
				'rescan_failed',
				$result['error'] ?? __( 'Rescan failed', 'wpmmcc-ats' ),
				array( 'status' => 422 )
			);
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Export PO file
	 *
	 * @param \WP_REST_Request $request Request object
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function export_template( $request ) {
		$id       = absint( $request->get_param( 'id' ) );
		$template = Template_Service::get( $id );

		if ( ! $template ) {
			return new \WP_Error(
				'not_found',
				__( 'Template does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		$entries_result = Template_Entry_Service::get_by_template( $id, array( 'per_page' => -1 ) );
		$entries        = $entries_result['items'];

		$content = POT_Parser::generate( $entries, array(
			'project'  => $template['source_name'],
			'version'  => $template['source_version'],
			'language' => '',
		) );

		return rest_ensure_response( array(
			'success'  => true,
			'filename' => $template['text_domain'] . '.po',
			'content'  => $content,
		) );
	}

	/**
	 * Import PO file
	 *
	 * @param \WP_REST_Request $request Request object
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function import_template( $request ) {
		$id      = absint( $request->get_param( 'id' ) );
		$content = $request->get_param( 'content' );

		$template = Template_Service::get( $id );
		if ( ! $template ) {
			return new \WP_Error(
				'not_found',
				__( 'Template does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		if ( empty( $content ) ) {
			return new \WP_Error(
				'no_content',
				__( 'PO file content not provided', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		// Parse PO content
		$entries = POT_Parser::parse_content( $content );

		if ( empty( $entries ) ) {
			wptsall_log(
				'templates',
				'error',
				'Failed to parse PO file during import',
				array( 'template_id' => $id )
			);
			return new \WP_Error(
				'parse_failed',
				__( 'Unable to parse PO file', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		$imported = 0;
		$updated  = 0;

		foreach ( $entries as $entry ) {
			if ( empty( $entry['msgstr'] ) ) {
				continue; // Skip untranslated entries
			}

			$existing = Template_Entry_Service::find_by_msgid(
				$id,
				$entry['msgid'],
				$entry['msgctxt'] ?? ''
			);

			if ( $existing ) {
				Template_Entry_Service::update( $existing['id'], array(
					'msgstr'       => $entry['msgstr'],
					'msgstr_plural'=> $entry['msgstr_plural'] ?? '',
					'status'       => 'translated',
					'source'       => 'manual',
				) );
				$updated++;
			} else {
				Template_Entry_Service::create( array(
					'template_id'  => $id,
					'msgid'        => $entry['msgid'],
					'msgid_plural' => $entry['msgid_plural'] ?? '',
					'msgctxt'      => $entry['msgctxt'] ?? '',
					'msgstr'       => $entry['msgstr'],
					'msgstr_plural'=> $entry['msgstr_plural'] ?? '',
					'status'       => 'translated',
					'source'       => 'manual',
				) );
				$imported++;
			}
		}

		// Update statistics
		Template_Service::update_stats( $id );

		return rest_ensure_response( array(
			'success'  => true,
			'imported' => $imported,
			'updated'  => $updated,
		) );
	}

	/**
	 * Get entry list
	 *
	 * @param \WP_REST_Request $request Request object
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_entries( $request ) {
		$template_id = absint( $request->get_param( 'id' ) );

		$template = Template_Service::get( $template_id );
		if ( ! $template ) {
			return new \WP_Error(
				'not_found',
				__( 'Template does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		$args = array(
			'status'   => $request->get_param( 'status' ),
			'source'   => $request->get_param( 'source' ),
			'search'   => $request->get_param( 'search' ),
			'page'     => $request->get_param( 'page' ),
			'per_page' => $request->get_param( 'per_page' ),
		);

		$result = Template_Entry_Service::get_by_template( $template_id, array_filter( $args ) );

		return rest_ensure_response( $result );
	}

	/**
	 * Get single entry
	 *
	 * @param \WP_REST_Request $request Request object
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_entry( $request ) {
		$id    = absint( $request->get_param( 'id' ) );
		$entry = Template_Entry_Service::get( $id );

		if ( ! $entry ) {
			return new \WP_Error(
				'not_found',
				__( 'Entry does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( $entry );
	}

	/**
	 * Update entry
	 *
	 * @param \WP_REST_Request $request Request object
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_entry( $request ) {
		$id    = absint( $request->get_param( 'id' ) );
		$entry = Template_Entry_Service::get( $id );

		if ( ! $entry ) {
			return new \WP_Error(
				'not_found',
				__( 'Entry does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		$data = array();

		if ( $request->has_param( 'msgstr' ) ) {
			$data['msgstr'] = $request->get_param( 'msgstr' );
			$data['source'] = 'manual';
		}
		if ( $request->has_param( 'msgstr_plural' ) ) {
			$data['msgstr_plural'] = $request->get_param( 'msgstr_plural' );
		}
		if ( $request->has_param( 'status' ) ) {
			$data['status'] = $request->get_param( 'status' );
		}
		if ( $request->has_param( 'note' ) ) {
			$data['note'] = $request->get_param( 'note' );
		}

		if ( empty( $data ) ) {
			return new \WP_Error(
				'no_data',
				__( 'No data to update', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		$result = Template_Entry_Service::update( $id, $data );

		if ( ! $result ) {
			return new \WP_Error(
				'update_failed',
				__( 'Update failed', 'wpmmcc-ats' ),
				array( 'status' => 500 )
			);
		}

		// Update template statistics
		Template_Service::update_stats( $entry['template_id'] );

		// Return updated entry
		$updated = Template_Entry_Service::get( $id );

		return rest_ensure_response( array(
			'success' => true,
			'entry'   => $updated,
		) );
	}

	/**
	 * Delete entry
	 *
	 * @param \WP_REST_Request $request Request object
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_entry( $request ) {
		$id    = absint( $request->get_param( 'id' ) );
		$entry = Template_Entry_Service::get( $id );

		if ( ! $entry ) {
			return new \WP_Error(
				'not_found',
				__( 'Entry does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		$template_id = $entry['template_id'];
		$result      = Template_Entry_Service::delete( $id );

		if ( ! $result ) {
			wptsall_log_error( 'templates-api', 'delete_entry failed', array( 'entry_id' => $id ) );
			return new \WP_Error(
				'delete_failed',
				__( 'Delete failed', 'wpmmcc-ats' ),
				array( 'status' => 500 )
			);
		}

		// Update template statistics
		Template_Service::update_stats( $template_id );

		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * Bulk update entries
	 *
	 * @param \WP_REST_Request $request Request object
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function bulk_update_entries( $request ) {
		$ids  = $request->get_param( 'ids' );
		$data = $request->get_param( 'data' );

		if ( empty( $ids ) || ! is_array( $ids ) ) {
			return new \WP_Error(
				'invalid_ids',
				__( 'Invalid entry ID list', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		$update_data  = array();
		$template_ids = array();

		// Build update data
		if ( isset( $data['status'] ) ) {
			$update_data['status'] = sanitize_key( $data['status'] );
		}
		if ( isset( $data['source'] ) ) {
			$update_data['source'] = sanitize_key( $data['source'] );
		}

		if ( empty( $update_data ) ) {
			return new \WP_Error(
				'no_data',
				__( 'No data to update', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		// Collect template IDs
		foreach ( $ids as $id ) {
			$entry = Template_Entry_Service::get( absint( $id ) );
			if ( $entry ) {
				$template_ids[] = $entry['template_id'];
			}
		}

		// Bulk update
		$count = Template_Entry_Service::bulk_update( array_map( 'absint', $ids ), $update_data );

		// Update statistics for affected templates
		foreach ( array_unique( $template_ids ) as $template_id ) {
			Template_Service::update_stats( $template_id );
		}

		return rest_ensure_response( array(
			'success' => true,
			'updated' => $count,
		) );
	}
}

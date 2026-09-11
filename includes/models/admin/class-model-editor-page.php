<?php
/**
 * WPTSALL Model Editor Page
 *
 * Model editor page
 *
 * @package WPTSALL
 * @since 0.3.0
 */

namespace WPTSALL\Models\Admin;

use WPTSALL\Admin\Admin_Page_Helper;
use WPTSALL\Models\Services\Model_Object_Service;
use WPTSALL\Models\Services\Plugin_Mapping_Service;
use WPTSALL\Models\Services\Plugin_Scanner;
use WPTSALL\Models\Services\Translation_Rule_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Model Editor Page Class
 */
class Model_Editor_Page {

	/**
	 * Initialize
	 *
	 * Note: Menu registration is handled in includes/admin/menu.php.
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue scripts
	 *
	 * @param string $hook Page hook.
	 */
	public static function enqueue_scripts( $hook ) {
		if ( strpos( $hook, 'wptsall-model-editor' ) === false && strpos( $hook, 'wpmmcc-ats' ) === false ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$model_id = isset( $_GET['model_id'] ) ? intval( $_GET['model_id'] ) : 0;

		// Custom model wizard page
		if ( 'custom_wizard' === $action ) {
			wp_enqueue_style(
				'wptsall-custom-model-wizard',
				WPTSALL_URL . 'assets/css/custom-model-wizard.css',
				array(),
				WPTSALL_VERSION
			);

			wp_enqueue_script(
				'wptsall-custom-model-wizard',
				WPTSALL_URL . 'assets/js/custom-model-wizard.js',
				array( 'jquery' ),
				WPTSALL_VERSION,
				true
			);

			$list_url = add_query_arg( 'page', 'wpmmcc-ats', admin_url( 'admin.php' ) );
			$edit_url = add_query_arg(
				array(
					'page'     => 'wpmmcc-ats',
					'action'   => 'edit',
					'model_id' => '{id}',
				),
				admin_url( 'admin.php' )
			);

			wp_localize_script( 'wptsall-custom-model-wizard', 'wptsallWizard', array(
				'restUrl'   => rest_url( 'wptsall/v2' ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'siteUrl'   => home_url(),
				'listUrl'   => $list_url,
				'editUrl'   => $edit_url,
				'i18n'      => array(
					'selectPlugin'      => __( 'Select plugin...', 'wpmmcc-ats' ),
					'enterUrlPattern'   => __( 'Please enter URL pattern', 'wpmmcc-ats' ),
					'urlPatternSlash'   => __( 'URL pattern must start with /', 'wpmmcc-ats' ),
					'incompleteChain'   => __( 'Please complete all chain configurations', 'wpmmcc-ats' ),
					'selectFields'      => __( 'Please select at least one field', 'wpmmcc-ats' ),
					'enterTestValue'    => __( 'Please enter a test value', 'wpmmcc-ats' ),
					'modelCreated'      => __( 'Custom model created successfully!', 'wpmmcc-ats' ),
					'preview'           => __( 'Preview', 'wpmmcc-ats' ),
					'extractedParams'   => __( 'Extracted parameters', 'wpmmcc-ats' ),
					'dataFound'         => __( 'Found {count} related data records', 'wpmmcc-ats' ),
					'noDataFound'       => __( 'No related data found', 'wpmmcc-ats' ),
					'postTypes'         => __( 'Post Types', 'wpmmcc-ats' ),
					'metaKeys'          => __( 'Meta Fields', 'wpmmcc-ats' ),
					'customTables'      => __( 'Custom Tables', 'wpmmcc-ats' ),
					'source'            => __( 'Source', 'wpmmcc-ats' ),
					'target'            => __( 'Target', 'wpmmcc-ats' ),
					'urlParam'          => __( 'URL Parameter', 'wpmmcc-ats' ),
					'tableColumn'       => __( 'Table Column', 'wpmmcc-ats' ),
					'selectParam'       => __( 'Select Parameter', 'wpmmcc-ats' ),
					'selectTable'       => __( 'Select Table', 'wpmmcc-ats' ),
					'columnName'        => __( 'Field name', 'wpmmcc-ats' ),
					'matchColumn'       => __( 'Match Column', 'wpmmcc-ats' ),
					'idColumn'          => __( 'ID Column', 'wpmmcc-ats' ),
					'addChainNode'      => __( 'Add Link Node', 'wpmmcc-ats' ),
					'testChain'         => __( 'Test Chain', 'wpmmcc-ats' ),
					'testValue'         => __( 'Enter test value...', 'wpmmcc-ats' ),
					'chainTestSuccess'  => __( 'Chain test successful!', 'wpmmcc-ats' ),
					'chainTestFailed'   => __( 'Chain test failed', 'wpmmcc-ats' ),
					'matchedRows'       => __( 'Matched Rows', 'wpmmcc-ats' ),
					'translateFields'   => __( 'Translation fields', 'wpmmcc-ats' ),
					'syncFields'        => __( 'Sync fields', 'wpmmcc-ats' ),
					'fieldMappings'     => __( 'ID Mapping', 'wpmmcc-ats' ),
					'computeFields'     => __( 'Compute fields', 'wpmmcc-ats' ),
					'noFields'          => __( 'No fields available', 'wpmmcc-ats' ),
					'pluginInfo'        => __( 'Plugin Info', 'wpmmcc-ats' ),
					'plugin'            => __( 'Plugin', 'wpmmcc-ats' ),
					'urlPattern'        => __( 'URL Pattern', 'wpmmcc-ats' ),
					'urlType'           => __( 'URL Type', 'wpmmcc-ats' ),
					'linkChains'        => __( 'Link Chains', 'wpmmcc-ats' ),
					'chainCount'        => __( 'Chain Nodes', 'wpmmcc-ats' ),
					'fields'            => __( 'Fields', 'wpmmcc-ats' ),
					'remove'            => __( 'Remove', 'wpmmcc-ats' ),
				),
			) );

			return;
		}

		// Rule edit page and rules list page both use V2 editor styles
		if ( 'edit' === $action || 'edit_rule' === $action ) {
			// V2 Rule Editor CSS
			wp_enqueue_style(
				'wptsall-rule-editor-v2',
				WPTSALL_URL . 'assets/css/rule-editor-v2.css',
				array(),
				WPTSALL_VERSION
			);

			// Also load model-editor CSS for shared styles
			wp_enqueue_style(
				'wptsall-model-editor',
				WPTSALL_URL . 'assets/css/model-editor.css',
				array(),
				WPTSALL_VERSION
			);

			wp_enqueue_script(
				'wptsall-rule-editor-v2',
				WPTSALL_URL . 'assets/js/rule-editor-v2.js',
				array( 'jquery', 'wp-api-fetch' ),
				WPTSALL_VERSION,
				true
			);

			wp_localize_script( 'wptsall-rule-editor-v2', 'wptsallRuleEditorV2', array(
				'restUrl'  => rest_url( 'wptsall/v2/' ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'modelId'  => $model_id,
				'i18n'     => array(
					'confirm_delete_rule' => __( 'Are you sure you want to delete this translation rule?', 'wpmmcc-ats' ),
					'saving'              => __( 'Saving...', 'wpmmcc-ats' ),
					'saved'               => __( 'Saved', 'wpmmcc-ats' ),
					'error'               => __( 'Save failed', 'wpmmcc-ats' ),
					'validation_error'    => __( 'Form validation failed, please check your input', 'wpmmcc-ats' ),
				),
			) );

			// Field Configurator (only for edit_rule page)
			if ( 'edit_rule' === $action ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$rule_id = isset( $_GET['rule_id'] ) ? sanitize_text_field( wp_unslash( $_GET['rule_id'] ) ) : '';
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$relation_id = isset( $_GET['relation_id'] ) ? absint( wp_unslash( $_GET['relation_id'] ) ) : 0;

				// Enqueue jQuery UI draggable and droppable
				wp_enqueue_script( 'jquery-ui-draggable' );
				wp_enqueue_script( 'jquery-ui-droppable' );

				// Field Configurator CSS
				wp_enqueue_style(
					'wptsall-field-configurator',
					WPTSALL_URL . 'assets/css/field-configurator.css',
					array(),
					WPTSALL_VERSION
				);

				// Field Configurator JS
				wp_enqueue_script(
					'wptsall-field-configurator',
					WPTSALL_URL . 'assets/js/field-configurator.js',
					array( 'jquery', 'jquery-ui-draggable', 'jquery-ui-droppable' ),
					WPTSALL_VERSION,
					true
				);

				$model = Translation_Rule_Service::get_model( $model_id );
				$rule  = ( 'new' === $rule_id || '' === $rule_id ) ? null : Translation_Rule_Service::get_rule( $rule_id );
				$rule_data = is_array( $rule ) ? $rule : array(
					'object_name' => '',
					'data_type'   => 'post',
				);
				$object_choices = self::build_rule_object_choices( $model_id, $rule_data );

				wp_localize_script( 'wptsall-field-configurator', 'wptsallFieldConfig', array(
					'restUrl'           => rest_url( 'wptsall/v2/' ),
					'nonce'             => wp_create_nonce( 'wp_rest' ),
					'ruleId'            => $rule_id,
					'relationId'        => $relation_id,
					'modelId'           => $model_id,
					'objectName'        => $rule_data['object_name'],
					'dataType'          => $rule_data['data_type'],
					'objectChoices'     => $object_choices['choices'],
					'objectPlaceholder' => __( '-- Please select --', 'wpmmcc-ats' ),
					'noObjectsLabel'    => __( 'No model object is available for the selected data type', 'wpmmcc-ats' ),
					'manualFields'      => $model['meta_fields'] ?? array(),
				) );
			}
		} else {
			// List page uses original editor (but calls V2 API)
			wp_enqueue_style(
				'wptsall-model-editor',
				WPTSALL_URL . 'assets/css/model-editor.css',
				array(),
				WPTSALL_VERSION
			);

			wp_enqueue_script(
				'wptsall-model-editor',
				WPTSALL_URL . 'assets/js/model-editor.js',
				array( 'jquery', 'wp-api-fetch' ),
				WPTSALL_VERSION,
				true
			);

			wp_localize_script( 'wptsall-model-editor', 'wptsallModelEditor', array(
				'restUrl'    => rest_url( 'wptsall/v2/' ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'i18n'       => array(
					'confirm_delete'        => __( 'Are you sure you want to delete this model? This action cannot be undone.', 'wpmmcc-ats' ),
					'confirm_delete_rule'   => __( 'Are you sure you want to delete this URL rule?', 'wpmmcc-ats' ),
					'export_failed'         => __( 'Export failed', 'wpmmcc-ats' ),
					'export_rule_success'   => __( 'Rule exported successfully!', 'wpmmcc-ats' ),
					'saving'                => __( 'Saving...', 'wpmmcc-ats' ),
					'saved'                 => __( 'Saved', 'wpmmcc-ats' ),
					'error'                 => __( 'Save failed', 'wpmmcc-ats' ),
					'scanning'              => __( 'Scanning...', 'wpmmcc-ats' ),
					'scan_complete'         => __( 'Scan complete', 'wpmmcc-ats' ),
					'confirm_scan_all'      => __( 'Are you sure you want to rescan all plugins? This will update plugin mapping data.', 'wpmmcc-ats' ),
					/* translators: 1: total plugins scanned, 2: content plugins found */
					'scan_all_success'      => __( 'Scan complete! Scanned %1$d plugins, found %2$d content plugins.', 'wpmmcc-ats' ),
					// H3: Import Conflict Dialog i18n strings.
					'import_conflict_model' => __( 'Import Conflict - Model Already Exists', 'wpmmcc-ats' ),
					'import_conflict_rule'  => __( 'Import Conflict - Rule Already Exists', 'wpmmcc-ats' ),
					'plugin_slug'           => __( 'Plugin Slug', 'wpmmcc-ats' ),
					'existing_id'           => __( 'Existing ID', 'wpmmcc-ats' ),
					'data_type'             => __( 'Data Type', 'wpmmcc-ats' ),
					'object_name'           => __( 'Object Name', 'wpmmcc-ats' ),
					'conflict_description'  => __( 'A matching item already exists. Choose how to proceed:', 'wpmmcc-ats' ),
					'field'                 => __( 'Field', 'wpmmcc-ats' ),
					'existing_value'        => __( 'Existing', 'wpmmcc-ats' ),
					'imported_value'        => __( 'Imported', 'wpmmcc-ats' ),
					'objects_count'         => __( 'Objects', 'wpmmcc-ats' ),
					'total_fields'          => __( 'Total Fields', 'wpmmcc-ats' ),
					'added_fields'          => __( 'Added Fields', 'wpmmcc-ats' ),
					'removed_fields'        => __( 'Removed Fields', 'wpmmcc-ats' ),
					'no_field_differences'  => __( 'No field-level differences detected', 'wpmmcc-ats' ),
					'skip_import'           => __( 'Skip', 'wpmmcc-ats' ),
					'create_duplicate'      => __( 'Create Duplicate', 'wpmmcc-ats' ),
					'overwrite_existing'    => __( 'Overwrite Existing', 'wpmmcc-ats' ),
					'import_skipped'        => __( 'Import skipped.', 'wpmmcc-ats' ),
					'import_failed'         => __( 'Import failed', 'wpmmcc-ats' ),
					'invalid_json'          => __( 'Invalid JSON file', 'wpmmcc-ats' ),
					'model_imported'        => __( 'Model imported successfully!', 'wpmmcc-ats' ),
					'rule_imported'         => __( 'Rule imported successfully!', 'wpmmcc-ats' ),
				),
			) );

			// Manual Fields JS
			wp_enqueue_script(
				'wptsall-manual-fields',
				WPTSALL_URL . 'assets/js/manual-fields.js',
				array( 'jquery', 'wp-api-fetch' ),
				WPTSALL_VERSION,
				true
			);

			wp_localize_script( 'wptsall-manual-fields', 'wptsallManualFields', array(
				'restUrl' => rest_url( 'wptsall/v2/' ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'modelId' => $model_id,
			) );

			// Field Sync Panel JS (v1.1.0).
			wp_enqueue_script(
				'wptsall-field-sync',
				WPTSALL_URL . 'assets/js/field-sync.js',
				array( 'jquery', 'wp-api-fetch' ),
				WPTSALL_VERSION,
				true
			);

			wp_localize_script( 'wptsall-field-sync', 'wptsallFieldSync', array(
				'restUrl' => rest_url( 'wptsall/v2/' ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'modelId' => $model_id,
			) );

			// Field Coverage Verification JS (v1.1.0).
			wp_enqueue_script(
				'wptsall-field-coverage',
				WPTSALL_URL . 'assets/js/field-coverage.js',
				array( 'jquery', 'wp-api-fetch' ),
				WPTSALL_VERSION,
				true
			);

			wp_localize_script( 'wptsall-field-coverage', 'wptsallFieldCoverage', array(
				'restUrl' => rest_url( 'wptsall/v2/' ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'modelId' => $model_id,
			) );

			// Model basic info editor (ISS-MOD-025).
			wp_enqueue_script(
				'wptsall-model-basic-info',
				WPTSALL_URL . 'assets/js/model-basic-info.js',
				array( 'jquery', 'wp-api-fetch' ),
				WPTSALL_VERSION,
				true
			);

			wp_localize_script( 'wptsall-model-basic-info', 'wptsallModelBasicInfo', array(
				'restUrl' => rest_url( 'wptsall/v2/' ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'modelId' => $model_id,
			) );

			// Object Editor JS for template pages (ISS-MOD-028).
			if ( in_array( $action, array( 'template', 'template_object' ), true ) ) {
				wp_enqueue_script(
					'wptsall-object-editor',
					WPTSALL_URL . 'assets/js/object-editor.js',
					array( 'jquery', 'wp-api-fetch' ),
					WPTSALL_VERSION,
					true
				);

				wp_localize_script( 'wptsall-object-editor', 'wptsallObjectEditor', array(
					'modelId' => $model_id,
					'i18n'    => array(
						'confirm_delete_object' => __( 'Deleting this object will also delete all its fields. Are you sure?', 'wpmmcc-ats' ),
						'confirm_delete_field'  => __( 'Are you sure you want to delete this field?', 'wpmmcc-ats' ),
						'saving'                => __( 'Saving...', 'wpmmcc-ats' ),
						'saved'                 => __( 'Saved', 'wpmmcc-ats' ),
						'deleted'               => __( 'Deleted', 'wpmmcc-ats' ),
						'error'                 => __( 'Operation failed', 'wpmmcc-ats' ),
						'add_object_type'       => __( 'Object Type', 'wpmmcc-ats' ),
						'add_object_name'       => __( 'Object name (e.g. post_type slug)', 'wpmmcc-ats' ),
					),
				) );
			}

			// Field Rules (Hot-plug) tab JS (was an inline <script> block;
			// enqueued per WordPress.org review feedback).
			wp_enqueue_script(
				'wptsall-model-field-rules',
				WPTSALL_URL . 'assets/js/model-field-rules.js',
				array(),
				WPTSALL_VERSION,
				true
			);

			wp_localize_script( 'wptsall-model-field-rules', 'wptsallFieldRules', array(
				'restUrl' => esc_url_raw( rest_url( 'wptsall/v2/field-rules-docs' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			) );
		}
	}

	/**
	 * Build model-driven object choices for rule binding.
	 *
	 * @param int   $model_id   Model ID.
	 * @param array $rule_data  Current rule data.
	 * @return array{labels:array<string,string>,choices:array<string,array<int,array<string,string>>>}
	 */
	private static function build_rule_object_choices( $model_id, array $rule_data = array() ) {
		$model_objects = Model_Object_Service::get_objects_for_model( $model_id );
		$labels        = array(
			'post'   => __( 'Model Post Objects', 'wpmmcc-ats' ),
			'term'   => __( 'Model Term Objects', 'wpmmcc-ats' ),
			'option' => __( 'Model Option Objects', 'wpmmcc-ats' ),
		);
		$choices       = array(
			'post'   => array(),
			'term'   => array(),
			'option' => array(),
		);
		$seen_keys     = array();

		foreach ( $model_objects as $model_object ) {
			$object_type = sanitize_key( (string) ( $model_object['object_type'] ?? '' ) );
			$object_name = sanitize_key( (string) ( $model_object['object_name'] ?? '' ) );
			$data_type   = self::map_model_object_type_to_rule_data_type( $object_type );

			if ( '' === $data_type || '' === $object_name ) {
				continue;
			}

			$choice_key = $data_type . ':' . $object_name;
			if ( isset( $seen_keys[ $choice_key ] ) ) {
				continue;
			}
			$seen_keys[ $choice_key ] = true;

			$choices[ $data_type ][] = array(
				'value'       => $object_name,
				'label'       => self::get_rule_object_choice_label( $object_type, $object_name ),
				'data_type'   => $data_type,
				'object_type' => $object_type,
			);
		}

		$current_data_type = sanitize_key( (string) ( $rule_data['data_type'] ?? '' ) );
		$current_object    = sanitize_key( (string) ( $rule_data['object_name'] ?? '' ) );
		if ( isset( $choices[ $current_data_type ] ) && '' !== $current_object ) {
			$current_exists = false;
			foreach ( $choices[ $current_data_type ] as $choice ) {
				if ( $current_object === ( $choice['value'] ?? '' ) ) {
					$current_exists = true;
					break;
				}
			}

			if ( ! $current_exists ) {
				$choices[ $current_data_type ][] = array(
					'value'       => $current_object,
					/* translators: %s: legacy object name */
					'label'       => sprintf( __( 'Legacy binding: %s', 'wpmmcc-ats' ), $current_object ),
					'data_type'   => $current_data_type,
					'object_type' => 'legacy',
				);
			}
		}

		return array(
			'labels'  => $labels,
			'choices' => $choices,
		);
	}

	/**
	 * Map model object type to rule data type.
	 *
	 * @param string $object_type Model object type.
	 * @return string
	 */
	private static function map_model_object_type_to_rule_data_type( $object_type ) {
		switch ( sanitize_key( (string) $object_type ) ) {
			case 'post_type':
				return 'post';
			case 'taxonomy':
				return 'term';
			case 'option':
				return 'option';
			default:
				return '';
		}
	}

	/**
	 * Build a human-readable label for a rule object choice.
	 *
	 * @param string $object_type Model object type.
	 * @param string $object_name Object name.
	 * @return string
	 */
	private static function get_rule_object_choice_label( $object_type, $object_name ) {
		$object_type = sanitize_key( (string) $object_type );
		$object_name = sanitize_key( (string) $object_name );

		if ( 'post_type' === $object_type ) {
			$post_type = get_post_type_object( $object_name );
			if ( $post_type && ! empty( $post_type->labels->singular_name ) ) {
				return $post_type->labels->singular_name . ' (' . $object_name . ')';
			}
		}

		if ( 'taxonomy' === $object_type ) {
			$taxonomy = get_taxonomy( $object_name );
			if ( $taxonomy && ! empty( $taxonomy->labels->singular_name ) ) {
				return $taxonomy->labels->singular_name . ' (' . $object_name . ')';
			}
		}

		return $object_name;
	}

	/**
	 * Render page
	 */
	public static function render_page() {
		if ( ! wptsall_user_can_manage_translations() ) {
			return;
		}

		// No extra notice container needed, WordPress admin notices auto-display after .wrap > h1 + hr.wp-header-end

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$model_id = isset( $_GET['model_id'] ) ? intval( $_GET['model_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$rule_id = isset( $_GET['rule_id'] ) ? sanitize_text_field( wp_unslash( $_GET['rule_id'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$object_id = isset( $_GET['object_id'] ) ? intval( $_GET['object_id'] ) : 0;

		if ( 'custom_wizard' === $action ) {
			// Custom model wizard page
			self::render_wizard_page();
		} elseif ( 'template_object' === $action && $model_id && $object_id ) {
			// Plugin template object detail page (B: plugin -> object -> fields).
			self::render_template_object_page( $model_id, $object_id );
		} elseif ( 'template' === $action && $model_id ) {
			// Plugin template objects list page (B: plugin -> objects).
			self::render_template_page( $model_id );
		} elseif ( 'edit_rule' === $action && $model_id ) {
			// Rule edit page (new or existing)
			self::render_rule_edit_page( $model_id, $rule_id );
		} elseif ( $model_id && 'edit' === $action ) {
			// Model's rules list page
			self::render_edit_page( $model_id );
		} else {
			// Models list page
			self::render_list_page();
		}
	}

	/**
	 * Check if a plugin is active by slug
	 *
	 * @param string $plugin_slug Plugin slug.
	 * @return bool
	 */
	private static function is_plugin_active_by_slug( $plugin_slug ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins = get_plugins();

		foreach ( $all_plugins as $plugin_file => $plugin_data ) {
			$slug = dirname( $plugin_file );
			if ( '.' === $slug ) {
				$slug = basename( $plugin_file, '.php' );
			}

			if ( $slug === $plugin_slug ) {
				return is_plugin_active( $plugin_file );
			}
		}

		return false;
	}

	/**
	 * Render model list page with tabs
	 *
	 * Two tabs:
	 * - Templates: Plugin list with scan status
	 * - Rules: Translation rules list
	 *
	 * @since 0.9.2
	 */
	protected static function render_list_page() {
		// Get current tab
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'templates';

		// Get pagination parameters
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_page = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
		$per_page     = 10;

		// Get plugin mappings (all scanned plugins) with pagination
		$mappings = Plugin_Mapping_Service::get_all( array(
			'limit'   => $per_page,
			'offset'  => ( $current_page - 1 ) * $per_page,
			'orderby' => 'plugin_name',
			'order'   => 'ASC',
		) );
		
		$total = Plugin_Mapping_Service::get_count();
		$total_pages = max( 1, ceil( $total / $per_page ) );

		// Enrich mappings with model data (rule counts, IDs) from models table
		$models_data = Translation_Rule_Service::get_models( array(
			'per_page'    => -1, // Get all to match against current page mappings
			'with_counts' => true,
		) );
		$existing_models = array();
		if ( ! empty( $models_data['models'] ) ) {
			foreach ( $models_data['models'] as $m ) {
				$existing_models[ $m['plugin_slug'] ] = $m;
			}
		}

			// Merge data
			$models = array();
			foreach ( $mappings as $mapping ) {
				$slug = $mapping['plugin_slug'];
				$model_entry = $existing_models[ $slug ] ?? null;

				$item = array(
					// Plugin mappings are stored in the unified models table.
					// Use the mapping row id as the model_id so the Templates tab can always link to edit.
					'id'            => (int) ( $mapping['id'] ?? ( $model_entry['id'] ?? 0 ) ),
					'plugin_name'   => $mapping['plugin_name'],
					'plugin_slug'   => $slug,
					'post_types'    => ! empty( $mapping['post_types'] ) ? wp_json_encode( $mapping['post_types'] ) : '[]',
					'rule_count'    => $model_entry['rule_count'] ?? 0,
					'last_scanned'  => $mapping['last_scanned'] ?? '',
					// Prefer mapping row fields; fall back to model_entry for legacy data.
					'source_type'   => $mapping['source_type'] ?? ( $model_entry['source_type'] ?? 'auto' ),
					'is_system'     => $mapping['is_system'] ?? ( $model_entry['is_system'] ?? 0 ),
				);
				$models[] = $item;
			}

		// Get available plugins for scanning
		// Use Plugin_Scanner::get_scannable_plugins() which returns all active plugins + blog
		$plugins = Plugin_Scanner::get_scannable_plugins();

		$wizard_url = add_query_arg(
			array(
				'page'   => 'wpmmcc-ats',
				'action' => 'custom_wizard',
			),
			admin_url( 'admin.php' )
		);

		// Tab definitions
		$tabs = array(
			'templates'   => __( 'Plugin Templates', 'wpmmcc-ats' ),
			'rules'       => __( 'Translation Rules', 'wpmmcc-ats' ),
			'field-rules' => __( 'Field Rules (Hot-plug)', 'wpmmcc-ats' ),
		);
		$actions = array(
			array(
				'label' => __( 'Add Custom Model', 'wpmmcc-ats' ),
				'url'   => $wizard_url,
				'class' => 'button button-primary',
			),
			array(
				'label' => __( 'Scan New Plugin', 'wpmmcc-ats' ),
				'url'   => '#',
				'id'    => 'wptsall-scan-plugin-btn',
				'class' => 'button',
			),
			array(
				'label' => __( 'Scan All Plugins', 'wpmmcc-ats' ),
				'url'   => '#',
				'id'    => 'wptsall-scan-all-btn',
				'class' => 'button',
			),
			array(
				'label' => __( 'Import Model', 'wpmmcc-ats' ),
				'url'   => '#',
				'id'    => 'wptsall-import-model-v3-btn',
				'class' => 'button',
			),
			array(
				'label' => __( 'Import', 'wpmmcc-ats' ),
				'url'   => '#',
				'id'    => 'wptsall-import-btn',
				'class' => 'button',
			),
			array(
				'label' => __( 'Export', 'wpmmcc-ats' ),
				'url'   => '#',
				'id'    => 'wptsall-export-btn',
				'class' => 'button',
			),
		);

		Admin_Page_Helper::render_header(
			__( 'Model Management', 'wpmmcc-ats' ),
			'models',
			$actions,
			array( 'wptsall-model-editor' )
		);

		Admin_Page_Helper::render_tabs( $tabs, $current_tab, admin_url( 'admin.php?page=wpmmcc-ats' ), 'wp' );
		?>
			<div class="wptsall-page-content">

			<!-- H3: Hidden file input for V3 model import -->
			<input type="file" id="wptsall-import-model-v3-file" class="wptsall-import-file-input" accept=".json">

			<!-- Scan Plugin Modal -->
			<div id="wptsall-scan-modal" class="wptsall-modal" style="display:none;">
				<div class="wptsall-modal-content">
					<span class="wptsall-modal-close">&times;</span>
					<h2><?php esc_html_e( 'Scan plugin to generate model', 'wpmmcc-ats' ); ?></h2>
					<p><?php esc_html_e( 'Select a content plugin to scan its URL structure and database fields to auto-generate a model.', 'wpmmcc-ats' ); ?></p>

					<form id="wptsall-scan-form">
						<table class="form-table">
							<tr>
								<th scope="row">
									<label for="scan-plugin"><?php esc_html_e( 'Select Plugin', 'wpmmcc-ats' ); ?></label>
								</th>
								<td>
									<select id="scan-plugin" name="plugin_slug">
										<option value=""><?php esc_html_e( '-- Please select --', 'wpmmcc-ats' ); ?></option>
										<?php foreach ( $plugins as $slug => $name ) : ?>
											<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $name ); ?></option>
										<?php endforeach; ?>
										<option value="__custom__"><?php esc_html_e( 'Manual input...', 'wpmmcc-ats' ); ?></option>
									</select>
									<p class="description"><?php esc_html_e( 'Auto-detected content plugins. If not listed, choose "Manual input".', 'wpmmcc-ats' ); ?></p>
								</td>
							</tr>
							<tr id="scan-plugin-custom-row" style="display:none;">
								<th scope="row">
									<label for="scan-plugin-custom"><?php esc_html_e( 'Manual Plugin Input', 'wpmmcc-ats' ); ?></label>
								</th>
								<td>
									<input type="text" id="scan-plugin-custom" name="plugin_slug_custom" class="regular-text" placeholder="<?php esc_attr_e( 'Enter plugin directory name, e.g. my-plugin', 'wpmmcc-ats' ); ?>">
									<p class="description">
										<?php esc_html_e( 'Enter the plugin directory name (slug). The system will attempt to scan the plugin.', 'wpmmcc-ats' ); ?>
										<br>
										<a href="#" id="show-available-plugins"><?php esc_html_e( 'View available plugins list', 'wpmmcc-ats' ); ?></a>
									</p>
									<div id="available-plugins-list" style="display:none; margin-top:10px; padding:10px; background:#f9f9f9; border:1px solid #ddd; max-height:200px; overflow-y:auto;">
										<strong><?php esc_html_e( 'Active plugins:', 'wpmmcc-ats' ); ?></strong>
										<ul style="margin:5px 0 0 20px;">
											<?php
											$all_plugins = function_exists( 'wptsall_active_plugins' ) ? wptsall_active_plugins() : array();
											foreach ( $all_plugins as $slug => $name ) :
												if ( 'wpmmcc-ats' === $slug ) {
													continue;
												}
												$is_content = isset( $plugins[ $slug ] );
												?>
												<li>
													<code><?php echo esc_html( $slug ); ?></code> - <?php echo esc_html( $name ); ?>
													<?php if ( $is_content ) : ?>
														<span style="color:green;"><?php esc_html_e( '(already listed)', 'wpmmcc-ats' ); ?></span>
													<?php endif; ?>
												</li>
											<?php endforeach; ?>
										</ul>
									</div>
								</td>
							</tr>
						</table>

						<div id="scan-preview" style="display:none;">
							<h3><?php esc_html_e( 'Scan Preview', 'wpmmcc-ats' ); ?></h3>
							<div id="scan-preview-content"></div>
						</div>

						<p class="submit">
							<button type="button" id="scan-preview-btn" class="button"><?php esc_html_e( 'Preview Scan Results', 'wpmmcc-ats' ); ?></button>
							<button type="submit" id="scan-save-btn" class="button button-primary" disabled><?php esc_html_e( 'Save Model', 'wpmmcc-ats' ); ?></button>
						</p>
					</form>
				</div>
			</div>

			<!-- Import Modal -->
			<div id="wptsall-import-modal" class="wptsall-modal" style="display:none;">
				<div class="wptsall-modal-content">
					<span class="wptsall-modal-close">&times;</span>
					<h2><?php esc_html_e( 'Import Model', 'wpmmcc-ats' ); ?></h2>
					<p><?php esc_html_e( 'Select a JSON file to import model configuration.', 'wpmmcc-ats' ); ?></p>

					<form id="wptsall-import-form" enctype="multipart/form-data">
						<div class="wptsall-form-row">
							<label for="import-file"><?php esc_html_e( 'Select File', 'wpmmcc-ats' ); ?></label>
							<input type="file" id="import-file" name="import_file" accept=".json" required>
							<p class="description"><?php esc_html_e( 'Supports .json format model configuration files', 'wpmmcc-ats' ); ?></p>
						</div>

						<div class="wptsall-form-row">
							<label>
								<input type="checkbox" id="import-overwrite" name="overwrite" value="1">
								<?php esc_html_e( 'Overwrite existing models', 'wpmmcc-ats' ); ?>
							</label>
						</div>

						<div id="import-preview" style="display:none;">
							<h3><?php esc_html_e( 'Preview', 'wpmmcc-ats' ); ?></h3>
							<div id="import-preview-content"></div>
						</div>

						<p class="submit">
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Import', 'wpmmcc-ats' ); ?></button>
							<button type="button" class="button wptsall-modal-close"><?php esc_html_e( 'Cancel', 'wpmmcc-ats' ); ?></button>
						</p>
					</form>
				</div>
			</div>

			<!-- Export Modal -->
			<div id="wptsall-export-modal" class="wptsall-modal" style="display:none;">
				<div class="wptsall-modal-content">
					<span class="wptsall-modal-close">&times;</span>
					<h2><?php esc_html_e( 'Export Model', 'wpmmcc-ats' ); ?></h2>
					<p><?php esc_html_e( 'Select models to export, a JSON configuration file will be generated.', 'wpmmcc-ats' ); ?></p>

					<form id="wptsall-export-form">
						<div class="wptsall-form-row">
							<label><?php esc_html_e( 'Select Models', 'wpmmcc-ats' ); ?></label>
							<div class="wptsall-checkbox-list">
								<label>
									<input type="checkbox" id="export-select-all">
									<strong><?php esc_html_e( 'Select All', 'wpmmcc-ats' ); ?></strong>
								</label>
								<?php foreach ( $models as $model ) : ?>
									<label>
										<input type="checkbox" name="export_models[]" value="<?php echo esc_attr( $model['id'] ); ?>">
										<?php echo esc_html( $model['plugin_name'] ); ?>
									</label>
								<?php endforeach; ?>
							</div>
						</div>

						<div class="wptsall-form-row">
							<label>
								<input type="checkbox" id="export-include-rules" name="include_rules" value="1" checked>
								<?php esc_html_e( 'Include URL Rules', 'wpmmcc-ats' ); ?>
							</label>
						</div>

						<p class="submit">
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Export', 'wpmmcc-ats' ); ?></button>
							<button type="button" class="button wptsall-modal-close"><?php esc_html_e( 'Cancel', 'wpmmcc-ats' ); ?></button>
						</p>
					</form>
				</div>
			</div>

			<!-- Tab Content -->
			<div class="wptsall-tab-content">
				<?php if ( 'templates' === $current_tab ) : ?>
					<!-- Templates Tab: Plugin list with scan status -->
					<?php self::render_templates_tab( $models, $total, $total_pages, $current_page ); ?>
				<?php elseif ( 'field-rules' === $current_tab ) : ?>
					<?php self::render_field_rules_tab(); ?>
				<?php else : ?>
					<!-- Rules Tab: Translation rules list -->
					<?php self::render_rules_tab( $models, $total, $total_pages, $current_page ); ?>
				<?php endif; ?>
			</div>
			</div><!-- .wptsall-page-content -->
		<?php
		Admin_Page_Helper::render_footer();
	}

	/**
	 * Admin UI: validate / save JSON field-rules without SSH into plugin dirs.
	 *
	 * The tab's JS lives in assets/js/model-field-rules.js and is enqueued by
	 * enqueue_scripts() (WordPress.org review: no inline <script> output).
	 *
	 * @since 2.1.0
	 */
	protected static function render_field_rules_tab() {
		$docs = \WPTSALL\Models\Adapters\Field_Rules_Store::discover_all_documents();
		?>
		<div class="wptsall-field-rules-tab" style="max-width:960px">
			<div class="notice notice-info inline" style="margin:0 0 16px">
				<p>
					<strong><?php esc_html_e( 'Merge order (highest wins):', 'wpmmcc-ats' ); ?></strong>
					<?php esc_html_e( 'Built-in PHP adapter → JSON hot-plug (site option overrides plugin file) → Manual model fields at rule generation.', 'wpmmcc-ats' ); ?>
				</p>
				<p>
					<?php esc_html_e( 'Required per field: type, content_format, storage. storage=custom_table is discover-only; writeback needs a PHP adapter.', 'wpmmcc-ats' ); ?>
					<?php esc_html_e( 'Acceptance: discovery / callback writeback / frontend are separate gates — see docs/e2e/MANUAL-ONBOARDING-ACCEPTANCE.md.', 'wpmmcc-ats' ); ?>
				</p>
			</div>

			<h2><?php esc_html_e( 'Discovered documents', 'wpmmcc-ats' ); ?></h2>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Plugin', 'wpmmcc-ats' ); ?></th>
						<th><?php esc_html_e( 'Source', 'wpmmcc-ats' ); ?></th>
						<th><?php esc_html_e( 'Fields', 'wpmmcc-ats' ); ?></th>
						<th><?php esc_html_e( 'Validation', 'wpmmcc-ats' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $docs ) ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No JSON field-rules yet. Paste a document below or place wptsall-field-rules.json in a plugin directory.', 'wpmmcc-ats' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $docs as $doc ) : ?>
						<tr>
							<td><code><?php echo esc_html( $doc['plugin_slug'] ); ?></code></td>
							<td><?php echo esc_html( $doc['source'] ); ?></td>
							<td><?php echo esc_html( (string) count( $doc['field_rules'] ?? array() ) ); ?></td>
							<td>
								<?php if ( ! empty( $doc['validation']['ok'] ) ) : ?>
									<span style="color:#008a20"><?php esc_html_e( 'OK', 'wpmmcc-ats' ); ?></span>
								<?php else : ?>
									<span style="color:#b32d2e"><?php echo esc_html( implode( '; ', $doc['validation']['errors'] ?? array( 'invalid' ) ) ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<h2 style="margin-top:24px"><?php esc_html_e( 'Edit / save (site option)', 'wpmmcc-ats' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Saves to WordPress option wptsall_json_field_rules — no need to write into third-party plugin folders.', 'wpmmcc-ats' ); ?></p>
			<p>
				<label for="wptsall-fr-slug"><strong><?php esc_html_e( 'Plugin slug', 'wpmmcc-ats' ); ?></strong></label><br>
				<input type="text" id="wptsall-fr-slug" class="regular-text" placeholder="give" />
			</p>
			<p>
				<label for="wptsall-fr-json"><strong><?php esc_html_e( 'Field-rules JSON', 'wpmmcc-ats' ); ?></strong></label><br>
				<textarea id="wptsall-fr-json" rows="18" class="large-text code" style="font-family:monospace" placeholder='{"plugin_slug":"give","field_rules":{...}}'></textarea>
			</p>
			<p>
				<button type="button" class="button" id="wptsall-fr-validate"><?php esc_html_e( 'Validate', 'wpmmcc-ats' ); ?></button>
				<button type="button" class="button button-primary" id="wptsall-fr-save"><?php esc_html_e( 'Save to site', 'wpmmcc-ats' ); ?></button>
				<button type="button" class="button" id="wptsall-fr-delete"><?php esc_html_e( 'Delete site doc', 'wpmmcc-ats' ); ?></button>
			</p>
			<pre id="wptsall-fr-result" style="background:#f6f7f7;padding:12px;max-height:240px;overflow:auto"></pre>
		</div>
		<?php
	}

	/**
	 * Render Templates Tab - Plugin list with scan status
	 *
	 * Shows all scanned plugins with:
	 * - Plugin name and slug
	 * - Post types count
	 * - Scan status (scanned/pending)
	 * - Actions (view rules, rescan)
	 *
	 * @since 0.9.2
	 * @param array $models      Models list.
	 * @param int   $total       Total count.
	 * @param int   $total_pages Total pages.
	 * @param int   $current_page Current page.
	 */
	protected static function render_templates_tab( $models, $total, $total_pages, $current_page ) {
		?>
		<div class="wptsall-templates-tab">
			<?php if ( empty( $models ) ) : ?>
				<div class="wptsall-no-models">
					<p><?php esc_html_e( 'No plugins scanned yet.', 'wpmmcc-ats' ); ?></p>
					<p><?php esc_html_e( 'Click the "Scan New Plugin" button above to select a content plugin and start creating a model.', 'wpmmcc-ats' ); ?></p>
				</div>
			<?php else : ?>
				<!-- Plugin search filter -->
				<div class="tablenav top">
					<div class="alignleft actions">
						<input type="text" id="wptsall-template-search" placeholder="<?php esc_attr_e( 'Filter plugins...', 'wpmmcc-ats' ); ?>" class="regular-text" style="vertical-align:middle;">
					</div>
				</div>
				<table class="wp-list-table widefat fixed striped wptsall-templates-table">
					<thead>
						<tr>
							<th class="column-name"><?php esc_html_e( 'Plugin Name', 'wpmmcc-ats' ); ?></th>
							<th class="column-post-types"><?php esc_html_e( 'Post Types', 'wpmmcc-ats' ); ?></th>
							<th class="column-scan-status"><?php esc_html_e( 'Scan Status', 'wpmmcc-ats' ); ?></th>
							<th class="column-rules-count"><?php esc_html_e( 'Rules', 'wpmmcc-ats' ); ?></th>
							<th class="column-actions"><?php esc_html_e( 'Actions', 'wpmmcc-ats' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $models as $model ) : ?>
								<?php
								$edit_url = add_query_arg( array(
									'page'     => 'wpmmcc-ats',
									'action'   => 'template',
									'model_id' => $model['id'],
								), admin_url( 'admin.php' ) );

								// Count post_types from JSON field (may contain detailed arrays).
								$post_types_raw = ! empty( $model['post_types'] ) ? json_decode( $model['post_types'], true ) : array();
								$post_types_raw = is_array( $post_types_raw ) ? $post_types_raw : array();
								$post_types     = array_values(
									array_filter(
										array_map(
											function ( $pt ) {
												return is_array( $pt ) ? ( $pt['name'] ?? '' ) : (string) $pt;
											},
											$post_types_raw
										)
									)
								);
								$post_types_count = count( $post_types );

							// Rule count
							$rule_count = isset( $model['rule_count'] ) ? (int) $model['rule_count'] : 0;

							// Check scan status
							$last_scanned = ! empty( $model['last_scanned'] ) ? $model['last_scanned'] : '';
							$is_scanned   = ! empty( $last_scanned );

							// Check if plugin is active
							$is_plugin_active = self::is_plugin_active_by_slug( $model['plugin_slug'] );

							// Source type
							$source_type = $model['source_type'] ?? 'auto';
							$is_manual   = 'manual' === $source_type;
							
							$has_model = ! empty( $model['id'] );
							?>
							<tr data-model-id="<?php echo esc_attr( $model['id'] ); ?>" data-plugin-slug="<?php echo esc_attr( $model['plugin_slug'] ); ?>">
								<td class="column-name">
									<strong>
										<?php if ( $has_model ) : ?>
												<a href="<?php echo esc_url( $edit_url ); ?>">
													<?php echo esc_html( $model['plugin_name'] ); ?>
												</a>
										<?php else : ?>
											<?php echo esc_html( $model['plugin_name'] ); ?>
										<?php endif; ?>
									</strong>
									<?php if ( $is_manual ) : ?>
										<span class="wptsall-source-badge wptsall-source-manual"><?php esc_html_e( 'Manual', 'wpmmcc-ats' ); ?></span>
									<?php endif; ?>
									<br>
									<span class="description"><?php echo esc_html( $model['plugin_slug'] ); ?></span>
									<?php if ( ! $is_plugin_active ) : ?>
										<br><span class="wptsall-status wptsall-status-inactive"><?php esc_html_e( 'Plugin not active', 'wpmmcc-ats' ); ?></span>
									<?php endif; ?>
								</td>
								<td class="column-post-types">
									<span class="wptsall-count-badge"><?php echo esc_html( $post_types_count ); ?></span>
									<?php if ( $post_types_count > 0 && is_array( $post_types ) ) : ?>
										<span class="description" title="<?php echo esc_attr( implode( ', ', $post_types ) ); ?>">
											<?php echo esc_html( implode( ', ', array_slice( $post_types, 0, 3 ) ) ); ?>
											<?php if ( $post_types_count > 3 ) : ?>
												...
											<?php endif; ?>
										</span>
									<?php endif; ?>
								</td>
								<td class="column-scan-status">
									<?php if ( $is_scanned ) : ?>
										<span class="wptsall-scan-status wptsall-scan-status-scanned" title="<?php echo esc_attr( $last_scanned ); ?>">
											✅ <?php esc_html_e( 'Scanned', 'wpmmcc-ats' ); ?>
										</span>
									<?php else : ?>
										<span class="wptsall-scan-status wptsall-scan-status-pending">
											⏳ <?php esc_html_e( 'Pending', 'wpmmcc-ats' ); ?>
										</span>
									<?php endif; ?>
								</td>
								<td class="column-rules-count">
									<?php if ( $has_model ) : ?>
										<span class="wptsall-count-badge"><?php echo esc_html( $rule_count ); ?></span>
									<?php else : ?>
										<span class="description">-</span>
									<?php endif; ?>
								</td>
										<td class="column-actions">
											<?php if ( $has_model ) : ?>
												<a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small"><?php esc_html_e( 'Edit', 'wpmmcc-ats' ); ?></a>
											<?php endif; ?>
										<?php if ( $rule_count > 0 ) : ?>
											<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'wpmmcc-ats', 'tab' => 'rules', 'plugin' => $model['plugin_slug'] ), admin_url( 'admin.php' ) ) ); ?>" class="button button-small"><?php esc_html_e( 'View Rules', 'wpmmcc-ats' ); ?></a>
										<?php endif; ?>
										<button type="button" class="button button-small wptsall-rescan-btn" 
											data-model-id="<?php echo esc_attr( $model['id'] ); ?>"
											data-plugin-slug="<?php echo esc_attr( $model['plugin_slug'] ); ?>">
										<?php echo $is_manual ? esc_html__( 'Verify', 'wpmmcc-ats' ) : esc_html__( 'Scan', 'wpmmcc-ats' ); ?>
									</button>
									<?php if ( $has_model && empty( $model['is_system'] ) ) : ?>
										<button type="button" class="button button-small button-link-delete wptsall-delete-model-btn" data-model-id="<?php echo esc_attr( $model['id'] ); ?>"><?php esc_html_e( 'Delete', 'wpmmcc-ats' ); ?></button>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( $total_pages > 1 ) : ?>
					<div class="tablenav bottom">
						<div class="tablenav-pages">
							<span class="displaying-num">
								<?php
								/* translators: %s: total number of items */
								printf( esc_html__( '%s items', 'wpmmcc-ats' ), number_format_i18n( $total ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- number_format_i18n() is safe
								?>
							</span>
							<span class="pagination-links">
								<?php
								$page_links = paginate_links( array(
									'base'      => add_query_arg( array( 'paged' => '%#%', 'tab' => 'templates' ) ),
									'format'    => '',
									'prev_text' => '&laquo;',
									'next_text' => '&raquo;',
									'total'     => $total_pages,
									'current'   => $current_page,
								) );
								echo wp_kses_post( $page_links );
								?>
							</span>
						</div>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>

		<?php
		$script_rel  = 'assets/js/model-template-search.js';
		$script_path = WPTSALL_PATH . $script_rel;
		wp_enqueue_script(
			'wptsall-model-template-search',
			WPTSALL_URL . $script_rel,
			array( 'jquery' ),
			file_exists( $script_path ) ? (string) filemtime( $script_path ) : WPTSALL_VERSION,
			true
		);
		}

		/**
		 * Render plugin template objects page (B: plugin -> objects).
		 *
		 * @param int $model_id Model ID.
		 */
		protected static function render_template_page( $model_id ) {
			$model_id = (int) $model_id;

			$mapping = Plugin_Mapping_Service::get_by_id( $model_id );
			if ( ! $mapping ) {
				wp_die( esc_html__( 'Plugin template not found', 'wpmmcc-ats' ) );
			}

			$objects = Model_Object_Service::get_objects_for_model( $model_id );
			$diagnostics = Model_Object_Service::get_model_diagnostics( $model_id );

			// Field counts by object_id (single query).
			$field_counts = array();
			if ( ! empty( $objects ) ) {
				global $wpdb;
				$fields_table = wptsall_table( 'model_object_fields' );
				$ids          = array_map(
					function ( $o ) {
						return (int) ( $o['id'] ?? 0 );
					},
					$objects
				);
				$ids = array_values( array_filter( $ids ) );

				if ( ! empty( $ids ) ) {
					list( $in_sql, $in_args ) = wptsall_db_prepare_int_in( $ids );
					$rows                     = wptsall_db_get_results(
						"SELECT object_id, COUNT(*) AS cnt FROM %i WHERE object_id IN ($in_sql) GROUP BY object_id",
						array_merge( array( $fields_table ), $in_args ),
						ARRAY_A
					);
					foreach ( (array) $rows as $r ) {
						$field_counts[ (int) $r['object_id'] ] = (int) $r['cnt'];
					}
				}
			}

			$back_url = add_query_arg(
				array(
					'page' => 'wpmmcc-ats',
					'tab'  => 'templates',
				),
				admin_url( 'admin.php' )
			);

			$rules_url = add_query_arg(
				array(
					'page'     => 'wpmmcc-ats',
					'action'   => 'edit',
					'model_id' => $model_id,
				),
				admin_url( 'admin.php' )
			);

			$page_title = sprintf(
				/* translators: %s: plugin name */
				esc_html__( '%s - Plugin Template', 'wpmmcc-ats' ),
				esc_html( $mapping['plugin_name'] ?? '' )
			);

			Admin_Page_Helper::render_header(
				$page_title,
				'models',
				array(
					array(
						'label' => __( 'Translation Rules', 'wpmmcc-ats' ),
						'url'   => $rules_url,
						'class' => 'button button-primary',
					),
					array(
						'label' => __( 'Back to Plugin List', 'wpmmcc-ats' ),
						'url'   => $back_url,
						'class' => 'button',
					),
				),
				array( 'wptsall-model-editor', 'wptsall-template-objects-page' )
			);
			?>
			<div class="wptsall-page-content" data-model-id="<?php echo esc_attr( $model_id ); ?>">
				<div class="wptsall-model-info-bar">
					<span class="wptsall-info-item">
						<strong><?php esc_html_e( 'Plugin:', 'wpmmcc-ats' ); ?></strong>
						<?php echo esc_html( $mapping['plugin_slug'] ?? '' ); ?>
					</span>
					<span class="wptsall-info-item">
						<strong><?php esc_html_e( 'Objects:', 'wpmmcc-ats' ); ?></strong>
						<span id="wptsall-object-count"><?php echo esc_html( count( $objects ) ); ?></span>
					</span>
					<span class="wptsall-info-item" style="margin-left:auto;">
						<button type="button" class="button button-primary" id="wptsall-add-object-btn">
							<?php esc_html_e( '+ Add Object', 'wpmmcc-ats' ); ?>
						</button>
					</span>
				</div>

				<p class="description">
					<?php esc_html_e( 'Manage storage objects and fields for this plugin. Click an object name to view and edit fields.', 'wpmmcc-ats' ); ?>
				</p>

				<div class="postbox" style="margin-bottom:16px;">
					<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Scan Diagnostics', 'wpmmcc-ats' ); ?></h2></div>
					<div class="inside">
						<p class="description"><?php esc_html_e( 'These counts help identify where auto-scan output still needs admin review before later relation/rule work relies on it.', 'wpmmcc-ats' ); ?></p>
						<div style="display:flex;gap:12px;flex-wrap:wrap;margin:12px 0;">
							<span class="wptsall-count-badge"><?php echo esc_html( (int) ( $diagnostics['summary']['total_fields'] ?? 0 ) ); ?> <?php esc_html_e( 'total fields', 'wpmmcc-ats' ); ?></span>
							<span class="wptsall-count-badge" style="background:#fff3cd;color:#7a5b00;"><?php echo esc_html( (int) ( $diagnostics['summary']['low_confidence'] ?? 0 ) ); ?> <?php esc_html_e( 'low confidence', 'wpmmcc-ats' ); ?></span>
							<span class="wptsall-count-badge" style="background:#fde2e1;color:#8a1f11;"><?php echo esc_html( (int) ( $diagnostics['summary']['unapproved'] ?? 0 ) ); ?> <?php esc_html_e( 'unapproved', 'wpmmcc-ats' ); ?></span>
							<span class="wptsall-count-badge" style="background:#e7f3ff;color:#0b57a4;"><?php echo esc_html( (int) ( $diagnostics['summary']['manual_override'] ?? 0 ) ); ?> <?php esc_html_e( 'manual override', 'wpmmcc-ats' ); ?></span>
						</div>
						<?php if ( ! empty( $diagnostics['low_confidence_fields'] ) ) : ?>
							<p><strong><?php esc_html_e( 'Examples needing review:', 'wpmmcc-ats' ); ?></strong></p>
							<ul style="margin-left:18px;list-style:disc;">
								<?php foreach ( array_slice( $diagnostics['low_confidence_fields'], 0, 5 ) as $diag_row ) : ?>
									<li><code><?php echo esc_html( $diag_row['field_key'] ?? '' ); ?></code> — <?php echo esc_html( $diag_row['object_name'] ?? '' ); ?> (<?php echo esc_html( (string) ( $diag_row['confidence_score'] ?? 0 ) ); ?>)</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</div>
				</div>

				<?php if ( empty( $objects ) ) : ?>
					<div class="wptsall-no-models" id="wptsall-objects-empty">
						<p><?php esc_html_e( 'No storage objects found. Click "Add Object" or run a scan.', 'wpmmcc-ats' ); ?></p>
					</div>
				<?php endif; ?>

				<table class="wp-list-table widefat fixed striped wptsall-templates-table" id="wptsall-objects-table" <?php echo empty( $objects ) ? 'style="display:none;"' : ''; ?>>
					<thead>
						<tr>
							<th class="column-name"><?php esc_html_e( 'Object Name', 'wpmmcc-ats' ); ?></th>
							<th class="column-post-types"><?php esc_html_e( 'Type', 'wpmmcc-ats' ); ?></th>
							<th class="column-url-pattern"><?php esc_html_e( 'URL Signature', 'wpmmcc-ats' ); ?></th>
							<th class="column-rules-count"><?php esc_html_e( 'Fields', 'wpmmcc-ats' ); ?></th>
							<th class="column-actions"><?php esc_html_e( 'Actions', 'wpmmcc-ats' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $objects as $obj ) : ?>
							<?php
							$object_id   = (int) ( $obj['id'] ?? 0 );
							$object_type = $obj['object_type'] ?? '';
							$object_name = $obj['object_name'] ?? '';
							$url_sig     = $obj['url_signature'] ?? '';
							$count       = $field_counts[ $object_id ] ?? 0;

							$detail_url = add_query_arg(
								array(
									'page'      => 'wpmmcc-ats',
									'action'    => 'template_object',
									'model_id'  => $model_id,
									'object_id' => $object_id,
								),
								admin_url( 'admin.php' )
							);
							?>
							<tr data-object-id="<?php echo esc_attr( $object_id ); ?>">
								<td class="column-name">
									<strong>
										<a href="<?php echo esc_url( $detail_url ); ?>">
											<?php echo esc_html( $object_name ); ?>
										</a>
									</strong>
									<br>
									<span class="description"><?php echo esc_html( $object_type ); ?></span>
								</td>
								<td class="column-post-types">
									<span class="wptsall-url-type-badge wptsall-url-type-<?php echo esc_attr( $object_type ); ?>">
										<?php echo esc_html( $object_type ); ?>
									</span>
								</td>
								<td class="column-url-pattern">
									<?php if ( ! empty( $url_sig ) ) : ?>
										<code><?php echo esc_html( $url_sig ); ?></code>
									<?php else : ?>
										<span class="description">-</span>
									<?php endif; ?>
								</td>
								<td class="column-rules-count">
									<span class="wptsall-count-badge"><?php echo esc_html( $count ); ?></span>
								</td>
								<td class="column-actions">
									<a href="<?php echo esc_url( $detail_url ); ?>" class="button button-small"><?php esc_html_e( 'Edit', 'wpmmcc-ats' ); ?></a>
									<button type="button" class="button button-small button-link-delete wptsall-delete-object-btn" data-object-id="<?php echo esc_attr( $object_id ); ?>"><?php esc_html_e( 'Delete', 'wpmmcc-ats' ); ?></button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php
			Admin_Page_Helper::render_footer();
		}

		/**
		 * Render plugin template object detail page (B: plugin -> object -> fields).
		 *
		 * @param int $model_id  Model ID.
		 * @param int $object_id Object ID.
		 */
		protected static function render_template_object_page( $model_id, $object_id ) {
			$model_id  = (int) $model_id;
			$object_id = (int) $object_id;

			$mapping = Plugin_Mapping_Service::get_by_id( $model_id );
			if ( ! $mapping ) {
				wp_die( esc_html__( 'Plugin template not found', 'wpmmcc-ats' ) );
			}

			$object = Model_Object_Service::get_object( $object_id );
			if ( ! $object || (int) ( $object['model_id'] ?? 0 ) !== $model_id ) {
				wp_die( esc_html__( 'Storage object not found', 'wpmmcc-ats' ) );
			}

			$fields = Model_Object_Service::get_fields_for_object( $object_id );

			$back_url = add_query_arg(
				array(
					'page'     => 'wpmmcc-ats',
					'action'   => 'template',
					'model_id' => $model_id,
				),
				admin_url( 'admin.php' )
			);

			$rules_url = add_query_arg(
				array(
					'page'     => 'wpmmcc-ats',
					'action'   => 'edit',
					'model_id' => $model_id,
				),
				admin_url( 'admin.php' )
			);

			$page_title = sprintf(
				/* translators: 1: plugin name, 2: object name */
				esc_html__( '%1$s - %2$s', 'wpmmcc-ats' ),
				esc_html( $mapping['plugin_name'] ?? '' ),
				esc_html( $object['object_name'] ?? '' )
			);

			Admin_Page_Helper::render_header(
				$page_title,
				'models',
				array(
					array(
						'label' => __( 'Translation Rules', 'wpmmcc-ats' ),
						'url'   => $rules_url,
						'class' => 'button button-primary',
					),
					array(
						'label' => __( 'Back to Plugin Template', 'wpmmcc-ats' ),
						'url'   => $back_url,
						'class' => 'button',
					),
				),
				array( 'wptsall-model-editor', 'wptsall-template-object-page' )
			);
			?>
			<div class="wptsall-page-content" data-model-id="<?php echo esc_attr( $model_id ); ?>" data-object-id="<?php echo esc_attr( $object_id ); ?>">
				<div class="wptsall-model-info-bar">
					<span class="wptsall-info-item">
						<strong><?php esc_html_e( 'Object Type:', 'wpmmcc-ats' ); ?></strong>
						<?php echo esc_html( $object['object_type'] ?? '' ); ?>
					</span>
					<?php if ( ! empty( $object['url_signature'] ) ) : ?>
						<span class="wptsall-info-item">
							<strong><?php esc_html_e( 'URL Signature:', 'wpmmcc-ats' ); ?></strong>
							<code><?php echo esc_html( $object['url_signature'] ); ?></code>
						</span>
					<?php endif; ?>
					<span class="wptsall-info-item">
						<strong><?php esc_html_e( 'Fields:', 'wpmmcc-ats' ); ?></strong>
						<span id="wptsall-field-count"><?php echo esc_html( count( $fields ) ); ?></span>
					</span>
					<span class="wptsall-info-item" style="margin-left:auto;">
						<button type="button" class="button button-primary" id="wptsall-add-field-btn">
							<?php esc_html_e( '+ Add Field', 'wpmmcc-ats' ); ?>
						</button>
					</span>
				</div>

				<?php if ( empty( $fields ) ) : ?>
					<div class="wptsall-no-models" id="wptsall-fields-empty">
						<p><?php esc_html_e( 'No field records found. Click "Add Field" to start.', 'wpmmcc-ats' ); ?></p>
					</div>
				<?php endif; ?>

				<table class="wp-list-table widefat fixed striped wptsall-rules-table" id="wptsall-fields-table" <?php echo empty( $fields ) ? 'style="display:none;"' : ''; ?>>
					<thead>
						<tr>
							<th class="column-name"><?php esc_html_e( 'Fields', 'wpmmcc-ats' ); ?></th>
							<th class="column-url-type"><?php esc_html_e( 'Type', 'wpmmcc-ats' ); ?></th>
							<th class="column-scan-status"><?php esc_html_e( 'Source', 'wpmmcc-ats' ); ?></th>
							<th class="column-url-pattern"><?php esc_html_e( 'Extra Info', 'wpmmcc-ats' ); ?></th>
							<th class="column-actions"><?php esc_html_e( 'Actions', 'wpmmcc-ats' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $fields as $f ) : ?>
							<?php
							$field_id  = (int) ( $f['id'] ?? 0 );
							$extra     = $f['extra'] ?? null;
							$extra_str = '';
							if ( is_array( $extra ) ) {
								$extra_str = wp_json_encode( $extra );
							} elseif ( is_string( $extra ) ) {
								$extra_str = $extra;
							}
							?>
							<tr data-field-id="<?php echo esc_attr( $field_id ); ?>" data-status="<?php echo esc_attr( $f['status'] ?? 'active' ); ?>">
								<td class="column-name">
									<code><?php echo esc_html( $f['field_key'] ?? '' ); ?></code>
								</td>
								<td class="column-url-type">
									<select class="wptsall-field-kind-select" data-field-id="<?php echo esc_attr( $field_id ); ?>">
										<option value="core" <?php selected( $f['field_kind'] ?? '', 'core' ); ?>>core</option>
										<option value="meta" <?php selected( $f['field_kind'] ?? '', 'meta' ); ?>>meta</option>
										<option value="column" <?php selected( $f['field_kind'] ?? '', 'column' ); ?>>column</option>
									</select>
								</td>
								<td class="column-scan-status">
									<select class="wptsall-field-source-select" data-field-id="<?php echo esc_attr( $field_id ); ?>">
										<option value="scan" <?php selected( $f['source'] ?? '', 'scan' ); ?>>scan</option>
										<option value="manual" <?php selected( $f['source'] ?? '', 'manual' ); ?>>manual</option>
										<option value="derived" <?php selected( $f['source'] ?? '', 'derived' ); ?>>derived</option>
										<option value="core" <?php selected( $f['source'] ?? '', 'core' ); ?>>core</option>
									</select>
									<div class="description" style="margin-top:6px;">
										<?php echo esc_html( $f['source_origin'] ?? '' ); ?>
									</div>
								</td>
								<td class="column-url-pattern">
									<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:6px;">
										<?php if ( isset( $f['confidence_score'] ) ) : ?>
											<span class="wptsall-count-badge" style="background:#f0f4f8;color:#334;"><?php echo esc_html( (int) $f['confidence_score'] ); ?>%</span>
										<?php endif; ?>
										<?php if ( ! empty( $f['is_admin_approved'] ) ) : ?>
											<span class="wptsall-count-badge" style="background:#e6f6ea;color:#1e6b34;"><?php esc_html_e( 'approved', 'wpmmcc-ats' ); ?></span>
										<?php else : ?>
											<span class="wptsall-count-badge" style="background:#fde2e1;color:#8a1f11;"><?php esc_html_e( 'unapproved', 'wpmmcc-ats' ); ?></span>
										<?php endif; ?>
										<?php if ( ! empty( $f['is_manual_override'] ) ) : ?>
											<span class="wptsall-count-badge" style="background:#e7f3ff;color:#0b57a4;"><?php esc_html_e( 'manual override', 'wpmmcc-ats' ); ?></span>
										<?php endif; ?>
									</div>
									<?php if ( ! empty( $f['confidence_reason'] ) ) : ?>
										<div class="description" style="margin-bottom:6px;"><?php echo esc_html( $f['confidence_reason'] ); ?></div>
									<?php endif; ?>
									<?php if ( '' !== $extra_str ) : ?>
										<code class="wptsall-extra-display"><?php echo esc_html( $extra_str ); ?></code>
									<?php else : ?>
										<span class="description">-</span>
									<?php endif; ?>
								</td>
								<td class="column-actions">
									<button type="button" class="button button-small button-link-delete wptsall-delete-field-btn" data-field-id="<?php echo esc_attr( $field_id ); ?>"><?php esc_html_e( 'Delete', 'wpmmcc-ats' ); ?></button>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
			</div>
			<?php
			Admin_Page_Helper::render_footer();
		}

		/**
		 * Render Rules Tab - Translation rules list
		 *
		 * Shows all translation rules with:
	 * - Rule name (post_type)
	 * - Plugin name
	 * - URL pattern
	 * - URL type
	 * - Actions (edit, delete)
	 *
	 * @since 0.9.2
	 * @param array $models      Models list (used for getting rules).
	 * @param int   $total       Total count.
	 * @param int   $total_pages Total pages.
	 * @param int   $current_page Current page.
	 */
	protected static function render_rules_tab( $models, $total, $total_pages, $current_page ) {
		// Get plugin filter if set
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$plugin_filter = isset( $_GET['plugin'] ) ? sanitize_key( $_GET['plugin'] ) : '';

		// Get all translation rules
		$rules = Translation_Rule_Service::get_all_rules( array(
			'per_page'    => 20,
			'page'        => $current_page,
			'plugin_slug' => $plugin_filter,
		) );

		$rules_list  = $rules['rules'] ?? array();
		$rules_total = $rules['total'] ?? 0;
		$rules_pages = $rules['total_pages'] ?? 1;

		// Build plugin options for filter
		$plugin_options = array();
		foreach ( $models as $model ) {
			$plugin_options[ $model['plugin_slug'] ] = $model['plugin_name'];
		}
		?>
		<div class="wptsall-rules-tab">
			<!-- Filter -->
			<div class="tablenav top">
				<div class="alignleft actions">
					<select id="wptsall-plugin-filter" name="plugin">
						<option value=""><?php esc_html_e( 'All Plugins', 'wpmmcc-ats' ); ?></option>
						<?php foreach ( $plugin_options as $slug => $name ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $plugin_filter, $slug ); ?>>
								<?php echo esc_html( $name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<button type="button" id="wptsall-filter-btn" class="button"><?php esc_html_e( 'Filter', 'wpmmcc-ats' ); ?></button>
					<?php if ( $plugin_filter ) : ?>
						<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'wpmmcc-ats', 'tab' => 'rules' ), admin_url( 'admin.php' ) ) ); ?>" class="button"><?php esc_html_e( 'Clear Filter', 'wpmmcc-ats' ); ?></a>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( empty( $rules_list ) ) : ?>
				<div class="wptsall-no-models">
					<?php if ( $plugin_filter ) : ?>
						<p><?php esc_html_e( 'No translation rules for this plugin.', 'wpmmcc-ats' ); ?></p>
					<?php else : ?>
						<p><?php esc_html_e( 'No translation rules created yet.', 'wpmmcc-ats' ); ?></p>
						<p><?php esc_html_e( 'Please scan a plugin in the "Plugin Templates" tab first, then edit to generate translation rules.', 'wpmmcc-ats' ); ?></p>
					<?php endif; ?>
				</div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped wptsall-rules-table">
					<thead>
						<tr>
							<th class="column-name"><?php esc_html_e( 'Rule Name', 'wpmmcc-ats' ); ?></th>
							<th class="column-plugin"><?php esc_html_e( 'Plugin', 'wpmmcc-ats' ); ?></th>
							<th class="column-url-pattern"><?php esc_html_e( 'URL Pattern', 'wpmmcc-ats' ); ?></th>
							<th class="column-url-type"><?php esc_html_e( 'Type', 'wpmmcc-ats' ); ?></th>
							<th class="column-actions"><?php esc_html_e( 'Actions', 'wpmmcc-ats' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rules_list as $rule ) : ?>
							<?php
							$rule_edit_url = add_query_arg( array(
								'page'     => 'wpmmcc-ats',
								'action'   => 'edit_rule',
								'model_id' => $rule['model_id'],
								'rule_id'  => $rule['id'],
							), admin_url( 'admin.php' ) );

							// Get plugin name
							$plugin_name = $rule['plugin_name'] ?? $rule['plugin_slug'] ?? '';
							?>
							<tr data-rule-id="<?php echo esc_attr( $rule['id'] ); ?>">
								<td class="column-name">
									<strong>
										<a href="<?php echo esc_url( $rule_edit_url ); ?>">
											<?php echo esc_html( $rule['name'] ?: $rule['object_name'] ); ?>
										</a>
									</strong>
									<br>
									<span class="description"><?php echo esc_html( $rule['object_name'] ); ?></span>
								</td>
								<td class="column-plugin">
									<?php echo esc_html( $plugin_name ); ?>
								</td>
								<td class="column-url-pattern">
									<code><?php echo esc_html( $rule['url_pattern'] ); ?></code>
								</td>
								<td class="column-url-type">
									<span class="wptsall-url-type-badge wptsall-url-type-<?php echo esc_attr( $rule['url_type'] ); ?>">
										<?php echo esc_html( $rule['url_type'] ); ?>
									</span>
								</td>
								<td class="column-actions">
									<a href="<?php echo esc_url( $rule_edit_url ); ?>" class="button button-small"><?php esc_html_e( 'Edit', 'wpmmcc-ats' ); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( $rules_pages > 1 ) : ?>
					<div class="tablenav bottom">
						<div class="tablenav-pages">
							<span class="displaying-num">
								<?php
								/* translators: %s: total number of items */
								printf( esc_html__( '%s items', 'wpmmcc-ats' ), number_format_i18n( $rules_total ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- number_format_i18n() is safe
								?>
							</span>
							<span class="pagination-links">
								<?php
								$base_args = array( 'paged' => '%#%', 'tab' => 'rules' );
								if ( $plugin_filter ) {
									$base_args['plugin'] = $plugin_filter;
								}
								$page_links = paginate_links( array(
									'base'      => add_query_arg( $base_args ),
									'format'    => '',
									'prev_text' => '&laquo;',
									'next_text' => '&raquo;',
									'total'     => $rules_pages,
									'current'   => $current_page,
								) );
								echo wp_kses_post( $page_links );
								?>
							</span>
						</div>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>

		<?php
		$rules_url    = admin_url( 'admin.php?page=wpmmcc-ats&tab=rules' );
		$script_rel   = 'assets/js/model-rules-filter.js';
		$script_path  = WPTSALL_PATH . $script_rel;
		wp_enqueue_script(
			'wptsall-model-rules-filter',
			WPTSALL_URL . $script_rel,
			array( 'jquery' ),
			file_exists( $script_path ) ? (string) filemtime( $script_path ) : WPTSALL_VERSION,
			true
		);
		wp_localize_script(
			'wptsall-model-rules-filter',
			'wptsallModelRulesFilter',
			array(
				'rulesUrl' => $rules_url,
			)
		);
	}

	/**
	 * Render custom model wizard page
	 *
	 * Five-step wizard for creating custom models:
	 * 1. Select plugin
	 * 2. URL pattern
	 * 3. Link chain configuration
	 * 4. Select fields
	 * 5. Validate and save
	 *
	 * @since 0.7.1
	 */
	protected static function render_wizard_page() {
		$back_url = add_query_arg( 'page', 'wpmmcc-ats', admin_url( 'admin.php' ) );
		Admin_Page_Helper::render_header(
			__( 'Add Custom Model', 'wpmmcc-ats' ),
			'models',
			array(
				array(
					'label' => __( 'Back to Model List', 'wpmmcc-ats' ),
					'url'   => $back_url,
					'class' => 'button',
				),
			),
			array( 'wptsall-wizard-page' )
		);
		?>
				<div class="wptsall-page-content">
					<div class="wptsall-wizard">
				<!-- Wizard Header -->
				<div class="wptsall-wizard-header">
					<h2><?php esc_html_e( 'Custom Model Creation Wizard', 'wpmmcc-ats' ); ?></h2>
					<p><?php esc_html_e( 'Use this wizard to create custom model configurations for any plugin, including extensions (SEO, ACF, etc.).', 'wpmmcc-ats' ); ?></p>
				</div>

				<!-- Step Indicators -->
				<div class="wptsall-wizard-steps">
					<div class="wptsall-wizard-step active" data-step="1">
						<span class="wptsall-wizard-step-number">1</span>
						<span class="wptsall-wizard-step-label"><?php esc_html_e( 'Select Plugin', 'wpmmcc-ats' ); ?></span>
					</div>
					<div class="wptsall-wizard-step" data-step="2">
						<span class="wptsall-wizard-step-number">2</span>
						<span class="wptsall-wizard-step-label"><?php esc_html_e( 'URL Pattern', 'wpmmcc-ats' ); ?></span>
					</div>
					<div class="wptsall-wizard-step" data-step="3">
						<span class="wptsall-wizard-step-number">3</span>
						<span class="wptsall-wizard-step-label"><?php esc_html_e( 'Link Chains', 'wpmmcc-ats' ); ?></span>
					</div>
					<div class="wptsall-wizard-step" data-step="4">
						<span class="wptsall-wizard-step-number">4</span>
						<span class="wptsall-wizard-step-label"><?php esc_html_e( 'Select Fields', 'wpmmcc-ats' ); ?></span>
					</div>
					<div class="wptsall-wizard-step" data-step="5">
						<span class="wptsall-wizard-step-number">5</span>
						<span class="wptsall-wizard-step-label"><?php esc_html_e( 'Validate & Save', 'wpmmcc-ats' ); ?></span>
					</div>
				</div>

				<!-- Step Content -->
				<div class="wptsall-wizard-content">
					<!-- Step 1: Select Plugin -->
					<div class="wptsall-wizard-step-content active" data-step="1">
						<h3><?php esc_html_e( 'Select Plugin', 'wpmmcc-ats' ); ?></h3>
						<p class="description"><?php esc_html_e( 'Select the plugin to create a model for. The list only shows active plugins without registered models.', 'wpmmcc-ats' ); ?></p>

						<div class="wptsall-wizard-field">
							<label for="wptsall-plugin-select"><?php esc_html_e( 'Select Plugin', 'wpmmcc-ats' ); ?></label>
							<select id="wptsall-plugin-select">
								<option value=""><?php esc_html_e( 'Loading...', 'wpmmcc-ats' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'These plugins are not yet registered in model management.', 'wpmmcc-ats' ); ?></p>
						</div>

						<div class="wptsall-plugin-data-check" style="display: none;"></div>
					</div>

					<!-- Step 2: URL Pattern -->
					<div class="wptsall-wizard-step-content" data-step="2">
						<h3><?php esc_html_e( 'Set URL Pattern', 'wpmmcc-ats' ); ?></h3>
						<p class="description"><?php esc_html_e( 'Define the frontend URL pattern for this model. Use {parameter} as dynamic placeholders.', 'wpmmcc-ats' ); ?></p>

						<div class="wptsall-wizard-field">
							<label for="wptsall-url-pattern"><?php esc_html_e( 'URL Pattern', 'wpmmcc-ats' ); ?></label>
							<input type="text" id="wptsall-url-pattern" placeholder="?post_type=product&p={id}">
							<p class="description"><?php esc_html_e( 'Example: /product/{slug}/ or /category/{term}/', 'wpmmcc-ats' ); ?></p>
						</div>

						<div class="wptsall-url-pattern-preview" style="display: none;"></div>

						<div class="wptsall-wizard-field">
							<label for="wptsall-url-type"><?php esc_html_e( 'URL Type', 'wpmmcc-ats' ); ?></label>
							<select id="wptsall-url-type">
								<option value="frontend"><?php esc_html_e( 'Frontend Page', 'wpmmcc-ats' ); ?></option>
								<option value="admin"><?php esc_html_e( 'Admin Page', 'wpmmcc-ats' ); ?></option>
								<option value="ajax"><?php esc_html_e( 'AJAX Request', 'wpmmcc-ats' ); ?></option>
								<option value="rest_api"><?php esc_html_e( 'REST API', 'wpmmcc-ats' ); ?></option>
							</select>
						</div>
					</div>

					<!-- Step 3: Link Chain -->
					<div class="wptsall-wizard-step-content" data-step="3">
						<h3><?php esc_html_e( 'Configure Link Chains', 'wpmmcc-ats' ); ?></h3>
						<p class="description"><?php esc_html_e( 'Configure link chains from URL parameters to database records. For simple models, this step can be skipped.', 'wpmmcc-ats' ); ?></p>

						<div class="wptsall-link-chain-builder"></div>
					</div>

					<!-- Step 4: Select Fields -->
					<div class="wptsall-wizard-step-content" data-step="4">
						<h3><?php esc_html_e( 'Select Fields', 'wpmmcc-ats' ); ?></h3>
						<p class="description"><?php esc_html_e( 'Select fields that need translation, sync, or mapping.', 'wpmmcc-ats' ); ?></p>

						<div class="wptsall-field-selection"></div>
					</div>

					<!-- Step 5: Validation -->
					<div class="wptsall-wizard-step-content" data-step="5">
						<h3><?php esc_html_e( 'Validate Configuration', 'wpmmcc-ats' ); ?></h3>
						<p class="description"><?php esc_html_e( 'Review the configuration summary, then save the model when confirmed.', 'wpmmcc-ats' ); ?></p>

						<div class="wptsall-validation-summary"></div>
					</div>
				</div>

				<!-- Wizard Footer -->
				<div class="wptsall-wizard-footer">
					<button type="button" class="button wptsall-wizard-prev" disabled><?php esc_html_e( 'Previous', 'wpmmcc-ats' ); ?></button>
					<button type="button" class="button button-primary wptsall-wizard-next"><?php esc_html_e( 'Next', 'wpmmcc-ats' ); ?></button>
					<button type="button" class="button button-primary wptsall-wizard-save" style="display: none;"><?php esc_html_e( 'Save Model', 'wpmmcc-ats' ); ?></button>
				</div>
			</div><!-- .wptsall-wizard -->
				</div><!-- .wptsall-page-content -->
		<?php
		Admin_Page_Helper::render_footer();
	}

	/**
	 * Render model edit page (V2 - Rules list without tabs)
	 *
	 * Shows all rules in a simple table sorted by url_pattern A-Z with pagination.
	 * Click "Edit" goes to a separate rule edit page.
	 *
	 * @param int $model_id Model ID.
	 */
	protected static function render_edit_page( $model_id ) {
		// Use Translation_Rule_Service for V2 data structure
		$model = Translation_Rule_Service::get_model( $model_id );

		if ( ! $model ) {
			wp_die( esc_html__( 'Model not found', 'wpmmcc-ats' ) );
		}

		// Normalize post_types/taxonomies for UI rendering.
		// Scanner may store detailed objects (arrays with name/labels/etc). UI needs name lists.
		$model_post_types = is_array( $model['post_types'] ?? null ) ? $model['post_types'] : array();
		$model_taxonomies = is_array( $model['taxonomies'] ?? null ) ? $model['taxonomies'] : array();

		$model_pt_names = array_values(
			array_filter(
				array_unique(
					array_map(
						function ( $pt ) {
							return is_array( $pt ) ? ( $pt['name'] ?? '' ) : (string) $pt;
						},
						$model_post_types
					)
				)
			)
		);

		$model_tax_names = array_values(
			array_filter(
				array_unique(
					array_map(
						function ( $tax ) {
							return is_array( $tax ) ? ( $tax['name'] ?? '' ) : (string) $tax;
						},
						$model_taxonomies
					)
				)
			)
		);

		// Pagination parameters
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_page = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
		$per_page     = 20;

		// Get all rules for this model, sorted by url_pattern A-Z
		$rules_result = Translation_Rule_Service::get_rules_for_model(
			$model_id,
			array(
				'orderby'  => 'url_pattern',
				'order'    => 'ASC',
				'per_page' => $per_page,
				'page'     => $current_page,
			)
		);

		$rules       = $rules_result['items'] ?? array();
		$total       = $rules_result['total'] ?? 0;
		$total_pages = $rules_result['total_pages'] ?? 1;

		$back_url = add_query_arg( 'page', 'wpmmcc-ats', admin_url( 'admin.php' ) );
		$add_rule_url = add_query_arg(
			array(
				'page'     => 'wpmmcc-ats',
				'action'   => 'edit_rule',
				'model_id' => $model_id,
				'rule_id'  => 'new',
			),
			admin_url( 'admin.php' )
		);

		$page_title = sprintf(
			/* translators: %s: Model name */
			esc_html__( '%s - Translation Rules', 'wpmmcc-ats' ),
			esc_html( $model['plugin_name'] )
		);

		Admin_Page_Helper::render_header(
			$page_title,
			'models',
			array(
				array(
					'label' => __( 'Add Rule', 'wpmmcc-ats' ),
					'url'   => $add_rule_url,
					'class' => 'button button-primary',
				),
				array(
					'label' => __( 'Import Rule', 'wpmmcc-ats' ),
					'url'   => '#',
					'id'    => 'wptsall-import-rule-v3-btn',
					'class' => 'button',
				),
				array(
					'label' => __( 'Back to Model List', 'wpmmcc-ats' ),
					'url'   => $back_url,
					'class' => 'button',
				),
			),
			array( 'wptsall-model-editor', 'wptsall-rules-list-page' )
		);
		?>
				<div class="wptsall-page-content">
					<!-- H3: Hidden file input for V3 rule import -->
					<input type="file" id="wptsall-import-rule-v3-file" class="wptsall-import-file-input" accept=".json" data-model-id="<?php echo esc_attr( $model_id ); ?>">

					<!-- Model Info Bar -->
				<div class="wptsall-model-info-bar">
					<span class="wptsall-info-item">
						<strong><?php esc_html_e( 'Plugin:', 'wpmmcc-ats' ); ?></strong>
						<?php echo esc_html( $model['plugin_slug'] ); ?>
					</span>
					<?php if ( ! empty( $model_pt_names ) ) : ?>
						<span class="wptsall-info-item">
							<strong><?php esc_html_e( 'Post Types:', 'wpmmcc-ats' ); ?></strong>
							<?php echo esc_html( implode( ', ', $model_pt_names ) ); ?>
						</span>
					<?php endif; ?>
					<?php if ( ! empty( $model_tax_names ) ) : ?>
						<span class="wptsall-info-item">
							<strong><?php esc_html_e( 'Taxonomies:', 'wpmmcc-ats' ); ?></strong>
							<?php echo esc_html( implode( ', ', $model_tax_names ) ); ?>
						</span>
					<?php endif; ?>
					<span class="wptsall-info-item">
						<strong><?php esc_html_e( 'Total Rules:', 'wpmmcc-ats' ); ?></strong>
						<?php echo esc_html( $total ); ?>
				</span>
					<span class="wptsall-info-item">
						<strong><?php esc_html_e( 'Semantic Status:', 'wpmmcc-ats' ); ?></strong>
						<span class="wptsall-count-badge" style="background:<?php echo ( 'approved' === ( $model['semantic_status'] ?? 'draft' ) ) ? '#e6f6ea' : '#fde2e1'; ?>;color:<?php echo ( 'approved' === ( $model['semantic_status'] ?? 'draft' ) ) ? '#1e6b34' : '#8a1f11'; ?>;">
							<?php echo esc_html( $model['semantic_status'] ?? 'draft' ); ?>
						</span>
					</span>
			</div>

				<?php
				// Provide suggestion lists, but do NOT render "all types" as selectable options by default.
				// This page should reflect the model's stored configuration; users can add new items explicitly.
				$pt_objects  = get_post_types( array(), 'objects' );
				$tax_objects = get_taxonomies( array(), 'objects' );
				?>

			<!-- Model Basic Info Section (ISS-MOD-025) -->
			<div class="wptsall-model-basic-info postbox">
				<div class="postbox-header">
					<h2 class="hndle"><?php esc_html_e( 'Model Basic Info', 'wpmmcc-ats' ); ?></h2>
				</div>
				<div class="inside">
					<form id="wptsall-model-basic-info-form">
						<table class="form-table">
							<tr>
								<th scope="row"><label for="wptsall-model-plugin-name"><?php esc_html_e( 'Plugin Name', 'wpmmcc-ats' ); ?></label></th>
								<td>
									<input type="text" class="regular-text" id="wptsall-model-plugin-name" value="<?php echo esc_attr( $model['plugin_name'] ?? '' ); ?>">
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="wptsall-model-plugin-version"><?php esc_html_e( 'Plugin Version', 'wpmmcc-ats' ); ?></label></th>
								<td>
									<input type="text" class="regular-text" id="wptsall-model-plugin-version" value="<?php echo esc_attr( $model['plugin_version'] ?? '' ); ?>">
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="wptsall-model-text-domain"><?php esc_html_e( 'Text Domain', 'wpmmcc-ats' ); ?></label></th>
								<td>
									<input type="text" class="regular-text" id="wptsall-model-text-domain" value="<?php echo esc_attr( $model['text_domain'] ?? '' ); ?>">
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="wptsall-model-description"><?php esc_html_e( 'Description', 'wpmmcc-ats' ); ?></label></th>
								<td>
									<textarea class="large-text" rows="3" id="wptsall-model-description"><?php echo esc_textarea( $model['description'] ?? '' ); ?></textarea>
								</td>
							</tr>
						</table>

							<h4 style="margin: 15px 0 8px;"><?php esc_html_e( 'Post Types', 'wpmmcc-ats' ); ?></h4>
							<p class="description" style="margin: 0 0 8px;">
								<?php esc_html_e( 'Only Post Types saved in this model are shown here. To add new ones, enter them below (all site types are not shown for selection).', 'wpmmcc-ats' ); ?>
							</p>
							<div id="wptsall-model-post-types" class="wptsall-tag-list" aria-label="<?php esc_attr_e( 'Saved Post Types', 'wpmmcc-ats' ); ?>">
								<?php if ( empty( $model_pt_names ) ) : ?>
									<span class="description"><?php esc_html_e( 'Not configured.', 'wpmmcc-ats' ); ?></span>
								<?php else : ?>
									<?php foreach ( $model_pt_names as $pt ) : ?>
										<?php
										$label = isset( $pt_objects[ $pt ] ) ? ( $pt_objects[ $pt ]->label ?: $pt ) : $pt;
										?>
										<span class="wptsall-tag" data-value="<?php echo esc_attr( $pt ); ?>">
											<span class="wptsall-tag-text"><?php echo esc_html( $label ); ?></span>
											<code><?php echo esc_html( $pt ); ?></code>
											<?php /* translators: %s: post type name to remove */ ?>
									<button type="button" class="button-link wptsall-tag-remove" aria-label="<?php echo esc_attr( sprintf( __( 'Remove %s', 'wpmmcc-ats' ), $pt ) ); ?>">×</button>
										</span>
									<?php endforeach; ?>
								<?php endif; ?>
							</div>
							<div class="wptsall-tag-add">
								<input type="text" id="wptsall-model-add-post-type" class="regular-text" list="wptsall-post-types-datalist" placeholder="<?php esc_attr_e( 'Enter post_type (e.g. product)', 'wpmmcc-ats' ); ?>">
								<datalist id="wptsall-post-types-datalist">
									<?php if ( is_array( $pt_objects ) ) : ?>
										<?php foreach ( $pt_objects as $pt_key => $pt_obj ) : ?>
											<option value="<?php echo esc_attr( $pt_key ); ?>"><?php echo esc_html( $pt_obj->label ?: $pt_key ); ?></option>
										<?php endforeach; ?>
									<?php endif; ?>
								</datalist>
								<button type="button" class="button" id="wptsall-add-post-type-btn"><?php esc_html_e( 'Add', 'wpmmcc-ats' ); ?></button>
							</div>

							<h4 style="margin: 15px 0 8px;"><?php esc_html_e( 'Taxonomies', 'wpmmcc-ats' ); ?></h4>
							<p class="description" style="margin: 0 0 8px;">
								<?php esc_html_e( 'Only Taxonomies saved in this model are shown here. To add new ones, enter them below.', 'wpmmcc-ats' ); ?>
							</p>
							<div id="wptsall-model-taxonomies" class="wptsall-tag-list" aria-label="<?php esc_attr_e( 'Saved Taxonomies', 'wpmmcc-ats' ); ?>">
								<?php if ( empty( $model_tax_names ) ) : ?>
									<span class="description"><?php esc_html_e( 'Not configured.', 'wpmmcc-ats' ); ?></span>
								<?php else : ?>
									<?php foreach ( $model_tax_names as $tax ) : ?>
										<?php
										$label = isset( $tax_objects[ $tax ] ) ? ( $tax_objects[ $tax ]->label ?: $tax ) : $tax;
										?>
										<span class="wptsall-tag" data-value="<?php echo esc_attr( $tax ); ?>">
											<span class="wptsall-tag-text"><?php echo esc_html( $label ); ?></span>
											<code><?php echo esc_html( $tax ); ?></code>
											<?php /* translators: %s: taxonomy name to remove */ ?>
									<button type="button" class="button-link wptsall-tag-remove" aria-label="<?php echo esc_attr( sprintf( __( 'Remove %s', 'wpmmcc-ats' ), $tax ) ); ?>">×</button>
										</span>
									<?php endforeach; ?>
								<?php endif; ?>
							</div>
							<div class="wptsall-tag-add">
								<input type="text" id="wptsall-model-add-taxonomy" class="regular-text" list="wptsall-taxonomies-datalist" placeholder="<?php esc_attr_e( 'Enter taxonomy (e.g. product_cat)', 'wpmmcc-ats' ); ?>">
								<datalist id="wptsall-taxonomies-datalist">
									<?php if ( is_array( $tax_objects ) ) : ?>
										<?php foreach ( $tax_objects as $tax_key => $tax_obj ) : ?>
											<option value="<?php echo esc_attr( $tax_key ); ?>"><?php echo esc_html( $tax_obj->label ?: $tax_key ); ?></option>
										<?php endforeach; ?>
									<?php endif; ?>
								</datalist>
								<button type="button" class="button" id="wptsall-add-taxonomy-btn"><?php esc_html_e( 'Add', 'wpmmcc-ats' ); ?></button>
							</div>

						<p class="submit" style="margin-top: 15px;">
							<button type="button" class="button button-primary" id="wptsall-save-model-basic-btn"><?php esc_html_e( 'Save Basic Info', 'wpmmcc-ats' ); ?></button>
							<span class="spinner" id="wptsall-model-basic-spinner"></span>
						</p>
					</form>
				</div>
			</div>

			<!-- Manual Fields Section -->
			<div class="wptsall-manual-fields-container postbox">
				<div class="postbox-header">
					<h2 class="hndle"><?php esc_html_e( 'Manual Field Definitions', 'wpmmcc-ats' ); ?></h2>
				</div>
				<div class="inside">
					<p class="description"><?php esc_html_e( 'If some fields are not detected by auto-scan (e.g. custom table columns or hidden meta), you can add them manually here. These fields can then be used as translation or sync fields in URL rule editing.', 'wpmmcc-ats' ); ?></p>
					
					<div id="wptsall-manual-fields-list" class="wptsall-manual-fields-list">
						<p class="wptsall-loading-fields"><?php esc_html_e( 'Loading fields...', 'wpmmcc-ats' ); ?></p>
					</div>

					<div class="wptsall-manual-fields-footer">
						<label style="margin-right:12px;display:inline-flex;align-items:center;gap:6px;">
							<input type="checkbox" id="wptsall-manual-fields-auto-sync" checked>
							<?php esc_html_e( 'Auto-sync saved fields to rules', 'wpmmcc-ats' ); ?>
						</label>
						<button type="button" class="button" id="wptsall-add-manual-field-btn">
							<?php esc_html_e( '+ Add Manual Field', 'wpmmcc-ats' ); ?>
						</button>
						<button type="button" class="button button-primary" id="wptsall-save-manual-fields-btn" style="display:none;">
							<?php esc_html_e( 'Save Manual Fields', 'wpmmcc-ats' ); ?>
						</button>
						<span class="spinner" id="wptsall-manual-fields-spinner"></span>
					</div>
				</div>
			</div>

			<!-- Field Sync Panel (v1.1.0) -->
			<div id="wptsall-field-sync-panel" class="postbox" style="display:none;">
				<div class="postbox-header">
					<h2 class="hndle"><?php esc_html_e( 'Sync Manual Fields to Rules', 'wpmmcc-ats' ); ?></h2>
				</div>
				<div class="inside">
					<div id="wptsall-field-sync-content"></div>
				</div>
			</div>

			<!-- Field Coverage Verification (v1.1.0) -->
			<div id="wptsall-field-coverage-panel" class="postbox">
				<div class="postbox-header">
					<h2 class="hndle"><?php esc_html_e( 'Verify Field Coverage', 'wpmmcc-ats' ); ?></h2>
				</div>
				<div class="inside">
					<div id="wptsall-field-coverage-content">
						<div class="wptsall-coverage-input">
							<label for="wptsall-coverage-url"><?php esc_html_e( 'Enter a URL or Post ID to verify field coverage:', 'wpmmcc-ats' ); ?></label>
							<div style="display:flex;gap:8px;margin-top:6px;">
								<input type="text" id="wptsall-coverage-url" class="regular-text" placeholder="<?php esc_attr_e( 'https://... or post ID', 'wpmmcc-ats' ); ?>">
								<label style="display:flex;align-items:center;gap:4px;font-size:12px;">
									<input type="checkbox" id="wptsall-coverage-include-rel">
									<?php esc_html_e( 'Include relationships', 'wpmmcc-ats' ); ?>
								</label>
								<button type="button" class="button button-primary" id="wptsall-coverage-verify-btn">
									<?php esc_html_e( 'Verify', 'wpmmcc-ats' ); ?>
								</button>
							</div>
						</div>
						<div id="wptsall-coverage-result" style="margin-top:12px;"></div>
					</div>
				</div>
			</div>

			<!-- Rules Table -->
			<?php if ( empty( $rules ) ) : ?>
				<div class="wptsall-no-rules-notice">
					<p><?php esc_html_e( 'This model has no translation rules yet.', 'wpmmcc-ats' ); ?></p>
					<p>
						<a href="<?php echo esc_url( $add_rule_url ); ?>" class="button button-primary">
							<?php esc_html_e( 'Add First Rule', 'wpmmcc-ats' ); ?>
						</a>
					</p>
				</div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped wptsall-rules-table">
					<thead>
						<tr>
							<th class="column-url-pattern" style="width: 30%;"><?php esc_html_e( 'URL Pattern', 'wpmmcc-ats' ); ?></th>
							<th class="column-url-type" style="width: 10%;"><?php esc_html_e( 'URL Type', 'wpmmcc-ats' ); ?></th>
							<th class="column-data-source" style="width: 20%;"><?php esc_html_e( 'Data Source', 'wpmmcc-ats' ); ?></th>
							<th class="column-example-url" style="width: 20%;"><?php esc_html_e( 'Example URL', 'wpmmcc-ats' ); ?></th>
							<th class="column-fields" style="width: 10%;"><?php esc_html_e( 'Fields', 'wpmmcc-ats' ); ?></th>
							<th class="column-actions" style="width: 10%;"><?php esc_html_e( 'Actions', 'wpmmcc-ats' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rules as $rule ) : ?>
							<?php
							$edit_url = add_query_arg(
								array(
									'page'     => 'wpmmcc-ats',
									'action'   => 'edit_rule',
									'model_id' => $model_id,
									'rule_id'  => $rule['id'],
								),
								admin_url( 'admin.php' )
							);

							// Count fields from field_capabilities (v0.8.0+ unified format).
							// Supports both v1 object values and v2 mixed string/object values.
							$field_capabilities = $rule['field_capabilities'] ?? array();
							$translate_count    = 0;
							$sync_count         = 0;
							foreach ( $field_capabilities as $config ) {
								// Normalize: v2 string ("translate") or v1/v2 object ({"type": "translate", ...}).
								if ( is_string( $config ) ) {
									$field_type = $config;
									$is_enabled = true;
								} else {
									$field_type = $config['type'] ?? '';
									$is_enabled = ! empty( $config['enabled'] );
								}
								if ( $is_enabled ) {
									if ( 'translate' === $field_type ) {
										++$translate_count;
									} elseif ( 'sync' === $field_type ) {
										++$sync_count;
									}
								}
							}
							$total_fields = count( $field_capabilities );

							$url_type_labels = array(
								'single'   => __( 'Single', 'wpmmcc-ats' ),
								'archive'  => __( 'Archive', 'wpmmcc-ats' ),
								'taxonomy' => __( 'Taxonomy', 'wpmmcc-ats' ),
							);
							$url_type_label = $url_type_labels[ $rule['url_type'] ] ?? $rule['url_type'];
							?>
							<tr data-rule-id="<?php echo esc_attr( $rule['id'] ); ?>">
								<td class="column-url-pattern">
									<code><?php echo esc_html( $rule['url_pattern'] ); ?></code>
									<?php if ( ! empty( $rule['name'] ) ) : ?>
										<br><span class="description"><?php echo esc_html( $rule['name'] ); ?></span>
									<?php endif; ?>
								</td>
								<td class="column-url-type">
									<span class="wptsall-url-type-badge wptsall-type-<?php echo esc_attr( $rule['url_type'] ); ?>">
										<?php echo esc_html( $url_type_label ); ?>
									</span>
								</td>
								<td class="column-data-source">
									<code><?php echo esc_html( $rule['data_type'] ); ?></code>
									&rarr;
									<code><?php echo esc_html( $rule['object_name'] ); ?></code>
								</td>
								<td class="column-example-url">
									<?php if ( ! empty( $rule['example_url'] ) ) : ?>
										<a href="<?php echo esc_url( $rule['example_url'] ); ?>" target="_blank" title="<?php echo esc_attr( $rule['example_url'] ); ?>">
											<?php echo esc_html( wp_trim_words( $rule['example_url'], 5, '...' ) ); ?>
										</a>
									<?php else : ?>
										<span class="description">-</span>
									<?php endif; ?>
								</td>
								<td class="column-fields">
									<span title="<?php esc_attr_e( 'Translation fields', 'wpmmcc-ats' ); ?>: <?php echo esc_attr( $translate_count ); ?>, <?php esc_attr_e( 'Sync fields', 'wpmmcc-ats' ); ?>: <?php echo esc_attr( $sync_count ); ?>">
										<?php echo esc_html( $total_fields ); ?>
									</span>
								</td>
								<td class="column-actions">
									<a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small">
										<?php esc_html_e( 'Edit', 'wpmmcc-ats' ); ?>
									</a>
									<button type="button" class="button button-small wptsall-export-rule-btn" data-rule-id="<?php echo esc_attr( $rule['id'] ); ?>" data-rule-name="<?php echo esc_attr( $rule['name'] ?? '' ); ?>" data-object-name="<?php echo esc_attr( $rule['object_name'] ?? '' ); ?>">
										<?php esc_html_e( 'Export', 'wpmmcc-ats' ); ?>
									</button>
									<button type="button" class="button button-small button-link-delete wptsall-delete-rule-btn" data-rule-id="<?php echo esc_attr( $rule['id'] ); ?>">
										<?php esc_html_e( 'Delete', 'wpmmcc-ats' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( $total_pages > 1 ) : ?>
					<div class="tablenav bottom">
						<div class="tablenav-pages">
							<span class="displaying-num">
								<?php
								/* translators: %s: total number of rules */
								printf( esc_html__( '%s rules', 'wpmmcc-ats' ), number_format_i18n( $total ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- number_format_i18n() is safe
								?>
							</span>
							<span class="pagination-links">
								<?php
								$page_links = paginate_links(
									array(
										'base'      => add_query_arg( 'paged', '%#%' ),
										'format'    => '',
										'prev_text' => '&laquo;',
										'next_text' => '&raquo;',
										'total'     => $total_pages,
										'current'   => $current_page,
									)
								);
								echo wp_kses_post( $page_links );
								?>
							</span>
						</div>
					</div>
				<?php endif; ?>
			<?php endif; ?>
				</div><!-- .wptsall-page-content -->
		<?php
		Admin_Page_Helper::render_footer();
	}

	/**
	 * Render rule edit page (separate page, not modal)
	 *
	 * Displays the URL relationship chain in 4 sections:
	 * 1. Frontend URL (url_pattern, url_type, example_url)
	 * 2. Data Source (data_type, object_name)
	 * 3. Fields (translate_fields, sync_fields)
	 * 4. Backend URL (backend_edit, backend_list)
	 *
	 * @param int $model_id Model ID.
	 * @param int $rule_id  Rule ID (0 for new rule).
	 */
	protected static function render_rule_edit_page( $model_id, $rule_id ) {
		$model = Translation_Rule_Service::get_model( $model_id );

		if ( ! $model ) {
			wp_die( esc_html__( 'Model not found', 'wpmmcc-ats' ) );
		}

		$is_new = ( 'new' === $rule_id || 0 === $rule_id );
		$rule   = null;

		if ( ! $is_new ) {
			$rule = Translation_Rule_Service::get_rule( $rule_id );
			if ( ! $rule || (int) $rule['model_id'] !== (int) $model_id ) {
				wp_die( esc_html__( 'Rule not found or does not belong to this model', 'wpmmcc-ats' ) );
			}
		}

		$back_url = add_query_arg(
			array(
				'page'     => 'wpmmcc-ats',
				'action'   => 'edit',
				'model_id' => $model_id,
			),
			admin_url( 'admin.php' )
		);

		// Data types for dropdown
		$data_types = array(
			'post'    => __( 'Post', 'wpmmcc-ats' ),
			'term'    => __( 'Term', 'wpmmcc-ats' ),
			'user'    => __( 'User', 'wpmmcc-ats' ),
			'comment' => __( 'Comment', 'wpmmcc-ats' ),
			'option'  => __( 'Option', 'wpmmcc-ats' ),
		);

		// URL types for dropdown
		$url_types = array(
			'single'   => __( 'Single', 'wpmmcc-ats' ),
			'archive'  => __( 'Archive', 'wpmmcc-ats' ),
			'taxonomy' => __( 'Taxonomy', 'wpmmcc-ats' ),
		);

		// Default values for new rule (v0.8.0 unified format)
		$rule_data = $rule ? $rule : array(
			'id'                 => '',
			'name'               => '',
			'url_pattern'        => '',
			'url_type'           => 'single',
			'example_url'        => '',
			'data_type'          => 'post',
			'object_name'        => '',
			'direction'          => 'source_to_target',
			// Product decision (2026-01-29): sync_mode is fixed to "new_only".
			'sync_mode'          => 'new_only',
			'field_capabilities' => array(),
			'backend_edit'       => '',
			'backend_list'       => '',
			'requires_login'     => 0,
			'priority'           => 10,
			'note'               => '',
			'is_active'          => 1,
		);
		$object_choice_data = self::build_rule_object_choices( $model_id, $rule_data );
		$object_labels      = $object_choice_data['labels'];
		$object_choices     = $object_choice_data['choices'];
		$page_title = $is_new
			? sprintf(
				/* translators: %s: Model name */
				esc_html__( '%s - Add Translation Rule', 'wpmmcc-ats' ),
				esc_html( $model['plugin_name'] )
			)
			: sprintf(
				/* translators: %s: Model name */
				esc_html__( '%s - Edit Translation Rule', 'wpmmcc-ats' ),
				esc_html( $model['plugin_name'] )
			);

		Admin_Page_Helper::render_header(
			$page_title,
			'models',
			array(
				array(
					'label' => __( 'Back to Rules List', 'wpmmcc-ats' ),
					'url'   => $back_url,
					'class' => 'button',
				),
			),
			array( 'wptsall-rule-edit-page' )
		);
		?>
				<div class="wptsall-page-content">
					<!-- URL Relationship Chain Visual -->
			<div class="wptsall-url-chain-visual">
				<div class="wptsall-chain-step" data-step="1">
					<span class="wptsall-chain-icon">🌐</span>
					<span class="wptsall-chain-label"><?php esc_html_e( 'Frontend URL', 'wpmmcc-ats' ); ?></span>
				</div>
				<div class="wptsall-chain-arrow">&harr;</div>
				<div class="wptsall-chain-step" data-step="2">
					<span class="wptsall-chain-icon">💾</span>
					<span class="wptsall-chain-label"><?php esc_html_e( 'Data Source', 'wpmmcc-ats' ); ?></span>
				</div>
				<div class="wptsall-chain-arrow">&harr;</div>
				<div class="wptsall-chain-step" data-step="3">
					<span class="wptsall-chain-icon">📝</span>
					<span class="wptsall-chain-label"><?php esc_html_e( 'Translation/Sync Fields', 'wpmmcc-ats' ); ?></span>
				</div>
				<div class="wptsall-chain-arrow">&harr;</div>
				<div class="wptsall-chain-step" data-step="4">
					<span class="wptsall-chain-icon">⚙️</span>
					<span class="wptsall-chain-label"><?php esc_html_e( 'Backend URL', 'wpmmcc-ats' ); ?></span>
				</div>
			</div>

			<form id="wptsall-rule-edit-form" method="post" class="wptsall-rule-form">
				<?php wp_nonce_field( 'wptsall_save_rule', 'wptsall_rule_nonce' ); ?>
				<input type="hidden" name="action" value="wptsall_save_rule">
				<input type="hidden" name="model_id" value="<?php echo esc_attr( $model_id ); ?>">
				<input type="hidden" name="rule_id" value="<?php echo esc_attr( $is_new ? '' : $rule_data['id'] ); ?>">

				<!-- Section 1: Frontend URL -->
				<div class="wptsall-form-section" data-section="1">
					<h2 class="wptsall-section-title">
						<span class="wptsall-section-icon">🌐</span>
						<?php esc_html_e( 'Frontend URL', 'wpmmcc-ats' ); ?>
					</h2>
					<p class="wptsall-section-desc"><?php esc_html_e( 'Define the frontend URL pattern and type matched by this rule.', 'wpmmcc-ats' ); ?></p>

					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="rule-name"><?php esc_html_e( 'Rule Name', 'wpmmcc-ats' ); ?></label>
							</th>
							<td>
								<input type="text" id="rule-name" name="name" class="regular-text" value="<?php echo esc_attr( $rule_data['name'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. Product Detail Page', 'wpmmcc-ats' ); ?>">
								<p class="description"><?php esc_html_e( 'Optional, helps identify the purpose of this rule.', 'wpmmcc-ats' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="rule-url-pattern"><?php esc_html_e( 'URL Pattern', 'wpmmcc-ats' ); ?> <span class="required">*</span></label>
							</th>
							<td>
								<input type="text" id="rule-url-pattern" name="url_pattern" class="large-text" value="<?php echo esc_attr( $rule_data['url_pattern'] ); ?>" required placeholder="?post_type=product&p={id}">
								<p class="description"><?php esc_html_e( 'Use {slug} or {id} as dynamic parameter placeholders. Example: /product/{slug}/', 'wpmmcc-ats' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="rule-url-type"><?php esc_html_e( 'URL Type', 'wpmmcc-ats' ); ?> <span class="required">*</span></label>
							</th>
							<td>
								<select id="rule-url-type" name="url_type" required>
									<?php foreach ( $url_types as $value => $label ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $rule_data['url_type'], $value ); ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Single: content detail page | Archive: content list page | Taxonomy: taxonomy page', 'wpmmcc-ats' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="rule-example-url"><?php esc_html_e( 'Example URL', 'wpmmcc-ats' ); ?></label>
							</th>
							<td>
								<input type="url" id="rule-example-url" name="example_url" class="large-text" value="<?php echo esc_attr( $rule_data['example_url'] ); ?>" placeholder="https://example.com/product/sample-product/">
								<p class="description"><?php esc_html_e( 'An actual URL example for testing and validating the rule.', 'wpmmcc-ats' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="rule-requires-login"><?php esc_html_e( 'Access Permissions', 'wpmmcc-ats' ); ?></label>
							</th>
							<td>
								<label>
									<input type="checkbox" id="rule-requires-login" name="requires_login" value="1" <?php checked( $rule_data['requires_login'], 1 ); ?>>
									<?php esc_html_e( 'Login required to access this URL', 'wpmmcc-ats' ); ?>
								</label>
							</td>
						</tr>
					</table>
				</div>

				<!-- Section 2: Data Source -->
				<div class="wptsall-form-section" data-section="2">
					<h2 class="wptsall-section-title">
						<span class="wptsall-section-icon">💾</span>
						<?php esc_html_e( 'Data Source', 'wpmmcc-ats' ); ?>
					</h2>
					<p class="wptsall-section-desc"><?php esc_html_e( 'Specify the data type and bind this rule to a model-defined object. Client discovery and sync follow this binding.', 'wpmmcc-ats' ); ?></p>

					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="rule-data-type"><?php esc_html_e( 'Data Type', 'wpmmcc-ats' ); ?> <span class="required">*</span></label>
							</th>
							<td>
								<select id="rule-data-type" name="data_type" required>
									<?php foreach ( $data_types as $value => $label ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $rule_data['data_type'], $value ); ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Select the data storage type.', 'wpmmcc-ats' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="rule-object-name"><?php esc_html_e( 'Object Name', 'wpmmcc-ats' ); ?> <span class="required">*</span></label>
							</th>
							<td>
								<select id="rule-object-name" name="object_name" required>
									<option value=""><?php esc_html_e( '-- Please select --', 'wpmmcc-ats' ); ?></option>
									<?php foreach ( $object_choices as $rule_data_type => $choices ) : ?>
										<?php if ( empty( $choices ) ) : ?>
											<?php continue; ?>
										<?php endif; ?>
										<optgroup
											label="<?php echo esc_attr( $object_labels[ $rule_data_type ] ?? ucfirst( $rule_data_type ) ); ?>"
											data-rule-data-type="<?php echo esc_attr( $rule_data_type ); ?>"
										>
											<?php foreach ( $choices as $choice ) : ?>
												<option
													value="<?php echo esc_attr( $choice['value'] ); ?>"
													data-rule-data-type="<?php echo esc_attr( $choice['data_type'] ); ?>"
													data-object-type="<?php echo esc_attr( $choice['object_type'] ); ?>"
													<?php selected( $rule_data['object_name'], $choice['value'] ); ?>
												>
													<?php echo esc_html( $choice['label'] ); ?>
												</option>
											<?php endforeach; ?>
										</optgroup>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Only model-defined objects can be bound here. Change the data type to filter the compatible objects.', 'wpmmcc-ats' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<!-- Section 3: Fields -->
				<div class="wptsall-form-section" data-section="3">
					<h2 class="wptsall-section-title">
						<span class="wptsall-section-icon">📝</span>
						<?php esc_html_e( 'Translation/Sync Fields', 'wpmmcc-ats' ); ?>
					</h2>
					<p class="wptsall-section-desc"><?php esc_html_e( 'Configure fields using WPML-style preferences: Translate, Copy, Copy once, or leave unassigned (Don\'t translate). ID Mapping and Compute are advanced.', 'wpmmcc-ats' ); ?></p>

					<!-- Field Configurator Container -->
					<div id="wptsall-field-configurator-container"></div>

					<!-- Hidden textarea to store JSON configuration (v0.8.0 unified format) -->
					<textarea
						id="wptsall-fc-json-output"
						name="field_configuration"
						style="display:none;"
						><?php
							// Load existing configuration (v0.8.0 unified format)
							$field_config = array(
								'field_capabilities' => $rule_data['field_capabilities'] ?? array(),
							);
							echo esc_textarea( wp_json_encode( $field_config ) );
						?></textarea>
				</div>

				<!-- Section 4: Backend URL -->
				<div class="wptsall-form-section" data-section="4">
					<h2 class="wptsall-section-title">
						<span class="wptsall-section-icon">⚙️</span>
						<?php esc_html_e( 'Backend URL', 'wpmmcc-ats' ); ?>
					</h2>
					<p class="wptsall-section-desc"><?php esc_html_e( 'Configure backend admin page URL patterns for quick navigation to edit pages.', 'wpmmcc-ats' ); ?></p>

					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="rule-backend-edit"><?php esc_html_e( 'Edit Page URL', 'wpmmcc-ats' ); ?></label>
							</th>
							<td>
								<input type="text" id="rule-backend-edit" name="backend_edit" class="large-text" value="<?php echo esc_attr( $rule_data['backend_edit'] ); ?>" placeholder="/wp-admin/post.php?post={id}&action=edit">
								<p class="description"><?php esc_html_e( 'Backend URL pattern for editing a single content item. Use {id} as content ID placeholder.', 'wpmmcc-ats' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="rule-backend-list"><?php esc_html_e( 'List Page URL', 'wpmmcc-ats' ); ?></label>
							</th>
							<td>
								<input type="text" id="rule-backend-list" name="backend_list" class="large-text" value="<?php echo esc_attr( $rule_data['backend_list'] ); ?>" placeholder="/wp-admin/edit.php?post_type=product">
								<p class="description"><?php esc_html_e( 'Backend content list page URL.', 'wpmmcc-ats' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<!-- Additional Options -->
				<div class="wptsall-form-section wptsall-form-section-other">
					<h2 class="wptsall-section-title"><?php esc_html_e( 'Other Options', 'wpmmcc-ats' ); ?></h2>

					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="rule-priority"><?php esc_html_e( 'Priority', 'wpmmcc-ats' ); ?></label>
							</th>
							<td>
								<input type="number" id="rule-priority" name="priority" class="small-text" value="<?php echo esc_attr( $rule_data['priority'] ); ?>" min="1" max="100">
								<p class="description"><?php esc_html_e( 'Lower number means higher priority. Default: 10', 'wpmmcc-ats' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="rule-is-active"><?php esc_html_e( 'Status', 'wpmmcc-ats' ); ?></label>
							</th>
							<td>
								<label>
									<input type="checkbox" id="rule-is-active" name="is_active" value="1" <?php checked( $rule_data['is_active'], 1 ); ?>>
									<?php esc_html_e( 'Enable this rule', 'wpmmcc-ats' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="rule-note"><?php esc_html_e( 'Note', 'wpmmcc-ats' ); ?></label>
							</th>
							<td>
								<textarea id="rule-note" name="note" class="large-text" rows="3" placeholder="<?php esc_attr_e( 'Optional notes', 'wpmmcc-ats' ); ?>"><?php echo esc_textarea( $rule_data['note'] ); ?></textarea>
							</td>
						</tr>
					</table>
				</div>

				<!-- G5: Validation Results Panel -->
				<div id="wptsall-validation-panel" class="wptsall-validation-panel" style="display:none;">
					<h3><?php esc_html_e( 'Validation Results', 'wpmmcc-ats' ); ?></h3>
					<div class="validation-status"></div>
					<div class="validation-details"></div>
				</div>

				<!-- Submit -->
				<div class="wptsall-form-actions">
					<button type="submit" class="button button-primary button-large">
						<?php $is_new ? esc_html_e( 'Create Rule', 'wpmmcc-ats' ) : esc_html_e( 'Save Rule', 'wpmmcc-ats' ); ?>
					</button>
					<a href="<?php echo esc_url( $back_url ); ?>" class="button button-large">
						<?php esc_html_e( 'Cancel', 'wpmmcc-ats' ); ?>
					</a>
					<?php if ( ! $is_new ) : ?>
						<button type="button" class="button button-link-delete button-large wptsall-delete-rule-btn" data-rule-id="<?php echo esc_attr( $rule_data['id'] ); ?>" style="float: right;">
							<?php esc_html_e( 'Delete This Rule', 'wpmmcc-ats' ); ?>
						</button>
					<?php endif; ?>
				</div>
			</form>
				</div><!-- .wptsall-page-content -->
		<?php
		Admin_Page_Helper::render_footer();
	}

}

<?php
/**
 * PSR-4 Autoloader for WPTSALL
 *
 * @package WPTSALL
 * @since 0.2.0
 */

namespace WPTSALL;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Autoloader class
 *
 * Implements PSR-4 autoloading for WPTSALL namespace with custom path mappings
 * to support modular directory structure.
 */
class Autoloader {

	/**
	 * Namespace prefix
	 *
	 * @var string
	 */
	private $prefix = 'WPTSALL\\';

	/**
	 * Base directory for the namespace prefix
	 *
	 * @var string
	 */
	private $base_dir;

	/**
	 * Namespace to directory mappings
	 *
	 * Maps namespace prefixes to actual directories.
	 * Uses modular namespaces: WPTSALL\{Module}\{Submodule}\{Class}
	 *
	 * @var array
	 */
	private $namespace_map = array(
		// Models module mappings (WPTSALL\Models\*)
		'Models\\Admin\\Model_Editor_Page'            => 'models/admin/class-model-editor-page.php',
		'Models\\API\\Translation_Rule_REST_Controller' => 'models/api/class-translation-rule-rest-controller.php',
		'Models\\API\\Plugin_Mapping_REST_Controller' => 'models/api/class-plugin-mapping-rest-controller.php',
		'Models\\Services\\Plugin_Mapping_Service'    => 'models/services/class-plugin-mapping-service.php',
		'Models\\Services\\Plugin_Scanner'            => 'models/services/class-plugin-scanner.php',
		'Models\\Services\\Translation_Rule_Service'  => 'models/services/class-translation-rule-service.php',
		'Models\\Services\\Model_Config_Provider'     => 'models/services/class-model-config-provider.php',
		'Models\\Services\\Rule_Validation_Service'   => 'models/services/class-rule-validation-service.php',
		'Models\\Services\\Template_Validation_Service' => 'models/services/class-template-validation-service.php',
		'Models\\Services\\Simulation_Validator'      => 'models/services/class-simulation-validator.php',
		'Models\\Services\\Template_Sync_Service'     => 'models/services/class-template-sync-service.php',
		'Models\\Services\\Field_Processor'           => 'models/services/class-field-processor.php',
		'Models\\Services\\Model_Object_Service'      => 'models/services/class-model-object-service.php',

		'Models\\Services\\Field_Discovery_Service'   => 'models/services/class-field-discovery-service.php',
		'Models\\Adapters\\Plugin_Field_Rules_Adapter' => 'models/adapters/interface-plugin-field-rules-adapter.php',
		'Models\\Adapters\\Yoast_Field_Rules_Adapter'   => 'models/adapters/class-yoast-field-rules-adapter.php',
		'Models\\Adapters\\Rank_Math_Field_Rules_Adapter' => 'models/adapters/class-rank-math-field-rules-adapter.php',
		'Models\\Adapters\\SEOPress_Field_Rules_Adapter' => 'models/adapters/class-seopress-field-rules-adapter.php',
		'Models\\Adapters\\AIOSEO_Field_Rules_Adapter' => 'models/adapters/class-aioseo-field-rules-adapter.php',
		'Models\\Adapters\\Elementor_Field_Rules_Adapter' => 'models/adapters/class-elementor-field-rules-adapter.php',
		'Models\\Adapters\\WooCommerce_Field_Rules_Adapter' => 'models/adapters/class-woocommerce-field-rules-adapter.php',
		'Models\\Adapters\\Advanced_Custom_Fields_Field_Rules_Adapter' => 'models/adapters/class-advanced-custom-fields-field-rules-adapter.php',
		'Models\\Adapters\\Classified_Listing_Field_Rules_Adapter' => 'models/adapters/class-classified-listing-field-rules-adapter.php',
		'Models\\Adapters\\Events_Manager_Field_Rules_Adapter' => 'models/adapters/class-events-manager-field-rules-adapter.php',
		'Models\\Adapters\\Site_Reviews_Field_Rules_Adapter' => 'models/adapters/class-site-reviews-field-rules-adapter.php',
		'Models\\Adapters\\PropertyHive_Field_Rules_Adapter' => 'models/adapters/class-propertyhive-field-rules-adapter.php',
		'Models\\Adapters\\Tutor_Field_Rules_Adapter'        => 'models/adapters/class-tutor-field-rules-adapter.php',
		'Models\\Adapters\\LearnPress_Field_Rules_Adapter'   => 'models/adapters/class-learnpress-field-rules-adapter.php',
		'Models\\Adapters\\The_Events_Calendar_Field_Rules_Adapter' => 'models/adapters/class-the-events-calendar-field-rules-adapter.php',
		'Models\\Adapters\\Give_Field_Rules_Adapter'         => 'models/adapters/class-give-field-rules-adapter.php',
		'Models\\Adapters\\Testimonial_Free_Field_Rules_Adapter' => 'models/adapters/class-testimonial-free-field-rules-adapter.php',
		'Models\\Adapters\\Envira_Gallery_Lite_Field_Rules_Adapter' => 'models/adapters/class-envira-gallery-lite-field-rules-adapter.php',
		'Models\\Adapters\\WP_EasyCart_Field_Rules_Adapter' => 'models/adapters/class-wp-easycart-field-rules-adapter.php',
		'Models\\Adapters\\Plugin_Field_Rules_Registry' => 'models/adapters/class-plugin-field-rules-registry.php',
		'Models\\Adapters\\Json_Field_Rules_Adapter' => 'models/adapters/class-json-field-rules-adapter.php',
		'Models\\Adapters\\Adapter_Manifest'           => 'models/adapters/class-adapter-manifest.php',
		'Models\\Adapters\\Field_Rules_Document_Validator' => 'models/adapters/class-field-rules-document-validator.php',
		'Models\\Adapters\\Field_Rules_Store'          => 'models/adapters/class-field-rules-store.php',
		'Models\\Services\\Custom_Model_Service'      => 'models/services/class-custom-model-service.php',
		'Models\\Services\\Id_Mapping_Resolver'       => 'models/services/class-id-mapping-resolver.php',
		'Models\\Services\\Field_Capability'          => 'models/services/class-field-capability.php',
		'Models\\Services\\Post_Mapping_Service'      => 'models/services/class-post-mapping-service.php',
		'Models\\Services\\Media_Mapping_Service'     => 'models/services/class-media-mapping-service.php',
		'Models\\Services\\User_Mapping_Service'      => 'models/services/class-user-mapping-service.php',
		'Models\\Services\\Term_Mapping_Service'      => 'models/services/class-term-mapping-service.php',
		'Models\\Services\\Option_Sync_State_Service' => 'models/services/class-option-sync-state-service.php',
		'Models\\Validators\\Translation_Rule_Validator' => 'models/validators/class-translation-rule-validator.php',
		'Models\\Validators\\Template_Validator'      => 'models/validators/class-template-validator.php',

		'Models\\Scanners\\Model_Scanner_V2'          => 'models/scanners/class-model-scanner-v2.php',
		'Models\\Scanners\\Runtime_Tracker'           => 'models/scanners/class-runtime-tracker.php',
		'Models\\Scanners\\Orphan_Resolver'           => 'models/scanners/class-orphan-resolver.php',
		'Models\\Scanners\\Smart_Field_Scanner'       => 'models/scanners/class-smart-field-scanner.php',
		'Models\\Blog_Template'                       => 'models/class-blog-template.php',

		// Sites module mappings (WPTSALL\Sites\*)
		'Sites\\Admin\\Sites_Page'                    => 'sites/admin/class-sites-page.php',
		'Sites\\API\\Sites_REST_Controller'           => 'sites/api/class-sites-rest-controller.php',
		'Sites\\Services\\Site_Relation_Service'      => 'sites/services/class-site-relation-service.php',
		'Sites\\Services\\Virtual_Site_Service'       => 'sites/services/class-virtual-site-service.php',
		'Sites\\Services\\Relation_Model_Service'     => 'sites/services/class-relation-model-service.php',
		'Sites\\Validators\\Site_Relation_Validator'  => 'sites/validators/class-site-relation-validator.php',
		'Sites\\Services\\Manual_Content_Service'       => 'sites/services/class-manual-content-service.php',
		'Sites\\Services\\URL_Transformer'              => 'sites/services/class-url-transformer.php',
		'Sites\\Services\\Url_Converter'                => 'sites/services/class-url-converter.php',
		'Sites\\Services\\Relation_Resolver'            => 'sites/services/class-relation-resolver.php',
		'Sites\\Services\\Virtual_Permalink'            => 'sites/services/class-virtual-permalink.php',
		'Sites\\Services\\Translation_Identity'         => 'sites/services/class-translation-identity.php',
		'Sites\\Services\\Relation_Config_Service'      => 'sites/services/class-relation-config-service.php',
		'Sites\\Admin\\Translation_Editor_Page'          => 'sites/admin/class-translation-editor-page.php',
		'Sites\\API\\Manual_Translation_REST_Controller' => 'sites/api/class-manual-translation-rest-controller.php',

		// Admin module mappings (WPTSALL\Admin\*)
		'Admin\\Initialization_Page'                  => 'admin/class-initialization-page.php',

		// Templates module mappings (WPTSALL\Templates\*)
		'Templates\\Services\\Template_Service'       => 'templates/services/class-template-service.php',
		'Templates\\Services\\Template_Entry_Service' => 'templates/services/class-template-entry-service.php',
		'Templates\\Scanners\\POT_Parser'             => 'templates/scanners/class-pot-parser.php',
		'Templates\\Scanners\\Language_Pack_Scanner'  => 'templates/scanners/class-language-pack-scanner.php',
		'Templates\\API\\Template_REST_Controller'    => 'templates/api/class-template-rest-controller.php',
		'Templates\\Admin\\Template_List_Page'        => 'templates/admin/class-template-list-page.php',
		'Templates\\Admin\\Template_Edit_Page'        => 'templates/admin/class-template-edit-page.php',
		'Templates\\Admin\\Theme_Plugin_Localization_Page' => 'templates/admin/class-theme-plugin-localization-page.php',
		'Templates\\Admin\\Browse_As_Role_Page'       => 'templates/admin/class-browse-as-role-page.php',

		// Hooks module mappings (WPTSALL\Hooks\*)
		'Hooks\\Hook_Manager'                         => 'hooks/class-hook-manager.php',
		'Hooks\\Gettext_Filter'                       => 'hooks/class-gettext-filter.php',
		'Hooks\\Virtual_Site_Router'                  => 'hooks/class-virtual-site-router.php',
		'Hooks\\Virtual_Site_Query_Switch'            => 'hooks/class-virtual-site-query-switch.php',
		'Hooks\\Virtual_Site_SEO'                     => 'hooks/class-virtual-site-seo.php',
		'Hooks\\Virtual_Site_Link_Filters'            => 'hooks/class-virtual-site-link-filters.php',
		'Hooks\\Output_Link_Localizer'                => 'hooks/class-output-link-localizer.php',
		'Hooks\\Metadata_Id_Remapper'                 => 'hooks/class-metadata-id-remapper.php',
		'Hooks\\Elementor_Data_Url_Rewriter'          => 'hooks/class-elementor-data-url-rewriter.php',
		'Hooks\\Admin_Virtual_Site_Manager'           => 'hooks/class-admin-virtual-site-manager.php',
		'Hooks\\Config_Filter'                        => 'hooks/class-config-filter.php',

		// Public API
		'API\\Object_Id'                              => 'api/class-object-id.php',

		// Languages module mappings (1.2.0)
		'Languages\\Services\\Language_Service'       => 'languages/services/class-language-service.php',
		'Languages\\Admin\\Languages_Page'            => 'languages/admin/class-languages-page.php',
		'Languages\\Admin\\Admin_Bar_Switcher'       => 'languages/admin/class-admin-bar-switcher.php',

		// Settings module mappings (1.2.0)
		'Settings\\Services\\Settings_Service'       => 'settings/services/class-settings-service.php',
		'Settings\\Admin\\Settings_Page'             => 'settings/admin/class-settings-page.php',
		'Settings\\Admin\\Seo_Settings_Page'         => 'settings/admin/class-seo-settings-page.php',

		// Strings module mappings (1.2.0)
		'Strings\\Services\\String_Translation_Service' => 'strings/services/class-string-translation-service.php',
		'Strings\\Services\\Site_String_Scanner'       => 'strings/services/class-site-string-scanner.php',
		'Strings\\Admin\\Strings_Page'               => 'strings/admin/class-strings-page.php',
		'Strings\\Site_Identity_Translation'         => 'strings/class-site-identity-translation.php',
		'Strings\\Module'                            => 'strings/module.php',

		// Translation memory (1.2.0)
		'TranslationMemory\\Services\\Translation_Memory_Service' => 'translation-memory/services/class-translation-memory-service.php',
		'TranslationMemory\\Admin\\Translation_Memory_Page' => 'translation-memory/admin/class-translation-memory-page.php',
		'CLI\\Translate_Command'        => 'cli/class-translate-command.php',
		'CLI\\TM_Command'               => 'cli/class-tm-command.php',
		'CLI\\Strings_Command'          => 'cli/class-strings-command.php',
		'CLI\\Audit_Log'               => 'cli/class-audit-log.php',
		'CLI\\Audit_Command'            => 'cli/class-audit-command.php',
		'CLI\\Security_Command'         => 'cli/class-security-command.php',
		'CLI\\Identity_Command'         => 'cli/class-identity-command.php',

		// Wizard (1.2.0)
		'Wizard\\Setup_Wizard'                          => 'wizard/class-setup-wizard.php',

		// Models backup (1.2.0)
		'Models\\Admin\\Model_Backup_Handler'         => 'models/admin/class-model-backup-handler.php',

		// Media translation (1.3.0)
		'MediaTranslation\\Services\\Media_Translation_Service' => 'media-translation/services/class-media-translation-service.php',
		'MediaTranslation\\Admin\\Media_Translation_Page' => 'media-translation/admin/class-media-translation-page.php',
		'MediaTranslation\\Admin\\Media_Library_Columns' => 'media-translation/admin/class-media-library-columns.php',
		'MediaTranslation\\Hooks\\Media_Translation_Frontend' => 'media-translation/hooks/class-media-translation-frontend.php',

		// Menu translation (1.3.0)
		'MenuTranslation\\Menu_Translation' => 'menu-translation/class-menu-translation.php',
		'MenuTranslation\\Menu_Mapping_Service' => 'menu-translation/class-menu-mapping-service.php',
		'MenuTranslation\\Admin\\Menu_Sync_Page' => 'menu-translation/admin/class-menu-sync-page.php',

		'Fse\\Fse_Content_Adapter' => 'fse/class-fse-content-adapter.php',
		'Fse\\Module' => 'fse/module.php',

		// Widget translation (1.3.0)
		'WidgetTranslation\\Widget_Translation' => 'widget-translation/class-widget-translation.php',

		// Theme & plugin localization (1.3.0)
		'ThemeLocalization\\Theme_Localization' => 'theme-localization/class-theme-localization.php',

		// Custom field translation (1.3.0)
		'CustomFields\\Services\\Custom_Field_Translation_Service' => 'custom-fields/services/class-custom-field-translation-service.php',
		'CustomFields\\Admin\\Custom_Fields_Page' => 'custom-fields/admin/class-custom-fields-page.php',

		// User translation (1.3.0)
		'UserTranslation\\Services\\User_Translation_Service' => 'user-translation/services/class-user-translation-service.php',
		'UserTranslation\\Admin\\User_Translation_Page' => 'user-translation/admin/class-user-translation-page.php',

		// Manual translation (1.4.0)
		'ManualTranslation\\Services\\Url_Discovery_Service' => 'manual-translation/services/class-url-discovery-service.php',
		'ManualTranslation\\Services\\Translation_Progress_Service' => 'manual-translation/services/class-translation-progress-service.php',
		'ManualTranslation\\Services\\Taxonomy_Translation_Service' => 'manual-translation/services/class-taxonomy-translation-service.php',
		'ManualTranslation\\Services\\Field_Translation_Service' => 'manual-translation/services/class-field-translation-service.php',
		'ManualTranslation\\Admin\\Manual_Translation_Hub' => 'manual-translation/admin/class-manual-translation-hub.php',
		'ManualTranslation\\Admin\\Url_Discovery_Page' => 'manual-translation/admin/class-url-discovery-page.php',
		'ManualTranslation\\Admin\\Pending_Translations_Page' => 'manual-translation/admin/class-pending-translations-page.php',
		'ManualTranslation\\Admin\\Taxonomy_Translation_Page' => 'manual-translation/admin/class-taxonomy-translation-page.php',
		'ManualTranslation\\Admin\\Field_Discovery_Page' => 'manual-translation/admin/class-field-discovery-page.php',
		'ManualTranslation\\Admin\\Content_Types_Config_Page' => 'manual-translation/admin/class-content-types-config-page.php',
		'ManualTranslation\\Admin\\Dashboard_Page' => 'manual-translation/admin/class-dashboard-page.php',
		'ManualTranslation\\Hooks\\Language_Meta_Saver' => 'manual-translation/hooks/class-language-meta-saver.php',

		// Sync service (1.3.0)
		'Sync\\Services\\Sync_Service' => 'sync/services/class-sync-service.php',
		'Sync\\Services\\Field_Ownership_Service' => 'sync/services/class-field-ownership-service.php',

		// Translation status column (1.2.0)
		'TranslationStatus\\Translation_Status_Column' => 'translation-status/class-translation-status-column.php',
		'TranslationStatus\\Translation_Status_Term_Column' => 'translation-status/class-translation-status-term-column.php',

		// Core module mappings (WPTSALL\Core\*)
		'Core\\Site_Verification'                     => 'core/class-site-verification.php',
		'Core\\Classification_Constants'              => 'core/classifiers/class-classification-constants.php',
		'Core\\Classification_Rules'                  => 'core/classifiers/class-classification-rules.php',
		'Core\\Data_Classification'                   => 'core/classifiers/class-data-classification.php',
		'Core\\Data_Classifier'                       => 'core/classifiers/class-data-classifier.php',
		'Core\\Smart_Field_Classifier'                => 'core/classifiers/class-smart-field-classifier.php',
		'Core\\Sync_Access_Validator'                 => 'core/classifiers/class-sync-access-validator.php',
		'Core\\URL_Classification'                    => 'core/classifiers/class-url-classification.php',
		'Core\\URL_Classifier'                        => 'core/classifiers/class-url-classifier.php',
		'Core\\Langpack_Service'                      => 'core/class-langpack-service.php',
		'Core\\Content_Format_Registry'               => 'core/class-content-format-registry.php',
		'Core\\Language_Context'                      => 'core/class-language-context.php',
		'Core\\Translation_Object_Graph'              => 'core/class-translation-object-graph.php',
		'Core\\Capabilities'                          => 'core/class-capabilities.php',
		'Core\\Egress_Guard'                          => 'core/class-egress-guard.php',
		'Core\\Component_Trust'                       => 'core/class-component-trust.php',
		'Core\\Job_Snapshot'                          => 'core/class-job-snapshot.php',
		'Hooks\\Content_Change_Dispatcher'            => 'hooks/class-content-change-dispatcher.php',
		'Admin\\Compatibility_Notices'                => 'admin/class-compatibility-notices.php',

		// Client task write-back sync module.
		//
		// The interface and base class intentionally live together in
		// class-write-back-adapter.php. The generic PSR-4 fallback would look
		// for class-write-back-adapter-interface.php and
		// class-write-back-adapter-base.php, which makes production REST
		// callbacks fatal when an adapter extends the base class.
		'Tasks\\Sync\\Write_Back_Adapter_Interface'   => 'tasks/sync/class-write-back-adapter.php',
		'Tasks\\Sync\\Write_Back_Adapter_Base'        => 'tasks/sync/class-write-back-adapter.php',
		'Tasks\\Sync\\Attachment_Write_Back_Adapter'  => 'tasks/sync/class-attachment-write-back-adapter.php',
		'Tasks\\Sync\\Media_Meta_Write_Back_Adapter'  => 'tasks/sync/class-media-meta-write-back-adapter.php',
		'Tasks\\Sync\\Document_Write_Back_Adapter'    => 'tasks/sync/class-document-write-back-adapter.php',
		'Tasks\\Sync\\Write_Back_Dispatcher'          => 'tasks/sync/class-write-back-dispatcher.php',
		'Tasks\\Sync\\Manual_Queue'                   => 'tasks/sync/class-manual-queue.php',
		'Tasks\\Sync\\Sync_Executor'                  => 'tasks/sync/class-sync-executor.php',
	);

	/**
	 * Constructor
	 *
	 * @param string $base_dir Base directory.
	 */
	public function __construct( $base_dir ) {
		// Use global namespace for WordPress function
		$this->base_dir = function_exists( '\trailingslashit' )
			? \trailingslashit( $base_dir )
			: rtrim( $base_dir, '/\\' ) . '/';
	}

	/**
	 * Register autoloader with SPL
	 *
	 * @return void
	 */
	public function register() {
		spl_autoload_register( array( $this, 'load_class' ) );
	}

	/**
	 * Load class file
	 *
	 * @param string $class Fully qualified class name.
	 * @return void
	 */
	public function load_class( $class ) {
		// Check if class uses our namespace
		if ( strpos( $class, $this->prefix ) !== 0 ) {
			return;
		}

		// Remove namespace prefix
		$relative_class = substr( $class, strlen( $this->prefix ) );

		// Check if we have a custom mapping for this class
		if ( isset( $this->namespace_map[ $relative_class ] ) ) {
			$file_path = $this->base_dir . $this->namespace_map[ $relative_class ];
			if ( file_exists( $file_path ) ) {
				require_once $file_path;
				return;
			}
		}

		// Fall back to standard PSR-4 resolution
		// Convert namespace separators to directory separators
		$relative_path = str_replace( '\\', '/', $relative_class );

		// Get directory and class name
		$parts      = explode( '/', $relative_path );
		$class_name = array_pop( $parts );
		$directory  = implode( '/', $parts );

		// Convert class name from CamelCase to hyphenated lowercase
		$filename = $this->convert_class_name_to_filename( $class_name );

		// Build full file path
		if ( ! empty( $directory ) ) {
			$file_path = $this->base_dir . strtolower( $directory ) . '/' . $filename;
		} else {
			$file_path = $this->base_dir . $filename;
		}

		// Load file if exists
		if ( file_exists( $file_path ) ) {
			require_once $file_path;
		}
	}

	/**
	 * Convert class name to filename
	 *
	 * Converts CamelCase to hyphenated lowercase with class- prefix
	 * Example: Scanner -> class-scanner.php
	 *          Template_Validator -> class-template-validator.php
	 *
	 * @param string $class_name Class name.
	 * @return string Filename.
	 */
	private function convert_class_name_to_filename( $class_name ) {
		// Convert underscores to hyphens
		$filename = str_replace( '_', '-', $class_name );

		// Convert to lowercase
		$filename = strtolower( $filename );

		// Add class- prefix and .php extension
		return 'class-' . $filename . '.php';
	}
}

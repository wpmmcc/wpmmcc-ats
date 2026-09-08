<?php
/**
 * Write-Back Dispatcher
 *
 * Routes non-text items to the appropriate write-back adapter.
 * Items that no adapter can handle are sent to the manual queue.
 *
 * Integration point: called from Sync_Executor when processing
 * non-text content items (images, videos, documents, etc.)
 *
 * @package WPTSALL\Tasks\Sync
 * @since 1.0.5
 */

namespace WPTSALL\Tasks\Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Write-back dispatcher.
 *
 * Manages adapter registry and routes items to the correct adapter.
 */
class Write_Back_Dispatcher {

	/**
	 * Registered adapters.
	 *
	 * @var Write_Back_Adapter_Interface[]
	 */
	private static $adapters = array();

	/**
	 * Whether adapters have been initialized.
	 *
	 * @var bool
	 */
	private static $initialized = false;

	/**
	 * Initialize default adapters.
	 *
	 * Called lazily on first dispatch.
	 *
	 * @return void
	 */
	private static function init() {
		if ( self::$initialized ) {
			return;
		}

		self::register_adapter( new Attachment_Write_Back_Adapter() );
		self::register_adapter( new Media_Meta_Write_Back_Adapter() );
		self::register_adapter( new Document_Write_Back_Adapter() );

		/**
		 * Allow third-party plugins to register additional write-back adapters.
		 *
		 * @since 1.0.5
		 */
		do_action( 'wptsall_register_write_back_adapters' );

		self::$initialized = true;
	}

	/**
	 * Register a write-back adapter.
	 *
	 * @param Write_Back_Adapter_Interface $adapter The adapter instance.
	 * @return void
	 */
	public static function register_adapter( Write_Back_Adapter_Interface $adapter ): void {
		self::$adapters[ $adapter->get_type() ] = $adapter;
	}

	/**
	 * Get a registered adapter by type.
	 *
	 * @param string $type Adapter type.
	 * @return Write_Back_Adapter_Interface|null
	 */
	public static function get_adapter( string $type ): ?Write_Back_Adapter_Interface {
		self::init();
		return self::$adapters[ $type ] ?? null;
	}

	/**
	 * Get all registered adapters.
	 *
	 * @return Write_Back_Adapter_Interface[]
	 */
	public static function get_adapters(): array {
		self::init();
		return self::$adapters;
	}

	/**
	 * Dispatch a non-text item to the appropriate adapter.
	 *
	 * Finds the first adapter that can handle the item and calls apply().
	 * If no adapter matches or apply() fails, routes to manual queue.
	 *
	 * @param array $item    The non-text item.
	 *   Expected keys: 'entity_type', 'source_id', 'translated_ref', 'metadata'.
	 * @param array $context Sync context.
	 *   Expected keys: 'relation_id', 'task_id', 'source_blog', 'target_blog', 'target_type', 'lang_to'.
	 * @return array Result with keys: 'success', 'target_id', 'error', 'adapter', 'queued'.
	 */
	public static function dispatch( array $item, array $context ): array {
		self::init();

		$entity_type = sanitize_key( (string) ( $item['entity_type'] ?? '' ) );

		// Try to find a matching adapter.
		$matched_adapter = null;
		foreach ( self::$adapters as $adapter ) {
			if ( $adapter->can_handle( $item ) ) {
				$matched_adapter = $adapter;
				break;
			}
		}

		if ( null === $matched_adapter ) {
			// No adapter found - route to manual queue.
			$queue_id = Manual_Queue::enqueue(
				$item,
				array_merge( $context, array( 'adapter' => 'none' ) ),
				sprintf( 'No adapter found for entity_type=%s', $entity_type )
			);

			return array(
				'success'   => false,
				'target_id' => null,
				'error'     => sprintf( 'No write-back adapter for entity_type=%s', $entity_type ),
				'adapter'   => null,
				'queued'    => false !== $queue_id,
				'queue_id'  => $queue_id,
			);
		}

		// Validate translated_ref before attempting apply.
		$translated_ref = $item['translated_ref'] ?? array();
		$validation = $matched_adapter->validate_ref( $translated_ref );

		if ( is_wp_error( $validation ) ) {
			$queue_id = Manual_Queue::enqueue(
				$item,
				array_merge( $context, array( 'adapter' => $matched_adapter->get_type() ) ),
				$validation->get_error_message()
			);

			return array(
				'success'   => false,
				'target_id' => null,
				'error'     => $validation->get_error_message(),
				'adapter'   => $matched_adapter->get_type(),
				'queued'    => false !== $queue_id,
				'queue_id'  => $queue_id,
			);
		}

		// Attempt to apply.
		$result = $matched_adapter->apply( $item, $context );

		// If apply failed, route to manual queue.
		if ( empty( $result['success'] ) ) {
			$queue_id = Manual_Queue::enqueue(
				$item,
				array_merge( $context, array( 'adapter' => $matched_adapter->get_type() ) ),
				$result['error'] ?? 'Adapter apply failed'
			);

			$result['queued']   = false !== $queue_id;
			$result['queue_id'] = $queue_id;
		}

		return $result;
	}

	/**
	 * Dispatch a batch of non-text items.
	 *
	 * @param array $items   Array of non-text items.
	 * @param array $context Sync context (shared across all items).
	 * @return array Summary: 'total', 'applied', 'queued', 'failed', 'items'.
	 */
	public static function dispatch_batch( array $items, array $context ): array {
		$summary = array(
			'total'   => count( $items ),
			'applied' => 0,
			'queued'  => 0,
			'failed'  => 0,
			'items'   => array(),
		);

		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) ) {
				++$summary['failed'];
				continue;
			}

			$result = self::dispatch( $item, $context );
			$summary['items'][] = $result;

			if ( ! empty( $result['success'] ) ) {
				++$summary['applied'];
			} elseif ( ! empty( $result['queued'] ) ) {
				++$summary['queued'];
			} else {
				++$summary['failed'];
			}
		}

		wptsall_log_info(
			'tasks-sync',
			'Write-back batch dispatch completed',
			array(
				'total'   => $summary['total'],
				'applied' => $summary['applied'],
				'queued'  => $summary['queued'],
				'failed'  => $summary['failed'],
			)
		);

		return $summary;
	}

	/**
	 * Dispatch media mappings from a translation result.
	 *
	 * Adapts the translation_results.media_mappings format to the standard
	 * non-text item format expected by dispatch().
	 *
	 * @since 1.1.0
	 *
	 * @param array $media_mappings Media mappings from translation result.
	 *   Each mapping: { 'source_url', 'translated_url', 'entity_type', 'source_id', 'metadata' }.
	 * @param array $context        Sync context (relation_id, task_id, source_blog, target_blog, target_type, lang_to).
	 * @return array Summary from dispatch_batch().
	 */
	public static function dispatch_translation_media( array $media_mappings, array $context ): array {
		$items = array();
		foreach ( $media_mappings as $mapping ) {
			$items[] = array(
				'entity_type'    => sanitize_key( (string) ( $mapping['entity_type'] ?? 'attachment' ) ),
				'source_id'      => (int) ( $mapping['source_id'] ?? 0 ),
				'translated_ref' => array(
					'ref_type'  => ! empty( $mapping['attachment_id'] ) ? 'id' : 'url',
					'ref_value' => ! empty( $mapping['attachment_id'] )
						? (int) $mapping['attachment_id']
						: ( $mapping['translated_url'] ?? '' ),
				),
				'metadata'       => $mapping['metadata'] ?? array(),
			);
		}

		return self::dispatch_batch( $items, $context );
	}

	/**
	 * Reset adapters (for testing).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$adapters    = array();
		self::$initialized = false;
	}
}

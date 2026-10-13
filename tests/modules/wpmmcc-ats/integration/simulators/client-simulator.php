<?php
/**
 * Client Simulator
 *
 * Simulates the Rust client's task-pull and translation-callback flow
 * entirely in-process using rest_do_request(). No actual HTTP round-trips
 * are made; all REST calls go through the WordPress REST server directly.
 *
 * This file MUST be included AFTER wp-load.php.
 *
 * Usage:
 *   require_once __DIR__ . '/simulators/client-simulator.php';
 *   $simulator = new WPTSALL_Client_Simulator();
 *   $results   = $simulator->pull_and_process_tasks( 20 );
 *
 * Routing by business_line:
 *   post_content / taxonomy_content / custom_model  -> simulate_content_callback()
 *   theme_i18n / plugin_i18n                        -> simulate_i18n_callback()
 *   task_type = media (image/video/audio/document)  -> simulate_media_upload()
 *
 * Translation marker format:
 *   Display fields: 【{lang}】{value}【/{lang}】
 *   Functional fields (post_name, guid): left unchanged.
 *
 * Idempotency:
 *   Keyed on client_task_id (= job_id from the task response).
 *   If job_id is empty the simulator falls back to "task_{task_id}".
 *
 * Protocol contracts:
 *   - Tasks list: { success: true, data: { schema_version: 3, items: [...] } }
 *   - Token header: X-WPTSALL-Client-Token
 *   - Route: /wptsall/v2/{secret}/client/tasks
 *   - Callback required fields: client_task_id, relation_id, business_line
 *
 * @package WPTSALL
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WPTSALL Client Simulator
 *
 * Mimics the behaviour of the external Rust client (client/src/) for use
 * inside integration tests. All REST calls use rest_do_request() so there
 * is no network overhead and no dependency on the server binary being
 * running.
 */
class WPTSALL_Client_Simulator {

	/**
	 * Client API token loaded from WordPress options.
	 *
	 * @var string
	 */
	private $token = '';

	/**
	 * Per-installation route secret that prefixes the client REST paths.
	 *
	 * @var string
	 */
	private $secret = '';

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	private $namespace = 'wptsall/v2';

	/**
	 * Device id bound to the issued device-scoped token.
	 *
	 * @var string
	 */
	private $device_id = '';

	/**
	 * Constructor – issues a device-scoped token and loads route secret.
	 */
	public function __construct() {
		if ( function_exists( 'wptsall_issue_client_device_token' ) ) {
			$issued = wptsall_issue_client_device_token( 'sim-' . wp_generate_password( 6, false ), 'simulator' );
			$this->token     = (string) ( $issued['token'] ?? '' );
			$this->device_id = (string) ( $issued['device_id'] ?? '' );
		}

		if ( function_exists( 'wptsall_get_client_route_secret' ) ) {
			$this->secret = (string) wptsall_get_client_route_secret();
		}
	}

	// =========================================================================
	// Public API
	// =========================================================================

	/**
	 * Pull pending tasks and process each one.
	 *
	 * Mirrors the "one-shot" execution loop of the Rust client
	 * (WPTSALL_ONESHOT=true). Returns a flat array of per-task result maps.
	 *
	 * @param int $limit Maximum number of tasks to pull (default 20).
	 * @return array Array of result maps, one per processed task.
	 */
	public function pull_and_process_tasks( int $limit = 20 ): array {
		$results = array();

		// Build the task-pull route.
		$route    = $this->build_client_route( 'tasks' );
		$request  = new WP_REST_Request( 'GET', $route );
		$request->set_header( 'X-WPTSALL-Protocol-Version', '2' );
		$request->set_header( 'X-WPTSALL-Device-Id', $this->device_id );
		$request->set_header( 'X-WPTSALL-Client-Token', $this->token );
		$request->set_query_params( array(
			'status' => 'pending',
			'limit'  => $limit,
		) );

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		// Validate envelope: { success: true, data: { schema_version: 3, items: [...] } }
		if ( ! isset( $data['success'] ) || ! $data['success'] ) {
			return array(
				array(
					'task_id'     => 0,
					'http_status' => $response->get_status(),
					'success'     => false,
					'error'       => 'pull_failed',
					'message'     => 'Task pull endpoint returned success=false',
					'raw'         => $data,
				),
			);
		}

		$items = $data['data']['items'] ?? array();
		if ( empty( $items ) || ! is_array( $items ) ) {
			return array();
		}

		foreach ( $items as $task ) {
			if ( ! is_array( $task ) ) {
				continue;
			}
			$results[] = $this->process_task( $task );
		}

		return $results;
	}

	/**
	 * Load and process a specific task by its DB id.
	 *
	 * This bypasses global pending queue ordering and is useful in
	 * integration tests where other suites may leave many pending tasks.
	 *
	 * @param int $task_id Task primary key in wptsall_tasks.
	 * @return array Result map.
	 */
	public function process_task_by_id( int $task_id ): array {
		if ( $task_id <= 0 ) {
			return array(
				'task_id'     => 0,
				'http_status' => 0,
				'success'     => false,
				'error'       => 'invalid_task_id',
				'message'     => 'task_id must be a positive integer.',
			);
		}

		$task = $this->load_task_by_id( $task_id );
		if ( empty( $task ) ) {
			return array(
				'task_id'     => $task_id,
				'http_status' => 0,
				'success'     => false,
				'error'       => 'task_not_found',
				'message'     => 'Task not found in tasks table.',
			);
		}

		return $this->process_task( $task );
	}

	// =========================================================================
	// Per-task dispatch
	// =========================================================================

	/**
	 * Dispatch a single task to the appropriate handler.
	 *
	 * @param array $task Task item from the tasks list response.
	 * @return array Result map.
	 */
	private function process_task( array $task ): array {
		$business_line = (string) ( $task['business_line'] ?? 'custom_model' );
		$task_type     = (string) ( $task['task_type'] ?? 'text' );

		// Media tasks are identified by task_type (image/video/audio/document).
		if ( in_array( $task_type, array( 'image', 'video', 'audio', 'document' ), true ) ) {
			return $this->simulate_media_upload( $task );
		}

		// i18n tasks.
		if ( in_array( $business_line, array( 'theme_i18n', 'plugin_i18n' ), true ) ) {
			return $this->simulate_i18n_callback( $task );
		}

		// Content tasks (post_content / taxonomy_content / custom_model).
		if ( in_array( $business_line, array( 'post_content', 'taxonomy_content', 'custom_model' ), true ) ) {
			return $this->simulate_content_callback( $task );
		}

		// Unknown business_line – fall through to content callback as safe default.
		return $this->simulate_content_callback( $task );
	}

	// =========================================================================
	// Content callback (post_content / taxonomy_content / custom_model)
	// =========================================================================

	/**
	 * Simulate a content translation callback.
	 *
	 * Builds a translation-callback payload that mirrors what the Rust
	 * executor sends after calling mock-translate-api. Each translatable
	 * field is wrapped with the translation marker:
	 *   【{lang}】{value}【/{lang}】
	 *
	 * The callback uses the newer /client/translation-callback endpoint
	 * (Client_Data_REST_Controller) rather than the deprecated /result one.
	 *
	 * Required fields in payload sent to the callback:
	 *   client_task_id    string  (= task's job_id, idempotency key)
	 *   relation_id       int
	 *   business_line     string
	 *   object_type       string  (post_type | taxonomy | post | term)
	 *   object_id         int
	 *   translated_fields array   { field_name => translated_value }
	 *
	 * @param array $task Task item from tasks list.
	 * @return array Result map with keys: task_id, http_status, success, ...
	 */
	public function simulate_content_callback( array $task ): array {
		$task_id        = (int) ( $task['task_id'] ?? 0 );
		$job_id         = (string) ( $task['job_id'] ?? '' );
		$client_task_id = '' !== $job_id ? $job_id : 'task_' . $task_id;
		$relation_id    = (int) ( $task['relation_id'] ?? 0 );
		$business_line  = (string) ( $task['business_line'] ?? 'post_content' );
		$object_type    = (string) ( $task['object_type'] ?? 'post_type' );
		$object_id      = (int) ( $task['object_id'] ?? 0 );
		$target_lang    = (string) ( $task['target_lang'] ?? 'zh_CN' );
		$source_lang    = (string) ( $task['source_lang'] ?? 'en_US' );
		$subtype        = $this->resolve_callback_subtype( $task, $object_type );

		// Path B requires a device-owned content claim before write-back.
		$this->claim_content_item( $relation_id, $object_type, $object_id, $subtype );

		// Build translated_fields from the task payload's subtasks.
		$translated_fields = $this->build_translated_fields_from_task( $task, $target_lang );

		$callback_body = array(
			'client_task_id'    => $client_task_id,
			'relation_id'       => $relation_id,
			'business_line'     => $business_line,
			'object_type'       => $object_type,
			'object_id'         => $object_id,
			'source_lang'       => $source_lang,
			'target_lang'       => $target_lang,
			'translated_fields' => $translated_fields,
			'translated_meta'   => array(),
			'media_mappings'    => array(),
			'source_revision'   => $this->resolve_source_revision( $task, $object_type, $object_id ),
			'policy_version'    => (string) ( $task['policy_version'] ?? $task['complete_data']['__wptsall_job_snapshot']['policy_version'] ?? ( class_exists( '\\WPTSALL\\Core\\Job_Snapshot' ) ? \WPTSALL\Core\Job_Snapshot::current_policy_version() : 'test-policy-v1' ) ),
			'post_type'         => $subtype,
			'subtype'           => $subtype,
		);

		$route   = $this->build_client_route( 'translation-callback' );
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WPTSALL-Protocol-Version', '2' );
		$request->set_header( 'X-WPTSALL-Device-Id', $this->device_id );
		$request->set_header( 'X-WPTSALL-Client-Token', $this->token );
		$request->set_body( wp_json_encode( $callback_body ) );

		$response = rest_do_request( $request );
		$status   = $response->get_status();
		$resp_data = $response->get_data();

		return array(
			'task_id'        => $task_id,
			'client_task_id' => $client_task_id,
			'relation_id'    => $relation_id,
			'object_type'    => $object_type,
			'object_id'      => $object_id,
			'business_line'  => $business_line,
			'http_status'    => $status,
			'success'        => $status === 200 && ! empty( $resp_data['success'] ),
			'idempotent'     => ! empty( $resp_data['idempotent'] ),
			'result_id'      => (int) ( $resp_data['result_id'] ?? 0 ),
			'sync_task_id'   => (int) ( $resp_data['sync_task_id'] ?? 0 ),
			'response'       => $resp_data,
		);
	}

	// =========================================================================
	// i18n callback (theme_i18n / plugin_i18n)
	// =========================================================================

	/**
	 * Simulate an i18n translation callback.
	 *
	 * The Rust client sends translated template entries back via the
	 * translation-callback endpoint with business_line = theme_i18n or
	 * plugin_i18n. The payload contains an "entries" array where each item
	 * references a template_entries row by entry_id and provides the
	 * translated msgstr.
	 *
	 * The subtasks in the task payload use the "entry_{id}" id format, e.g.:
	 *   { "id": "entry_42", "type": "text", "value": "Hello" }
	 *
	 * Required callback fields:
	 *   client_task_id  string
	 *   relation_id     int
	 *   business_line   string (theme_i18n | plugin_i18n)
	 *   entries         array  [{ entry_id: int, msgstr: string }, ...]
	 *
	 * @param array $task Task item from tasks list.
	 * @return array Result map.
	 */
	public function simulate_i18n_callback( array $task ): array {
		$task_id        = (int) ( $task['task_id'] ?? 0 );
		$job_id         = (string) ( $task['job_id'] ?? '' );
		$client_task_id = '' !== $job_id ? $job_id : 'task_' . $task_id;
		$relation_id    = (int) ( $task['relation_id'] ?? 0 );
		$business_line  = (string) ( $task['business_line'] ?? 'plugin_i18n' );
		$target_lang    = (string) ( $task['target_lang'] ?? 'zh_CN' );
		$source_lang    = (string) ( $task['source_lang'] ?? 'en_US' );

		// Build entries from subtasks. Each subtask has id = "entry_{id}".
		$entries = $this->build_i18n_entries_from_task( $task, $target_lang );

		$callback_body = array(
			'client_task_id'  => $client_task_id,
			'relation_id'     => $relation_id,
			'business_line'   => $business_line,
			'source_lang'     => $source_lang,
			'target_lang'     => $target_lang,
			'entries'         => $entries,
			'source_revision' => $this->resolve_source_revision( $task, (string) ( $task['object_type'] ?? 'post_type' ), (int) ( $task['object_id'] ?? 0 ) ),
			'policy_version'  => (string) ( $task['policy_version'] ?? $task['complete_data']['__wptsall_job_snapshot']['policy_version'] ?? ( class_exists( '\\WPTSALL\\Core\\Job_Snapshot' ) ? \WPTSALL\Core\Job_Snapshot::current_policy_version() : 'test-policy-v1' ) ),
		);

		$route   = $this->build_client_route( 'translation-callback' );
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WPTSALL-Protocol-Version', '2' );
		$request->set_header( 'X-WPTSALL-Device-Id', $this->device_id );
		$request->set_header( 'X-WPTSALL-Client-Token', $this->token );
		$request->set_body( wp_json_encode( $callback_body ) );

		$response  = rest_do_request( $request );
		$status    = $response->get_status();
		$resp_data = $response->get_data();

		return array(
			'task_id'         => $task_id,
			'client_task_id'  => $client_task_id,
			'relation_id'     => $relation_id,
			'business_line'   => $business_line,
			'http_status'     => $status,
			'success'         => $status === 200 && ! empty( $resp_data['success'] ),
			'idempotent'      => ! empty( $resp_data['idempotent'] ),
			'entries_updated' => (int) ( $resp_data['entries_updated'] ?? 0 ),
			'entries_count'   => count( $entries ),
			'response'        => $resp_data,
		);
	}

	// =========================================================================
	// Media upload (task_type = image/video/audio/document)
	// =========================================================================

	/**
	 * Simulate a media file upload.
	 *
	 * The Rust client uploads translated media as raw binary via the
	 * /client/media-upload endpoint. The simulator generates a minimal
	 * valid PNG (1×1 white pixel) as the binary body so that WordPress's
	 * media pipeline accepts the upload.
	 *
	 * Required headers for the media-upload endpoint:
	 *   X-WPTSALL-Client-Token  string  (auth)
	 *   X-WPTSALL-Filename      string  (required – original filename)
	 *   X-WPTSALL-Task-ID       int     (associated task ID)
	 *   X-WPTSALL-Source-ID     int     (source attachment ID)
	 *   Content-Type            string  (MIME type of binary body)
	 *
	 * Body: raw binary file data.
	 *
	 * @param array $task Task item from tasks list.
	 * @return array Result map.
	 */
	public function simulate_media_upload( array $task ): array {
		$task_id    = (int) ( $task['task_id'] ?? 0 );
		$job_id     = (string) ( $task['job_id'] ?? '' );
		$client_task_id = '' !== $job_id ? $job_id : 'task_' . $task_id;
		$task_type  = (string) ( $task['task_type'] ?? 'image' );
		$object_id  = (int) ( $task['object_id'] ?? 0 );
		$relation_id = (int) ( $task['relation_id'] ?? 0 );
		$target_lang = (string) ( $task['target_lang'] ?? 'zh_CN' );

		// Derive a reasonable filename and MIME type from task_type.
		$media_info  = $this->get_mock_media_info( $task_type, $target_lang );
		$filename    = $media_info['filename'];
		$content_type = $media_info['content_type'];
		$binary_body  = $media_info['body'];

		$route   = $this->build_client_route( 'media-upload' );
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_header( 'Content-Type', $content_type );
		$request->set_header( 'X-WPTSALL-Protocol-Version', '2' );
		$request->set_header( 'X-WPTSALL-Device-Id', $this->device_id );
		$request->set_header( 'X-WPTSALL-Client-Token', $this->token );
		$request->set_header( 'X-WPTSALL-Filename', $filename );
		$request->set_header( 'X-WPTSALL-Task-ID', (string) $task_id );
		$request->set_header( 'X-WPTSALL-Source-ID', (string) $object_id );
		$request->set_body( $binary_body );

		$response  = rest_do_request( $request );
		$status    = $response->get_status();
		$resp_data = $response->get_data();

		return array(
			'task_id'        => $task_id,
			'client_task_id' => $client_task_id,
			'relation_id'    => $relation_id,
			'object_id'      => $object_id,
			'business_line'  => 'media',
			'task_type'      => $task_type,
			'http_status'    => $status,
			'success'        => $status === 200 && ! empty( $resp_data['success'] ),
			'attachment_id'  => (int) ( $resp_data['attachment_id'] ?? 0 ),
			'url'            => (string) ( $resp_data['url'] ?? '' ),
			'response'       => $resp_data,
		);
	}

	// =========================================================================
	// Helper: build translated fields
	// =========================================================================

	/**
	 * Build a translated_fields map from a task's subtask list.
	 *
	 * Each subtask that carries a text value is translated with the mock
	 * translation marker format:  【{lang}】{value}【/{lang}】
	 *
	 * The following fields are left unchanged (functional fields that must
	 * not carry translated text):
	 *   post_name, post_slug, guid, id, ID
	 *
	 * @param array  $task        Task item from tasks list.
	 * @param string $target_lang Target language code (e.g. zh_CN).
	 * @return array  Associative array of field_name => translated_value.
	 */
	private function build_translated_fields_from_task( array $task, string $target_lang ): array {
		$translated = array();

		// Subtasks are carried in payload.subtasks (normalised by the server).
		$payload  = $task['payload'] ?? array();
		$subtasks = array();

		if ( is_array( $payload ) ) {
			$subtasks = $payload['subtasks'] ?? ( $payload['content_items'] ?? array() );
		}

		// Functional fields that must NOT receive translation markers.
		$functional_fields = array( 'post_name', 'post_slug', 'guid', 'id', 'ID' );

		foreach ( (array) $subtasks as $subtask ) {
			if ( ! is_array( $subtask ) ) {
				continue;
			}

			$type  = (string) ( $subtask['type'] ?? 'text' );
			$key   = (string) ( $subtask['key'] ?? ( $subtask['id'] ?? '' ) );
			$value = (string) ( $subtask['value'] ?? '' );

			// Only process text subtasks; skip media ones here.
			if ( 'text' !== $type ) {
				continue;
			}

			if ( '' === $key || in_array( $key, $functional_fields, true ) ) {
				continue;
			}

			// Apply mock translation marker.
			$translated[ $key ] = $this->apply_translation_marker( $value, $target_lang );
		}

		// Fall back: if payload carries a flat "fields" map, use that.
		if ( empty( $translated ) && is_array( $payload ) && ! empty( $payload['fields'] ) && is_array( $payload['fields'] ) ) {
			foreach ( $payload['fields'] as $field_name => $field_value ) {
				if ( in_array( $field_name, $functional_fields, true ) ) {
					continue;
				}
				if ( is_string( $field_value ) ) {
					$translated[ $field_name ] = $this->apply_translation_marker( $field_value, $target_lang );
				}
			}
		}

		// Final fallback: some payloads provide empty values only. Use the
		// source object content so callback assertions can verify marker format.
		if ( $this->translated_fields_effectively_empty( $translated ) ) {
			$object_type = (string) ( $task['object_type'] ?? '' );
			$object_id   = (int) ( $task['object_id'] ?? 0 );
			if ( $object_id > 0 && in_array( $object_type, array( 'post', 'post_type' ), true ) ) {
				$post = get_post( $object_id );
				if ( $post instanceof \WP_Post ) {
					foreach ( array( 'post_title', 'post_content', 'post_excerpt' ) as $field_name ) {
						$value = (string) ( $post->{$field_name} ?? '' );
						if ( '' === $value ) {
							continue;
						}
						$translated[ $field_name ] = $this->apply_translation_marker( $value, $target_lang );
					}
				}
			}
		}

		return $translated;
	}

	/**
	 * Check whether translated fields are missing meaningful values.
	 *
	 * @param array $translated Field map.
	 * @return bool
	 */
	private function translated_fields_effectively_empty( array $translated ): bool {
		if ( empty( $translated ) ) {
			return true;
		}

		foreach ( $translated as $value ) {
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Build the entries array for an i18n callback from a task's subtasks.
	 *
	 * i18n subtasks use the id format "entry_{id}". The simulator translates
	 * the source msgid value using the mock marker and maps it to entry_id
	 * and msgstr for the callback payload.
	 *
	 * @param array  $task        Task item from tasks list.
	 * @param string $target_lang Target language code.
	 * @return array  Array of { entry_id: int, msgstr: string } maps.
	 */
	private function build_i18n_entries_from_task( array $task, string $target_lang ): array {
		$entries  = array();
		$payload  = $task['payload'] ?? array();
		$subtasks = array();

		if ( is_array( $payload ) ) {
			$subtasks = $payload['subtasks'] ?? ( $payload['content_items'] ?? array() );
		}

		foreach ( (array) $subtasks as $subtask ) {
			if ( ! is_array( $subtask ) ) {
				continue;
			}

			$id    = (string) ( $subtask['id'] ?? ( $subtask['key'] ?? '' ) );
			$value = (string) ( $subtask['value'] ?? '' );

			// Entry IDs use the format "entry_{numeric_id}".
			if ( 0 !== strpos( $id, 'entry_' ) ) {
				continue;
			}

			$entry_id = (int) substr( $id, 6 );
			if ( $entry_id <= 0 ) {
				continue;
			}

			$entries[] = array(
				'entry_id' => $entry_id,
				'msgstr'   => $this->apply_translation_marker( $value, $target_lang ),
			);
		}

		return $entries;
	}

	// =========================================================================
	// Helper: translation marker
	// =========================================================================

	/**
	 * Wrap a value with the mock translation marker.
	 *
	 * Format: 【{lang}】{value}【/{lang}】
	 *
	 * Mirrors the format produced by mock-translate-api (:9090).
	 * Empty values are returned as-is.
	 *
	 * @param string $value       Source text.
	 * @param string $target_lang Target language code (e.g. zh_CN).
	 * @return string Translated value with marker.
	 */
	private function apply_translation_marker( string $value, string $target_lang ): string {
		if ( '' === $value ) {
			return $value;
		}
		return '【' . $target_lang . '】' . $value . '【/' . $target_lang . '】';
	}

	// =========================================================================
	// Helper: mock media binary
	// =========================================================================

	/**
	 * Return mock binary data for a given media task_type.
	 *
	 * For image tasks a minimal 1×1 white PNG is used because WordPress's
	 * media pipeline validates the actual file contents (not just the MIME
	 * header). For other media types a tiny valid binary is generated.
	 *
	 * @param string $task_type   Media type: image, video, audio, document.
	 * @param string $target_lang Target language code (used in filename only).
	 * @return array { filename: string, content_type: string, body: string }
	 */
	private function get_mock_media_info( string $task_type, string $target_lang ): array {
		$lang_safe = preg_replace( '/[^a-z0-9_\-]/', '_', strtolower( $target_lang ) );

		switch ( $task_type ) {
			case 'video':
				return array(
					'filename'     => 'translated_video_' . $lang_safe . '.mp4',
					'content_type' => 'video/mp4',
					// Minimal valid ftyp box for MP4.
					'body'         => "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41",
				);

			case 'audio':
				return array(
					'filename'     => 'translated_audio_' . $lang_safe . '.mp3',
					'content_type' => 'audio/mpeg',
					// ID3v2 header + silent MPEG frame.
					'body'         => "ID3\x03\x00\x00\x00\x00\x00\x00\xff\xfb\x90\x00",
				);

			case 'document':
				return array(
					'filename'     => 'translated_doc_' . $lang_safe . '.pdf',
					'content_type' => 'application/pdf',
					// Minimal valid PDF.
					'body'         => "%PDF-1.0\n1 0 obj<</Type /Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type /Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type /Page/MediaBox[0 0 3 3]>>endobj\nxref\n0 4\n0000000000 65535 f\n0000000009 00000 n\n0000000058 00000 n\n0000000115 00000 n\ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n190\n%%EOF",
				);

			case 'image':
			default:
				// Minimal 1x1 white PNG (binary-safe).
				return array(
					'filename'     => 'translated_image_' . $lang_safe . '.png',
					'content_type' => 'image/png',
					'body'         => base64_decode(
						'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
					),
				);
		}
	}

	// =========================================================================
	// Helper: route construction
	// =========================================================================

	/**
	 * Claim a content item for this simulator device before callback (Path B).
	 *
	 * @param int    $relation_id Relation id.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object id.
	 * @param string $subtype     Post type / taxonomy.
	 * @return void
	 */
	private function claim_content_item( int $relation_id, string $object_type, int $object_id, string $subtype ): void {
		if ( $relation_id <= 0 || $object_id <= 0 ) {
			return;
		}
		$data_type = 'post';
		if ( in_array( $object_type, array( 'taxonomy', 'term' ), true ) ) {
			$data_type = 'term';
		} elseif ( 'media' === $object_type ) {
			$data_type = 'media';
		}
		$route   = $this->build_client_route( 'content/claim' );
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WPTSALL-Protocol-Version', '2' );
		$request->set_header( 'X-WPTSALL-Device-Id', $this->device_id );
		$request->set_header( 'X-WPTSALL-Client-Token', $this->token );
		$request->set_body(
			wp_json_encode(
				array(
					'relation_id' => $relation_id,
					'data_type'   => $data_type,
					'items'       => array(
						array(
							'object_id' => $object_id,
							'post_type' => $subtype,
							'subtype'   => $subtype,
						),
					),
				)
			)
		);
		rest_do_request( $request );
	}

	/**
	 * Resolve Path B source_revision for a callback (claim snapshot or compute).
	 *
	 * @param array  $task        Task item.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object id.
	 * @return string
	 */
	private function resolve_source_revision( array $task, string $object_type, int $object_id ): string {
		$from_task = (string) ( $task['source_revision']
			?? $task['complete_data']['__wptsall_job_snapshot']['source_revision']
			?? '' );
		if ( '' !== $from_task ) {
			return $from_task;
		}
		if ( class_exists( '\\WPTSALL\\Core\\Job_Snapshot' ) && $object_id > 0 ) {
			$ot = $object_type;
			if ( 'post' === $ot ) {
				$ot = 'post_type';
			} elseif ( 'term' === $ot ) {
				$ot = 'taxonomy';
			}
			return (string) \WPTSALL\Core\Job_Snapshot::compute_source_revision( $ot, $object_id );
		}
		return 'test-source-revision';
	}

	/**
	 * Resolve post_type/taxonomy subtype required by Path B callbacks.
	 *
	 * @param array  $task        Task item.
	 * @param string $object_type Object type.
	 * @return string
	 */
	private function resolve_callback_subtype( array $task, string $object_type ): string {
		$from_task = sanitize_key(
			(string) ( $task['subtype']
				?? $task['post_type']
				?? $task['complete_data']['post_type']
				?? '' )
		);
		if ( '' !== $from_task ) {
			return $from_task;
		}
		if ( in_array( $object_type, array( 'post_type', 'post' ), true ) ) {
			return 'post';
		}
		if ( 'media' === $object_type ) {
			return 'attachment';
		}
		if ( in_array( $object_type, array( 'taxonomy', 'term' ), true ) ) {
			return 'category';
		}
		return 'post';
	}

	/**
	 * Build an absolute REST route for a client endpoint.
	 *
	 * Pattern: /wptsall/v2/{secret}/client/{endpoint}
	 *
	 * @param string $endpoint Endpoint name (e.g. 'tasks', 'translation-callback').
	 * @return string Full route path (e.g. /wptsall/v2/abc123/client/tasks).
	 */
	private function build_client_route( string $endpoint ): string {
		$parts = array( '', $this->namespace );

		if ( '' !== $this->secret ) {
			$parts[] = $this->secret;
		}

		$parts[] = 'client';
		$parts[] = $endpoint;

		return implode( '/', $parts );
	}

	/**
	 * Load one task row from DB and map it into the simulator's task shape.
	 *
	 * @param int $task_id Task primary key.
	 * @return array
	 */
	private function load_task_by_id( int $task_id ): array {
		global $wpdb;

		if ( ! function_exists( 'wptsall_table' ) ) {
			return array();
		}

		$table = wptsall_table( 'tasks' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, type, site_id, object_type, subtype, object_id, lang_from, lang_to, payload
				 FROM {$table}
				 WHERE id = %d
				 LIMIT 1",
				$task_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return array();
		}

		$payload = json_decode( (string) ( $row['payload'] ?? '' ), true );
		if ( ! is_array( $payload ) ) {
			$payload = array();
		}

		$task = array(
			'task_id'       => (int) ( $row['id'] ?? 0 ),
			'type'          => sanitize_key( (string) ( $row['type'] ?? 'sync' ) ),
			'job_id'        => sanitize_text_field( (string) ( $payload['job_id'] ?? '' ) ),
			'business_line' => sanitize_key( (string) ( $payload['business_line'] ?? '' ) ),
			'task_type'     => sanitize_key( (string) ( $payload['task_type'] ?? 'text' ) ),
			'object_type'   => sanitize_key( (string) ( $row['object_type'] ?? 'post_type' ) ),
			'subtype'       => sanitize_key( (string) ( $row['subtype'] ?? '' ) ),
			'object_id'     => (int) ( $row['object_id'] ?? 0 ),
			'relation_id'   => (int) ( $row['site_id'] ?? 0 ),
			'source_lang'   => sanitize_text_field( (string) ( $row['lang_from'] ?? 'en_US' ) ),
			'target_lang'   => sanitize_text_field( (string) ( $row['lang_to'] ?? 'zh_CN' ) ),
			'payload'       => $payload,
		);

		if ( '' === $task['business_line'] ) {
			$task['business_line'] = 'taxonomy' === $task['object_type'] ? 'taxonomy_content' : 'post_content';
		}

		return $task;
	}
}

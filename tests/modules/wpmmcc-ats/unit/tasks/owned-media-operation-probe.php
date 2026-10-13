<?php
// Private real-controller/import/filesystem checks on verified owned WP only.
global $media_operation_rows;
$media_operation_rows = array();
function media_operation_check( $name, $ok ) {
	global $media_operation_rows;
	$media_operation_rows[] = array( 'name' => $name, 'passed' => (bool) $ok );
}
function media_operation_request( $step, $operation, $source, $relation, $png, $device = 'owned-operation-device' ) {
	$request = new \WP_REST_Request( 'status' === $step ? 'GET' : 'POST', '/client/media-upload/' . $step );
	$request->set_header( 'X-WPTSALL-Device-Id', $device );
	$request->set_header( 'X-WPTSALL-Operation-ID', $operation );
	$request->set_header( 'X-WPTSALL-Content-SHA256', hash( 'sha256', $png ) );
	$request->set_header( 'X-WPTSALL-Filename', 'owned-operation.png' );
	$request->set_header( 'X-WPTSALL-Source-ID', (string) $source );
	$request->set_header( 'X-WPTSALL-Task-ID', '0' );
	$request->set_header( 'X-WPTSALL-Relation-ID', (string) $relation );
	if ( 'single' === $step ) {
		$request->set_header( 'Content-Type', 'image/png' );
		$request->set_body( $png );
	} elseif ( 'status' === $step ) {
		$request->set_param( 'operation_id', $operation );
	} else {
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array(
			'operation_id' => $operation, 'content_sha256' => hash( 'sha256', $png ),
			'filename' => 'owned-operation.png', 'total_size' => strlen( $png ),
			'chunk_count' => 1, 'chunk_size' => 5 * 1024 * 1024,
			'content_type' => 'image/png', 'source_id' => $source, 'task_id' => 0, 'relation_id' => $relation,
		) ) );
	}
	return $request;
}
$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' );
$uploaded = wp_upload_bits( 'owned-operation-source.png', null, $png );
if ( ! empty( $uploaded['error'] ) ) { throw new \RuntimeException( 'owned PNG fixture failed' ); }
$source = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'Owned source', 'post_status' => 'private' ), $uploaded['file'] );
$created = \WPTSALL\Sites\Services\Site_Relation_Service::create_relation( array(
	'template' => 'wordpress-blog', 'source_site_id' => get_current_blog_id(), 'source_lang' => 'en',
	'target_sites' => array( array( 'id' => 'v_owned_op_' . uniqid(), 'type' => 'virtual', 'lang' => 'zh_CN' ) ),
) );
$relation = (int) ( $created['relation_ids'][0] ?? 0 );
if ( $source <= 0 || $relation <= 0 ) { throw new \RuntimeException( 'owned operation identity fixture failed' ); }
$controller = new \WPTSALL\Tasks\API\Client_Data_REST_Controller();
$id = wp_generate_uuid4();
$status = $controller->media_upload_status( media_operation_request( 'status', $id, $source, $relation, $png ) );
media_operation_check( 'absent operation has explicit not-started proof', 'not_started' === ( $status->get_data()['data']['state'] ?? '' ) );
$init = media_operation_request( 'init', $id, $source, $relation, $png );
$first = $controller->media_upload_init( $init );
$second = $controller->media_upload_init( $init );
media_operation_check( 'init operation identity is deterministic and idempotent', 200 === $first->get_status() && $id === ( $second->get_data()['upload_id'] ?? '' ) );
$path = trailingslashit( wp_upload_dir()['basedir'] ) . 'wptsall-chunked/' . $id . '/meta.json';
$before = file_get_contents( $path );
$other = $controller->media_upload_init( media_operation_request( 'init', $id, $source, $relation, $png, 'other-device' ) );
media_operation_check( 'foreign init cannot claim operation', 403 === $other->get_status() && $before === file_get_contents( $path ) );
$changed = media_operation_request( 'init', $id, $source, $relation, $png . 'changed' );
media_operation_check( 'changed bytes cannot reuse operation', 409 === $controller->media_upload_init( $changed )->get_status() );
$chunk = new \WP_REST_Request( 'POST', '/client/media-upload/chunk' );
$chunk->set_header( 'X-WPTSALL-Device-Id', 'owned-operation-device' );
$chunk->set_header( 'X-WPTSALL-Upload-ID', $id );
$chunk->set_header( 'X-WPTSALL-Chunk-Index', '0' );
$chunk->set_body( $png );
$controller->media_upload_chunk( $chunk );
$complete = new \WP_REST_Request( 'POST', '/client/media-upload/complete' );
$complete->set_header( 'X-WPTSALL-Device-Id', 'owned-operation-device' );
$complete->set_header( 'Content-Type', 'application/json' );
$complete->set_body( wp_json_encode( array( 'upload_id' => $id ) ) );
$done = $controller->media_upload_complete( $complete );
$attachment = (int) ( $done->get_data()['attachment_id'] ?? 0 );
media_operation_check( 'operation completes genuine attachment', 200 === $done->get_status() && $attachment > 0 );
$status = $controller->media_upload_status( media_operation_request( 'status', $id, $source, $relation, $png ) );
$data = $status->get_data()['data'] ?? array();
media_operation_check( 'lookup by operation retains full completion binding', $id === ( $data['operation_id'] ?? '' ) && hash( 'sha256', $png ) === ( $data['content_sha256'] ?? '' ) && $attachment === (int) ( $data['attachment_id'] ?? 0 ) && $source === (int) ( $data['source_id'] ?? 0 ) );

$id = wp_generate_uuid4();
$single = media_operation_request( 'single', $id, $source, $relation, $png );
$one = $controller->media_upload( $single );
$two = $controller->media_upload( $single );
$attachment = (int) ( $one->get_data()['attachment_id'] ?? 0 );
media_operation_check( 'single upload replays the same genuine attachment', $attachment > 0 && $one->get_data() === $two->get_data() );
$ids = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'fields' => 'ids',
	'posts_per_page' => 5, 'meta_key' => '_wptsall_media_recovery_operation', 'meta_value' => $id ) );
media_operation_check( 'single operation imports exactly once', 1 === count( $ids ) );
media_operation_check( 'foreign single request does not import', 403 === $controller->media_upload( media_operation_request( 'single', $id, $source, $relation, $png, 'other-device' ) )->get_status() );
media_operation_check( 'single source binding cannot change', 403 === $controller->media_upload( media_operation_request( 'single', $id, $source + 1, $relation, $png ) )->get_status() );
$wrong = media_operation_request( 'single', wp_generate_uuid4(), $source, $relation, $png );
$wrong->set_header( 'X-WPTSALL-Content-SHA256', str_repeat( '0', 64 ) );
media_operation_check( 'single digest mismatch refuses import', 400 === $controller->media_upload( $wrong )->get_status() );

$id = wp_generate_uuid4();
$fail_result = function( $metadata ) { $GLOBALS['owned_chunk_refuse_meta_rename'] = true; return $metadata; };
add_filter( 'wp_generate_attachment_metadata', $fail_result, 99 );
$failed = $controller->media_upload( media_operation_request( 'single', $id, $source, $relation, $png ) );
remove_filter( 'wp_generate_attachment_metadata', $fail_result, 99 );
$GLOBALS['owned_chunk_refuse_meta_rename'] = false;
media_operation_check( 'import succeeded but result persistence failed closed', 500 === $failed->get_status() );
$unknown = $controller->media_upload_status( media_operation_request( 'status', $id, $source, $relation, $png ) );
media_operation_check( 'lost import result remains completion unknown', 'completion_unknown' === ( $unknown->get_data()['data']['state'] ?? '' ) );
media_operation_check( 'unknown single does not reimport', 409 === $controller->media_upload( media_operation_request( 'single', $id, $source, $relation, $png ) )->get_status() );
$review = media_operation_request( 'reconcile', $id, $source, $relation, $png );
$reviewed = $controller->media_upload_reconcile( $review );
media_operation_check( 'explicit reconciliation verifies the existing import', 200 === $reviewed->get_status() && (int) ( $reviewed->get_data()['attachment_id'] ?? 0 ) > 0 );
$ids = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'fields' => 'ids',
	'posts_per_page' => 5, 'meta_key' => '_wptsall_media_recovery_operation', 'meta_value' => $id ) );
media_operation_check( 'reconciliation and unknown replay import no duplicates', 1 === count( $ids ) );
$other = media_operation_request( 'reconcile', $id, $source, $relation, $png, 'other-device' );
media_operation_check( 'foreign reconciliation is denied', 403 === $controller->media_upload_reconcile( $other )->get_status() );

$attachment = (int) $reviewed->get_data()['attachment_id'];
$directory = trailingslashit( wp_upload_dir()['basedir'] ) . 'wptsall-chunked/' . $id;
$metadata_path = $directory . '/meta.json';
$completed_metadata = file_get_contents( $metadata_path );
$pending_metadata = json_decode( $completed_metadata, true );
$pending_metadata['state'] = 'completion_unknown';
unset( $pending_metadata['completion_response'] );
file_put_contents( $metadata_path, wp_json_encode( $pending_metadata ) );
$proof = get_post_meta( $attachment, '_wptsall_media_recovery_proof', true );
foreach ( array( 'owner_hash', 'source_id', 'relation_id', 'task_id' ) as $field ) {
	$changed_proof = $proof;
	$changed_proof[ $field ] = 'owner_hash' === $field ? str_repeat( '0', 64 ) : (int) $proof[ $field ] + 1;
	update_post_meta( $attachment, '_wptsall_media_recovery_proof', $changed_proof );
	$before = file_get_contents( $metadata_path );
	$refused = $controller->media_upload_reconcile( $review );
	media_operation_check( 'automatic reconciliation refuses changed ' . $field . ' proof',
		409 === $refused->get_status() && $before === file_get_contents( $metadata_path ) );
}
update_post_meta( $attachment, '_wptsall_media_recovery_proof', $proof );
$candidate = media_operation_request( 'reconcile', $id, $source, $relation, $png );
$candidate->set_body( wp_json_encode( array( 'operation_id' => $id, 'attachment_id' => $source ) ) );
media_operation_check( 'an attachment ID alone is not association proof',
	409 === $controller->media_upload_reconcile( $candidate )->get_status() );
$extra = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'Owned ambiguous evidence',
	'post_status' => 'private' ), get_attached_file( $attachment ) );
update_post_meta( $extra, '_wptsall_media_recovery_operation', $id );
media_operation_check( 'ambiguous operation attachments require review',
	409 === $controller->media_upload_reconcile( $review )->get_status() );
delete_post_meta( $extra, '_wptsall_media_recovery_operation' );
file_put_contents( $metadata_path, $completed_metadata );
$retained_directory = $directory . '.owned-backup';
if ( ! rename( $directory, $retained_directory ) ) { throw new \RuntimeException( 'owned directory fault failed' ); }
$lookup = $controller->media_upload_status( media_operation_request( 'status', $id, $source, $relation, $png ) );
media_operation_check( 'lost session directory never proves not-started when attachment evidence exists',
	409 === $lookup->get_status() );
if ( ! rename( $retained_directory, $directory ) ) { throw new \RuntimeException( 'owned directory restore failed' ); }

$damaged_id = wp_generate_uuid4();
$damaged_dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'wptsall-chunked/' . $damaged_id;
wp_mkdir_p( $damaged_dir );
file_put_contents( $damaged_dir . '/meta.json', '{' );
media_operation_check( 'damaged single metadata is not replaced', 409 === $controller->media_upload( media_operation_request( 'single', $damaged_id, $source, $relation, $png ) )->get_status() && '{' === file_get_contents( $damaged_dir . '/meta.json' ) );
media_operation_check( 'damaged init metadata is not replaced', 409 === $controller->media_upload_init( media_operation_request( 'init', $damaged_id, $source, $relation, $png ) )->get_status() );
echo wp_json_encode( array( 'rows' => $media_operation_rows ) );

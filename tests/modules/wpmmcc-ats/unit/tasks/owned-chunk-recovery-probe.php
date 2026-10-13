<?php
// Private owned-WP fault probe. Run through verify-owned-chunk-recovery.py.
class Owned_Chunk_Probe_Controller extends \WPTSALL\Tasks\API\Client_Data_REST_Controller {
	public $imports = 0;
	public $mode = '';
	public $meta_path = '';
	public function media_upload( $request ) {
		++$this->imports;
		if ( 'result_failure' === $this->mode ) {
			rename( $this->meta_path, $this->meta_path . '.owned-backup' );
			mkdir( $this->meta_path );
		}
		if ( 'unknown' === $this->mode ) {
			throw new \RuntimeException( 'owned importer lost response' );
		}
		return new \WP_REST_Response( array( 'success' => true, 'attachment_id' => 321, 'url' => 'owned-result' ), 200 );
	}
}
global $rows;
$rows = array();
function owned_check( $name, $passed ) {
	global $rows;
	$rows[] = array( 'name' => $name, 'passed' => (bool) $passed );
}
function owned_request( $step, $id = '', $device = 'owned-device-a', $body = null ) {
	$r = new \WP_REST_Request( 'status' === $step ? 'GET' : 'POST', '/client/media-upload/' . $step );
	$r->set_header( 'X-WPTSALL-Device-Id', $device );
	$r->set_header( 'X-WPTSALL-Upload-ID', $id );
	$r->set_header( 'X-WPTSALL-Chunk-Index', '0' );
	$r->set_param( 'upload_id', $id );
	if ( 'init' === $step ) {
		$r->set_header( 'Content-Type', 'application/json' );
		$r->set_body( wp_json_encode( array( 'filename' => 'owned.png', 'total_size' => 5, 'chunk_count' => 1, 'content_type' => 'image/png', 'source_id' => 77, 'relation_id' => 33 ) ) );
	} elseif ( 'complete' === $step ) {
		$r->set_header( 'Content-Type', 'application/json' );
		$r->set_body( wp_json_encode( array( 'upload_id' => $id ) ) );
	} else {
		$r->set_body( $body ?? 'owned' );
	}
	return $r;
}
function owned_session( $controller ) {
	$response = $controller->media_upload_init( owned_request( 'init' ) );
	$id = $response->get_data()['upload_id'];
	$controller->meta_path = trailingslashit( wp_upload_dir()['basedir'] ) . 'wptsall-chunked/' . $id . '/meta.json';
	return $id;
}
$c = new Owned_Chunk_Probe_Controller();
$id = owned_session( $c );
$missing = $c->media_upload_init( owned_request( 'init', '', '' ) );
owned_check( 'missing device cannot create session', 403 === $missing->get_status() );
$before = file_get_contents( $c->meta_path );
foreach ( array( 'chunk', 'status', 'complete' ) as $step ) {
	$response = $c->{'media_upload_' . $step}( owned_request( $step, $id, 'owned-device-b' ) );
	owned_check( 'other device cannot ' . $step, 403 === $response->get_status() );
}
owned_check( 'foreign attempts leave metadata intact', $before === file_get_contents( $c->meta_path ) );
owned_check( 'foreign complete invokes no importer', 0 === $c->imports );

$c = new Owned_Chunk_Probe_Controller();
$id = owned_session( $c );
$c->media_upload_chunk( owned_request( 'chunk', $id ) );
$first = $c->media_upload_complete( owned_request( 'complete', $id ) );
$second = $c->media_upload_complete( owned_request( 'complete', $id ) );
owned_check( 'successful complete replays same result', 200 === $second->get_status() && $first->get_data() === $second->get_data() );
owned_check( 'successful complete imports exactly once', 1 === $c->imports );
$status = $c->media_upload_status( owned_request( 'status', $id ) );
owned_check( 'status retains confirmed completed receipt', 321 === ( $status->get_data()['data']['attachment_id'] ?? 0 ) );
owned_check( 'completion retains original chunks for manual cleanup', is_file( dirname( $c->meta_path ) . '/chunk-0.bin' ) );
owned_check( 'completed session rejects chunk mutation', 409 === $c->media_upload_chunk( owned_request( 'chunk', $id, 'owned-device-a', 'other' ) )->get_status() );
update_option( 'owned_chunk_recovery_resume_case', array( 'upload_id' => $id, 'response' => $first->get_data() ) );

$c = new Owned_Chunk_Probe_Controller();
$id = owned_session( $c );
$c->media_upload_chunk( owned_request( 'chunk', $id ) );
$c->mode = 'unknown';
try { $c->media_upload_complete( owned_request( 'complete', $id ) ); } catch ( \Throwable $e ) {}
$c->mode = '';
$response = $c->media_upload_complete( owned_request( 'complete', $id ) );
owned_check( 'unknown completion cannot import again', 409 === $response->get_status() && 1 === $c->imports );

$c = new Owned_Chunk_Probe_Controller();
$id = owned_session( $c );
$c->media_upload_chunk( owned_request( 'chunk', $id ) );
$c->mode = 'result_failure';
$response = $c->media_upload_complete( owned_request( 'complete', $id ) );
owned_check( 'receipt write refusal is not success', 500 === $response->get_status() );
if ( is_dir( $c->meta_path ) && is_file( $c->meta_path . '.owned-backup' ) ) {
	rmdir( $c->meta_path );
	rename( $c->meta_path . '.owned-backup', $c->meta_path );
}
$c->mode = '';
$response = $c->media_upload_complete( owned_request( 'complete', $id ) );
owned_check( 'restored unknown marker never repeats importer', 409 === $response->get_status() && 1 === $c->imports );

$c = new Owned_Chunk_Probe_Controller();
$id = owned_session( $c );
$chunk = owned_request( 'chunk', $id );
$chunk->set_header( 'X-WPTSALL-Chunk-Index', '-1' );
owned_check( 'negative index is not coerced to zero', 400 === $c->media_upload_chunk( $chunk )->get_status() );
$saved = json_decode( file_get_contents( $c->meta_path ), true );
unset( $saved['owner_hash'] );
file_put_contents( $c->meta_path, wp_json_encode( $saved ) );
owned_check( 'legacy ownerless session is not adopted', 403 === $c->media_upload_status( owned_request( 'status', $id ) )->get_status() );

$c = new Owned_Chunk_Probe_Controller();
$id = owned_session( $c );
$c->media_upload_chunk( owned_request( 'chunk', $id ) );
$lock = fopen( dirname( $c->meta_path ) . '/session.lock', 'c' );
flock( $lock, LOCK_EX );
owned_check( 'held session lock stops complete before importer', 409 === $c->media_upload_complete( owned_request( 'complete', $id ) )->get_status() && 0 === $c->imports );
owned_check( 'held session lock stops chunk mutation', 409 === $c->media_upload_chunk( owned_request( 'chunk', $id ) )->get_status() );
flock( $lock, LOCK_UN );
fclose( $lock );

$c = new Owned_Chunk_Probe_Controller();
$id = owned_session( $c );
$c->media_upload_chunk( owned_request( 'chunk', $id ) );
$alias = $id . '-alias';
$unknown = $c->media_upload_status( owned_request( 'status', $alias ) );
owned_check( 'invalid ID does not create a lookup directory', 404 === $unknown->get_status() && ! is_dir( dirname( dirname( $c->meta_path ) ) . '/' . $alias ) );

$c = new Owned_Chunk_Probe_Controller();
$id = owned_session( $c );
$c->media_upload_chunk( owned_request( 'chunk', $id ) );
$before = file_get_contents( $c->meta_path );
$GLOBALS['owned_chunk_refuse_meta_rename'] = true;
$intent = $c->media_upload_complete( owned_request( 'complete', $id ) );
$GLOBALS['owned_chunk_refuse_meta_rename'] = false;
owned_check( 'unknown marker write refusal stops before importer', 500 === $intent->get_status() && 0 === $c->imports );
owned_check( 'failed atomic write preserves original metadata', $before === file_get_contents( $c->meta_path ) );
owned_check( 'failed atomic write removes only its temporary file', array() === glob( $c->meta_path . '.*.tmp' ) );

$c = new Owned_Chunk_Probe_Controller();
$id = owned_session( $c );
$GLOBALS['owned_chunk_refuse_meta_rename'] = true;
$chunk = $c->media_upload_chunk( owned_request( 'chunk', $id ) );
$GLOBALS['owned_chunk_refuse_meta_rename'] = false;
owned_check( 'chunk metadata write refusal is not success', 500 === $chunk->get_status() );

$c = new Owned_Chunk_Probe_Controller();
$id = owned_session( $c );
$c->media_upload_chunk( owned_request( 'chunk', $id ) );
$GLOBALS['owned_chunk_refuse_meta_chmod'] = true;
$permission = $c->media_upload_complete( owned_request( 'complete', $id ) );
$GLOBALS['owned_chunk_refuse_meta_chmod'] = false;
owned_check( 'private metadata mode refusal stops before importer', 500 === $permission->get_status() && 0 === $c->imports );
owned_check( 'private mode refusal removes its temporary file', array() === glob( $c->meta_path . '.*.tmp' ) );
echo wp_json_encode( array( 'rows' => $rows, 'passed' => count( array_filter( $rows, function ( $row ) { return $row['passed']; } ) ), 'failed' => count( array_filter( $rows, function ( $row ) { return ! $row['passed']; } ) ) ) );

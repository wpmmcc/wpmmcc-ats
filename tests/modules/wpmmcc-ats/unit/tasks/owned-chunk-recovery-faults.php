<?php
// Test-only fault injection, loaded exclusively into a verified owned WP process.
namespace WPTSALL\Tasks\API;

function rename( $from, $to ) {
	if ( ! empty( $GLOBALS['owned_chunk_refuse_meta_rename'] ) && 'meta.json' === basename( $to ) ) {
		return false;
	}
	return \rename( $from, $to );
}

function chmod( $path, $mode ) {
	if ( ! empty( $GLOBALS['owned_chunk_refuse_meta_chmod'] ) && 0 === strpos( basename( $path ), 'meta.json.' ) ) {
		return false;
	}
	return \chmod( $path, $mode );
}

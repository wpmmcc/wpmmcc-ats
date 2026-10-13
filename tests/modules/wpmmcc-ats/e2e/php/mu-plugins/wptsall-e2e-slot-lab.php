<?php
/**
 * Isolated matrix slot WordPress: lab-only settings the products refuse by default.
 *
 * 1. Allow Protocol v2 client REST over plain HTTP.
 *    Slot containers use http://127.0.0.1:91xx without TLS; without this
 *    mu-plugin, /client/* routes return transport_encryption_required (400).
 *
 * 2. Allow WPMMCC peers on a private network.
 *    The sites of this lab reach each other by container name over a private
 *    Docker network, an address range WPMMCC refuses as a peer unless the
 *    wpmmcc_allow_private_peers option is on (WMC-09, server-side request
 *    forgery). The option is answered here, not stored, so the lab keeps
 *    running the real policy: link-local addresses (169.254.0.0/16, where cloud
 *    metadata services answer) stay refused.
 */
add_filter( 'wptsall_client_transport_encryption_policy', static function () {
	return 'off';
}, 1 );
add_filter( 'wptsall_client_transport_encryption_required', '__return_false', 1 );
add_filter( 'pre_option_wpmmcc_allow_private_peers', '__return_true' );

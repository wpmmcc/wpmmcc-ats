<?php
/**
 * E2E v2 URL audit for translation rules.
 *
 * This script inspects each active translation rule and verifies:
 * - a sample object/term can be resolved
 * - canonical URL (rule url_pattern) response code
 * - pretty URL (get_permalink/get_term_link) response code
 *
 * It classifies failures into configuration-level categories to avoid
 * masking real problems with hardcoded routing changes.
 *
 * Run:
 *   cd ${WPTSALL_WP_ROOT:-/var/www/html} && wp eval-file /home/john/projects/wptsall/tests/modules/wpmmcc-ats/e2e/php/audit-rule-urls.php
 */

require_once __DIR__ . '/helpers.php';

if ( ! defined( 'ABSPATH' ) ) {
	echo "Must be run via wp eval-file\n";
	exit( 1 );
}

global $wpdb;

/**
 * Build absolute URL from rule pattern.
 */
function e2e_audit_build_url( string $pattern, array $vars ): string {
	$replacements = array(
		'{id}'   => (string) ( $vars['id'] ?? '' ),
		'{term}' => (string) ( $vars['term'] ?? '' ),
	);
	$resolved     = str_replace( array_keys( $replacements ), array_values( $replacements ), $pattern );

	if ( preg_match( '#^https?://#i', $resolved ) ) {
		return $resolved;
	}

	$resolved = ltrim( $resolved, '/' );
	return home_url( '/' . $resolved );
}

/**
 * Lightweight HTTP status checker.
 *
 * @return array{code:int,error:string,location:string}
 */
function e2e_audit_http_status_via_curl( string $url ): int {
	$cmd = 'curl -sS -L -o /dev/null -w "%{http_code}" --max-time 20 ' . escapeshellarg( $url ) . ' 2>/dev/null';
	$out = shell_exec( $cmd );
	if ( ! is_string( $out ) ) {
		return 0;
	}

	$code = (int) trim( $out );
	return $code > 0 ? $code : 0;
}

/**
 * Lightweight HTTP status checker.
 *
 * @return array{code:int,error:string,location:string}
 */
function e2e_audit_http_status( string $url ): array {
	$args = array(
		'timeout'     => 15,
		'redirection' => 5,
		'sslverify'   => false,
		'user-agent'  => 'WPTSALL-E2E-URL-Audit/1.0',
		'method'      => 'GET',
	);

	$resp = wp_remote_get( $url, $args );

	if ( is_wp_error( $resp ) ) {
		return array(
			'code'     => 0,
			'error'    => $resp->get_error_message(),
			'location' => '',
		);
	}

	$headers = wp_remote_retrieve_headers( $resp );
	$code    = (int) wp_remote_retrieve_response_code( $resp );
	if ( $code <= 0 || $code >= 500 ) {
		$curl_code = e2e_audit_http_status_via_curl( $url );
		if ( $curl_code > 0 ) {
			$code = $curl_code;
		}
	}

	return array(
		'code'     => $code,
		'error'    => '',
		'location' => (string) ( $headers['location'] ?? '' ),
	);
}

/**
 * True for 2xx and 3xx.
 */
function e2e_audit_is_ok_code( int $code ): bool {
	return $code >= 200 && $code < 400;
}

echo "=== E2E v2: Audit Rule URLs ===\n\n";

$rules_table = e2e_table( 'translation_rules' );
$models_table = e2e_table( 'models' );
if ( ! e2e_table_exists( $rules_table ) ) {
	echo "ERROR: table not found: {$rules_table}\n";
	exit( 1 );
}

$project_plugins = array();
foreach ( e2e_model_binding_plugin_slugs() as $slug ) {
	$slug = sanitize_key( (string) $slug );
	if ( '' !== $slug ) {
		$project_plugins[] = $slug;
	}
}
$project_plugins = array_values( array_unique( $project_plugins ) );

$rules = array();
if ( ! empty( $project_plugins ) && e2e_table_exists( $models_table ) ) {
	$placeholders = implode( ',', array_fill( 0, count( $project_plugins ), '%s' ) );
	$sql          = "SELECT r.id, r.object_name, r.data_type, r.url_type, r.url_pattern, r.requires_login, r.is_active
		FROM {$rules_table} r
		INNER JOIN {$models_table} m ON m.id = r.model_id
		WHERE r.is_active = 1
		  AND m.plugin_slug IN ($placeholders)
		ORDER BY r.id ASC";
	$prepared     = $wpdb->prepare( $sql, ...$project_plugins );
	$rules        = $wpdb->get_results( $prepared, ARRAY_A );
} else {
	$rules = $wpdb->get_results(
		"SELECT id, object_name, data_type, url_type, url_pattern, requires_login, is_active
		 FROM {$rules_table}
		 WHERE is_active = 1
		 ORDER BY id ASC",
		ARRAY_A
	);
}

$known_gated_posts = array(
	'lp_lesson',
	'lp_quiz',
	'lp_question',
	'lesson',
	'envira',
	'tutor_enrolled',
);

$report = array(
	'timestamp' => gmdate( 'c' ),
	'home_url'  => home_url( '/' ),
	'project'   => e2e_project(),
	'scope'     => array(
		'project_plugins' => $project_plugins,
		'rules_count'     => is_array( $rules ) ? count( $rules ) : 0,
	),
	'summary'   => array(
		'total_rules'               => 0,
		'public_rules'              => 0,
		'gated_rules'               => 0,
		'public_ok'                 => 0,
		'public_problem'            => 0,
		'gated_expected'            => 0,
		'gated_but_public'          => 0,
		'no_sample'                 => 0,
		'object_missing'            => 0,
	),
	'rules'    => array(),
);

foreach ( (array) $rules as $rule ) {
	$rule_id        = (int) ( $rule['id'] ?? 0 );
	$data_type      = sanitize_key( (string) ( $rule['data_type'] ?? '' ) );
	$object_name    = (string) ( $rule['object_name'] ?? '' );
	$url_pattern    = (string) ( $rule['url_pattern'] ?? '' );
	$requires_login = (int) ( $rule['requires_login'] ?? 0 );

	$entry = array(
		'id'             => $rule_id,
		'object_name'    => $object_name,
		'data_type'      => $data_type,
		'url_pattern'    => $url_pattern,
		'requires_login' => $requires_login,
		'sample'         => array(),
		'urls'           => array(
			'canonical' => '',
			'pretty'    => '',
		),
		'http'           => array(
			'canonical' => array( 'code' => 0, 'error' => '', 'location' => '' ),
			'pretty'    => array( 'code' => 0, 'error' => '', 'location' => '' ),
		),
		'object_flags'   => array(),
		'classification' => '',
		'note'           => '',
	);

	$sample_ready = false;
	$sample_vars  = array(
		'id'   => 0,
		'term' => '',
	);

	if ( 'post' === $data_type ) {
		if ( ! post_type_exists( $object_name ) ) {
			$entry['classification'] = 'object_missing';
			$entry['note']           = 'post_type not registered';
			$report['summary']['object_missing']++;
			$report['summary']['no_sample']++;
		} else {
			$post_type = get_post_type_object( $object_name );
			if ( $post_type ) {
				$rewrite = $post_type->rewrite;
				$slug    = is_array( $rewrite ) ? (string) ( $rewrite['slug'] ?? '' ) : '';

				$entry['object_flags'] = array(
					'public'           => (int) ( $post_type->public ?? 0 ),
					'publicly_queryable' => (int) ( $post_type->publicly_queryable ?? 0 ),
					'has_archive'      => (string) (
						is_string( $post_type->has_archive )
							? $post_type->has_archive
							: ( $post_type->has_archive ? '1' : '0' )
					),
					'rewrite_slug'     => $slug,
				);
			}

			$sample_post_ids = get_posts(
				array(
					'post_type'      => $object_name,
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'orderby'        => 'ID',
					'order'          => 'DESC',
					'fields'         => 'ids',
				)
			);

			$sample_post_id = ! empty( $sample_post_ids ) ? (int) $sample_post_ids[0] : 0;
			if ( $sample_post_id <= 0 ) {
				$entry['classification'] = 'no_sample';
				$entry['note']           = 'no published post for this post_type';
				$report['summary']['no_sample']++;
			} else {
				$sample_vars['id'] = $sample_post_id;
				$entry['sample']   = array(
					'post_id' => $sample_post_id,
				);
				$entry['urls']['pretty'] = (string) get_permalink( $sample_post_id );
				$sample_ready            = true;
			}
		}
	} elseif ( 'term' === $data_type ) {
		if ( ! taxonomy_exists( $object_name ) ) {
			$entry['classification'] = 'object_missing';
			$entry['note']           = 'taxonomy not registered';
			$report['summary']['object_missing']++;
			$report['summary']['no_sample']++;
		} else {
			$taxonomy = get_taxonomy( $object_name );
			if ( $taxonomy ) {
				$rewrite = $taxonomy->rewrite;
				$slug    = is_array( $rewrite ) ? (string) ( $rewrite['slug'] ?? '' ) : '';

				$entry['object_flags'] = array(
					'public'           => (int) ( $taxonomy->public ?? 0 ),
					'publicly_queryable' => (int) ( $taxonomy->publicly_queryable ?? 0 ),
					'rewrite_slug'     => $slug,
				);
			}

			$terms = get_terms(
				array(
					'taxonomy'   => $object_name,
					'hide_empty' => false,
					'number'     => 20,
					'orderby'    => 'count',
					'order'      => 'DESC',
				)
			);

			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				$entry['classification'] = 'no_sample';
				$entry['note']           = 'no term in taxonomy';
				$report['summary']['no_sample']++;
			} else {
				$term = $terms[0];
				foreach ( $terms as $candidate ) {
					if ( (int) ( $candidate->count ?? 0 ) > 0 ) {
						$term = $candidate;
						break;
					}
				}
				$sample_vars['term']  = (string) $term->slug;
				$entry['sample']      = array(
					'term_id' => (int) $term->term_id,
					'slug'    => (string) $term->slug,
					'count'   => (int) ( $term->count ?? 0 ),
				);
				$pretty               = get_term_link( $term, $object_name );
				$entry['urls']['pretty'] = is_wp_error( $pretty ) ? '' : (string) $pretty;
				$sample_ready            = true;
			}
		}
	} else {
		$entry['classification'] = 'unsupported';
		$entry['note']           = 'unsupported data_type';
	}

	if ( $sample_ready ) {
		$entry['urls']['canonical'] = e2e_audit_build_url( $url_pattern, $sample_vars );
		if ( '' !== $entry['urls']['canonical'] ) {
			$entry['http']['canonical'] = e2e_audit_http_status( $entry['urls']['canonical'] );
		}
		if ( '' !== $entry['urls']['pretty'] ) {
			$entry['http']['pretty'] = e2e_audit_http_status( $entry['urls']['pretty'] );
		}
	}

	$canonical_code   = (int) ( $entry['http']['canonical']['code'] ?? 0 );
	$pretty_code      = (int) ( $entry['http']['pretty']['code'] ?? 0 );
	$canonical_ok     = e2e_audit_is_ok_code( $canonical_code );
	$pretty_ok        = e2e_audit_is_ok_code( $pretty_code );
	$has_pretty_url   = '' !== (string) ( $entry['urls']['pretty'] ?? '' );
	$front_access_ok  = $has_pretty_url ? $pretty_ok : $canonical_ok;
	$either_access_ok = $canonical_ok || $pretty_ok;

	if ( '' === $entry['classification'] ) {
		if ( 1 === $requires_login ) {
			if ( $front_access_ok ) {
				$entry['classification'] = 'gated_but_public';
				$entry['note']           = 'rule marked gated but anonymous request succeeded';
			} else {
				$entry['classification'] = 'expected_gated';
				$entry['note']           = 'anonymous access blocked';
			}
		} else {
			if ( $front_access_ok ) {
				if ( ! $canonical_ok ) {
					$entry['classification'] = 'public_ok_pretty_only';
					$entry['note']           = 'pretty permalink reachable, canonical url_pattern failed';
				} else {
					$entry['classification'] = 'public_ok';
					$entry['note']           = 'public URL reachable';
				}
			} else {
				if ( in_array( $object_name, $known_gated_posts, true ) ) {
					$entry['classification'] = 'likely_gated_needs_rule_flag';
					$entry['note']           = 'behavior looks gated but requires_login=0';
				} elseif ( $either_access_ok && $has_pretty_url && ! $pretty_ok ) {
					$entry['classification'] = 'pretty_rewrite_404';
					$entry['note']           = 'canonical query works, pretty permalink fails';
				} else {
					$entry['classification'] = 'both_404_public';
					$entry['note']           = 'both canonical and pretty URLs failed';
				}
			}
		}
	}

	$report['summary']['total_rules']++;
	if ( 1 === $requires_login ) {
		$report['summary']['gated_rules']++;
		if ( 'expected_gated' === $entry['classification'] ) {
			$report['summary']['gated_expected']++;
		}
		if ( 'gated_but_public' === $entry['classification'] ) {
			$report['summary']['gated_but_public']++;
		}
	} else {
		$report['summary']['public_rules']++;
		if ( 'public_ok' === $entry['classification'] || 'public_ok_pretty_only' === $entry['classification'] ) {
			$report['summary']['public_ok']++;
		} elseif ( 'no_sample' !== $entry['classification'] && 'object_missing' !== $entry['classification'] ) {
			$report['summary']['public_problem']++;
		}
	}

	$report['rules'][] = $entry;
}

$runtime_dir = e2e_runtime_dir();
if ( ! is_dir( $runtime_dir ) ) {
	wp_mkdir_p( $runtime_dir );
}

$runtime_file = $runtime_dir . '/url-audit.json';
file_put_contents( $runtime_file, wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n" );

echo 'project: ' . (string) $report['project'] . "\n";
echo 'scope plugins: ' . implode( ',', (array) ( $report['scope']['project_plugins'] ?? array() ) ) . "\n";
echo 'scoped rules: ' . (int) ( $report['scope']['rules_count'] ?? 0 ) . "\n";
echo "report file: {$runtime_file}\n\n";
echo wp_json_encode( $report['summary'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n";

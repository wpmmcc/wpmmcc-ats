<?php
/**
 * WP-CLI Translation Identity Command
 *
 * Heal / scan divergence between post_mappings and `_wptsall_*` meta.
 *
 * ## EXAMPLES
 *
 *     # Dry-run scan (default)
 *     $ wp wptsall identity scan --limit=100
 *
 *     # Apply heal for up to 200 diverged rows
 *     $ wp wptsall identity heal --yes --limit=200
 *
 * @package WPTSALL\CLI
 * @since 2.3.0
 */

namespace WPTSALL\CLI;

use WPTSALL\Sites\Services\Translation_Identity;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Identity_Command class.
 */
class Identity_Command extends \WP_CLI_Command {

	/**
	 * Scan mapping↔meta divergences (dry-run heal report).
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Max mapping rows to inspect.
	 * ---
	 * default: 200
	 * ---
	 *
	 * [--offset=<n>]
	 * : Offset into post_mappings.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--relation_id=<id>]
	 * : Only this site relation.
	 *
	 * [--format=<format>]
	 * : table|json
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args       Positional.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function scan( $args, $assoc_args ) {
		$report = Translation_Identity::heal_batch(
			array(
				'dry_run'     => true,
				'limit'       => (int) ( $assoc_args['limit'] ?? 200 ),
				'offset'      => (int) ( $assoc_args['offset'] ?? 0 ),
				'relation_id' => (int) ( $assoc_args['relation_id'] ?? 0 ),
				'sample_max'  => 50,
			)
		);
		$this->print_report( $report, $assoc_args['format'] ?? 'table' );
	}

	/**
	 * Heal diverged mapping↔meta pairs.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Required to apply writes (otherwise dry-run).
	 *
	 * [--limit=<n>]
	 * : Max mapping rows to inspect.
	 * ---
	 * default: 200
	 * ---
	 *
	 * [--offset=<n>]
	 * : Offset into post_mappings.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--relation_id=<id>]
	 * : Only this site relation.
	 *
	 * [--format=<format>]
	 * : table|json
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args       Positional.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function heal( $args, $assoc_args ) {
		$apply = \WP_CLI\Utils\get_flag_value( $assoc_args, 'yes', false );
		if ( ! $apply ) {
			\WP_CLI::warning( 'Dry-run only. Re-run with --yes to write markers/mappings.' );
		}

		$report = Translation_Identity::heal_batch(
			array(
				'dry_run'     => ! $apply,
				'limit'       => (int) ( $assoc_args['limit'] ?? 200 ),
				'offset'      => (int) ( $assoc_args['offset'] ?? 0 ),
				'relation_id' => (int) ( $assoc_args['relation_id'] ?? 0 ),
				'sample_max'  => 50,
			)
		);
		$this->print_report( $report, $assoc_args['format'] ?? 'table' );

		if ( $apply && (int) $report['failed'] > 0 ) {
			\WP_CLI::warning( sprintf( '%d heal attempt(s) failed.', (int) $report['failed'] ) );
		} elseif ( $apply ) {
			\WP_CLI::success( sprintf( 'Healed %d diverged row(s).', (int) $report['healed'] ) );
		}
	}

	/**
	 * @param array  $report Report.
	 * @param string $format table|json.
	 * @return void
	 */
	private function print_report( array $report, string $format ): void {
		if ( 'json' === $format ) {
			\WP_CLI::line( wp_json_encode( $report ) );
			return;
		}

		$summary = array(
			array( 'metric' => 'scanned', 'value' => (int) $report['scanned'] ),
			array( 'metric' => 'ok', 'value' => (int) $report['ok'] ),
			array( 'metric' => 'diverged', 'value' => (int) $report['diverged'] ),
			array( 'metric' => 'missing_target', 'value' => (int) $report['missing_target'] ),
			array( 'metric' => 'skipped_conflict', 'value' => (int) ( $report['skipped_conflict'] ?? 0 ) ),
			array( 'metric' => 'healed', 'value' => (int) $report['healed'] ),
			array( 'metric' => 'failed', 'value' => (int) $report['failed'] ),
			array( 'metric' => 'dry_run', 'value' => ! empty( $report['dry_run'] ) ? 1 : 0 ),
		);
		\WP_CLI\Utils\format_items( 'table', $summary, array( 'metric', 'value' ) );

		$samples = (array) ( $report['samples'] ?? array() );
		if ( ! empty( $samples ) ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( 'Samples:' );
			$rows = array();
			foreach ( $samples as $s ) {
				$rows[] = array(
					'mapping_id'     => (int) ( $s['mapping_id'] ?? 0 ),
					'source_post_id' => (int) ( $s['source_post_id'] ?? 0 ),
					'target_post_id' => (int) ( $s['target_post_id'] ?? 0 ),
					'relation_id'    => (int) ( $s['relation_id'] ?? 0 ),
					'reason'         => (string) ( $s['reason'] ?? '' ),
				);
			}
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'mapping_id', 'source_post_id', 'target_post_id', 'relation_id', 'reason' ) );
		}
	}
}

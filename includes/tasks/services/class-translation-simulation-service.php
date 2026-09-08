<?php
/**
 * Translation Simulation Service
 *
 * Implements marker-based translation simulation.
 *
 * @package WPTSALL\Tasks\Services
 * @since 0.6.0
 */

namespace WPTSALL\Tasks\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translation_Simulation_Service class
 *
 * Handles adding translation markers to content and strings.
 */
class Translation_Simulation_Service {

	/**
	 * Check if translation simulation is enabled.
	 *
	 * Production default is disabled. Enable explicitly only for local debug:
	 * - define( 'WPTSALL_ENABLE_TRANSLATION_SIMULATION', true );
	 * - update_option( 'wptsall_enable_translation_simulation', 1 );
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$enabled = false;

		if ( defined( 'WPTSALL_ENABLE_TRANSLATION_SIMULATION' ) ) {
			$enabled = (bool) WPTSALL_ENABLE_TRANSLATION_SIMULATION;
		} else {
			$enabled = (bool) get_option( 'wptsall_enable_translation_simulation', false );
		}

		/**
		 * Filter simulation toggle for test/debug scenarios.
		 *
		 * @param bool $enabled Current simulation enabled flag.
		 */
		return (bool) apply_filters( 'wptsall_enable_translation_simulation', $enabled );
	}

	/**
	 * Apply translation markers to content
	 *
	 * @param string $content Original content.
	 * @param string $lang    Target language code.
	 * @return string Content with markers.
	 */
	public static function apply_markers( $content, $lang ) {
		if ( empty( $content ) || ! is_string( $content ) ) {
			wptsall_log_debug(
				'tasks-translation',
				'apply_markers skipped: empty or non-string content',
				array( 'lang' => $lang )
			);
			return $content;
		}

		if ( ! self::is_enabled() ) {
			return $content;
		}

		// Don't double-wrap if already marked
		$pattern = '/^【' . preg_quote( $lang, '/' ) . '】.*【\/' . preg_quote( $lang, '/' ) . '】$/s';
		if ( preg_match( $pattern, $content ) ) {
			wptsall_log_debug(
				'tasks-translation',
				'apply_markers skipped: already marked',
				array( 'lang' => $lang )
			);
			return $content;
		}

		wptsall_log_debug(
			'tasks-translation',
			'apply_markers applied',
			array(
				'lang'           => $lang,
				'content_length' => strlen( $content ),
			)
		);

		return '【' . $lang . '】' . $content . '【/' . $lang . '】';
	}

	/**
	 * Translate a single string (simulation)
	 *
	 * @param string $text Source text.
	 * @param string $lang Target language.
	 * @return string Simulated translation.
	 */
	public static function translate_string( $text, $lang ) {
		return self::apply_markers( $text, $lang );
	}

	/**
	 * Process and save simulated translations for a model's template
	 *
	 * @param int    $template_id Template ID.
	 * @param string $target_lang Target language code.
	 * @return array Stats of translated items.
	 */
	public static function simulate_template_translation( $template_id, $target_lang ) {
		if ( ! self::is_enabled() ) {
			wptsall_log_info(
				'tasks-translation',
				'simulate_template_translation skipped: simulation disabled',
				array(
					'template_id' => $template_id,
					'target_lang' => $target_lang,
				)
			);
			return array(
				'translated' => 0,
				'skipped'    => true,
				'reason'     => 'simulation_disabled',
			);
		}

		global $wpdb;
		$entries_table = wptsall_table( 'template_entries' );
		$templates_table = wptsall_table( 'templates' );

		wptsall_log_info(
			'tasks-translation',
			'simulate_template_translation started',
			array(
				'template_id' => $template_id,
				'target_lang' => $target_lang,
			)
		);

		// Get pending entries for this template
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$entries = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, msgid, msgid_plural FROM %i WHERE template_id = %d AND status = 'pending'",
				$entries_table,
				$template_id
			),
			ARRAY_A
		);

		wptsall_log_debug(
			'tasks-translation',
			'Found pending entries',
			array(
				'template_id'   => $template_id,
				'pending_count' => count( $entries ),
			)
		);

		$count = 0;
		foreach ( $entries as $entry ) {
			$msgstr        = self::translate_string( $entry['msgid'], $target_lang );
			$msgstr_plural = ! empty( $entry['msgid_plural'] ) ? self::translate_string( $entry['msgid_plural'], $target_lang ) : null;

			// Update entry
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->update(
				$entries_table,
				array(
					'msgstr'        => $msgstr,
					'msgstr_plural' => $msgstr_plural,
					'status'        => 'translated',
					'source'        => 'auto',
					'updated_at'    => current_time( 'mysql' ),
				),
				array( 'id' => $entry['id'] ),
				array( '%s', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);

			if ( false === $result ) {
				wptsall_log_error(
					'tasks-translation',
					'Failed to update entry',
					array(
						'entry_id' => $entry['id'],
						'error'    => $wpdb->last_error,
					)
				);
			}
			$count++;
		}

		// Update template stats
		if ( $count > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE %i
					 SET translated_entries = (SELECT COUNT(*) FROM %i WHERE template_id = %d AND status IN ('translated', 'reviewed')),
					     updated_at = %s
					 WHERE id = %d",
					$templates_table,
					$entries_table,
					$template_id,
					current_time( 'mysql' ),
					$template_id
				)
			);

			// Trigger event to clear hook cache
			do_action( 'wptsall_translation_completed', $template_id );
		}

		wptsall_log_info(
			'tasks-translation',
			'simulate_template_translation completed',
			array(
				'template_id' => $template_id,
				'translated'  => $count,
			)
		);

		return array(
			'translated' => $count,
		);
	}
}

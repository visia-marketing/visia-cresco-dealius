<?php
/**
 * WP-CLI command: `wp cresco import`.
 *
 * Intended entry point for a real server cron, e.g.:
 *   wp cresco import --path=/var/www/site
 */

namespace Cresco\Dealius;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class CLI {

	/** @var Importer */
	private $importer;

	public function __construct( Importer $importer ) {
		$this->importer = $importer;
	}

	/**
	 * Import active listings from Dealius into `dealius_property` posts.
	 *
	 * ## OPTIONS
	 *
	 * [--pages=<n>]
	 * : Maximum number of listing pages to pull (default: all).
	 *
	 * [--page-size=<n>]
	 * : Listings per page (max 50, default 50).
	 *
	 * [--property=<id>]
	 * : Restrict to a single Dealius MainPropertyID (skips retirement). Useful for testing.
	 *
	 * [--dry-run]
	 * : Compute and log everything but write nothing to the database.
	 *
	 * [--reset-auth]
	 * : Clear the auth cooldown before running (use after unlocking the Dealius
	 *   account or changing the password).
	 *
	 * ## EXAMPLES
	 *
	 *     wp cresco import --dry-run --pages=1
	 *     wp cresco import --reset-auth --dry-run --pages=1
	 *     wp cresco import --property=16022
	 *     wp cresco import
	 *
	 * @when after_wp_load
	 */
	public function import( $args, $assoc_args ) {
		if ( isset( $assoc_args['reset-auth'] ) ) {
			$this->importer->clear_auth_block();
			\WP_CLI::log( 'Auth cooldown cleared.' );
		}

		$run_args = array(
			'pages'     => isset( $assoc_args['pages'] ) ? (int) $assoc_args['pages'] : 0,
			'page_size' => isset( $assoc_args['page-size'] ) ? (int) $assoc_args['page-size'] : 50,
			'property'  => isset( $assoc_args['property'] ) ? (string) $assoc_args['property'] : '',
			'dry_run'   => isset( $assoc_args['dry-run'] ),
		);

		\WP_CLI::log( 'Starting Dealius import' . ( $run_args['dry_run'] ? ' (dry run)' : '' ) . '…' );

		$summary = $this->importer->run( $run_args );

		if ( ! empty( $summary['locked'] ) ) {
			\WP_CLI::warning( 'Another import is already running. Aborted.' );
			return;
		}

		if ( ! empty( $summary['error'] ) ) {
			\WP_CLI::error( $summary['error'] ); // exits non-zero
			return;
		}

		if ( ! empty( $summary['note'] ) ) {
			\WP_CLI::warning( $summary['note'] );
		}

		\WP_CLI::success(
			sprintf(
				'Done. Listings: %d, properties written: %d, deleted: %d, duration: %ds.',
				(int) ( $summary['number_of_listings_imported'] ?? 0 ),
				(int) ( $summary['number_of_properties_written'] ?? 0 ),
				(int) ( $summary['number_of_properties_deleted'] ?? 0 ),
				(int) ( $summary['time_duration'] ?? 0 )
			)
		);
	}
}

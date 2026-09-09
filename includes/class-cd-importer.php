<?php
/**
 * Import orchestration.
 *
 * Flow: auth → resolve active status → pull active listings paginated → GROUP by
 * MainPropertyID (the Level-1 property = the building) → per group write one
 * `dealius_property` post → retire properties no longer in the feed → bust caches.
 *
 * Idempotent: a property is matched on its MainPropertyID meta and updated in
 * place; the units + broker repeaters are rebuilt in full each run.
 */

namespace Cresco\Dealius;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Importer {

	const LOCK_OPTION = 'cd_dealius_is_running';
	const LOCK_TTL    = 3 * HOUR_IN_SECONDS;

	/** @var Api_Client */
	private $api;
	/** @var Lookups */
	private $lookups;
	/** @var Repository */
	private $repository;
	/** @var Logger */
	private $logger;
	/** @var Property_Writer */
	private $writer;

	public function __construct( Api_Client $api, Lookups $lookups, Repository $repository, Logger $logger ) {
		$this->api        = $api;
		$this->lookups    = $lookups;
		$this->repository = $repository;
		$this->logger     = $logger;
		$this->writer     = new Property_Writer( $api, $lookups, new Rollups(), $repository, $logger );
	}

	/**
	 * @param array $args {
	 *     @type int    $pages    max pages to pull (default: all)
	 *     @type bool   $dry_run  compute only, write nothing
	 *     @type string $property restrict to a single MainPropertyID (skips retirement)
	 *     @type int    $page_size
	 * }
	 * @return array run summary
	 */
	public function run( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'pages'     => 0,        // 0 = all pages
				'dry_run'   => false,
				'property'  => '',
				'page_size' => 50,
			)
		);

		if ( $this->is_locked() ) {
			$this->logger->log( 'Import already running — exiting.' );
			return array( 'locked' => true );
		}
		$this->lock();

		$started   = time();
		$page_size = min( 50, max( 1, (int) $args['page_size'] ) );

		// Get a token (reusing a still-valid cached one — no needless login). If auth
		// fails, stop with a clear reason rather than silently reporting 0 listings.
		$token = $this->api->get_token();
		if ( ! $token ) {
			$this->unlock();
			$detail = $this->api->get_last_auth_error();
			$summary = array(
				'number_of_listings_imported'  => 0,
				'number_of_properties_written' => 0,
				'number_of_properties_deleted' => 0,
				'dry_run'                       => (bool) $args['dry_run'],
				'time_duration'                 => time() - $started,
				'current_date_and_time'         => gmdate( 'Y-m-d H:i:s' ),
				'error'                         => 'Authentication failed. ' . ( $detail ? $detail : 'Verify the Dealius service-account credentials.' ),
			);
			$this->logger->record_summary( $summary );
			$this->logger->log( 'Import aborted: ' . $summary['error'] );
			return $summary;
		}

		$active_status_ids = $this->lookups->active_status_ids();

		$groups       = array(); // building_key => array of listings
		$reps         = array(); // building_key => representative PropertyID (for the API fetch)
		$listing_ids  = array();
		$raw_seen     = 0; // total listings returned by the feed, before status filtering
		$page         = 1;
		$pages_total  = null;
		$reached_end  = false;
		$fetch_failed = false;

		do {
			$response = $this->api->get_listings(
				array(
					'page'     => $page,
					'pageSize' => $page_size,
				)
			);

			// A failed request is NOT proof the feed ended — /listings intermittently
			// returns nothing usable. Treating it as the end and then retiring is what
			// wipes the catalogue, so record it as a failure and leave retirement off.
			if ( ! is_array( $response ) ) {
				$fetch_failed = true;
				$this->logger->log(
					'Listing fetch FAILED on page ' . $page . ' of '
					. ( null === $pages_total ? 'unknown' : $pages_total )
					. ' — stopping early; retirement will be skipped.'
				);
				break;
			}

			// An empty page from a healthy response is a genuine end of feed.
			if ( empty( $response['Data'] ) ) {
				$reached_end = true;
				break;
			}

			if ( null === $pages_total ) {
				$pages_total = isset( $response['PagesTotal'] ) ? (int) $response['PagesTotal'] : 1;
			}

			foreach ( $response['Data'] as $listing_summary ) {
				$raw_seen++;
				$listing_id = isset( $listing_summary['ListingID'] ) ? $listing_summary['ListingID'] : null;
				if ( null === $listing_id ) {
					continue;
				}

				$listing = $this->api->get_listing_by_id( $listing_id );
				if ( ! $listing ) {
					continue;
				}

				if ( ! $this->is_active( $listing, $active_status_ids ) ) {
					continue;
				}

				$building = $this->building_identity( $listing );
				if ( ! $building ) {
					continue;
				}

				// --property may match either the building key or the PropertyID.
				if ( '' !== $args['property']
					&& (string) $building['key'] !== (string) $args['property']
					&& (string) $building['pid'] !== (string) $args['property'] ) {
					continue;
				}

				$groups[ $building['key'] ][] = $listing;
				if ( ! isset( $reps[ $building['key'] ] ) ) {
					$reps[ $building['key'] ] = $building['pid'];
				}
				$listing_ids[] = $listing_id;
			}

			$page++;
			$hit_page_cap = ( $args['pages'] > 0 && $page > (int) $args['pages'] );
			$hit_last     = ( null !== $pages_total && $page > $pages_total );

			if ( $hit_last ) {
				$reached_end = true;
			}
		} while ( ! $hit_page_cap && ! $hit_last );

		$this->logger->log(
			'Fetched listings: ' . count( $listing_ids ) . ' active across ' . count( $groups ) . ' properties.'
		);

		// Write each property group.
		$written       = 0;
		$subproperties = 0;
		foreach ( $groups as $building_key => $listings ) {
			$result = $this->writer->write_group( $building_key, $reps[ $building_key ], $listings, (bool) $args['dry_run'] );
			if ( ! empty( $result['skipped'] ) || ! empty( $result['error'] ) ) {
				continue;
			}
			$written++;
			$subproperties += isset( $result['units'] ) ? (int) $result['units'] : 0;
		}

		// Retirement — only on a full, real, non-single-property run that imported
		// something (guards against wiping the catalogue on an API failure).
		$deleted = 0;
		$can_retire = ! $args['dry_run']
			&& '' === $args['property']
			&& $reached_end
			&& ! $fetch_failed
			&& count( $listing_ids ) > 0;

		if ( $can_retire ) {
			$deleted = $this->repository->retire_missing( array_keys( $groups ) );
		} else {
			$this->logger->log( 'Retirement skipped (dry-run, single-property, partial run, or zero imports).' );
		}

		if ( ! $args['dry_run'] ) {
			$this->bust_caches();
		}

		$this->unlock();

		$summary = array(
			'number_of_listings_imported'     => count( $listing_ids ),
			'number_of_properties_written'    => $written,
			'number_of_subproperties_written' => $subproperties,
			'number_of_properties_deleted'    => $deleted,
			'dry_run'                          => (bool) $args['dry_run'],
			'time_duration'                    => time() - $started,
			'current_date_and_time'            => gmdate( 'Y-m-d H:i:s' ),
		);

		// Explain a zero-result run so it isn't a silent no-op.
		if ( 0 === count( $listing_ids ) ) {
			if ( 0 === $raw_seen ) {
				$summary['note'] = 'The Dealius API returned no listings (authentication succeeded but the feed was empty). Check the service account has access to listings.';
			} elseif ( '' !== $args['property'] ) {
				$summary['note'] = sprintf( '%d listings scanned but none belong to property %s.', $raw_seen, $args['property'] );
			} else {
				$summary['note'] = sprintf( '%d listings returned but none matched the active-status filter (default: On Market / In Contract). Adjust the allowed_listing_statuses filter if needed.', $raw_seen );
			}
		}

		$this->logger->record_summary( $summary );
		$this->logger->log( 'Import finished.', $summary );

		return $summary;
	}

	/** Clear the auth cooldown (after unlocking the account / changing credentials). */
	public function clear_auth_block() {
		$this->api->clear_auth_block();
	}

	/** @return int unix timestamp the auth cooldown lasts until (0 if not blocked). */
	public function auth_blocked_until() {
		return $this->api->auth_blocked_until();
	}

	/**
	 * Is an import currently running?
	 *
	 * @return int unix timestamp the run started (0 if not running / lock is stale).
	 */
	public function is_running() {
		$started = (int) get_option( self::LOCK_OPTION, 0 );
		return ( $started > 0 && $started > ( time() - self::LOCK_TTL ) ) ? $started : 0;
	}

	/** Force-clear the run-lock (use only if a run was killed and the lock is stuck). */
	public function clear_lock() {
		delete_option( self::LOCK_OPTION );
	}

	/** @return array{token: string, expiry: int} cached token without triggering a login. */
	public function get_cached_token() {
		return $this->api->get_cached_token();
	}

	/** Force a fresh token (one login attempt, subject to the auth cooldown). @return string|false */
	public function refresh_token() {
		return $this->api->get_token( true );
	}

	/* ---- helpers ----------------------------------------------------------- */

	/**
	 * Identify the building a listing belongs to.
	 *
	 * Returns the building KEY (OriginPropertyID, or the Level-1 PropertyID when no
	 * origin is set) plus the representative PropertyID for the API fetch. Sale and
	 * lease records of one building share an OriginPropertyID, so they group together.
	 *
	 * @param array $listing
	 * @return array{key: string, pid: string}|false
	 */
	private function building_identity( array $listing ) {
		$properties = cd_arr_get( $listing, 'Properties' );
		if ( ! is_array( $properties ) ) {
			return false;
		}
		foreach ( $properties as $property ) {
			if ( (int) cd_arr_get( $property, 'Level' ) === 1 ) {
				$pid = cd_arr_get( $property, 'PropertyID' );
				if ( ! $pid ) {
					return false;
				}
				$origin = cd_arr_get( $property, 'OriginPropertyID' );
				$key    = ( null !== $origin && '' !== (string) $origin && 0 !== (int) $origin )
					? (string) $origin
					: (string) $pid;
				return array(
					'key' => $key,
					'pid' => (string) $pid,
				);
			}
		}
		return false;
	}

	/**
	 * Is a listing active? Prefer the resolved status IDs; fall back to the
	 * StatusName allow-list (matches the legacy behavior).
	 *
	 * @param array $listing
	 * @param int[] $active_status_ids
	 * @return bool
	 */
	private function is_active( array $listing, array $active_status_ids ) {
		if ( ! empty( $active_status_ids ) ) {
			$status_id = cd_arr_get( $listing, 'Status', cd_arr_get( $listing, 'StatusID' ) );
			if ( null !== $status_id ) {
				return in_array( (int) $status_id, $active_status_ids, true );
			}
		}

		$allowed = apply_filters( 'allowed_listing_statuses', array( 'On Market', 'In Contract' ) );
		$allowed = array_map( 'strtolower', array_map( 'trim', (array) $allowed ) );
		$name    = strtolower( trim( (string) cd_arr_get( $listing, 'StatusName', '' ) ) );
		return in_array( $name, $allowed, true );
	}

	private function bust_caches() {
		delete_option( 'ff_search_cache' );
		delete_transient( 'ff_90596_properties' );
		delete_transient( 'cd_properties' );
		wp_cache_flush();
	}

	/* ---- run lock ---------------------------------------------------------- */

	private function is_locked() {
		$started = (int) get_option( self::LOCK_OPTION, 0 );
		return $started > 0 && $started > ( time() - self::LOCK_TTL );
	}

	private function lock() {
		update_option( self::LOCK_OPTION, time(), false );
	}

	private function unlock() {
		delete_option( self::LOCK_OPTION );
	}
}

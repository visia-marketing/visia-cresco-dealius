<?php
/**
 * Resolves Dealius integer IDs to names (and back) via /api/lookup/*, cached in
 * transients. Per CLAUDE.md we must NOT hardcode IDs — but we keep the legacy map
 * as a fallback so the importer still works if a lookup endpoint is unavailable.
 */

namespace Cresco\Dealius;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Lookups {

	const CACHE_PREFIX = 'cd_lookup_';
	const CACHE_TTL    = WEEK_IN_SECONDS;

	/** Legacy hardcoded property-type map, used only as a fallback. */
	private static $property_type_fallback = array(
		1003 => 'Healthcare',
		6    => 'Hospitality',
		3    => 'Industrial',
		9    => 'Investment',
		5    => 'Land',
		1002 => 'Manufactured Housing',
		8    => 'Medical',
		4    => 'Multi-Family',
		2    => 'Office',
		7    => 'Restaurant',
		1    => 'Retail',
		1001 => 'Self-Storage',
	);

	/** @var Api_Client */
	private $api;

	public function __construct( Api_Client $api ) {
		$this->api = $api;
	}

	/**
	 * @param int|string $type_id
	 * @return string|null
	 */
	public function property_type_name( $type_id ) {
		if ( null === $type_id || '' === $type_id ) {
			return null;
		}
		$map = $this->id_to_text_map( 'propertytypes', self::$property_type_fallback );
		return isset( $map[ (int) $type_id ] ) ? $map[ (int) $type_id ] : null;
	}

	/**
	 * Resolve the integer status IDs that count as "active" for our purposes.
	 * Matches the allowed status NAMES (default On Market / In Contract) against
	 * the listingstatuses lookup. Filterable via `allowed_listing_statuses`.
	 *
	 * @return int[] empty array means "could not resolve — fall back to name matching"
	 */
	public function active_status_ids() {
		$allowed = apply_filters( 'allowed_listing_statuses', array( 'On Market', 'In Contract' ) );
		$allowed = array_map( 'strtolower', array_map( 'trim', (array) $allowed ) );

		$map = $this->id_to_text_map( 'listingstatuses', array() );
		if ( empty( $map ) ) {
			return array();
		}

		$ids = array();
		foreach ( $map as $id => $text ) {
			if ( in_array( strtolower( trim( $text ) ), $allowed, true ) ) {
				$ids[] = (int) $id;
			}
		}
		return $ids;
	}

	/**
	 * Resolve a SubmarketID to its name. Submarkets are office-scoped
	 * (/api/lookup/submarkets/{officeId}); we use the account's primary office.
	 *
	 * @param int|string $submarket_id
	 * @return string|null
	 */
	public function submarket_name( $submarket_id ) {
		if ( null === $submarket_id || '' === (string) $submarket_id || 0 === (int) $submarket_id ) {
			return null;
		}
		$office_id = $this->primary_office_id();
		if ( ! $office_id ) {
			return null;
		}

		$cache_key = self::CACHE_PREFIX . 'submarkets_' . $office_id;
		$map       = get_transient( $cache_key );
		if ( ! is_array( $map ) || empty( $map ) ) {
			$rows = $this->api->get( '/lookup/submarkets/' . rawurlencode( (string) $office_id ) );
			$map  = array();
			if ( is_array( $rows ) ) {
				foreach ( $rows as $row ) {
					if ( isset( $row['ID'], $row['Text'] ) ) {
						$map[ (int) $row['ID'] ] = (string) $row['Text'];
					}
				}
			}
			if ( ! empty( $map ) ) {
				set_transient( $cache_key, $map, self::CACHE_TTL );
			}
		}
		return isset( $map[ (int) $submarket_id ] ) ? $map[ (int) $submarket_id ] : null;
	}

	/**
	 * The account's primary office ID (first office), cached. Overridable via the
	 * `cd_dealius_office_id` filter / CRESCO_DEALIUS_OFFICE_ID constant.
	 *
	 * @return int
	 */
	private function primary_office_id() {
		if ( defined( 'CRESCO_DEALIUS_OFFICE_ID' ) && (int) CRESCO_DEALIUS_OFFICE_ID ) {
			return (int) CRESCO_DEALIUS_OFFICE_ID;
		}
		$cached = (int) get_transient( 'cd_lookup_primary_office' );
		if ( $cached ) {
			return $cached;
		}
		$offices = $this->api->get_lookup( 'offices' );
		$office_id = ( is_array( $offices ) && isset( $offices[0]['ID'] ) ) ? (int) $offices[0]['ID'] : 0;
		$office_id = (int) apply_filters( 'cd_dealius_office_id', $office_id );
		if ( $office_id ) {
			set_transient( 'cd_lookup_primary_office', $office_id, self::CACHE_TTL );
		}
		return $office_id;
	}

	/**
	 * Build an ID => Text map for a lookup endpoint, cached. Falls back to the
	 * provided map when the endpoint can't be reached.
	 *
	 * @param string $lookup_name
	 * @param array  $fallback
	 * @return array
	 */
	private function id_to_text_map( $lookup_name, array $fallback ) {
		$cache_key = self::CACHE_PREFIX . $lookup_name;
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}

		$rows = $this->api->get_lookup( $lookup_name );
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return $fallback;
		}

		$map = array();
		foreach ( $rows as $row ) {
			// Lookup rows are { ID, Text }.
			$id   = isset( $row['ID'] ) ? (int) $row['ID'] : null;
			$text = isset( $row['Text'] ) ? (string) $row['Text'] : null;
			if ( null !== $id && null !== $text ) {
				$map[ $id ] = $text;
			}
		}

		if ( empty( $map ) ) {
			return $fallback;
		}

		set_transient( $cache_key, $map, self::CACHE_TTL );
		return $map;
	}
}

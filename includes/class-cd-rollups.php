<?php
/**
 * Pure rollup math. Given the set of (full) listings that belong to ONE property,
 * and the available-unit sizes, compute the parent-level values the front-end
 * filter and single template need (since per-unit price/type are no longer stored
 * on each repeater row).
 */

namespace Cresco\Dealius;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Rollups {

	/**
	 * Normalize a single listing's type to Sale or Lease (matches the legacy rule).
	 *
	 * @param array $listing
	 * @return string 'Sale' | 'Lease'
	 */
	public function listing_type( array $listing ) {
		$name = (string) cd_arr_get( $listing, 'ListingTypeName', '' );
		return ( stripos( $name, 'sale' ) !== false ) ? 'Sale' : 'Lease';
	}

	/**
	 * Property-level availability flag.
	 *
	 * @param array $listings
	 * @return string 'Sale' | 'Lease' | 'Both' | ''
	 */
	public function availability( array $listings ) {
		$types = array();
		foreach ( $listings as $listing ) {
			$types[ $this->listing_type( $listing ) ] = true;
		}
		$has_sale  = isset( $types['Sale'] );
		$has_lease = isset( $types['Lease'] );
		if ( $has_sale && $has_lease ) {
			return 'Both';
		}
		if ( $has_sale ) {
			return 'Sale';
		}
		if ( $has_lease ) {
			return 'Lease';
		}
		return '';
	}

	/**
	 * Legacy slash-joined unique type string (e.g. "Lease/Sale"), kept so the
	 * front-end filter's substring match keeps working.
	 *
	 * @param array $listings
	 * @return string
	 */
	public function listing_type_string( array $listings ) {
		$types = array();
		foreach ( $listings as $listing ) {
			$types[ $this->listing_type( $listing ) ] = true;
		}
		$types = array_keys( $types );
		sort( $types );
		return implode( '/', $types );
	}

	/**
	 * Min/max asking price across the group (AskingPriceAmount).
	 *
	 * @param array $listings
	 * @return array{0: float|null, 1: float|null} [min, max]
	 */
	public function price_range( array $listings ) {
		$values = array();
		foreach ( $listings as $listing ) {
			$price = cd_arr_get( $listing, 'AskingPriceAmount' );
			if ( is_numeric( $price ) && (float) $price > 0 ) {
				$values[] = (float) $price;
			}
		}
		return $this->min_max( $values );
	}

	/**
	 * Min/max lease rate across the group. Mirrors the legacy merge of the
	 * listing RentAmount and the AvailableSpacesAskingRate yearly min/max.
	 *
	 * @param array $listings
	 * @return array{0: float|null, 1: float|null} [min, max]
	 */
	public function rate_range( array $listings ) {
		$values = array();
		foreach ( $listings as $listing ) {
			foreach (
				array(
					cd_arr_get( $listing, 'RentPerSfAmount' ), // actual per-SF rate field
					cd_arr_get( $listing, 'RentAmount' ),       // legacy/fallback
					cd_arr_get( $listing, 'AvailableSpacesAskingRate YearlyMin' ),
					cd_arr_get( $listing, 'AvailableSpacesAskingRate YearlyMax' ),
				) as $candidate
			) {
				if ( is_numeric( $candidate ) && (float) $candidate > 0 ) {
					$values[] = (float) $candidate;
				}
			}
		}
		return $this->min_max( $values );
	}

	/**
	 * The single representative asking rate (legacy: first property's AskingRatePerSf).
	 *
	 * @param array $listings
	 * @return float|null
	 */
	public function asking_rate( array $listings ) {
		foreach ( $listings as $listing ) {
			foreach (
				array(
					cd_arr_get( $listing, 'Properties 0 AskingRatePerSf' ),
					cd_arr_get( $listing, 'RentPerSfAmount' ),
					cd_arr_get( $listing, 'AvailableSpacesAskingRate YearlyMin' ),
				) as $rate
			) {
				if ( is_numeric( $rate ) && (float) $rate > 0 ) {
					return (float) $rate;
				}
			}
		}
		return null;
	}

	/**
	 * @param float[] $sizes
	 * @return array{0: float|null, 1: float|null} [min, max]
	 */
	public function size_range( array $sizes ) {
		$values = array_filter(
			array_map( 'floatval', $sizes ),
			function ( $v ) {
				return $v > 0;
			}
		);
		return $this->min_max( array_values( $values ) );
	}

	/**
	 * @param float[] $sizes
	 * @return float
	 */
	public function total_size( array $sizes ) {
		$total = 0.0;
		foreach ( $sizes as $size ) {
			if ( is_numeric( $size ) ) {
				$total += (float) $size;
			}
		}
		return $total;
	}

	/**
	 * @param float[] $values
	 * @return array{0: float|null, 1: float|null}
	 */
	private function min_max( array $values ) {
		if ( empty( $values ) ) {
			return array( null, null );
		}
		return array( min( $values ), max( $values ) );
	}
}

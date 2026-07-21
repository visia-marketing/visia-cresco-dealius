<?php
/**
 * Root-namespace helpers shared across the plugin and consumed by the theme/filter.
 *
 * Intentionally NOT namespaced so they are callable as plain functions everywhere.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! function_exists( 'cd_dealius_property' ) ) {
	/**
	 * Global accessor for the plugin container.
	 *
	 * The theme uses cd_dealius_property()->get_api_token() and
	 * cd_dealius_property()->get_api_client()->get_listing_by_id( $id ).
	 *
	 * @return \Cresco\Dealius\Plugin
	 */
	function cd_dealius_property() {
		return \Cresco\Dealius\Plugin::instance();
	}
}

if ( ! function_exists( 'cd_arr_get' ) ) {
	/**
	 * Safe nested array read. Mirrors the old th_data_holder space-delimited path
	 * access (e.g. 'AvailableSpacesAskingRate YearlyMin'), but accepts either a
	 * space- or dot-delimited path, or an array of keys.
	 *
	 * @param array        $array
	 * @param string|array $path
	 * @param mixed        $default
	 * @return mixed
	 */
	function cd_arr_get( $array, $path, $default = null ) {
		if ( ! is_array( $array ) ) {
			return $default;
		}
		$keys = is_array( $path ) ? $path : preg_split( '/[ .]+/', (string) $path );
		$pointer = $array;
		foreach ( $keys as $key ) {
			if ( is_array( $pointer ) && array_key_exists( $key, $pointer ) ) {
				$pointer = $pointer[ $key ];
			} else {
				return $default;
			}
		}
		return $pointer;
	}
}

if ( ! function_exists( 'cd_normalize_suite' ) ) {
	/**
	 * Normalize a suite/unit label for dedupe across multiple listings on one
	 * property. Trims, collapses whitespace, and lowercases.
	 *
	 * @param string $suite
	 * @return string
	 */
	function cd_normalize_suite( $suite ) {
		$suite = trim( (string) $suite );
		$suite = preg_replace( '/\s+/', ' ', $suite );
		return strtolower( $suite );
	}
}

<?php
/**
 * Credential + configuration resolution.
 *
 * Credentials NEVER live in code. Resolution order:
 *   1. wp-config constants  CRESCO_DEALIUS_EMAIL / CRESCO_DEALIUS_PASSWORD
 *      (also accepts the legacy names CRESCO_API_USERNAME / CRESCO_API_PASSWORD)
 *   2. environment variables of the same names
 *   3. the admin settings option `th_cresco_credentials` (reused from the old plugin)
 */

namespace Cresco\Dealius;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Settings {

	const CREDENTIALS_OPTION = 'th_cresco_credentials';
	const API_BASE           = 'https://cresco.dealius.com/api';

	

	/** @return string */
	public function get_api_base() {
		return apply_filters( 'cd_dealius_api_base', self::API_BASE );
	}

	/** @return string */
	public function get_email() {
		$value = $this->from_constant( array( 'CRESCO_DEALIUS_EMAIL', 'CRESCO_API_USERNAME' ) );
		if ( $value ) {
			return $value;
		}
		$value = $this->from_env( array( 'CRESCO_DEALIUS_EMAIL', 'CRESCO_API_USERNAME' ) );
		if ( $value ) {
			return $value;
		}
		$creds = get_option( self::CREDENTIALS_OPTION, array() );
		if ( ! empty( $creds['username'] ) ) {
			return (string) $creds['username'];
		}
		return ''; // no hardcoded fallback — set credentials in wp-config / env / settings
	}

	/** @return string */
	public function get_password() {
		$value = $this->from_constant( array( 'CRESCO_DEALIUS_PASSWORD', 'CRESCO_API_PASSWORD' ) );
		if ( $value ) {
			return $value;
		}
		$value = $this->from_env( array( 'CRESCO_DEALIUS_PASSWORD', 'CRESCO_API_PASSWORD' ) );
		if ( $value ) {
			return $value;
		}
		$creds = get_option( self::CREDENTIALS_OPTION, array() );
		if ( ! empty( $creds['password'] ) ) {
			return (string) $creds['password'];
		}
		return ''; // no hardcoded fallback — set credentials in wp-config / env / settings
	}

	/** @return bool true when credentials are stored in the DB option rather than wp-config/env. */
	public function credentials_from_option() {
		return ! $this->from_constant( array( 'CRESCO_DEALIUS_EMAIL', 'CRESCO_API_USERNAME' ) )
			&& ! $this->from_env( array( 'CRESCO_DEALIUS_EMAIL', 'CRESCO_API_USERNAME' ) );
	}

	/**
	 * @param string $username
	 * @param string $password
	 */
	public function save_option_credentials( $username, $password ) {
		update_option(
			self::CREDENTIALS_OPTION,
			array(
				'username' => $username,
				'password' => $password,
			)
		);
	}

	/**
	 * @param string[] $names
	 * @return string
	 */
	private function from_constant( array $names ) {
		foreach ( $names as $name ) {
			if ( defined( $name ) && constant( $name ) ) {
				return (string) constant( $name );
			}
		}
		return '';
	}

	/**
	 * @param string[] $names
	 * @return string
	 */
	private function from_env( array $names ) {
		foreach ( $names as $name ) {
			$value = getenv( $name );
			if ( false !== $value && '' !== $value ) {
				return (string) $value;
			}
		}
		return '';
	}
}

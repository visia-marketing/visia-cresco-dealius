<?php
/**
 * Dealius API client.
 *
 * Auth quirk: there is no API key. We authenticate a service account and pass the
 * returned token as a `token` QUERY-STRING param on every call (not a Bearer header).
 * The token expires, so we re-auth on expiry and retry once on a 401.
 *
 * Security: this class NEVER logs the token, credentials, or full request URLs.
 */

namespace Cresco\Dealius;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Api_Client {

	const TOKEN_OPTION  = 'cresco_api_token';
	const EXPIRY_OPTION = 'cresco_api_token_expiry';

	// Auth circuit breaker — prevents repeated failed logins from hammering Dealius
	// (and locking the account). After a failure we refuse to log in again until
	// the cooldown passes; clicking Run / cron firing makes ZERO API calls meanwhile.
	const BLOCK_UNTIL_OPTION  = 'cd_dealius_auth_blocked_until';
	const BLOCK_REASON_OPTION = 'cd_dealius_auth_block_reason';
	const COOLDOWN_DEFAULT    = 900;  // 15 min after an ordinary failure
	const COOLDOWN_LOCKOUT    = 3600; // 1 hour if Dealius reports the account locked

	/** @var Settings */
	private $settings;
	/** @var Logger */
	private $logger;
	/** @var string Human-readable detail of the last auth failure (no secrets). */
	private $last_auth_error = '';

	public function __construct( Settings $settings, Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/** @return string */
	public function get_last_auth_error() {
		return $this->last_auth_error;
	}

	/**
	 * The currently cached token + expiry, WITHOUT triggering a login.
	 *
	 * @return array{token: string, expiry: int}
	 */
	public function get_cached_token() {
		return array(
			'token'  => (string) get_option( self::TOKEN_OPTION, '' ),
			'expiry' => (int) get_option( self::EXPIRY_OPTION, 0 ),
		);
	}

	/**
	 * Manually clear the auth cooldown (e.g. after unlocking the account or
	 * changing credentials). Returns nothing.
	 */
	public function clear_auth_block() {
		delete_option( self::BLOCK_UNTIL_OPTION );
		delete_option( self::BLOCK_REASON_OPTION );
	}

	/** @return int unix timestamp the cooldown lasts until (0 if not blocked). */
	public function auth_blocked_until() {
		$until = (int) get_option( self::BLOCK_UNTIL_OPTION, 0 );
		return ( $until > time() ) ? $until : 0;
	}

	/**
	 * Record a cooldown after a failed login so we don't retry immediately.
	 *
	 * @param int    $seconds
	 * @param string $reason
	 */
	private function block_auth( $seconds, $reason ) {
		$seconds = (int) apply_filters( 'cd_dealius_auth_cooldown', $seconds, $reason );
		update_option( self::BLOCK_UNTIL_OPTION, time() + $seconds, false );
		update_option( self::BLOCK_REASON_OPTION, $reason, false );
	}

	/* ---- Authentication ---------------------------------------------------- */

	/**
	 * @param bool $force re-authenticate even if a cached token looks valid.
	 * @return string|false
	 */
	public function get_token( $force = false ) {
		$token  = get_option( self::TOKEN_OPTION );
		$expiry = (int) get_option( self::EXPIRY_OPTION );

		// A still-valid cached token never needs a login.
		if ( ! $force && ! empty( $token ) && $expiry > time() ) {
			return $token;
		}

		// Circuit breaker: if a recent login failed, do NOT contact the API again
		// until the cooldown expires — even when $force is true.
		$blocked_until = $this->auth_blocked_until();
		if ( $blocked_until ) {
			$reason                = (string) get_option( self::BLOCK_REASON_OPTION, '' );
			$this->last_auth_error = sprintf(
				'Login skipped to protect the Dealius account — a previous attempt failed%s. Not retrying until %s UTC. Use "wp cresco import --reset-auth" (or save credentials) once the account is unlocked.',
				$reason ? ' (' . $reason . ')' : '',
				gmdate( 'Y-m-d H:i', $blocked_until )
			);
			$this->logger->log( 'AUTH: in cooldown until ' . gmdate( 'Y-m-d H:i:s', $blocked_until ) . ' — not attempting.' );
			return false;
		}

		if ( $force || empty( $token ) || $expiry < time() ) {
			return $this->authenticate();
		}
		return $token;
	}

	/**
	 * @return string|false
	 */
	private function authenticate() {
		$email    = $this->settings->get_email();
		$password = $this->settings->get_password();

		$this->last_auth_error = '';

		if ( '' === $email || '' === $password ) {
			$this->last_auth_error = 'No credentials configured.';
			$this->logger->log( 'AUTH: missing credentials (set CRESCO_DEALIUS_EMAIL / CRESCO_DEALIUS_PASSWORD in wp-config or env).' );
			return false;
		}

		$response = wp_remote_post(
			$this->settings->get_api_base() . '/account/authenticate',
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'Email'    => $email,
						'Password' => $password,
					)
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->last_auth_error = 'Network error contacting Dealius: ' . $response->get_error_message();
			$this->block_auth( self::COOLDOWN_DEFAULT, 'network error' );
			$this->logger->log( 'AUTH: transport error - ' . $response->get_error_message() );
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( 200 !== $code || empty( $data['Token'] ) ) {
			// Include the HTTP status and a scrubbed snippet of the response so the
			// real reason (bad password vs. blocked account vs. bad request) is visible.
			$snippet               = trim( $this->logger->scrub( wp_strip_all_tags( (string) $body ) ) );
			$snippet               = ( strlen( $snippet ) > 300 ) ? substr( $snippet, 0, 300 ) . '…' : $snippet;
			$this->last_auth_error = 'Dealius returned HTTP ' . $code . ( '' !== $snippet ? ' — ' . $snippet : '' );

			// A lockout / 403 gets a longer cooldown so we don't keep the account locked.
			$is_lockout = ( 403 === $code ) || ( false !== stripos( (string) $body, 'lock' ) );
			$this->block_auth(
				$is_lockout ? self::COOLDOWN_LOCKOUT : self::COOLDOWN_DEFAULT,
				'HTTP ' . $code . ( $is_lockout ? ' (account locked)' : '' )
			);

			$this->logger->log( 'AUTH: failed, HTTP ' . $code . ' — cooling down.' );
			return false;
		}

		$token = (string) $data['Token'];

		// Prefer the server-provided expiry; otherwise use a conservative 5-minute window.
		$expiry = time() + 5 * MINUTE_IN_SECONDS;
		if ( ! empty( $data['TokenExpiry'] ) ) {
			$parsed = strtotime( (string) $data['TokenExpiry'] );
			if ( $parsed ) {
				// Refresh a minute before the real expiry to avoid edge-of-window 401s.
				$expiry = $parsed - MINUTE_IN_SECONDS;
			}
		}

		update_option( self::TOKEN_OPTION, $token, false );
		update_option( self::EXPIRY_OPTION, $expiry, false );
		$this->clear_auth_block(); // success → drop any cooldown

		$this->logger->log( 'AUTH: ok, token ' . $this->logger->mask( $token ) );
		return $token;
	}

	/* ---- Core request helper ---------------------------------------------- */

	/**
	 * GET a path with the token appended as a query param. Retries once on 401
	 * with a freshly minted token.
	 *
	 * @param string $path  e.g. '/listings' or '/properties/123/details'
	 * @param array  $query query args (token is added automatically)
	 * @return array|false decoded JSON, or false on failure
	 */
	public function get( $path, array $query = array() ) {
		return $this->request( $path, $query, false );
	}

	/**
	 * @param string $path
	 * @param array  $query
	 * @param bool   $is_retry
	 * @return array|false
	 */
	private function request( $path, array $query, $is_retry ) {
		$token = $this->get_token();
		if ( ! $token ) {
			return false;
		}

		$query['token'] = $token;
		$url            = $this->settings->get_api_base() . $path . '?' . http_build_query( $query );

		// Log the path only — never the query string (it carries the token).
		$this->logger->log( 'GET ' . $path );

		$response = wp_remote_get(
			$url,
			array(
				'headers' => array( 'Accept' => 'application/json' ),
				'timeout' => 45,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->logger->log( 'GET ' . $path . ' transport error - ' . $response->get_error_message() );
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 401 === $code && ! $is_retry ) {
			// Token likely expired mid-run; force a refresh and retry once.
			$this->logger->log( 'GET ' . $path . ' got 401, re-authenticating' );
			$this->get_token( true );
			return $this->request( $path, $query, true );
		}

		if ( 200 !== $code ) {
			$this->logger->log( 'GET ' . $path . ' HTTP ' . $code );
			return false;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return ( null === $data ) ? false : $data;
	}

	/* ---- Endpoints --------------------------------------------------------- */

	/**
	 * Paged active-listing query.
	 *
	 * @param array $params searchTerm/status/page/pageSize/dateFrom/...
	 * @return array|false { Data, DataTotal, Page, PageSize, PagesTotal }
	 */
	public function get_listings( array $params = array() ) {
		$defaults = array(
			'page'     => 1,
			'pageSize' => 50,
		);
		return $this->get( '/listings', wp_parse_args( $params, $defaults ) );
	}

	/**
	 * @param int|string $listing_id
	 * @param bool        $use_cache
	 * @return array|false
	 */
	public function get_listing_by_id( $listing_id, $use_cache = true ) {
		$cache_key = 'cd_listing_' . $listing_id;
		if ( $use_cache ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}
		$data = $this->get( '/listings/' . rawurlencode( (string) $listing_id ) );
		$data = ( is_array( $data ) && isset( $data['ListingID'] ) ) ? $data : false;
		set_transient( $cache_key, $data, DAY_IN_SECONDS );
		return $data;
	}

	/**
	 * @param int|string $property_id
	 * @return array|false
	 */
	public function get_property( $property_id ) {
		$data = $this->get( '/properties/' . rawurlencode( (string) $property_id ) );
		return ( is_array( $data ) && isset( $data['PropertyID'] ) ) ? $data : false;
	}

	/**
	 * @param int|string $property_id
	 * @return array|false
	 */
	public function get_property_details( $property_id ) {
		$data = $this->get( '/properties/' . rawurlencode( (string) $property_id ) . '/details' );
		return is_array( $data ) ? $data : false;
	}

	/**
	 * Spaces, keyed by PropertySpaceID for easy lookup (matches the old shape).
	 *
	 * @param int|string $property_id
	 * @return array keyed by PropertySpaceID => { SpaceTypeName, PropertySpaceID, Name }
	 */
	public function get_property_spaces( $property_id ) {
		$data = $this->get( '/properties/' . rawurlencode( (string) $property_id ) . '/spaces' );
		if ( ! is_array( $data ) ) {
			return array();
		}
		$keyed = array();
		foreach ( $data as $space ) {
			if ( isset( $space['PropertySpaceID'] ) ) {
				$keyed[ $space['PropertySpaceID'] ] = array(
					'SpaceTypeName'   => isset( $space['SpaceTypeName'] ) ? $space['SpaceTypeName'] : null,
					'PropertySpaceID' => $space['PropertySpaceID'],
					'Name'            => isset( $space['Name'] ) ? $space['Name'] : null,
				);
			}
		}
		return $keyed;
	}

	/**
	 * Generic lookup endpoint (e.g. 'propertytypes', 'listingstatuses').
	 *
	 * @param string $name
	 * @return array|false
	 */
	public function get_lookup( $name ) {
		$data = $this->get( '/lookup/' . rawurlencode( $name ) );
		return is_array( $data ) ? $data : false;
	}
}

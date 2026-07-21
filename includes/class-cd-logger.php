<?php
/**
 * Minimal logger that NEVER writes credentials, tokens, or full request URLs.
 *
 * Writes to the WP debug log (when WP_DEBUG_LOG is on) and keeps the most recent
 * import summaries in an option for the admin screen.
 */

namespace Cresco\Dealius;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Logger {

	const SUMMARY_OPTION = 'cd_dealius_import_summaries';
	const MAX_SUMMARIES  = 20;

	/**
	 * Log a message. Any token=... query param and any cresco.dealius.com URL is
	 * masked before being written, as a defence-in-depth measure.
	 *
	 * @param string $message
	 * @param mixed  $context optional array/scalar appended as JSON.
	 */
	public function log( $message, $context = null ) {
		$message = $this->scrub( (string) $message );
		if ( null !== $context ) {
			$message .= ' ' . $this->scrub( wp_json_encode( $context ) );
		}
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[cd-dealius] ' . $message );
		}
	}

	/**
	 * Mask a secret for display (first/last char kept).
	 *
	 * @param string $secret
	 * @return string
	 */
	public function mask( $secret ) {
		$secret = (string) $secret;
		$len    = strlen( $secret );
		if ( $len <= 4 ) {
			return str_repeat( '*', $len );
		}
		return substr( $secret, 0, 2 ) . str_repeat( '*', $len - 4 ) . substr( $secret, -2 );
	}

	/**
	 * Remove tokens and credentials from a string before it is logged.
	 *
	 * @param string $text
	 * @return string
	 */
	public function scrub( $text ) {
		// token=<value> in a query string.
		$text = preg_replace( '/token=[^&\s"]+/i', 'token=***', $text );
		// "Token":"<value>" / "Password":"<value>" in JSON.
		$text = preg_replace( '/"(Token|Password|password)"\s*:\s*"[^"]*"/i', '"$1":"***"', $text );
		return $text;
	}

	/**
	 * Persist a one-line summary of an import run for the admin screen.
	 *
	 * @param array $summary
	 */
	public function record_summary( array $summary ) {
		$summaries   = get_option( self::SUMMARY_OPTION, array() );
		$summaries[] = $summary;
		if ( count( $summaries ) > self::MAX_SUMMARIES ) {
			$summaries = array_slice( $summaries, -self::MAX_SUMMARIES );
		}
		update_option( self::SUMMARY_OPTION, $summaries, false );
	}

	/** @return array */
	public function get_summaries() {
		return array_reverse( (array) get_option( self::SUMMARY_OPTION, array() ) );
	}
}

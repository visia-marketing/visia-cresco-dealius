<?php
/**
 * Admin settings page: shows the next scheduled sync, a manual "Run import now"
 * button, recent import summaries, and (only when credentials are stored in the
 * DB rather than wp-config/env) a credentials form.
 */

namespace Cresco\Dealius;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Admin {

	const PAGE_SLUG        = 'cd-dealius-property';
	const RUN_ACTION       = 'cd_dealius_run';
	const RESET_ACTION     = 'cd_dealius_reset_auth';
	const CLEAR_LOCK_ACTION = 'cd_dealius_clear_lock';
	const TOKEN_ACTION      = 'cd_dealius_refresh_token';

	/** @var Importer */
	private $importer;
	/** @var Settings */
	private $settings;
	/** @var Logger */
	private $logger;

	public function __construct( Importer $importer, Settings $settings, Logger $logger ) {
		$this->importer = $importer;
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_' . self::RUN_ACTION, array( $this, 'handle_run' ) );
		add_action( 'admin_post_' . self::RESET_ACTION, array( $this, 'handle_reset_auth' ) );
		add_action( 'admin_post_' . self::CLEAR_LOCK_ACTION, array( $this, 'handle_clear_lock' ) );
		add_action( 'admin_post_' . self::TOKEN_ACTION, array( $this, 'handle_refresh_token' ) );
		add_action( 'admin_notices', array( $this, 'maybe_credentials_notice' ) );
	}

	public function add_menu() {
		add_menu_page(
			__( 'Cresco Dealius', 'cd-dealius' ),
			__( 'Cresco Dealius', 'cd-dealius' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			'dashicons-building',
			11
		);
	}

	public function maybe_credentials_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( $this->settings->credentials_from_option() ) {
			echo '<div class="notice notice-warning"><p><strong>Cresco Dealius:</strong> API credentials are coming from the plugin code/database fallback. For security, define <code>CRESCO_DEALIUS_EMAIL</code> and <code>CRESCO_DEALIUS_PASSWORD</code> in <code>wp-config.php</code> instead, then remove the hardcoded fallback in <code>class-cd-settings.php</code> and rotate the Dealius service-account password.</p></div>';
		}
	}

	public function handle_run() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'cd-dealius' ) );
		}
		check_admin_referer( self::RUN_ACTION );

		$args = array();
		if ( ! empty( $_POST['dry_run'] ) ) {
			$args['dry_run'] = true;
		}
		if ( ! empty( $_POST['pages'] ) ) {
			$args['pages'] = (int) $_POST['pages'];
		}

		$summary = $this->importer->run( $args );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => self::PAGE_SLUG,
					'cd_ran'   => 1,
					'imported' => isset( $summary['number_of_listings_imported'] ) ? (int) $summary['number_of_listings_imported'] : 0,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_reset_auth() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'cd-dealius' ) );
		}
		check_admin_referer( self::RESET_ACTION );
		$this->importer->clear_auth_block();
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'cd_reset' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_clear_lock() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'cd-dealius' ) );
		}
		check_admin_referer( self::CLEAR_LOCK_ACTION );
		$this->importer->clear_lock();
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'cd_lock_cleared' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_refresh_token() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'cd-dealius' ) );
		}
		check_admin_referer( self::TOKEN_ACTION );
		$this->importer->refresh_token(); // one login attempt (cooldown-protected)
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'cd_token' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private function render_token() {
		$cached = $this->importer->get_cached_token();
		$token  = $cached['token'];
		$expiry = (int) $cached['expiry'];

		echo '<h2>API token</h2>';

		if ( '' === $token ) {
			echo '<p>No token cached yet. Click “Refresh token” or run an import.</p>';
		} else {
			$valid = $expiry > time();
			echo '<p>';
			echo $valid
				? 'Valid until <strong>' . esc_html( gmdate( 'Y-m-d H:i:s', $expiry ) ) . ' UTC</strong> (' . esc_html( human_time_diff( time(), $expiry ) ) . ' from now).'
				: '<strong style="color:#b32d2e;">Expired</strong> at ' . esc_html( gmdate( 'Y-m-d H:i:s', $expiry ) ) . ' UTC — refresh for a current one.';
			echo '</p>';
			echo '<textarea readonly rows="3" style="width:100%;max-width:680px;font-family:monospace;" onclick="this.select();">' . esc_textarea( $token ) . '</textarea>';
			echo '<p class="description">Click the box to select, then copy. Treat this as a secret.</p>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::TOKEN_ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::TOKEN_ACTION ) . '">';
		echo '<button type="submit" class="button">Refresh token</button> ';
		echo '<em>Forces one login to Dealius (respects the auth cooldown).</em>';
		echo '</form>';
	}

	private function render_status() {
		$started = $this->importer->is_running();
		if ( $started ) {
			$elapsed = human_time_diff( $started, time() );
			echo '<div class="notice notice-info"><p>';
			echo '<span class="spinner is-active" style="float:none;margin:0 6px 0 0;"></span>';
			echo '<strong>An import is currently running</strong> — started ' . esc_html( $elapsed ) . ' ago. This page auto-refreshes.';
			echo '</p>';
			// Stuck-lock escape hatch.
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0 0 8px;">';
			wp_nonce_field( self::CLEAR_LOCK_ACTION );
			echo '<input type="hidden" name="action" value="' . esc_attr( self::CLEAR_LOCK_ACTION ) . '">';
			echo '<button type="submit" class="button button-small">Clear run-lock (only if a run was killed)</button>';
			echo '</form></div>';
			// Auto-refresh while a run is in progress so the status stays current.
			echo '<script>setTimeout(function(){ location.reload(); }, 10000);</script>';
		} else {
			echo '<div class="notice notice-success inline"><p><strong>No import is currently running.</strong></p></div>';
		}
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Save credentials when posted (only relevant if not using constants/env).
		if ( isset( $_POST['cd_save_credentials'] ) && check_admin_referer( 'cd_save_credentials' ) ) {
			$this->settings->save_option_credentials(
				sanitize_text_field( wp_unslash( $_POST['cd_username'] ?? '' ) ),
				sanitize_text_field( wp_unslash( $_POST['cd_password'] ?? '' ) )
			);
			// New credentials → clear any auth cooldown so the next run can try them.
			$this->importer->clear_auth_block();
			echo '<div class="updated"><p>Credentials saved. Auth cooldown cleared.</p></div>';
		}

		echo '<div class="wrap"><h1>Cresco Dealius Property</h1>';

		$this->render_status();

		if ( isset( $_GET['cd_lock_cleared'] ) ) {
			echo '<div class="updated"><p>Run-lock cleared.</p></div>';
		}

		if ( isset( $_GET['cd_token'] ) ) {
			echo '<div class="updated"><p>Token refreshed (or skipped if the auth cooldown is active — see below).</p></div>';
		}

		if ( isset( $_GET['cd_reset'] ) ) {
			echo '<div class="updated"><p>Auth cooldown cleared. The next run will attempt a login.</p></div>';
		}

		if ( isset( $_GET['cd_ran'] ) ) {
			printf(
				'<div class="updated"><p>Import finished. Listings imported: %d.</p></div>',
				isset( $_GET['imported'] ) ? (int) $_GET['imported'] : 0
			);

			// Surface the reason for a zero-result / failed run.
			$summaries = $this->logger->get_summaries();
			$latest    = ! empty( $summaries ) ? $summaries[0] : array();
			if ( ! empty( $latest['error'] ) ) {
				echo '<div class="notice notice-error"><p><strong>' . esc_html( $latest['error'] ) . '</strong></p></div>';
			} elseif ( ! empty( $latest['note'] ) ) {
				echo '<div class="notice notice-warning"><p>' . esc_html( $latest['note'] ) . '</p></div>';
			}
		}

		$this->render_schedule();
		$this->render_run_form();
		$this->render_summaries();
		$this->render_token();
		$this->render_credentials_form();

		echo '</div>';
	}

	private function render_schedule() {
		$timestamp = wp_next_scheduled( Plugin::CRON_HOOK );
		echo '<h2>Schedule</h2><p>';
		if ( $timestamp ) {
			printf(
				'A WP-Cron sync is scheduled for %s (%s from now). Note: imports are meant to run via a server cron — <code>wp cresco import</code>.',
				esc_html( gmdate( 'Y-m-d H:i:s', $timestamp ) ),
				esc_html( human_time_diff( time(), $timestamp ) )
			);
		} else {
			echo 'No WP-Cron schedule (by design). The import runs via a server cron — e.g. a MyKinsta scheduled task running <code>wp cresco import</code>.';
		}
		echo '</p>';
	}

	private function render_run_form() {
		echo '<h2>Run import</h2>';

		// Auth cooldown status — shows when the plugin is deliberately NOT logging in.
		$blocked_until = $this->importer->auth_blocked_until();
		if ( $blocked_until ) {
			echo '<div class="notice notice-error inline"><p><strong>Auth paused.</strong> A login attempt failed, so the plugin will not contact Dealius again until <strong>' . esc_html( gmdate( 'Y-m-d H:i', $blocked_until ) ) . ' UTC</strong> (protects the account from lockout). Once the Dealius account is unlocked / the password is fixed, click below to clear it.</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-bottom:8px;">';
			wp_nonce_field( self::RESET_ACTION );
			echo '<input type="hidden" name="action" value="' . esc_attr( self::RESET_ACTION ) . '">';
			echo '<button type="submit" class="button">Clear auth cooldown</button>';
			echo '</form></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::RUN_ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::RUN_ACTION ) . '">';
		echo '<p><label>Max pages (blank = all): <input type="number" name="pages" min="1" style="width:80px"></label></p>';
		echo '<p><label><input type="checkbox" name="dry_run" value="1"> Dry run (compute only, write nothing — see the debug log)</label></p>';
		echo '<p><button type="submit" class="button button-primary">Run import now</button> ';
		echo '<em>Long imports may hit the PHP time limit in a browser request; use WP-CLI for full runs.</em></p>';
		echo '</form>';
	}

	private function render_summaries() {
		$summaries = $this->logger->get_summaries();
		echo '<h2>Recent imports</h2>';
		if ( empty( $summaries ) ) {
			echo '<p>No imports recorded yet.</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>When (UTC)</th><th>Listings</th><th>Properties written</th><th>Subproperties written</th><th>Deleted</th><th>Duration (s)</th><th>Dry run</th><th>Result</th>';
		echo '</tr></thead><tbody>';
		foreach ( $summaries as $row ) {
			$result = '';
			if ( ! empty( $row['error'] ) ) {
				$result = $row['error'];
			} elseif ( ! empty( $row['note'] ) ) {
				$result = $row['note'];
			}
			printf(
				'<tr><td>%s</td><td>%d</td><td>%d</td><td>%d</td><td>%d</td><td>%d</td><td>%s</td><td>%s</td></tr>',
				esc_html( $row['current_date_and_time'] ?? '' ),
				(int) ( $row['number_of_listings_imported'] ?? 0 ),
				(int) ( $row['number_of_properties_written'] ?? 0 ),
				(int) ( $row['number_of_subproperties_written'] ?? 0 ),
				(int) ( $row['number_of_properties_deleted'] ?? 0 ),
				(int) ( $row['time_duration'] ?? 0 ),
				! empty( $row['dry_run'] ) ? 'yes' : 'no',
				esc_html( $result )
			);
		}
		echo '</tbody></table>';
	}

	private function render_credentials_form() {
		echo '<h2>API credentials</h2>';
		if ( ! $this->settings->credentials_from_option() ) {
			echo '<p>Credentials are provided by <code>wp-config.php</code> constants or environment variables. Nothing to enter here.</p>';
			return;
		}
		echo '<form method="post" action="">';
		wp_nonce_field( 'cd_save_credentials' );
		echo '<p><label>API Username (email): <input type="text" name="cd_username" value="' . esc_attr( $this->settings->get_email() ) . '" class="regular-text"></label></p>';
		echo '<p><label>API Password: <input type="password" name="cd_password" value="" class="regular-text" autocomplete="new-password"></label></p>';
		echo '<p><button type="submit" name="cd_save_credentials" value="1" class="button">Save credentials</button></p>';
		echo '</form>';
	}
}

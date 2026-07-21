<?php
/*
 * Plugin Name: Visia Cresco Dealius
 * Description: Imports active commercial real estate listings from the Dealius API into a single `dealius_property` post type. Owns the data model (CPT + ACF fields) and the import/sync pipeline.
 * Version: 1.0.0
 * Author: Visia Marketing
 * License: GPL-2.0+
 * Text Domain: cd-dealius
 *
 * Replaces the legacy two-post-type plugin (th-cd-cresco-dealius). One `dealius_property`
 * post per Dealius property; units/suites live as ACF repeater rows on the property.
 */

namespace Cresco\Dealius;

if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'CD_DEALIUS_VERSION', '1.0.0' );
define( 'CD_DEALIUS_FILE', __FILE__ );
define( 'CD_DEALIUS_DIR', plugin_dir_path( __FILE__ ) );
define( 'CD_DEALIUS_URL', plugin_dir_url( __FILE__ ) );

require_once CD_DEALIUS_DIR . 'includes/helpers.php';
require_once CD_DEALIUS_DIR . 'includes/class-cd-logger.php';
require_once CD_DEALIUS_DIR . 'includes/class-cd-settings.php';
require_once CD_DEALIUS_DIR . 'includes/class-cd-model.php';
require_once CD_DEALIUS_DIR . 'includes/class-cd-api-client.php';
require_once CD_DEALIUS_DIR . 'includes/class-cd-lookups.php';
require_once CD_DEALIUS_DIR . 'includes/class-cd-rollups.php';
require_once CD_DEALIUS_DIR . 'includes/class-cd-repository.php';
require_once CD_DEALIUS_DIR . 'includes/class-cd-property-writer.php';
require_once CD_DEALIUS_DIR . 'includes/class-cd-importer.php';
require_once CD_DEALIUS_DIR . 'includes/class-cd-admin.php';

/**
 * Main plugin container. Lazily constructs collaborators and wires up hooks.
 *
 * This is intentionally lean — it replaces the old `th` framework (container /
 * data_holder / conf). The only surface the theme depends on is the API token
 * accessor + the API client, exposed via the global cd_dealius_property().
 */
final class Plugin {

	const CRON_HOOK = 'cd_dealius_daily_sync';

	/** @var Plugin|null */
	private static $instance = null;

	/** @var Settings */
	private $settings;
	/** @var Logger */
	private $logger;
	/** @var Api_Client */
	private $api_client;
	/** @var Lookups */
	private $lookups;
	/** @var Repository */
	private $repository;
	/** @var Importer */
	private $importer;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->logger     = new Logger();
		$this->settings   = new Settings();
		$this->api_client = new Api_Client( $this->settings, $this->logger );
		$this->lookups    = new Lookups( $this->api_client );
		$this->repository = new Repository( $this->logger );
		$this->importer   = new Importer( $this->api_client, $this->lookups, $this->repository, $this->logger );
	}

	public function run() {
		// Register the data model (CPT + ACF local JSON). Owned by the plugin.
		Model::instance()->register();

		// Admin settings page + manual run button.
		( new Admin( $this->importer, $this->settings, $this->logger ) )->register();

		// WP-CLI: `wp cresco import`.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once CD_DEALIUS_DIR . 'includes/class-cd-cli.php';
			\WP_CLI::add_command( 'cresco', new CLI( $this->importer ) );
		}

		// The import runs ONLY via a real server cron (e.g. a MyKinsta scheduled
		// task running `wp cresco import`). We do not self-schedule a WP-Cron event;
		// clear any legacy one left by earlier versions so it can't double-run.
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/* ---- Accessors the theme/filter rely on -------------------------------- */

	/** @return Api_Client */
	public function get_api_client() {
		return $this->api_client;
	}

	/** Convenience accessor used by the theme for token-gated document/image URLs. */
	public function get_api_token() {
		return $this->api_client->get_token();
	}

	/** @return Importer */
	public function get_importer() {
		return $this->importer;
	}

	/** @return Lookups */
	public function get_lookups() {
		return $this->lookups;
	}
}

/**
 * Global accessor. The theme uses cd_dealius_property()->get_api_token() and
 * cd_dealius_property()->get_api_client()->get_listing_by_id( $id ).
 *
 * @return Plugin
 */
function instance() {
	return Plugin::instance();
}

// Activation/deactivation: keep rewrite rules in sync with the CPT.
register_activation_hook( __FILE__, function () {
	require_once CD_DEALIUS_DIR . 'includes/class-cd-model.php';
	// init has already fired at activation time, so register the CPT directly
	// before flushing so the 'properties' rewrite slug is picked up.
	Model::instance()->register_post_type();
	flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( Plugin::CRON_HOOK );
	flush_rewrite_rules();
} );

Plugin::instance()->run();

// The global accessor cd_dealius_property() is defined in includes/helpers.php
// (root namespace) so the theme/filter can call it without a `use` import.

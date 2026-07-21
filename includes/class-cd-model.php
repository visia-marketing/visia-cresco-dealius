<?php
/**
 * The data model — owned by this plugin so a theme switch can never wipe it.
 *
 * Registers the single `dealius_property` CPT and points ACF at the local
 * acf-json field group (units repeater, listingbrokers repeater, building fields).
 *
 * Kept in its own class so it could later be lifted into a standalone foundation
 * plugin without dragging the importer along.
 */

namespace Cresco\Dealius;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Model {

	const POST_TYPE = 'dealius_property';

	/** Reference: the representative Dealius MainPropertyID for the building. */
	const META_PROPERTY_ID = 'MainPropertyID';

	/**
	 * Idempotency key for one building = OriginPropertyID (falls back to PropertyID).
	 * Sale + lease records of the same building share an OriginPropertyID, so this
	 * collapses them into a single property post.
	 */
	const META_IDENTITY = 'building_key';

	/** @var Model|null */
	private static $instance = null;

	/** @var bool */
	private $registered = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register() {
		if ( $this->registered ) {
			return;
		}
		$this->registered = true;

		add_action( 'init', array( $this, 'register_post_type' ) );

		// Tell ACF where this plugin's field group JSON lives (load only; saving
		// stays in whatever location the site configures).
		add_filter( 'acf/settings/load_json', array( $this, 'register_acf_json_path' ) );
	}

	public function register_post_type() {
		$labels = array(
			'name'               => __( 'Properties', 'cd-dealius' ),
			'singular_name'      => __( 'Property', 'cd-dealius' ),
			'menu_name'          => __( 'Properties', 'cd-dealius' ),
			'add_new_item'       => __( 'Add New Property', 'cd-dealius' ),
			'edit_item'          => __( 'Edit Property', 'cd-dealius' ),
			'new_item'           => __( 'New Property', 'cd-dealius' ),
			'view_item'          => __( 'View Property', 'cd-dealius' ),
			'search_items'       => __( 'Search Properties', 'cd-dealius' ),
			'not_found'          => __( 'No properties found', 'cd-dealius' ),
			'all_items'          => __( 'All Properties', 'cd-dealius' ),
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => $labels,
				'public'       => true,
				'has_archive'  => true,
				'show_in_rest' => true,
				'menu_icon'    => 'dashicons-building',
				'supports'     => array( 'title', 'editor', 'thumbnail', 'custom-fields' ),
				// Preserve existing /properties/... URLs even though the key changed.
				'rewrite'      => array(
					'slug'       => 'properties',
					'with_front' => false,
				),
			)
		);
	}

	/**
	 * @param array $paths
	 * @return array
	 */
	public function register_acf_json_path( $paths ) {
		$paths[] = CD_DEALIUS_DIR . 'acf-json';
		return $paths;
	}
}

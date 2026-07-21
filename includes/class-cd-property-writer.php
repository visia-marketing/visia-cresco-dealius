<?php
/**
 * Writes ONE property group (all active listings sharing a MainPropertyID) into a
 * single `dealius_property` post: building meta + units repeater (deduped) +
 * broker repeater + rollup meta + featured image.
 *
 * Supports a dry-run mode that computes everything but writes nothing.
 */

namespace Cresco\Dealius;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Property_Writer {

	/** @var Api_Client */
	private $api;
	/** @var Lookups */
	private $lookups;
	/** @var Rollups */
	private $rollups;
	/** @var Repository */
	private $repository;
	/** @var Logger */
	private $logger;

	public function __construct( Api_Client $api, Lookups $lookups, Rollups $rollups, Repository $repository, Logger $logger ) {
		$this->api        = $api;
		$this->lookups    = $lookups;
		$this->rollups    = $rollups;
		$this->repository = $repository;
		$this->logger     = $logger;
	}

	/**
	 * @param string $building_key      identity (OriginPropertyID, or PropertyID fallback)
	 * @param string $main_property_id   representative PropertyID for the API fetch
	 * @param array  $listings           full listing-detail arrays for this building (sale + lease)
	 * @param bool   $dry_run
	 * @return array summary for logging
	 */
	public function write_group( $building_key, $main_property_id, array $listings, $dry_run = false ) {
		$property = $this->api->get_property( $main_property_id );
		if ( ! $property ) {
			$this->logger->log( 'Skipping building ' . $building_key . ' — property record ' . $main_property_id . ' not found.' );
			return array(
				'property_id' => $main_property_id,
				'skipped'     => true,
			);
		}
		$details = $this->api->get_property_details( $main_property_id );
		$details = is_array( $details ) ? $details : array();
		$spaces  = $this->api->get_property_spaces( $main_property_id );

		$title       = $this->build_title( $property, $main_property_id );
		$parent_type = $this->lookups->property_type_name( cd_arr_get( $property, 'PropertyTypeID' ) );

		$units      = $this->build_units( $listings, $spaces, $parent_type );
		$brokers    = $this->build_brokers( $listings );
		$rollups    = $this->compute_rollups( $listings, $units );

		$listing_ids = array();
		$documents   = array();
		$comments    = '';
		foreach ( $listings as $listing ) {
			if ( isset( $listing['ListingID'] ) ) {
				$listing_ids[] = $listing['ListingID'];
			}
			$docs = cd_arr_get( $listing, 'Documents' );
			if ( is_array( $docs ) ) {
				$documents = array_merge( $documents, $docs );
			}
			if ( '' === $comments ) {
				$notes = trim( (string) cd_arr_get( $listing, 'Notes', '' ) );
				if ( '' !== $notes ) {
					$comments = $notes;
				}
			}
		}
		$listing_ids = array_values( array_unique( $listing_ids ) );

		$summary = array(
			'building_key' => $building_key,
			'property_id'  => $main_property_id,
			'title'        => $title,
			'units'        => count( $units ),
			'brokers'      => count( $brokers ),
			'listings'     => count( $listings ),
			'availability' => $rollups['availability'],
			'price'        => array( $rollups['price_min'], $rollups['price_max'] ),
			'size'         => array( $rollups['size_min'], $rollups['size_max'] ),
		);

		if ( $dry_run ) {
			$this->logger->log( 'DRY-RUN building ' . $building_key, $summary );
			return $summary;
		}

		$post_id = $this->repository->upsert_post( $building_key, $main_property_id, $title );
		if ( ! $post_id ) {
			return array(
				'property_id' => $main_property_id,
				'error'       => 'upsert failed',
			);
		}

		$this->write_building_meta( $post_id, $property, $details );
		$this->write_rollup_meta( $post_id, $rollups );

		update_post_meta( $post_id, 'associated_listing_ids', $listing_ids );
		update_post_meta( $post_id, 'documents', $documents );
		update_post_meta( $post_id, 'comments', $comments );
		update_post_meta( $post_id, 'origin_property_id', cd_arr_get( $property, 'OriginPropertyID' ) );

		$this->write_repeaters( $post_id, $units, $brokers );

		$image_url = cd_arr_get( $property, 'ImageFileUrl' );
		update_post_meta( $post_id, 'imageurl', $image_url );
		if ( $image_url ) {
			// Dealius images are token-gated; fetch with the token, but dedup on the
			// raw URL so re-imports don't re-download when the image is unchanged.
			$token     = $this->api->get_token();
			$fetch_url = $token ? add_query_arg( 'token', $token, $image_url ) : $image_url;
			$this->repository->update_featured_image( $post_id, $fetch_url, $image_url );
		}

		$summary['post_id'] = $post_id;
		return $summary;
	}

	/* ---- building meta ----------------------------------------------------- */

	private function build_title( array $property, $main_property_id ) {
		$name = trim( (string) cd_arr_get( $property, 'Name', '' ) );
		if ( '' !== $name ) {
			return $name;
		}
		$address = trim( (string) cd_arr_get( $property, 'AddressLine1', '' ) );
		if ( '' !== $address ) {
			return $address;
		}
		return (string) $main_property_id;
	}

	private function write_building_meta( $post_id, array $property, array $details ) {
		$type_id   = cd_arr_get( $property, 'PropertyTypeID' );
		$type_name = $this->lookups->property_type_name( $type_id );

		$citystatezip = trim(
			sprintf(
				'%s, %s %s',
				cd_arr_get( $property, 'City', '' ),
				cd_arr_get( $property, 'StateAbbreviatedName', '' ),
				cd_arr_get( $property, 'Zip', '' )
			),
			', '
		);

		$meta = array(
			'name'                     => cd_arr_get( $property, 'Name' ),
			'address'                  => cd_arr_get( $property, 'AddressLine1' ),
			'citystatezipcalc'         => $citystatezip,
			'latitude'                 => cd_arr_get( $property, 'Latitude' ),
			'longitude'                => cd_arr_get( $property, 'Longitude' ),
			'propertytype'             => $type_name,
			'sf'                       => cd_arr_get( $property, 'BuildingSizeSf' ),
			'parcelnumber'             => cd_arr_get( $property, 'ParcelNumber' ),
			'level'                    => cd_arr_get( $property, 'Level' ),
			// Core building facts.
			'year_built'               => cd_arr_get( $property, 'YearBuilt' ),
			'units_count'              => cd_arr_get( $property, 'NumberOfUnits' ),
			'county'                   => cd_arr_get( $property, 'CountyName' ),
			'submarket'                => $this->lookups->submarket_name( cd_arr_get( $property, 'SubmarketID' ) ),
			// from /details
			'landacres'                => cd_arr_get( $details, 'Acres' ),
			'industrialsf'             => cd_arr_get( $details, 'IndustrialSF' ),
			'officesf'                 => cd_arr_get( $details, 'OfficeSF' ),
			'retailsf'                 => cd_arr_get( $details, 'RetailsSF' ),
			'landsf'                   => cd_arr_get( $details, 'LandSF' ),
			'contiguousmaxsfavailable' => cd_arr_get( $details, 'MaxContiguousSF' ),
			'floorsnumber'             => cd_arr_get( $details, 'NumberOfFloors' ),
			'class'                    => cd_arr_get( $details, 'BuildingClass' ),
			'heat'                     => cd_arr_get( $details, 'Heat' ),
			'ac'                       => cd_arr_get( $details, 'AC' ),
			'gas'                      => cd_arr_get( $details, 'Gas' ),
			'sewer'                    => cd_arr_get( $details, 'Sewer' ),
			'water'                    => cd_arr_get( $details, 'Water' ),
			'zoning'                   => cd_arr_get( $details, 'Zoning' ),
			'primaryuse'               => cd_arr_get( $details, 'PrimaryUse' ),
			'secondaryuse'             => cd_arr_get( $details, 'SecondaryUse' ),
		);

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}
	}

	private function write_rollup_meta( $post_id, array $rollups ) {
		foreach (
			array(
				'availability'            => $rollups['availability'],
				'listingtype'             => $rollups['listingtype'],
				'askingprice'             => $rollups['price_min'],
				'askingrate'              => $rollups['asking_rate'],
				'price_min'               => $rollups['price_min'],
				'price_max'               => $rollups['price_max'],
				'rate_min'                => $rollups['rate_min'],
				'rate_max'                => $rollups['rate_max'],
				'listing_askingrate_min'  => $rollups['rate_min'],
				'listing_askingrate_max'  => $rollups['rate_max'],
				'size_min'                => $rollups['size_min'],
				'size_max'                => $rollups['size_max'],
				'lease_sf_min'            => $rollups['lease_sf_min'],
				'lease_sf_max'            => $rollups['lease_sf_max'],
				'total_available_sf'      => $rollups['total_available_sf'],
			) as $key => $value
		) {
			update_post_meta( $post_id, $key, $value );
		}
	}

	/* ---- units ------------------------------------------------------------- */

	/**
	 * Build deduped unit rows across all listings on the property. Each row carries
	 * suite + size + per-unit property type + listing type (Sale/Lease).
	 *
	 * @param array  $listings
	 * @param array  $spaces      keyed by PropertySpaceID
	 * @param string $parent_type fallback property type from the building record
	 * @return array list of [ 'suite', 'size', 'property_type', 'listing_type' ]
	 */
	private function build_units( array $listings, array $spaces, $parent_type ) {
		$rows = array();

		foreach ( $listings as $listing ) {
			$listing_type = $this->rollups->listing_type( $listing ); // 'Sale' | 'Lease'

			$properties = cd_arr_get( $listing, 'Properties' );
			if ( ! is_array( $properties ) ) {
				continue;
			}

			// Leasable/available units are the Level-2+ spaces (the suites). The Level-1
			// entry is the building record itself — its SpaceAvailableSf is the whole
			// building and would inflate a lease range. We fall back to the whole
			// building ONLY for a Sale listing (you buy the whole building); for a
			// Lease with no suites we show no units rather than the building total.
			$suites   = array();
			$building = null;
			foreach ( $properties as $candidate ) {
				if ( (int) cd_arr_get( $candidate, 'Level' ) === 1 ) {
					if ( null === $building ) {
						$building = $candidate;
					}
				} else {
					$suites[] = $candidate;
				}
			}
			if ( ! empty( $suites ) ) {
				$spaces_for_units = $suites;
			} elseif ( $building && 'Sale' === $listing_type ) {
				$spaces_for_units = array( $building );
			} else {
				$spaces_for_units = array();
			}

			foreach ( $spaces_for_units as $space ) {
				if ( (int) cd_arr_get( $space, 'Status' ) === 4 ) {
					continue; // Status 4 = deleted in Dealius.
				}

				$size  = (float) cd_arr_get( $space, 'SpaceAvailableSf', 0 );
				$suite = trim( (string) cd_arr_get( $space, 'AddressLine2', '' ) );

				// Per-unit property type: the space type if known, else the building type.
				$space_id      = cd_arr_get( $space, 'PropertySpaceID' );
				$property_type = ( $space_id && ! empty( $spaces[ $space_id ]['SpaceTypeName'] ) )
					? (string) $spaces[ $space_id ]['SpaceTypeName']
					: (string) $parent_type;

				if ( '' === $suite && $space_id && ! empty( $spaces[ $space_id ]['Name'] ) ) {
					$suite = trim( (string) $spaces[ $space_id ]['Name'] );
				}

				// Useless row: no label and no size.
				if ( '' === $suite && $size <= 0 ) {
					continue;
				}

				$key = '' !== $suite
					? 'suite:' . cd_normalize_suite( $suite )
					: 'space:' . cd_arr_get( $space, 'PropertyID', uniqid() );

				if ( isset( $rows[ $key ] ) ) {
					if ( $size > $rows[ $key ]['size'] ) {
						$this->logger->log(
							'Unit dedupe: suite "' . $suite . '" seen with sizes '
							. $rows[ $key ]['size'] . ' and ' . $size . '; keeping larger.'
						);
						$rows[ $key ]['size'] = $size;
					}
					// If the same suite appears under both Sale and Lease, mark it Both.
					if ( $listing_type !== $rows[ $key ]['listing_type'] && '' !== $rows[ $key ]['listing_type'] ) {
						$rows[ $key ]['listing_type'] = 'Both';
					}
					continue;
				}

				$rows[ $key ] = array(
					'suite'         => $suite,
					'size'          => $size,
					'property_type' => $property_type,
					'listing_type'  => $listing_type,
				);
			}
		}

		return array_values( $rows );
	}

	/* ---- brokers ----------------------------------------------------------- */

	/**
	 * Merge brokers across the group's listings, dedupe by ContactID, lead first.
	 *
	 * @param array $listings
	 * @return array list of [ contact_id, broker_name, broker_email, sortorder ]
	 */
	private function build_brokers( array $listings ) {
		$by_contact = array();

		foreach ( $listings as $listing ) {
			$brokers = cd_arr_get( $listing, 'Brokers' );
			if ( ! is_array( $brokers ) ) {
				continue;
			}
			foreach ( $brokers as $broker ) {
				$contact_id = cd_arr_get( $broker, 'ContactID' );
				if ( null === $contact_id ) {
					continue;
				}
				if ( ! isset( $by_contact[ $contact_id ] ) ) {
					$by_contact[ $contact_id ] = array(
						'contact_id'   => $contact_id,
						'broker_name'  => trim( cd_arr_get( $broker, 'FirstName', '' ) . ' ' . cd_arr_get( $broker, 'LastName', '' ) ),
						'broker_email' => cd_arr_get( $broker, 'Email', '' ),
						'is_lead'      => (bool) cd_arr_get( $broker, 'IsLead', false ),
					);
				} elseif ( cd_arr_get( $broker, 'IsLead', false ) ) {
					$by_contact[ $contact_id ]['is_lead'] = true;
				}
			}
		}

		$brokers = array_values( $by_contact );
		usort(
			$brokers,
			function ( $a, $b ) {
				return ( $b['is_lead'] <=> $a['is_lead'] );
			}
		);

		$rows = array();
		foreach ( $brokers as $i => $broker ) {
			$rows[] = array(
				'contact_id'   => $broker['contact_id'],
				'broker_name'  => $broker['broker_name'],
				'broker_email' => $broker['broker_email'],
				'sortorder'    => $i,
			);
		}
		return $rows;
	}

	/* ---- rollups ----------------------------------------------------------- */

	private function compute_rollups( array $listings, array $units ) {
		$unit_sizes = wp_list_pluck( $units, 'size' );

		// Sizes of units offered for lease (a Both unit counts as lease-available too).
		$lease_sizes = array();
		foreach ( $units as $unit ) {
			$lt = isset( $unit['listing_type'] ) ? $unit['listing_type'] : '';
			if ( 'Lease' === $lt || 'Both' === $lt ) {
				$lease_sizes[] = $unit['size'];
			}
		}

		list( $price_min, $price_max ) = $this->rollups->price_range( $listings );
		list( $rate_min, $rate_max )   = $this->rollups->rate_range( $listings );
		list( $size_min, $size_max )   = $this->rollups->size_range( $unit_sizes );
		list( $lease_min, $lease_max ) = $this->rollups->size_range( $lease_sizes );

		return array(
			'availability'       => $this->rollups->availability( $listings ),
			'listingtype'        => $this->rollups->listing_type_string( $listings ),
			'asking_rate'        => $this->rollups->asking_rate( $listings ),
			'price_min'          => $price_min,
			'price_max'          => $price_max,
			'rate_min'           => $rate_min,
			'rate_max'           => $rate_max,
			'size_min'           => $size_min,
			'size_max'           => $size_max,
			'lease_sf_min'       => $lease_min,
			'lease_sf_max'       => $lease_max,
			'total_available_sf' => $this->rollups->total_size( $unit_sizes ),
		);
	}

	/* ---- repeaters (ACF) --------------------------------------------------- */

	private function write_repeaters( $post_id, array $units, array $brokers ) {
		if ( ! function_exists( 'update_field' ) ) {
			$this->logger->log( 'ACF update_field() unavailable — units/brokers not written. Is ACF Pro active?' );
			return;
		}
		update_field( 'units', $units, $post_id );
		update_field( 'listingbrokers', $brokers, $post_id );
	}
}

<?php

class Bullhorn_Connection {

	/**
	 * Stores the settings for the connection, including the client ID, client
	 * secret, etc.
	 *
	 * @var array
	 */
	protected static $settings;

	/**
	 * Stores the credentials we need for logging into the API (access token,
	 * refresh token, etc.).
	 *
	 * @var array
	 */
	protected static $api_access;

	/**
	 * Stores the session variable we need in requests to Bullhorn.
	 *
	 * @var string
	 */
	protected static $session;

	/**
	 * Stores the URL we need to make requests to (includes the corpToken).
	 *
	 * @var string
	 */
	protected static $url;

	/**
	 * Array to cache the categories retrieved from bullhorn.
	 *
	 * @var array
	 */
	private static $categories = array();

	//protected $settings;

	/**
	 * Constructor that just gets and sets the settings/access arrays.
	 *
	 * @return \Bullhorn_Connection
	 */
	public function __construct() {
		self::$settings   = get_option( 'bullhorn_settings' );
		self::$api_access = get_option( 'bullhorn_api_access' );
	}

	/**
	 * This should be the only method that is called externally, as it handles
	 * all processing of jobs from Bullhorn into WordPress.
	 *
	 * @param $throw bool
	 * @throws Exception
	 * @return boolean
	 */
	public static function sync( $throw = true ) {

		$logged_in = self::login();
		if ( ! $logged_in ) {
			if ( $throw ) {
				throw new Exception( __( 'There was a problem logging into the Bullhorn API.', 'bh-staffing-job-listing-and-cv-upload-for-wp' ) );
			} else {
				return __( 'There was a problem logging into the Bullhorn API.', 'bh-staffing-job-listing-and-cv-upload-for-wp' );
			}
		}

		wp_defer_term_counting( true );

		$response = self::get_categories_from_bullhorn();
		if ( is_wp_error( $response ) ) {
			if ( $throw ) {
				error_log( 'Get categories failed: ' . serialize( $response->get_error_message() ) );
			} else {
				return __( 'Get categories failed: ', 'bh-staffing-job-listing-and-cv-upload-for-wp' ) . serialize( $response->get_error_message() );
			}
		}

		if ( apply_filters( 'bullhorn_sync_specialties', false ) ) {
			$response = self::get_specialties_from_bullhorn();

			if ( is_wp_error( $response ) ) {
				if ( $throw ) {
					error_log( 'Get specialties failed: ' . serialize( $response->get_error_message() ) );
				} else {
					return __( 'Get specialties failed: ', 'bh-staffing-job-listing-and-cv-upload-for-wp' ) . serialize( $response->get_error_message() );
				}
			}
		}

		$response = self::get_skills_from_bullhorn();
		if ( is_wp_error( $response ) ) {
			if ( $throw ) {
				error_log( 'Get skills failed: ' . serialize( $response->get_error_message() ) );
			} else {
				return __( 'Get skills failed: ', 'bh-staffing-job-listing-and-cv-upload-for-wp' ) . serialize( $response->get_error_message() );
			}
		}

		$jobs = self::get_jobs_from_bullhorn();

		if ( is_wp_error( $jobs ) ) {
			return __( 'Get Jobs failed: ' . serialize( $jobs ) );
		}

		$existing = self::get_existing();
		// move job on in current job list
		self::remove_old( $jobs );

		if ( count( $jobs ) ) {
			foreach ( $jobs as $job ) {

				if ( 'Archive' !== $job->status ) {
					if ( isset( $existing[ $job->id ] ) ) {
						self::sync_job( $job, $existing[ $job->id ] );
					} else {
						self::sync_job( $job );
					}
				}
			}
		}

		wp_defer_term_counting( false );

		return true;
	}

	/**
	 * This allows our application to log into the API so we can get the session
	 * and corpToken to use in subsequent requests.
	 *
	 * @throws Exception
	 * @return boolean
	 */
	protected static function login() {
		$cache_id    = 'bullhorn_token';
		$cache_token = get_transient( $cache_id );
		if ( false === $cache_token ) {

			if ( false === self::refresh_token() ) {

				return false;
			};

			$url = add_query_arg(
				array(
					'version'      => '*',
					'access_token' => self::$api_access['access_token'],
					'ttl'          => 20,
				), 'https://rest.bullhornstaffing.com/rest-services/login'
			);

			$response = self::request( $url );
			$body     = json_decode( $response['body'] );

			// TODO: make to user freindly
			if ( isset( $body->errorMessage ) ) {
				$admin_email = get_option( 'admin_email' );
				error_log( 'Login failed. With the message:' . $body->errorMessage );
				$headers = 'From: Bullhorn Plugin <' . $admin_email . '>';
				wp_mail( $admin_email, 'Login failed please reconnect ', 'With the message: ' . $body->errorMessage, $headers );
				throw new Exception( $body->errorMessage );

				return false;
			}

			if ( isset( $body->BhRestToken ) ) {
				self::$session = $body->BhRestToken;
				self::$url     = $body->restUrl;
				set_transient( $cache_id, $body, MINUTE_IN_SECONDS * 8 );

				return true;
			}
		} else {
			if( ! is_object( $cache_token) ) {
				$cache_token   = json_decode( $cache_token );
			}

			self::$session = $cache_token->BhRestToken;
			self::$url     = $cache_token->restUrl;

			return true;
		}

		return false;
	}

	/**
	 * Every 10 minutes we need to refresh our access token for continued access
	 * to the API. We first determine if we need to refresh, and then we need to
	 * request a new token from Bullhorn if our current one has expired.
	 *
	 * @return boolean
	 */
	protected static function refresh_token ( $force = false ) {
		//	error_log( 'refresh token start' );
		// TODO: stop re-calling every time
        $eight_mins_ago = strtotime( '8 minutes ago' );
        if ( false !== $force && $eight_mins_ago <= self::$api_access['last_refreshed'] ) {
			error_log( 'refresh token last refreshed'  . self::$api_access['last_refreshed'] );
			return true;
		}

        // ok lets not do this if we have already done it in the last 20 sec
        if ( false !== get_transient( 'get_bullhorn_token' ) ){

	        return true;
        }
		set_transient( 'get_bullhorn_token', self::$api_access['last_refreshed'], 20 );

		// TODO: return false if client not set and add handlers for the call
		if (
			null === self::$api_access['refresh_token'] ||
			null === self::$settings['client_id'] ||
			null === self::$settings['client_secret']
		) {
			add_action( 'admin_notices', array( __CLASS__, 'no_token_admin_notice' ) );

			return false;
		}

		$url = add_query_arg(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => self::$api_access['refresh_token'],
				'client_id'     => self::$settings['client_id'],
				'client_secret' => self::$settings['client_secret'],
			), 'https://auth.bullhornstaffing.com/oauth/token'
		);

		$response = wp_remote_post( $url );

		if ( ! is_array( $response ) ) {

			return false;
		}
		$body = json_decode( $response['body'], true );

		if ( isset( $body['access_token'] ) ) {
			$body['last_refreshed'] = time();
			update_option( 'bullhorn_api_access', $body );
			self::$api_access = $body;
			// Sleep for a Quarter Second. Bullhorn's Login servers sometimes sync slower
			// slower than our script runs. This sleep should minimize login failures.
			usleep( 250000 );
			return true;
		} elseif ( isset( $body['error_description'] ) ) {

			wp_die( $body['error_description'] );
		}

		return false;
	}
	/**
	 * This retreives all available categories from Bullhorn.
	 *
	 * @return error|bool
	 */
	private static function get_categories_from_bullhorn () {
		//TODO: cache this
		$url    = self::$url . 'options/Category';
		$params = array(
			'BhRestToken' => self::$session,
		);

		$response = self::request( $url . '?' . http_build_query( $params ), false );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = json_decode( $response['body'] );
		if ( isset( $body->data ) ) {
			foreach ( $body->data as $category ) {
				wp_insert_term( $category->label, Bullhorn_2_WP::$taxonomy_listing_category );
			}
		}

		return true;
	}

	/**
	 * This retreives all available Skills from Bullhorn.
	 *
	 * @return error|bool
	 */
	private static function get_skills_from_bullhorn() {

		$url = self::$url . 'query/Skill';
		$url = add_query_arg( $params = array(
			'BhRestToken' => self::$session,
			'where'       => 'enabled=true',
			'fields'      => '*',
		), $url );

		$response = self::request( $url, false );
		if ( is_wp_error( $response ) ) {

			return $response;
		}
		$body = json_decode( $response['body'] );
		if ( isset( $body->data ) ) {
			foreach ( $body->data as $category ) {
				$args = array( 'description' => __( 'Synced with Bullhorn', 'bh-staffing-job-listing-and-cv-upload-for-wp' ) );
				wp_insert_term( $category->name, Bullhorn_2_WP::$taxonomy_listing_skills, $args );
			}
		}

		return true;
	}

	/**
	 * This retreives all available specialties from Bullhorn.
	 *
	 * @return error|bool
	 */
	private static function get_specialties_from_bullhorn() {
		$url = self::$url . 'query/Specialties';
		$url = add_query_arg( $params = array(
			'BhRestToken' => self::$session,
			'where'       => 'enabled=true',
			'fields'      => '*',
		), $url );

		$response = self::request( $url, false );
		if ( is_wp_error( $response ) ) {

			return $response;
		}
		$body = json_decode( $response['body'] );
		if ( isset( $body->data ) ) {
			foreach ( $body->data as $category ) {
				wp_insert_term( $category->label, 'bullhorn_specialties' );
			}
		}

		return true;
	}


	public static function no_token_admin_notice () {
		printf( '<div class="error"> <p>%s</p></div>', esc_html__( 'Error in saving', 'bh-staffing-job-listing-and-cv-upload-for-wp' ) );
	}

	/**
	 * Gets the description field as chosen by the user in settings.
	 *
	 * @return string
	 */
	private static function get_description_field () {
		if ( isset( self::$settings['description_field'] ) ) {
			$description = self::$settings['description_field'];
		} else {
			$description = 'description';
		}

		return $description;
	}

	/**
	 * Bullhorn Users may have Custom Entity fields.
	 *
	 * Get list of custom mapped job fields.
	 * @return string
	 */
	private static function get_custom_mapped_job_field_list ($settings = null) {
		if (!isset($settings)) {
			if (!isset(self::$settings)) {
				$settings = (array) get_option( 'bullhorn_settings' );
			} else {
				$settings = self::$settings;
			}
		}

		$custom_job_field_raw = $settings['custom_job_fields'];

		if (empty($custom_job_field_raw)) {
			$custom_job_field_list = array();
		} else {
			$custom_job_field_list = preg_split("/[,\s]/", $custom_job_field_raw);
		}

		// Remove empty or invalid fields
		$custom_job_field_list = array_filter($custom_job_field_list, function($value) {
			if ($value !== null &&
				preg_match('/\w+/', $value)) {
				return true;
			}
			return false;
		});

		return implode(',', $custom_job_field_list);
	}

	/**
	 * This retreives all available jobs from Bullhorn.
	 *
	 * @return array
	 */
	private static function get_jobs_from_bullhorn ($args = array()) {
		// Use the specified description field if set, otherwise the default
		$description = self::get_description_field();

		$where = 'isPublic=1 AND isOpen=true AND isDeleted=false';

		$settings = apply_filters( 'wp_bullhorn_settings', (array) get_option( 'bullhorn_settings' ) );

		if ( isset( $settings['is_public'] ) ) {
			$is_public = $settings['is_public'];
			if ( 'false' === $is_public ) {
				$where = 'isOpen=true AND isDeleted=false';
			}
		}

		$custom_mapped_fields = self::get_custom_mapped_job_field_list($settings);

		$fields_addendum = '';
		if (!empty($custom_mapped_fields)) $fields_addendum = ',' . $custom_mapped_fields;

		$start = 0;
		$page  = 100;
		$jobs  = array();
		while ( true ) {
			$url    = self::$url . 'query/JobOrder';
			$params = array(
				'BhRestToken' => self::$session,
				'fields'      => 'id,title,' . $description . ',dateAdded,dateEnd,categories,address,benefits,salary,educationDegree,employmentType,yearsRequired,clientCorporation,degreeList,skillList,bonusPackage,status,skills,payRate,taxStatus,travelRequirements,willRelocate,certificationList,notes' . $fields_addendum,
				'where'       => $where,
				'count'       => $page,
				'start'       => $start,
			);

			if ( isset( self::$settings['client_corporation'] ) and ! empty( self::$settings['client_corporation'] ) ) {
				$ids = explode( ',', self::$settings['client_corporation'] );
				$ids = array_map( 'trim', $ids );

				$params['where'] .= ' AND (clientCorporation.id=' . implode( ' OR clientCorporation.id=', $ids ) . ')';
			}

			$response = self::request( $url . '?' . http_build_query( $params ), false );

			if ( is_wp_error( $response ) ) {

				return $response;
			}

			$body = json_decode( $response['body'] );

			if ( isset( $body->data ) ) {
				$start += $page;

				$jobs = array_merge( $jobs, $body->data );

				if ( count( $body->data ) < $page ) {
					break;
				}
			} else {
				break;
			}
		}

		return $jobs;
	}

	/**
	 * This makes a job search request to Bullhorn.
	 *
	 * @return array
	 */
	public function bullhorn_job_search ($args = array()) {
		// Use the specified description field if set, otherwise the default
		$description = self::get_description_field();

		$settings = (array) get_option( 'bullhorn_settings' );

		$custom_mapped_fields = self::get_custom_mapped_job_field_list($settings);

		$fields_addendum = '';
		if (!empty($custom_mapped_fields)) $fields_addendum = ',' . $custom_mapped_fields;

		$query = $args['query']; // This should be a required item.
		$start = isset($args['start']) ? $args['start'] : 0;
		$page = isset($args['count']) ? $args['count'] : 100;
		$jobs  = array();

		$logged_in = self::login();
		if ( ! $logged_in ) {
			return new WP_error(
				__( 'Careers postings were unavailabe, refresh to try again.', 'bh-staffing-job-listing-and-cv-upload-for-wp' ),
				"Connection Error",
				array( 'status' => 500 )
			);
		}

		$url    = self::$url . 'search/JobOrder';

		$params = array(
			'BhRestToken' => self::$session,
			'fields'      => 'id,title,' . $description . ',dateAdded,dateEnd,categories,address,benefits,salary,educationDegree,employmentType,yearsRequired,clientCorporation,degreeList,skillList,bonusPackage,status,skills,payRate,taxStatus,travelRequirements,willRelocate,certificationList,notes' . $fields_addendum,
			'query'       => $query,
			'count'       => $page,
			'start'       => $start,
		);

		if (isset($args['groupBy'])) $params['groupBy'] = $args['groupBy'];
		if (isset($args['orderBy'])) $params['orderBy'] = $args['orderBy'];
		if (isset($args['fields'])) $params['fields'] = $args['fields'];
		if (isset($args['sort'])) $params['sort'] = $args['sort'];
		if (isset($args['showTotalMatched'])) $params['showTotalMatched'] = $args['showTotalMatched'];

		if ( isset( self::$settings['client_corporation'] ) and ! empty( self::$settings['client_corporation'] ) ) {
			$ids = explode( ',', self::$settings['client_corporation'] );
			$ids = array_map( 'trim', $ids );

			$params['where'] .= ' AND (clientCorporation.id=' . implode( ' OR clientCorporation.id=', $ids ) . ')';
		}

		error_log('search request uri ' . print_r( $url . '?' . http_build_query( $params ), true ));

		$response = self::request( $url . '?' . http_build_query( $params ), false );

		if ( is_wp_error( $response ) ) {
			if ($response->get_error_code() == 401) {
				$cache_id    = 'bullhorn_token';
				$cache_token = delete_transient( $cache_id );
			}
			return $response;
		}

		$body = json_decode( $response['body'] );

		if (isset($body->errorCode) && $body->errorCode == 401) {
			$cache_token = delete_transient( 'bullhorn_token' );
		}

		return $body;
	}

	/**
	 * This makes a job query request to Bullhorn.
	 *
	 * @return array
	 */
	public function bullhorn_job_query ($args = array()) {
		// Use the specified description field if set, otherwise the default
		$description = self::get_description_field();

		$settings = (array) get_option( 'bullhorn_settings' );
		$custom_mapped_fields = self::get_custom_mapped_job_field_list($settings);

		if (isset($args['where'])) {
			$where = $args['where'];
		} else {
			// Default to search for Public Open, filter deleted.
			$where = 'isPublic=1 AND isOpen=true AND isDeleted=false';

			if ( isset( $settings['is_public'] ) ) {
				$is_public = $settings['is_public'];
				if ( 'false' === $is_public ) {
					$where = 'isOpen=true AND isDeleted=false';
				}
			}
		}

		$fields_addendum = '';
		if (!empty($custom_mapped_fields)) $fields_addendum = ',' . $custom_mapped_fields;

		$start = isset($args['start']) ? $args['start'] : 0;
		$page = isset($args['count']) ? $args['count'] : 100;
		$jobs  = array();

		$logged_in = self::login();
		if ( ! $logged_in ) {
			return new WP_error(
				__( 'Careers postings were unavailabe, refresh to try again.', 'bh-staffing-job-listing-and-cv-upload-for-wp' ),
				"Connection Error",
				array( 'status' => 500 )
			);
		}

		$url    = self::$url . 'query/JobBoardPost';

		$params = array(
			'BhRestToken' => self::$session,
			'fields'      => 'id,title,' . $description . ',dateAdded,categories,address,benefits,salary,educationDegree,employmentType,yearsRequired,clientCorporation,degreeList,skillList,bonusPackage,status,skills,payRate,taxStatus,travelRequirements,willRelocate,certificationList,notes' . $fields_addendum,
			'where'       => $where,
			'count'       => $page,
			'start'       => $start,
		);

		if (isset($args['groupBy'])) $params['groupBy'] = $args['groupBy'];
		if (isset($args['orderBy'])) $params['orderBy'] = $args['orderBy'];
		if (isset($args['fields'])) $params['fields'] = $args['fields'];

		if ( isset( self::$settings['client_corporation'] ) and ! empty( self::$settings['client_corporation'] ) ) {
			$ids = explode( ',', self::$settings['client_corporation'] );
			$ids = array_map( 'trim', $ids );

			$params['where'] .= ' AND (clientCorporation.id=' . implode( ' OR clientCorporation.id=', $ids ) . ')';
		}

		error_log('query request uri ' . $url . '?' . print_r(http_build_query( $params ), true));

		$response = self::request( $url . '?' . http_build_query( $params ), false );

		if ( is_wp_error( $response ) ) {
			if ($response->get_error_code() == 401) {
				$cache_id    = 'bullhorn_token';
				$cache_token = delete_transient( $cache_id );
			}
			return $response;
		}

		$body = json_decode( $response['body'] );

		if (isset($body->errorCode) && $body->errorCode == 401) {
			$cache_token = delete_transient( 'bullhorn_token' );
		}

		return $body;
	}


	/**
	 * This will take a job object from Bullhorn and insert it into WordPress
	 * with the proper fields, custom fields, and taxonomy relationships. If
	 * the job already exists in WordPress it simply updates the fields.
	 *
	 * @param      $job
	 * @param null $id
	 *
	 * @return bool
	 * @throws Exception
	 */
	private static function sync_job( $job, $id = null ) {
		global $post;
		$description = self::get_description_field();

		$post_args = array(
			'post_title'   => $job->title,
			'post_content' => $job->{$description},
			'post_type'    => Bullhorn_2_WP::$post_type_job_listing,
			'post_status'  => apply_filters( 'wp_bullhorn_new_job_status', 'publish' ),
			'post_date'    => date( 'Y-m-d 00:00:01', $job->dateAdded / 1000 ),
		);

		if ( null !== $id ) {
			$post_args['ID'] = $id;
			wp_update_post( $post_args );
		} else {
			$id = wp_insert_post( $post_args );
		}


		$address = (array) $job->address;
		unset( $address['countryID'] );

		//categories
		$categories = array();
		if ( $job->categories->total <= 5 ) {
			foreach ( $job->categories->data as $category ) {
				$categories[] = $category->name;
			}
		} else {

			$url    = self::$url . 'entity/JobOrder/' . $job->id . '/categories';
			$params = array(
				'BhRestToken' => self::$session,
				'fields'      => '*',
			);

			$response = self::request( $url . '?' . http_build_query( $params ), false );

			$body = json_decode( $response['body'] );

			foreach ( $body->data as $category ) {
				$categories[] = $category->name;
			}
		}
		wp_set_object_terms( $id, $categories, Bullhorn_2_WP::$taxonomy_listing_category );

		//skills
		$skills = array();
		if ( $job->skills->total <= 5 ) {
			foreach ( $job->skills->data as $skill ) {
				$skills[] = $skill->name;
			}
		} else {

			$url    = self::$url . 'entity/JobOrder/' . $job->id . '/skills';
			$params = array(
				'BhRestToken' => self::$session,
				'fields'      => '*',
			);

			$response = self::request( $url . '?' . http_build_query( $params ), false );

			$body = json_decode( $response['body'] );

			foreach ( $body->data as $skill ) {
				$skills[] = $skill->name;
			}
		}
		wp_set_object_terms( $id, $skills, Bullhorn_2_WP::$taxonomy_listing_skills );

		//certifications
		$certifications = array();
		if ( $job->certificationList ) {
			$certifications = $job->certificationList;
		}
		wp_set_object_terms( $id, $certifications, Bullhorn_2_WP::$taxonomy_listing_certifications );

		wp_set_object_terms( $id, array( $job->address->state ), Bullhorn_2_WP::$taxonomy_listing_state );

		if( null !== Bullhorn_2_WP::$taxonomy_listing_type ){
			wp_set_object_terms( $id, array( $job->employmentType ), Bullhorn_2_WP::$taxonomy_listing_type );
		}

		$create_json_ld = self::create_json_ld( $job, $categories, self::get_custom_mapped_job_field_list());

		error_log("adding more values to meta " .print_r($create_json_ld, true));

		foreach ( $create_json_ld as $key => $val ) {
			update_post_meta( $id, $key, $val );
		}

		$city = isset( $create_json_ld['jobLocation']['address']['addressLocality'] ) ? $create_json_ld['jobLocation']['address']['addressLocality'] : '';
		$state = isset( $create_json_ld['jobLocation']['address']['addressRegion'] ) ? $create_json_ld['jobLocation']['address']['addressRegion'] : '';
		$country = isset( $create_json_ld['jobLocation']['address']['addressCountry'] ) ? $create_json_ld['jobLocation']['address']['addressCountry'] : '';
		$zip = isset( $create_json_ld['jobLocation']['address']['postalCode'] ) ? $create_json_ld['jobLocation']['address']['postalCode'] : '';

		$comma = ( $city && $state ) ? ', ' : '';
		$space = ( $city || $state ) && $zip ? ' ' : '';
		$dash = (( $city || $state || $zip ) && $country) ? ' - ' : '';

		if ( $city ) {
			update_post_meta( $id, 'city', $city );
		}
		if ( $state ) {
			update_post_meta( $id, 'state', $state );
		}
		if ( $country ) {
			update_post_meta( $id, 'Country', $country );
		}
		if ( $zip ) {
			update_post_meta( $id, 'zip', $zip );
		}

		//some fields wp job manager uses
		$location = sprintf( '%s%s%s%s%s%s%s', $city, $comma, $state, $space, $zip, $dash, $country );
		if ( $location ) {
			update_post_meta( $id, '_job_location', $location );
		}

		if ( isset( $create_json_ld['hiringOrganization']['name'] ) ) {
			update_post_meta( $id, '_company_name', $create_json_ld['hiringOrganization']['name'] );
		}

		if ( isset( $create_json_ld['validThrough'] ) ) {

			try {
				$date = new DateTime( $create_json_ld['validThrough'] );
				$job_expires = $date->format( 'Y-m-d' );
				update_post_meta( $id, '_job_expires', $job_expires );
			} catch (Exception $e) {
				error_log( $e->getMessage() );
			}
		}

		if ( isset( $create_json_ld['hiringOrganization']['url'] ) ) {
			update_post_meta( $id, '_company_website', $create_json_ld['hiringOrganization']['url'] );
		}

		$custom_fields = array(
			'bullhorn_job_id'      => $job->id,
			'bullhorn_job_address' => implode( ' ', $address ),
			'bullhorn_json_ld'     => $create_json_ld,
			'employmentType'       => $job->employmentType,
			'baseSalary'           => $job->salary,
			'educationDegree'      => $job->educationDegree,
			'payRate'              => $job->payRate,
			'taxStatus'            => $job->taxStatus,
			'travelRequirements'   => $job->travelRequirements,
			'willRelocate'         => $job->willRelocate,
			'yearsRequired'        => $job->yearsRequired,
		);

		foreach ( $custom_fields as $key => $val ) {
			update_post_meta( $id, $key, $val );
		}

		do_action('bullhorn_sync_complete', $id, $job );

		return true;
	}

	private static function create_json_ld ( $job, $categories, $custom_fields = "" ) {
		$description = self::get_description_field();
		$address     = (array) $job->address;
		$custom_field_list = array_filter(explode(',', $custom_fields), function($value) {
			if ($value !== null &&
				preg_match('/\w+/', $value)) {
				return true;
			}
			return false;
		});

		$ld                                    = array();
		$ld['@context']                        = 'http://schema.org';
		$ld['@type']                           = 'JobPosting';
		$ld['title']                           = $job->title;
		$ld['description']                     = $job->{$description};
		$ld['datePosted']                      = self::format_date_to_8601( $job->dateAdded );
		$ld['occupationalCategory']            = implode( ',', $categories );
		$ld['jobLocation']['@type']            = 'Place';
		$ld['jobLocation']['address']['@type'] = 'PostalAddress';
		$ld['validThrough']                    = self::format_date_to_8601( $job->dateEnd );
		$ld['hiringOrganization']['@type']     = 'Organization';
		$ld['educationRequirements']           = $job->educationDegree;
		$ld['employmentType']                  = $job->employmentType;


		if ( ! empty( $address['city'] ) ) {
			$ld['jobLocation']['address']['addressLocality'] = $address['city'];
		}
		if ( ! empty( $address['state'] ) ) {
			$ld['jobLocation']['address']['addressRegion'] = $address['state'];
		}
		if ( ! empty( $address['zip'] ) ) {
			$ld['jobLocation']['address']['postalCode'] = $address['zip'];
		}
		if ( ! empty( $address['countryID'] ) ) {
			$ld['jobLocation']['address']['addressCountry'] = $address['countryID'];
		}
		if ( ! empty( $address['countryID'] ) ) {
			$addressCountry = self::get_country_name( $address['countryID'] );
			if ( false !== $addressCountry ) {
				$ld['jobLocation']['address']['addressCountry'] = $addressCountry;
			}
		}

		if ( isset( $job->clientCorporation->name ) ) {
			$ld['hiringOrganization']['name'] = $job->clientCorporation->name;
		}
		if ( isset( $job->clientCorporation->id ) ) {

			$url = self::get_hiring_organization_url( $job->clientCorporation->id );
			if ( false !== $url ) {
				$ld['hiringOrganization']['url'] = $url;
			}
		}
		if ( isset( $job->benefits ) && null !== $job->benefits ) {
			$ld['jobBenefits'] = $job->benefits;
		}
		if ( isset( $job->salary ) && 0 < $job->salary ) {
			$ld['baseSalary'] = $job->clientCorporation->name;
		}
		if ( isset( $job->yearsRequired ) ) {
			$ld['experienceRequirements'] = $job->yearsRequired;
		}
		if ( isset( $job->degreeList ) ) {
			$ld['educationRequirements'] = $job->degreeList;
		}
		if ( isset( $job->skillList ) ) {
			$ld['skills'] = $job->skillList;
		}
		if ( isset( $job->bonusPackage ) ) {
			$ld['incentiveCompensation'] = $job->bonusPackage;
		}

		foreach ($custom_field_list as $custom_field) {
			error_log('We are requesting custom field ' . print_r($custom_field, true));
			if (!isset($ld[$custom_field]) && isset($job->$custom_field)) {
				$ld[$custom_field] = $job->$custom_field;
			}
		}

		return $ld;
	}

	/**
	 * format the date
	 *
	 * @param $microtime
	 *
	 * @return string
	 * @internal param $date
	 *
	 */
	private static function format_date_to_8601 ( $microtime ) {
		$microtime = $microtime / 1000;
		// make sure the have a .00 in the date format
		if ( ! strpos( $microtime, '.' ) ) {
			$microtime = $microtime . '.00';
		}

		$utc = DateTime::createFromFormat( 'U.u', $microtime );

		return $utc->format( 'c' );
	}

	private static function get_country_name( $country_id ) {

		$country_list_id = 'bullhorn_country_list';

		$country_list = get_transient( $country_list_id );
		if ( false === $country_list || ! isset( $country_list[ $country_id ] ) ) {
			$url = add_query_arg(
				array(
					'BhRestToken' => self::$session,
					//   'fields'      => 'name',
					'count'       => '300',
				), self::$url . 'options/Country'// . absint( $country_id )
			);

			$response = wp_remote_get( $url, array( 'method' => 'GET' ) );

			if ( 200 === $response['response']['code'] ) {
				$body = wp_remote_retrieve_body( $response );
				$data = json_decode( $body, true );
				if ( ! isset( $data['data'] ) ) {
					return false;
				}
				$data = $data['data'];

				$country_list = array();
				foreach ( $data as $key ) {
					$country_list[ $key['value'] ] = $key['label'];
				}

				set_transient( $country_list_id, $country_list, HOUR_IN_SECONDS * 1 );
			}
		}
		if ( isset( $country_list[ $country_id ] ) ) {
			return $country_list[ $country_id ];
		}

		return _x( '- None Specified -', ' no county set', 'bh-staffing-job-listing-and-cv-upload-for-wp' );
	}

	private static function get_hiring_organization_url( $organization_id ) {

		$cached_organization_url = get_transient( 'bullhorn_organization_id#' . $organization_id );

		if ( $cached_organization_url ) {
			return $cached_organization_url;
		}

		$url      = self::$url . 'entity/ClientCorporation/' . $organization_id;
		$params   = array( 'BhRestToken' => self::$session, 'fields' => 'companyURL' );
		$response = self::request( $url . '?' . http_build_query( $params ) );

		if ( 200 === $response['response']['code'] ) {
			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );
			if ( ! isset( $data['data']['companyURL'] ) || empty( $data['data']['companyURL'] ) ) {
				return false;
			} else {
				set_transient( 'bullhorn_organization_id#' . $organization_id, $data['data']['companyURL'], HOUR_IN_SECONDS * 1 );
				return $data['data']['companyURL'];
			}
		} else {
			return false;
		}
	}

	/**
	 * Before we start adding in new jobs, we need to delete jobs that are no
	 * longer in Bullhorn.
	 *
	 * @param  array $jobs
	 *
	 * @return boolean
	 */
	private static function remove_old ( $jobs ) {
		$ids = array();
		foreach ( $jobs as $job ) {
			if ( 'Archive' !== $job->status ) {
				$ids[] = $job->id;
			}
		}

		$jobs = new WP_Query( array(
			'post_type'      => Bullhorn_2_WP::$post_type_job_listing,
			'post_status'    => 'any',
			'posts_per_page' => 500,
			'meta_query'     => array(
				'relation' => 'OR',
				array(
					'key'     => 'bullhorn_job_id',
					'compare' => 'NOT IN',
					'value'   => $ids,
				),
				array(
					'key'     => 'bullhorn_job_id',
					'compare' => 'EXISTS', // works!
					'value'   => '', // This is ignored, but is necessary...
				),
			),
		) );

		if ( $jobs->have_posts() ) {
			while ( $jobs->have_posts() ) {
				$jobs->the_post();

				// Don't trash post, actually delete it
				wp_delete_post( get_the_ID(), true );
			}
		}

		return true;
	}

	/**
	 * Gets an array of IDs for existing jobs in the WordPress CPT.
	 *
	 * @return array
	 */
	private static function get_existing () {
		global $wpdb;
		//TODO: change this the WP_QUERY meta select
		$posts = $wpdb->get_results( "SELECT $wpdb->posts.id, $wpdb->postmeta.meta_value FROM $wpdb->postmeta JOIN $wpdb->posts ON $wpdb->posts.id = $wpdb->postmeta.post_id WHERE meta_key = 'bullhorn_job_id'", ARRAY_A );

		$existing = array();
		foreach ( $posts as $post ) {
			$existing[ $post['meta_value'] ] = $post['id'];
		}

		return $existing;
	}

	/**
	 * Wrapper around wp_remote_get() so any errors are reported to the screen.
	 *
	 * @param $url
	 *
	 * @return array
	 * @throws Exception
	 */
	public static function request ( $url, $throw = true ) {
		$response = wp_remote_get( $url, array( 'timeout' => 180 ) );
		if ( is_wp_error( $response ) ) {
			if ( $throw ) {
				throw new Exception( $response->get_error_message() );
			} else {
				return $response;
			}
		}

		return $response;
	}
}

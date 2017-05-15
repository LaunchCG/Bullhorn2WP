<?php
/**
 * Plugin Name: WP REST API
 * Description: JSON-based REST API for WordPress, originally developed as part of GSoC 2013.
 * Author: WP REST API Team
 * Author: Launch Consulting Group
 * Author URI: http://v2.wp-api.org
 * Version: 2.0-beta15
 * Plugin URI: https://github.com/WP-API/WP-API
 * License: GPL2+
 */

/**
 * BH_REST_Controller class.
 */
if ( ! class_exists( 'BH_REST_Controller' ) ) {
	// require_once dirname( __FILE__ ) . '/api/class-bh-rest-controller.php';
}

/**
 * BH_REST_Jobs_Controller class.
 */
if ( ! class_exists( 'BH_REST_Jobs_Controller' ) ) {
	require_once dirname( __FILE__ ) . '/api/class-bh-rest-jobs-controller.php';
}

add_action( 'rest_api_init', 'bh_create_initial_rest_routes');


if ( ! function_exists( 'bh_create_initial_rest_routes' ) ) {
	/**
	 * Registers default REST API routes.
	 *
	 * @since 4.4.0
	 */
	function bh_create_initial_rest_routes() {
		// Is that it?.
		$controller = new BH_REST_Jobs_Controller();
		$controller->register_routes();
	}
}

if ( ! function_exists( 'rest_authorization_required_code' ) ) {
	/**
	 * Returns a contextual HTTP error code for authorization failure.
	 *
	 * @return integer
	 */
	function rest_authorization_required_code() { // I don't intend to require login for this endpoint
		return is_user_logged_in() ? 403 : 401;
	}
}

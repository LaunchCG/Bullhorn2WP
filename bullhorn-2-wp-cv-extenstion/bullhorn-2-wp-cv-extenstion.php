<?php
/*
Plugin Name: Fork form Marketing Press Bullhorn Integration Resume/Candidate Extension
Plugin URI: http://bullhorntowordpress.com / https://github.com/pbearne/Bullhorn2WP
Description: This plugin is an extension for the Marketing Press Bullhorn Integration plugin that allows for resume uploads and candidate creation. Pull request acepted at https://github.com/pbearne/Bullhorn2WP
Version: 2.0
Author: Paul Beanre and Marketing Press
Author URI: http://marketingpress.com / https://github.com/pbearne/
License: GPL2
*/

require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';
require_once plugin_dir_path( __FILE__ ) . 'bullhorn.php';
require_once plugin_dir_path( __FILE__ ) . 'settings.php';
require_once plugin_dir_path( __FILE__ ) . 'shortcode.php';


/**
 * Plugin Hooks
 */
# add_action('admin_init'   , 'child_plugin_has_parent_plugin');
add_filter( 'query_vars', 'add_query_vars' );
add_action( 'init', 'add_endpoint' );
add_action( 'parse_request', 'sniff_requests' );


/**
 * Checks to make ure that the parent plugin 'Bullhorn2WP' is activated.
 *
 * @return void
 */
function child_plugin_has_parent_plugin() {
	if ( is_admin() && current_user_can( 'activate_plugins' ) && ! is_plugin_active( 'bullhorn-2-wp/bullhorn-2-wp.php' ) ) {
		add_action( 'admin_notices', 'child_plugin_notice' );

		deactivate_plugins( plugin_basename( __FILE__ ) );

		if ( isset( $_GET['activate'] ) ) {
			unset( $_GET['activate'] );
		}
	}
}

/**
 * This function is called by function child_plugin_has_parent_plugin()
 * if the parent plugin is not active
 *
 * @return void
 */
function child_plugin_notice() {
	?>
	<div class="error"><p>Sorry, but 'Marketing Press Bullhorn Integration Resume/Candidate Extension' requires 'Marketing Press Bullhorn Integration' to be installed and active.</p></div><?php
}

/**
 * Update vars
 *
 * @param $vars
 *
 * @return array
 */
function add_query_vars( $vars ) {
	$vars[] = '__api';
	$vars[] = 'endpoint';

	return $vars;
}

/**
 * Initialize the reqrite rule
 *
 * @return void
 */
function add_endpoint() {
	add_rewrite_rule( '^api/bullhorn/([^/]+)/?', 'index.php?__api=1&endpoint=$matches[1]', 'top' );
}

/**
 * Check to see if the request is a bullhorn API request
 *
 * @return void
 */
function sniff_requests() {
	global $wp;
	if ( isset( $wp->query_vars['__api'] ) && isset( $wp->query_vars['endpoint'] ) ) {
		switch ( $wp->query_vars['endpoint'] ) {
			case 'resume':

				if (
					! isset( $_POST['bullhorn_cv_form'] )
					|| ! wp_verify_nonce( $_POST['bullhorn_cv_form'], 'bullhorn_cv_form' )
				) {
					print 'Sorry, your nonce did not verify.';
					die();

				}
				$bullhorn = new Bullhorn_Extended_Connection;

				// Get Resume
				$resume = $bullhorn->parseResume();

				// Create candidate
				$candidate = $bullhorn->createCandidate( $resume );

				// Attach education to candidate
				$bullhorn->attachEducation( $resume, $candidate );

				// Attach work history to candidate
				$bullhorn->attachWorkHistory( $resume, $candidate );

				// Attach resume file to candidate
				$bullhorn->attachResume( $candidate );

				// link to job
				$bullhorn->link_candidate_to_job( $candidate );

				// Redirect
				$settings  = (array) get_option( 'bullhorn_extension_settings' );
				$permalink = add_query_arg( array(
					'bh_applied' => true,
				),	get_permalink( $settings['thanks_page'] ) );

				header( "location: $permalink" );
				exit;

				break;
			default:
				$response = array(
					'status' => 404,
					'error'  => 'The endpoint you are trying to reach does not exist.'
				);
				echo json_encode( $response );
		}
		exit;
	}
}
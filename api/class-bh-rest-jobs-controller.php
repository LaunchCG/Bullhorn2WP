<?php

class BH_REST_Jobs_Controller extends WP_REST_Controller {

	/**
	 * Register Bullhorn API routes
	 */
	public function register_routes() {
		register_rest_route(
			'bh-api/v1',
			'jobs',
			array(
				'methods'  => WP_REST_Server::READABLE,
				'callback' => array( $this, 'get_all_jobs' )
			)
		);

		register_rest_route(
			'bh-api/v1',
			'jobs-passthrough',
			array(
				'methods'  => WP_REST_Server::READABLE,
				'callback' => array( $this, 'bullhorn_passthrough_request' )
			)
		);

		register_rest_route(
			'bh-api/v1',
			'search',
			array(
				'methods'  => WP_REST_Server::READABLE,
				'callback' => array( $this, 'bullhorn_passthrough_search' )
			)
		);

		register_rest_route(
			'bh-api/v1',
			'query',
			array(
				'methods'  => WP_REST_Server::READABLE,
				'callback' => array( $this, 'bullhorn_passthrough_query' )
			)
		);

		register_rest_route(
			'bh-api/v1',
			'jobs/(?P<id>\d+)',
			array(
				'methods'  => WP_REST_Server::READABLE,
				'callback' => array( $this, 'get_job_details' )
			)
		);
	}

	public function prepare_item_for_response( $item, $request ) {
		$ld_extract = $item['bullhorn_json_ld'];
		unset($item['bullhorn_json_ld']);
		if (!isset($item['publicDescription'])) {
			$item['publicDescription'] = isset($item['description']) ? $item['description'] : "";
		}
		$item = array_merge( $ld_extract, $item );

		return $this->filter_descriptor_properties($item);
	}

	public function prepare_response_for_collection( $itemdata ) {
		return $itemdata;
	}

	public function get_items($request) {
		foreach( array() as $item ) {
			$itemdata = $this->prepare_item_for_response( $item, $request ); // Probably applies metadata to an item
			$data[] = $this->prepare_response_for_collection( $itemdata ); // Places that item inside a collection?
		}

		return new WP_REST_RESPONSE( array(
			'_links' => array(
				'self' => $request
			),
			'data' => $data
			), 200 );
	}

	public function bullhorn_passthrough_search($request) {
		// Params
		$params = array();
		
		//if ($request['query']) { $query = $request['query']; }
		if ($request['fields']) { $params['fields'] = $request['fields']; }
		if ($request['query']) { $params['query'] = $request['query']; }
		if ($request['start']) { $params['start'] = $request['start']; }
		if ($request['orderBy']) { $params['orderBy'] = $request['orderBy']; }
		if ($request['groupBy']) { $params['groupBy'] = $request['groupBy']; }
		if ($request['sort']) { $params['sort'] = $request['sort']; }
		if ($request['showTotalMatched']) { $params['showTotalMatched'] = $request['showTotalMatched']; }
		if ($request['count']) { $params['count'] = $request['count']; }

		if (!$params['query']) {
			return new WP_Error(
				"Query is required url parameter for this request.",
				'Invalid query',
				array( 'status' => 400 )
			);
		}

		$bullhorn_connection = new Bullhorn_Connection();
		
		$results = $bullhorn_connection->bullhorn_job_search($params);

		return new WP_REST_RESPONSE( $results, 200 );
	}

	public function bullhorn_passthrough_query($request) {
		// Params
		$params = array();
		
		//if ($request['query']) { $query = $request['query']; }
		if ($request['fields']) { $params['fields'] = $request['fields']; }
		if ($request['where']) { $params['where'] = $request['where']; }
		if ($request['start']) { $params['start'] = $request['start']; }
		if ($request['orderBy']) { $params['orderBy'] = $request['orderBy']; }
		if ($request['groupBy']) { $params['groupBy'] = $request['groupBy']; }
		if ($request['count']) { $params['count'] = $request['count']; }

		$bullhorn_connection = new Bullhorn_Connection();

		$results = $bullhorn_connection->bullhorn_job_query($params);

		return new WP_REST_RESPONSE( $results, 200 );
	}

	public function bullhorn_passthrough_request($request) {


		// Params
		$query = $request['query'];
		$fields = $request['fields'];
		$where = $request['where'];
		$start = $request['start'] or $start = 0;
		$count = $request['count'] or $count = 50;
		$orderBy = $request['orderBy'];
		$showTotalMatched = $request['showTotalMatched'];
		$groupBy = $request['groupBy'];
	}

	/**
	 * Get all jobs request.
	 */
	public function get_all_jobs($request) {
		$settings = (array) get_option( 'bullhorn_settings' );
		$custom_job_fields = '';

		if ( isset( $settings['custom_job_fields'] ) ) {
			$custom_job_fields = $settings['custom_job_fields'];
		}

		$jobController = new bh_jobs_controller();

		$jobs = $jobController->find();
		$columns = $jobController->getColumnList();
		$data = array();

		// Params
		$query = $request['query'];
		$fields = $request['fields'];
		$where = $request['where'];
		$start = $request['start'] or $start = 0;
		$count = $request['count'] or $count = 50;
		$orderBy = $request['orderBy'];
		$showTotalMatched = $request['showTotalMatched'];
		$groupBy = $request['groupBy'];

		//$query_params = extract_query_params($query);

		global $wp;
		$current_url = add_query_arg( $wp->query_string, '', home_url( $wp->request ) );

		foreach( $jobs as $item ) {
			$itemdata = $this->prepare_item_for_response( $item, $request ); // Probably applies metadata to an item
			$data[] = $this->prepare_response_for_collection( $itemdata ); // Places that item inside a collection?
		}

		return new WP_REST_RESPONSE( array(
			'_links' => array(
				'self' => $current_url,
				'query' => $request['query'],
				'fields' => $columns,
				'etc' => array(
					$query,
					$fields,
					$where,
					$start,
					$count,
					$orderBy,
					$showTotalMatched,
					$groupBy,
				)
			),
			'data' => $data
			), 200 );
	}

	/**
	 * Extract a query params object from a formatted bullhorn api query request string
	 */
	private function extract_query_params($query_string) {
		$matches = array();
		preg_match_all("/(?:([\w,]*)/i", $query_string, $matches);
		return $matches;
	}

	public function get_job_details( WP_REST_Request $request ) {
	  // You can access parameters via direct array access on the object:
		$param = $request['id'];
	 
	 /*
	  // Or via the helper method:
	 $param = $request->get_param( 'some_param' );
	 
	  // You can get the combined, merged set of parameters:
	 $parameters = $request->get_params();
	 
	  // The individual sets of parameters are also available, if needed:
	 $parameters = $request->get_url_params();
	  $parameters = $request->get_query_params();
	  $parameters = $request->get_body_params();
	  $parameters = $request->get_json_params();
	  $parameters = $request->get_default_params();
	 
	  // Uploads aren't merged in, but can be accessed separately:
	 $parameters = $request->get_file_params();
	 */

		return $param . " Job";
	}

	private function filter_descriptor_properties($subject) {
		//if ($key == null) return null;
		$filtered_list = array();

		if (is_array($subject)) {
			foreach ($subject as $key => $item) {
				if (!empty($key) && $key[0] != '@') {
					$filtered_list[$key] = $this->filter_descriptor_properties($item);
				}
			}
			return $filtered_list;
		}
		return $subject;
	}
}
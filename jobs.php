<?php

//use WP_Query;

class bh_jobs_controller {

	protected $custom_fields = array();

	public function __construct($custom_fields = array()) {
		$this->custom_fields = $custom_fields;

	}

	private static $bullhorn_default_columns = array(
		'bullhorn_job_id',
		'title',
		'content',
		'type',
		'show_date',
		'bullhorn_job_address',
		'bullhorn_json_ld',
		'employmentType',
		'baseSalary',
		'city',
		'state',
		'Country',
		'zip'
	);

/** bullhorn system field list, use this to compare against wp_posts request
'fields'      => 'id,title,' . $description . ',dateAdded,categories,address,benefits,salary,educationDegree,employmentType,yearsRequired,clientCorporation,degreeList,skillList,bonusPackage,status' . $fields_addendum,
*/

	public function getColumnList() {
		return self::$bullhorn_default_columns;
	}

	public function find($opts = array()) {

		$limit = isset($opts['limit']) ? $opts->limit : 50;
		$state = isset($opts['state']) ? $opts->state : null;
		$state = isset($opts['city']) ? $opts->city : null;
		$type = isset($opts['type']) ? $opts->type : null;
		$title = isset($opts['title']) ? $opts->title : null;

		$args = array(
			'post_type'      => 'bullhornjoblisting',
			'posts_per_page' => intval( $limit ),
			'tax_query'      => array(),
		);

		if ( $state ) {
			$args['tax_query'][] = array(
				'taxonomy' => 'bullhorn_state',
				'field'    => 'slug',
				'terms'    => sanitize_title( $state ),
			);
		}

		if ( $city ) {
			$args['tax_query'][] = array(
				'taxonomy' => 'bullhorn_city',
				'field'    => 'slug',
				'terms'    => sanitize_title( $city ),
			);
		}

		if ( $type ) {
			$args['tax_query'][] = array(
				'taxonomy' => 'bullhorn_category',
				'field'    => 'slug',
				'terms'    => sanitize_title( $type ),
			);
		}

		if ( $title ) {
			$args['post_title_like'] = $title;
		}

		$possible_fields = apply_filters(
			'bullhorn_shortcode_possible_fields_and_order',
			self::$bullhorn_default_columns
		);
		// TODO - add custom fields to possiblefield list

		$jobs = new \WP_Query( $args );
		$jobs_json = array();

		if ( $jobs->have_posts() ) {

			while ( $jobs->have_posts() ) {
				$jobs->the_post();
				$id = get_the_ID();

				$jobs_json[$id] = array();

				foreach ( $possible_fields as $possible_field ) {

					$meta_value = get_post_meta( $id, $possible_field, true );
					$jobs_json[$id][$possible_field] = $meta_value;
				}
			}
		}

		return $jobs_json;
	}
}
?>
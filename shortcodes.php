<?php

/**
 * Created by IntelliJ IDEA.
 * User: Paul
 * Date: 2015-12-04
 * Time: 1:29 PM
 */
namespace bullhorn_2_wp;


use WP_Query;

class Shortcodes {

	/**
	 * Shortcodes constructor.
	 */
	public function __construct() {

		add_shortcode( 'bullhorn_cv_form', array( __CLASS__, 'render_cv_form' ) );
		add_shortcode( 'bullhorn', array( __CLASS__, 'bullhorn_shortcode' ) );
		add_shortcode( 'bullhorn_categories', array( __CLASS__, 'bullhorn_categories' ) );
		add_shortcode( 'bullhorn_states', array( __CLASS__, 'bullhorn_states' ) );
		add_shortcode( 'bullhorn_search', array( __CLASS__, 'bullhorn_search' ) );

		add_filter( 'posts_where', array( __CLASS__, 'bullhorn_title_like_posts_where' ), 10, 2 );

		/**
		 * Added so shortcodes are processed in text widgets.
		 */
		add_filter( 'widget_text', 'do_shortcode' );
	}

	/**
	 * @return string
	 */
	public function render_cv_form() {
		$settings = (array) get_option( 'bullhorn_settings' );
		if ( isset( $settings['form_page'] ) && 0 < $settings['form_page'] ) {
			return sprintf( '<a href="%s" class="bullhorn-apply-here-link">%s</a>', esc_url( get_permalink( $settings['form_page'] ) ), __( 'Apply Here.', 'bullhorn' ) );
		}

		ob_start();
		?>
		<form id="bullhorn-resume" action="/api/bullhorn/resume" enctype="multipart/form-data" method="post">
			<label for="name">Name<span class="gfield_required"> *</span></label>
			<input id="name" name="name" type="text"/>
			<label for="email">Email<span class="gfield_required"> *</span></label>
			<input id="email" name="email" type="text"/>
			<label for="phone">Phone</label>
			<input id="phone" name="phone" type="text"/>
			<label for="fileToUpload">Your Resume<span class="gfield_required"> *</span></label>
			<input id="fileToUpload" name="resume" type="file"/>
			<br/><br/>
			<?php
			if ( isset( $_GET['position'] ) ) {
				printf( '<input id="position" name="position" type="hidden" value="%s" />',	esc_attr( $_GET['position'] ) );
			} elseif ( 'bullhornjoblisting' === get_post_type() ) {
				printf( '<input id="position" name="position" type="hidden" value="%s" />',	esc_attr( get_post_meta( get_the_ID(), 'bullhorn_job_id', true ) ) );
			}

			wp_nonce_field( 'bullhorn_cv_form', 'bullhorn_cv_form' );
			?>
			<input name="submit" type="submit" value="Upload Resume"/>
		</form>
		<script type="application/javascript">

			jQuery(document).ready( function () {
				var error_color = '#FFDFE0';
				var defaut_file_color = jQuery('#fileToUpload').css('background-color'); //'#fff';
				var defaut_color = jQuery('#email').css('background-color'); //'#d0eafa';
				jQuery('#bullhorn-resume').on('submit', function () {


					var $email = jQuery('#email'),
						$no_error = true,
						$name,
						$fileToUpload;

					if (( 3 > $email.val().length ) || !isValidEmailAddress($email.val())) {
						$email.css('background-color', error_color);
						$no_error = false;
					} else {
						$email.css('background-color', defaut_color);
					}
					$name = jQuery('#name');
					if (3 > $name.val().length) {
						$name.css('background-color', error_color);
						$no_error = false;
					} else {
						$name.css('background-color', defaut_color);
					}
					$fileToUpload = jQuery('#fileToUpload');
					if (3 > $fileToUpload.val().length) {
						$fileToUpload.css('background-color', error_color);
						$no_error = false;
					} else {
						$fileToUpload.css('background-color', defaut_file_color);
					}

					//	e.preventDefault();
					return $no_error;
				});

				function isValidEmailAddress(emailAddress) {
					var pattern = /^([a-z\d!#$%&'*+\-\/=?^_`{|}~\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]+(\.[a-z\d!#$%&'*+\-\/=?^_`{|}~\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]+)*|"((([ \t]*\r\n)?[ \t]+)?([\x01-\x08\x0b\x0c\x0e-\x1f\x7f\x21\x23-\x5b\x5d-\x7e\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]|\\[\x01-\x09\x0b\x0c\x0d-\x7f\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]))*(([ \t]*\r\n)?[ \t]+)?")@(([a-z\d\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]|[a-z\d\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF][a-z\d\-._~\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]*[a-z\d\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])\.)+([a-z\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]|[a-z\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF][a-z\d\-._~\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]*[a-z\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])\.?$/i;
					return pattern.test(emailAddress);
				}
			});
		</script>
		<?php
		$content = ob_get_contents();
		ob_end_clean();

		return $content;
	}

	/**
	 * Adds the shortcode for generating a list of Bullhorn job listings. It has the
	 * ability to filter by state, category, and partial matching of job titles.
	 *
	 * Example usages:
	 * [bullhorn] -- Default usage
	 * [bullhorn state="California" type="Contract"] -- Shows contract jobs in CA
	 * [bulllhorn limit=50 show_date=true] -- Shows 50 jobs with their posting date
	 * [bullhorn title="Intern"] -- Only shows jobs that have the word "Intern" in the title
	 *
	 * @param  array $atts
	 *
	 * @return string
	 */
	function bullhorn_shortcode( $atts ) {
		shortcode_atts( array(
			'limit'     => 5,
			'show_date' => false,
			'state'     => null,
			'type'      => null,
			'title'     => null,
			'columns'   => 1,
		), $atts );

		$output = null;

		$limit     = absint( $atts['limit'] );
		$show_date = (bool) $atts['show_date'];
		$state     = esc_attr( $atts['state'] );
		$type      = esc_attr( $atts['type'] );
		$title     = esc_attr( $atts['title'] );
		$columns   = absint( $atts['columns'] );

		// Only allow up to two columns for now
		if ( $columns > 4 or $columns < 1 ) {
			$columns = 1;
		}

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

		if ( isset( $_GET['bullhorn_state'] ) ) {
			$args['tax_query'][] = array(
				'taxonomy' => 'bullhorn_state',
				'field'    => 'slug',
				'terms'    => sanitize_key( $_GET['bullhorn_state'] ),
			);
		}

		if ( $type ) {
			$args['tax_query'][] = array(
				'taxonomy' => 'bullhorn_category',
				'field'    => 'slug',
				'terms'    => sanitize_title( $type ),
			);
		}

		if ( isset( $_GET['bullhorn_category'] ) ) {
			$args['tax_query'][] = array(
				'taxonomy' => 'bullhorn_category',
				'field'    => 'slug',
				'terms'    => sanitize_key( $_GET['bullhorn_category'] ),
			);
		}

		if ( $title ) {
			$args['post_title_like'] = $title;
		}

		$jobs = new \WP_Query( $args );
		if ( $jobs->have_posts() ) {
			$output .= '<ul class="bullhorn-listings">';
			while ( $jobs->have_posts() ) {
				$jobs->the_post();

				$output .= '<li>';
				$output .= '<a href="' . get_permalink() . '">' . get_the_title() . '</a>';
				if ( $show_date ) {
					$output .= ' posted on ' . get_the_date( 'F jS, Y' );
				}
				$output .= '</li>';
			}
			$output .= '</ul>';
		} else {
			$output .= '<p>Nothing Matches Your Search</p>';
		}

		$c = intval( $columns );
		$output .= '<style>';
		$output .= '.bullhorn-listings { -moz-column-count: ' . $c . '; -moz-column-gap: 20px; -webkit-column-count: ' . $c . '; -webkit-column-gap: 20px; column-count: ' . $c . '; column-gap: 20px; }';
		$output .= '</style>';
		$output .= '<!--[if lt IE 10]><style>.bullhorn-listings li { width: ' . ( 100 / $c ) . '%; float: left; }</style><![endif]-->';

		return $output;
	}


	/**
	 * Adds the ability to filter posts in WP_Query by post title.
	 *
	 * @param string   $where
	 * @param WP_Query $wp_query
	 *
	 * @return string
	 */
	function bullhorn_title_like_posts_where( $where, &$wp_query ) {
		global $wpdb;

		if ( $post_title_like = $wp_query->get( 'post_title_like' ) ) {
			$where .= ' AND ' . $wpdb->posts . '.post_title LIKE \'' . esc_sql( like_escape( $post_title_like ) ) . '%\'';
		}

		return $where;
	}


	/**
	 * Adds the shortcode for generating a list of Bullhorn states.
	 *
	 * @param  array $atts
	 *
	 * @return string
	 */
	function bullhorn_categories( $atts ) {
		if ( ! empty( $atts ) ) {
			_doing_it_wrong( __FUNCTION__, 'bullhorn categories Shortcode does not need attributes ', 2.0 );
		}
		$output = '<select onchange="if (this.value) window.location.href=this.value">';
		$output .= '<option value="">Filter by category...</option>';

		$categories = get_categories( array(
			'taxonomy'   => 'bullhorn_category',
			'hide_empty' => 0,
		) );
		foreach ( $categories as $category ) {
			$params = array( 'bullhorn_category' => $category->slug );
			if ( isset( $_GET['bullhorn_state'] ) ) {
				$params['bullhorn_state'] = $_GET['bullhorn_state'];
			}

			$selected = null;
			if ( isset( $_GET['bullhorn_category'] ) and $_GET['bullhorn_category'] === $category->slug ) {
				$selected = 'selected="selected"';
			}

			$output .= '<option value="?' . http_build_query( $params ) . '" ' . $selected . '>' . esc_html( $category->name ) . '</option>';
		}

		$output .= '</select>';

		return $output;
	}


	/**
	 * Adds the shortcode for generating a list of Bullhorn states.
	 *
	 * @param  array $atts
	 *
	 * @return string
	 */
	function bullhorn_states( $atts ) {
		if ( ! empty( $atts ) ) {
			_doing_it_wrong( __FUNCTION__, 'bullhorn categories Shortcode does not need attributes ', 2.0 );
		}
		$output = '<select onchange="if (this.value) window.location.href=this.value">';
		$output .= '<option value="">Filter by state...</option>';

		$states = get_categories( array(
			'taxonomy'   => 'bullhorn_state',
			'hide_empty' => 0,
		) );
		foreach ( $states as $state ) {
			$params = array( 'bullhorn_state' => $state->slug );
			if ( isset( $_GET['bullhorn_category'] ) ) {
				$params['bullhorn_category'] = $_GET['bullhorn_category'];
			}

			$selected = null;
			if ( isset( $_GET['bullhorn_state'] ) and $_GET['bullhorn_state'] === $state->slug ) {
				$selected = 'selected="selected"';
			}

			$output .= '<option value="?' . http_build_query( $params ) . '" ' . $selected . '>' . esc_html( $state->name ) . '</option>';
		}

		$output .= '</select>';

		return $output;
	}


	/**
	 * Adds the shortcode for searching job postings.
	 *
	 * @param  array $atts
	 *
	 * @return string
	 */
	function bullhorn_search( $atts ) {
		if ( ! empty( $atts ) ) {
			_doing_it_wrong( __FUNCTION__, 'bullhorn categories Shortcode does not need attributes ', 2.0 );
		}
		$form   = get_search_form( false );
		$hidden = '<input type="hidden" name="post_type" value="bullhornjoblisting" />';

		return str_replace( '</form>', $hidden . '</form>', $form );
	}
}

new Shortcodes();

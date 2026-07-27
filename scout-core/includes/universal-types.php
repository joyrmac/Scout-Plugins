<?php
/**
 * The content types every Scout site needs, confirmed across the Maravela's and
 * North Crest builds: Testimonials/Reviews and FAQs. (Business identity lives in
 * includes/business.php since it is site-level, not a post type.)
 *
 * Per-client types (Event, Menu, Amenity, Attorney, Practice Area...) belong in
 * a small companion plugin that calls scout_core_register_type() on this same
 * action. See platform/scout-rvpark for the worked example.
 *
 * @package Scout_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'scout_core_register', 'scout_core_register_universal_types' );

function scout_core_register_universal_types() {

	// Testimonial: title = author display name, editor = the quote,
	// featured image = a photo or a handwritten-card scan.
	scout_core_register_type(
		'testimonial',
		array(
			'singular'    => 'Testimonial',
			'plural'      => 'Testimonials',
			'menu_icon'   => 'dashicons-format-quote',
			'has_archive' => false,
			'supports'    => array( 'title', 'editor', 'thumbnail' ),
			'fields'      => array(
				'author_location' => array( 'label' => 'Author location', 'control' => 'text' ),
				'rating'          => array( 'label' => 'Rating (1-5)', 'control' => 'number' ),
				'source'          => array(
					'label'   => 'Source',
					'control' => 'select',
					'options' => array(
						'google'   => 'Google',
						'yelp'     => 'Yelp',
						'facebook' => 'Facebook',
						'direct'   => 'Direct / handwritten',
					),
				),
				'source_url'      => array( 'label' => 'Source URL', 'control' => 'url' ),
				'date'            => array( 'label' => 'Date (YYYY-MM-DD)', 'control' => 'text' ),
			),
		)
	);

	// FAQ: title = the question, editor = the answer. scout-schema reads both
	// for FAQPage markup, so no custom fields are needed here.
	scout_core_register_type(
		'faq',
		array(
			'singular'    => 'FAQ',
			'plural'      => 'FAQs',
			'menu_icon'   => 'dashicons-editor-help',
			'has_archive' => false,
			'supports'    => array( 'title', 'editor', 'page-attributes' ),
			'fields'      => array(),
		)
	);
}

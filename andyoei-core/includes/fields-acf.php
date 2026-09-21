<?php
/**
 * Field groups, registered in code so they are version controlled and deploy
 * with the plugin. Editors see normal ACF panels; nothing is stored in the
 * database as JSON to drift between environments.
 *
 * Requires ACF Pro (repeaters, relationships, taxonomy term fields). Without
 * it the post types and filters still work — only these panels disappear.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'acf/init', 'ao_register_fields' );

/**
 * Small helper so each field is one readable line.
 */
function ao_field( $name, $label, $type = 'text', $extra = array() ) {
	return array_merge( array(
		'key'   => 'field_' . $name,
		'name'  => $name,
		'label' => $label,
		'type'  => $type,
	), $extra );
}

function ao_group( $key, $title, $location, $fields, $extra = array() ) {
	acf_add_local_field_group( array_merge( array(
		'key'                   => 'group_' . $key,
		'title'                 => $title,
		'fields'                => $fields,
		'location'              => $location,
		'menu_order'            => 0,
		'position'              => 'normal',
		'style'                 => 'default',
		'hide_on_screen'        => array(),
		'active'                => true,
	), $extra ) );
}

function ao_location( $param, $value ) {
	return array( array( array( 'param' => $param, 'operator' => '==', 'value' => $value ) ) );
}

function ao_register_fields() {

	if ( ! function_exists( 'acf_add_local_field_group' ) ) {
		return;
	}

	// ── Curation: which records Andy features, and in what order ──────────
	$curation_types = array( 'ao_building', 'ao_sold', 'ao_press', 'ao_insight', 'ao_testimonial', 'ao_case_study' );
	$curation_loc   = array();

	foreach ( $curation_types as $type ) {
		$curation_loc[] = array( array( 'param' => 'post_type', 'operator' => '==', 'value' => $type ) );
	}

	ao_group( 'curation', 'Featured', $curation_loc, array(
		ao_field( 'ao_featured', 'Featured', 'true_false', array(
			'ui'            => 1,
			'instructions'  => 'Show this record in the featured strip.',
		) ),
		ao_field( 'ao_featured_rank', 'Featured Rank', 'number', array(
			'instructions'      => 'Display order within the featured strip. 1 shows first.',
			'min'               => 1,
			'conditional_logic' => array( array( array( 'field' => 'field_ao_featured', 'operator' => '==', 'value' => '1' ) ) ),
		) ),
	), array( 'position' => 'side', 'menu_order' => 5 ) );

	// ── 02B Individual condominium building page ──────────────────────────
	ao_group( 'building', 'Building Page', ao_location( 'post_type', 'ao_building' ), array(

		ao_field( 'ao_tab_facts', 'Facts', 'tab' ),
		ao_field( 'ao_address', 'Street Address', 'text', array(
			'instructions' => 'Included in directory search.',
		) ),
		ao_field( 'ao_year_built', 'Year Built', 'number' ),
		ao_field( 'ao_stories', 'Stories', 'number' ),
		ao_field( 'ao_units', 'Residences', 'number' ),
		ao_field( 'ao_building_logo', 'Building Logo', 'image', array(
			'return_format' => 'array',
			'instructions'  => 'Sits in the top right of the hero.',
		) ),

		ao_field( 'ao_tab_hero', 'Hero', 'tab' ),
		ao_field( 'ao_hero_media_type', 'Hero Media', 'button_group', array(
			'choices'       => array( 'image' => 'Image', 'video' => 'Video' ),
			'default_value' => 'image',
		) ),
		ao_field( 'ao_hero_image', 'Hero Image', 'image', array( 'return_format' => 'array' ) ),
		ao_field( 'ao_hero_video', 'Hero Video URL', 'url', array(
			'conditional_logic' => array( array( array( 'field' => 'field_ao_hero_media_type', 'operator' => '==', 'value' => 'video' ) ) ),
		) ),
		ao_field( 'ao_hero_statement', 'Hero Statement', 'text', array(
			'instructions' => '2–5 words. Not the building name.',
			'maxlength'    => 60,
		) ),

		ao_field( 'ao_tab_story', 'The Building', 'tab' ),
		ao_field( 'ao_building_image', 'The Building Image', 'image', array( 'return_format' => 'array' ) ),
		ao_field( 'ao_building_copy', 'The Building Copy', 'textarea', array(
			'instructions' => 'Approximately 30–45 words.',
			'rows'         => 3,
		) ),
		ao_field( 'ao_residences_image', 'Residences Image', 'image', array( 'return_format' => 'array' ) ),
		ao_field( 'ao_residences_copy', 'Residences Copy', 'textarea', array(
			'instructions' => 'Approximately 25–40 words.',
			'rows'         => 3,
		) ),

		ao_field( 'ao_tab_amenities', 'Amenities', 'tab' ),
		ao_field( 'ao_amenities', 'Amenity Blocks', 'repeater', array(
			'instructions' => 'Repeatable blocks. Number and subjects vary by building.',
			'layout'       => 'block',
			'button_label' => 'Add Amenity',
			'sub_fields'   => array(
				ao_field( 'ao_amenity_image', 'Image', 'image', array( 'return_format' => 'array' ) ),
				ao_field( 'ao_amenity_title', 'Title', 'text' ),
				ao_field( 'ao_amenity_copy', 'Copy', 'textarea', array( 'rows' => 2 ) ),
			),
		) ),

		ao_field( 'ao_tab_availability', 'Availability', 'tab' ),
		ao_field( 'ao_idx_availability', 'IDX Availability Embed', 'textarea', array(
			'instructions' => 'Shortcode or embed for current listings in this building. Listings appear with no introduction.',
			'rows'         => 2,
		) ),
		ao_field( 'ao_floorplan_note', 'Floorplan Note', 'text', array(
			'default_value' => 'Inquire about floorplan availability.',
		) ),

		ao_field( 'ao_tab_neighborhood', 'Neighborhood', 'tab' ),
		ao_field( 'ao_map_embed', 'Map Embed', 'textarea', array(
			'instructions' => 'Opens in an overlay and returns to the same scroll position.',
			'rows'         => 3,
		) ),
		ao_field( 'ao_neighborhood_copy', 'Neighborhood Context', 'textarea', array( 'rows' => 3 ) ),

		ao_field( 'ao_tab_gallery', 'Gallery', 'tab' ),
		ao_field( 'ao_gallery', 'Gallery Items', 'repeater', array(
			'instructions' => 'Categories drive the gallery filter. VIEWS is only used where there is enough media.',
			'layout'       => 'table',
			'button_label' => 'Add Media',
			'sub_fields'   => array(
				ao_field( 'ao_gallery_media', 'Media', 'image', array( 'return_format' => 'array' ) ),
				ao_field( 'ao_gallery_video', 'Video / 3D URL', 'url' ),
				ao_field( 'ao_gallery_category', 'Category', 'select', array(
					'choices' => array(
						'building'   => 'Building',
						'residences' => 'Residences',
						'amenities'  => 'Amenities',
						'views'      => 'Views',
					),
				) ),
			),
		) ),

		ao_field( 'ao_tab_faqs', 'FAQs', 'tab' ),
		ao_field( 'ao_faqs', 'FAQs', 'repeater', array(
			'instructions' => 'Verified building facts. Reverify policies and fees before publication.',
			'layout'       => 'block',
			'button_label' => 'Add FAQ',
			'sub_fields'   => array(
				ao_field( 'ao_faq_question', 'Question', 'text' ),
				ao_field( 'ao_faq_answer', 'Answer', 'textarea', array( 'rows' => 3 ) ),
				ao_field( 'ao_faq_verified', 'Verified On', 'date_picker' ),
			),
		) ),

		ao_field( 'ao_tab_contact', 'Contact', 'tab' ),
		ao_field( 'ao_contact_line', 'Contact Line', 'text', array(
			'instructions' => 'Defaults to "Advice and insight on [Building Name]." if left empty.',
		) ),
	) );

	// ── 03B Individual neighborhood page, stored on the term ──────────────
	ao_group( 'neighborhood', 'Neighborhood Page', ao_location( 'taxonomy', 'ao_neighborhood' ), array(

		ao_field( 'ao_tab_nbhd_hero', 'Hero', 'tab' ),
		ao_field( 'ao_hero_image_nbhd', 'Hero Image', 'image', array( 'return_format' => 'array' ) ),
		ao_field( 'ao_card_image', 'Directory Card Image', 'image', array(
			'return_format' => 'array',
			'instructions'  => 'Used on the neighborhood directory card.',
		) ),
		ao_field( 'ao_descriptor', 'Hero Descriptor', 'textarea', array(
			'instructions' => '15–25 words.',
			'rows'         => 2,
		) ),

		ao_field( 'ao_tab_nbhd_body', 'Content', 'tab' ),
		ao_field( 'ao_overview', 'Neighborhood Overview', 'textarea', array(
			'instructions' => '100–125 words. One concise overview, not multiple lifestyle sections.',
			'rows'         => 5,
		) ),
		ao_field( 'ao_real_estate', 'Neighborhood Real Estate', 'textarea', array(
			'instructions' => '110–140 words. Buyer-first and condominium-led; avoid tourism copy.',
			'rows'         => 5,
		) ),
		ao_field( 'ao_real_estate_image', 'Real Estate Image', 'image', array( 'return_format' => 'array' ) ),
		ao_field( 'ao_perspective_heading', "Andy's Perspective — Heading", 'text' ),
		ao_field( 'ao_perspective', "Andy's Perspective", 'textarea', array(
			'instructions' => 'Judgment-led professional insight, specific to this neighborhood.',
			'rows'         => 4,
		) ),

		ao_field( 'ao_tab_nbhd_related', 'Related', 'tab' ),
		ao_field( 'ao_featured_buildings', 'Featured Condominium Buildings', 'relationship', array(
			'post_type'     => array( 'ao_building' ),
			'return_format' => 'id',
			'instructions'  => 'Manually selected. Leave empty where condominium inventory is not meaningful.',
		) ),
		ao_field( 'ao_idx_neighborhood', 'IDX Listings Embed', 'textarea', array(
			'instructions' => 'Neighborhood-filtered IDX inventory. No introductory copy.',
			'rows'         => 2,
		) ),

		ao_field( 'ao_tab_nbhd_faqs', 'FAQs', 'tab' ),
		ao_field( 'ao_nbhd_faqs', 'FAQs', 'repeater', array(
			'layout'       => 'block',
			'button_label' => 'Add FAQ',
			'sub_fields'   => array(
				ao_field( 'ao_nbhd_faq_q', 'Question', 'text' ),
				ao_field( 'ao_nbhd_faq_a', 'Answer', 'textarea', array( 'rows' => 3 ) ),
			),
		) ),
		ao_field( 'ao_cta_statement', 'Closing Statement', 'textarea', array( 'rows' => 2 ) ),

		ao_field( 'ao_tab_nbhd_internal', 'Internal', 'tab' ),
		ao_field( 'ao_zip_codes', 'ZIP Codes', 'text' ),
		ao_field( 'ao_section', 'Philadelphia Section', 'text' ),
		ao_field( 'ao_research', 'Internal Research Record', 'wysiwyg', array(
			'instructions' => 'Sources, methodology and maintenance notes. Never published.',
		) ),
	) );

	// ── 07B Testimonials ──────────────────────────────────────────────────
	ao_group( 'testimonial', 'Testimonial', ao_location( 'post_type', 'ao_testimonial' ), array(
		ao_field( 'ao_attribution', 'Attribution', 'text', array(
			'instructions' => 'Kept discreet in display.',
		) ),
		ao_field( 'ao_related_building', 'Related Building', 'post_object', array(
			'post_type'     => array( 'ao_building' ),
			'return_format' => 'id',
			'allow_null'    => 1,
		) ),
		ao_field( 'ao_transaction_date', 'Date', 'date_picker' ),
		ao_field( 'ao_internal_context', 'Internal Context', 'textarea', array( 'rows' => 2 ) ),
	) );

	// ── 07C Press & Media ─────────────────────────────────────────────────
	ao_group( 'press', 'Press Record', ao_location( 'post_type', 'ao_press' ), array(
		ao_field( 'ao_publication', 'Publication', 'text', array(
			'instructions' => 'Included in press search.',
		) ),
		ao_field( 'ao_external_url', 'External URL', 'url', array(
			'instructions' => 'Normally links straight to the publisher.',
		) ),
		ao_field( 'ao_publication_logo', 'Publication Logo', 'image', array( 'return_format' => 'array' ) ),
		ao_field( 'ao_summary', 'Summary', 'textarea', array( 'rows' => 3 ) ),
		ao_field( 'ao_role', "Andy's Role", 'text', array(
			'instructions' => 'For example: quoted expert, subject of coverage, award recipient.',
		) ),
		ao_field( 'ao_related_building_press', 'Related Building', 'post_object', array(
			'post_type'     => array( 'ao_building' ),
			'return_format' => 'id',
			'allow_null'    => 1,
		) ),
	) );

	// ── 06 Market Insights ────────────────────────────────────────────────
	ao_group( 'insight', 'Insight Record', ao_location( 'post_type', 'ao_insight' ), array(
		ao_field( 'ao_report_period', 'Report Period', 'text', array(
			'instructions' => 'For example: Q3 2026.',
		) ),
		ao_field( 'ao_updated_date', 'Updated Date', 'date_picker' ),
		ao_field( 'ao_takeaways', 'Key Takeaways', 'repeater', array(
			'layout'       => 'table',
			'button_label' => 'Add Takeaway',
			'sub_fields'   => array( ao_field( 'ao_takeaway', 'Takeaway', 'text' ) ),
		) ),
		ao_field( 'ao_methodology', 'Methodology & Sources', 'wysiwyg' ),
		ao_field( 'ao_report_pdf', 'Report PDF', 'file', array(
			'return_format' => 'array',
			'instructions'  => 'Supplements the HTML report; it does not replace it.',
		) ),
		ao_field( 'ao_related_buildings_insight', 'Related Buildings', 'relationship', array(
			'post_type'     => array( 'ao_building' ),
			'return_format' => 'id',
		) ),
	) );

	// ── 05D Seller case studies ───────────────────────────────────────────
	ao_group( 'case_study', 'Case Study', ao_location( 'post_type', 'ao_case_study' ), array(
		ao_field( 'ao_price', 'Sale Price', 'number', array(
			'key'          => 'field_ao_case_study_price',
			'instructions' => 'Drives price sorting.',
		) ),
		ao_field( 'ao_challenge', 'Property / Challenge', 'textarea', array( 'rows' => 3 ) ),
		ao_field( 'ao_strategy', 'Strategy / Execution', 'textarea', array( 'rows' => 3 ) ),
		ao_field( 'ao_result', 'Result / Proof', 'textarea', array( 'rows' => 3 ) ),
		ao_field( 'ao_metrics', 'Measurable Results', 'repeater', array(
			'layout'       => 'table',
			'button_label' => 'Add Result',
			'sub_fields'   => array(
				ao_field( 'ao_metric_value', 'Value', 'text' ),
				ao_field( 'ao_metric_label', 'Label', 'text' ),
			),
		) ),
		ao_field( 'ao_verified', 'Claims Verified', 'true_false', array(
			'ui'           => 1,
			'instructions' => 'Performance claims must be verified before publication.',
		) ),
	) );

	// ── 01 Sold properties ────────────────────────────────────────────────
	ao_group( 'sold', 'Sold Property', ao_location( 'post_type', 'ao_sold' ), array(
		ao_field( 'ao_address', 'Address', 'text', array( 'key' => 'field_ao_sold_address' ) ),
		ao_field( 'ao_price', 'Sold Price', 'number', array(
			'key'          => 'field_ao_sold_price',
			'instructions' => 'Sorts high to low. The sold date is intentionally not displayed.',
		) ),
		ao_field( 'ao_related_building_sold', 'Building', 'post_object', array(
			'post_type'     => array( 'ao_building' ),
			'return_format' => 'id',
			'allow_null'    => 1,
		) ),
		ao_field( 'ao_representation', 'Representation', 'select', array(
			'choices' => array( 'seller' => 'Seller', 'buyer' => 'Buyer', 'both' => 'Both' ),
		) ),
		ao_field( 'ao_beds', 'Beds', 'number' ),
		ao_field( 'ao_baths', 'Baths', 'number', array( 'step' => '0.5' ) ),
		ao_field( 'ao_sqft', 'Square Feet', 'number' ),
		ao_field( 'ao_city', 'City', 'text', array(
			'default_value' => 'Philadelphia',
			'instructions'  => 'Shown after the neighborhood on the card.',
		) ),
	) );

	// ── 07D Proof points ──────────────────────────────────────────────────
	ao_group( 'proof', 'Proof Point', ao_location( 'post_type', 'ao_proof' ), array(
		ao_field( 'ao_value', 'Value', 'text', array(
			'instructions' => 'For example: $650M+.',
		) ),
		ao_field( 'ao_label', 'Label', 'text', array(
			'instructions' => 'For example: Career Sales.',
		) ),
		ao_field( 'ao_footnote', 'Footnote / Source', 'text' ),
		ao_field( 'ao_verified_proof', 'Verified', 'true_false', array(
			'ui'           => 1,
			'instructions' => 'Verify every ranking and performance claim before publication.',
		) ),
	) );
}

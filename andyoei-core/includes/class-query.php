<?php
defined( 'ABSPATH' ) || exit;

/**
 * Turns a filter request into a WP_Query and renders the result set.
 */
class AO_Query {

	/**
	 * Normalise raw request input into a predictable shape.
	 */
	public static function parse_request( $config, $raw ) {
		$selected = array();

		$in = isset( $raw['f'] ) && is_array( $raw['f'] ) ? $raw['f'] : array();

		foreach ( $config['facets'] as $name => $facet ) {
			if ( empty( $in[ $name ] ) ) {
				continue;
			}

			$values = is_array( $in[ $name ] ) ? $in[ $name ] : explode( ',', $in[ $name ] );
			$values = array_filter( array_map( 'sanitize_title', $values ) );

			if ( $values ) {
				$selected[ $name ] = array_values( $values );
			}
		}

		$sort = isset( $raw['sort'] ) ? sanitize_key( $raw['sort'] ) : '';
		if ( ! isset( $config['sorts'][ $sort ] ) ) {
			$sort = $config['default_sort'];
		}

		return array(
			'facets' => $selected,
			'search' => isset( $raw['s'] ) ? sanitize_text_field( wp_unslash( $raw['s'] ) ) : '',
			'sort'   => $sort,
			'page'   => max( 1, isset( $raw['page'] ) ? (int) $raw['page'] : 1 ),
		);
	}

	/**
	 * True when the visitor has narrowed the set — the featured strip hides
	 * in that state, per the concept document.
	 */
	public static function is_filtered( $config, $request ) {
		return ! empty( $request['facets'] )
			|| '' !== $request['search']
			|| $request['sort'] !== $config['default_sort'];
	}

	/**
	 * The choices a facet offers, as value => label, whether it is backed by
	 * a taxonomy or by numeric buckets.
	 */
	public static function facet_options( $facet ) {
		if ( isset( $facet['type'] ) && 'range' === $facet['type'] ) {
			$options = array();

			foreach ( $facet['options'] as $value => $range ) {
				$options[ $value ] = $range['label'];
			}

			return $options;
		}

		$terms = get_terms( array( 'taxonomy' => $facet['taxonomy'], 'hide_empty' => false ) );

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$options = array();

		foreach ( $terms as $term ) {
			$options[ $term->slug ] = $term->name;
		}

		return $options;
	}

	/**
	 * What a pill reads when it is closed: its all-label, the single choice,
	 * or a count once several are selected.
	 */
	public static function facet_summary( $facet, $options, $active ) {
		$all = isset( $facet['all_label'] ) ? $facet['all_label'] : 'All';

		if ( ! $active ) {
			return $all;
		}

		if ( 1 === count( $active ) && isset( $options[ $active[0] ] ) ) {
			return $options[ $active[0] ];
		}

		return count( $active ) . ' selected';
	}

	/**
	 * Build the WP_Query arguments.
	 */
	public static function args( $config, $request ) {
		$args = array(
			'post_type'              => $config['post_type'],
			'post_status'            => 'publish',
			'posts_per_page'         => $config['per_page'],
			'paged'                  => $request['page'],
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => false,
			'update_post_term_cache' => true,
		);

		// Taxonomy facets. OR inside a group, AND between groups; a features
		// group uses AND so a building must carry every selected feature.
		$tax_query = array();

		$ranges = array();

		foreach ( $config['facets'] as $name => $facet ) {
			if ( empty( $request['facets'][ $name ] ) ) {
				continue;
			}

			// Numeric buckets filter on a meta field, not a taxonomy.
			if ( isset( $facet['type'] ) && 'range' === $facet['type'] ) {
				$ranges[ $name ] = $facet;
				continue;
			}

			$tax_query[] = array(
				'taxonomy' => $facet['taxonomy'],
				'field'    => 'slug',
				'terms'    => $request['facets'][ $name ],
				'operator' => isset( $facet['operator'] ) ? $facet['operator'] : 'IN',
			);
		}

		if ( $tax_query ) {
			$tax_query['relation'] = 'AND';
			$args['tax_query']     = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery
		}

		// Meta clauses are always named so they can be combined with orderby.
		$meta_query = array( 'relation' => 'AND' );

		if ( '' !== $request['search'] ) {
			$meta_query['search'] = array(
				'key'     => AO_Index::SEARCH,
				'value'   => self::search_key( $request['search'] ),
				'compare' => 'LIKE',
			);
		}

		foreach ( $ranges as $name => $facet ) {
			$clause = self::range_clause( $facet, $request['facets'][ $name ] );

			if ( $clause ) {
				$meta_query[ 'range_' . $name ] = $clause;
			}
		}

		$meta_query = array_merge( $meta_query, self::sort_clauses( $request['sort'] ) );

		if ( count( $meta_query ) > 1 ) {
			$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery
		}

		$args['orderby'] = self::orderby( $request['sort'] );

		return apply_filters( 'ao_query_args', $args, $config, $request );
	}

	/**
	 * One clause covering every selected bucket. Several buckets are OR'd,
	 * so picking two year ranges widens the set rather than emptying it.
	 */
	private static function range_clause( $facet, $selected ) {
		$clauses = array( 'relation' => 'OR' );

		foreach ( $selected as $value ) {
			if ( ! isset( $facet['options'][ $value ] ) ) {
				continue;
			}

			$range = $facet['options'][ $value ];
			$min   = isset( $range['min'] ) ? (int) $range['min'] : 0;
			$max   = isset( $range['max'] ) ? (int) $range['max'] : 0;

			if ( $min && $max ) {
				$compare = 'BETWEEN';
				$value_q = array( $min, $max );
			} elseif ( $min ) {
				$compare = '>=';
				$value_q = $min;
			} elseif ( $max ) {
				$compare = '<=';
				$value_q = $max;
			} else {
				continue;
			}

			$clauses[] = array(
				'key'     => $facet['meta_key'],
				'value'   => $value_q,
				'type'    => 'NUMERIC',
				'compare' => $compare,
			);
		}

		return count( $clauses ) > 1 ? $clauses : array();
	}

	/**
	 * Meta clauses a given sort depends on.
	 *
	 * Clause names are prefixed because WP_Query reserves several bare names
	 * ('name', 'title', 'date') for post columns — an 'ao_' clause always
	 * resolves to the meta value.
	 */
	private static function sort_clauses( $sort ) {
		switch ( $sort ) {
			case 'name_asc':
			case 'name_desc':
				return array( 'ao_name' => array( 'key' => AO_Index::SORT_NAME, 'compare' => 'EXISTS' ) );

			case 'nbhd_asc':
				return array(
					'ao_nbhd' => array( 'key' => AO_Index::SORT_NBHD, 'compare' => 'EXISTS' ),
					'ao_name' => array( 'key' => AO_Index::SORT_NAME, 'compare' => 'EXISTS' ),
				);

		}

		return array();
	}

	private static function orderby( $sort ) {
		switch ( $sort ) {
			case 'name_asc':
				return array( 'ao_name' => 'ASC' );

			case 'name_desc':
				return array( 'ao_name' => 'DESC' );

			case 'nbhd_asc':
				return array( 'ao_nbhd' => 'ASC', 'ao_name' => 'ASC' );

			default:
				return array( 'ao_name' => 'ASC' );
		}
	}

	/**
	 * Match the normalisation used when the search index was written.
	 */
	private static function search_key( $value ) {
		$value = remove_accents( $value );
		$value = strtolower( $value );
		$value = preg_replace( '/[^a-z0-9 ]+/', ' ', $value );

		return trim( preg_replace( '/\s+/', ' ', $value ) );
	}

	/**
	 * Run the query and render the cards, inserting alphabetical group
	 * headers where the directory asks for them.
	 *
	 * @return array{html:string,total:int,pages:int,has_more:bool,last_group:string}
	 */
	public static function render( $config, $request, $last_group = '' ) {
		$query = new WP_Query( self::args( $config, $request ) );
		$html  = '';

		while ( $query->have_posts() ) {
			$query->the_post();

			$post_id = get_the_ID();

			if ( $config['group_by'] ) {
				$group = self::group_for( $post_id, $request['sort'], $config['group_by'] );

				if ( $group && $group !== $last_group ) {
					$html      .= '<div class="ao-group" role="heading" aria-level="3">' . esc_html( $group ) . '</div>';
					$last_group = $group;
				}
			}

			$html .= ao_template( $config['card'], array( 'post_id' => $post_id, 'config' => $config ) );
		}

		wp_reset_postdata();

		return array(
			'html'       => $html,
			'total'      => (int) $query->found_posts,
			'pages'      => (int) $query->max_num_pages,
			'has_more'   => $request['page'] < (int) $query->max_num_pages,
			'last_group' => $last_group,
		);
	}

	/**
	 * The header a record sits under for the active sort.
	 */
	private static function group_for( $post_id, $sort, $group_by ) {
		if ( 'letter' !== $group_by ) {
			return '';
		}

		if ( 'nbhd_asc' === $sort ) {
			$terms = wp_get_object_terms( $post_id, 'ao_neighborhood', array( 'fields' => 'names' ) );

			return ( ! is_wp_error( $terms ) && $terms ) ? $terms[0] : 'Other';
		}

		// Grouping follows the sort key, so "The Ritz-Carlton" groups under R.
		$name = (string) get_post_meta( $post_id, AO_Index::SORT_NAME, true );

		return $name ? strtoupper( substr( $name, 0, 1 ) ) : '#';
	}
}

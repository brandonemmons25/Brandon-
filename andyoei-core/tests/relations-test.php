<?php
/**
 * Exercises the paths that changed in 2.0 against stubbed WordPress
 * functions. Not a substitute for a real install — it cannot catch a bad
 * hook name — but it does catch a wrong array shape or a bad slug lookup.
 */

define( 'ABSPATH', true );
define( 'AO_PATH', dirname( __DIR__ ) . '/' );
define( 'AO_URL', 'http://example.test/' );
define( 'AO_VERSION', '2.0.0' );

// ── The fixture: three neighborhoods, four buildings ──────────────────
$NEIGHBORHOODS = array(
	101 => array( 'title' => 'Rittenhouse Square', 'slug' => 'rittenhouse-square' ),
	102 => array( 'title' => 'Fishtown',           'slug' => 'fishtown' ),
	103 => array( 'title' => 'Old City',           'slug' => 'old-city' ),
);

$BUILDINGS = array(
	201 => array( 'title' => 'The Laurel',   'nbhd' => 101 ),
	202 => array( 'title' => 'Ten Rittenhouse', 'nbhd' => 101 ),
	203 => array( 'title' => 'The Battery',  'nbhd' => 102 ),
	204 => array( 'title' => 'Unplaced Tower', 'nbhd' => 0 ),
);

$META = array();
foreach ( $BUILDINGS as $id => $b ) {
	if ( $b['nbhd'] ) {
		$META[ $id ]['ao_neighborhood'] = $b['nbhd'];
	}
}
$META[101]['ao_median_price'] = '$685,000';

// ── Stubs ─────────────────────────────────────────────────────────────
function add_action() {} function add_filter() {} function add_shortcode() {}
function register_post_meta() {} function register_post_type() {} function register_taxonomy() {}
function current_user_can() { return true; }
function apply_filters( $tag, $value ) { return $value; }
function sanitize_title( $v ) { return strtolower( preg_replace( '/[^a-zA-Z0-9]+/', '-', trim( $v ) ) ); }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $v ) ); }
function sanitize_text_field( $v ) { return is_string( $v ) ? trim( strip_tags( $v ) ) : $v; }
function wp_unslash( $v ) { return $v; }
function remove_accents( $v ) { return $v; }
function esc_html( $v ) { return htmlspecialchars( (string) $v ); }
function esc_attr( $v ) { return htmlspecialchars( (string) $v ); }
function esc_url( $v ) { return $v; }
function number_format_i18n( $v ) { return number_format( $v ); }
function get_permalink( $id ) { return 'http://example.test/?p=' . $id; }
function get_the_post_thumbnail() { return '<img>'; }
function is_wp_error( $v ) { return false; }
function get_terms() { return array(); }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, $args ); }

function get_post_meta( $id, $key, $single = false ) {
	global $META;
	return isset( $META[ $id ][ $key ] ) ? $META[ $id ][ $key ] : '';
}

function get_post( $id ) {
	global $NEIGHBORHOODS, $BUILDINGS;

	if ( isset( $NEIGHBORHOODS[ $id ] ) ) {
		return (object) array( 'ID' => $id, 'post_type' => 'ao_neighborhood',
			'post_title' => $NEIGHBORHOODS[ $id ]['title'], 'post_name' => $NEIGHBORHOODS[ $id ]['slug'] );
	}
	if ( isset( $BUILDINGS[ $id ] ) ) {
		return (object) array( 'ID' => $id, 'post_type' => 'ao_building',
			'post_title' => $BUILDINGS[ $id ]['title'], 'post_name' => sanitize_title( $BUILDINGS[ $id ]['title'] ) );
	}
	return null;
}

function get_post_type( $id ) { $p = get_post( $id ); return $p ? $p->post_type : ''; }
function get_the_title( $id ) { $p = get_post( $id ); return $p ? $p->post_title : ''; }

function get_posts( $args ) {
	global $NEIGHBORHOODS, $BUILDINGS, $META;

	$type   = isset( $args['post_type'] ) ? $args['post_type'] : 'post';
	$fields = isset( $args['fields'] ) ? $args['fields'] : '';

	if ( 'ao_neighborhood' === $type ) {
		$out = array();
		foreach ( $NEIGHBORHOODS as $id => $n ) {
			if ( isset( $args['name'] ) && $args['name'] !== $n['slug'] ) { continue; }
			$out[] = get_post( $id );
		}
		usort( $out, function ( $a, $b ) { return strcmp( $a->post_title, $b->post_title ); } );
	} else {
		$out = array();
		foreach ( $BUILDINGS as $id => $b ) {
			if ( isset( $args['meta_key'] ) && isset( $args['meta_value'] ) ) {
				$have = isset( $META[ $id ][ $args['meta_key'] ] ) ? $META[ $id ][ $args['meta_key'] ] : 0;
				if ( (int) $have !== (int) $args['meta_value'] ) { continue; }
			}
			$out[] = get_post( $id );
		}
	}

	if ( 'ids' === $fields ) {
		return array_map( function ( $p ) { return $p->ID; }, $out );
	}

	return $out;
}

require_once AO_PATH . 'includes/class-relations.php';
require_once AO_PATH . 'includes/class-curation.php';
require_once AO_PATH . 'includes/class-index.php';
require_once AO_PATH . 'includes/class-directories.php';
require_once AO_PATH . 'includes/class-query.php';
require_once AO_PATH . 'includes/class-destinations.php';

function ao_template( $file, $vars = array() ) {
	extract( $vars, EXTR_SKIP );
	ob_start();
	include AO_PATH . 'templates/' . $file;
	return ob_get_clean();
}

// ── Checks ────────────────────────────────────────────────────────────
$fails = 0;
function check( $label, $got, $want ) {
	global $fails;
	$ok = $got === $want;
	if ( ! $ok ) { $fails++; }
	printf( "%s  %s\n", $ok ? ' ok ' : 'FAIL', $label );
	if ( ! $ok ) {
		echo "        got:  " . var_export( $got, true ) . "\n";
		echo "        want: " . var_export( $want, true ) . "\n";
	}
}

AO_Directories::register();
$config = AO_Directories::get( 'buildings' );
$area   = $config['facets']['area'];

check( 'Areas facet is backed by the post type', $area['post_type'], 'ao_neighborhood' );

check(
	'facet_options() lists neighborhoods as slug => name, A–Z',
	AO_Query::facet_options( $area ),
	array( 'fishtown' => 'Fishtown', 'old-city' => 'Old City', 'rittenhouse-square' => 'Rittenhouse Square' )
);

check( 'a building resolves its neighborhood name', AO_Relations::name_for_building( 201 ), 'Rittenhouse Square' );
check( 'an unplaced building resolves to nothing', AO_Relations::name_for_building( 204 ), '' );
check( 'the building count is live', AO_Relations::building_count( 101 ), 2 );
check( 'a neighborhood with no buildings counts zero', AO_Relations::building_count( 103 ), 0 );

// A filter URL carrying slugs must become a meta clause carrying IDs.
$request = AO_Query::parse_request( $config, array( 'f' => array( 'area' => 'rittenhouse-square,fishtown' ) ) );
check( 'slugs survive request parsing', $request['facets']['area'], array( 'rittenhouse-square', 'fishtown' ) );

$args = AO_Query::args( $config, $request );
check( 'Areas becomes a meta clause, not a tax clause', isset( $args['tax_query'] ), false );
check(
	'selected slugs become the stored IDs',
	$args['meta_query']['rel_area'],
	array( 'key' => 'ao_neighborhood', 'value' => array( 101, 102 ), 'compare' => 'IN', 'type' => 'NUMERIC' )
);

// An unknown slug must return nothing, not everything.
$bogus = AO_Query::parse_request( $config, array( 'f' => array( 'area' => 'not-a-place' ) ) );
$args2 = AO_Query::args( $config, $bogus );
check(
	'an unknown area matches no building',
	$args2['meta_query']['rel_area'],
	array( 'key' => 'ao_neighborhood', 'value' => 0, 'compare' => '=' )
);

// Other facets must still go through the taxonomy path.
$views = AO_Query::parse_request( $config, array( 'f' => array( 'views' => 'skyline' ) ) );
$args3 = AO_Query::args( $config, $views );
check( 'Views still filters by taxonomy', isset( $args3['tax_query'][0]['taxonomy'] ) ? $args3['tax_query'][0]['taxonomy'] : '', 'ao_view' );

// Cards render.
$card = ao_template( 'card-neighborhood.php', array( 'post_id' => 101, 'featured' => false ) );
check( 'neighborhood card shows the live count', (bool) strpos( $card, '<dd>2</dd>' ), true );
check( 'neighborhood card shows the median price', (bool) strpos( $card, '$685,000' ), true );

$card2 = ao_template( 'card-neighborhood.php', array( 'post_id' => 103, 'featured' => false ) );
check( 'an empty neighborhood card shows a dash, not a blank', (bool) strpos( $card2, '<dd>—</dd>' ), true );

$bcard = ao_template( 'card-building.php', array( 'post_id' => 201 ) );
check( 'building card shows its Area', (bool) strpos( $bcard, 'Rittenhouse Square' ), true );

echo $fails ? "\n{$fails} failed\n" : "\nall passed\n";
exit( $fails ? 1 : 0 );

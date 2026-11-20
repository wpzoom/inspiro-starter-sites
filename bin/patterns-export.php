<?php
/**
 * One-off: export every Inspiro Patterns design as static block markup with
 * its default content, for the pattern library (see PatternLibrary.php).
 *
 * Run on a site with the Inspiro Patterns plugin active:
 *   wp eval-file bin/patterns-export.php raw.json
 * then turn raw.json into the provider's catalog/patterns/ files:
 *   python3 bin/patterns-convert.py raw.json <provider>/catalog/patterns
 */
$out_file = $args[0];
$p = new AI_Blocks_Patterns();
$p->init_patterns();
$all = $p->get_patterns();
$out = array();
foreach ( $all as $id => $pat ) {
	$content = isset( $pat['content_structure'] ) ? $pat['content_structure'] : array();
	$slots   = isset( $pat['image_slots'] ) ? (int) $pat['image_slots'] : ( isset( $pat['images_count'] ) ? (int) $pat['images_count'] : 0 );
	$images  = array();
	for ( $i = 1; $i <= $slots; $i++ ) {
		$images[] = 'https://pattern-photo.invalid/slot-' . $i . '.jpg';
	}
	$opts   = array( 'team_size' => isset( $pat['dynamic_options']['team_size']['default'] ) ? $pat['dynamic_options']['team_size']['default'] : '3' );
	$markup = $p->render_pattern( $id, $content, $images, $opts );
	$out[ $id ] = array(
		'id'         => $id,
		'title'      => $pat['title'],
		'category'   => array_values( (array) ( isset( $pat['category'] ) ? $pat['category'] : array() ) ),
		'type'       => isset( $pat['type'] ) ? $pat['type'] : '',
		'background' => isset( $pat['background_mode'] ) ? $pat['background_mode'] : '',
		'slots'      => $slots,
		'keys'       => array_keys( $pat ),
		'markup'     => $markup,
	);
}
file_put_contents( $out_file, wp_json_encode( $out ) );
echo count( $out ), " patterns\n";

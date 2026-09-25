<?php
/**
 * Catalog builder — DEV TOOL. bin/ is excluded from the release zip.
 *
 * Writes the curated catalog: every entry in bin/catalog-curation.json
 * ([ candidate id, section id, role, fits, description ]) is copied from the
 * extractor's candidates into the AI server's catalog, catalog/sections/ in
 * the wpzoom-api-key-provider plugin (deploy the provider to publish it).
 *
 * Usage (MAMP WP-CLI, from the Lite install):
 *   wp eval-file bin/catalog-build.php <candidates-dir> <wpzoom-api-key-provider-dir>
 *
 * Rebuild from scratch:
 *   1. download the demo exports (see components/importer/class-inspiro-starter-sites-importer-setup.php)
 *   2. wp eval-file bin/catalog-extract.php <work-dir> lite <demo.xml> ...
 *   3. wp eval-file bin/catalog-build.php <work-dir>/candidates <provider-dir>
 *
 * @package Inspiro Starter Sites
 */

defined( 'ABSPATH' ) || exit;

if ( isset( $args[1] ) ) {
	$candidates = rtrim( $args[0], '/' );
	$out        = rtrim( $args[1], '/' ) . '/catalog/sections';
	$curation   = json_decode( (string) file_get_contents( __DIR__ . '/catalog-curation.json' ), true );

	wp_mkdir_p( $out );
	array_map( 'unlink', (array) glob( $out . '/*.json' ) );

	$written = 0;
	foreach ( (array) $curation as $row ) {
		list( $candidate_id, $id, $role, $fits, $description ) = $row;
		$file = $candidates . '/' . $candidate_id . '.json';
		if ( ! is_file( $file ) ) {
			echo "missing candidate: $candidate_id\n";
			continue;
		}
		$c = json_decode( (string) file_get_contents( $file ), true );

		$section = array(
			'id'           => $id,
			'role'         => $role,
			'fits'         => $fits,
			'description'  => $description,
			'tone'         => $c['tone'],
			'tier'         => 'lite',
			'source_theme' => isset( $c['source_theme'] ) ? $c['source_theme'] : 'lite',
			'source'       => $c['source']['demo'] . ' / ' . $c['source']['page'] . ' / ' . $c['source']['index'],
			'photos'       => (int) $c['stats']['image'],
			'needs'        => $c['needs'],
			'requires'     => $c['requires'],
			'fields'       => $c['fields'] ? $c['fields'] : new stdClass(),
			'ids'          => $c['ids'] ? $c['ids'] : new stdClass(),
			'assets'       => $c['assets'],
			'markup'       => $c['markup'],
		);
		file_put_contents( $out . '/' . $id . '.json', wp_json_encode( $section, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
		$written++;
	}
	echo "sections written: $written\n";
}

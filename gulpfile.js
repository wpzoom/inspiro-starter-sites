/**
 * Load plugins.
 */
import debug from 'gulp-debug';
import { createRequire } from "module";
const require = createRequire(import.meta.url);

const gulp = require( 'gulp' ),
	cached = require( 'gulp-cached' ),
	sass = require('gulp-sass')(require('sass')),
	sourcemaps = require( 'gulp-sourcemaps' ),
	rename = require( 'gulp-rename' ),
	uglify = require( 'gulp-uglify' ),
	readme = require( 'gulp-readme-to-markdown' ),
	replace = require( 'gulp-replace' ),
	packageJSON = require( './package.json' ),
	exec = require( 'child_process' ).exec;

// The gulpfile runs from the plugin root. The importer component's frontend
// assets live under components/importer/assets, so source/output paths are
// scoped there explicitly.
const importerAssets = 'components/importer/assets';

const plugin = {
	scss: [
		importerAssets + '/css/**/*.scss',
	],
	js: [
		importerAssets + '/js/*.js',
		'!' + importerAssets + '/js/*.min.js',
	],
	files_replace_ver: [
		"**/*.php",
		"**/*.js",
		"!**/*.min.js",
		"!languages/**",
		"!node_modules/**",
		"!**/vendor/**",
		"!gulpfile.js",
	],
};

/**
 * Compile SCSS to CSS, compress.
 */
gulp.task( 'css', function () {
	return gulp.src( plugin.scss )
		// UnMinified file.
		.pipe( cached( 'processCSS' ) )
		.pipe( sourcemaps.init() )
		.pipe( sass( { outputStyle: 'expanded' } ).on( 'error', sass.logError ) )
		.pipe( rename( function ( path ) {
			path.dirname = '/' + importerAssets + '/css';
			path.extname = '.css';
		} ) )
		.pipe( sourcemaps.write() )
		.pipe( gulp.dest( './' ) )
		// Minified file.
		.pipe( sass( { outputStyle: 'compressed' } ).on( 'error', sass.logError ) )
		.pipe( rename( function ( path ) {
			path.dirname = '/' + importerAssets + '/css';
			path.extname = '.min.css';
		} ) )
		.pipe( gulp.dest( './' ) )
		.pipe( debug( { title: '[css]' } ) );
} );

/**
 * Compress js.
 */
gulp.task( 'js', function () {
	return gulp.src( plugin.js )
		.pipe( cached( 'processJS' ) )
		.pipe( uglify() ).on( 'error', console.log )
		.pipe( rename( function ( path ) {
			path.dirname = '/' + importerAssets + '/js';
			path.basename += '.min';
		} ) )
		.pipe( gulp.dest( '.' ) )
		.pipe( debug( { title: '[js]' } ) );
} );

/**
 * Generate .pot file.
 */
gulp.task( 'pot', function ( cb ) {
	exec(
		'wp i18n make-pot ./ ./languages/inspiro-starter-sites.pot --slug="inspiro-starter-sites" --domain="inspiro-starter-sites" --package-name="Inspiro Starter Sites" --file-comment="" --exclude="node_modules,vendor,tests,bin,docs"',
		function ( err, stdout, stderr ) {
			console.log( stdout );
			console.log( stderr );
			cb( err );
		}
	);
} );

/**
 * Generate readme.md from readme.txt
 */
gulp.task('readme', function() {
	gulp.src([ 'readme.txt' ])
		.pipe(readme({
			details: true,
		}))
		.pipe(gulp.dest('.'));
});

/**
 * Replace plugin version with one from package.json in the main plugin file.
 */
gulp.task( 'replace_plugin_file_ver', function () {
	return gulp.src( [ 'inspiro-starter-sites.php' ] )
		.pipe(
			// File header. Function replacement avoids `$`-group ambiguity
			// (e.g. `$1` + a version starting with a digit becoming `$11`).
			replace(
				/(Version:\s*)[0-9*][0-9.*]*/gm,
				function ( match, prefix ) {
					return prefix + packageJSON.version;
				}
			)
		)
		.pipe( gulp.dest( './' ) );
} );

/**
 * Replace plugin version with one from package.json in @since comments in plugin PHP and JS files.
 */
gulp.task( 'replace_since_ver', function() {
	return gulp.src( plugin.files_replace_ver )
		.pipe(
			replace(
				/@since {VERSION}/g,
				'@since ' + packageJSON.version
			)
		)
		.pipe( gulp.dest( './' ) );
} );

gulp.task( 'replace_ver', gulp.series( 'replace_plugin_file_ver', 'replace_since_ver' ) );

/**
 * Task: build.
 *
 * Compiles assets, generates the .pot file and stamps the version. Packaging
 * the distributable zip is handled separately by `wp dist-archive` (driven by
 * .distignore), so it is not part of this task.
 */
gulp.task( 'build', gulp.series( gulp.parallel( 'css', 'js', 'pot' ), 'replace_ver' ) );

/**
 * Look out for relevant sass/js changes.
 */
gulp.task( 'watch', function () {
	gulp.watch( plugin.scss, gulp.parallel( 'css' ) );
	gulp.watch( plugin.js, gulp.parallel( 'js' ) );
} );

/**
 * Default.
 */
gulp.task( 'default', gulp.parallel( 'css', 'js' ) );

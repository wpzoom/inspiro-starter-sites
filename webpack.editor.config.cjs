/**
 * Editor script build (wp-scripts). The plugin's package.json is
 * "type": "module" for gulp, which makes webpack treat every .js file as
 * strict ESM and demand fully specified import paths (also inside Babel's
 * runtime helpers). Relax that for this bundle.
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	module: {
		...defaultConfig.module,
		rules: [
			{ test: /\.m?js$/, resolve: { fullySpecified: false } },
			...defaultConfig.module.rules,
		],
	},
};

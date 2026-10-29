/**
 * The wp-scripts configuration with two changes: the entry is
 * resources/index.tsx (src/ is PHP), and no RTL stylesheet is generated.
 * DependencyExtractionWebpackPlugin stays: it externalises react, react-dom
 * and the wp-* packages to the script handles WordPress ships, and bundles
 * @wordpress/dataviews, which WordPress does not ship.
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

const base = Array.isArray( defaultConfig ) ? defaultConfig[ 0 ] : defaultConfig;

module.exports = {
	...base,
	entry: {
		index: path.resolve( __dirname, 'resources/index.tsx' ),
	},
	output: {
		...base.output,
		path: path.resolve( __dirname, 'build' ),
	},
	plugins: base.plugins.filter(
		( plugin ) => plugin.constructor.name !== 'RtlCssPlugin'
	),
	performance: {
		hints: false,
	},
};

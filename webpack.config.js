/**
 * Configuración de @wordpress/scripts con un ajuste: el runtime JSX (`react/jsx-runtime`) se
 * incluye en el paquete en vez de pedirlo a WordPress. El script `react-jsx-runtime` solo existe
 * desde WordPress 6.6 y el plugin admitía desde 6.5 (ahora 6.9); ocupa 1 KB.
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const DependencyExtractionWebpackPlugin = require( '@wordpress/dependency-extraction-webpack-plugin' );

module.exports = {
	...defaultConfig,
	plugins: [
		...defaultConfig.plugins.filter(
			( plugin ) =>
				plugin.constructor.name !== 'DependencyExtractionWebpackPlugin'
		),
		new DependencyExtractionWebpackPlugin( {
			requestToExternal: ( request ) =>
				request === 'react/jsx-runtime' ||
				request === 'react/jsx-dev-runtime'
					? false
					: undefined,
		} ),
	],
};

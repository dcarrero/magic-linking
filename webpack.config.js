/**
 * Configuración de @wordpress/scripts con un ajuste: el runtime JSX (`react/jsx-runtime`) se
 * incluye en el paquete en vez de pedirlo a WordPress. El script `react-jsx-runtime` solo existe
 * desde WordPress 6.6 y el plugin admitía desde 6.5 (ahora 6.9); ocupa 1 KB.
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const DependencyExtractionWebpackPlugin = require( '@wordpress/dependency-extraction-webpack-plugin' );

module.exports = {
	...defaultConfig,
	// Además de la pantalla del administrador (index), el panel de Gutenberg (editor) y el del editor clásico
	// (classic): scripts aparte para que el clásico no cargue los paquetes del editor de bloques.
	entry: async () => ( {
		...( await defaultConfig.entry() ),
		editor: path.resolve( __dirname, 'assets/src/editor.tsx' ),
		classic: path.resolve( __dirname, 'assets/src/classic.tsx' ),
	} ),
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

/**
 * Punto de entrada de la pantalla «Internal links».
 */
import { createRoot } from '@wordpress/element';
import { App } from './components/App';
import './style.scss';

const root = document.getElementById( 'magiclinking-root' );

if ( root ) {
	const boot = window.magiclinking;
	createRoot( root ).render(
		<App
			exportUrl={ boot?.exportUrl ?? '' }
			canManage={ boot?.canManage ?? false }
			initialTab={ boot?.initialTab }
			tabUrls={ boot?.tabUrls }
		/>
	);
}

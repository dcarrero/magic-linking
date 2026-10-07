/**
 * Punto de entrada de Gutenberg: registra la barra lateral «Internal links» en el editor de entradas.
 */
import { registerPlugin } from '@wordpress/plugins';
import { link } from '@wordpress/icons';
import { editorBoot } from './editor/boot';
import { Sidebar } from './editor/Sidebar';
import './editor/panel.scss';

const boot = editorBoot();

if ( boot && boot.mode === 'block' ) {
	registerPlugin( 'magic-linking', {
		icon: link,
		render: () => <Sidebar boot={ boot } />,
	} );
}

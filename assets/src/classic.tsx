/**
 * Punto de entrada del editor clásico: monta el panel dentro de su caja.
 */
import { createRoot, useMemo } from '@wordpress/element';
import domReady from '@wordpress/dom-ready';
import { classicAdapter } from './editor/adapters/classic';
import { editorBoot } from './editor/boot';
import type { EditorBoot } from './editor/boot';
import { Panel } from './editor/Panel';
import { usePanelState } from './editor/usePanelState';
import './editor/panel.scss';

/**
 * @param root0
 * @param root0.boot
 */
function ClassicPanel( { boot }: { boot: EditorBoot } ) {
	const adapter = useMemo(
		() => classicAdapter( boot.postId ),
		[ boot.postId ]
	);
	const state = usePanelState( adapter, boot.perPage );
	return <Panel adapter={ adapter } boot={ boot } state={ state } />;
}

domReady( () => {
	const boot = editorBoot();
	const root = document.getElementById( 'magiclinking-editor-root' );
	if ( boot && root ) {
		createRoot( root ).render( <ClassicPanel boot={ boot } /> );
	}
} );

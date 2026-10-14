import { speak } from '@wordpress/a11y';
import { useEffect, useMemo, useRef } from '@wordpress/element';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/editor';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Icon, link } from '@wordpress/icons';
import { gutenbergAdapter } from './adapters/gutenberg';
import type { EditorBoot } from './boot';
import { Panel } from './Panel';
import { usePanelState } from './usePanelState';

export const SIDEBAR = 'magiclinking-sidebar';

/**
 * Icono del botón de la barra superior con el número de salientes (docs/07 §3). El número va solo como
 * adorno (aria-hidden): el recuento llega a los lectores de pantalla por `speak()` y por la pestaña «Salientes (n)».
 *
 * @param root0
 * @param root0.count
 */
function CountIcon( { count }: { count: number | null } ) {
	return (
		<span className="magiclinking-toolbar-icon">
			<Icon icon={ link } />
			{ count !== null && count > 0 && (
				<span
					className="magiclinking-toolbar-icon__count"
					aria-hidden="true"
				>
					{ count > 99 ? '99+' : count }
				</span>
			) }
		</span>
	);
}

/**
 * Barra lateral de Gutenberg. Siempre montada (aunque la barra esté cerrada) para pedir las sugerencias y
 * poder enseñar el contador en la barra superior.
 *
 * @param root0
 * @param root0.boot
 */
export function Sidebar( { boot }: { boot: EditorBoot } ) {
	const adapter = useMemo(
		() => gutenbergAdapter( boot.postId, boot ),
		[ boot ]
	);
	const state = usePanelState( adapter, boot.perPage );
	const count = state.outbound.data ? state.visibleOutbound.length : null;

	const title = __( 'Internal links', 'magic-linking' );

	// La insignia del botón es solo adorno (aria-hidden): el recuento llega a los lectores de pantalla con este
	// aviso cortés cuando se carga o cambia, también con el panel cerrado, sin repetirlo si el número no cambia.
	const announcedCount = useRef< number | null >( null );
	useEffect( () => {
		if ( count === null || count === announcedCount.current ) {
			return;
		}
		announcedCount.current = count;
		if ( count > 0 ) {
			speak(
				sprintf(
					/* translators: %d: number of suggestions. */
					_n(
						'%d outbound suggestion',
						'%d outbound suggestions',
						count,
						'magic-linking'
					),
					count
				),
				'polite'
			);
		}
	}, [ count ] );

	return (
		<>
			<PluginSidebarMoreMenuItem target={ SIDEBAR } icon={ link }>
				{ title }
			</PluginSidebarMoreMenuItem>
			<PluginSidebar
				name={ SIDEBAR }
				title={ title }
				icon={ <CountIcon count={ count } /> }
				className="magiclinking-sidebar"
			>
				<Panel
					adapter={ adapter }
					boot={ boot }
					state={ state }
					announce={ false }
				/>
			</PluginSidebar>
		</>
	);
}

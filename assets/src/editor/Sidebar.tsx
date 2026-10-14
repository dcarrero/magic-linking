import { Button } from '@wordpress/components';
import { useViewportMatch } from '@wordpress/compose';
import { useDispatch, useSelect } from '@wordpress/data';
import { useMemo } from '@wordpress/element';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/editor';
import { __ } from '@wordpress/i18n';
import { Icon, link, starEmpty, starFilled } from '@wordpress/icons';
import { gutenbergAdapter } from './adapters/gutenberg';
import type { EditorBoot } from './boot';
import { buttonName } from './buttonName';
import { Panel } from './Panel';
import { usePanelState } from './usePanelState';

export const SIDEBAR = 'magiclinking-sidebar';

/** Ámbito y identificador con los que PluginSidebar registra la barra en el store `core/interface`. */
const SCOPE = 'core';
const IDENTIFIER = `magic-linking/${ SIDEBAR }`;

interface InterfaceSelectors {
	isItemPinned: ( scope: string, identifier: string ) => boolean;
}

interface InterfaceActions {
	pinItem: ( scope: string, identifier: string ) => void;
	unpinItem: ( scope: string, identifier: string ) => void;
}

/**
 * Cabecera del panel: el título visible sin número y la estrella de fijar. Pasar `header` a
 * ComplementaryArea sustituye la cabecera por defecto entera (h2 y estrella), así que se reconstruye la
 * estrella igual que la del núcleo (mismas clases, iconos, cadenas del dominio del núcleo y acciones del
 * store `core/interface`; también se oculta en móvil). El botón de cerrar lo añade ComplementaryArea.
 *
 * @param root0
 * @param root0.title
 */
function SidebarHeader( { title }: { title: string } ) {
	const isMobile = useViewportMatch( 'medium', '<' );
	const isPinned = useSelect(
		( select ) =>
			(
				select( 'core/interface' ) as unknown as InterfaceSelectors
			 ).isItemPinned( SCOPE, IDENTIFIER ),
		[]
	);
	const { pinItem, unpinItem } = useDispatch(
		'core/interface'
	) as unknown as InterfaceActions;

	return (
		<>
			<h2 className="interface-complementary-area-header__title">
				{ title }
			</h2>
			{ ! isMobile && (
				<Button
					className="interface-complementary-area__pin-unpin-item"
					icon={ isPinned ? starFilled : starEmpty }
					// Cadenas del núcleo (dominio por defecto) para que salgan traducidas como la estrella nativa.
					/* eslint-disable @wordpress/i18n-text-domain */
					label={
						isPinned
							? __( 'Unpin from toolbar' )
							: __( 'Pin to toolbar' )
					}
					/* eslint-enable @wordpress/i18n-text-domain */
					onClick={ () =>
						( isPinned ? unpinItem : pinItem )( SCOPE, IDENTIFIER )
					}
					isPressed={ isPinned }
					aria-expanded={ isPinned }
					size="compact"
				/>
			) }
		</>
	);
}

/**
 * Icono del botón de la barra superior con el número de salientes (docs/07 §3). El número va solo como
 * adorno: el nombre accesible del botón lo lleva `buttonLabel` (el título visible del panel no lleva número).
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
	const buttonLabel = buttonName( title, count );

	// `header` llega a ComplementaryArea (sustituye la cabecera por defecto) pero no está en los tipos de PluginSidebar.
	const headerProp = { header: <SidebarHeader title={ title } /> };

	return (
		<>
			<PluginSidebarMoreMenuItem target={ SIDEBAR } icon={ link }>
				{ title }
			</PluginSidebarMoreMenuItem>
			<PluginSidebar
				name={ SIDEBAR }
				title={ buttonLabel }
				{ ...headerProp }
				icon={ <CountIcon count={ count } /> }
				className="magiclinking-sidebar"
			>
				<Panel adapter={ adapter } boot={ boot } state={ state } />
			</PluginSidebar>
		</>
	);
}

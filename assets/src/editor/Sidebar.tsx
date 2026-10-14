import { useMemo } from '@wordpress/element';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/editor';
import { __ } from '@wordpress/i18n';
import { Icon, link } from '@wordpress/icons';
import { gutenbergAdapter } from './adapters/gutenberg';
import type { EditorBoot } from './boot';
import { buttonName } from './buttonName';
import { Panel } from './Panel';
import { usePanelState } from './usePanelState';

export const SIDEBAR = 'magiclinking-sidebar';

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

	// `header` llega a ComplementaryArea (sustituye al h2 del título) pero no está en los tipos de PluginSidebar.
	const headerProp = {
		header: (
			<h2 className="interface-complementary-area-header__title">
				{ title }
			</h2>
		),
	};

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

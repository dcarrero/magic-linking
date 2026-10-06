import { useCallback, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { StatusResponse, Summary } from '../types';
import { BrokenTab } from './BrokenTab';
import { IndexStatus } from './IndexStatus';
import { ReportTab } from './ReportTab';
import { SettingsTab } from './SettingsTab';

type Tab = 'report' | 'broken' | 'settings';

type TabUrls = Record< Tab, string >;

interface Props {
	exportUrl: string;
	canManage: boolean;
	initialTab?: Tab;
	tabUrls?: TabUrls;
}

const DEFAULT_URLS: TabUrls = {
	report: 'admin.php?page=magic-linking',
	broken: 'admin.php?page=magic-linking-broken',
	settings: 'admin.php?page=magic-linking-settings',
};

/**
 * Entrada concreta pedida en la dirección (?entry=ID&entry_title=…), solo para la pantalla de enlaces rotos.
 */
function onlyFromLocation(): { id: number; title: string } | null {
	const params = new URLSearchParams( window.location.search );
	const id = Number( params.get( 'entry' ) );
	return id > 0 ? { id, title: params.get( 'entry_title' ) ?? '' } : null;
}

/**
 * Dirección de la pantalla de enlaces rotos, opcionalmente limitada a una entrada.
 * @param base        Dirección base de la pantalla.
 * @param entry       Entrada a la que limitar la lista.
 * @param entry.id    Identificador de la entrada.
 * @param entry.title Título de la entrada.
 */
function brokenUrl( base: string, entry?: { id: number; title: string } ) {
	if ( ! entry ) {
		return base;
	}
	const url = new URL( base, window.location.href );
	url.searchParams.set( 'entry', String( entry.id ) );
	url.searchParams.set( 'entry_title', entry.title );
	return url.toString();
}

/**
 * Pantalla completa: cabecera con cifras, estado del análisis y la sección de la página actual.
 * @param root0
 * @param root0.exportUrl
 * @param root0.canManage
 * @param root0.initialTab
 * @param root0.tabUrls
 */
export function App( {
	exportUrl,
	canManage,
	initialTab,
	tabUrls = DEFAULT_URLS,
}: Props ) {
	const tab: Tab =
		initialTab === 'settings' && ! canManage
			? 'report'
			: initialTab ?? 'report';
	const [ refreshKey, setRefreshKey ] = useState( 0 );
	const [ statusKey, setStatusKey ] = useState( 0 );
	const [ status, setStatus ] = useState< StatusResponse | null >( null );
	const [ summary, setSummary ] = useState< Summary | null >( null );
	const [ only ] = useState< { id: number; title: string } | null >(
		tab === 'broken' ? onlyFromLocation() : null
	);

	const onFinished = useCallback(
		() => setRefreshKey( ( key ) => key + 1 ),
		[]
	);
	const onStatus = useCallback(
		( next: StatusResponse ) => setStatus( next ),
		[]
	);
	const onSummary = useCallback(
		( next: Summary ) => setSummary( next ),
		[]
	);

	const labels: Record< Tab, string > = {
		report: __( 'Report', 'magic-linking' ),
		broken: __( 'Broken links', 'magic-linking' ),
		settings: __( 'Settings', 'magic-linking' ),
	};

	const indexed = status ? status.indexed : null;
	const hasIndex = indexed !== null && indexed > 0;

	return (
		<>
			<h1 className="wp-heading-inline">
				{ sprintf(
					/* translators: %s: section name (Report, Broken links, Settings). */
					__( 'Magic Linking · %s', 'magic-linking' ),
					labels[ tab ]
				) }
			</h1>
			<hr className="wp-header-end" />

			<IndexStatus
				canManage={ canManage }
				onFinished={ onFinished }
				onStatus={ onStatus }
				refreshKey={ statusKey }
			/>

			{ summary && hasIndex && (
				<dl className="magiclinking-stats">
					<div>
						<dt>{ __( 'Entries analyzed', 'magic-linking' ) }</dt>
						<dd>{ summary.analyzed.toLocaleString() }</dd>
					</div>
					<div>
						<dt>{ __( 'Orphan entries', 'magic-linking' ) }</dt>
						<dd>{ summary.orphans.toLocaleString() }</dd>
					</div>
					<div>
						<dt>{ __( 'Internal links', 'magic-linking' ) }</dt>
						<dd>{ summary.internal_links.toLocaleString() }</dd>
					</div>
					<div>
						<dt>{ __( 'Broken links', 'magic-linking' ) }</dt>
						<dd>
							{ summary.broken_links > 0 ? (
								<a href={ tabUrls.broken }>
									{ summary.broken_links.toLocaleString() }
								</a>
							) : (
								0
							) }
						</dd>
					</div>
				</dl>
			) }

			{ ( hasIndex || tab === 'settings' ) && (
				<>
					<div className="magiclinking-panel">
						{ tab === 'report' && (
							<>
								<p className="description">
									{ __(
										'Only the links written inside each entry’s content are counted. Links in menus, widgets and theme templates are not.',
										'magic-linking'
									) }
								</p>
								<ReportTab
									refreshKey={ refreshKey }
									exportUrl={ exportUrl }
									onSummary={ onSummary }
									onBroken={ ( id, title ) => {
										window.location.assign(
											brokenUrl( tabUrls.broken, {
												id,
												title,
											} )
										);
									} }
								/>
							</>
						) }
						{ tab === 'broken' && (
							<BrokenTab
								refreshKey={ refreshKey }
								exportUrl={ exportUrl }
								only={ only }
								onClearOnly={ () =>
									window.location.assign( tabUrls.broken )
								}
							/>
						) }
						{ tab === 'settings' && canManage && (
							<SettingsTab
								status={ status }
								onReindex={ () =>
									setStatusKey( ( key ) => key + 1 )
								}
							/>
						) }
					</div>
				</>
			) }

			{ ! hasIndex && indexed !== null && (
				<p className="description">
					{ sprintf(
						/* translators: %s: WP-CLI command. */
						__(
							'You can also run the analysis from the terminal: %s',
							'magic-linking'
						),
						'wp magic-linking index'
					) }
				</p>
			) }
		</>
	);
}

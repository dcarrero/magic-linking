import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { ReportFilter, StatusResponse, Summary } from '../types';
import { BrokenTab } from './BrokenTab';
import { HistoryTab } from './HistoryTab';
import { IndexStatus } from './IndexStatus';
import { ReportTab } from './ReportTab';
import { SettingsTab } from './SettingsTab';
import { Wizard } from './Wizard';

type Tab = 'report' | 'broken' | 'history' | 'settings';

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
	history: 'admin.php?page=magic-linking-history',
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

	const [ wizardOpen, setWizardOpen ] = useState( false );
	const [ reportFilter, setReportFilter ] = useState< ReportFilter >( 'all' );
	const decided = useRef( false );

	// El asistente se abre solo una vez, en un sitio recién instalado (sin análisis) y mientras el usuario no
	// lo haya cerrado; también cuando se pide a propósito desde Ajustes (?wizard=1).
	useEffect( () => {
		if ( decided.current || ! status || ! canManage || tab !== 'report' ) {
			return;
		}
		decided.current = true;
		const params = new URLSearchParams( window.location.search );
		const asked = params.get( 'wizard' ) === '1';
		if ( asked ) {
			// Se pide una vez: al recargar después de cerrarlo no vuelve a abrirse.
			params.delete( 'wizard' );
			const query = params.toString();
			window.history.replaceState(
				null,
				'',
				window.location.pathname +
					( query ? `?${ query }` : '' ) +
					window.location.hash
			);
		}
		const fresh =
			( window.magiclinking?.wizard ?? false ) &&
			status.indexed === 0 &&
			status.job === null;
		setWizardOpen( asked || fresh );
	}, [ status, canManage, tab ] );

	const openWizard = () => {
		// Solo se navega: el asistente se abre por `?wizard=1` sin escribir nada antes, así que cancelar el
		// aviso de cambios sin guardar no deja ningún rastro en el servidor.
		const url = new URL( tabUrls.report, window.location.href );
		url.searchParams.set( 'wizard', '1' );
		window.location.assign( url.toString() );
	};

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
		history: __( 'History', 'magic-linking' ),
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

			{ wizardOpen && tab === 'report' && (
				<Wizard
					status={ status }
					onStarted={ () => setStatusKey( ( key ) => key + 1 ) }
					onClose={ () => setWizardOpen( false ) }
					onSeeOrphans={ () => {
						setReportFilter( 'orphans' );
						setWizardOpen( false );
					} }
				/>
			) }

			<IndexStatus
				hideIntro={ wizardOpen && tab === 'report' }
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

			{ ( hasIndex || tab === 'settings' || tab === 'history' ) && (
				<>
					<div
						className={
							tab === 'settings'
								? 'magiclinking-panel magiclinking-panel--plain'
								: 'magiclinking-panel'
						}
					>
						{ tab === 'report' && (
							<>
								<p className="description">
									{ __(
										'Only the links written inside each entry’s content are counted. Links in menus, widgets and theme templates are not.',
										'magic-linking'
									) }
								</p>
								<ReportTab
									key={ reportFilter }
									initialFilter={ reportFilter }
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
						{ tab === 'history' && (
							<HistoryTab
								settingsUrl={
									canManage ? tabUrls.settings : undefined
								}
							/>
						) }
						{ tab === 'settings' && canManage && (
							<SettingsTab
								status={ status }
								onOpenWizard={ openWizard }
								onReindex={ () =>
									setStatusKey( ( key ) => key + 1 )
								}
							/>
						) }
					</div>
				</>
			) }

			{ ! hasIndex && indexed !== null && tab !== 'history' && (
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

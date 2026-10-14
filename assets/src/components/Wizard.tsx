import { Button, CheckboxControl, Notice } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { speak } from '@wordpress/a11y';
import { __, sprintf } from '@wordpress/i18n';
import { api, errorMessage } from '../api';
import { useLoad } from '../hooks';
import type {
	ReportItem,
	StatusResponse,
	Summary,
	WizardResponse,
} from '../types';

interface Props {
	status: StatusResponse | null;
	/** Se llama al lanzar un análisis, para que el estado se refresque. */
	onStarted: () => void;
	/** Cierra el asistente (ya guardado como cerrado para este usuario). */
	onClose: () => void;
	/** Cierra el asistente y abre el informe con las huérfanas. */
	onSeeOrphans: () => void;
}

const STEPS = 3;

/**
 * Asistente de arranque (docs/07 §2): qué enlazamos, IA y primer análisis. Sin email, sin cuenta, sin tour: se
 * puede saltar en cualquier paso y se cierra para siempre; se reabre desde Ajustes.
 *
 * @param root0
 * @param root0.status
 * @param root0.onStarted
 * @param root0.onClose
 * @param root0.onSeeOrphans
 */
export function Wizard( { status, onStarted, onClose, onSeeOrphans }: Props ) {
	const [ step, setStep ] = useState( 1 );
	const [ types, setTypes ] = useState< string[] | null >( null );
	const [ busy, setBusy ] = useState( false );
	const [ failure, setFailure ] = useState( '' );
	const [ started, setStarted ] = useState( false );
	const heading = useRef< HTMLHeadingElement >( null );
	const first = useRef( true );

	const settings = useLoad(
		() => api.settings(),
		[],
		__( 'Could not load the settings.', 'magic-linking' )
	);
	const info = useLoad(
		() => api.wizard(),
		[],
		__( 'Could not load the setup information.', 'magic-linking' )
	);

	useEffect( () => {
		if ( settings.data && types === null ) {
			setTypes( settings.data.settings.post_types );
		}
	}, [ settings.data, types ] );

	// Al cambiar de paso el foco pasa al título, y se anuncia (no al abrir el asistente).
	useEffect( () => {
		if ( first.current ) {
			first.current = false;
			return;
		}
		heading.current?.focus();
		speak(
			sprintf(
				/* translators: 1: current step, 2: number of steps. */
				__( 'Step %1$s of %2$s', 'magic-linking' ),
				String( step ),
				String( STEPS )
			),
			'polite'
		);
	}, [ step ] );

	const running = status?.job ?? null;
	const analyzed = ( status?.indexed ?? 0 ) > 0;
	const done = step === STEPS && started && analyzed && running === null;

	const dismiss = async ( after: () => void ) => {
		setBusy( true );
		setFailure( '' );
		try {
			await api.setWizardDismissed( true );
			after();
		} catch ( e ) {
			setFailure(
				errorMessage(
					e,
					__( 'That did not work. Try again.', 'magic-linking' )
				)
			);
		} finally {
			setBusy( false );
		}
	};

	const next = async () => {
		if ( step === 1 && settings.data && types ) {
			const same =
				types.length === settings.data.settings.post_types.length &&
				types.every(
					( type ) =>
						settings.data?.settings.post_types.includes( type )
				);
			if ( ! same ) {
				setBusy( true );
				setFailure( '' );
				try {
					const saved = await api.saveSettings( {
						...settings.data.settings,
						post_types: types,
					} );
					if ( saved.job ) {
						setStarted( true );
						onStarted();
					}
				} catch ( e ) {
					setFailure(
						errorMessage(
							e,
							__(
								'The settings could not be saved.',
								'magic-linking'
							)
						)
					);
					setBusy( false );
					return;
				}
				setBusy( false );
			}
		}
		setStep( ( current ) => Math.min( STEPS, current + 1 ) );
	};

	const start = async () => {
		setBusy( true );
		setFailure( '' );
		try {
			await api.startIndex( false );
			setStarted( true );
			onStarted();
			speak( __( 'Analysis started.', 'magic-linking' ), 'polite' );
		} catch ( e ) {
			setFailure(
				errorMessage(
					e,
					__( 'That did not work. Try again.', 'magic-linking' )
				)
			);
		} finally {
			setBusy( false );
		}
	};

	const titles = [
		__( 'What do we link?', 'magic-linking' ),
		__( 'Artificial intelligence', 'magic-linking' ),
		done
			? __( 'All set', 'magic-linking' )
			: __( 'Analyze your site', 'magic-linking' ),
	];

	return (
		<section
			className="magiclinking-wizard"
			aria-labelledby="magiclinking-wizard-title"
		>
			<p className="magiclinking-wizard-step">
				{ sprintf(
					/* translators: 1: current step, 2: number of steps. */
					__( 'Setup · step %1$s of %2$s', 'magic-linking' ),
					String( step ),
					String( STEPS )
				) }
			</p>
			<h2 id="magiclinking-wizard-title" ref={ heading } tabIndex={ -1 }>
				{ titles[ step - 1 ] }
			</h2>

			{ step === 1 && (
				<StepContent
					info={ info.data }
					error={ settings.error || info.error }
					loading={ settings.loading || info.loading }
				>
					<fieldset className="magiclinking-wizard-types">
						<legend>
							{ __(
								'Content types to analyze',
								'magic-linking'
							) }
						</legend>
						{ ( settings.data?.post_types ?? [] ).map( ( type ) => (
							<CheckboxControl
								key={ type.name }
								__nextHasNoMarginBottom
								label={ type.label }
								checked={ ( types ?? [] ).includes(
									type.name
								) }
								onChange={ ( checked ) =>
									setTypes(
										checked
											? [ ...( types ?? [] ), type.name ]
											: ( types ?? [] ).filter(
													( name ) =>
														name !== type.name
											  )
									)
								}
							/>
						) ) }
					</fieldset>
					{ info.data && (
						<p>
							{ sprintf(
								/* translators: %s: language name. */
								__( 'Language detected: %s.', 'magic-linking' ),
								info.data.language
							) }{ ' ' }
							{ info.data.multilingual
								? __(
										'Your site is multilingual: we will link each language separately.',
										'magic-linking'
								  )
								: __(
										'Entries are only linked to others in the same language.',
										'magic-linking'
								  ) }
						</p>
					) }
				</StepContent>
			) }

			{ step === 2 && (
				<StepContent
					info={ info.data }
					error={ info.error }
					loading={ info.loading }
				>
					<p>{ info.data ? aiText( info.data.ai ) : '' }</p>
				</StepContent>
			) }

			{ step === 3 && (
				<div>
					{ done ? (
						<Done onSeeOrphans={ () => dismiss( onSeeOrphans ) } />
					) : (
						<>
							<p>
								{ __(
									'Analyzing only reads your content; it never changes it. Links are only added when you press Link.',
									'magic-linking'
								) }
							</p>
							{ running ? (
								<p>
									{ __(
										'The analysis is running. You can close this screen: it continues in the background.',
										'magic-linking'
									) }
								</p>
							) : (
								<Button
									variant="primary"
									disabled={ busy }
									accessibleWhenDisabled
									onClick={ start }
								>
									{ __( 'Start', 'magic-linking' ) }
								</Button>
							) }
						</>
					) }
				</div>
			) }

			{ failure && (
				<Notice status="error" isDismissible={ false }>
					{ failure }
				</Notice>
			) }

			<div className="magiclinking-actions">
				{ step > 1 && ! done && (
					<Button
						variant="secondary"
						disabled={ busy }
						accessibleWhenDisabled
						onClick={ () => setStep( step - 1 ) }
					>
						{ __( 'Back', 'magic-linking' ) }
					</Button>
				) }
				{ step < STEPS && (
					<Button
						variant="primary"
						disabled={
							busy ||
							( step === 1 &&
								( types === null || types.length === 0 ) )
						}
						accessibleWhenDisabled
						onClick={ next }
					>
						{ __( 'Next', 'magic-linking' ) }
					</Button>
				) }
				{ done ? (
					<Button
						variant="secondary"
						disabled={ busy }
						accessibleWhenDisabled
						onClick={ () => dismiss( onClose ) }
					>
						{ __( 'Close', 'magic-linking' ) }
					</Button>
				) : (
					<Button
						variant="tertiary"
						disabled={ busy }
						accessibleWhenDisabled
						onClick={ () => dismiss( onClose ) }
					>
						{ __( 'Skip setup', 'magic-linking' ) }
					</Button>
				) }
			</div>
			{ step === 1 && types !== null && types.length === 0 && (
				<p className="description">
					{ __(
						'Choose at least one content type.',
						'magic-linking'
					) }
				</p>
			) }
			<p className="description">
				{ __(
					'You can open this assistant again from Settings.',
					'magic-linking'
				) }
			</p>
		</section>
	);
}

/**
 * Frase sobre el conector de IA de WordPress (sin llamadas: solo si lo hay).
 * @param state Estado del conector.
 */
function aiText( state: WizardResponse[ 'ai' ] ): string {
	if ( state === 'connected' ) {
		return __(
			'Your WordPress has an AI connector. This version of Magic Linking does not use it yet: suggestions come from its own engine, which needs no AI. When AI features arrive they will use that connector, and nothing will be spent without showing you an estimate first.',
			'magic-linking'
		);
	}
	return __(
		'No AI connected: Magic Linking works with its own engine, which needs no AI. You can connect one whenever you like in Settings → Connectors.',
		'magic-linking'
	);
}

interface StepProps {
	info: WizardResponse | null;
	error: string;
	loading: boolean;
	children: React.ReactNode;
}

/**
 * Cuerpo de un paso que depende de datos del servidor.
 * @param root0
 * @param root0.info
 * @param root0.error
 * @param root0.loading
 * @param root0.children
 */
function StepContent( { info, error, loading, children }: StepProps ) {
	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}
	if ( loading && ! info ) {
		return <p>{ __( 'Loading…', 'magic-linking' ) }</p>;
	}
	return <>{ children }</>;
}

/**
 * Resumen al terminar el primer análisis.
 * @param root0
 * @param root0.onSeeOrphans
 */
function Done( { onSeeOrphans }: { onSeeOrphans: () => void } ) {
	const { data, error } = useLoad(
		() =>
			api.report( {
				filter: 'all',
				search: '',
				post_type: '',
				lang: '',
				orderby: 'inbound',
				order: 'asc',
				page: 1,
				per_page: 1,
			} ),
		[],
		__( 'Could not load the report.', 'magic-linking' )
	);

	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}
	if ( ! data ) {
		return <p>{ __( 'Loading…', 'magic-linking' ) }</p>;
	}
	const summary: Summary = data.summary;
	const entry: ReportItem | undefined = data.items[ 0 ];

	return (
		<>
			<p>
				{ sprintf(
					/* translators: 1: entries analyzed, 2: orphan entries, 3: internal links. */
					__(
						'%1$s entries analyzed · %2$s orphans · %3$s internal links',
						'magic-linking'
					),
					summary.analyzed.toLocaleString(),
					summary.orphans.toLocaleString(),
					summary.internal_links.toLocaleString()
				) }
			</p>
			<div className="magiclinking-actions">
				<Button variant="primary" onClick={ onSeeOrphans }>
					{ __( 'See orphans', 'magic-linking' ) }
				</Button>
				{ entry && (
					<Button variant="secondary" href={ entry.edit_url }>
						{ __( 'Open an entry', 'magic-linking' ) }
					</Button>
				) }
			</div>
		</>
	);
}

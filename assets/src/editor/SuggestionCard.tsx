import { useEffect, useId, useRef, useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __, _x, sprintf } from '@wordpress/i18n';
import { api } from '../api';
import type {
	AnchorNote,
	RerankImproved,
	Suggestion,
	SuggestionAlternative,
} from '../types';
import type { AiMark } from './ai';
import { AnchorAdjuster } from './AnchorAdjuster';
import { SentenceOptions } from './SentenceOptions';

/** Estado de una tarjeta después de pulsar «Enlazar». */
export type CardStatus =
	| { kind: 'busy' }
	| {
			kind: 'added';
			message: string;
			/** Deshace el enlace; devuelve el mensaje de lo ocurrido o null si no se pudo. */
			undo?: () => Promise< string | null >;
			/** Se escribió en el servidor: ya está en el Historial y la fila enseña el enlace a él. */
			history?: boolean;
			/** Solo entrantes: el servidor ya no la devuelve; se pinta donde estaba. */
			ghost?: { suggestion: Suggestion; index: number };
	  }
	| { kind: 'error'; message: string }
	/** Descartada para siempre; se puede deshacer hasta que se vuelva a pedir la lista. */
	| { kind: 'dismissing' }
	| {
			kind: 'dismissed';
			busy?: boolean;
			/** Solo entrantes: el servidor ya no la devuelve; se pinta donde estaba. */
			ghost?: { suggestion: Suggestion; index: number };
	  };

/** Más frases y ancla ajustable (docs/07 §3.1 y §3.2); sin esto, la tarjeta es la de siempre. */
export interface CardVariants {
	/** La principal y las alternativas; la 0 es la principal. */
	options: SuggestionAlternative[];
	/** Opción que se enseña ahora. */
	selected: number;
	onSelect: ( option: number ) => void;
	/** El usuario confirmó otro tramo de la frase como ancla (índices UTF-16). */
	onAdjust: (
		span: { start: number; end: number },
		notes?: AnchorNote[]
	) => void;
	/** Anclas que otras tarjetas de la lista llevan a otros destinos (solo salientes). */
	taken?: { anchor: string; target_id: number }[];
}

interface Props {
	suggestion: Suggestion;
	/** Más frases y ancla ajustable. */
	variants?: CardVariants;
	direction: 'outbound' | 'inbound';
	status?: CardStatus;
	/** Dirección de la pantalla Historial, para el enlace de las entrantes. */
	historyUrl: string;
	onLink: ( suggestion: Suggestion ) => void;
	onDismiss: ( suggestion: Suggestion ) => void;
	/** Deshace el descarte. */
	onRestore: ( suggestion: Suggestion ) => void;
	onUndo: ( suggestion: Suggestion ) => void | Promise< void >;
	/** Mientras se vuelve a pedir la lista, la tarjeta no se puede enlazar ni descartar (podría estar ya hecho). */
	locked?: boolean;
	/** Tras un Deshacer del usuario, la tarjeta que se despliega recibe el foco (un solo sitio: aquí). */
	focusRequested?: boolean;
	onFocusDone?: () => void;
	/** Resalta la frase en el lienzo (solo salientes). */
	onHighlight?: ( suggestion: Suggestion | null ) => void;
	/** Lo que la IA opina de esta sugerencia tras «Mejorar con IA» (solo salientes). */
	ai?: AiMark;
	/** Acepta el ancla mejor que propone la IA. */
	onUseAnchor?: ( improved: RerankImproved ) => void;
}

/**
 * Avisos de ancla repetida de lo que se enseña (docs/07 §3.3). Los de la frase del servidor vienen con ella; si el ancla
 * es otra (ajustada a mano o cambiada por la IA) el servidor aún no los ha dado (`null`) y se piden aquí, con la misma
 * regla que usa para comprobar el ancla. Si no se pueden leer, la tarjeta sigue sin avisos: informan, no bloquean.
 *
 * @param suggestion Sugerencia que se enseña.
 */
function useAnchorNotes( suggestion: Suggestion ): AnchorNote[] {
	const known = suggestion.anchor_notes;
	const key = `${ suggestion.source.id }|${ suggestion.target.id }|${ suggestion.sentence }|${ suggestion.offset }|${ suggestion.anchor }`;
	const [ asked, setAsked ] = useState< {
		key: string;
		notes: AnchorNote[];
	} | null >( null );
	const needed = known === null && suggestion.can_insert;

	useEffect( () => {
		if ( ! needed ) {
			return undefined;
		}
		const controller = new AbortController();
		api.checkAnchor(
			suggestion.source.id,
			suggestion.sentence,
			suggestion.offset,
			suggestion.anchor,
			suggestion.target.id,
			[],
			controller.signal
		)
			.then( ( reply ) => {
				if ( ! controller.signal.aborted ) {
					setAsked( { key, notes: reply.notes ?? [] } );
				}
			} )
			.catch( () => undefined );
		return () => controller.abort();
		// La clave resume todo lo que se envía.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ needed, key ] );

	if ( known ) {
		return known;
	}
	return asked?.key === key ? asked.notes : [];
}

/**
 * Una sugerencia: la frase con el ancla marcada, el destino, el motivo y las acciones (docs/07 §3).
 * @param root0
 * @param root0.suggestion
 * @param root0.variants
 * @param root0.direction
 * @param root0.status
 * @param root0.historyUrl
 * @param root0.onLink
 * @param root0.onDismiss
 * @param root0.onRestore
 * @param root0.onUndo
 * @param root0.onHighlight
 * @param root0.locked
 * @param root0.focusRequested
 * @param root0.onFocusDone
 * @param root0.ai
 * @param root0.onUseAnchor
 */
export function SuggestionCard( {
	suggestion,
	variants,
	direction,
	status,
	historyUrl,
	onLink,
	onDismiss,
	onRestore,
	onUndo,
	onHighlight,
	locked = false,
	focusRequested,
	onFocusDone,
	ai,
	onUseAnchor,
}: Props ) {
	// Al plegarse (enlazada o descartada) el botón pulsado desaparece: el foco pasa a la fila, que se lee entera.
	// Solo en el cambio de estado, no al montarse ya plegada (volver de otra pestaña no roba el foco).
	const cardRef = useRef< HTMLLIElement >( null );
	const statusRef = useRef< HTMLParagraphElement >( null );
	const previousKind = useRef< CardStatus[ 'kind' ] | undefined >(
		status?.kind
	);
	const notes = useAnchorNotes( suggestion );
	const [ undoing, setUndoing ] = useState( false );
	const [ adjusting, setAdjusting ] = useState( false );
	const [ moreOpen, setMoreOpen ] = useState( false );
	const adjustButton = useRef< HTMLButtonElement >( null );
	const moreId = useId();
	// Al cerrar el ajuste el botón vuelve a pintarse: el foco lo recoge cuando ya existe.
	const refocus = useRef( false );
	useEffect( () => {
		if ( ! adjusting && refocus.current ) {
			refocus.current = false;
			adjustButton.current?.focus();
		}
	}, [ adjusting ] );
	const kind = status?.kind;
	useEffect( () => {
		setUndoing( false );
		if ( previousKind.current === kind ) {
			return;
		}
		previousKind.current = kind;
		if ( kind === 'added' || kind === 'error' || kind === 'dismissed' ) {
			statusRef.current?.focus();
		}
	}, [ kind ] );
	// Tras un Deshacer del usuario la fila se va y el foco vuelve a la tarjeta desplegada (solo aquí).
	useEffect( () => {
		if ( focusRequested && ! kind ) {
			cardRef.current?.focus();
			onFocusDone?.();
		}
	}, [ focusRequested, kind, onFocusDone ] );
	// Otra frase u otro ancla elegidos con el foco en la tarjeta: el resaltado del lienzo los sigue.
	const shown = `${ suggestion.sentence }|${ suggestion.offset }|${ suggestion.anchor }`;
	const shownBefore = useRef( shown );
	useEffect( () => {
		if ( shownBefore.current === shown ) {
			return;
		}
		shownBefore.current = shown;
		if (
			cardRef.current?.contains(
				cardRef.current.ownerDocument.activeElement
			)
		) {
			onHighlight?.( suggestion );
		}
		// Solo cuando cambia lo que se enseña.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ shown ] );
	const { source, target } = suggestion;
	const title = ( post: { title?: string } ) =>
		post.title || __( '(no title)', 'magic-linking' );
	const busy = status?.kind === 'busy';
	const dismissing = status?.kind === 'dismissing';

	// Enlazada o descartada: una fila corta con el ancla, el destino y Deshacer (F1-31).
	if ( status?.kind === 'dismissed' || status?.kind === 'added' ) {
		const isAdded = status.kind === 'added';
		const message = isAdded
			? status.message
			: __(
					'Suggestion dismissed. It will not be proposed again.',
					'magic-linking'
			  );
		const canUndo = isAdded ? !! status.undo : true;
		const inbound = direction === 'inbound';
		// En las entrantes el destino es siempre la entrada que se edita: lo que distingue la fila es el origen.
		const subject = inbound ? source : target;
		let undoLabel: string;
		if ( ! isAdded ) {
			undoLabel = sprintf(
				/* translators: %s: title of the entry the suggestion is about. */
				__(
					'Undo dismissing the suggestion for “%s”',
					'magic-linking'
				),
				title( subject )
			);
		} else if ( inbound ) {
			undoLabel = sprintf(
				/* translators: %s: title of the entry that got the link. */
				__( 'Undo the link in “%s”', 'magic-linking' ),
				title( source )
			);
		} else {
			undoLabel = sprintf(
				/* translators: %s: title of the destination. */
				__( 'Undo the link to “%s”', 'magic-linking' ),
				title( target )
			);
		}
		const busyUndo = isAdded ? undoing : !! status.busy;
		return (
			<li
				className={ `magiclinking-card is-folded ${
					isAdded ? 'is-added' : 'is-dismissed'
				}` }
			>
				<p
					className="magiclinking-fold__text"
					ref={ statusRef }
					tabIndex={ -1 }
				>
					<span className="screen-reader-text">{ message } </span>
					<span
						aria-hidden="true"
						className="magiclinking-fold__state"
						title={ message }
					>
						{ isAdded ? '✓ ' : '✕ ' }
						{ isAdded
							? _x(
									'Linked',
									'state of a suggestion that has been linked',
									'magic-linking'
							  )
							: _x(
									'Dismissed',
									'state of a suggestion that has been dismissed',
									'magic-linking'
							  ) }
					</span>{ ' ' }
					{ inbound ? (
						<>
							<span className="screen-reader-text">
								{ __( 'In:', 'magic-linking' ) }{ ' ' }
							</span>
							<span aria-hidden="true">
								{ sprintf(
									/* translators: %s: title of the entry that would get the link. */
									__( 'In: %s', 'magic-linking' ),
									title( source )
								) }
							</span>
							<span className="screen-reader-text">
								{ ' ' }
								{ title( source ) }, { suggestion.anchor }
							</span>
						</>
					) : (
						<>
							<span className="magiclinking-fold__anchor">
								{ suggestion.anchor }
							</span>{ ' ' }
							<span aria-hidden="true">{ '→' }</span>
							<span className="screen-reader-text">
								{ ' ' }
								{ __( 'Links to:', 'magic-linking' ) }{ ' ' }
							</span>{ ' ' }
							<span>{ title( target ) }</span>
						</>
					) }
				</p>
				{ canUndo && (
					<Button
						variant="link"
						className="magiclinking-fold__undo"
						disabled={ busyUndo }
						accessibleWhenDisabled
						aria-busy={ busyUndo }
						onClick={ () => {
							if ( ! isAdded ) {
								onRestore( suggestion );
								return;
							}
							setUndoing( true );
							Promise.resolve( onUndo( suggestion ) ).finally(
								() => setUndoing( false )
							);
						} }
						aria-label={ undoLabel }
					>
						{ __( 'Undo', 'magic-linking' ) }
					</Button>
				) }
				{ isAdded && status.history && (
					<a
						className="magiclinking-fold__history"
						href={ historyUrl }
					>
						{ __( 'See in History', 'magic-linking' ) }
					</a>
				) }
			</li>
		);
	}

	const reasons = suggestion.reasons
		.map( ( reason ) => reason.text )
		.filter( Boolean );

	return (
		<li
			className="magiclinking-card"
			ref={ cardRef }
			tabIndex={ -1 }
			onMouseEnter={ () => onHighlight?.( suggestion ) }
			onMouseLeave={ () => onHighlight?.( null ) }
			onFocus={ () => onHighlight?.( suggestion ) }
			onBlur={ () => onHighlight?.( null ) }
		>
			{ direction === 'inbound' && (
				<p className="magiclinking-card__source">
					{ source.edit_url ? (
						<>
							{ __( 'In:', 'magic-linking' ) }{ ' ' }
							<a href={ source.edit_url }>{ title( source ) }</a>
						</>
					) : (
						sprintf(
							/* translators: %s: title of the entry that would get the link. */
							__( 'In: %s', 'magic-linking' ),
							title( source )
						)
					) }
					{ source.type_label ? ` (${ source.type_label })` : '' }
				</p>
			) }
			{ adjusting && variants ? (
				<AnchorAdjuster
					postId={ source.id }
					targetId={ target.id }
					taken={ variants.taken }
					sentence={ suggestion.sentence }
					start={ suggestion.anchor_start }
					end={ suggestion.anchor_end }
					onApply={ ( span, checked ) => {
						variants.onAdjust( span, checked );
						refocus.current = true;
						setAdjusting( false );
					} }
					onCancel={ () => {
						refocus.current = true;
						setAdjusting( false );
					} }
				/>
			) : (
				<p className="magiclinking-card__sentence">
					{ suggestion.before }
					<mark className="magiclinking-card__anchor">
						{ suggestion.anchor }
					</mark>
					{ suggestion.after }
				</p>
			) }
			{ variants && suggestion.can_insert && ! status && ! adjusting && (
				<div className="magiclinking-card__variants">
					<Button
						ref={ adjustButton }
						variant="link"
						onClick={ () => setAdjusting( true ) }
						aria-label={ sprintf(
							/* translators: %s: title of the destination. */
							__(
								'Adjust anchor, link to “%s”',
								'magic-linking'
							),
							title( target )
						) }
					>
						{ __( 'Adjust anchor', 'magic-linking' ) }
					</Button>
					{ variants.options.length > 1 && (
						<Button
							variant="link"
							aria-expanded={ moreOpen }
							aria-controls={ moreId }
							onClick={ () => setMoreOpen( ! moreOpen ) }
						>
							{ sprintf(
								/* translators: %d: number of sentences to choose from. */
								__( 'More sentences (%d)', 'magic-linking' ),
								variants.options.length
							) }
						</Button>
					) }
				</div>
			) }
			{ variants && variants.options.length > 1 && (
				<div
					id={ moreId }
					hidden={ ! moreOpen || !! status || adjusting }
				>
					<SentenceOptions
						options={ variants.options }
						selected={ variants.selected }
						name={ moreId }
						onSelect={ variants.onSelect }
					/>
				</div>
			) }
			<p className="magiclinking-card__target">
				<span aria-hidden="true">→ </span>
				<span className="screen-reader-text">
					{ __( 'Links to:', 'magic-linking' ) }{ ' ' }
				</span>
				{ target.url ? (
					<a
						href={ target.url }
						target="_blank"
						rel="noopener noreferrer"
					>
						{ title( target ) }
						<span className="screen-reader-text">
							{ ' ' }
							{ __( '(opens in a new tab)', 'magic-linking' ) }
						</span>
					</a>
				) : (
					title( target )
				) }
			</p>
			{ reasons.length > 0 && (
				<p className="magiclinking-card__reasons">
					{ reasons.join( ' · ' ) }
				</p>
			) }
			{ notes.length > 0 && ! status && ! adjusting && (
				<ul
					className="magiclinking-card__notes"
					aria-label={ __( 'Anchor notices', 'magic-linking' ) }
				>
					{ notes.map( ( note ) => (
						<li
							key={ note.code }
							className={ `magiclinking-card__note-item is-${ note.code }` }
						>
							<span aria-hidden="true">⚠ </span>
							{ note.text }
						</li>
					) ) }
				</ul>
			) }
			{ /* Con estado propio (enlazando, error, descartando) cambiar el ancla cambiaría la clave y lo perdería. */ }
			{ ai && direction === 'outbound' && ! status && (
				<AiNote
					mark={ ai }
					anchor={ suggestion.anchor }
					onUseAnchor={ onUseAnchor }
				/>
			) }
			{ ! suggestion.can_insert && (
				<p className="magiclinking-card__note">
					{ __(
						'You cannot edit this entry, so the link cannot be added from here.',
						'magic-linking'
					) }
				</p>
			) }
			{ status?.kind === 'error' && (
				<p
					className="magiclinking-card__status is-error"
					ref={ statusRef }
					tabIndex={ -1 }
				>
					{ status.message }
				</p>
			) }
			<div className="magiclinking-card__actions">
				{ suggestion.can_insert && (
					<Button
						variant="primary"
						size="compact"
						isBusy={ busy }
						disabled={ busy || dismissing || locked || adjusting }
						accessibleWhenDisabled
						onClick={ () => onLink( suggestion ) }
						aria-label={ sprintf(
							/* translators: %s: title of the destination. */
							__( 'Link to “%s”', 'magic-linking' ),
							title( target )
						) }
					>
						{ _x(
							'Link',
							'button: add the link to the text',
							'magic-linking'
						) }
					</Button>
				) }
				{ suggestion.can_insert && (
					<Button
						variant="tertiary"
						size="compact"
						isBusy={ dismissing }
						aria-busy={ dismissing }
						disabled={ busy || dismissing || locked }
						accessibleWhenDisabled
						onClick={ () => onDismiss( suggestion ) }
						aria-label={ sprintf(
							/* translators: %s: title of the destination. */
							__(
								'Dismiss the suggestion for “%s”',
								'magic-linking'
							),
							title( target )
						) }
					>
						{ __( 'Dismiss', 'magic-linking' ) }
					</Button>
				) }
			</div>
		</li>
	);
}

interface AiNoteProps {
	mark: AiMark;
	/** Ancla que se enseña ahora en la tarjeta. */
	anchor: string;
	onUseAnchor?: ( improved: RerankImproved ) => void;
}

/**
 * Lo que la IA opina de una sugerencia: si la ve apta (con texto, no solo color) y, a veces, un ancla mejor que el
 * usuario puede aceptar. El ancla nunca se cambia sola.
 *
 * @param root0
 * @param root0.mark
 * @param root0.anchor
 * @param root0.onUseAnchor
 */
function AiNote( { mark, anchor, onUseAnchor }: AiNoteProps ) {
	const [ accepted, setAccepted ] = useState( '' );
	const acceptedRef = useRef< HTMLParagraphElement >( null );

	// Al aceptar el ancla el botón desaparece: el foco pasa a la frase que lo confirma.
	useEffect( () => {
		if ( accepted !== '' ) {
			acceptedRef.current?.focus();
		}
	}, [ accepted ] );

	return (
		<div className="magiclinking-card__ai">
			{ mark.fit === false && (
				<p className="magiclinking-card__ai-fit is-unfit">
					<span aria-hidden="true">✕ </span>
					{ __(
						'The AI does not see this as a good link.',
						'magic-linking'
					) }
				</p>
			) }
			{ mark.fit === true && (
				<p className="magiclinking-card__ai-fit is-fit">
					<span aria-hidden="true">✓ </span>
					{ mark.confidence === null
						? __( 'Recommended by the AI.', 'magic-linking' )
						: sprintf(
								/* translators: %d: confidence of the AI, from 0 to 100. */
								__(
									'Recommended by the AI (confidence %d%%).',
									'magic-linking'
								),
								Math.round( mark.confidence * 100 )
						  ) }
				</p>
			) }
			{ mark.improved && onUseAnchor && accepted === '' && (
				<p className="magiclinking-card__ai-anchor">
					{ sprintf(
						/* translators: %s: anchor text the AI proposes. */
						__(
							'The AI suggests this anchor: “%s”.',
							'magic-linking'
						),
						mark.improved.anchor
					) }{ ' ' }
					<Button
						variant="link"
						onClick={ () => {
							if ( mark.improved ) {
								setAccepted( mark.improved.anchor );
								onUseAnchor( mark.improved );
							}
						} }
						aria-label={ sprintf(
							/* translators: %s: anchor text the AI proposes. */
							__( 'Use the anchor “%s”', 'magic-linking' ),
							mark.improved.anchor
						) }
					>
						{ __( 'Use this anchor', 'magic-linking' ) }
					</Button>
				</p>
			) }
			{ accepted !== '' && (
				<p
					className="magiclinking-card__ai-anchor"
					ref={ acceptedRef }
					tabIndex={ -1 }
				>
					{ sprintf(
						/* translators: %s: new anchor text. */
						__( 'Anchor changed to “%s”.', 'magic-linking' ),
						anchor
					) }
				</p>
			) }
		</div>
	);
}

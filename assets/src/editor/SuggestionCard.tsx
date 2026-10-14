import { useEffect, useRef, useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __, _x, sprintf } from '@wordpress/i18n';
import type { Suggestion } from '../types';

/** Estado de una tarjeta después de pulsar «Enlazar». */
export type CardStatus =
	| { kind: 'busy' }
	| {
			kind: 'added';
			message: string;
			/** Deshace el enlace; devuelve el mensaje de lo ocurrido o null si no se pudo. */
			undo?: () => Promise< string | null >;
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

interface Props {
	suggestion: Suggestion;
	direction: 'outbound' | 'inbound';
	status?: CardStatus;
	/** Dirección de la pantalla Historial, para el enlace de las entrantes. */
	historyUrl: string;
	onLink: ( suggestion: Suggestion ) => void;
	onDismiss: ( suggestion: Suggestion ) => void;
	/** Deshace el descarte. */
	onRestore: ( suggestion: Suggestion ) => void;
	onUndo: ( suggestion: Suggestion ) => void | Promise< void >;
	/** Tras un Deshacer del usuario, la tarjeta que se despliega recibe el foco (un solo sitio: aquí). */
	focusRequested?: boolean;
	onFocusDone?: () => void;
	/** Resalta la frase en el lienzo (solo salientes). */
	onHighlight?: ( suggestion: Suggestion | null ) => void;
}

/**
 * Una sugerencia: la frase con el ancla marcada, el destino, el motivo y las acciones (docs/07 §3).
 * @param root0
 * @param root0.suggestion
 * @param root0.direction
 * @param root0.status
 * @param root0.historyUrl
 * @param root0.onLink
 * @param root0.onDismiss
 * @param root0.onRestore
 * @param root0.onUndo
 * @param root0.onHighlight
 * @param root0.focusRequested
 * @param root0.onFocusDone
 */
export function SuggestionCard( {
	suggestion,
	direction,
	status,
	historyUrl,
	onLink,
	onDismiss,
	onRestore,
	onUndo,
	onHighlight,
	focusRequested,
	onFocusDone,
}: Props ) {
	// Al plegarse (enlazada o descartada) el botón pulsado desaparece: el foco pasa a la fila, que se lee entera.
	// Solo en el cambio de estado, no al montarse ya plegada (volver de otra pestaña no roba el foco).
	const cardRef = useRef< HTMLLIElement >( null );
	const statusRef = useRef< HTMLParagraphElement >( null );
	const previousKind = useRef< CardStatus[ 'kind' ] | undefined >(
		status?.kind
	);
	const [ undoing, setUndoing ] = useState( false );
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
				{ isAdded && inbound && (
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
			<p className="magiclinking-card__sentence">
				{ suggestion.before }
				<mark className="magiclinking-card__anchor">
					{ suggestion.anchor }
				</mark>
				{ suggestion.after }
			</p>
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
						disabled={ busy || dismissing }
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
						disabled={ busy || dismissing }
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

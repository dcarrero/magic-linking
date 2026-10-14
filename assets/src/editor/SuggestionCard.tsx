import { useEffect, useRef } from '@wordpress/element';
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
	onUndo: ( suggestion: Suggestion ) => void;
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
}: Props ) {
	// Al plegarse (enlazada o descartada) el botón pulsado desaparece: el foco pasa a la fila, que se lee entera.
	// Al deshacer, la fila desaparece y el foco vuelve a la tarjeta que se despliega.
	const cardRef = useRef< HTMLLIElement >( null );
	const statusRef = useRef< HTMLParagraphElement >( null );
	const previousKind = useRef< CardStatus[ 'kind' ] | undefined >(
		undefined
	);
	const kind = status?.kind;
	useEffect( () => {
		const before = previousKind.current;
		previousKind.current = kind;
		if ( kind === 'added' || kind === 'error' || kind === 'dismissed' ) {
			statusRef.current?.focus();
		} else if (
			( before === 'added' || before === 'dismissed' ) &&
			kind === undefined
		) {
			cardRef.current?.focus();
		}
	}, [ kind ] );
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
		const undoLabel = isAdded
			? sprintf(
					/* translators: %s: title of the destination. */
					__( 'Undo the link to “%s”', 'magic-linking' ),
					title( target )
			  )
			: sprintf(
					/* translators: %s: title of the destination. */
					__(
						'Undo dismissing the suggestion for “%s”',
						'magic-linking'
					),
					title( target )
			  );
		const busyUndo = ! isAdded && !! status.busy;
		return (
			<li
				className={ `magiclinking-card is-folded ${
					isAdded ? 'is-added' : 'is-dismissed'
				}` }
				ref={ cardRef }
				tabIndex={ -1 }
			>
				<p
					className="magiclinking-fold__text"
					ref={ statusRef }
					tabIndex={ -1 }
				>
					<span
						aria-hidden="true"
						className="magiclinking-fold__icon"
					>
						{ isAdded ? '✓' : '✕' }
					</span>
					<span className="screen-reader-text">{ message } </span>
					<span
						className="magiclinking-fold__anchor"
						title={ suggestion.anchor }
					>
						{ suggestion.anchor }
					</span>
					<span
						aria-hidden="true"
						className="magiclinking-fold__arrow"
					>
						{ '→' }
					</span>
					<span className="screen-reader-text">
						{ ' ' }
						{ __( 'Links to:', 'magic-linking' ) }{ ' ' }
					</span>
					<span
						className="magiclinking-fold__target"
						title={ title( target ) }
					>
						{ title( target ) }
					</span>
				</p>
				{ canUndo && (
					<Button
						variant="link"
						className="magiclinking-fold__undo"
						disabled={ busyUndo }
						accessibleWhenDisabled
						onClick={ () =>
							isAdded
								? onUndo( suggestion )
								: onRestore( suggestion )
						}
						aria-label={ undoLabel }
					>
						{ __( 'Undo', 'magic-linking' ) }
					</Button>
				) }
				{ isAdded && direction === 'inbound' && (
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

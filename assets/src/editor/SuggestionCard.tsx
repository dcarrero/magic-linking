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
	| { kind: 'error'; message: string };

interface Props {
	suggestion: Suggestion;
	direction: 'outbound' | 'inbound';
	status?: CardStatus;
	/** Dirección de la pantalla Historial, para el enlace de las entrantes. */
	historyUrl: string;
	onLink: ( suggestion: Suggestion ) => void;
	onDismiss: ( suggestion: Suggestion ) => void;
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
	onUndo,
	onHighlight,
}: Props ) {
	// El botón que se pulsó desaparece: el foco pasa al texto del resultado, que se lee entero.
	const statusRef = useRef< HTMLParagraphElement >( null );
	const kind = status?.kind;
	useEffect( () => {
		if ( kind === 'added' || kind === 'error' ) {
			statusRef.current?.focus();
		}
	}, [ kind ] );
	const { source, target } = suggestion;
	const title = ( post: { title?: string } ) =>
		post.title || __( '(no title)', 'magic-linking' );
	const busy = status?.kind === 'busy';
	const added = status?.kind === 'added';
	const reasons = suggestion.reasons
		.map( ( reason ) => reason.text )
		.filter( Boolean );

	return (
		<li
			className={ `magiclinking-card${ added ? ' is-added' : '' }` }
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

			{ added && status.kind === 'added' ? (
				<div className="magiclinking-card__actions">
					<p
						className="magiclinking-card__status"
						ref={ statusRef }
						tabIndex={ -1 }
					>
						{ status.message }
					</p>
					{ status.undo && (
						<Button
							variant="link"
							onClick={ () => onUndo( suggestion ) }
						>
							{ __( 'Undo', 'magic-linking' ) }
						</Button>
					) }
					{ direction === 'inbound' && (
						<a href={ historyUrl }>
							{ __( 'See in History', 'magic-linking' ) }
						</a>
					) }
				</div>
			) : (
				<div className="magiclinking-card__actions">
					{ suggestion.can_insert && (
						<Button
							variant="primary"
							size="compact"
							isBusy={ busy }
							disabled={ busy }
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
					<Button
						variant="tertiary"
						size="compact"
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
				</div>
			) }
		</li>
	);
}

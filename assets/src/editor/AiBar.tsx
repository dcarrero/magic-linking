import { Button } from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
import { __, _n, sprintf } from '@wordpress/i18n';
import { estimateText, tokensText, usageText } from './aiText';
import type { AiRerank } from './useAiRerank';

/**
 * Botón «Mejorar con IA» de las salientes, con a quién se envía y cuánto costaría, y el resultado de la última
 * llamada (docs/07 §3). Solo existe si el sitio tiene un conector de IA que genera texto y el usuario no apagó la IA
 * con el tope diario; sin IA, el panel queda exactamente como siempre.
 *
 * @param root0
 * @param root0.ai
 */
export function AiBar( { ai }: { ai: AiRerank } ) {
	const hintId = `magiclinking-ai-hint-${ useInstanceId( AiBar ) }`;
	const caps = ai.capabilities;
	if ( ! caps || ! caps.text || ! caps.enabled ) {
		return null;
	}

	const loading = ai.phase === 'loading';
	let hint = estimateText( caps );
	if ( caps.budget === 'exhausted' ) {
		hint = __(
			'The daily AI limit you set is reached. It will be available again tomorrow.',
			'magic-linking'
		);
	} else if ( caps.budget === 'too_small' ) {
		hint = sprintf(
			/* translators: 1: daily limit in tokens, 2: tokens one AI request needs. */
			__(
				'The daily AI limit you set (%1$s tokens) is lower than what one request needs (about %2$s). Raise it in Settings → AI.',
				'magic-linking'
			),
			tokensText( caps.limit ),
			tokensText( caps.needs )
		);
	}

	return (
		<div className="magiclinking-ai">
			<Button
				variant="secondary"
				size="compact"
				isBusy={ loading }
				disabled={ loading || ! caps.ready || ai.pending === 0 }
				accessibleWhenDisabled
				aria-describedby={ hintId }
				onClick={ ai.run }
			>
				<span className="magiclinking-ai__label">
					<span aria-hidden="true">✨</span>
					<span>
						{ loading
							? __( 'Improving…', 'magic-linking' )
							: __( 'Improve with AI', 'magic-linking' ) }
					</span>
				</span>
			</Button>
			<p id={ hintId } className="magiclinking-panel__hint">
				{ hint }
			</p>

			{ ai.phase === 'done' && (
				<p className="magiclinking-ai__result">
					{ __( 'Ordered by AI.', 'magic-linking' ) }{ ' ' }
					{ ai.unfit > 0 &&
						sprintf(
							/* translators: %d: number of suggestions the AI does not recommend. */
							_n(
								'%d suggestion is marked as not recommended.',
								'%d suggestions are marked as not recommended.',
								ai.unfit,
								'magic-linking'
							),
							ai.unfit
						) }{ ' ' }
					{ ai.usage && usageText( ai.usage ) }{ ' ' }
					<Button variant="link" onClick={ ai.reset }>
						{ __( 'Back to the original order', 'magic-linking' ) }
					</Button>
				</p>
			) }

			{ ai.phase === 'failed' && (
				<p className="magiclinking-ai__failed">{ ai.message }</p>
			) }
		</div>
	);
}

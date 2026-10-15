// Textos de cifras de la IA (tokens y coste) que comparten el panel del editor y Ajustes.
import { __, _x, sprintf } from '@wordpress/i18n';
import type { AiCapabilities, AiUsage } from '../types';

/**
 * Importe en dólares con los decimales que hagan falta para que una llamada barata no salga como 0,00 $.
 *
 * @param cost Dólares.
 */
export function money( cost: number ): string {
	const digits = cost > 0 && cost < 0.01 ? 4 : 2;
	return cost.toLocaleString( undefined, {
		style: 'currency',
		currency: 'USD',
		minimumFractionDigits: 2,
		maximumFractionDigits: digits,
	} );
}

/**
 * Número de tokens con separador de millares.
 *
 * @param tokens Tokens.
 */
export function tokensText( tokens: number ): string {
	return tokens.toLocaleString();
}

/**
 * Proveedor y modelo en una línea («OpenAI · gpt-4o-mini»).
 *
 * @param provider Proveedor.
 * @param model    Modelo.
 */
export function engineText(
	provider: string | null | undefined,
	model: string | null | undefined
): string {
	return [ provider, model ].filter( Boolean ).join( ' · ' );
}

/**
 * Lo que se enseña junto al botón antes de pulsarlo: a quién se envía y cuánto costaría, aproximadamente.
 *
 * @param caps Capacidades del conector.
 */
export function estimateText( caps: AiCapabilities ): string {
	const engine = engineText( caps.provider?.name, caps.model );
	const tokens = tokensText( caps.estimate.tokens );
	if ( caps.local ) {
		return sprintf(
			/* translators: 1: AI provider and model, 2: estimated tokens. */
			__( '%1$s · local, no cost · ~%2$s tokens', 'magic-linking' ),
			engine,
			tokens
		);
	}
	if ( caps.estimate.cost === null ) {
		return sprintf(
			/* translators: 1: AI provider and model, 2: estimated tokens. */
			__( '%1$s · ~%2$s tokens', 'magic-linking' ),
			engine,
			tokens
		);
	}
	return sprintf(
		/* translators: 1: AI provider and model, 2: estimated tokens, 3: estimated cost in dollars. */
		__( '%1$s · ~%2$s tokens · ~%3$s', 'magic-linking' ),
		engine,
		tokens,
		money( caps.estimate.cost )
	);
}

/**
 * Lo que costó una llamada ya hecha, con el modelo que respondió.
 *
 * @param usage Gasto de la llamada.
 */
export function usageText( usage: AiUsage ): string {
	const tokens = tokensText( usage.tokens );
	if ( usage.local ) {
		return sprintf(
			/* translators: 1: tokens used, 2: model name. */
			__(
				'Used %1$s tokens with %2$s (local model, no cost).',
				'magic-linking'
			),
			tokens,
			usage.model
		);
	}
	if ( usage.cost === null ) {
		return sprintf(
			/* translators: 1: tokens used, 2: model name. */
			__( 'Used %1$s tokens with %2$s.', 'magic-linking' ),
			tokens,
			usage.model
		);
	}
	return sprintf(
		/* translators: 1: tokens used, 2: estimated cost in dollars, 3: model name. */
		_x(
			'Used %1$s tokens (~%2$s) with %3$s.',
			'cost of one AI request',
			'magic-linking'
		),
		tokens,
		money( usage.cost ),
		usage.model
	);
}

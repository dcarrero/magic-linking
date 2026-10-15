import { Button, Notice, SelectControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { ReactNode } from 'react';
import { api } from '../api';
import { engineText, money, tokensText } from '../editor/aiText';
import { useLoad } from '../hooks';
import type { AiStatus } from '../types';
import { SettingsSection, type SectionInfo } from './SettingsSection';

interface Props {
	sections: Record< 'aiConnector' | 'aiLimits' | 'aiUsage', SectionInfo >;
	/** Modelo guardado en el borrador de Ajustes; vacío = automático. */
	model: string;
	onModel: ( model: string ) => void;
	/** Campo del tope diario (lo pinta Ajustes con el resto de números); recibe si va desactivado. */
	limitField: ( disabled: boolean ) => ReactNode;
}

/**
 * Las tarjetas de la pestaña IA (docs/05 §5.3 y §6, docs/07 §8): estado del conector, tope diario, modelo y gasto de
 * 30 días. En WordPress anterior a 7.0 no hay cliente de IA: los campos aparecen desactivados con su explicación.
 * Averiguar el estado no llama a ningún modelo.
 *
 * @param root0
 * @param root0.sections
 * @param root0.model
 * @param root0.onModel
 * @param root0.limitField
 */
export function AiSettingsCards( {
	sections,
	model,
	onModel,
	limitField,
}: Props ) {
	const [ check, setCheck ] = useState( 0 );
	const { data, loading, error } = useLoad(
		( signal ) => api.aiStatus( check > 0, signal ),
		[ check ],
		__( 'Could not load the AI status.', 'magic-linking' )
	);

	if ( ! data ) {
		return (
			<SettingsSection section={ sections.aiConnector }>
				{ error ? (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) : (
					<p role="status">
						{ loading ? __( 'Loading…', 'magic-linking' ) : '' }
					</p>
				) }
			</SettingsSection>
		);
	}

	const off = data.state === 'unavailable';

	return (
		<>
			<SettingsSection section={ sections.aiConnector }>
				<ConnectorStatus
					status={ data }
					checking={ loading }
					onCheck={ () => setCheck( ( value ) => value + 1 ) }
				/>
			</SettingsSection>
			<SettingsSection section={ sections.aiLimits }>
				{ limitField( off ) }
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Preferred model', 'magic-linking' ) }
					help={ __(
						'Automatic picks a small, fast model from your connector, the cheapest one when it can. Pick another if you prefer.',
						'magic-linking'
					) }
					disabled={ off }
					value={ model }
					options={ modelOptions( data, model ) }
					onChange={ onModel }
				/>
				<p className="description" role="status">
					{ budgetText( data ) }
				</p>
			</SettingsSection>
			<SettingsSection section={ sections.aiUsage }>
				<Usage status={ data } />
			</SettingsSection>
		</>
	);
}

/**
 * Opciones del selector de modelo: automático, los modelos que ofrece el conector y, si el guardado ya no está,
 * ese mismo marcado como no disponible (para no perder el ajuste sin avisar).
 *
 * @param status Estado de la IA.
 * @param saved  Modelo guardado.
 */
function modelOptions( status: AiStatus, saved: string ) {
	const options: { value: string; label: string }[] = [
		{
			value: '',
			label: __( 'Automatic (recommended)', 'magic-linking' ),
		},
	];
	const seen = new Set< string >();
	status.models.forEach( ( entry ) => {
		if ( seen.has( entry.model ) ) {
			return;
		}
		seen.add( entry.model );
		options.push( {
			value: entry.model,
			label: engineText( entry.model, entry.provider ),
		} );
	} );
	if ( saved !== '' && ! seen.has( saved ) ) {
		options.push( {
			value: saved,
			label: sprintf(
				/* translators: %s: model name. */
				__( '%s (not available now)', 'magic-linking' ),
				saved
			),
		} );
	}
	return options;
}

/**
 * Cuánto se ha gastado hoy del tope diario guardado.
 *
 * @param status Estado de la IA.
 */
function budgetText( status: AiStatus ): string {
	const { limit, used } = status.budget;
	if ( limit === 0 ) {
		return __(
			'AI is turned off: the daily limit is 0 tokens. Suggestions use the built-in engine.',
			'magic-linking'
		);
	}
	const text = sprintf(
		/* translators: 1: tokens used today, 2: daily limit in tokens. */
		__( 'Today: %1$s of %2$s tokens used.', 'magic-linking' ),
		tokensText( used ),
		tokensText( limit )
	);
	return status.budget.exhausted
		? `${ text } ${ __(
				'The limit is reached: AI is paused until tomorrow and suggestions use the built-in engine.',
				'magic-linking'
		  ) }`
		: text;
}

interface ConnectorProps {
	status: AiStatus;
	checking: boolean;
	onCheck: () => void;
}

/**
 * Estado del conector en lenguaje llano y la línea fija de privacidad (docs/05 §6).
 *
 * @param root0
 * @param root0.status
 * @param root0.checking
 * @param root0.onCheck
 */
function ConnectorStatus( { status, checking, onCheck }: ConnectorProps ) {
	if ( status.state === 'unavailable' ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				{ __(
					'AI needs WordPress 7.0 or later. This site runs an earlier version, so Magic Linking works with its own engine and the AI options are turned off.',
					'magic-linking'
				) }
			</Notice>
		);
	}

	if ( status.state === 'none' ) {
		return (
			<>
				<p>
					{ __(
						'This site has no AI connected. Magic Linking works fully without it: suggestions use its own engine.',
						'magic-linking'
					) }
				</p>
				<p>
					{ __(
						'You can connect yours in Settings → Connectors (OpenAI, Google, Ollama local…).',
						'magic-linking'
					) }
				</p>
				<p className="magiclinking-ai-actions">
					<Button
						variant="secondary"
						href={ status.connectors_url }
						aria-label={ __( 'Go to Connectors', 'magic-linking' ) }
					>
						{ __( 'Go to Connectors', 'magic-linking' ) }
					</Button>
					<Button
						variant="tertiary"
						onClick={ onCheck }
						isBusy={ checking }
						disabled={ checking }
						accessibleWhenDisabled
					>
						{ __( 'Check again', 'magic-linking' ) }
					</Button>
				</p>
			</>
		);
	}

	const provider = status.provider?.name ?? '';
	return (
		<>
			<dl className="magiclinking-ai-facts">
				<div>
					<dt>{ __( 'Provider', 'magic-linking' ) }</dt>
					<dd>{ provider }</dd>
				</div>
				<div>
					<dt>{ __( 'Model it will use', 'magic-linking' ) }</dt>
					<dd>{ status.model }</dd>
				</div>
				<div>
					<dt>{ __( 'Where it runs', 'magic-linking' ) }</dt>
					<dd>
						{ status.local
							? __( 'Local, on your own server', 'magic-linking' )
							: __(
									'Remote, at your provider',
									'magic-linking'
							  ) }
					</dd>
				</div>
			</dl>
			<p className="magiclinking-ai-privacy">
				{ status.local
					? __(
							'The AI on this site is local: the text does not leave your server.',
							'magic-linking'
					  )
					: sprintf(
							/* translators: %s: name of the AI provider. */
							__(
								'To use the AI suggestions, the text of the entries involved is sent to %s through your WordPress connector.',
								'magic-linking'
							),
							provider
					  ) }
			</p>
			<p className="magiclinking-ai-actions">
				<Button variant="secondary" href={ status.connectors_url }>
					{ __( 'Go to Connectors', 'magic-linking' ) }
				</Button>
				<Button
					variant="tertiary"
					onClick={ onCheck }
					isBusy={ checking }
					disabled={ checking }
					accessibleWhenDisabled
				>
					{ __( 'Check again', 'magic-linking' ) }
				</Button>
			</p>
		</>
	);
}

/**
 * Gasto de los últimos 30 días, por modelo. El importe es una estimación con los precios de lista.
 *
 * @param root0
 * @param root0.status
 */
function Usage( { status }: { status: AiStatus } ) {
	const { usage } = status;
	if ( usage.calls === 0 ) {
		return (
			<p>
				{ __( 'No AI requests in the last 30 days.', 'magic-linking' ) }
			</p>
		);
	}

	const total = usage.cost_partial
		? sprintf(
				/* translators: %s: estimated cost in dollars. */
				__( '%s (only models with a known price)', 'magic-linking' ),
				money( usage.cost )
		  )
		: money( usage.cost );

	return (
		<>
			<p>
				{ sprintf(
					/* translators: 1: number of AI requests, 2: tokens used, 3: estimated cost. */
					__(
						'%1$s requests, %2$s tokens, about %3$s.',
						'magic-linking'
					),
					usage.calls.toLocaleString(),
					tokensText( usage.tokens ),
					total
				) }
				{ usage.failed > 0 &&
					` ${ sprintf(
						/* translators: %s: number of AI requests that got no usable answer. */
						__(
							'Requests without an answer: %s.',
							'magic-linking'
						),
						usage.failed.toLocaleString()
					) }` }
			</p>
			<table className="widefat striped magiclinking-ai-usage">
				<caption className="screen-reader-text">
					{ __( 'AI use by model', 'magic-linking' ) }
				</caption>
				<thead>
					<tr>
						<th scope="col">{ __( 'Model', 'magic-linking' ) }</th>
						<th scope="col">
							{ __( 'Requests', 'magic-linking' ) }
						</th>
						<th scope="col">{ __( 'Tokens', 'magic-linking' ) }</th>
						<th scope="col">
							{ __( 'Estimated cost', 'magic-linking' ) }
						</th>
					</tr>
				</thead>
				<tbody>
					{ usage.models.map( ( row ) => (
						<tr key={ `${ row.provider }/${ row.model }` }>
							<th scope="row">{ row.model }</th>
							<td>{ row.calls.toLocaleString() }</td>
							<td>{ tokensText( row.tokens ) }</td>
							<td>{ costCell( row ) }</td>
						</tr>
					) ) }
				</tbody>
			</table>
			<p className="description">
				{ sprintf(
					/* translators: %s: date of the price list, like 2026-10-15. */
					__(
						'Costs are estimates with public list prices as of %s. What your provider charges is what counts. A local model costs nothing.',
						'magic-linking'
					),
					usage.prices_date
				) }
			</p>
		</>
	);
}

/**
 * Celda de coste de un modelo: local = sin coste; sin precio conocido = solo tokens.
 *
 * @param row       Fila del modelo.
 * @param row.local
 * @param row.cost
 */
function costCell( row: { local: boolean; cost: number | null } ): string {
	if ( row.local ) {
		return __( 'No cost (local)', 'magic-linking' );
	}
	return row.cost === null
		? __( 'Price unknown', 'magic-linking' )
		: money( row.cost );
}

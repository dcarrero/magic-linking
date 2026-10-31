import { Button, Notice, SelectControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { ReactNode } from 'react';
import { api } from '../api';
import { engineText, money, tokensText } from '../editor/aiText';
import { useLoad } from '../hooks';
import type { AiLastFailure, AiStatus, AiStatusEntry } from '../types';
import { formatDate } from './historyFormat';
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
					onChange={ onModel }
				>
					{ modelGroups( data, model ).map( ( group, index ) =>
						group.label === '' ? (
							group.options.map( ( o ) => (
								<option key={ o.value } value={ o.value }>
									{ o.label }
								</option>
							) )
						) : (
							<optgroup key={ index } label={ group.label }>
								{ group.options.map( ( o ) => (
									<option key={ o.value } value={ o.value }>
										{ o.label }
									</option>
								) ) }
							</optgroup>
						)
					) }
				</SelectControl>
				<p className="description" role="status">
					{ budgetText( data ) }
				</p>
			</SettingsSection>
			<SettingsSection section={ sections.aiUsage }>
				<Usage status={ data } />
				{ data.last_failure && (
					<LastFailure failure={ data.last_failure } />
				) }
			</SettingsSection>
		</>
	);
}

interface ModelOption {
	value: string;
	label: string;
}

interface ModelGroup {
	/** Vacío = opciones sueltas, sin grupo. */
	label: string;
	options: ModelOption[];
}

/**
 * Precio de un modelo para el desplegable: «sin coste (local)», «0,15 $ / 0,60 $ por millón» o nada si no se conoce.
 *
 * @param entry Modelo del servidor.
 */
function priceText( entry: AiStatusEntry ): string {
	if ( entry.local ) {
		return __( 'no cost (local)', 'magic-linking' );
	}
	if ( ! entry.price ) {
		return '';
	}
	return sprintf(
		/* translators: 1: dollars per million input tokens, 2: dollars per million output tokens. */
		__( '%1$s in / %2$s out per million', 'magic-linking' ),
		money( entry.price[ 0 ] ),
		money( entry.price[ 1 ] )
	);
}

/**
 * Etiqueta de un modelo: proveedor · modelo y, si se sabe, su precio.
 *
 * @param entry Modelo del servidor.
 */
function modelLabel( entry: AiStatusEntry ): string {
	const price = priceText( entry );
	const base = engineText( entry.provider_name, entry.model );
	return price === '' ? base : `${ base } (${ price })`;
}

/**
 * Grupos del selector de modelo: «Automático (recomendado)», los recomendados (el servidor decide cuáles y en qué
 * orden; este código no repite esa lógica), todos los demás por nombre y, si el guardado ya no está, ese mismo marcado
 * como no disponible (para no perder el ajuste sin avisar).
 *
 * @param status Estado de la IA.
 * @param saved  Modelo guardado.
 */
export function modelGroups( status: AiStatus, saved: string ): ModelGroup[] {
	const seen = new Set< string >();
	const recommended: ModelOption[] = [];
	const others: ModelOption[] = [];
	status.models.forEach( ( entry ) => {
		if ( seen.has( entry.model ) ) {
			return;
		}
		seen.add( entry.model );
		( entry.recommended ? recommended : others ).push( {
			value: entry.model,
			label: modelLabel( entry ),
		} );
	} );

	const groups: ModelGroup[] = [
		{
			label: '',
			options: [
				{
					value: '',
					label: __( 'Automatic (recommended)', 'magic-linking' ),
				},
			],
		},
	];
	if ( recommended.length > 0 ) {
		groups.push( {
			label: __( 'Recommended', 'magic-linking' ),
			options: recommended,
		} );
	}
	if ( others.length > 0 ) {
		groups.push( {
			label: __( 'All models', 'magic-linking' ),
			options: others,
		} );
	}
	if ( saved !== '' && ! seen.has( saved ) ) {
		groups.push( {
			label: '',
			options: [
				{
					value: saved,
					label: sprintf(
						/* translators: %s: model name. */
						__( '%s (not available now)', 'magic-linking' ),
						saved
					),
				},
			],
		} );
	}
	return groups;
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

/**
 * Fecha y hora de una marca del servidor, en la zona y el formato del sitio; «—» si no es una fecha real.
 *
 * @param iso Fecha del servidor.
 */
function whenText( iso: string ): string {
	return formatDate( iso );
}

/**
 * Última llamada fallida con el código y el mensaje técnico saneado por el servidor (sin claves ni texto de las
 * entradas), para que el administrador pueda ver por qué «la IA no respondió».
 *
 * @param root0
 * @param root0.failure
 */
export function LastFailure( { failure }: { failure: AiLastFailure } ) {
	return (
		<div className="magiclinking-ai-lastfailure">
			<h4>{ __( 'Last failed request', 'magic-linking' ) }</h4>
			<p>
				{ sprintf(
					/* translators: 1: date and time, 2: model name (may be empty), 3: error code. */
					__( '%1$s · %2$s · code: %3$s', 'magic-linking' ),
					whenText( failure.at ),
					failure.model === ''
						? __( 'unknown model', 'magic-linking' )
						: failure.model,
					failure.code
				) }
			</p>
			{ failure.message !== '' && (
				<p>
					<code>{ failure.message }</code>
				</p>
			) }
		</div>
	);
}

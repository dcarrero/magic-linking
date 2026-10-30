import { useEffect, useRef, useState } from '@wordpress/element';
import { speak } from '@wordpress/a11y';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api, errorMessage } from '../api';
import { useDebounced, useLoad } from '../hooks';
import type { RuleInput, RuleItem, RuleOptions } from '../types';
import { SearchBox } from './SearchBox';

interface Props {
	/** Regla que se edita; null para una nueva. */
	rule: RuleItem | null;
	options: RuleOptions | null;
	/** La regla se ha guardado. */
	onSaved: ( rule: RuleItem, created: boolean ) => void;
	onCancel: () => void;
}

/**
 * Valores iniciales del formulario.
 * @param rule Regla que se edita, si la hay.
 */
function initial( rule: RuleItem | null ) {
	return {
		phrases: rule ? rule.phrases.join( '\n' ) : '',
		maxPerPost: String( rule?.max_per_post ?? 1 ),
		firstOnly: rule?.first_only ?? true,
		wordGap: rule?.word_gap ?? false,
		excludeTypes: rule?.exclude_post_types ?? [],
		excludeCategories: rule?.exclude_categories ?? [],
		active: rule?.active ?? true,
	};
}

/**
 * Crear o editar una regla automática: frase y variantes, destino, cuántos enlaces por entrada, primera aparición,
 * hueco de una palabra y exclusiones por tipo o categoría. Todo con etiquetas, ayudas enlazadas con `aria-describedby`
 * y los errores del servidor en un aviso que recibe el foco.
 * @param root0
 * @param root0.rule
 * @param root0.options
 * @param root0.onSaved
 * @param root0.onCancel
 */
export function RuleForm( { rule, options, onSaved, onCancel }: Props ) {
	const [ values, setValues ] = useState( () => initial( rule ) );
	const [ target, setTarget ] = useState< {
		id: number;
		title: string;
	} | null >(
		rule ? { id: rule.target_id, title: rule.target_title } : null
	);
	const [ changingTarget, setChangingTarget ] = useState( ! rule );
	const [ search, setSearch ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ failure, setFailure ] = useState( '' );
	const heading = useRef< HTMLHeadingElement >( null );
	const alertRef = useRef< HTMLParagraphElement >( null );
	const debounced = useDebounced( search );

	useEffect( () => {
		heading.current?.focus();
	}, [] );

	useEffect( () => {
		if ( failure ) {
			alertRef.current?.focus();
		}
	}, [ failure ] );

	const targets = useLoad(
		( signal ) =>
			changingTarget
				? api.ruleTargets( debounced, signal )
				: Promise.resolve( { items: [] } ),
		[ debounced, changingTarget ],
		__( 'Could not search the entries.', 'magic-linking' )
	);

	const set = < K extends keyof typeof values >(
		key: K,
		value: ( typeof values )[ K ]
	) => setValues( ( previous ) => ( { ...previous, [ key ]: value } ) );

	const toggle = ( list: ( string | number )[], item: string | number ) =>
		list.includes( item )
			? list.filter( ( entry ) => entry !== item )
			: [ ...list, item ];

	const save = async ( event: { preventDefault: () => void } ) => {
		event.preventDefault();
		if ( busy ) {
			return;
		}
		if ( target === null ) {
			setFailure(
				__( 'Pick the entry the rule should link to.', 'magic-linking' )
			);
			return;
		}
		const input: RuleInput = {
			phrases: values.phrases
				.split( /\r?\n/ )
				.map( ( line ) => line.trim() )
				.filter( Boolean ),
			target_id: target.id,
			max_per_post: Math.round( Number( values.maxPerPost ) ) || 1,
			first_only: values.firstOnly,
			word_gap: values.wordGap,
			exclude_post_types: values.excludeTypes,
			exclude_categories: values.excludeCategories,
			active: values.active,
		};
		setBusy( true );
		setFailure( '' );
		try {
			// Al editar, el destino solo se envía si cambió: así el servidor no lo revalida sin necesidad.
			const { target_id: chosen, ...rest } = input;
			const response = rule
				? await api.updateRule(
						rule.id,
						chosen === rule.target_id ? rest : input
				  )
				: await api.createRule( input );
			onSaved( response.rule, ! rule );
		} catch ( error ) {
			setFailure(
				errorMessage(
					error,
					__(
						'The rule could not be saved. Nothing was changed.',
						'magic-linking'
					)
				)
			);
			setBusy( false );
		}
	};

	const limits = options?.limits;

	return (
		<form
			className="magiclinking-rule-form"
			onSubmit={ save }
			aria-labelledby="magiclinking-rule-form-title"
			noValidate
		>
			<h2
				id="magiclinking-rule-form-title"
				ref={ heading }
				tabIndex={ -1 }
			>
				{ rule
					? __( 'Edit rule', 'magic-linking' )
					: __( 'Add a rule', 'magic-linking' ) }
			</h2>

			{ failure && (
				<p
					ref={ alertRef }
					tabIndex={ -1 }
					role="alert"
					className="magiclinking-error"
				>
					{ failure }
				</p>
			) }

			<p>
				<label htmlFor="magiclinking-rule-phrases">
					<strong>
						{ __( 'Phrases to link', 'magic-linking' ) }
					</strong>{ ' ' }
					<span className="description">
						{ __( '(required)', 'magic-linking' ) }
					</span>
				</label>
				<textarea
					id="magiclinking-rule-phrases"
					className="large-text"
					rows={ 4 }
					value={ values.phrases }
					onChange={ ( event ) =>
						set( 'phrases', event.target.value )
					}
					aria-describedby="magiclinking-rule-phrases-help"
					required
				/>
				<span
					id="magiclinking-rule-phrases-help"
					className="description"
				>
					{ __(
						'One phrase per line: the first is the main one and the rest are variants. Plurals and similar forms are matched too, so “heat pump” also finds “heat pumps”. The text is linked exactly as it is written in the entry.',
						'magic-linking'
					) }
				</span>
			</p>

			<fieldset>
				<legend>
					<strong>{ __( 'Destination', 'magic-linking' ) }</strong>
				</legend>
				{ target && ! changingTarget ? (
					<p>
						{ target.title }{ ' ' }
						<button
							type="button"
							className="button-link"
							onClick={ () => setChangingTarget( true ) }
						>
							{ __( 'Change destination', 'magic-linking' ) }
						</button>
					</p>
				) : (
					<>
						<SearchBox
							id="magiclinking-rule-target-search"
							label={ __(
								'Search entries by title',
								'magic-linking'
							) }
							value={ search }
							onChange={ setSearch }
						/>
						<div role="status" className="screen-reader-text">
							{ ! targets.loading && targets.data && debounced
								? sprintf(
										/* translators: %d: number of entries found. */
										_n(
											'%d entry found',
											'%d entries found',
											targets.data.items.length,
											'magic-linking'
										),
										targets.data.items.length
								  )
								: '' }
						</div>
						{ targets.error && (
							<p className="magiclinking-error" role="alert">
								{ targets.error }
							</p>
						) }
						<div
							className="magiclinking-targets"
							aria-busy={ targets.loading }
						>
							{ ! debounced && (
								<p className="description">
									{ __(
										'Type part of the title of the entry the links should lead to.',
										'magic-linking'
									) }
								</p>
							) }
							{ debounced &&
								targets.data &&
								targets.data.items.length === 0 && (
									<p>
										{ __(
											'No published entries match.',
											'magic-linking'
										) }
									</p>
								) }
							<ul>
								{ ( targets.data?.items ?? [] ).map(
									( item ) => (
										<li key={ item.id }>
											<label
												htmlFor={ `magiclinking-rule-target-${ item.id }` }
											>
												<input
													id={ `magiclinking-rule-target-${ item.id }` }
													type="radio"
													name="magiclinking-rule-target"
													value={ item.id }
													checked={
														target?.id === item.id
													}
													onChange={ () => {
														setTarget( {
															id: item.id,
															title: item.title,
														} );
														speak(
															sprintf(
																/* translators: %s: entry title. */
																__(
																	'Destination: %s',
																	'magic-linking'
																),
																item.title
															),
															'polite'
														);
													} }
												/>{ ' ' }
												<strong>{ item.title }</strong>
												<br />
												<span className="magiclinking-url">
													{ item.url }
												</span>
											</label>
										</li>
									)
								) }
							</ul>
							{ target && (
								<p>
									{ sprintf(
										/* translators: %s: entry title. */
										__( 'Selected: %s', 'magic-linking' ),
										target.title
									) }{ ' ' }
									{ rule && (
										<button
											type="button"
											className="button-link"
											onClick={ () => {
												setTarget( {
													id: rule.target_id,
													title: rule.target_title,
												} );
												setChangingTarget( false );
											} }
										>
											{ __(
												'Keep the current one',
												'magic-linking'
											) }
										</button>
									) }
								</p>
							) }
						</div>
					</>
				) }
			</fieldset>

			<p>
				<label htmlFor="magiclinking-rule-max">
					<strong>
						{ __( 'Links per entry', 'magic-linking' ) }
					</strong>
				</label>
				<br />
				<input
					id="magiclinking-rule-max"
					type="number"
					min={ 1 }
					max={ 10 }
					className="small-text"
					value={ values.maxPerPost }
					onChange={ ( event ) =>
						set( 'maxPerPost', event.target.value )
					}
					aria-describedby="magiclinking-rule-max-help"
				/>
				<br />
				<span id="magiclinking-rule-max-help" className="description">
					{ limits
						? sprintf(
								/* translators: %d: links to the same target allowed from one entry (a setting). */
								_n(
									'The most links this rule adds to one entry. Your settings allow %d link to the same target from one entry, so that is the real maximum.',
									'The most links this rule adds to one entry. Your settings allow %d links to the same target from one entry, so that is the real maximum.',
									limits.links_per_target,
									'magic-linking'
								),
								limits.links_per_target
						  )
						: __(
								'The most links this rule adds to one entry.',
								'magic-linking'
						  ) }
				</span>
			</p>

			<p>
				<label htmlFor="magiclinking-rule-first">
					<input
						id="magiclinking-rule-first"
						type="checkbox"
						checked={ values.firstOnly }
						onChange={ ( event ) =>
							set( 'firstOnly', event.target.checked )
						}
					/>{ ' ' }
					{ __(
						'Only the first appearance of each phrase',
						'magic-linking'
					) }
				</label>
			</p>
			<p>
				<label htmlFor="magiclinking-rule-gap">
					<input
						id="magiclinking-rule-gap"
						type="checkbox"
						checked={ values.wordGap }
						onChange={ ( event ) =>
							set( 'wordGap', event.target.checked )
						}
						aria-describedby="magiclinking-rule-gap-help"
					/>{ ' ' }
					{ __(
						'Allow one extra word inside a phrase',
						'magic-linking'
					) }
				</label>
				<br />
				<span id="magiclinking-rule-gap-help" className="description">
					{ __(
						'“kitchen remodel” also matches “kitchen full remodel”, but not two extra words, and never across punctuation.',
						'magic-linking'
					) }
				</span>
			</p>

			{ options && options.post_types.length > 0 && (
				<fieldset>
					<legend>
						<strong>
							{ __( 'Do not apply to', 'magic-linking' ) }
						</strong>
					</legend>
					{ options.post_types.map( ( type ) => (
						<p key={ type.name }>
							<label
								htmlFor={ `magiclinking-rule-type-${ type.name }` }
							>
								<input
									id={ `magiclinking-rule-type-${ type.name }` }
									type="checkbox"
									checked={ values.excludeTypes.includes(
										type.name
									) }
									onChange={ () =>
										set(
											'excludeTypes',
											toggle(
												values.excludeTypes,
												type.name
											) as string[]
										)
									}
								/>{ ' ' }
								{ type.label }
							</label>
						</p>
					) ) }
				</fieldset>
			) }

			{ options && options.categories.length > 0 && (
				<fieldset>
					<legend>
						<strong>
							{ __(
								'Skip entries in these categories',
								'magic-linking'
							) }
						</strong>
					</legend>
					<div
						className="magiclinking-targets"
						role="group"
						tabIndex={ 0 }
						aria-label={ __(
							'Categories to skip',
							'magic-linking'
						) }
					>
						<ul>
							{ options.categories.map( ( category ) => (
								<li key={ category.id }>
									<label
										htmlFor={ `magiclinking-rule-cat-${ category.id }` }
									>
										<input
											id={ `magiclinking-rule-cat-${ category.id }` }
											type="checkbox"
											checked={ values.excludeCategories.includes(
												category.id
											) }
											onChange={ () =>
												set(
													'excludeCategories',
													toggle(
														values.excludeCategories,
														category.id
													) as number[]
												)
											}
										/>{ ' ' }
										{ category.name }
									</label>
								</li>
							) ) }
						</ul>
					</div>
				</fieldset>
			) }

			<p>
				<label htmlFor="magiclinking-rule-active">
					<input
						id="magiclinking-rule-active"
						type="checkbox"
						checked={ values.active }
						onChange={ ( event ) =>
							set( 'active', event.target.checked )
						}
					/>{ ' ' }
					{ __(
						'Active: apply it when an entry is saved',
						'magic-linking'
					) }
				</label>
			</p>

			<div className="magiclinking-actions">
				<button
					type="submit"
					className="button button-primary"
					disabled={ busy }
					aria-disabled={ busy }
				>
					{ rule
						? __( 'Save rule', 'magic-linking' )
						: __( 'Add rule', 'magic-linking' ) }
				</button>
				<button
					type="button"
					className="button"
					onClick={ onCancel }
					disabled={ busy }
				>
					{ __( 'Cancel', 'magic-linking' ) }
				</button>
			</div>
		</form>
	);
}

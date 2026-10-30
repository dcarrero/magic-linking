import { useEffect, useRef, useState } from '@wordpress/element';
import { speak } from '@wordpress/a11y';
import { __, _n, sprintf } from '@wordpress/i18n';
import type { ReactNode } from 'react';
import { api, errorMessage, RULES_PER_PAGE } from '../api';
import { useLoad } from '../hooks';
import type { RuleItem } from '../types';
import { ApplyRuleModal } from './ApplyRuleModal';
import { ConfirmModal } from './ConfirmModal';
import { Column, DataTable } from './DataTable';
import { RuleForm } from './RuleForm';
import { pageAfterChange, rulesTotalPages } from './rulesPaging';

interface Props {
	settingsUrl?: string;
}

/**
 * Pantalla de reglas automáticas: lista sin límite de reglas, formulario para crear o editar y, por regla, *Apply
 * to existing entries* (entrada a entrada, con vista previa). Gestionarlas pide `manage_options`.
 * @param root0
 * @param root0.settingsUrl
 */
export function RulesTab( { settingsUrl }: Props ) {
	const [ page, setPage ] = useState( 1 );
	const [ version, setVersion ] = useState( 0 );
	const [ editing, setEditing ] = useState< RuleItem | 'new' | null >( null );
	const [ applying, setApplying ] = useState< RuleItem | null >( null );
	const [ deleting, setDeleting ] = useState< RuleItem | null >( null );
	const [ busy, setBusy ] = useState( false );
	const [ notice, setNotice ] = useState< {
		text: string;
		error?: boolean;
	} | null >( null );
	const noticeRef = useRef< HTMLDivElement >( null );
	const addButton = useRef< HTMLButtonElement >( null );

	const { data, loading, error } = useLoad(
		( signal ) => api.rules( page, signal ),
		[ page, version ],
		__( 'Could not load the rules.', 'magic-linking' )
	);
	const options = useLoad(
		( signal ) => api.ruleOptions( signal ),
		[ version ],
		__( 'Could not load the options.', 'magic-linking' )
	);

	// Si se borra la última regla de una página, se vuelve a la última que existe en vez de mostrar una vacía.
	useEffect( () => {
		if ( data ) {
			const next = pageAfterChange( page, data.total, RULES_PER_PAGE );
			if ( next !== page ) {
				setPage( next );
			}
		}
	}, [ data, page ] );

	useEffect( () => {
		if ( notice ) {
			noticeRef.current?.focus();
		}
	}, [ notice ] );

	const tell = ( text: string, isError = false ) => {
		setNotice( { text, error: isError } );
		speak( text, isError ? 'assertive' : 'polite' );
	};

	const toggleActive = async ( rule: RuleItem ) => {
		setBusy( true );
		try {
			await api.updateRule( rule.id, { active: ! rule.active } );
			setVersion( ( value ) => value + 1 );
			tell(
				rule.active
					? __( 'Rule paused.', 'magic-linking' )
					: __( 'Rule activated.', 'magic-linking' )
			);
		} catch ( e ) {
			tell(
				errorMessage(
					e,
					__( 'The rule could not be changed.', 'magic-linking' )
				),
				true
			);
		}
		setBusy( false );
	};

	const remove = async () => {
		if ( ! deleting ) {
			return;
		}
		setBusy( true );
		try {
			await api.deleteRule( deleting.id );
			setDeleting( null );
			setVersion( ( value ) => value + 1 );
			tell(
				__(
					'Rule deleted. The links it already added stay in your entries; you can undo them from the history.',
					'magic-linking'
				)
			);
		} catch ( e ) {
			setDeleting( null );
			tell(
				errorMessage(
					e,
					__( 'The rule could not be deleted.', 'magic-linking' )
				),
				true
			);
		}
		setBusy( false );
	};

	const typeLabel = ( name: string ) =>
		options.data?.post_types.find( ( type ) => type.name === name )
			?.label ?? name;

	// Lo que la regla añade de verdad por entrada: nunca más de lo que permiten los ajustes.
	const effective = ( rule: RuleItem ) =>
		Math.min(
			rule.max_per_post,
			data?.limits.links_per_target ?? rule.max_per_post
		);

	const columns: Column< RuleItem, never >[] = [
		{
			id: 'phrases',
			header: __( 'Phrases', 'magic-linking' ),
			render: ( rule ) => (
				<>
					<strong>{ rule.phrases[ 0 ] }</strong>
					{ rule.phrases.length > 1 && (
						<>
							<br />
							<span className="description">
								{ rule.phrases.slice( 1 ).join( ' · ' ) }
							</span>
						</>
					) }
				</>
			),
		},
		{
			id: 'destination',
			header: __( 'Destination', 'magic-linking' ),
			render: ( rule ) =>
				rule.target_available ? (
					<a href={ rule.target_url }>{ rule.target_title }</a>
				) : (
					<span className="magiclinking-warn">
						{ rule.target_title ||
							__( '(missing entry)', 'magic-linking' ) }
						{ ' — ' }
						{ __( 'not published', 'magic-linking' ) }
					</span>
				),
		},
		{
			id: 'options',
			header: __( 'Options', 'magic-linking' ),
			render: ( rule ) => {
				const parts: ReactNode[] = [
					sprintf(
						/* translators: %d: links per entry. */
						_n(
							'%d link per entry',
							'%d links per entry',
							effective( rule ),
							'magic-linking'
						),
						effective( rule )
					),
				];
				if ( rule.first_only ) {
					parts.push(
						__( 'first appearance only', 'magic-linking' )
					);
				}
				if ( rule.word_gap ) {
					parts.push(
						__( 'one extra word allowed', 'magic-linking' )
					);
				}
				if ( rule.exclude_post_types.length > 0 ) {
					parts.push(
						sprintf(
							/* translators: %s: content types, comma separated. */
							__( 'skips %s', 'magic-linking' ),
							rule.exclude_post_types
								.map( typeLabel )
								.join( ', ' )
						)
					);
				}
				if ( rule.exclude_categories.length > 0 ) {
					parts.push(
						sprintf(
							/* translators: %d: number of categories. */
							_n(
								'skips %d category',
								'skips %d categories',
								rule.exclude_categories.length,
								'magic-linking'
							),
							rule.exclude_categories.length
						)
					);
				}
				return parts.join( ' · ' );
			},
		},
		{
			id: 'status',
			header: __( 'Status', 'magic-linking' ),
			render: ( rule ) =>
				rule.active ? (
					<span className="magiclinking-badge magiclinking-badge--ok">
						{ __( 'Active', 'magic-linking' ) }
					</span>
				) : (
					<span className="magiclinking-badge magiclinking-badge--undone">
						{ __( 'Paused', 'magic-linking' ) }
					</span>
				),
		},
	];

	const rowActions = ( rule: RuleItem ) => {
		const label = ( text: string ) => (
			<>
				{ text }
				<span className="screen-reader-text">
					{ ' ' + rule.phrases[ 0 ] }
				</span>
			</>
		);
		return [
			{
				id: 'apply',
				node: (
					<button
						type="button"
						className="button-link"
						onClick={ () => setApplying( rule ) }
						disabled={ ! rule.target_available }
					>
						{ label(
							__( 'Apply to existing entries', 'magic-linking' )
						) }
					</button>
				),
			},
			{
				id: 'edit',
				node: (
					<button
						type="button"
						className="button-link"
						onClick={ () => setEditing( rule ) }
					>
						{ label( __( 'Edit', 'magic-linking' ) ) }
					</button>
				),
			},
			{
				id: 'toggle',
				node: (
					<button
						type="button"
						className="button-link"
						onClick={ () => toggleActive( rule ) }
						disabled={ busy }
					>
						{ label(
							rule.active
								? __( 'Pause', 'magic-linking' )
								: __( 'Activate', 'magic-linking' )
						) }
					</button>
				),
			},
			{
				id: 'delete',
				node: (
					<button
						type="button"
						className="button-link"
						onClick={ () => setDeleting( rule ) }
					>
						{ label( __( 'Delete', 'magic-linking' ) ) }
					</button>
				),
			},
		];
	};

	const limits = data?.limits;

	return (
		<div className="magiclinking-rules">
			<p className="description">
				{ __(
					'A rule turns a phrase into a link to an entry. It is applied when a published entry is saved (never when a page is shown) and it only adds links: it never removes or changes one that exists, and everything it writes can be undone from the history. If someone has the entry open in the editor, the rule waits until it is closed.',
					'magic-linking'
				) }
			</p>
			{ limits && (
				<p className="description">
					{ sprintf(
						/* translators: 1: words per link, 2: links to the same target per entry, 3: entries with the same anchor. */
						__(
							'Limits that rules never exceed (change them in Settings): one internal link for every %1$d words of an entry, %2$d to the same target from one entry, and %3$d entries using the same anchor text for the same target.',
							'magic-linking'
						),
						limits.words_per_link,
						limits.links_per_target,
						limits.same_anchor_max
					) }{ ' ' }
					{ settingsUrl && (
						<a href={ settingsUrl }>
							{ __( 'Open Settings', 'magic-linking' ) }
						</a>
					) }
				</p>
			) }

			<div
				ref={ noticeRef }
				tabIndex={ -1 }
				role="status"
				aria-live="polite"
				className="magiclinking-fix-notice"
			>
				{ notice && (
					<p className={ notice.error ? 'magiclinking-error' : '' }>
						{ notice.text }
					</p>
				) }
			</div>

			{ data &&
				( data.stalled.length > 0 ||
					data.stalled_rules.length > 0 ) && (
					<div
						className="magiclinking-fix-notice"
						role="region"
						aria-labelledby="magiclinking-stalled-title"
					>
						<p
							id="magiclinking-stalled-title"
							className="magiclinking-warn"
						>
							{ __(
								'Some entries stayed open in the editor for a day, so the rules could not be applied to them. Save them again once they are closed.',
								'magic-linking'
							) }
						</p>
						<ul>
							{ data.stalled_rules.map( ( item ) => (
								<li key={ `rule-${ item.rule_id }` }>
									<span className="magiclinking-warn">
										{ sprintf(
											/* translators: %s: main phrase of a rule. */
											__(
												'The rule “%s” is not being applied: the person who created it can no longer manage the site.',
												'magic-linking'
											),
											item.phrase
										) }
									</span>{ ' ' }
									<button
										type="button"
										className="button-link"
										onClick={ async () => {
											await api.dismissStalledRuleNotice(
												item.rule_id
											);
											setVersion(
												( value ) => value + 1
											);
										} }
									>
										{ __( 'Dismiss', 'magic-linking' ) }
										<span className="screen-reader-text">
											{ ' ' + item.phrase }
										</span>
									</button>
								</li>
							) ) }
							{ data.stalled.map( ( item ) => (
								<li key={ item.post_id }>
									<a href={ item.edit_url }>{ item.title }</a>{ ' ' }
									<button
										type="button"
										className="button-link"
										onClick={ async () => {
											await api.dismissStalledRule(
												item.post_id
											);
											setVersion(
												( value ) => value + 1
											);
											speak(
												__(
													'Notice dismissed.',
													'magic-linking'
												),
												'polite'
											);
										} }
									>
										{ __( 'Dismiss', 'magic-linking' ) }
										<span className="screen-reader-text">
											{ ' ' + item.title }
										</span>
									</button>
								</li>
							) ) }
						</ul>
					</div>
				) }

			{ editing && (
				<RuleForm
					rule={ editing === 'new' ? null : editing }
					options={ options.data }
					onCancel={ () => {
						setEditing( null );
						window.setTimeout(
							() => addButton.current?.focus(),
							0
						);
					} }
					onSaved={ ( rule, created ) => {
						setEditing( null );
						setVersion( ( value ) => value + 1 );
						tell(
							created
								? sprintf(
										/* translators: %s: main phrase of the rule. */
										__(
											'Rule “%s” added. It will be applied the next time a published entry is saved. To use it on existing entries, choose “Apply to existing entries”.',
											'magic-linking'
										),
										rule.phrases[ 0 ] ?? ''
								  )
								: sprintf(
										/* translators: %s: main phrase of the rule. */
										__(
											'Rule “%s” saved.',
											'magic-linking'
										),
										rule.phrases[ 0 ] ?? ''
								  )
						);
					} }
				/>
			) }

			{ error && (
				<p className="magiclinking-error" role="alert">
					{ error }
				</p>
			) }

			<DataTable< RuleItem, never >
				caption={ __( 'Automatic rules', 'magic-linking' ) }
				columns={ columns }
				rows={ data?.items ?? [] }
				rowKey={ ( rule ) => rule.id }
				rowActions={ rowActions }
				busy={ loading }
				emptyMessage={ __(
					'There are no rules yet. Add one to link a phrase to an entry automatically.',
					'magic-linking'
				) }
				actions={
					<button
						type="button"
						ref={ addButton }
						className="button button-primary"
						onClick={ () => setEditing( 'new' ) }
					>
						{ __( 'Add rule', 'magic-linking' ) }
					</button>
				}
				noun="rules"
				page={ page }
				totalPages={ rulesTotalPages(
					data?.total ?? 0,
					RULES_PER_PAGE
				) }
				total={ data?.total ?? 0 }
				onPage={ setPage }
			/>

			{ applying && (
				<ApplyRuleModal
					rule={ applying }
					onClose={ () => setApplying( null ) }
				/>
			) }

			{ deleting && (
				<ConfirmModal
					title={ __( 'Delete rule', 'magic-linking' ) }
					confirmLabel={ __( 'Delete rule', 'magic-linking' ) }
					busy={ busy }
					onConfirm={ remove }
					onCancel={ () => setDeleting( null ) }
				>
					<p>
						{ sprintf(
							/* translators: %s: main phrase of the rule. */
							__(
								'Delete the rule “%s”? The links it already added stay in your entries; you can undo them from the history.',
								'magic-linking'
							),
							deleting.phrases[ 0 ] ?? ''
						) }
					</p>
				</ConfirmModal>
			) }
		</div>
	);
}

import { CheckboxControl, TextControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { CategoryGroup } from '../types';

/** A partir de cuántas categorías se ofrece un filtro por nombre. */
export const FILTER_FROM = 50;
/** Cuántas categorías sin marcar se pintan como máximo a la vez (las marcadas se pintan siempre). */
export const SHOWN_MAX = 200;

interface Props {
	group: CategoryGroup;
	selected: number[];
	/** Si ya no se pueden marcar más (las marcadas se pueden quitar). */
	full: boolean;
	onToggle: ( id: number, checked: boolean ) => void;
}

/**
 * Las categorías de una taxonomía que se pueden excluir. Con muchas, un filtro por nombre y un máximo de filas
 * pintadas; las ya marcadas se ven siempre, para poder quitarlas.
 * @param root0
 * @param root0.group
 * @param root0.selected
 * @param root0.full
 * @param root0.onToggle
 */
export function CategoryList( { group, selected, full, onToggle }: Props ) {
	const chosen = new Set( selected );
	const [ search, setSearch ] = useState( '' );
	const many = group.terms.length > FILTER_FROM;
	const needle = search.trim().toLowerCase();
	const matching = group.terms.filter(
		( term ) =>
			chosen.has( term.id ) ||
			needle === '' ||
			term.name.toLowerCase().includes( needle )
	);
	let free = 0;
	const shown = matching.filter( ( term ) => {
		if ( chosen.has( term.id ) ) {
			return true;
		}
		free += 1;
		return free <= SHOWN_MAX;
	} );
	const hidden = matching.length - shown.length;

	return (
		<fieldset className="magiclinking-categories">
			<legend>{ group.label }</legend>
			{ many && (
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					type="search"
					label={ sprintf(
						/* translators: %s: name of the taxonomy, e.g. Categories. */
						__( 'Filter %s by name', 'magic-linking' ),
						group.label
					) }
					value={ search }
					onChange={ setSearch }
				/>
			) }
			<div className="magiclinking-categories__list">
				{ shown.map( ( term ) => (
					<div
						key={ term.id }
						className="magiclinking-categories__item"
						style={ {
							paddingInlineStart: `${ term.depth * 1.25 }rem`,
						} }
					>
						<CheckboxControl
							__nextHasNoMarginBottom
							label={ term.name }
							checked={ chosen.has( term.id ) }
							disabled={ full && ! chosen.has( term.id ) }
							onChange={ ( checked ) =>
								onToggle( term.id, checked )
							}
						/>
					</div>
				) ) }
			</div>
			{ many && (
				<p className="description" role="status">
					{ hidden > 0
						? sprintf(
								/* translators: %d: number of categories not shown. */
								__(
									'%d more not shown. Type in the filter to find them.',
									'magic-linking'
								),
								hidden
						  )
						: '' }
				</p>
			) }
		</fieldset>
	);
}

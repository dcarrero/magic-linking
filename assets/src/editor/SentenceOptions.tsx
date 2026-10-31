import { __ } from '@wordpress/i18n';
import type { SuggestionAlternative } from '../types';

interface Props {
	options: SuggestionAlternative[];
	selected: number;
	/** Nombre común del grupo de opciones (único por tarjeta). */
	name: string;
	onSelect: ( option: number ) => void;
}

/**
 * Las frases entre las que elegir para una sugerencia (docs/07 §3.1): la principal y hasta dos alternativas, cada una
 * con su ancla resaltada. Un grupo de botones de opción nativos, para poder pintar el ancla dentro de la etiqueta.
 *
 * @param root0
 * @param root0.options
 * @param root0.selected
 * @param root0.name
 * @param root0.onSelect
 */
export function SentenceOptions( {
	options,
	selected,
	name,
	onSelect,
}: Props ) {
	return (
		<fieldset className="magiclinking-options">
			<legend className="magiclinking-options__legend">
				{ __( 'Link from this sentence', 'magic-linking' ) }
			</legend>
			{ options.map( ( option, index ) => (
				<div
					key={ `${ option.paragraph }:${ option.offset }:${ option.sentence }` }
					className="magiclinking-options__option"
				>
					<input
						type="radio"
						id={ `${ name }-${ index }` }
						name={ name }
						checked={ index === selected }
						onChange={ () => onSelect( index ) }
					/>
					<label htmlFor={ `${ name }-${ index }` }>
						{ option.before }
						<mark className="magiclinking-card__anchor">
							{ option.anchor }
						</mark>
						{ option.after }
						{ index === 0 && (
							<span className="magiclinking-options__main">
								{ ' (' }
								{ __( 'recommended', 'magic-linking' ) }
								{ ')' }
							</span>
						) }
					</label>
				</div>
			) ) }
		</fieldset>
	);
}

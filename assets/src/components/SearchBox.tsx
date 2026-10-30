import { __ } from '@wordpress/i18n';

interface Props {
	id: string;
	label: string;
	value: string;
	onChange: ( value: string ) => void;
	/** Dentro de otro `<form>`: no pinta un formulario propio ni botón, e Intro no envía nada. */
	nested?: boolean;
}

/**
 * Buscador nativo (`.search-box`). Filtra al escribir; el botón solo existe por costumbre del escritorio.
 * @param root0
 * @param root0.id
 * @param root0.label
 * @param root0.value
 * @param root0.onChange
 * @param root0.nested
 */
export function SearchBox( {
	id,
	label,
	value,
	onChange,
	nested = false,
}: Props ) {
	if ( nested ) {
		// Un `<form>` dentro de otro no es HTML válido: Intro enviaba el de fuera y navegaba a admin.php?.
		return (
			<div role="search">
				<p className="search-box">
					<label className="screen-reader-text" htmlFor={ id }>
						{ label }
					</label>
					<input
						type="search"
						id={ id }
						value={ value }
						onChange={ ( event ) => onChange( event.target.value ) }
						onKeyDown={ ( event ) => {
							if ( event.key === 'Enter' ) {
								event.preventDefault();
							}
						} }
					/>
				</p>
			</div>
		);
	}

	return (
		<form role="search" onSubmit={ ( event ) => event.preventDefault() }>
			<p className="search-box">
				<label className="screen-reader-text" htmlFor={ id }>
					{ label }
				</label>
				<input
					type="search"
					id={ id }
					value={ value }
					onChange={ ( event ) => onChange( event.target.value ) }
				/>{ ' ' }
				<input
					type="submit"
					className="button"
					value={ __( 'Search', 'magic-linking' ) }
				/>
			</p>
		</form>
	);
}

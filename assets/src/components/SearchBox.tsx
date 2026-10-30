import type { FormEvent } from 'react';
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
	// Un `<form>` dentro de otro no es HTML válido: Intro enviaba el de fuera y navegaba a admin.php?.
	const Wrapper = nested ? 'div' : 'form';
	const wrapperProps = nested
		? {}
		: {
				onSubmit: ( event: FormEvent ) => event.preventDefault(),
		  };

	return (
		<Wrapper role="search" { ...wrapperProps }>
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
						// Intro con un IME (japonés, chino...) confirma la composición: no se toca.
						if (
							nested &&
							event.key === 'Enter' &&
							! event.nativeEvent.isComposing
						) {
							event.preventDefault();
						}
					} }
				/>
				{ nested ? null : (
					<>
						{ ' ' }
						<input
							type="submit"
							className="button"
							value={ __( 'Search', 'magic-linking' ) }
						/>
					</>
				) }
			</p>
		</Wrapper>
	);
}

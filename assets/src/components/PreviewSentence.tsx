interface Props {
	before: string;
	anchor: string;
	after: string;
}

// El servidor recorta (trim) el texto de antes y de después, así que no dice si había espacio.
// Se pone salvo donde sobra: antes de signos de cierre y de puntuación, y después de los de apertura.
const NO_SPACE_BEFORE = /^[,.;:!?)\]}»”’…%]/;
const NO_SPACE_AFTER = /[([{«“‘¿¡]$/;

/**
 * La frase de la vista previa con la ancla resaltada: «… completa, ten …».
 * @param root0
 * @param root0.before
 * @param root0.anchor
 * @param root0.after
 */
export function PreviewSentence( { before, anchor, after }: Props ) {
	const spaceBefore = before !== '' && ! NO_SPACE_AFTER.test( before );
	const spaceAfter = after !== '' && ! NO_SPACE_BEFORE.test( after );

	return (
		<>
			{ before }
			{ spaceBefore ? ' ' : '' }
			<mark>{ anchor }</mark>
			{ spaceAfter ? ' ' : '' }
			{ after }
		</>
	);
}

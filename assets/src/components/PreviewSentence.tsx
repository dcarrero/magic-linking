interface Props {
	before: string;
	anchor: string;
	after: string;
}

/**
 * La frase de la vista previa con el ancla resaltada. El servidor ya manda el contexto con el espacio (o la falta
 * de espacio) junto al ancla, así que se pinta tal cual, como SuggestionCard: sin añadir separadores.
 * @param root0
 * @param root0.before
 * @param root0.anchor
 * @param root0.after
 */
export function PreviewSentence( { before, anchor, after }: Props ) {
	return (
		<>
			{ before }
			<mark>{ anchor }</mark>
			{ after }
		</>
	);
}

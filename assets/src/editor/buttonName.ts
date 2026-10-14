import { _n, sprintf } from '@wordpress/i18n';

/**
 * Nombre accesible del botón de la barra superior: el título y, si hay, cuántas sugerencias salientes tiene.
 * @param title Título del panel.
 * @param count Sugerencias salientes visibles (null = aún sin cargar).
 */
export function buttonName( title: string, count: number | null ): string {
	if ( count === null || count < 1 ) {
		return title;
	}
	return sprintf(
		/* translators: 1: panel title, 2: number of outbound suggestions. */
		_n(
			'%1$s, %2$d outbound suggestion',
			'%1$s, %2$d outbound suggestions',
			count,
			'magic-linking'
		),
		title,
		count
	);
}

/**
 * Páginas de la lista de reglas.
 */

/**
 * Páginas que ocupan `total` reglas (al menos una, para que la lista vacía siga teniendo página 1).
 * @param total   Reglas en total.
 * @param perPage Reglas por página.
 */
export function rulesTotalPages( total: number, perPage: number ): number {
	return Math.max( 1, Math.ceil( total / Math.max( 1, perPage ) ) );
}

/**
 * Página que hay que mostrar tras un cambio en el total (p. ej. borrar la última regla de una página): la actual si
 * sigue existiendo y, si no, la última.
 * @param page    Página actual.
 * @param total   Reglas en total ahora.
 * @param perPage Reglas por página.
 */
export function pageAfterChange(
	page: number,
	total: number,
	perPage: number
): number {
	return Math.min( page, rulesTotalPages( total, perPage ) );
}

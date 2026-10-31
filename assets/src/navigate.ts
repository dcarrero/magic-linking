/**
 * Va a una dirección en esta pestaña. Aparte para poder sustituirlo en las pruebas (jsdom no navega).
 *
 * @param url Dirección.
 */
export function navigate( url: string ): void {
	window.location.assign( url );
}

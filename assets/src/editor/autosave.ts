/**
 * Si un autoguardado escribe la entrada misma (y no una revisión aparte).
 *
 * Como hace WordPress: solo para un borrador o un auto-draft cuyo autor es el usuario actual. En una entrada
 * publicada, pendiente o de otro autor, el autoguardado va a una revisión y `post_content` no cambia.
 *
 * @param status Estado de la entrada.
 * @param author Autor de la entrada.
 * @param user   Usuario actual (0 = desconocido).
 */
export function autosaveWritesPost(
	status: string,
	author: number,
	user: number
): boolean {
	return (
		user > 0 &&
		author === user &&
		( status === 'draft' || status === 'auto-draft' )
	);
}

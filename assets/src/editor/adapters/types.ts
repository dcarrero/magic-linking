import type { Suggestion } from '../../types';
import type { LinkAttributes, LinkScope } from '../boot';

/** Ajustes del usuario que cambian dónde y cómo se enlaza. */
export interface AdapterOptions {
	linkIn: LinkScope;
	linkAttrs: LinkAttributes;
}

export type ApplyFailure =
	/** El texto ya no está donde estaba (el usuario lo cambió). */
	| 'not_found'
	/** La frase aparece más de una vez: no se sabe cuál. */
	| 'ambiguous'
	/** El ancla ya está dentro de un enlace. */
	| 'already_linked'
	/** Editor clásico en la pestaña «Texto»: el enlace se pone en la pestaña «Visual». */
	| 'text_mode';

export type ApplyResult =
	| {
			ok: true;
			/** Texto que quedó enlazado, tal como está en el editor. */
			anchor: string;
			/** Deshace el enlace si el contenido no ha cambiado desde que se puso; devuelve si lo hizo. */
			undo: () => boolean;
	  }
	| { ok: false; reason: ApplyFailure };

/**
 * Lo que el panel necesita de un editor: Gutenberg y el clásico lo cumplen cada uno a su manera.
 * Ningún método escribe en el servidor.
 */
export interface EditorAdapter {
	postId: number;
	/** Contenido actual, tal como está en el editor (sin guardar). */
	getContent: () => string;
	getTitle: () => string;
	/** Avisa cuando el usuario guarda la entrada. Devuelve la función que deja de avisar. */
	onSaved: ( callback: () => void ) => () => void;
	/**
	 * Avisa cuando empieza un guardado que puede escribir la entrada (`begin`, para fijar qué enlaces entran en él) y
	 * cuando termina con éxito (`finish`; `autosave` = fue un autoguardado). Solo cuenta el que escribe la entrada
	 * misma: el autoguardado de un borrador propio sí, el que va a una revisión aparte no. Devuelve la función que
	 * deja de avisar.
	 */
	onSaveCycle: ( handlers: {
		begin: () => void;
		finish: ( autosave: boolean ) => void;
	} ) => () => void;
	/** Pone el enlace de la sugerencia en el editor. */
	apply: ( suggestion: Suggestion ) => ApplyResult;
	/** Resalta (sin modificar el contenido) la frase en el lienzo; `null` quita el resalte. */
	highlight: ( suggestion: Suggestion | null ) => void;
}

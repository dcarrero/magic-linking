/**
 * Cuenta de los enlaces que el panel pone en el editor (F1-34, D-58).
 *
 * Los salientes se insertan en el cliente y se guardan con la entrada, así que el servidor no los ve pasar. El
 * panel apunta aquí cada uno (destino, ancla y sugerencia de la que sale) y, cuando el guardado termina bien,
 * se lo dice al servidor, que comprueba que siguen en el contenido guardado y los registra en el Historial.
 * Los que se deshacen en el editor antes de guardar se quitan de la cuenta.
 */
import type { EditorLink } from '../types';

export interface InsertionLog {
	/** Apunta un enlace recién puesto; devuelve su identificador de inserción. */
	add: ( link: Omit< EditorLink, 'insertion_id' > ) => string;
	/** Lo quita de la cuenta (se deshizo en el editor). */
	remove: ( insertionId: string ) => void;
	/** Los enlaces pendientes de avisar. */
	list: () => EditorLink[];
	/** Los quita de la cuenta (el servidor ya los ha tratado). */
	clear: ( insertionIds: string[] ) => void;
}

const PREFIX = 'magiclinking:editor-links:';

/**
 * Identificador de inserción: único por enlace puesto, solo letras, cifras, guion y guion bajo.
 */
export function newInsertionId(): string {
	const bytes = new Uint8Array( 12 );
	if ( typeof crypto !== 'undefined' && crypto.getRandomValues ) {
		crypto.getRandomValues( bytes );
	} else {
		for ( let i = 0; i < bytes.length; i++ ) {
			bytes[ i ] = Math.floor( Math.random() * 256 );
		}
	}
	return (
		'ml' +
		Array.from( bytes, ( byte ) => byte.toString( 16 ).padStart( 2, '0' ) )
			.join( '' )
			.slice( 0, 24 )
	);
}

/**
 * Cuenta de enlaces de una entrada. Se guarda en `sessionStorage` (por pestaña): el editor clásico recarga la
 * página al guardar y la lista tiene que sobrevivir a esa recarga. Sin almacenamiento (ventana privada,
 * bloqueado) funciona igual dentro de la página.
 *
 * @param postId  Entrada que se edita.
 * @param storage Dónde se guarda (por defecto, `sessionStorage`); `null` = solo memoria.
 */
export function createInsertionLog(
	postId: number,
	storage: Pick<
		Storage,
		'getItem' | 'setItem' | 'removeItem'
	> | null = safeStorage()
): InsertionLog {
	const key = `${ PREFIX }${ postId }`;
	let links: EditorLink[] = [];

	try {
		const raw = storage?.getItem( key );
		const parsed: unknown = raw ? JSON.parse( raw ) : [];
		if ( Array.isArray( parsed ) ) {
			links = parsed.filter( isLink );
		}
	} catch {
		links = [];
	}

	const save = () => {
		try {
			if ( links.length === 0 ) {
				storage?.removeItem( key );
			} else {
				storage?.setItem( key, JSON.stringify( links ) );
			}
		} catch {
			// Sin almacenamiento: la cuenta vive en la página.
		}
	};

	return {
		add: ( link ) => {
			const insertionId = newInsertionId();
			links = [ ...links, { ...link, insertion_id: insertionId } ];
			save();
			return insertionId;
		},
		remove: ( insertionId ) => {
			links = links.filter(
				( link ) => link.insertion_id !== insertionId
			);
			save();
		},
		list: () => [ ...links ],
		clear: ( insertionIds ) => {
			links = links.filter(
				( link ) => ! insertionIds.includes( link.insertion_id )
			);
			save();
		},
	};
}

function safeStorage(): Storage | null {
	try {
		return window.sessionStorage;
	} catch {
		return null;
	}
}

function isLink( value: unknown ): value is EditorLink {
	if ( typeof value !== 'object' || value === null ) {
		return false;
	}
	const link = value as Record< string, unknown >;
	return (
		typeof link.insertion_id === 'string' &&
		typeof link.suggestion === 'string' &&
		typeof link.target_id === 'number' &&
		typeof link.anchor === 'string'
	);
}

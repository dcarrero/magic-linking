/**
 * Aviso al servidor de los enlaces que el panel puso en el editor (F1-34, D-58).
 *
 * Reglas:
 * - Se envía lo que había **al empezar** el guardado (la foto de `begin()`): lo añadido mientras se guardaba no
 *   estaba en lo guardado y se enviará en el siguiente.
 * - Se parte en bloques del máximo del servidor.
 * - Un fallo de red, de permisos o de nonce conserva la lista; solo un 400 o un 404 son definitivos (y un 400 se
 *   reintenta uno a uno para descartar solo el rechazado).
 * - Tras un autoguardado el servidor puede no haber visto el enlace (si guardó en una revisión aparte): los
 *   `not_found` se conservan.
 * - Si se deshace un enlace que ya se había enviado (o se está enviando), el siguiente guardado manda una lista
 *   vacía para que el servidor marque como deshechas las filas cuyo enlace ya no está.
 */
import type { EditorHistoryResponse, EditorLink } from '../types';
import type { InsertionLog } from './insertions';

/** Enlaces como máximo por petición (el mismo tope que `EditorHistoryController::MAX_LINKS`). */
export const MAX_LINKS = 50;

type Send = ( links: EditorLink[] ) => Promise< EditorHistoryResponse >;

/**
 * Si un error de la API es definitivo para esos enlaces (la petición está mal o la entrada ya no existe).
 *
 * @param error Lo que lanzó la petición.
 */
export function isDefinitive( error: unknown ): boolean {
	const status = ( error as { data?: { status?: number } } )?.data?.status;
	return status === 400 || status === 404;
}

export class InsertionSync {
	private snapshot: string[] | null = null;

	private inFlight = new Set< string >();

	private reconcile = false;

	private queue: Promise< void > = Promise.resolve();

	/**
	 * @param log  Cuenta de enlaces.
	 * @param send Envía una lista al servidor.
	 */
	// eslint-disable-next-line no-useless-constructor -- Propiedades declaradas en los parámetros.
	constructor(
		private log: InsertionLog,
		private send: Send
	) {}

	/**
	 * Apunta un enlace recién puesto.
	 *
	 * @param link Enlace sin identificador.
	 * @return El identificador de la inserción.
	 */
	add( link: Omit< EditorLink, 'insertion_id' > ): string {
		return this.log.add( link );
	}

	/** Empieza un guardado: fija qué enlaces entran en él. */
	begin(): void {
		this.snapshot = this.log.list().map( ( link ) => link.insertion_id );
	}

	/**
	 * Deja de contar un enlace que se deshizo en el editor.
	 *
	 * @param insertionId Identificador de la inserción.
	 */
	forget( insertionId: string ): void {
		const pending = this.log
			.list()
			.some( ( link ) => link.insertion_id === insertionId );
		this.log.remove( insertionId );
		if ( ! pending || this.inFlight.has( insertionId ) ) {
			this.reconcile = true;
		}
	}

	/**
	 * Termina un guardado con éxito y avisa al servidor.
	 *
	 * @param autosave Si fue un autoguardado.
	 */
	finish( autosave: boolean ): Promise< void > {
		const ids = this.snapshot;
		this.snapshot = null;
		return this.enqueue( ids, autosave );
	}

	/** Envía todo lo pendiente (al cargar la página). */
	flushAll(): Promise< void > {
		return this.enqueue( null, false );
	}

	private enqueue(
		ids: string[] | null,
		autosave: boolean
	): Promise< void > {
		this.queue = this.queue.then( () => this.run( ids, autosave ) );
		return this.queue;
	}

	private async run(
		ids: string[] | null,
		autosave: boolean
	): Promise< void > {
		const links = this.log
			.list()
			.filter(
				( link ) => ids === null || ids.includes( link.insertion_id )
			);
		if ( links.length === 0 && ! this.reconcile ) {
			return;
		}

		const wasReconcile = this.reconcile;
		this.reconcile = false;
		this.inFlight = new Set( links.map( ( link ) => link.insertion_id ) );
		let sent = false;

		try {
			const chunks: EditorLink[][] = [];
			for ( let i = 0; i < links.length; i += MAX_LINKS ) {
				chunks.push( links.slice( i, i + MAX_LINKS ) );
			}
			if ( chunks.length === 0 ) {
				chunks.push( [] );
			}

			for ( const chunk of chunks ) {
				const ok = await this.sendChunk( chunk, autosave );
				if ( ! ok ) {
					break;
				}
				sent = true;
			}
		} finally {
			this.inFlight = new Set();
			if ( wasReconcile && ! sent ) {
				this.reconcile = true;
			}
		}
	}

	/**
	 * @param chunk    Hasta MAX_LINKS enlaces.
	 * @param autosave Si viene de un autoguardado.
	 * @return Si el servidor contestó (aunque descarte algunos).
	 */
	private async sendChunk(
		chunk: EditorLink[],
		autosave: boolean
	): Promise< boolean > {
		try {
			const response = await this.send( chunk );
			this.log.clear(
				response.results
					.filter(
						( result ) =>
							! ( autosave && result.status === 'not_found' )
					)
					.map( ( result ) => result.insertion_id )
			);
			return true;
		} catch ( error ) {
			if ( ! isDefinitive( error ) ) {
				return false;
			}
			if ( chunk.length > 1 ) {
				// Uno mal formado hunde la petición: de uno en uno se descarta solo el rechazado.
				let any = false;
				for ( const link of chunk ) {
					any = ( await this.sendChunk( [ link ], autosave ) ) || any;
				}
				return any;
			}
			this.log.clear( chunk.map( ( link ) => link.insertion_id ) );
			return true;
		}
	}
}

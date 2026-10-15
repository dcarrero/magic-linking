/**
 * Llamadas a la API REST del plugin.
 */
import apiFetch from '@wordpress/api-fetch';
import type {
	AiCapabilities,
	AiStatus,
	BrokenResponse,
	BrokenTargetsResponse,
	FixResponse,
	DismissalResponse,
	EditorHistoryResponse,
	EntryRunResponse,
	HistoryEntriesResponse,
	HistoryEntry,
	HistoryEntryChangesResponse,
	HistoryStatusFilter,
	EditorLink,
	HistoryChangesResponse,
	HistoryGroup,
	HistoryJob,
	HistoryResponse,
	InboundResponse,
	InsertLink,
	InsertResponse,
	OutboundResponse,
	RerankItem,
	RerankResponse,
	RunResponse,
	Job,
	ReportFilter,
	ReportResponse,
	SettingsResponse,
	SettingsValues,
	SortKey,
	StatusResponse,
	WizardResponse,
} from './types';

const NS = window.magiclinking?.namespace ?? 'magic-linking/v1';

function path( route: string, query: Record< string, string | number > = {} ) {
	const params = new URLSearchParams();
	Object.entries( query ).forEach( ( [ key, value ] ) => {
		if ( value !== '' && value !== 0 ) {
			params.set( key, String( value ) );
		}
	} );
	const qs = params.toString();
	return `/${ NS }${ route }${ qs ? `?${ qs }` : '' }`;
}

export interface ReportQuery {
	filter: ReportFilter;
	search: string;
	post_type: string;
	lang: string;
	orderby: SortKey;
	order: 'asc' | 'desc';
	page: number;
	per_page: number;
}

export interface BrokenQuery {
	post_id: number;
	search: string;
	page: number;
	per_page: number;
}

export const api = {
	report: ( q: ReportQuery, signal?: AbortSignal ) =>
		apiFetch< ReportResponse >( {
			path: path( '/report', { ...q } ),
			signal,
		} ),
	broken: ( q: BrokenQuery, signal?: AbortSignal ) =>
		apiFetch< BrokenResponse >( {
			path: path( '/broken', { ...q } ),
			signal,
		} ),
	/**
	 * Entradas publicadas a las que se puede apuntar para arreglar un enlace roto, por título.
	 *
	 * @param linkId Fila de la lista de rotos.
	 * @param search Texto del título.
	 * @param signal Para cancelar.
	 */
	brokenTargets: ( linkId: number, search: string, signal?: AbortSignal ) =>
		apiFetch< BrokenTargetsResponse >( {
			path: path( `/broken/${ linkId }/targets`, { search } ),
			signal,
		} ),
	/**
	 * Arregla un enlace roto: cambia su destino o lo quita dejando el texto.
	 *
	 * @param linkId Fila de la lista de rotos.
	 * @param fix    Qué hacer.
	 */
	fixBroken: (
		linkId: number,
		fix: { mode: 'retarget'; targetId: number } | { mode: 'unlink' }
	) =>
		apiFetch< FixResponse >( {
			path: path( `/broken/${ linkId }/fix` ),
			method: 'POST',
			data:
				fix.mode === 'retarget'
					? { mode: 'retarget', target_id: fix.targetId }
					: { mode: 'unlink' },
		} ),
	status: ( signal?: AbortSignal ) =>
		apiFetch< StatusResponse >( { path: path( '/status' ), signal } ),
	/**
	 * Lanza el análisis. Sin `force` solo procesa lo nuevo o modificado; con `force`, todo desde cero.
	 *
	 * @param force Volver a analizar todas las entradas.
	 */
	startIndex: ( force = false ) =>
		apiFetch< { job: Job } >( {
			path: path( '/index' ),
			method: 'POST',
			data: { force },
		} ),
	changeJob: ( id: number, action: 'pause' | 'resume' | 'cancel' ) =>
		apiFetch< { job: Job } >( {
			path: path( `/jobs/${ id }/${ action }` ),
			method: 'POST',
		} ),
	history: ( before: string, perPage: number, signal?: AbortSignal ) =>
		apiFetch< HistoryResponse >( {
			path: path( '/history', { before, per_page: perPage } ),
			signal,
		} ),
	/**
	 * Historial agrupado por entrada, paginado (F1-36).
	 *
	 * @param query         Página y filtros.
	 * @param query.page    Página (desde 1).
	 * @param query.perPage Entradas por página.
	 * @param query.status  Estado de los cambios.
	 * @param query.origin  Origen (editor, plugin o vacío).
	 * @param query.search  Texto del título de la entrada.
	 * @param signal        Para cancelar.
	 */
	historyEntries: (
		query: {
			page: number;
			perPage: number;
			status: HistoryStatusFilter;
			origin: string;
			search: string;
		},
		signal?: AbortSignal
	) =>
		apiFetch< HistoryEntriesResponse >( {
			path: path( '/history', {
				group: 'post',
				page: query.page,
				per_page: query.perPage,
				status: query.status === 'all' ? '' : query.status,
				origin: query.origin,
				search: query.search,
			} ),
			signal,
		} ),
	historyEntry: ( postId: number, origin = '' ) =>
		apiFetch< { entry: HistoryEntry } >( {
			path: path( `/history/posts/${ postId }`, { origin } ),
		} ),
	/**
	 * Más cambios de una entrada, con cursor: solo los anteriores al último mostrado.
	 *
	 * @param postId  Entrada.
	 * @param before  Id del último cambio mostrado.
	 * @param perPage Cambios por petición.
	 * @param origin  Filtro de origen (vacío = todos).
	 */
	historyEntryChanges: (
		postId: number,
		before: number,
		perPage: number,
		origin = ''
	) =>
		apiFetch< HistoryEntryChangesResponse >( {
			path: path( `/history/posts/${ postId }/changes`, {
				before,
				per_page: perPage,
				origin,
			} ),
		} ),
	/**
	 * Deshace o rehace cambios de una entrada en una sola petición: los indicados o, sin lista, todos los que
	 * cumplan el filtro de origen (lo que la pantalla ha contado y confirmado).
	 *
	 * @param mode              undo o redo.
	 * @param postId            Entrada.
	 * @param options           Qué cambios.
	 * @param options.changeIds Cambios concretos de esa entrada.
	 * @param options.origin    Filtro de origen.
	 */
	runHistoryEntry: (
		mode: 'undo' | 'redo',
		postId: number,
		options: { changeIds?: number[]; origin?: string } = {}
	) =>
		apiFetch< EntryRunResponse >( {
			path: path( `/${ mode }` ),
			method: 'POST',
			data: {
				post_id: postId,
				...( options.changeIds
					? { change_ids: options.changeIds }
					: {} ),
				...( options.origin ? { origin: options.origin } : {} ),
			},
		} ),
	historyGroup: ( batchId: string ) =>
		apiFetch< { group: HistoryGroup } >( {
			path: path( `/history/${ batchId }` ),
		} ),
	historyChanges: ( batchId: string, page: number, perPage: number ) =>
		apiFetch< HistoryChangesResponse >( {
			path: path( `/history/${ batchId }/changes`, {
				page,
				per_page: perPage,
			} ),
		} ),
	historyJob: ( id: number ) =>
		apiFetch< { job: HistoryJob } >( {
			path: path( `/history/jobs/${ id }` ),
		} ),
	controlHistoryJob: ( id: number, action: 'resume' | 'cancel' ) =>
		apiFetch< {
			job: HistoryJob;
			group: HistoryGroup | null;
			entry: HistoryEntry | null;
		} >( {
			path: path( `/history/jobs/${ id }/${ action }` ),
			method: 'POST',
		} ),
	/**
	 * Deshace o rehace un cambio (`changeId`) o un grupo entero (`batchId`).
	 *
	 * @param mode   undo o redo.
	 * @param target El cambio o el grupo.
	 */
	runHistory: (
		mode: 'undo' | 'redo',
		target: { changeId: number } | { batchId: string }
	) =>
		apiFetch< RunResponse >( {
			path: path( `/${ mode }` ),
			method: 'POST',
			data:
				'changeId' in target
					? { change_id: target.changeId }
					: { batch_id: target.batchId },
		} ),
	/**
	 * Avisa de los enlaces que el panel puso en el editor y que ya están guardados: el servidor comprueba que
	 * siguen en el contenido y los registra en el Historial, sin escribir nada en la entrada (F1-34).
	 *
	 * @param postId Entrada guardada.
	 * @param links  Enlaces que el panel puso.
	 */
	recordEditorLinks: ( postId: number, links: EditorLink[] ) =>
		apiFetch< EditorHistoryResponse >( {
			path: path( '/history/editor' ),
			method: 'POST',
			data: { post_id: postId, links },
		} ),
	/**
	 * Sugerencias salientes de una entrada tal como está guardada.
	 *
	 * @param postId Entrada.
	 * @param signal Para cancelar.
	 */
	outbound: ( postId: number, signal?: AbortSignal ) =>
		apiFetch< OutboundResponse >( {
			path: path( '/suggestions/outbound', { post_id: postId } ),
			signal,
		} ),
	/**
	 * Salientes de lo que el usuario tiene ahora en el editor, sin guardar: no se escribe nada.
	 *
	 * @param postId  Entrada abierta.
	 * @param content Contenido actual (bloques serializados o HTML).
	 * @param title   Título actual.
	 * @param signal  Para cancelar.
	 */
	outboundDraft: (
		postId: number,
		content: string,
		title?: string,
		signal?: AbortSignal
	) =>
		apiFetch< OutboundResponse >( {
			path: path( '/suggestions/outbound' ),
			method: 'POST',
			data: {
				post_id: postId,
				content,
				...( title !== undefined ? { title } : {} ),
			},
			signal,
		} ),
	/**
	 * Sugerencias entrantes hacia una entrada (solo desde orígenes que el usuario puede editar), paginadas.
	 *
	 * @param postId  Destino.
	 * @param page    Página.
	 * @param perPage Por página (1 a 50).
	 * @param signal  Para cancelar.
	 */
	inbound: (
		postId: number,
		page: number,
		perPage: number,
		signal?: AbortSignal
	) =>
		apiFetch< InboundResponse >( {
			path: path( '/suggestions/inbound', {
				post_id: postId,
				page,
				per_page: perPage,
			} ),
			signal,
		} ),
	/**
	 * Inserta uno o varios enlaces (un lote, hasta 25) en el servidor. Cada elemento es el `insert` de una sugerencia.
	 *
	 * @param links Enlaces a insertar.
	 */
	insertLinks: ( links: InsertLink[] ) =>
		apiFetch< InsertResponse >( {
			path: path( '/links' ),
			method: 'POST',
			data: { links },
		} ),
	/**
	 * Descarta para siempre el par (origen, destino): no se vuelve a proponer en ningún panel.
	 *
	 * @param sourceId Entrada donde iría el enlace (hay que poder editarla).
	 * @param targetId Entrada a la que enlazaría.
	 */
	dismissSuggestion: ( sourceId: number, targetId: number ) =>
		apiFetch< DismissalResponse >( {
			path: path( '/suggestions/dismissals' ),
			method: 'POST',
			data: { source_id: sourceId, target_id: targetId },
		} ),
	/**
	 * Deshace el descarte de un par.
	 *
	 * @param sourceId Origen.
	 * @param targetId Destino.
	 */
	restoreSuggestion: ( sourceId: number, targetId: number ) =>
		apiFetch< DismissalResponse >( {
			path: path( `/suggestions/dismissals/${ sourceId }/${ targetId }` ),
			method: 'DELETE',
		} ),
	/**
	 * Lo que necesita el botón «Mejorar con IA»: si hay conector, el modelo previsto y una estimación. No llama al modelo.
	 *
	 * @param count  Sugerencias que se enviarían (para la estimación).
	 * @param signal Para cancelar.
	 */
	aiCapabilities: ( count: number, signal?: AbortSignal ) =>
		apiFetch< AiCapabilities >( {
			path: path( '/ai/capabilities', { count } ),
			signal,
		} ),
	/**
	 * Reordena con IA las salientes de la entrada abierta. Una sola llamada al modelo por petición; un fallo de la IA
	 * llega como `ok: false` con un texto, no como error HTTP.
	 *
	 * @param postId  Entrada que se edita.
	 * @param items   Sugerencias, en orden léxico (máximo 20).
	 * @param content Contenido que el editor tiene ahora (puede no estar guardado): el servidor comprueba que las frases son de él.
	 * @param signal  Para cancelar.
	 */
	rerank: (
		postId: number,
		items: RerankItem[],
		content: string,
		signal?: AbortSignal
	) =>
		apiFetch< RerankResponse >( {
			path: path( '/suggestions/rerank' ),
			method: 'POST',
			data: { post_id: postId, items, content },
			signal,
		} ),
	/**
	 * Estado del conector, tope diario y gasto de 30 días (Ajustes).
	 *
	 * @param refresh Vuelve a comprobar el conector en vez de usar lo guardado.
	 * @param signal  Para cancelar.
	 */
	aiStatus: ( refresh = false, signal?: AbortSignal ) =>
		apiFetch< AiStatus >( {
			path: path( '/ai/status', { refresh: refresh ? 1 : 0 } ),
			signal,
		} ),
	/**
	 * Cierra para siempre (para este usuario) el aviso «Sin conector de IA», o lo vuelve a abrir.
	 *
	 * @param dismissed Cerrarlo.
	 */
	setAiNoticeDismissed: ( dismissed: boolean ) =>
		apiFetch< { dismissed: boolean } >( {
			path: path( '/ai/notice' ),
			method: 'POST',
			data: { dismissed },
		} ),
	settings: () =>
		apiFetch< SettingsResponse >( { path: path( '/settings' ) } ),
	wizard: () => apiFetch< WizardResponse >( { path: path( '/wizard' ) } ),
	/**
	 * Cierra el asistente de arranque para este usuario, o lo vuelve a abrir.
	 *
	 * @param dismissed Cerrarlo para siempre.
	 */
	setWizardDismissed: ( dismissed: boolean ) =>
		apiFetch< WizardResponse >( {
			path: path( '/wizard' ),
			method: 'POST',
			data: { dismissed },
		} ),
	saveSettings: ( values: SettingsValues ) =>
		apiFetch< SettingsResponse >( {
			path: path( '/settings' ),
			method: 'POST',
			data: values,
		} ),
};

/**
 * Código HTTP de un error de apiFetch, o 0 si no lo trae.
 *
 * @param error Lo que lanzó apiFetch.
 */
export function errorStatus( error: unknown ): number {
	if ( typeof error === 'object' && error !== null && 'data' in error ) {
		const data = ( error as { data?: { status?: unknown } } ).data;
		return typeof data?.status === 'number' ? data.status : 0;
	}
	return 0;
}

/**
 * Mensaje de un error de apiFetch, sin códigos sueltos.
 *
 * @param error    Lo que lanzó apiFetch.
 * @param fallback Texto si el error no trae mensaje.
 */
export function errorMessage( error: unknown, fallback: string ): string {
	if (
		typeof error === 'object' &&
		error !== null &&
		'message' in error &&
		typeof ( error as { message: unknown } ).message === 'string'
	) {
		return ( error as { message: string } ).message;
	}
	return fallback;
}

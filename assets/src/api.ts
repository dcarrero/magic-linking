/**
 * Llamadas a la API REST del plugin.
 */
import apiFetch from '@wordpress/api-fetch';
import type {
	BrokenResponse,
	HistoryChangesResponse,
	HistoryGroup,
	HistoryJob,
	HistoryResponse,
	RunResponse,
	Job,
	ReportFilter,
	ReportResponse,
	SettingsResponse,
	SettingsValues,
	SortKey,
	StatusResponse,
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
	settings: () =>
		apiFetch< SettingsResponse >( { path: path( '/settings' ) } ),
	saveSettings: ( values: SettingsValues ) =>
		apiFetch< SettingsResponse >( {
			path: path( '/settings' ),
			method: 'POST',
			data: values,
		} ),
};

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

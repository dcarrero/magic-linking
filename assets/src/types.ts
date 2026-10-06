/**
 * Tipos de la API REST de Magic Linking.
 */

export type LinkStatus = 'orphan' | 'low' | 'over' | 'ok';

export type ReportFilter = 'all' | 'orphans' | 'low' | 'over' | 'broken';

export type SortKey =
	| 'title'
	| 'type'
	| 'lang'
	| 'inbound'
	| 'outbound'
	| 'external'
	| 'broken'
	| 'words'
	| 'indexed_at';

export interface ReportItem {
	id: number;
	title: string;
	type: string;
	type_label: string;
	lang: string;
	inbound: number;
	outbound: number;
	external: number;
	broken: number;
	words: number;
	status: LinkStatus;
	indexed_at: string;
	url: string;
	edit_url: string;
}

export interface CountLabel {
	name: string;
	label: string;
	count: number;
}

export interface Summary {
	analyzed: number;
	orphans: number;
	low: number;
	over: number;
	broken_posts: number;
	internal_links: number;
	broken_links: number;
	types: CountLabel[];
	langs: string[];
	/** Hay WPML o Polylang activos, o el índice tiene más de un idioma: solo entonces se muestra el idioma. */
	multilingual: boolean;
}

export interface Paged {
	total: number;
	total_pages: number;
	page: number;
}

export interface ReportResponse extends Paged {
	items: ReportItem[];
	summary: Summary;
}

export interface BrokenItem {
	id: number;
	source_id: number;
	source_title: string;
	target_id: number | null;
	url: string;
	anchor: string;
	reason_code: number;
	reason: string;
	edit_url: string;
}

export interface BrokenResponse extends Paged {
	items: BrokenItem[];
}

export type JobStatus =
	| 'queued'
	| 'running'
	| 'paused'
	| 'done'
	| 'failed'
	| 'cancelled';

export interface Job {
	id: number;
	type: string;
	status: JobStatus;
	total: number;
	done: number;
	percent: number;
	error: string;
	/** Análisis forzado (todo desde cero) o solo de cambios. */
	force: boolean;
	/** Entradas nuevas o modificadas analizadas. */
	changed: number;
	/** Entradas saltadas por no haber cambiado. */
	unchanged: number;
	created_at: string;
	updated_at: string;
}

export interface StatusResponse {
	indexed: number;
	eligible: number;
	pending: number;
	job: Job | null;
	stalled: boolean;
	/** Segundos estimados hasta terminar el proceso en marcha, o null si aún no se puede saber. */
	eta: number | null;
	last_job: Job | null;
	last_done: Job | null;
}

export interface SettingsValues {
	post_types: string[];
	low_inbound_threshold: number;
	words_per_link: number;
	delete_data_on_uninstall: boolean;
}

export interface SettingsResponse {
	settings: SettingsValues;
	post_types: { name: string; label: string }[];
	job?: Job | null;
}

export interface Boot {
	namespace: string;
	exportUrl: string;
	canManage: boolean;
	/** Elementos por página (Opciones de pantalla). */
	perPage?: number;
	settingsUrl: string;
	initialTab?: 'report' | 'broken' | 'settings';
	tabUrls?: Record< 'report' | 'broken' | 'settings', string >;
}

declare global {
	interface Window {
		magiclinking?: Boot;
	}
}

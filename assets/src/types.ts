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
	/** Días que se conserva el historial: 30, 90, 365 o 0 (sin caducidad). */
	history_retention_days: number;
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
	initialTab?: 'report' | 'broken' | 'history' | 'settings';
	tabUrls?: Record< 'report' | 'broken' | 'history' | 'settings', string >;
}

/** Un grupo (lote) del historial: una acción del usuario. */
export interface HistoryGroup {
	batch_id: string;
	created_at: string;
	user_id: number;
	user_name: string;
	/** Enlaces del grupo (rehacer no cuenta dos veces el mismo). */
	links: number;
	/** Enlaces puestos ahora. */
	active: number;
	/** Enlaces deshechos. */
	undone: number;
	/** Entradas distintas. */
	posts: number;
	/** Títulos de las primeras entradas. */
	titles: string[];
	/** Proceso en segundo plano de este grupo, si lo hay. */
	job?: HistoryJob | null;
}

export interface HistoryResponse {
	items: HistoryGroup[];
	/** Cursor de la página siguiente (el último lote mostrado), o null. */
	next: string | null;
	retention_days: number;
}

export interface HistoryChange {
	id: number;
	post_id: number;
	/** Null si la entrada ya no existe. */
	post_title: string | null;
	edit_url: string | null;
	anchor: string;
	url: string;
	state: 'active' | 'undone';
	created_at: string;
	undone_at: string | null;
}

export interface HistoryChangesResponse extends Paged {
	items: HistoryChange[];
}

export type ChangeStatus =
	| 'restored'
	| 'link_removed'
	| 'already_gone'
	| 'manual'
	| 'failed'
	| 'already_undone'
	| 'redone';

export interface ChangeResult {
	change_id: number;
	post_id: number;
	post_title: string | null;
	status: ChangeStatus;
	message: string;
	edit_url: string | null;
}

export interface HistoryIssue {
	change_id: number;
	post_id: number;
	post_title: string | null;
	status: ChangeStatus;
	message: string;
	edit_url: string | null;
}

export interface HistoryJob {
	id: number;
	status: JobStatus;
	mode: 'undo' | 'redo';
	batch_id: string;
	total: number;
	done: number;
	percent: number;
	counts: Partial< Record< ChangeStatus, number > >;
	issues: HistoryIssue[];
	error: string;
	/** El proceso lleva más de 10 minutos sin avanzar. */
	stalled: boolean;
	/** Quien mira puede reanudarlo o cancelarlo (lo lanzó o administra el plugin). */
	can_control: boolean;
	created_at: string;
	updated_at: string;
}

export interface RunResponse {
	results: ChangeResult[];
	group: HistoryGroup | null;
	job: HistoryJob | null;
}

/** Entrada tal como la enseña la tarjeta de una sugerencia. */
export interface SuggestionPost {
	id: number;
	title?: string;
	url?: string;
	type?: string;
	type_label?: string;
	lang?: string;
	/** Solo si el usuario puede editarla. */
	edit_url?: string | null;
}

/** Cuerpo de `POST /links` para un enlace: es el `insert` de una sugerencia, tal cual. */
export interface InsertLink {
	/** Entrada donde se escribe el enlace (el origen). */
	post_id: number;
	/** Entrada a la que enlaza; la dirección la pone el servidor. */
	target_id: number;
	sentence: string;
	/** Byte de la frase donde empieza el ancla. */
	offset: number;
	anchor: string;
	block_path: string | null;
}

export interface SuggestionReason {
	code: string;
	/** Texto en el idioma del usuario. */
	text: string;
}

/** Frase alternativa del mismo destino (`04 §5`); vacío mientras el motor no las devuelva. */
export interface SuggestionAlternative {
	anchor: string;
	sentence: string;
	offset: number;
	before: string;
	after: string;
	anchor_start: number;
	anchor_end: number;
	paragraph: number;
	score: number;
	/** Solo si el usuario puede editar el origen (`can_insert` de la sugerencia). */
	insert?: InsertLink;
}

export interface Suggestion extends SuggestionAlternative {
	source: SuggestionPost;
	target: SuggestionPost;
	/** El usuario puede editar el origen: si no, la tarjeta se enseña sin *Enlazar*, sin `insert` ni `edit_url`. */
	can_insert: boolean;
	reasons: SuggestionReason[];
	alternatives: SuggestionAlternative[];
}

/** Por qué no hay sugerencias, o `ok`. */
export type SuggestionState =
	| 'ok'
	| 'index_not_ready'
	| 'not_analyzed'
	| 'not_published';

export interface OutboundResponse {
	/** El índice léxico está construido; si no, `items` va vacío. */
	ready: boolean;
	state: SuggestionState;
	/** Se analizó el contenido del editor y no el guardado. */
	draft?: boolean;
	total: number;
	items: Suggestion[];
	post_id?: number;
}

export interface InboundResponse extends Paged {
	ready: boolean;
	state: SuggestionState;
	per_page: number;
	items: Suggestion[];
	post_id?: number;
}

export type InsertStatus = 'inserted' | 'failed';

export interface InsertResult {
	index: number;
	post_id: number;
	target_id: number;
	post_title: string | null;
	status: InsertStatus;
	/** Solo si se insertó. */
	change_id?: number;
	path?: string;
	/** Solo si falló: `text_changed`, `locked`, `already_linked`, `language_mismatch`, `bad_target`… */
	reason?: string;
	message?: string;
}

export interface InsertResponse {
	/** Para deshacer con `/undo`; null si no se insertó ninguno. */
	batch_id: string | null;
	inserted: number;
	failed: number;
	results: InsertResult[];
	group: HistoryGroup | null;
}

declare global {
	interface Window {
		magiclinking?: Boot;
	}
}

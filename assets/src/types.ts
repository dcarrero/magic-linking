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

/** `GET /report/rows`: las filas pedidas con las cifras de ahora y el resumen, calculado una sola vez. */
export interface ReportRowsResponse {
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
	/** El usuario puede editar la entrada donde está el enlace. */
	can_fix: boolean;
}

/** Entrada a la que se puede apuntar para arreglar un enlace roto. */
export interface BrokenTarget {
	id: number;
	title: string;
	url: string;
	type_label: string;
}

export interface BrokenTargetsResponse {
	items: BrokenTarget[];
}

export interface FixResponse {
	status: 'fixed';
	mode: 'retarget' | 'unlink';
	link_id: number;
	change_id: number;
	batch_id: string;
	post_id: number;
	group: HistoryGroup | null;
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

/** Modo de un filtro de las sugerencias. */
export type FilterMode = 'ignore' | 'consider' | 'require';

export type FilterName = 'type' | 'category' | 'tag';

export type SuggestionFilters = Record< FilterName, FilterMode >;

/** Estado de la casilla «Contenido pilar» de una entrada (`GET|POST /pillar/{id}`). */
export interface PillarState {
	post_id: number;
	/** Cuenta como pilar: la casilla propia o la marca de un plugin de SEO. */
	pillar: boolean;
	/** La casilla propia de Magic Linking. */
	own: boolean;
	/** Quién más la marca: Yoast SEO o Rank Math. Se cambia en su plugin. */
	external: 'yoast' | 'rank_math' | null;
}

export interface SettingsValues {
	post_types: string[];
	low_inbound_threshold: number;
	words_per_link: number;
	/** Días que se conserva el historial: 30, 90, 365 o 0 (sin caducidad). */
	history_retention_days: number;
	delete_data_on_uninstall: boolean;
	link_in_headings: boolean;
	link_in_quotes: boolean;
	link_in_tables: boolean;
	open_in_new_tab: boolean;
	/** Valores de `rel` separados por espacios. */
	extra_rel: string;
	outbound_shown: number;
	inbound_per_page: number;
	same_anchor_max: number;
	/** Frases alternativas por sugerencia (0 a 2; docs/04 §5). */
	alternatives: number;
	/** Enlaces al mismo destino desde una entrada: tope duro de las reglas (docs/04 §6.1). */
	links_per_target_max: number;
	ignored_urls: string[];
	ignored_words: string[];
	/** Filtros de las sugerencias por tipo, categoría y etiqueta (docs/04 §4.3). */
	filters: SuggestionFilters;
	/** Tope diario de tokens de IA; 0 = sin IA (docs/05 §5.3). */
	ai_daily_token_limit: number;
	/** Modelo de texto preferido; vacío = automático (docs/05 §4). */
	ai_model: string;
}

export interface WizardResponse {
	dismissed: boolean;
	/** Idioma del sitio, ya con su nombre. */
	language: string;
	multilingual: boolean;
	/** Conector de IA de WordPress: conectado, sin conectar o no disponible (WordPress anterior a 7.0). */
	ai: 'connected' | 'none' | 'unavailable';
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
	/** El asistente de arranque no se ha cerrado para este usuario. */
	wizard?: boolean;
	/** Elementos por página (Opciones de pantalla). */
	perPage?: number;
	settingsUrl: string;
	initialTab?: 'report' | 'broken' | 'history' | 'rules' | 'settings';
	tabUrls?: Record<
		'report' | 'broken' | 'history' | 'rules' | 'settings',
		string
	>;
}

/** De dónde viene un grupo: lo escribió Magic Linking, o el usuario lo puso en el editor y solo se registró. */
export type HistoryOrigin = 'plugin' | 'editor' | 'rule';

/** Lo que se sabe de la IA en un cambio que ella recomendó (F1-38). */
export interface HistoryAi {
	/** Se usó el ancla que propuso la IA. */
	anchor: boolean;
	/** Modelo que lo recomendó; vacío si no se registró. */
	model: string;
}

/** Un grupo (lote) del historial: una acción del usuario. */
/** Qué hizo un cambio: poner un enlace, cambiar su destino o quitarlo. */
export type ChangeKind = 'insert' | 'retarget' | 'unlink';

export interface HistoryGroup {
	batch_id: string;
	origin: HistoryOrigin;
	/** Acción del primer cambio del grupo. */
	kind?: ChangeKind;
	created_at: string;
	user_id: number;
	user_name: string;
	/** Enlaces del grupo (rehacer no cuenta dos veces el mismo). */
	links: number;
	/** De ellos, los que recomendó la IA. */
	ai_links?: number;
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
	origin: HistoryOrigin;
	/** Null si la IA no lo recomendó. */
	ai?: HistoryAi | null;
	post_id: number;
	/** Null si la entrada ya no existe. */
	post_title: string | null;
	edit_url: string | null;
	anchor: string;
	url: string;
	kind?: ChangeKind;
	/** Dirección anterior, si se cambió el destino o se quitó el enlace. */
	previous_url?: string | null;
	state: 'active' | 'undone';
	created_at: string;
	undone_at: string | null;
}

/** Un cambio dentro de la vista por entrada (F1-36). */
export interface HistoryEntryChange {
	id: number;
	post_id: number;
	batch_id: string;
	/** De cuántas entradas es el lote del cambio. */
	batch_posts: number;
	kind: ChangeKind;
	origin: HistoryOrigin;
	/** Null si la IA no lo recomendó. */
	ai: HistoryAi | null;
	anchor: string;
	/** Dirección a la que apunta ahora (vacía si se quitó el enlace). */
	url: string;
	previous_url: string | null;
	/** Título de la entrada de destino si es del sitio y el usuario puede leerla. */
	target_title: string | null;
	user_id: number;
	user_name: string;
	state: 'active' | 'undone';
	created_at: string;
	undone_at: string | null;
}

/** Una entrada con sus cambios (vista por entrada). */
export interface HistoryEntry {
	post_id: number;
	/** Null si la entrada ya no existe. */
	post_title: string | null;
	edit_url: string | null;
	view_url: string | null;
	total: number;
	active: number;
	undone: number;
	last_at: string;
	/** Los más recientes; el resto se pide aparte. */
	changes: HistoryEntryChange[];
	/** Proceso en segundo plano de esta entrada, si lo hay. */
	job?: HistoryJob | null;
}

export interface HistoryEntriesResponse extends Paged {
	items: HistoryEntry[];
	retention_days: number;
}

export interface HistoryEntryChangesResponse {
	items: HistoryEntryChange[];
	/** Cambios de la entrada con el filtro de origen aplicado. */
	total: number;
}

export interface EntryRunResponse {
	results: ChangeResult[];
	entry: HistoryEntry | null;
	/** Proceso en segundo plano si había demasiados cambios para la petición (202). */
	job: HistoryJob | null;
}

export type HistoryStatusFilter = 'all' | 'active' | 'undone';

export interface HistoryChangesResponse extends Paged {
	items: HistoryChange[];
}

export type ChangeStatus =
	| 'restored'
	| 'link_removed'
	| 'fix_reverted'
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
	/** Entrada del proceso (0 si es de un lote). */
	post_id?: number;
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
	/** El usuario ajustó el ancla dentro de la frase: el servidor la vuelve a comprobar (F1-22). */
	adjusted?: boolean;
	/** Lo que la IA opina del enlace; solo informativo, el servidor lo guarda en el Historial (F1-38). */
	ai_recommended?: boolean;
	ai_anchor?: boolean;
	ai_model?: string;
}

/** Un motivo por el que un ancla ajustada no vale (`errors`) o es dudosa (`warnings`), con su texto. */
export interface AnchorIssue {
	code: string;
	message: string;
}

/** Respuesta de `POST /suggestions/anchor` (docs/07 §3.2). */
export interface AnchorCheckResponse {
	valid: boolean;
	errors: AnchorIssue[];
	warnings: AnchorIssue[];
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
	/** Filtros en modo Exigir; sin sugerencias, el estado vacío los explica. */
	required?: FilterName[];
}

export interface InboundResponse extends Paged {
	ready: boolean;
	state: SuggestionState;
	per_page: number;
	items: Suggestion[];
	post_id?: number;
	required?: FilterName[];
}

/** Respuesta de descartar una sugerencia o de deshacer el descarte. */
export interface DismissalResponse {
	dismissed: boolean;
	source_id: number;
	target_id: number;
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

/** Enlace que el panel puso en el editor, para registrarlo en el Historial al guardar (F1-34). */
export interface EditorLink {
	/** Identificador de la inserción, generado en el cliente: evita registrar dos veces el mismo enlace. */
	insertion_id: string;
	/** Clave de la sugerencia de la que sale. */
	suggestion: string;
	/** Entrada a la que enlaza; la dirección la pone el servidor. */
	target_id: number;
	/** Texto enlazado. */
	anchor: string;
	/** La IA recomendaba la sugerencia (F1-38). Solo informativo: no cambia lo que se registra. */
	ai_recommended?: boolean;
	/** Se usó el ancla que propuso la IA. */
	ai_anchor?: boolean;
	/** Identificador exacto del modelo que la recomendó. */
	ai_model?: string;
}

export interface EditorLinkResult {
	insertion_id: string;
	status:
		| 'recorded'
		| 'duplicate'
		| 'not_found'
		| 'ambiguous'
		| 'unverified'
		| 'invalid';
	change_id: number;
}

export interface EditorHistoryResponse {
	batch_id: string | null;
	results: EditorLinkResult[];
}

/** Estimación de una llamada a la IA (`CostEstimator`); `cost` es nulo si el modelo no está en la tabla de precios. */
export interface AiEstimate {
	tokens_in: number;
	tokens_out: number;
	tokens: number;
	cost: number | null;
	prices_date: string;
}

/** Lo que el botón «Mejorar con IA» necesita saber (`GET /ai/capabilities`). */
export interface AiCapabilities {
	/** WordPress trae el cliente de IA (7.0 o posterior). */
	available: boolean;
	/** Hay un conector que genera texto. */
	text: boolean;
	/** El tope diario no es cero. */
	enabled: boolean;
	/** Se puede usar ahora: hay conector y no se ha alcanzado el tope de hoy. */
	ready: boolean;
	/**
	 * Tope diario: `ok` cabe una petición; `exhausted` hoy ya no cabe; `too_small` el tope es menor que una sola
	 * petición; `off` el tope es 0.
	 */
	budget: 'ok' | 'exhausted' | 'too_small' | 'off';
	/** Tope diario fijado, en tokens. */
	limit: number;
	/** Lo máximo que puede costar una petición, en tokens. */
	needs: number;
	provider: { id: string; name: string } | null;
	model: string | null;
	local: boolean;
	/** Segundos que espera el servidor al modelo en esta petición. */
	timeout: number;
	estimate: AiEstimate;
	connectors_url: string;
}

/** Sugerencia saliente tal como se envía a `POST /suggestions/rerank`. */
export interface RerankItem {
	target_id: number;
	sentence: string;
	anchor: string;
	offset: number;
}

/** Ancla mejor que propone la IA: un tramo literal de la frase, ya localizado. */
export interface RerankImproved {
	anchor: string;
	offset: number;
	before: string;
	after: string;
	anchor_start: number;
	anchor_end: number;
}

export interface RerankResult {
	/** Posición de la sugerencia en la petición. */
	index: number;
	target_id: number;
	/** Nulo si la IA no la valoró. */
	fit: boolean | null;
	confidence: number | null;
	improved: RerankImproved | null;
}

/** Gasto de una llamada, con el modelo que respondió. */
export interface AiUsage {
	tokens_in: number;
	tokens_out: number;
	tokens: number;
	/** Nulo si el modelo no está en la tabla de precios. */
	cost: number | null;
	local: boolean;
	provider: string;
	provider_name: string;
	model: string;
	prices_date: string;
}

export type RerankResponse =
	| {
			ok: true;
			/** En el orden nuevo. */
			items: RerankResult[];
			/** Posiciones que no se enviaron (destino no publicado, otro idioma…). */
			skipped: number[];
			usage: AiUsage;
	  }
	| {
			ok: false;
			reason: string;
			/** Texto breve y sin detalles técnicos, en el idioma del usuario. */
			message: string;
			usage?: AiUsage;
	  };

export interface AiStatusModel {
	provider: string;
	model: string;
	calls: number;
	tokens: number;
	local: boolean;
	cost: number | null;
}

export interface AiStatusEntry {
	provider: string;
	provider_name: string;
	model: string;
	name: string;
	local: boolean;
	/** Declara todo lo que pide el reordenado (esquema JSON, sistema, tope de salida). */
	strict?: boolean;
	/** Dólares por millón de tokens de entrada y de salida; null si no se conoce. */
	price: [ number, number ] | null;
	recommended: boolean;
}

export interface AiLastFailure {
	/** Fecha ISO 8601 en UTC. */
	at: string;
	model: string;
	provider: string;
	code: string;
	message: string;
}

/** Estado del conector, tope y gasto para Ajustes (`GET /ai/status`). */
export interface AiStatus {
	state: 'connected' | 'none' | 'unavailable';
	available: boolean;
	text: boolean;
	json: boolean;
	local: boolean;
	provider: { id: string; name: string } | null;
	model: string | null;
	/** Modelos sin `:batch`: los recomendados primero y el resto por nombre. */
	models: AiStatusEntry[];
	/** Última llamada fallida (solo administradores); null si no hay. */
	last_failure: AiLastFailure | null;
	connectors_url: string;
	budget: {
		limit: number;
		used: number;
		remaining: number;
		exhausted: boolean;
	};
	usage: {
		days: number;
		calls: number;
		failed: number;
		tokens: number;
		cost: number;
		cost_partial: boolean;
		models: AiStatusModel[];
		prices_date: string;
	};
	notice_dismissed: boolean;
}

/** Reglas automáticas (F1-13). */
export interface RuleItem {
	id: number;
	phrases: string[];
	target_id: number;
	target_title: string;
	target_url: string;
	target_available: boolean;
	lang: string;
	max_per_post: number;
	first_only: boolean;
	word_gap: boolean;
	exclude_post_types: string[];
	exclude_categories: number[];
	active: boolean;
	created_at: string;
}

/** Topes duros vigentes (ajustes del usuario). */
export interface RuleLimitsInfo {
	words_per_link: number;
	links_per_target: number;
	same_anchor_max: number;
}

export interface RuleStalled {
	post_id: number;
	title: string;
	edit_url: string;
	time: number;
}

export interface RuleStalledRule {
	rule_id: number;
	phrase: string;
	time: number;
}

export interface RulesResponse {
	items: RuleItem[];
	total: number;
	limits: RuleLimitsInfo;
	/** Entradas que siguieron abiertas en el editor durante un día y a las que no se pudo aplicar las reglas. */
	stalled: RuleStalled[];
	/** Reglas que no se aplican porque quien las creó ya no puede gestionar el sitio. */
	stalled_rules: RuleStalledRule[];
}

export interface RuleOptions {
	post_types: { name: string; label: string }[];
	categories: { id: number; name: string }[];
	limits: RuleLimitsInfo;
}

export interface RuleTarget {
	id: number;
	title: string;
	type: string;
	url: string;
}

/** Lo que se envía al crear o editar una regla. */
export interface RuleInput {
	phrases: string[];
	target_id: number;
	max_per_post: number;
	first_only: boolean;
	word_gap: boolean;
	exclude_post_types: string[];
	exclude_categories: number[];
	active: boolean;
}

export interface RulePreviewLink {
	anchor: string;
	before: string;
	after: string;
}

/** Una entrada donde encaja una regla, con el enlace que se añadiría. */
export interface RuleCandidate {
	post_id: number;
	title: string;
	skipped: string | null;
	skipped_message: string;
	links: RulePreviewLink[];
	edit_url: string;
	view_url: string;
}

export interface RuleCandidatesResponse {
	items: RuleCandidate[];
	cursor: number;
	done: boolean;
	examined: number;
	/** La búsqueda se acotó con el índice léxico (hay un recorrido completo disponible). */
	narrowed: boolean;
}

export interface RuleApplyResponse {
	post_id: number;
	rule_id: number;
	inserted: number;
	failed: { reason: string; message: string }[];
	skipped: string | null;
	skipped_message: string;
	batch_id: string;
}

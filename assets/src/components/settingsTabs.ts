// Pestañas de Ajustes y su relación con la dirección (sin dependencias de WordPress, para poder probarlo).
import type { SettingsValues } from '../types';

export type SettingsTabName =
	| 'general'
	| 'suggestions'
	| 'links'
	| 'ai'
	| 'data';

export const TAB_NAMES: SettingsTabName[] = [
	'general',
	'suggestions',
	'links',
	'ai',
	'data',
];

export type SectionKey =
	| 'content'
	| 'suggestions'
	| 'overload'
	| 'underlinked'
	| 'filters'
	| 'insertion'
	| 'manner'
	| 'aiConnector'
	| 'aiLimits'
	| 'aiUsage'
	| 'exclusions'
	| 'history'
	| 'uninstall'
	| 'maintenance';

/** Única fuente de la pestaña y del ancla de cada tarjeta (los textos están en `settingsSections()`). */
export const SECTION_META: Record<
	SectionKey,
	{ id: string; tab: SettingsTabName }
> = {
	content: { id: 'magiclinking-s-content', tab: 'general' },
	maintenance: { id: 'magiclinking-maintenance', tab: 'general' },
	suggestions: { id: 'magiclinking-s-suggestions', tab: 'suggestions' },
	overload: { id: 'magiclinking-s-overload', tab: 'suggestions' },
	underlinked: { id: 'magiclinking-s-underlinked', tab: 'suggestions' },
	filters: { id: 'magiclinking-s-filters', tab: 'suggestions' },
	insertion: { id: 'magiclinking-s-insertion', tab: 'links' },
	manner: { id: 'magiclinking-s-manner', tab: 'links' },
	exclusions: { id: 'magiclinking-s-exclusions', tab: 'links' },
	aiConnector: { id: 'magiclinking-s-ai-connector', tab: 'ai' },
	aiLimits: { id: 'magiclinking-s-ai-limits', tab: 'ai' },
	aiUsage: { id: 'magiclinking-s-ai-usage', tab: 'ai' },
	history: { id: 'magiclinking-s-data', tab: 'data' },
	uninstall: { id: 'magiclinking-s-uninstall', tab: 'data' },
};

/**
 * Anclas de la versión anterior de Ajustes (sin pestañas) y la pestaña en la que están ahora, para que los
 * enlaces guardados sigan llevando a su sitio. Sale de `SECTION_META`: solo estos ids cuentan, no cualquier
 * `#magiclinking-…` de la pantalla.
 */
export const LEGACY_ANCHORS: Record< string, SettingsTabName > =
	Object.fromEntries(
		Object.values( SECTION_META ).map( ( { id, tab } ) => [ id, tab ] )
	);

/**
 * En qué pestaña está cada ajuste. Es un `Record` de todas las claves de `SettingsValues`: si se añade un
 * ajuste y no se le da pestaña, el compilador falla.
 */
const TAB_OF: Record< keyof SettingsValues, SettingsTabName > = {
	post_types: 'general',
	outbound_shown: 'suggestions',
	inbound_per_page: 'suggestions',
	words_per_link: 'suggestions',
	same_anchor_max: 'suggestions',
	links_per_target_max: 'suggestions',
	low_inbound_threshold: 'suggestions',
	filters: 'suggestions',
	link_in_headings: 'links',
	link_in_quotes: 'links',
	link_in_tables: 'links',
	open_in_new_tab: 'links',
	extra_rel: 'links',
	ignored_urls: 'links',
	ignored_words: 'links',
	ai_daily_token_limit: 'ai',
	ai_model: 'ai',
	history_retention_days: 'data',
	delete_data_on_uninstall: 'data',
};

/** Ajustes de cada pestaña: de aquí salen sus marcas de cambios sin guardar. */
export const TAB_FIELDS = TAB_NAMES.reduce(
	( fields, tab ) => ( {
		...fields,
		[ tab ]: ( Object.keys( TAB_OF ) as ( keyof SettingsValues )[] ).filter(
			( key ) => TAB_OF[ key ] === tab
		),
	} ),
	{} as Record< SettingsTabName, ( keyof SettingsValues )[] >
);

/**
 * Pestaña (y ancla) que pide la dirección: `#tab=suggestions` o una de las anclas antiguas. Cualquier otro
 * fragmento (los ids de los títulos, `#magiclinking-root`…) no es una petición de pestaña: devuelve `null`.
 * @param hash Fragmento de la dirección (con o sin «#»).
 */
export function parseHash(
	hash: string
): { tab: SettingsTabName; anchor: string } | null {
	const clean = hash.replace( /^#/, '' );
	const wanted = /^tab=([a-z]+)$/.exec( clean )?.[ 1 ] ?? '';
	const named = TAB_NAMES.find( ( name ) => name === wanted );
	if ( named ) {
		return { tab: named, anchor: '' };
	}
	if ( Object.prototype.hasOwnProperty.call( LEGACY_ANCHORS, clean ) ) {
		return {
			tab: LEGACY_ANCHORS[ clean ] as SettingsTabName,
			anchor: clean,
		};
	}
	return null;
}

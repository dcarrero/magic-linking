// Pestañas de Ajustes y su relación con la dirección (sin dependencias de WordPress, para poder probarlo).

export type SettingsTabName = 'general' | 'suggestions' | 'links' | 'data';

/**
 * Anclas de la versión anterior de Ajustes (cuando no había pestañas) y la pestaña en la que están ahora,
 * para que los enlaces guardados sigan llevando al sitio correcto.
 */
export const LEGACY_ANCHORS: Record< string, SettingsTabName > = {
	'magiclinking-s-content': 'general',
	'magiclinking-maintenance': 'general',
	'magiclinking-s-suggestions': 'suggestions',
	'magiclinking-s-overload': 'suggestions',
	'magiclinking-s-underlinked': 'suggestions',
	'magiclinking-s-insertion': 'links',
	'magiclinking-s-manner': 'links',
	'magiclinking-s-exclusions': 'links',
	'magiclinking-s-data': 'data',
	'magiclinking-s-uninstall': 'data',
};

const TAB_NAMES: SettingsTabName[] = [
	'general',
	'suggestions',
	'links',
	'data',
];

/**
 * Pestaña (y ancla) que pide la dirección: `#tab=suggestions` o un ancla de la versión anterior.
 * @param hash Fragmento de la dirección (con o sin «#»).
 */
export function tabFromHash( hash: string ): {
	tab: SettingsTabName;
	anchor: string;
} {
	const clean = hash.replace( /^#/, '' );
	const wanted = /^tab=([a-z]+)$/.exec( clean )?.[ 1 ] ?? '';
	const named = TAB_NAMES.find( ( name ) => name === wanted );
	if ( named ) {
		return { tab: named, anchor: '' };
	}
	const legacy = Object.prototype.hasOwnProperty.call( LEGACY_ANCHORS, clean )
		? LEGACY_ANCHORS[ clean ]
		: undefined;
	if ( legacy ) {
		return { tab: legacy, anchor: clean };
	}
	return { tab: 'general', anchor: '' };
}

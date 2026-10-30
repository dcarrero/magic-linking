export interface LinkScope {
	headings: boolean;
	quotes: boolean;
	tables: boolean;
}

export interface LinkAttributes {
	target?: string;
	rel?: string;
}

/** De dónde lee el editor las categorías o etiquetas de la entrada (filtros por taxonomía). */
export interface TermSource {
	taxonomy: string;
	/** Atributo de la entrada en Gutenberg (`categories`, `tags`, la `rest_base` de la taxonomía). */
	field: string;
	hierarchical: boolean;
}

export type TermSources = Partial<
	Record< 'category' | 'post_tag', TermSource >
>;

/** Categorías y etiquetas elegidas en el editor, sin guardar: IDs o, en el clásico, nombres de etiqueta. */
export type EditorTerms = Partial<
	Record< 'category' | 'post_tag', ( number | string )[] >
>;

/**
 * Configuración que el servidor pasa al panel del editor (`EditorPanel::enqueue`).
 */
export interface EditorBoot {
	mode: 'block' | 'classic';
	postId: number;
	/** Sugerencias entrantes por página. */
	perPage: number;
	/** Dónde más se enlaza además de párrafos y listas (Ajustes). */
	linkIn: LinkScope;
	/** `target` y `rel` de los enlaces que se insertan (Ajustes). */
	linkAttrs: LinkAttributes;
	historyUrl: string;
	reportUrl: string;
	/** Ajustes → Sugerencias; vacío si el usuario no puede cambiar los ajustes. */
	settingsUrl?: string;
	/** De dónde lee el editor sus categorías y etiquetas. */
	terms?: TermSources;
}

declare global {
	interface Window {
		magiclinkingEditor?: EditorBoot;
	}
}

export function editorBoot(): EditorBoot | null {
	return window.magiclinkingEditor ?? null;
}

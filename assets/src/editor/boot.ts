export interface LinkScope {
	headings: boolean;
	quotes: boolean;
	tables: boolean;
}

export interface LinkAttributes {
	target?: string;
	rel?: string;
}

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
}

declare global {
	interface Window {
		magiclinkingEditor?: EditorBoot;
	}
}

export function editorBoot(): EditorBoot | null {
	return window.magiclinkingEditor ?? null;
}

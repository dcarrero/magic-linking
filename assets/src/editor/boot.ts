/**
 * Configuración que el servidor pasa al panel del editor (`EditorPanel::enqueue`).
 */
export interface EditorBoot {
	mode: 'block' | 'classic';
	postId: number;
	/** Sugerencias entrantes por página. */
	perPage: number;
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

import type { FullConfig } from '@playwright/test';
import { assertSameSite } from './wp';

/** Antes de la pasada: WP-CLI y el navegador tienen que ser el mismo sitio. */
export default function globalSetup( config: FullConfig ): void {
	assertSameSite( config.projects[ 0 ]?.use.baseURL ?? 'http://localhost:8888' );
}

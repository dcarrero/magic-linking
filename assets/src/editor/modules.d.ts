// @wordpress/block-editor no trae tipos propios en esta versión: solo se usa su `store`.
declare module '@wordpress/block-editor' {
	import type { StoreDescriptor } from '@wordpress/data';
	export const store: StoreDescriptor;
}

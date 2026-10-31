import { useId, useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import type { CurrentLink } from '../types';

/**
 * Cómo se nombra el destino: con título y enlace, o sin ellos si el usuario no puede leerlo.
 *
 * @param root0
 * @param root0.link
 */
function LinkName( { link }: { link: CurrentLink } ) {
	if ( link.restricted ) {
		return <>{ __( 'Internal link (no access)', 'magic-linking' ) }</>;
	}
	const title = link.title || __( '(no title)', 'magic-linking' );
	if ( ! link.url ) {
		return <>{ title }</>;
	}
	return (
		<a href={ link.url } target="_blank" rel="noopener noreferrer">
			{ title }
			<span className="screen-reader-text">
				{ ' ' }
				{ __( '(opens in a new tab)', 'magic-linking' ) }
			</span>
		</a>
	);
}

interface Props {
	links: CurrentLink[];
}

/**
 * Bloque plegable «Enlaces actuales (N)» de la pestaña Salientes (docs/07 §3.3): los destinos a los que la entrada ya
 * enlaza, cada uno con la marca «Ya enlazado» en icono y texto (nunca solo color) y las anclas que usa.
 *
 * @param root0
 * @param root0.links
 */
export function CurrentLinks( { links }: Props ) {
	const [ open, setOpen ] = useState( false );
	const id = useId();

	if ( links.length === 0 ) {
		return null;
	}

	return (
		<div className="magiclinking-current">
			<Button
				variant="link"
				className="magiclinking-current__toggle"
				aria-expanded={ open }
				aria-controls={ id }
				onClick={ () => setOpen( ! open ) }
			>
				<span aria-hidden="true">{ open ? '▾ ' : '▸ ' }</span>
				{ sprintf(
					/* translators: %d: number of entries this one already links to. */
					__( 'Current links (%d)', 'magic-linking' ),
					links.length
				) }
			</Button>
			<ul
				id={ id }
				className="magiclinking-current__list"
				hidden={ ! open }
			>
				{ links.map( ( link ) => (
					<li key={ link.id } className="magiclinking-current__item">
						<span className="magiclinking-current__title">
							<LinkName link={ link } />
						</span>{ ' ' }
						<span className="magiclinking-current__mark">
							<span aria-hidden="true">✓ </span>
							{ __( 'Already linked', 'magic-linking' ) }
						</span>
						{ link.anchors.length > 0 && (
							<span className="magiclinking-current__anchors">
								{ ' · ' }
								{ link.anchors
									.map( ( anchor ) => `“${ anchor }”` )
									.join( ', ' ) }
							</span>
						) }
					</li>
				) ) }
			</ul>
		</div>
	);
}

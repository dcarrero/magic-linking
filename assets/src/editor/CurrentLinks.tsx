import { useId, useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import type { CurrentLink } from '../types';

/**
 * Suma a los enlaces actuales que dio el servidor los que se han puesto desde el panel desde entonces (el servidor solo
 * los vuelve a dar al pedir la lista otra vez). Un destino no se repite: sus anclas se juntan.
 *
 * @param known Enlaces actuales del servidor.
 * @param added Enlaces puestos desde el panel.
 */
export function mergeCurrent(
	known: CurrentLink[],
	added: CurrentLink[]
): CurrentLink[] {
	const merged = known.map( ( link ) => ( {
		...link,
		anchors: [ ...link.anchors ],
	} ) );
	for ( const link of added ) {
		const same = merged.find( ( other ) => other.id === link.id );
		if ( ! same ) {
			merged.push( { ...link, anchors: [ ...link.anchors ] } );
			continue;
		}
		for ( const anchor of link.anchors ) {
			if ( ! same.anchors.includes( anchor ) ) {
				same.anchors.push( anchor );
			}
		}
	}
	return merged;
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
							{ link.url ? (
								<a
									href={ link.url }
									target="_blank"
									rel="noopener noreferrer"
								>
									{ link.title ||
										__( '(no title)', 'magic-linking' ) }
									<span className="screen-reader-text">
										{ ' ' }
										{ __(
											'(opens in a new tab)',
											'magic-linking'
										) }
									</span>
								</a>
							) : (
								link.title ||
								__( '(no title)', 'magic-linking' )
							) }
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

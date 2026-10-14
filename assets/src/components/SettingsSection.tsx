import { Card, CardBody, CardHeader } from '@wordpress/components';
import type { ReactNode } from 'react';

interface Props {
	/** Identificador de la sección: ancla del índice y base del id del título. */
	id: string;
	title: string;
	/** Explicación breve de la sección, a la izquierda en pantallas anchas. */
	description?: string;
	children: ReactNode;
}

/**
 * Una tarjeta de Ajustes por tema (docs/07 §8): título real (h2), explicación a un lado y controles al otro.
 * @param root0
 * @param root0.id
 * @param root0.title
 * @param root0.description
 * @param root0.children
 */
export function SettingsSection( { id, title, description, children }: Props ) {
	return (
		<section
			id={ id }
			className="magiclinking-section"
			aria-labelledby={ `${ id }-title` }
		>
			<Card size="large">
				<CardHeader>
					<h2
						id={ `${ id }-title` }
						className="magiclinking-section__title"
					>
						{ title }
					</h2>
				</CardHeader>
				<CardBody>
					<div className="magiclinking-section__layout">
						{ description && (
							<p className="magiclinking-section__description">
								{ description }
							</p>
						) }
						<div className="magiclinking-section__controls">
							{ children }
						</div>
					</div>
				</CardBody>
			</Card>
		</section>
	);
}

import { Card, CardBody, CardHeader } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';

export type SectionKey =
	| 'content'
	| 'suggestions'
	| 'insertion'
	| 'exclusions'
	| 'data'
	| 'maintenance';

export interface SectionInfo {
	id: string;
	title: string;
	description: string;
}

/**
 * Las secciones de Ajustes, en orden: de aquí salen el índice y el título y la explicación de cada tarjeta.
 */
export function settingsSections(): Record< SectionKey, SectionInfo > {
	return {
		content: {
			id: 'magiclinking-s-content',
			title: __( 'Content analyzed', 'magic-linking' ),
			description: __(
				'Magic Linking reads these content types to find links between them.',
				'magic-linking'
			),
		},
		suggestions: {
			id: 'magiclinking-s-suggestions',
			title: __( 'Suggestions', 'magic-linking' ),
			description: __(
				'How many suggestions you see, and how entries are judged as linked too little or too much.',
				'magic-linking'
			),
		},
		insertion: {
			id: 'magiclinking-s-insertion',
			title: __( 'Link insertion', 'magic-linking' ),
			description: __(
				'Where in your content links can go, and what the links Magic Linking inserts look like.',
				'magic-linking'
			),
		},
		exclusions: {
			id: 'magiclinking-s-exclusions',
			title: __( 'Exclusions', 'magic-linking' ),
			description: __(
				'Entries and words that Magic Linking should leave alone.',
				'magic-linking'
			),
		},
		data: {
			id: 'magiclinking-s-data',
			title: __( 'History and data', 'magic-linking' ),
			description: __(
				'How long you can undo changes, and what happens to the data when the plugin is deleted.',
				'magic-linking'
			),
		},
		maintenance: {
			id: 'magiclinking-maintenance',
			title: __( 'Maintenance', 'magic-linking' ),
			description: __(
				'Run the analysis again and go back through the first steps.',
				'magic-linking'
			),
		},
	};
}

interface Props {
	/** Identificador de la sección: ancla del índice y base del id del título. */
	section: SectionInfo;
	children: ReactNode;
}

/**
 * Una tarjeta de Ajustes por tema (docs/07 §8): título real (h2), explicación a un lado y controles al otro.
 * @param root0
 * @param root0.section
 * @param root0.children
 */
export function SettingsSection( { section, children }: Props ) {
	const { id, title, description } = section;
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

import { Card, CardBody, CardHeader } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';
import {
	SECTION_META,
	type SectionKey,
	type SettingsTabName,
} from './settingsTabs';

export interface SectionInfo {
	id: string;
	/** Pestaña en la que está la tarjeta. */
	tab: SettingsTabName;
	title: string;
	description: string;
}

/**
 * Las tarjetas de Ajustes: de aquí salen el id, el título y la explicación de cada una.
 */
export function settingsSections(): Record< SectionKey, SectionInfo > {
	return {
		content: {
			...SECTION_META.content,
			title: __( 'Content analyzed', 'magic-linking' ),
			description: __(
				'Magic Linking reads these content types to find links between them.',
				'magic-linking'
			),
		},
		maintenance: {
			...SECTION_META.maintenance,
			title: __( 'Maintenance', 'magic-linking' ),
			description: __(
				'Run the analysis again and go back through the first steps.',
				'magic-linking'
			),
		},
		suggestions: {
			...SECTION_META.suggestions,
			title: __( 'Suggestions shown', 'magic-linking' ),
			description: __(
				'How many suggestions you see in the editor.',
				'magic-linking'
			),
		},
		overload: {
			...SECTION_META.overload,
			title: __( 'To avoid overloading your texts', 'magic-linking' ),
			description: __(
				'These are quality settings, not limits: you can always insert any link yourself.',
				'magic-linking'
			),
		},
		underlinked: {
			...SECTION_META.underlinked,
			title: __( 'Under-linked entries', 'magic-linking' ),
			description: __(
				'When an entry is shown as linked too little.',
				'magic-linking'
			),
		},
		insertion: {
			...SECTION_META.insertion,
			title: __( 'Where links can be inserted', 'magic-linking' ),
			description: __(
				'Paragraphs and lists are always used. These are off by default; turn them on if you want suggestions and links there too.',
				'magic-linking'
			),
		},
		manner: {
			...SECTION_META.manner,
			title: __( 'How links are inserted', 'magic-linking' ),
			description: __(
				'What the links Magic Linking inserts look like.',
				'magic-linking'
			),
		},
		exclusions: {
			...SECTION_META.exclusions,
			title: __( 'Exclusions', 'magic-linking' ),
			description: __(
				'Entries and words that Magic Linking should leave alone.',
				'magic-linking'
			),
		},
		history: {
			...SECTION_META.history,
			title: __( 'History of changes', 'magic-linking' ),
			description: __(
				'How long you can undo changes.',
				'magic-linking'
			),
		},
		uninstall: {
			...SECTION_META.uninstall,
			title: __( 'When the plugin is deleted', 'magic-linking' ),
			description: __(
				'What happens to the data of Magic Linking.',
				'magic-linking'
			),
		},
	};
}

interface Props {
	/** Datos de la tarjeta: su id es el ancla y la base del id del título. */
	section: SectionInfo;
	children: ReactNode;
}

/**
 * Una tarjeta (grupo) de Ajustes dentro de una pestaña (docs/07 §8): título real (h2), explicación a un lado y controles al otro.
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

import { Button, Modal } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';

interface Props {
	title: string;
	/** Texto del botón que confirma. */
	confirmLabel: string;
	busy: boolean;
	onConfirm: () => void;
	onCancel: () => void;
	children: ReactNode;
}

/**
 * Confirmación antes de cambiar contenido. `Modal` atrapa el foco, se cierra con Esc y devuelve el foco al
 * botón que lo abrió.
 * @param root0
 * @param root0.title
 * @param root0.confirmLabel
 * @param root0.busy
 * @param root0.onConfirm
 * @param root0.onCancel
 * @param root0.children
 */
export function ConfirmModal( {
	title,
	confirmLabel,
	busy,
	onConfirm,
	onCancel,
	children,
}: Props ) {
	return (
		<Modal
			title={ title }
			onRequestClose={ busy ? () => undefined : onCancel }
			size="medium"
		>
			<div className="magiclinking-confirm">
				{ children }
				<div className="magiclinking-actions">
					<Button
						variant="primary"
						onClick={ onConfirm }
						isBusy={ busy }
						disabled={ busy }
						accessibleWhenDisabled
					>
						{ confirmLabel }
					</Button>
					<Button
						variant="secondary"
						onClick={ onCancel }
						disabled={ busy }
						accessibleWhenDisabled
					>
						{ __( 'Cancel', 'magic-linking' ) }
					</Button>
				</div>
			</div>
		</Modal>
	);
}

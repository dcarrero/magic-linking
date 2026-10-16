import type { ChangeKind, HistoryAi, HistoryOrigin } from '../types';
import { aiDetails, sourceLabel } from './historyFormat';

interface Props {
	origin: HistoryOrigin;
	kind: ChangeKind;
	/** Lo que se sabe de la IA, si recomendó el enlace (F1-38). */
	ai?: HistoryAi | null;
}

/**
 * De dónde viene un cambio: «Panel del editor», y si la IA lo recomendó, «· IA» más el modelo y si se usó su ancla.
 * Lo comparten la vista por entrada y las filas desplegadas de un lote.
 * @param root0
 * @param root0.origin
 * @param root0.kind
 * @param root0.ai
 */
export function HistorySource( { origin, kind, ai }: Props ) {
	return (
		<>
			{ sourceLabel( origin, kind, !! ai ) }
			{ ai &&
				aiDetails( ai ).map( ( line ) => (
					<span key={ line } className="magiclinking-change__note">
						{ line }
					</span>
				) ) }
		</>
	);
}

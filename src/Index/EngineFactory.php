<?php
/**
 * Motor de pesos del índice léxico con la K de los ajustes.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Index;

use MagicLinking\Core\Settings;
use MagicLinking\Engine\Indexer;

/**
 * Da el {@see Indexer} que corresponde a K en este momento (Ajustes → Avanzado). Recuerda uno por valor de K: el
 * indexado lo pide por cada entrada y no tiene que construir otro cada vez; si el ajuste cambia, la siguiente
 * petición ya recibe el nuevo.
 */
final class EngineFactory {

	/**
	 * Indexadores ya creados, por K.
	 *
	 * @var array<int, Indexer>
	 */
	private array $made = array();

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Ajustes.
	 */
	public function __construct( private Settings $settings ) {
	}

	/**
	 * El indexador para la K actual.
	 */
	public function indexer(): Indexer {
		$k = $this->settings->k_terms();

		return $this->made[ $k ] ??= new Indexer( array( 'k' => $k ) );
	}
}

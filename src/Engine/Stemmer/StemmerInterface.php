<?php
/**
 * Contrato común de los stemmers del motor léxico.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine\Stemmer;

/**
 * Reduce una palabra a su raíz.
 */
interface StemmerInterface {

	/**
	 * Devuelve la raíz de una palabra.
	 *
	 * La palabra llega ya normalizada por el tokenizador: minúsculas, NFC y
	 * apóstrofo recto (U+0027). La raíz es una clave de comparación, no una
	 * palabra para mostrar.
	 *
	 * @param string $word Palabra en minúsculas, UTF-8.
	 * @return string Raíz.
	 */
	public function stem( string $word ): string;
}

<?php
/**
 * Palabras vacías por idioma.
 *
 * Listas iniciales: las de Snowball para castellano e inglés
 * (https://snowballstem.org/algorithms/spanish/stop.txt y
 * https://snowballstem.org/algorithms/english/stop.txt), sin cambios,
 * más unas pocas palabras funcionales propias en castellano que Snowball no
 * incluye. Las listas de Snowball son de Martin Porter y del proyecto Snowball,
 * BSD-3-Clause:
 *
 * Copyright (c) 2001, Dr Martin Porter
 * Copyright (c) 2002, Richard Boulton
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 * 2. Redistributions in binary form must reproduce the above copyright notice,
 *    this list of conditions and the following disclaimer in the documentation
 *    and/or other materials provided with the distribution.
 * 3. Neither the name of the copyright holder nor the names of its
 *    contributors may be used to endorse or promote products derived from this
 *    software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
 * ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE
 * LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
 * CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 * La lista castellana conviene revisarla con un corpus real (palabras con
 * df > 40 %).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * Consulta de palabras vacías.
 */
final class Stopwords {

	/**
	 * Castellano: lista de Snowball.
	 */
	private const SNOWBALL_ES = array(
		'de',
		'la',
		'que',
		'el',
		'en',
		'y',
		'a',
		'los',
		'del',
		'se',
		'las',
		'por',
		'un',
		'para',
		'con',
		'no',
		'una',
		'su',
		'al',
		'lo',
		'como',
		'más',
		'pero',
		'sus',
		'le',
		'ya',
		'o',
		'este',
		'sí',
		'porque',
		'esta',
		'entre',
		'cuando',
		'muy',
		'sin',
		'sobre',
		'también',
		'me',
		'hasta',
		'hay',
		'donde',
		'quien',
		'desde',
		'todo',
		'nos',
		'durante',
		'todos',
		'uno',
		'les',
		'ni',
		'contra',
		'otros',
		'ese',
		'eso',
		'ante',
		'ellos',
		'e',
		'esto',
		'mí',
		'antes',
		'algunos',
		'qué',
		'unos',
		'yo',
		'otro',
		'otras',
		'otra',
		'él',
		'tanto',
		'esa',
		'estos',
		'mucho',
		'quienes',
		'nada',
		'muchos',
		'cual',
		'poco',
		'ella',
		'estar',
		'estas',
		'algunas',
		'algo',
		'nosotros',
		'mi',
		'mis',
		'tú',
		'te',
		'ti',
		'tu',
		'tus',
		'ellas',
		'nosotras',
		'vosotros',
		'vosotras',
		'os',
		'mío',
		'mía',
		'míos',
		'mías',
		'tuyo',
		'tuya',
		'tuyos',
		'tuyas',
		'suyo',
		'suya',
		'suyos',
		'suyas',
		'nuestro',
		'nuestra',
		'nuestros',
		'nuestras',
		'vuestro',
		'vuestra',
		'vuestros',
		'vuestras',
		'esos',
		'esas',
		'estoy',
		'estás',
		'está',
		'estamos',
		'estáis',
		'están',
		'esté',
		'estés',
		'estemos',
		'estéis',
		'estén',
		'estaré',
		'estarás',
		'estará',
		'estaremos',
		'estaréis',
		'estarán',
		'estaría',
		'estarías',
		'estaríamos',
		'estaríais',
		'estarían',
		'estaba',
		'estabas',
		'estábamos',
		'estabais',
		'estaban',
		'estuve',
		'estuviste',
		'estuvo',
		'estuvimos',
		'estuvisteis',
		'estuvieron',
		'estuviera',
		'estuvieras',
		'estuviéramos',
		'estuvierais',
		'estuvieran',
		'estuviese',
		'estuvieses',
		'estuviésemos',
		'estuvieseis',
		'estuviesen',
		'estando',
		'estado',
		'estada',
		'estados',
		'estadas',
		'estad',
		'he',
		'has',
		'ha',
		'hemos',
		'habéis',
		'han',
		'haya',
		'hayas',
		'hayamos',
		'hayáis',
		'hayan',
		'habré',
		'habrás',
		'habrá',
		'habremos',
		'habréis',
		'habrán',
		'habría',
		'habrías',
		'habríamos',
		'habríais',
		'habrían',
		'había',
		'habías',
		'habíamos',
		'habíais',
		'habían',
		'hube',
		'hubiste',
		'hubo',
		'hubimos',
		'hubisteis',
		'hubieron',
		'hubiera',
		'hubieras',
		'hubiéramos',
		'hubierais',
		'hubieran',
		'hubiese',
		'hubieses',
		'hubiésemos',
		'hubieseis',
		'hubiesen',
		'habiendo',
		'habido',
		'habida',
		'habidos',
		'habidas',
		'soy',
		'eres',
		'es',
		'somos',
		'sois',
		'son',
		'sea',
		'seas',
		'seamos',
		'seáis',
		'sean',
		'seré',
		'serás',
		'será',
		'seremos',
		'seréis',
		'serán',
		'sería',
		'serías',
		'seríamos',
		'seríais',
		'serían',
		'era',
		'eras',
		'éramos',
		'erais',
		'eran',
		'fui',
		'fuiste',
		'fue',
		'fuimos',
		'fuisteis',
		'fueron',
		'fuera',
		'fueras',
		'fuéramos',
		'fuerais',
		'fueran',
		'fuese',
		'fueses',
		'fuésemos',
		'fueseis',
		'fuesen',
		'siendo',
		'sido',
		'tengo',
		'tienes',
		'tiene',
		'tenemos',
		'tenéis',
		'tienen',
		'tenga',
		'tengas',
		'tengamos',
		'tengáis',
		'tengan',
		'tendré',
		'tendrás',
		'tendrá',
		'tendremos',
		'tendréis',
		'tendrán',
		'tendría',
		'tendrías',
		'tendríamos',
		'tendríais',
		'tendrían',
		'tenía',
		'tenías',
		'teníamos',
		'teníais',
		'tenían',
		'tuve',
		'tuviste',
		'tuvo',
		'tuvimos',
		'tuvisteis',
		'tuvieron',
		'tuviera',
		'tuvieras',
		'tuviéramos',
		'tuvierais',
		'tuvieran',
		'tuviese',
		'tuvieses',
		'tuviésemos',
		'tuvieseis',
		'tuviesen',
		'teniendo',
		'tenido',
		'tenida',
		'tenidos',
		'tenidas',
		'tened',
	);

	/**
	 * Castellano: palabras funcionales propias que no están en la lista de Snowball.
	 */
	private const EXTRA_ES = array(
		'cada',
		'hacia',
		'tras',
		'según',
		'mediante',
		'sino',
		'aunque',
		'pues',
		'así',
		'cómo',
		'cuál',
		'cuáles',
		'cuales',
		'cuándo',
		'dónde',
		'quién',
		'usted',
		'ustedes',
	);

	/**
	 * Inglés: lista de Snowball.
	 */
	private const SNOWBALL_EN = array(
		'i',
		'me',
		'my',
		'myself',
		'we',
		'our',
		'ours',
		'ourselves',
		'you',
		'your',
		'yours',
		'yourself',
		'yourselves',
		'he',
		'him',
		'his',
		'himself',
		'she',
		'her',
		'hers',
		'herself',
		'it',
		'its',
		'itself',
		'they',
		'them',
		'their',
		'theirs',
		'themselves',
		'what',
		'which',
		'who',
		'whom',
		'this',
		'that',
		'these',
		'those',
		'am',
		'is',
		'are',
		'was',
		'were',
		'be',
		'been',
		'being',
		'have',
		'has',
		'had',
		'having',
		'do',
		'does',
		'did',
		'doing',
		'would',
		'should',
		'could',
		'ought',
		'i\'m',
		'you\'re',
		'he\'s',
		'she\'s',
		'it\'s',
		'we\'re',
		'they\'re',
		'i\'ve',
		'you\'ve',
		'we\'ve',
		'they\'ve',
		'i\'d',
		'you\'d',
		'he\'d',
		'she\'d',
		'we\'d',
		'they\'d',
		'i\'ll',
		'you\'ll',
		'he\'ll',
		'she\'ll',
		'we\'ll',
		'they\'ll',
		'isn\'t',
		'aren\'t',
		'wasn\'t',
		'weren\'t',
		'hasn\'t',
		'haven\'t',
		'hadn\'t',
		'doesn\'t',
		'don\'t',
		'didn\'t',
		'won\'t',
		'wouldn\'t',
		'shan\'t',
		'shouldn\'t',
		'can\'t',
		'cannot',
		'couldn\'t',
		'mustn\'t',
		'let\'s',
		'that\'s',
		'who\'s',
		'what\'s',
		'here\'s',
		'there\'s',
		'when\'s',
		'where\'s',
		'why\'s',
		'how\'s',
		'a',
		'an',
		'the',
		'and',
		'but',
		'if',
		'or',
		'because',
		'as',
		'until',
		'while',
		'of',
		'at',
		'by',
		'for',
		'with',
		'about',
		'against',
		'between',
		'into',
		'through',
		'during',
		'before',
		'after',
		'above',
		'below',
		'to',
		'from',
		'up',
		'down',
		'in',
		'out',
		'on',
		'off',
		'over',
		'under',
		'again',
		'further',
		'then',
		'once',
		'here',
		'there',
		'when',
		'where',
		'why',
		'how',
		'all',
		'any',
		'both',
		'each',
		'few',
		'more',
		'most',
		'other',
		'some',
		'such',
		'no',
		'nor',
		'not',
		'only',
		'own',
		'same',
		'so',
		'than',
		'too',
		'very',
	);

	/**
	 * Formas que se ignoran al comparar (la ñ se conserva).
	 */
	private const ACCENTS = array(
		'á' => 'a',
		'é' => 'e',
		'í' => 'i',
		'ó' => 'o',
		'ú' => 'u',
		'ü' => 'u',
	);

	/**
	 * Conjuntos de búsqueda por idioma, construidos la primera vez que se piden.
	 *
	 * @var array<string, array<string, true>>
	 */
	private static array $sets = array();

	/**
	 * Idiomas con lista.
	 *
	 * @return list<string> Códigos ISO 639-1.
	 */
	public static function languages(): array {
		return array( 'es', 'en' );
	}

	/**
	 * Lista de palabras vacías de un idioma, tal como se escriben.
	 *
	 * @param string $language Código de idioma («es», «es_ES», «en-US»…).
	 * @return list<string> Palabras en minúsculas; vacía si el idioma no tiene lista.
	 */
	public static function for( string $language ): array {
		switch ( self::code( $language ) ) {
			case 'es':
				return array_values( array_unique( array_merge( self::SNOWBALL_ES, self::EXTRA_ES ) ) );
			case 'en':
				return self::SNOWBALL_EN;
			default:
				return array();
		}
	}

	/**
	 * ¿Es palabra vacía?
	 *
	 * Acepta la palabra con o sin tildes («más» y «mas», «qué» y «que»), porque
	 * en texto informal se omiten a menudo.
	 *
	 * @param string $word     Palabra en minúsculas, UTF-8.
	 * @param string $language Código de idioma.
	 * @return bool
	 */
	public static function is( string $word, string $language ): bool {
		$code = self::code( $language );
		if ( ! isset( self::$sets[ $code ] ) ) {
			self::$sets[ $code ] = array();
			foreach ( self::for( $code ) as $stopword ) {
				self::$sets[ $code ][ $stopword ]                         = true;
				self::$sets[ $code ][ strtr( $stopword, self::ACCENTS ) ] = true;
			}
		}

		return isset( self::$sets[ $code ][ $word ] ) || isset( self::$sets[ $code ][ strtr( $word, self::ACCENTS ) ] );
	}

	/**
	 * Código de idioma de dos letras.
	 *
	 * @param string $language Código de idioma o locale.
	 * @return string
	 */
	private static function code( string $language ): string {
		return strtolower( substr( $language, 0, 2 ) );
	}
}

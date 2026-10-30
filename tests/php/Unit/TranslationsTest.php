<?php
/**
 * Las cadenas del código están en el .pot y traducidas al castellano.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class TranslationsTest extends TestCase {

	private const ROOT = __DIR__ . '/../../../';

	/**
	 * Cadenas con el dominio magic-linking en PHP y TypeScript.
	 *
	 * @return array<int, string>
	 */
	private function code_strings(): array {
		$strings = array();
		$files   = array( self::ROOT . 'magic-linking.php' );

		foreach ( array( 'src', 'assets/src' ) as $dir ) {
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( self::ROOT . $dir, RecursiveDirectoryIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( in_array( $file->getExtension(), array( 'php', 'ts', 'tsx' ), true ) ) {
					$files[] = $file->getPathname();
				}
			}
		}

		foreach ( $files as $path ) {
			$source = (string) file_get_contents( $path );
			// Literal entre comillas simples o dobles (el apóstrofo obliga a las dobles en TypeScript).
			$lit = "(?:'((?:[^'\\\\\\\\]|\\\\\\\\.)*)'|\"((?:[^\"\\\\\\\\]|\\\\\\\\.)*)\")";
			preg_match_all( "/\\b(?:__|esc_html__|esc_attr__)\\(\\s*{$lit}\\s*,\\s*'magic-linking'/s", $source, $m, PREG_SET_ORDER );
			foreach ( $m as $hit ) {
				$strings[] = stripcslashes( '' !== $hit[1] ? $hit[1] : ( $hit[2] ?? '' ) );
			}
			// Con contexto: en el catálogo la clave es contexto + \x04 + msgid.
			preg_match_all( "/\\b(?:_x|esc_html_x|esc_attr_x)\\(\\s*{$lit}\\s*,\\s*{$lit}\\s*,\\s*'magic-linking'/s", $source, $m, PREG_SET_ORDER );
			foreach ( $m as $hit ) {
				$text      = '' !== $hit[1] ? $hit[1] : $hit[2];
				$context   = '' !== ( $hit[3] ?? '' ) ? $hit[3] : ( $hit[4] ?? '' );
				$strings[] = stripcslashes( $context ) . "\x04" . stripcslashes( $text );
			}
			preg_match_all( "/\\b_n\\(\\s*{$lit}\\s*,\\s*{$lit}/s", $source, $m, PREG_SET_ORDER );
			foreach ( $m as $hit ) {
				// En el catálogo, el plural va en msgid_plural de la misma entrada.
				$strings[] = stripcslashes( '' !== $hit[1] ? $hit[1] : ( $hit[2] ?? '' ) );
			}
		}

		// La descripción del plugin sale de la cabecera.
		$header = (string) file_get_contents( self::ROOT . 'magic-linking.php' );
		if ( preg_match( '/^\s*\*\s*Description:\s*(.+)$/m', $header, $d ) ) {
			$strings[] = trim( $d[1] );
		}

		return array_values( array_unique( $strings ) );
	}

	/**
	 * Entradas de un .po o .pot como msgid → msgstr (sin plurales).
	 *
	 * @param string $file Nombre del fichero en languages/.
	 *
	 * @return array<string, string>
	 */
	private function catalog( string $file ): array {
		$entries = array();
		foreach ( preg_split( '/\n\n/', (string) file_get_contents( self::ROOT . 'languages/' . $file ) ) as $block ) {
			if ( preg_match( '/^msgid "(.*)"$/m', $block, $id ) && '' !== $id[1] ) {
				preg_match( '/^msgstr "(.*)"$/m', $block, $str );
				$context                                       = preg_match( '/^msgctxt "(.*)"$/m', $block, $ctx ) ? stripcslashes( $ctx[1] ) . "\x04" : '';
				$entries[ $context . stripcslashes( $id[1] ) ] = stripcslashes( $str[1] ?? '' );
			}
		}

		return $entries;
	}

	public function test_every_string_is_in_the_pot(): void {
		$pot     = $this->catalog( 'magic-linking.pot' );
		$missing = array_diff( $this->code_strings(), array_keys( $pot ) );

		$this->assertSame( array(), array_values( $missing ), 'Faltan en languages/magic-linking.pot; regenéralo.' );
	}

	public function test_every_singular_string_is_translated_to_spanish(): void {
		$po      = $this->catalog( 'magic-linking-es_ES.po' );
		$missing = array();

		foreach ( $this->code_strings() as $text ) {
			if ( ! isset( $po[ $text ] ) || '' === $po[ $text ] ) {
				// Los plurales llevan msgstr[0]; se comprueban en la prueba de integración.
				if ( ! $this->is_plural( $text ) ) {
					$missing[] = $text;
				}
			}
		}

		$this->assertSame( array(), $missing, 'Sin traducir en languages/magic-linking-es_ES.po.' );
	}

	public function test_spanish_files_exist(): void {
		foreach ( array( 'magic-linking-es_ES.mo', 'magic-linking-es_ES.po' ) as $file ) {
			$this->assertFileExists( self::ROOT . 'languages/' . $file );
		}
		// Un JSON por script que traduce (WordPress lo busca por el hash de la ruta): administración, Gutenberg y editor clásico.
		foreach ( array( 'index', 'editor', 'classic' ) as $script ) {
			$this->assertFileExists( self::ROOT . 'languages/magic-linking-es_ES-' . md5( "assets/build/{$script}.js" ) . '.json', "Falta el JSON de traducción de assets/build/{$script}.js." );
		}
	}

	private function is_plural( string $text ): bool {
		return in_array( $text, array( '%d entry analyzed.', '%d outbound suggestion', '%d broken link', '%s entry', '%s link', '%d entry', 'About %s minute left.', 'About %s hour left.', '%s new or modified entry pending.', '%s link added', '%s link undone.', '%s link added again.', '%s link will be taken out.', '%s undone link will be added again, as it was.', '%s batch in the history.', '%s older batch shown.', '%d change could not be applied by itself.', '%d history row has expired.', '%d history row deleted.', '%d entry found', '%d suggestion', '%s link destination changed', '%s link removed', '%s more change shown.', '%1$s change will be undone in “%2$s”, newest first.', '%1$s undone change will be applied again in “%2$s”, as it was.', '%s entry with changes.', '%1$s of %2$s change active', 'Changes are kept for %s day.', '%1$s, %2$d outbound suggestion', '%d suggestion is marked as not recommended.', 'Suggestions improved with AI. %d is not recommended.', '%1$s of %2$s link recommended by the AI' , '%s rule', '%d link added.', '%d link per entry', 'skips %d category', 'The most links this rule adds to one entry. Your settings allow %d link to the same target from one entry, so that is the real maximum.', '%s rule', '%d link added.', '%d link per entry', 'skips %d category', 'The most links this rule adds to one entry. Your settings allow %d link to the same target from one entry, so that is the real maximum.' ), true );
	}
}

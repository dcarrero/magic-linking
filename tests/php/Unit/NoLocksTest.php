<?php
/**
 * La gratuita no trae topes, cuotas, comprobaciones de licencia ni funciones bloqueadas.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class NoLocksTest extends TestCase {

	/**
	 * Palabras que delatan un bloqueo, un tope o una venta dentro del plugin.
	 */
	private const FORBIDDEN = '/\b(upgrade to|upgrade now|upgrade plan|premium|unlock|coming soon|pr[oó]ximamente|license key|licence key|trial|pro version|free version|limit reached|quota|cuota)\b/i';

	/**
	 * @return array<string, array{0: string}>
	 */
	public function sources(): array {
		$root  = dirname( __DIR__, 3 );
		$files = array();

		foreach ( array( 'src', 'assets/src' ) as $dir ) {
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $dir, RecursiveDirectoryIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( in_array( $file->getExtension(), array( 'php', 'ts', 'tsx', 'scss' ), true ) ) {
					$files[ substr( $file->getPathname(), strlen( $root ) + 1 ) ] = array( $file->getPathname() );
				}
			}
		}

		return $files;
	}

	/**
	 * @dataProvider sources
	 */
	public function test_source_has_no_locks_limits_or_sales_copy( string $path ): void {
		$source = (string) file_get_contents( $path );

		$this->assertSame( 0, preg_match( self::FORBIDDEN, $source, $match ), 'Texto de bloqueo o venta en ' . $path . ': ' . ( $match[0] ?? '' ) );
	}

	public function test_the_report_has_no_row_selection(): void {
		$table = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/src/components/DataTable.tsx' );

		$this->assertStringNotContainsString( 'checkbox', $table, 'La gratuita no pinta casillas de selección múltiple.' );
	}
}

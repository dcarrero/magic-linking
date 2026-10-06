<?php
/**
 * Esquema de las tablas: sentencias válidas para dbDelta() y sin interpolación de llamadas.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Unit\Core;

use MagicLinking\Core\Schema;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase {

	private const COLLATE = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';

	public function test_one_statement_per_table_with_the_site_prefix(): void {
		$statements = Schema::create_statements( 'wp_7_', self::COLLATE );

		$this->assertSame( Schema::TABLES, array_keys( $statements ) );
		foreach ( Schema::TABLES as $table ) {
			$this->assertStringStartsWith( "CREATE TABLE wp_7_magiclinking_{$table} (\n", $statements[ $table ] );
			$this->assertStringEndsWith( ') ' . self::COLLATE . ';', $statements[ $table ] );
		}
	}

	public function test_statements_follow_dbdelta_format(): void {
		foreach ( Schema::create_statements( 'wp_', self::COLLATE ) as $sql ) {
			$this->assertStringContainsString( "\n  PRIMARY KEY  (", $sql, 'dbDelta() exige dos espacios tras PRIMARY KEY.' );
			$this->assertStringNotContainsString( '{', $sql, 'No debe quedar ninguna interpolación sin resolver.' );
			$this->assertStringNotContainsString( '$', $sql );
		}
	}

	public function test_source_has_no_callable_interpolation(): void {
		$source = (string) file_get_contents( __DIR__ . '/../../../../src/Core/Schema.php' );

		$this->assertSame( 0, preg_match( '/\{\$[A-Za-z_>-]*\(/', $source ), 'Nada de {$f(...)} dentro de cadenas.' );
	}
}

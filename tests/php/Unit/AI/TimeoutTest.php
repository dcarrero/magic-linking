<?php
/**
 * Tiempo de espera de la IA por proveedor y recorte por el límite de PHP (docs/05 §10).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Unit\AI;

use MagicLinking\AI\Client;
use MagicLinking\AI\Failure;
use MagicLinking\AI\Reranker;
use PHPUnit\Framework\TestCase;

final class TimeoutTest extends TestCase {

	public function test_a_cloud_provider_gets_more_time_than_a_local_one(): void {
		$this->assertSame( 20, Reranker::timeout( true ), 'Ollama conserva los 20 s.' );
		$this->assertGreaterThanOrEqual( 45, Reranker::timeout( false ), 'Con OpenRouter + gpt-4.1-mini el reordenado tardó ~25 s.' );
		$this->assertLessThanOrEqual( 60, Reranker::timeout( false ) );
	}

	public function test_the_wait_is_cut_to_what_php_allows(): void {
		$this->assertSame( 50, Client::fit_seconds( 50, 0, 3.0, false ), 'Sin límite de PHP, lo pedido.' );
		$this->assertSame( 50, Client::fit_seconds( 50, 30, 3.0, true ), 'Si se pudo ampliar el límite, lo pedido.' );
		$this->assertSame( 22, Client::fit_seconds( 50, 30, 3.0, false ), '30 s de PHP - 3 s ya gastados - 5 s de margen.' );
		$this->assertSame( 20, Client::fit_seconds( 20, 120, 1.0, false ), 'Nunca más de lo pedido.' );
		$this->assertSame( 1, Client::fit_seconds( 50, 30, 29.0, false ), 'Como mínimo 1 s.' );
	}

	public function test_a_timeout_or_an_upstream_error_is_not_confused_with_a_network_error(): void {
		$this->assertTrue( ( new Failure( Failure::UPSTREAM ) )->retryable() );
		$this->assertFalse( ( new Failure( Failure::TIMEOUT ) )->retryable(), 'Reintentar un tiempo agotado solo dobla la espera.' );
	}

	public function test_the_detail_shown_to_the_admin_has_no_keys_and_is_short(): void {
		$raw = 'prompt_client_error: Incorrect API key sk-abc123def456ghi789 at https://x.test/v1?key=SECRET99&a=1 Authorization: Bearer tok_abcdefghijk <b>x</b>';
		$out = Failure::scrub( $raw );

		$this->assertStringNotContainsString( 'abc123def456', $out );
		$this->assertStringNotContainsString( 'SECRET99', $out );
		$this->assertStringNotContainsString( 'tok_abcdefghijk', $out );
		$this->assertStringNotContainsString( '<b>', $out );
		$this->assertLessThanOrEqual( 300, mb_strlen( Failure::scrub( str_repeat( 'a ', 500 ) ) ) );
	}

	public function test_scrub_hides_google_openai_anthropic_and_openrouter_keys_but_not_look_alikes(): void {
		$out = Failure::scrub( 'API key AIzaSyA1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q not valid; sk-or-v1-0123456789abcdef; sk-ant-api03-abcdefghijkl; Bearer abc.def.ghi; use key_something and order-12345' );

		$this->assertStringNotContainsString( 'AIzaSy', $out );
		$this->assertStringNotContainsString( 'sk-or-v1', $out );
		$this->assertStringNotContainsString( 'sk-ant', $out );
		$this->assertStringNotContainsString( 'abc.def.ghi', $out );
		$this->assertStringContainsString( 'key_something', $out );
		$this->assertStringContainsString( 'order-12345', $out );
	}

	public function test_scrub_removes_quoted_fragments_of_the_users_text(): void {
		$out = Failure::scrub( 'invalid input near \'Mi receta secreta de paella\' at position 5; body {"title":"Tarta de la abuela"} and “otro título” "otro más"; it\'s fine' );

		$this->assertStringNotContainsString( 'paella', $out );
		$this->assertStringNotContainsString( 'abuela', $out );
		$this->assertStringNotContainsString( 'título', $out );
		$this->assertStringNotContainsString( 'otro más', $out );
		$this->assertStringContainsString( 'invalid input near', $out );
		$this->assertStringContainsString( 'it\'s fine', $out, 'Un apóstrofo dentro de una palabra no abre una cita.' );
	}
}

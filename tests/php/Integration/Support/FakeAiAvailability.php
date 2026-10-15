<?php
/**
 * Disponibilidad del proveedor de IA simulado.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration\Support;

use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;

final class FakeAiAvailability implements ProviderAvailabilityInterface {

	public function isConfigured(): bool {
		return FakeAiProvider::$enabled;
	}
}

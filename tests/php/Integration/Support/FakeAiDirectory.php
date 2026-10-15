<?php
/**
 * Lista de modelos del proveedor de IA simulado.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration\Support;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;

final class FakeAiDirectory implements ModelMetadataDirectoryInterface {

	/**
	 * @return list<ModelMetadata>
	 */
	public function listModelMetadata(): array {
		return array_map( array( $this, 'metadata' ), FakeAiProvider::$models );
	}

	public function hasModelMetadata( string $model_id ): bool {
		return in_array( $model_id, FakeAiProvider::$models, true );
	}

	public function getModelMetadata( string $model_id ): ModelMetadata {
		if ( ! $this->hasModelMetadata( $model_id ) ) {
			throw new InvalidArgumentException( 'Unknown model ' . $model_id ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Prueba.
		}

		return $this->metadata( $model_id );
	}

	private function metadata( string $id ): ModelMetadata {
		return new ModelMetadata(
			$id,
			$id,
			array( CapabilityEnum::textGeneration() ),
			array_merge(
				array(
					new SupportedOption( OptionEnum::maxTokens() ),
					new SupportedOption( OptionEnum::systemInstruction() ),
				),
				// Como los modelos de OpenRouter sin `response_format` en `supported_parameters`.
				in_array( $id, FakeAiProvider::$without_schema, true ) ? array() : array(
					new SupportedOption( OptionEnum::outputMimeType() ),
					new SupportedOption( OptionEnum::outputSchema() ),
				),
				array(
					new SupportedOption( OptionEnum::inputModalities() ),
					new SupportedOption( OptionEnum::outputModalities() ),
					new SupportedOption( OptionEnum::customOptions() ),
				)
			)
		);
	}
}

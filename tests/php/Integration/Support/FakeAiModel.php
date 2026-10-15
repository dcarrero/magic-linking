<?php
/**
 * Modelo de texto del proveedor de IA simulado.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration\Support;

use Throwable;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

final class FakeAiModel implements ModelInterface, TextGenerationModelInterface {

	private ModelConfig $config;

	public function __construct( private ModelMetadata $metadata, private ProviderMetadata $provider ) {
		$this->config = new ModelConfig();
	}

	public function metadata(): ModelMetadata {
		return $this->metadata;
	}

	public function providerMetadata(): ProviderMetadata {
		return $this->provider;
	}

	public function setConfig( ModelConfig $config ): void {
		$this->config = $config;
	}

	public function getConfig(): ModelConfig {
		return $this->config;
	}

	/**
	 * @param list<Message> $prompt Mensajes.
	 *
	 * @throws Throwable Lo que `FakeAiProvider::$reply` diga que lance.
	 */
	public function generateTextResult( array $prompt ): GenerativeAiResult {
		++FakeAiProvider::$calls;

		$sent = (string) $this->config->getSystemInstruction();
		foreach ( $prompt as $message ) {
			foreach ( $message->getParts() as $part ) {
				$sent .= "\n" . (string) $part->getText();
			}
		}
		FakeAiProvider::$sent[] = $sent;
		FakeAiProvider::$used[] = $this->metadata->getId();

		$answer = FakeAiProvider::answer( FakeAiProvider::$calls );
		if ( $answer instanceof Throwable ) {
			throw $answer;
		}

		return new GenerativeAiResult(
			'fake-' . FakeAiProvider::$calls,
			array( new Candidate( new Message( MessageRoleEnum::model(), array( new MessagePart( $answer ) ) ), FinishReasonEnum::stop() ) ),
			new TokenUsage( FakeAiProvider::$usage[0], FakeAiProvider::$usage[1], FakeAiProvider::$usage[0] + FakeAiProvider::$usage[1] ),
			$this->provider,
			$this->metadata
		);
	}
}

<?php
/**
 * Extractor de texto que sigue los ajustes de «dónde se puede enlazar».
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Index;

use MagicLinking\Core\Settings;
use MagicLinking\Engine\ContentExtractor;
use MagicLinking\Engine\DomExtractor;
use MagicLinking\Engine\ExtractedContent;

/**
 * Lee los ajustes en cada extracción (no al crearse), de modo que un proceso largo o una petición que cambia
 * los ajustes y analiza a continuación usan siempre los vigentes.
 */
final class ScopedExtractor implements ContentExtractor {

	/**
	 * Último extractor creado y los ajustes con los que se creó.
	 *
	 * @var array{scope: array{headings: bool, quotes: bool, tables: bool}, extractor: DomExtractor}|null
	 */
	private ?array $current = null;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Ajustes.
	 */
	public function __construct( private Settings $settings ) {
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $content Contenido guardado.
	 */
	public function extract( string $content ): ExtractedContent {
		$scope = $this->settings->link_scope();
		if ( null === $this->current || $this->current['scope'] !== $scope ) {
			$this->current = array(
				'scope'     => $scope,
				'extractor' => new DomExtractor( $scope['headings'], $scope['quotes'], $scope['tables'] ),
			);
		}

		return $this->current['extractor']->extract( $content );
	}
}

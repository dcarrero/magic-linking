<?php
/**
 * Reordenado de las sugerencias salientes con un modelo generativo (docs/04 §9).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\AI;

use MagicLinking\Content\Destination;
use MagicLinking\Engine\Document;
use MagicLinking\Engine\Semantic;
use MagicLinking\I18n\Language;
use MagicLinking\Index\PostSource;
use MagicLinking\Rest\SuggestionPresenter;
use WP_Post;

/**
 * Manda al modelo, en una sola llamada, las 20 mejores sugerencias salientes de la entrada abierta y devuelve su
 * orden nuevo, cuáles no encajan y, a veces, un ancla mejor.
 *
 * Qué sale del sitio (docs/05 §6): la frase de la entrada que el usuario está editando con el ancla marcada (la
 * entrada abierta se envía porque pulsa el botón) y, de cada destino, su título y un resumen de unos 200 tokens hecho
 * con título-encabezados-cuerpo ya limpios. Solo se envían destinos publicados, sin contraseña, de un tipo que se
 * analiza y del mismo idioma; las privadas, protegidas y los borradores se descartan antes de construir la
 * petición. Nunca metadatos, autores, correos ni comentarios.
 *
 * Nunca se llama en bucle: una petición del usuario, una llamada al modelo. Si algo falla (sin IA, tope diario,
 * veto, red, respuesta vacía o JSON roto), devuelve `ok: false` con el motivo y el panel se queda con el orden léxico.
 *
 * @phpstan-type Item array{target_id: int, sentence: string, anchor: string, offset: int}
 */
final class Reranker {

	/**
	 * Caracteres del resumen de cada destino (~200 tokens).
	 */
	public const SUMMARY_CHARS = 720;

	/**
	 * Segundos de espera al modelo en el editor con un proveedor local (Ollama): el de siempre (05 §8).
	 */
	public const LOCAL_TIMEOUT = 20;

	/**
	 * Segundos de espera con un proveedor en la nube: con OpenRouter y `gpt-4.1-mini` el reordenado tardó ~25 s
	 * (D-61 anexo 9) y 20 s cortaba una petición que sí terminaba. `Client` lo recorta si `max_execution_time` de PHP
	 * es menor.
	 */
	public const CLOUD_TIMEOUT = 50;

	/**
	 * Tiempo de espera según el proveedor.
	 *
	 * @param bool $local El proveedor es local.
	 */
	public static function timeout( bool $local ): int {
		return $local ? self::LOCAL_TIMEOUT : self::CLOUD_TIMEOUT;
	}

	/**
	 * Motivo cuando ninguna sugerencia se puede enviar al modelo (destino no publicado, de otro idioma…).
	 */
	public const NOTHING = 'nothing';

	/**
	 * Caracteres que puede tener la frase de una sugerencia; una más larga no se envía.
	 */
	public const SENTENCE_LIMIT = 2000;

	/**
	 * Palabras del cuerpo que entran en el resumen antes de cortar por caracteres.
	 */
	public const SUMMARY_WORDS = 120;

	/**
	 * Tokens de entrada fijos de una petición (instrucción de sistema y formato), medidos.
	 */
	public const BASE_INPUT_TOKENS = 280;

	/**
	 * Caracteres del marco de la petición (línea de idioma y saltos) que se suman a la cota.
	 */
	public const FRAME_CHARS = 60;

	/**
	 * Tokens de entrada por candidata (frase, título y resumen de hasta {@see self::SUMMARY_WORDS} palabras).
	 */
	public const CANDIDATE_INPUT_TOKENS = 190;

	/**
	 * Tokens de salida por candidata y fijos (el JSON de `results`), medidos.
	 */
	public const CANDIDATE_OUTPUT_TOKENS = 45;

	/**
	 * Tokens de salida fijos.
	 */
	public const BASE_OUTPUT_TOKENS = 30;

	/**
	 * Constructor.
	 *
	 * @param Client       $client       Cliente de IA.
	 * @param Capabilities $capabilities Capacidades del conector.
	 * @param PostSource   $posts        Lector de entradas ya limpias.
	 * @param Destination  $destination  Comprobación de que un destino se puede enlazar.
	 * @param Language     $language     Idioma por entrada.
	 * @param Budget       $budget       Tope diario.
	 */
	public function __construct(
		private Client $client,
		private Capabilities $capabilities,
		private PostSource $posts,
		private Destination $destination,
		private Language $language,
		private Budget $budget
	) {
	}

	/**
	 * Estimación típica de un reordenado de `$count` candidatas, para enseñarla antes de pulsar.
	 *
	 * Calibrada con gemma3:4b (05 §5.1): 3 candidatas gastaron 678 + 103 tokens y 4, 846 + 148. La estimación queda
	 * entre 1,2 y 1,3 veces por encima de lo real: una cota prudente, no un múltiplo.
	 *
	 * @param int         $count Candidatas.
	 * @param string|null $model Modelo previsto.
	 * @param bool        $local El modelo es local.
	 *
	 * @return array{tokens_in: int, tokens_out: int, tokens: int, cost: float|null}
	 */
	public static function typical_estimate( int $count, ?string $model, bool $local ): array {
		$count = max( 0, min( RerankProtocol::MAX_CANDIDATES, $count ) );
		// Instrucción de sistema y formato (~280 tokens) y, por candidata, frase, título y resumen (~190, con el
		// resumen en su tope de palabras).
		$in  = self::BASE_INPUT_TOKENS + $count * self::CANDIDATE_INPUT_TOKENS;
		$out = self::output_tokens( $count );

		return CostEstimator::estimate( $in, $out, $model, $local );
	}

	/**
	 * Cota de lo que puede costar una petición de `$count` candidatas: es la cifra con la que se comprueba el tope
	 * diario (regla 4), la que devuelve `GET /ai/capabilities` como `needs` y la del aviso «tope demasiado pequeño».
	 * Entrada en el peor caso (todas las candidatas con frase, título y resumen en su tope) más el tope de salida
	 * que la petición envía de verdad, así que cubre cualquier petición válida de esa cantidad de candidatas.
	 * Lo que enseña el botón es la estimación típica ({@see self::typical_estimate()}), mucho más ajustada.
	 *
	 * @param int $count Candidatas.
	 */
	public static function worst_case_tokens( int $count ): int {
		$count = max( 0, min( RerankProtocol::MAX_CANDIDATES, $count ) );
		$chars = mb_strlen( RerankProtocol::system(), 'UTF-8' ) + self::FRAME_CHARS + $count * RerankProtocol::CANDIDATE_MAX_CHARS;

		return (int) ceil( $chars / CostEstimator::CHARS_PER_TOKEN ) + RerankProtocol::MAX_OUTPUT_TOKENS;
	}

	/**
	 * Tokens de salida típicos para `$count` candidatas (el tope de la petición es mayor, ver RerankProtocol).
	 *
	 * @param int $count Candidatas.
	 */
	private static function output_tokens( int $count ): int {
		return min( RerankProtocol::MAX_OUTPUT_TOKENS, self::CANDIDATE_OUTPUT_TOKENS * $count + self::BASE_OUTPUT_TOKENS );
	}

	/**
	 * Cota de una petición ya montada: el texto real de entrada más el tope de salida que se envía. Es lo que se
	 * compara con el tope diario y lo que se guarda en `ai_tokens_est`; nunca pasa de {@see self::worst_case_tokens()}.
	 *
	 * @param string $system Instrucción de sistema.
	 * @param string $prompt Petición.
	 */
	public static function request_tokens( string $system, string $prompt ): int {
		// Siempre con la medida de los idiomas que no son inglés (3,6): la instrucción va en inglés pero las frases
		// y los resúmenes no tienen por qué, y el idioma declarado del sitio puede no ser el del contenido.
		return CostEstimator::tokens( $system . "\n" . $prompt, 'es' ) + RerankProtocol::MAX_OUTPUT_TOKENS;
	}

	/**
	 * Reordena las sugerencias salientes de una entrada.
	 *
	 * Cada frase se comprueba en el servidor contra el texto real de la entrada: el del editor si se manda
	 * (`$content`, puede no estar guardado) y, si no, el guardado. Una frase que no está se descarta y no se envía.
	 * El usuario ya puede escribir lo que quiera en una entrada que puede editar; lo que no puede es mandar al modelo
	 * texto que no es de la entrada.
	 *
	 * @param int         $post_id Entrada que se edita (origen de los enlaces).
	 * @param array       $items   Sugerencias en orden léxico: destino, frase, ancla y byte donde empieza el ancla.
	 * @param string|null $content Contenido del editor; null = el guardado.
	 *
	 * @phpstan-param list<Item> $items
	 *
	 * @return array<string, mixed> `ok` y, si va bien, `items` (orden nuevo), `skipped` y `usage`; si no, `reason`.
	 */
	public function rerank( int $post_id, array $items, ?string $content = null ): array {
		$caps = $this->capabilities->detect();
		if ( ! $caps['text'] ) {
			return array(
				'ok'     => false,
				'reason' => $this->client->vetoed() ? Failure::VETOED : Failure::UNAVAILABLE,
			);
		}

		$lang       = $this->language->for_post( $post_id );
		$haystack   = $this->paragraphs( $post_id, $content );
		$candidates = array();
		$positions  = array();
		$skipped    = array();
		$summaries  = array();

		foreach ( $items as $index => $item ) {
			$summary = $this->candidate( $post_id, $item, $summaries, $haystack );
			if ( null === $summary || count( $candidates ) >= RerankProtocol::MAX_CANDIDATES ) {
				$skipped[] = $index;
				continue;
			}

			$candidates[] = array(
				'sentence' => $item['sentence'],
				'anchor'   => $item['anchor'],
				'offset'   => $item['offset'],
				'title'    => $summary[0],
				'summary'  => $summary[1],
			);
			$positions[]  = $index;
		}

		if ( array() === $candidates ) {
			return array(
				'ok'     => false,
				'reason' => self::NOTHING,
			);
		}

		$prompt = RerankProtocol::prompt( $candidates, $lang );
		$system = RerankProtocol::system();
		$count  = count( $candidates );
		$tokens = self::request_tokens( $system, $prompt );

		// Un tope menor que una sola petición no se arregla esperando a mañana: se dice aparte.
		if ( $this->budget->enabled() && $this->budget->limit() < $tokens ) {
			return array(
				'ok'     => false,
				'reason' => Failure::TOO_SMALL,
				'needs'  => $tokens,
			);
		}

		$request = new Request(
			$prompt,
			$system,
			$caps['json'] ? RerankProtocol::schema() : null,
			RerankProtocol::MAX_OUTPUT_TOKENS,
			self::timeout( $caps['local'] ),
			null === $caps['model'] ? array() : array( $caps['model'] ),
			$tokens,
			'ai_rerank',
			array(
				'post_id'    => $post_id,
				'candidates' => $count,
				'lang'       => $lang,
			),
			false,
			null === $caps['provider'] ? '' : $caps['provider']['id']
		);

		$response = $this->client->generate( $request );
		if ( $response instanceof Failure ) {
			return array(
				'ok'     => false,
				'reason' => $response->code,
			);
		}

		$usage = $this->usage( $response );
		$rows  = RerankProtocol::parse( $response->text, $candidates );
		if ( null === $rows ) {
			return array(
				'ok'     => false,
				'reason' => Failure::INVALID,
				'usage'  => $usage,
			);
		}

		$out = array();
		foreach ( RerankProtocol::order( $rows ) as $at ) {
			$row   = $rows[ $at ];
			$item  = $items[ $positions[ $at ] ];
			$out[] = array(
				'index'      => $positions[ $at ],
				'target_id'  => $item['target_id'],
				'fit'        => $row['fit'],
				'confidence' => $row['confidence'],
				'improved'   => null === $row['improved'] ? null : array_merge(
					$row['improved'],
					SuggestionPresenter::span( $item['sentence'], $row['improved']['offset'], $row['improved']['anchor'] )
				),
			);
		}

		return array(
			'ok'      => true,
			'items'   => $out,
			'skipped' => $skipped,
			'usage'   => $usage,
		);
	}

	/**
	 * Gasto de la llamada, con el modelo que respondió.
	 *
	 * @param Response $response Respuesta.
	 *
	 * @return array<string, mixed>
	 */
	private function usage( Response $response ): array {
		return array(
			'tokens_in'     => $response->tokens_in,
			'tokens_out'    => $response->tokens_out,
			'tokens'        => $response->tokens(),
			'cost'          => $response->cost,
			'local'         => $response->local,
			'provider'      => $response->provider,
			'provider_name' => $response->provider_name,
			'model'         => $response->model,
			'prices_date'   => Models::PRICES_DATE,
		);
	}

	/**
	 * Textos de la entrada abierta (párrafos y encabezados), con los espacios colapsados, contra los que se comprueba
	 * cada frase.
	 *
	 * @param int         $post_id Entrada.
	 * @param string|null $content Contenido del editor; null = el guardado.
	 *
	 * @return list<string>
	 */
	private function paragraphs( int $post_id, ?string $content ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return array();
		}

		$document = $this->posts->from_post( $post, $content );
		$texts    = $document->headings;
		foreach ( $document->paragraphs as $paragraph ) {
			$texts[] = $paragraph->text;
		}

		return array_map( array( self::class, 'squash' ), $texts );
	}

	/**
	 * Texto con las secuencias de espacios (también los de no separación) convertidas en un solo espacio.
	 *
	 * @param string $text Texto.
	 */
	private static function squash( string $text ): string {
		return trim( (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', $text ) );
	}

	/**
	 * Título y resumen del destino de una sugerencia, o null si no se puede enviar a un modelo.
	 *
	 * @param int                                     $post_id   Origen.
	 * @param array                                   $item      Sugerencia.
	 * @param array<int, array{0: string, 1: string}> $summaries Resúmenes ya hechos en esta llamada.
	 * @param array                                   $haystack  Textos de la entrada abierta.
	 *
	 * @phpstan-param Item $item
	 * @phpstan-param list<string> $haystack
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private function candidate( int $post_id, array $item, array &$summaries, array $haystack ): ?array {
		$target_id = $item['target_id'];
		$sentence  = $item['sentence'];

		// Lo que llega del navegador se comprueba contra el servidor: el ancla está donde dice, la frase es de la
		// entrada abierta y no es desmesurada.
		if ( '' === $item['anchor'] || mb_strlen( $sentence, 'UTF-8' ) > self::SENTENCE_LIMIT || substr( $sentence, $item['offset'], strlen( $item['anchor'] ) ) !== $item['anchor'] ) {
			return null;
		}
		$squashed = self::squash( $sentence );
		$found    = false;
		foreach ( $haystack as $text ) {
			if ( str_contains( $text, $squashed ) ) {
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			return null;
		}

		// Destino publicado, sin contraseña, de un tipo analizado y del mismo idioma (regla 9): lo decide `resolve()`.
		if ( ! is_string( $this->destination->resolve( $post_id, $target_id ) ) ) {
			return null;
		}
		if ( isset( $summaries[ $target_id ] ) ) {
			return $summaries[ $target_id ];
		}

		$post = get_post( $target_id );
		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		$document = $this->posts->from_post( $post );
		// El título va aparte; el resumen es lo que sigue: encabezados y comienzo del cuerpo.
		$bare = new Document( $document->id, $document->type, $document->lang, '', '', 0, $document->headings, $document->paragraphs );
		$text = Semantic::text( $bare, self::SUMMARY_WORDS, self::SUMMARY_CHARS );
		$text = RerankProtocol::flat( wp_strip_all_tags( strip_shortcodes( $text ) ) );

		$summaries[ $target_id ] = array( RerankProtocol::flat( $document->title ), $text );

		return $summaries[ $target_id ];
	}
}

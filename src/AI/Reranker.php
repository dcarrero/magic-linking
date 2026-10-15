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
	 * Motivo cuando ninguna sugerencia se puede enviar al modelo (destino no publicado, de otro idioma…).
	 */
	public const NOTHING = 'nothing';

	/**
	 * Palabras del cuerpo que entran en el resumen antes de cortar por caracteres.
	 */
	public const SUMMARY_WORDS = 120;

	/**
	 * Constructor.
	 *
	 * @param Client       $client       Cliente de IA.
	 * @param Capabilities $capabilities Capacidades del conector.
	 * @param PostSource   $posts        Lector de entradas ya limpias.
	 * @param Destination  $destination  Comprobación de que un destino se puede enlazar.
	 * @param Language     $language     Idioma por entrada.
	 */
	public function __construct(
		private Client $client,
		private Capabilities $capabilities,
		private PostSource $posts,
		private Destination $destination,
		private Language $language
	) {
	}

	/**
	 * Estimación típica de un reordenado de `$count` candidatas, para enseñarla antes de pulsar.
	 *
	 * @param int         $count Candidatas.
	 * @param string|null $model Modelo previsto.
	 * @param bool        $local El modelo es local.
	 *
	 * @return array{tokens_in: int, tokens_out: int, tokens: int, cost: float|null}
	 */
	public static function typical_estimate( int $count, ?string $model, bool $local ): array {
		$count = max( 0, min( RerankProtocol::MAX_CANDIDATES, $count ) );
		// Instrucción de sistema y formato (~300 tokens) y, por candidata, frase, título y resumen (~300).
		$in  = 300 + $count * 300;
		$out = self::output_tokens( $count );

		return CostEstimator::estimate( $in, $out, $model, $local );
	}

	/**
	 * Tokens de salida previstos para `$count` candidatas (el tope de la petición es mayor, ver RerankProtocol).
	 *
	 * @param int $count Candidatas.
	 */
	private static function output_tokens( int $count ): int {
		return min( RerankProtocol::MAX_OUTPUT_TOKENS, 60 * $count + 120 );
	}

	/**
	 * Reordena las sugerencias salientes de una entrada.
	 *
	 * @param int   $post_id Entrada que se edita (origen de los enlaces).
	 * @param array $items   Sugerencias en orden léxico: destino, frase, ancla y byte donde empieza el ancla.
	 *
	 * @phpstan-param list<Item> $items
	 *
	 * @return array<string, mixed> `ok` y, si va bien, `items` (orden nuevo), `skipped` y `usage`; si no, `reason`.
	 */
	public function rerank( int $post_id, array $items ): array {
		if ( ! $this->capabilities->detect()['text'] ) {
			return array(
				'ok'     => false,
				'reason' => $this->client->vetoed() ? Failure::VETOED : Failure::UNAVAILABLE,
			);
		}

		$lang       = $this->language->for_post( $post_id );
		$candidates = array();
		$positions  = array();
		$skipped    = array();
		$summaries  = array();

		foreach ( $items as $index => $item ) {
			$summary = $this->candidate( $post_id, $item, $summaries );
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

		$caps    = $this->capabilities->detect();
		$prompt  = RerankProtocol::prompt( $candidates, $lang );
		$system  = RerankProtocol::system();
		$count   = count( $candidates );
		$tokens  = CostEstimator::tokens( $system . "\n" . $prompt, $lang ) + self::output_tokens( $count );
		$request = new Request(
			$prompt,
			$system,
			$caps['json'] ? RerankProtocol::schema() : null,
			RerankProtocol::MAX_OUTPUT_TOKENS,
			20,
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
	 * Título y resumen del destino de una sugerencia, o null si no se puede enviar a un modelo.
	 *
	 * @param int                                     $post_id   Origen.
	 * @param array                                   $item      Sugerencia.
	 * @param array<int, array{0: string, 1: string}> $summaries Resúmenes ya hechos en esta llamada.
	 *
	 * @phpstan-param Item $item
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private function candidate( int $post_id, array $item, array &$summaries ): ?array {
		$target_id = $item['target_id'];

		// Lo que llega del navegador se comprueba contra el servidor: el ancla está donde dice y el destino es una
		// entrada publicada, sin contraseña, de un tipo analizado y del mismo idioma (regla 9).
		if ( '' === $item['anchor'] || substr( $item['sentence'], $item['offset'], strlen( $item['anchor'] ) ) !== $item['anchor'] ) {
			return null;
		}
		if ( ! is_string( $this->destination->resolve( $post_id, $target_id ) ) ) {
			return null;
		}
		if ( isset( $summaries[ $target_id ] ) ) {
			return $summaries[ $target_id ];
		}

		$post = get_post( $target_id );
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || '' !== $post->post_password ) {
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

<?php
/**
 * Aplicar reglas automáticas a las entradas.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Rules;

use MagicLinking\Content\Destination;
use MagicLinking\Content\InsertionException;
use MagicLinking\Content\InsertRequest;
use MagicLinking\Content\Inserter;
use MagicLinking\Content\PostWriter;
use MagicLinking\Content\InsertResult;
use MagicLinking\Core\PostTitle;
use MagicLinking\Core\Settings;
use MagicLinking\Engine\Analyzer;
use MagicLinking\Engine\RuleMatcher;
use MagicLinking\Engine\RulePlan;
use MagicLinking\Graph\GraphRepository;
use MagicLinking\History\BatchId;
use MagicLinking\History\ChangeRepository;
use MagicLinking\I18n\Language;
use MagicLinking\Index\TableDocuments;
use Throwable;
use WP_Post;

/**
 * Une el motor de coincidencia ({@see RuleMatcher}) con la inserción segura ({@see Inserter}). Una regla nunca escribe
 * por su cuenta: cada enlace pasa por el mismo camino que los demás (solo el bloque afectado, verificación byte a byte
 * del resto, diff en el Historial antes de escribir y deshacer) y solo añade enlaces, nunca quita ni cambia uno
 * existente (docs/06, regla 5).
 */
final class RuleService {

	public const NOT_PUBLISHED     = 'not_published';
	public const TYPE_NOT_ANALYZED = 'type_not_analyzed';
	public const EXCLUDED_TYPE     = 'excluded_type';
	public const EXCLUDED_CATEGORY = 'excluded_category';
	public const OTHER_LANGUAGE    = 'other_language';
	public const BAD_TARGET        = 'bad_target';
	public const ALREADY_APPLIED   = 'already_applied';
	public const IGNORED           = 'ignored';
	public const CREATOR_CANNOT    = 'creator_cannot';

	/**
	 * Metadato de la entrada con las claves de las reglas ya aplicadas (o deshechas) en ella. Persistente, sin autoload
	 * y fuera del Historial: no caduca con la retención.
	 */
	public const APPLIED_META = 'magiclinking_rules_applied';
	public const LOCKED       = 'locked';

	/**
	 * Origen que se anota en el Historial.
	 */
	private const ORIGIN = ChangeRepository::ORIGIN_RULE;

	/**
	 * Constructor.
	 *
	 * @param TableDocuments   $documents   Documentos del motor con sus enlaces actuales.
	 * @param Inserter         $inserter    Inserción segura.
	 * @param Destination      $destination Comprobación del destino.
	 * @param Language         $language    Idioma por entrada.
	 * @param Settings         $settings    Ajustes (tipos y topes).
	 * @param SiteAnchors      $anchors     Anclas ya usadas hacia cada destino.
	 * @param ChangeRepository $changes     Historial.
	 * @param GraphRepository  $graph       Grafo (recorre las entradas).
	 * @param RuleRepository   $rules       Reglas.
	 * @param RuleMatcher      $matcher     Coincidencia.
	 */
	public function __construct(
		private TableDocuments $documents,
		private Inserter $inserter,
		private Destination $destination,
		private Language $language,
		private Settings $settings,
		private SiteAnchors $anchors,
		private ChangeRepository $changes,
		private GraphRepository $graph,
		private RuleRepository $rules,
		private RuleMatcher $matcher = new RuleMatcher()
	) {
	}

	/**
	 * Explicación de un motivo, en el idioma de la persona que usa el plugin.
	 *
	 * @return array<string, string> Código → frase.
	 */
	public static function reasons(): array {
		return array(
			self::NOT_PUBLISHED      => __( 'The entry is not published.', 'magic-linking' ),
			self::TYPE_NOT_ANALYZED  => __( 'This content type is not one that Magic Linking analyzes.', 'magic-linking' ),
			self::EXCLUDED_TYPE      => __( 'The rule excludes this content type.', 'magic-linking' ),
			self::EXCLUDED_CATEGORY  => __( 'The rule excludes a category of this entry.', 'magic-linking' ),
			self::OTHER_LANGUAGE     => __( 'The entry is in a different language than the destination.', 'magic-linking' ),
			self::BAD_TARGET         => __( 'The destination is not a published entry that can be linked.', 'magic-linking' ),
			self::ALREADY_APPLIED    => __( 'This rule was already applied to the entry (or undone there), so it is not applied again by itself.', 'magic-linking' ),
			self::IGNORED            => __( 'The entry or the destination is in your list of ignored URLs.', 'magic-linking' ),
			self::CREATOR_CANNOT     => __( 'The person who created the rule can no longer manage the site or edit this entry.', 'magic-linking' ),
			self::LOCKED             => __( 'Someone has the entry open in the editor.', 'magic-linking' ),
			RulePlan::SELF           => __( 'The entry is the destination itself.', 'magic-linking' ),
			RulePlan::DENSITY        => __( 'The entry already has as many internal links as its length allows.', 'magic-linking' ),
			RulePlan::TARGET_LINKED  => __( 'The entry already links to the destination as many times as allowed.', 'magic-linking' ),
			RulePlan::ANCHOR_OVERUSE => __( 'That anchor already points to the destination from as many entries as allowed.', 'magic-linking' ),
			RulePlan::NO_MATCH       => __( 'No phrase of the rule fits in the text.', 'magic-linking' ),
		);
	}

	/**
	 * Por qué una regla no se aplica a una entrada, sin mirar su texto; null si puede aplicarse.
	 *
	 * @param WP_Post $post Entrada.
	 * @param Rule    $rule Regla.
	 */
	public function why_not( WP_Post $post, Rule $rule ): ?string {
		if ( 'publish' !== $post->post_status ) {
			return self::NOT_PUBLISHED;
		}
		// «URLs ignoradas» (Ajustes) vale igual que en las sugerencias: ni como origen ni como destino.
		$ignored = $this->settings->all()['ignored_ids'];
		if ( in_array( $post->ID, $ignored, true ) || in_array( $rule->target_id, $ignored, true ) ) {
			return self::IGNORED;
		}
		if ( ! in_array( $post->post_type, $this->settings->post_types(), true ) ) {
			return self::TYPE_NOT_ANALYZED;
		}
		if ( in_array( $post->post_type, $rule->exclude_post_types, true ) ) {
			return self::EXCLUDED_TYPE;
		}
		if ( array() !== $rule->exclude_categories && has_term( $rule->exclude_categories, 'category', $post ) ) {
			return self::EXCLUDED_CATEGORY;
		}
		// Regla 9: nunca entre idiomas distintos.
		if ( $this->language->for_post( $post->ID ) !== $this->language->for_post( $rule->target_id ) ) {
			return self::OTHER_LANGUAGE;
		}

		return null;
	}

	/**
	 * Lo que una regla haría en una entrada tal como está guardada, sin escribir nada.
	 *
	 * @param WP_Post $post Entrada.
	 * @param Rule    $rule Regla.
	 */
	public function plan( WP_Post $post, Rule $rule ): RulePlan {
		$why = $this->why_not( $post, $rule );
		if ( null !== $why ) {
			return new RulePlan( array(), $why );
		}

		$document = $this->documents->from_post( $post );
		$analyzer = Analyzer::for_language( $document->lang );

		return $this->matcher->plan( $document, $rule->spec(), $this->settings->rule_limits(), $this->anchors->counter( $rule->target_id, $post->ID, $analyzer ), $this->settings->all()['ignored_words'] );
	}

	/**
	 * Vista previa de lo que una regla pondría en una entrada, para la pantalla.
	 *
	 * @param WP_Post $post Entrada.
	 * @param Rule    $rule Regla.
	 *
	 * @return array{post_id: int, title: string, skipped: string|null, links: list<array{anchor: string, before: string, after: string}>}
	 */
	public function preview( WP_Post $post, Rule $rule ): array {
		$plan  = $this->plan( $post, $rule );
		$links = array();
		foreach ( $plan->matches as $match ) {
			$before  = substr( $match->sentence, 0, $match->offset );
			$after   = substr( $match->sentence, $match->offset + strlen( $match->anchor ) );
			$links[] = array(
				'anchor' => $match->anchor,
				'before' => self::tail( $before, 70 ),
				'after'  => self::head( $after, 70 ),
			);
		}

		return array(
			'post_id' => $post->ID,
			'title'   => PostTitle::of( $post ),
			'skipped' => $plan->skipped,
			'links'   => $links,
		);
	}

	/**
	 * Aplica una regla a una entrada.
	 *
	 * @param WP_Post     $post     Entrada.
	 * @param Rule        $rule     Regla.
	 * @param int|null    $user_id  Quien lo pide (null = el usuario actual, con `edit_post`; 0 = el sistema, al guardar).
	 * @param string|null $batch_id Lote del Historial; null = uno nuevo.
	 * @param bool        $auto     Si es la aplicación al guardar: no repite una regla ya aplicada (o deshecha) en la entrada.
	 */
	public function apply( WP_Post $post, Rule $rule, ?int $user_id = null, ?string $batch_id = null, bool $auto = false ): RuleOutcome {
		$key = $rule->key_for( $post->ID );
		if ( $auto && in_array( $key, get_post_meta( $post->ID, self::APPLIED_META, false ), true ) ) {
			return new RuleOutcome( $post->ID, $rule->id, 0, array(), self::ALREADY_APPLIED );
		}

		$plan = $this->plan( $post, $rule );
		if ( array() === $plan->matches ) {
			return new RuleOutcome( $post->ID, $rule->id, 0, array(), $plan->skipped ?? RulePlan::NO_MATCH );
		}

		$url = $this->destination->resolve( $post->ID, $rule->target_id );
		if ( $url instanceof InsertionException ) {
			return new RuleOutcome( $post->ID, $rule->id, 0, array(), InsertionException::LANGUAGE === $url->reason() ? self::OTHER_LANGUAGE : self::BAD_TARGET );
		}

		$requests = array();
		foreach ( $plan->matches as $match ) {
			$requests[] = new InsertRequest(
				$post->ID,
				$url,
				$match->anchor,
				substr( $match->sentence, 0, $match->offset ),
				substr( $match->sentence, $match->offset + strlen( $match->anchor ) ),
				null,
				array(),
				$user_id,
				false,
				false,
				false,
				null,
				self::ORIGIN,
				$key
			);
		}

		$batch   = $batch_id ?? BatchId::generate();
		$results = $this->inserter->insert_many( $requests, $batch );
		$this->anchors->forget();

		$inserted = 0;
		$failed   = array();
		foreach ( $results as $result ) {
			if ( $result instanceof InsertResult ) {
				++$inserted;
			} else {
				$failed[] = array(
					'reason'  => $result->reason(),
					'message' => $result->getMessage(),
				);
			}
		}

		if ( $inserted > 0 ) {
			// Aunque el usuario lo deshaga, la regla no vuelve a aplicarse sola en esta entrada.
			add_post_meta( $post->ID, self::APPLIED_META, $key );
		}

		return new RuleOutcome( $post->ID, $rule->id, $inserted, $failed, null, $inserted > 0 ? $batch : '' );
	}

	/**
	 * Aplica todas las reglas activas a una entrada recién guardada, en un solo lote del Historial. Cada regla escribe
	 * **como quien la creó** (el Historial y la revisión llevan su nombre y `kses` filtra con sus capacidades), y solo si
	 * esa persona sigue gestionando el sitio y puede editar la entrada; si no, la regla se salta. Una regla sin autor
	 * (creada desde WP-CLI sin usuario) escribe como el sistema. Una entrada abierta en el editor no se toca.
	 *
	 * @param int $post_id Entrada.
	 *
	 * @return list<RuleOutcome>
	 */
	public function apply_saved( int $post_id ): array {
		$outcomes = array();
		$batch    = BatchId::generate();

		foreach ( $this->rules->active() as $rule ) {
			// Cada regla ve el contenido que dejó la anterior.
			clean_post_cache( $post_id );
			$post = get_post( $post_id );
			if ( ! $post instanceof WP_Post ) {
				break;
			}
			try {
				$outcome = $this->apply_as_creator( $post, $rule, $batch );
			} catch ( Throwable $e ) {
				PostWriter::log( sprintf( 'La regla %d falló en la entrada %d: %s', $rule->id, $post_id, $e->getMessage() ) );
				continue;
			}
			$outcomes[] = $outcome;
		}

		return $outcomes;
	}

	/**
	 * Aplica una regla como quien la creó, con la sesión de esa persona durante la escritura y restaurada después.
	 *
	 * @param WP_Post $post  Entrada.
	 * @param Rule    $rule  Regla.
	 * @param string  $batch Lote del Historial.
	 */
	private function apply_as_creator( WP_Post $post, Rule $rule, string $batch ): RuleOutcome {
		$creator = $rule->created_by;
		if ( $creator < 1 ) {
			return $this->apply( $post, $rule, 0, $batch, true );
		}
		if ( ! user_can( $creator, 'manage_options' ) || ! user_can( $creator, 'edit_post', $post->ID ) ) {
			return new RuleOutcome( $post->ID, $rule->id, 0, array(), self::CREATOR_CANNOT );
		}

		$previous = get_current_user_id();
		wp_set_current_user( $creator );
		try {
			return $this->apply( $post, $rule, $creator, $batch, true );
		} finally {
			wp_set_current_user( $previous );
		}
	}

	/**
	 * Busca las entradas donde la regla encaja, a partir de un cursor y dentro de un presupuesto de tiempo: la pantalla
	 * pide más hasta que `done` es verdadero, así que la búsqueda se reanuda sola y no hay proceso que quede a medias.
	 *
	 * @param Rule  $rule    Regla.
	 * @param int   $user_id Quien busca: solo se listan las entradas que puede editar (0 = todas).
	 * @param int   $after   Cursor: solo entradas con ID mayor.
	 * @param int   $want    Entradas que se quieren encontrar como máximo.
	 * @param float $budget  Segundos como máximo.
	 *
	 * @return array{items: list<array{post_id: int, title: string, skipped: string|null, links: list<array{anchor: string, before: string, after: string}>}>, cursor: int, done: bool, examined: int}
	 */
	public function scan( Rule $rule, int $user_id, int $after, int $want, float $budget ): array {
		$types    = array_values( array_diff( $this->settings->post_types(), $rule->exclude_post_types ) );
		$deadline = microtime( true ) + $budget;
		$items    = array();
		$examined = 0;
		$cursor   = $after;

		while ( true ) {
			$ids = $this->graph->eligible_ids( $types, $cursor, 50, $this->settings->all()['ignored_ids'] );
			if ( array() === $ids ) {
				return array(
					'items'    => $items,
					'cursor'   => $cursor,
					'done'     => true,
					'examined' => $examined,
				);
			}
			_prime_post_caches( $ids, true, true );

			foreach ( $ids as $id ) {
				$cursor = $id;
				if ( $user_id > 0 && ! user_can( $user_id, 'edit_post', $id ) ) {
					continue;
				}
				$post = get_post( $id );
				if ( ! $post instanceof WP_Post ) {
					continue;
				}
				++$examined;
				$preview = $this->preview( $post, $rule );
				if ( array() !== $preview['links'] ) {
					$items[] = $preview;
				}
				if ( count( $items ) >= $want || microtime( true ) > $deadline ) {
					return array(
						'items'    => $items,
						'cursor'   => $cursor,
						'done'     => false,
						'examined' => $examined,
					);
				}
			}//end foreach
		}//end while
	}

	/**
	 * Últimos caracteres de un texto.
	 *
	 * @param string $text  Texto.
	 * @param int    $chars Caracteres.
	 */
	private static function tail( string $text, int $chars ): string {
		$text = trim( $text );

		return mb_strlen( $text, 'UTF-8' ) > $chars ? '…' . mb_substr( $text, -$chars, null, 'UTF-8' ) : $text;
	}

	/**
	 * Primeros caracteres de un texto.
	 *
	 * @param string $text  Texto.
	 * @param int    $chars Caracteres.
	 */
	private static function head( string $text, int $chars ): string {
		$text = trim( $text );

		return mb_strlen( $text, 'UTF-8' ) > $chars ? mb_substr( $text, 0, $chars, 'UTF-8' ) . '…' : $text;
	}
}

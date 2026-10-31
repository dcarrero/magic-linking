<?php
/**
 * Ruta REST de la sección «Frases objetivo» del panel del editor.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Rest;

use MagicLinking\Core\Module;
use MagicLinking\Core\Settings;
use MagicLinking\Engine\Analyzer;
use MagicLinking\I18n\Language;
use MagicLinking\Index\TargetPhrases;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `GET|POST /target-phrases/{post_id}` (docs/07 §3.4, docs/04 §4.2).
 *
 * Permisos: `edit_post` sobre la entrada, que además tiene que ser de un tipo que se analiza. Guardar es inmediato
 * (no espera a guardar la entrada): la meta cambia y {@see \MagicLinking\Jobs\Jobs} reindexa la entrada y renueva
 * las sugerencias guardadas. El POST sustituye la lista entera y devuelve la lista limpia tal como quedó.
 */
final class TargetPhrasesController implements Module {

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Ajustes (tipos que se analizan).
	 * @param Language $language Idioma de la entrada (cuenta las palabras como el motor).
	 */
	public function __construct( private Settings $settings, private Language $language ) {
	}

	/**
	 * Engancha las rutas.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Registra las rutas.
	 */
	public function routes(): void {
		register_rest_route(
			ReportController::NAMESPACE,
			'/target-phrases/(?P<post_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_phrases' ),
					'permission_callback' => array( AnalyzedEntry::class, 'can_edit' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'set_phrases' ),
					'permission_callback' => array( AnalyzedEntry::class, 'can_edit' ),
					'args'                => array(
						'phrases' => array(
							'type'     => 'array',
							'required' => true,
						),
					),
				),
			)
		);
	}

	/**
	 * Comprueba la lista ya limpia (sin vacíos ni repetidos): máximos de frases, variantes, caracteres y palabras. Se
	 * llama desde la ruta y no desde el argumento porque el núcleo sustituye el mensaje de un `validate_callback` por
	 * «Invalid parameter(s)» y aquí el mensaje sale al usuario. Las palabras se cuentan como las cuenta el motor, en el
	 * idioma de la entrada.
	 *
	 * @param mixed $phrases Lista enviada.
	 * @param int   $post_id Entrada.
	 *
	 * @return true|WP_Error
	 */
	public function validate( mixed $phrases, int $post_id ) {
		if ( ! is_array( $phrases ) ) {
			return new WP_Error( 'rest_invalid_param', __( 'The phrases must be a list.', 'magic-linking' ), array( 'status' => 400 ) );
		}

		$list = TargetPhrases::clean( $phrases, false )['phrases'];
		if ( count( $list ) > TargetPhrases::MAX_PHRASES ) {
			/* translators: %d: maximum number of phrases. */
			return new WP_Error( 'rest_invalid_param', sprintf( _n( 'You can add up to %d phrase.', 'You can add up to %d phrases.', TargetPhrases::MAX_PHRASES, 'magic-linking' ), TargetPhrases::MAX_PHRASES ), array( 'status' => 400 ) );
		}

		$analyzer = Analyzer::for_language( $this->language->for_post( $post_id ) );
		foreach ( $list as $entry ) {
			if ( count( $entry['variants'] ) > TargetPhrases::MAX_VARIANTS ) {
				/* translators: %d: maximum number of variants per phrase. */
				return new WP_Error( 'rest_invalid_param', sprintf( _n( 'Each phrase can have up to %d variant.', 'Each phrase can have up to %d variants.', TargetPhrases::MAX_VARIANTS, 'magic-linking' ), TargetPhrases::MAX_VARIANTS ), array( 'status' => 400 ) );
			}
			foreach ( array_merge( array( $entry['phrase'] ), $entry['variants'] ) as $text ) {
				if ( mb_strlen( $text ) > TargetPhrases::MAX_CHARS ) {
					/* translators: 1: the start of the phrase, 2: maximum number of characters. */
					return new WP_Error( 'rest_invalid_param', sprintf( __( '“%1$s…” is too long: a phrase can have up to %2$d characters.', 'magic-linking' ), mb_substr( $text, 0, 40 ), TargetPhrases::MAX_CHARS ), array( 'status' => 400 ) );
				}
				if ( TargetPhrases::words( $text, $analyzer ) > TargetPhrases::MAX_WORDS ) {
					/* translators: 1: the phrase, 2: maximum number of words. */
					return new WP_Error( 'rest_invalid_param', sprintf( __( '“%1$s” is too long: a phrase can have up to %2$d words.', 'magic-linking' ), $text, TargetPhrases::MAX_WORDS ), array( 'status' => 400 ) );
				}
			}
		}

		return true;
	}

	/**
	 * GET: las frases guardadas.
	 *
	 * @param WP_REST_Request $request Petición.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_phrases( WP_REST_Request $request ) {
		$post = AnalyzedEntry::find( $this->settings, (int) $request['post_id'] );

		return $post instanceof WP_Error ? $post : new WP_REST_Response( $this->state( $post->ID, TargetPhrases::get( $post->ID ), array() ) );
	}

	/**
	 * POST: sustituye la lista.
	 *
	 * @param WP_REST_Request $request Petición.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function set_phrases( WP_REST_Request $request ) {
		$post = AnalyzedEntry::find( $this->settings, (int) $request['post_id'] );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$valid = $this->validate( $request['phrases'], $post->ID );
		if ( $valid instanceof WP_Error ) {
			return $valid;
		}

		$saved = TargetPhrases::set( $post->ID, (array) $request['phrases'] );

		return new WP_REST_Response( $this->state( $post->ID, $saved['phrases'], $saved['removed'] ) );
	}

	/**
	 * Respuesta: la lista y los máximos (los fija el servidor).
	 *
	 * @param int   $post_id ID.
	 * @param array $phrases Lista limpia.
	 * @param array $removed Textos que se han quitado por repetidos.
	 *
	 * @phpstan-param list<array{phrase: string, variants: list<string>, gap: bool}> $phrases
	 * @phpstan-param list<string> $removed
	 * @return array<string, mixed>
	 */
	private function state( int $post_id, array $phrases, array $removed ): array {
		return array(
			'post_id' => $post_id,
			'phrases' => $phrases,
			'removed' => $removed,
			'limits'  => array(
				'phrases'  => TargetPhrases::MAX_PHRASES,
				'variants' => TargetPhrases::MAX_VARIANTS,
				'words'    => TargetPhrases::MAX_WORDS,
				'chars'    => TargetPhrases::MAX_CHARS,
			),
		);
	}
}

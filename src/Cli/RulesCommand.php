<?php
/**
 * Órdenes de WP-CLI de las reglas automáticas.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Cli;

use MagicLinking\Rules\Rule;
use MagicLinking\Engine\RulePlan;
use MagicLinking\Rules\RuleRepository;
use MagicLinking\Rules\RuleRunner;
use MagicLinking\Rules\RuleService;
use MagicLinking\Rules\RuleValidator;
use WP_CLI;
use WP_Error;
use WP_Post;

/**
 * Reglas automáticas de enlazado (`wp magic-linking rules …`). Son el equivalente de la pantalla *Reglas*: crear,
 * listar y borrar reglas, buscar las entradas donde encajan y aplicarlas **de una en una**, con la misma inserción
 * segura, el mismo Historial y el mismo deshacer. Aplicar una regla a todas las existentes de una vez es de Pro.
 */
final class RulesCommand {

	/**
	 * Constructor.
	 *
	 * @param RuleRepository $rules     Reglas.
	 * @param RuleValidator  $validator Validación.
	 * @param RuleService    $service   Aplicación.
	 */
	public function __construct( private RuleRepository $rules, private RuleValidator $validator, private RuleService $service ) {
	}

	/**
	 * Lista las reglas.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Formato de salida.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp magic-linking rules list
	 *
	 * @subcommand list
	 *
	 * @param array<int, string>   $args       Argumentos.
	 * @param array<string, mixed> $assoc_args Opciones.
	 */
	public function list_rules( array $args, array $assoc_args ): void {
		unset( $args );

		$format = (string) ( $assoc_args['format'] ?? 'table' );
		$rows   = array();
		$page   = 1;
		$total  = 0;
		$count  = 0;
		do {
			$found = $this->rules->page( $page, 100 );
			$total = $found['total'];
			$count = count( $rows ) + count( $found['items'] );
			foreach ( $found['items'] as $rule ) {
				$rows[] = array(
					'id'           => $rule->id,
					'phrases'      => implode( ' | ', $rule->variants ),
					'target_id'    => $rule->target_id,
					'max_per_post' => $rule->max_per_post,
					'first_only'   => $rule->first_only ? 'yes' : 'no',
					'word_gap'     => $rule->gap ? 'yes' : 'no',
					'active'       => $rule->active ? 'yes' : 'no',
				);
			}
			++$page;
		} while ( $count < $total && array() !== $found['items'] );

		if ( 'count' === $format ) {
			WP_CLI::line( (string) count( $rows ) );
			return;
		}

		\WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'phrases', 'target_id', 'max_per_post', 'first_only', 'word_gap', 'active' ) );
	}

	/**
	 * Crea una regla.
	 *
	 * ## OPTIONS
	 *
	 * --phrases=<phrases>
	 * : Frase y variantes, separadas por «|».
	 *
	 * --target=<id>
	 * : ID de la entrada de destino (publicada).
	 *
	 * [--max-per-post=<n>]
	 * : Enlaces como máximo por entrada. Por defecto 1 (lo limita el ajuste «Enlaces al mismo destino desde una entrada»).
	 *
	 * [--first-only]
	 * : Solo la primera aparición de cada frase (por defecto). Con `--no-first-only` se enlazan todas las apariciones hasta el máximo.
	 *
	 * [--word-gap]
	 * : Admite una palabra más entre dos de la frase.
	 *
	 * [--inactive]
	 * : La crea sin activar.
	 *
	 * El autor de la regla es el usuario de WP-CLI (`--user=<id>`); sin él, la regla se aplica como el sistema.
	 *
	 * ## EXAMPLES
	 *
	 *     wp magic-linking rules add --phrases="bomba de calor|aerotermia" --target=42
	 *
	 * @param array<int, string>   $args       Argumentos.
	 * @param array<string, mixed> $assoc_args Opciones.
	 */
	public function add( array $args, array $assoc_args ): void {
		unset( $args );

		$input = array(
			'phrases'    => explode( '|', (string) ( $assoc_args['phrases'] ?? '' ) ),
			'target_id'  => absint( $assoc_args['target'] ?? 0 ),
			'active'     => empty( $assoc_args['inactive'] ),
			'first_only' => ! isset( $assoc_args['first-only'] ) || filter_var( $assoc_args['first-only'], FILTER_VALIDATE_BOOLEAN ),
			'word_gap'   => ! empty( $assoc_args['word-gap'] ),
		);
		if ( isset( $assoc_args['max-per-post'] ) ) {
			$input['max_per_post'] = (int) $assoc_args['max-per-post'];
		}

		$rule = $this->validator->validate( $input, null, get_current_user_id() );
		if ( $rule instanceof WP_Error ) {
			WP_CLI::error( $rule->get_error_message() );
			return;
		}
		$id = $this->rules->insert( $rule );
		if ( $id < 1 ) {
			WP_CLI::error( __( 'The rule could not be saved.', 'magic-linking' ) );
			return;
		}
		WP_CLI::success( sprintf( /* translators: %d: rule ID. */ __( 'Rule %d created.', 'magic-linking' ), $id ) );
	}

	/**
	 * Borra una regla. Los enlaces que ya puso se quedan; se deshacen desde el Historial.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : ID de la regla.
	 *
	 * @param array<int, string>   $args       Argumentos.
	 * @param array<string, mixed> $assoc_args Opciones.
	 */
	public function delete( array $args, array $assoc_args ): void {
		unset( $assoc_args );

		$id = absint( $args[0] ?? 0 );
		if ( null === $this->rules->find( $id ) || ! $this->rules->delete( $id ) ) {
			WP_CLI::error( sprintf( /* translators: %d: rule ID. */ __( 'Rule %d does not exist.', 'magic-linking' ), $id ) );
			return;
		}
		WP_CLI::success( sprintf( /* translators: %d: rule ID. */ __( 'Rule %d deleted.', 'magic-linking' ), $id ) );
	}

	/**
	 * Busca las entradas donde encaja una regla y muestra qué enlace pondría en cada una. No escribe nada.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : ID de la regla.
	 *
	 * [--limit=<n>]
	 * : Entradas como máximo. Por defecto 50.
	 *
	 * [--after=<id>]
	 * : Sigue la búsqueda después de esta entrada (el cursor que se imprime al final).
	 *
	 * [--full]
	 * : Recorre todas las entradas, como «Search every entry» de la pantalla. Sin esto se usa el índice, que es más rápido pero puede dejarse alguna.
	 *
	 * @param array<int, string>   $args       Argumentos.
	 * @param array<string, mixed> $assoc_args Opciones.
	 */
	public function scan( array $args, array $assoc_args ): void {
		$rule = $this->rules->find( absint( $args[0] ?? 0 ) );
		if ( null === $rule ) {
			WP_CLI::error( __( 'That rule does not exist.', 'magic-linking' ) );
			return;
		}

		$limit  = max( 1, (int) ( $assoc_args['limit'] ?? 50 ) );
		$cursor = absint( $assoc_args['after'] ?? 0 );
		$full   = ! empty( $assoc_args['full'] );
		$shown  = 0;
		do {
			$found  = $this->service->scan( $rule, 0, $cursor, $limit - $shown, 20.0, $full );
			$cursor = $found['cursor'];
			foreach ( $found['items'] as $item ) {
				++$shown;
				foreach ( $item['links'] as $link ) {
					WP_CLI::line( sprintf( '%d %s: …%s[%s]%s…', $item['post_id'], $item['title'], $link['before'], $link['anchor'], $link['after'] ) );
				}
			}
		} while ( ! $found['done'] && $shown < $limit );

		if ( $found['done'] ) {
			if ( 0 === $shown && ! $full ) {
				// El índice guarda los términos de más peso de cada entrada, no todas sus palabras: puede dejarse alguna.
				WP_CLI::warning( __( 'No entry found using the index, which can miss some matches. Run it again with --full to check every entry.', 'magic-linking' ) );
			}
			WP_CLI::success( sprintf( /* translators: %d: number of entries. */ _n( '%d entry where the rule fits.', '%d entries where the rule fits.', $shown, 'magic-linking' ), $shown ) );
			return;
		}
		WP_CLI::success( sprintf( /* translators: 1: number of entries shown, 2: entry ID to continue after. */ __( '%1$d entries shown. Continue with --after=%2$d', 'magic-linking' ), $shown, $cursor ) );
	}

	/**
	 * Aplica una regla a UNA entrada (la misma inserción segura, con Historial y deshacer). Sin --yes solo muestra la vista previa.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : ID de la regla.
	 *
	 * --post=<id>
	 * : Entrada.
	 *
	 * [--yes]
	 * : Confirma que se añadan los enlaces de la vista previa.
	 *
	 * @param array<int, string>   $args       Argumentos.
	 * @param array<string, mixed> $assoc_args Opciones.
	 */
	public function apply( array $args, array $assoc_args ): void {
		$rule = $this->rules->find( absint( $args[0] ?? 0 ) );
		$post = get_post( absint( $assoc_args['post'] ?? 0 ) );
		if ( null === $rule || ! $post instanceof WP_Post ) {
			WP_CLI::error( __( 'That rule or entry does not exist.', 'magic-linking' ) );
			return;
		}

		$preview = $this->service->preview( $post, $rule );
		if ( array() === $preview['links'] ) {
			WP_CLI::warning( RuleService::reasons()[ $preview['skipped'] ?? '' ] ?? __( 'Nothing to link.', 'magic-linking' ) );
			return;
		}
		foreach ( $preview['links'] as $link ) {
			WP_CLI::line( sprintf( '…%s[%s]%s…', $link['before'], $link['anchor'], $link['after'] ) );
		}
		if ( empty( $assoc_args['yes'] ) ) {
			WP_CLI::warning( __( 'Nothing was written. Add --yes to add these links.', 'magic-linking' ) );
			return;
		}

		$outcome = $this->service->apply( $post, $rule, get_current_user_id() );
		foreach ( $outcome->failed as $failure ) {
			WP_CLI::warning( $failure['message'] );
		}
		if ( $outcome->inserted < 1 ) {
			// Nada que deshacer: ni éxito ni orden con un lote vacío.
			if ( array() === $outcome->failed ) {
				WP_CLI::warning( RuleService::reasons()[ $outcome->skipped ?? '' ] ?? __( 'Nothing was added.', 'magic-linking' ) );
			}
			return;
		}
		WP_CLI::success( sprintf( /* translators: 1: number of links added, 2: history batch ID. */ _n( '%1$d link added (batch %2$s). Undo with: wp magic-linking history undo %2$s', '%1$d links added (batch %2$s). Undo with: wp magic-linking history undo %2$s', $outcome->inserted, 'magic-linking' ), $outcome->inserted, $outcome->batch_id ) );
	}

	/**
	 * Aplica ahora todas las reglas activas a una entrada, como se hace al guardarla.
	 *
	 * ## OPTIONS
	 *
	 * --post=<id>
	 * : Entrada.
	 *
	 * @param array<int, string>   $args       Argumentos.
	 * @param array<string, mixed> $assoc_args Opciones.
	 */
	public function run( array $args, array $assoc_args ): void {
		unset( $args );

		$post_id = absint( $assoc_args['post'] ?? 0 );
		if ( ! get_post( $post_id ) instanceof WP_Post ) {
			WP_CLI::error( __( 'That entry does not exist.', 'magic-linking' ) );
			return;
		}
		$added   = 0;
		$blocked = false;
		$reasons = RuleService::reasons();
		foreach ( $this->service->apply_saved( $post_id ) as $outcome ) {
			$added += $outcome->inserted;
			foreach ( $outcome->failed as $failure ) {
				$blocked = true;
				WP_CLI::warning( sprintf( /* translators: 1: rule ID, 2: reason. */ __( 'Rule %1$d: %2$s', 'magic-linking' ), $outcome->rule_id, $failure['message'] ) );
			}
			if ( null !== $outcome->skipped && ! in_array( $outcome->skipped, array( RulePlan::NO_MATCH, RuleService::ALREADY_APPLIED ), true ) ) {
				$blocked = $blocked || in_array( $outcome->skipped, array( RuleService::CREATOR_CANNOT, RuleService::LOCKED ), true );
				WP_CLI::warning( sprintf( /* translators: 1: rule ID, 2: reason. */ __( 'Rule %1$d: %2$s', 'magic-linking' ), $outcome->rule_id, $reasons[ $outcome->skipped ] ?? $outcome->skipped ) );
			}
		}
		if ( 0 === $added && $blocked ) {
			WP_CLI::warning( __( 'Nothing was added.', 'magic-linking' ) );
			return;
		}
		WP_CLI::success( sprintf( /* translators: %d: number of links added. */ _n( '%d link added.', '%d links added.', $added, 'magic-linking' ), $added ) );
	}
}

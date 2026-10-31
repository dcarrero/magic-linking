<?php
/**
 * Registro de avisos del plugin (docs/07 §5).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Admin;

use MagicLinking\Core\Schema;
use MagicLinking\History\ChangeRepository;
use wpdb;

/**
 * Qué aviso toca enseñar, si toca alguno.
 *
 * Reglas (regla 7 de CLAUDE.md): solo dentro de las pantallas del plugin (nada de `admin_notices`), como máximo uno a
 * la vez (el de más prioridad), todos se cierran para siempre y por usuario en un solo metadato con los
 * identificadores cerrados, y ninguno es comercial ni habla de límites (docs/02 §3.3 y §5).
 *
 * Cada definición: `id`, `priority` (menor = antes), `type` (info, warning o error), `applies` (booleano o función
 * que lo calcula; solo se llama si el aviso no está cerrado), `screens` (opcional: pantallas donde puede salir),
 * `on_shown` (opcional: se llama al elegirlo), y para pintarlo `text`, `action` (opcional, con `label`
 * y `url`) y `dismiss_label`.
 *
 * @phpstan-type Definition array{id: string, priority: int, type: string, applies: bool|callable(): bool, screens?: list<string>, on_shown?: callable(): void, text?: string, action?: array{label: string, url: string}, dismiss_label?: string}
 */
final class Notices {

	/**
	 * Metadato de usuario con la lista de identificadores cerrados (sin el prefijo del sitio).
	 */
	public const META = 'magiclinking_dismissed_notices';

	/**
	 * Opción (sin autoload) con la fecha de activación, en segundos Unix.
	 */
	public const ACTIVATED_OPTION = 'magiclinking_activated_at';

	/**
	 * Metadato de usuario con lo que el sistema recuerda por usuario: cuándo se enseñó cada aviso por primera vez y el
	 * último recuento de enlaces (sin el prefijo del sitio).
	 */
	public const STATE_META = 'magiclinking_notices_state';

	/**
	 * Metadato antiguo del aviso «Sin conector de IA» (antes de F1-17); se migra al de cerrados y se borra.
	 */
	public const LEGACY_AI_META = 'magiclinking_ai_notice_dismissed';

	/**
	 * Días que se sigue enseñando la reseña desde la primera vez (si no se cierra): después no vuelve nunca.
	 */
	public const REVIEW_SHOWN_DAYS = 7;

	/**
	 * Enlaces insertados que hacen falta para pedir una reseña.
	 */
	public const REVIEW_LINKS = 20;

	/**
	 * Días desde la activación antes de pedir una reseña.
	 */
	public const REVIEW_DAYS = 30;

	/**
	 * Página de reseñas del plugin en wordpress.org.
	 */
	public const REVIEW_URL = 'https://wordpress.org/support/plugin/magic-linking/reviews/#new-post';

	/**
	 * Definiciones; null = las del plugin.
	 *
	 * @var list<Definition>|null
	 */
	private ?array $definitions;

	/**
	 * Estado del conector de IA (`unavailable`, `connected` o `none`); null = sin aviso de IA.
	 *
	 * @var (\Closure(): string)|null
	 */
	private ?\Closure $ai_state;

	/**
	 * Constructor.
	 *
	 * @param wpdb                      $wpdb        Base de datos.
	 * @param list<Definition>|null     $definitions Definiciones propias (pruebas); por defecto las del plugin.
	 * @param (\Closure(): string)|null $ai_state Estado del conector de IA, para el aviso «Sin conector de IA».
	 */
	public function __construct( private wpdb $wpdb, ?array $definitions = null, ?\Closure $ai_state = null ) {
		$this->definitions = $definitions;
		$this->ai_state    = $ai_state;
	}

	/**
	 * Clave del metadato de cerrados en este sitio (con el prefijo de las tablas: en una red cada sitio cierra los suyos).
	 */
	public function meta_key(): string {
		return $this->wpdb->get_blog_prefix() . self::META;
	}

	/**
	 * Clave del metadato de estado en este sitio.
	 */
	public function state_key(): string {
		return $this->wpdb->get_blog_prefix() . self::STATE_META;
	}

	/**
	 * Guarda la fecha de activación si aún no existe, sin autoload.
	 */
	public static function remember_activation(): void {
		add_option( self::ACTIVATED_OPTION, time(), '', false );
	}

	/**
	 * Elige el aviso que se ve: el de más prioridad que no esté cerrado y cuya condición se cumpla.
	 *
	 * @param array $definitions Definiciones.
	 * @param array $dismissed   Identificadores cerrados.
	 *
	 * @phpstan-param list<Definition> $definitions
	 * @phpstan-param list<string>     $dismissed
	 * @phpstan-return Definition|null
	 *
	 * @return array|null
	 */
	public static function select( array $definitions, array $dismissed ): ?array {
		usort( $definitions, static fn( array $a, array $b ): int => $a['priority'] <=> $b['priority'] );

		foreach ( $definitions as $definition ) {
			if ( in_array( $definition['id'], $dismissed, true ) ) {
				continue;
			}
			$applies = $definition['applies'];
			if ( is_callable( $applies ) ? (bool) $applies() : (bool) $applies ) {
				return $definition;
			}
		}

		return null;
	}

	/**
	 * Aviso que toca enseñar a un usuario en una pantalla, listo para pintar, o null.
	 *
	 * @param int    $user_id Usuario.
	 * @param string $screen  Pantalla del plugin (report, broken, history, rules, status o settings).
	 *
	 * @return array{id: string, type: string, text: string, action: array{label: string, url: string}|null, dismiss_label: string}|null
	 */
	public function current( int $user_id, string $screen = '' ): ?array {
		$definitions = array_values(
			array_filter(
				$this->definitions( $user_id ),
				static fn( array $d ): bool => ! isset( $d['screens'] ) || in_array( $screen, $d['screens'], true )
			)
		);
		$definition  = self::select( $definitions, $this->dismissed( $user_id ) );
		if ( null === $definition ) {
			return null;
		}
		if ( isset( $definition['on_shown'] ) ) {
			( $definition['on_shown'] )();
		}

		return array(
			'id'            => $definition['id'],
			'type'          => $definition['type'],
			'text'          => $definition['text'] ?? '',
			'action'        => $definition['action'] ?? null,
			'dismiss_label' => $definition['dismiss_label'] ?? __( 'Do not show again', 'magic-linking' ),
		);
	}

	/**
	 * Cierra un aviso para siempre para ese usuario. Devuelve false si el identificador no existe.
	 *
	 * @param int    $user_id Usuario.
	 * @param string $id      Identificador del aviso.
	 */
	public function dismiss( int $user_id, string $id ): bool {
		if ( ! in_array( $id, array_column( $this->definitions( $user_id ), 'id' ), true ) ) {
			return false;
		}

		$dismissed = $this->dismissed( $user_id );
		if ( ! in_array( $id, $dismissed, true ) ) {
			$dismissed[] = $id;
			update_user_meta( $user_id, $this->meta_key(), $dismissed );
		}

		return true;
	}

	/**
	 * Identificadores cerrados por un usuario. Si queda el metadato antiguo del aviso de IA, lo pasa a la lista y lo borra.
	 *
	 * @param int $user_id Usuario.
	 *
	 * @return list<string>
	 */
	private function dismissed( int $user_id ): array {
		$value     = get_user_meta( $user_id, $this->meta_key(), true );
		$dismissed = is_array( $value ) ? array_values( array_filter( $value, 'is_string' ) ) : array();

		$legacy_key = $this->wpdb->get_blog_prefix() . self::LEGACY_AI_META;
		if ( '' !== get_user_meta( $user_id, $legacy_key, true ) ) {
			if ( ! in_array( 'ai-connector', $dismissed, true ) ) {
				$dismissed[] = 'ai-connector';
				update_user_meta( $user_id, $this->meta_key(), $dismissed );
			}
			delete_user_meta( $user_id, $legacy_key );
		}

		return $dismissed;
	}

	/**
	 * Lo que se recuerda del usuario (primera vez que se enseñó cada aviso y último recuento).
	 *
	 * @param int $user_id Usuario.
	 *
	 * @return array{seen: array<string, int>, links?: array{count: int, at: int}}
	 */
	private function state( int $user_id ): array {
		$value = get_user_meta( $user_id, $this->state_key(), true );
		$seen  = is_array( $value ) && isset( $value['seen'] ) && is_array( $value['seen'] ) ? array_map( 'intval', $value['seen'] ) : array();
		$state = array( 'seen' => $seen );
		if ( is_array( $value ) && isset( $value['links']['count'], $value['links']['at'] ) ) {
			$state['links'] = array(
				'count' => (int) $value['links']['count'],
				'at'    => (int) $value['links']['at'],
			);
		}

		return $state;
	}

	/**
	 * Definiciones vigentes.
	 *
	 * @param int $user_id Usuario para el que se evalúan.
	 *
	 * @return list<Definition>
	 */
	private function definitions( int $user_id ): array {
		if ( null !== $this->definitions ) {
			return $this->definitions;
		}

		$definitions = array(
			array(
				'id'            => 'review',
				'priority'      => 100,
				'type'          => 'info',
				'applies'       => fn(): bool => $this->review_due( $user_id ),
				'on_shown'      => function () use ( $user_id ): void {
					$this->mark_seen( $user_id, 'review' );
				},
				'text'          => __( 'You have added a good number of links with Magic Linking. If it is saving you time, a review on wordpress.org helps other people find it.', 'magic-linking' ),
				'action'        => array(
					'label' => __( 'Write a review', 'magic-linking' ),
					'url'   => self::REVIEW_URL,
				),
				'dismiss_label' => __( 'No, thanks', 'magic-linking' ),
			),
		);

		if ( null !== $this->ai_state ) {
			$state         = $this->ai_state;
			$definitions[] = array(
				'id'       => 'ai-connector',
				'priority' => 50,
				'type'     => 'info',
				// Solo en el Informe y para quien administra: el estado sale del conector guardado, sin llamar a ningún modelo.
				'screens'  => array( 'report' ),
				'applies'  => static fn(): bool => current_user_can( 'manage_options' ) && 'none' === $state(),
				'text'     => __( 'This site has no AI connected. You can connect yours in Settings → Connectors (OpenAI, Google, Ollama local…). Meanwhile, suggestions use the lexical engine.', 'magic-linking' ),
				'action'   => array(
					'label' => __( 'Go to Connectors', 'magic-linking' ),
					'url'   => admin_url( 'options-connectors.php' ),
				),
			);
		}

		return $definitions;
	}

	/**
	 * Apunta la primera vez que se enseñó un aviso.
	 *
	 * @param int    $user_id Usuario.
	 * @param string $id      Aviso.
	 */
	private function mark_seen( int $user_id, string $id ): void {
		$state = $this->state( $user_id );
		if ( isset( $state['seen'][ $id ] ) ) {
			return;
		}
		$state['seen'][ $id ] = time();
		update_user_meta( $user_id, $this->state_key(), $state );
	}

	/**
	 * ¿Toca la reseña? Una vez que se enseñó, solo se sigue enseñando durante una semana; después no vuelve nunca.
	 * Antes de la primera vez: lo barato primero (los días desde la activación) y después el recuento.
	 *
	 * @param int $user_id Usuario.
	 */
	private function review_due( int $user_id ): bool {
		$seen = $this->state( $user_id )['seen']['review'] ?? 0;
		if ( $seen > 0 ) {
			return time() - $seen < self::REVIEW_SHOWN_DAYS * DAY_IN_SECONDS;
		}

		$activated = (int) get_option( self::ACTIVATED_OPTION, 0 );
		if ( $activated <= 0 ) {
			// Sitio que ya tenía el plugin antes de este aviso: los 30 días cuentan desde esta primera carga.
			self::remember_activation();

			return false;
		}
		if ( time() - $activated < self::REVIEW_DAYS * DAY_IN_SECONDS ) {
			return false;
		}

		return $this->inserted_links( $user_id ) >= self::REVIEW_LINKS;
	}

	/**
	 * Enlaces que el usuario ha insertado y siguen puestos (no los de las reglas automáticas), contados solo hasta el
	 * umbral. La consulta usa el índice `user_action` y se detiene al llegar a 20; el resultado se recuerda un día en el
	 * metadato de estado del usuario para no contar en cada pantalla.
	 *
	 * @param int $user_id Usuario.
	 */
	private function inserted_links( int $user_id ): int {
		$state = $this->state( $user_id );
		if ( isset( $state['links'] ) && time() - $state['links']['at'] < DAY_IN_SECONDS ) {
			return $state['links']['count'];
		}

		$table = Schema::table( $this->wpdb->prefix, 'changes' );
		$query = $this->wpdb->prepare(
			'SELECT COUNT(*) FROM ( SELECT 1 FROM %i WHERE user_id = %d AND action = %s AND origin IN ( %s, %s ) AND undone_at IS NULL LIMIT %d ) AS c',
			$table,
			$user_id,
			ChangeRepository::INSERT,
			ChangeRepository::ORIGIN_PLUGIN,
			ChangeRepository::ORIGIN_EDITOR,
			self::REVIEW_LINKS
		);
		$count = (int) $this->wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Preparada arriba y recordada en el metadato.

		$state['links'] = array(
			'count' => $count,
			'at'    => time(),
		);
		update_user_meta( $user_id, $this->state_key(), $state );

		return $count;
	}
}

<?php
/**
 * Tabla de reglas automáticas.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Rules;

use MagicLinking\Core\Schema;
use wpdb;

/**
 * Lee y escribe `magiclinking_rules`. Sin límite de filas: ninguna función de aquí cuenta reglas para negarse a
 * guardar una más (docs/02 §3.2, regla 6).
 */
final class RuleRepository {

	/**
	 * Tabla.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Si hay alguna regla activa, ya consultado en esta petición.
	 *
	 * @var bool|null
	 */
	private ?bool $any_active = null;

	/**
	 * Reglas activas ya leídas en esta petición.
	 *
	 * @var list<Rule>|null
	 */
	private ?array $active = null;

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb Conexión.
	 */
	public function __construct( private wpdb $wpdb ) {
		$this->table = Schema::table( $wpdb->prefix, 'rules' );
	}

	/**
	 * Una regla.
	 *
	 * @param int $id ID.
	 */
	public function find( int $id ): ?Rule {
		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table, $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Tabla propia.

		return is_array( $row ) ? Rule::from_row( $row ) : null;
	}

	/**
	 * Una página de reglas, las más recientes primero.
	 *
	 * @param int $page     Página (desde 1).
	 * @param int $per_page Reglas por página.
	 *
	 * @return array{items: list<Rule>, total: int}
	 */
	public function page( int $page, int $per_page ): array {
		$wpdb   = $this->wpdb;
		$offset = max( 0, ( $page - 1 ) * $per_page );
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d OFFSET %d', $this->table, $per_page, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Tabla propia.
		$total  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Tabla propia.

		return array(
			'items' => array_map( static fn( array $row ): Rule => Rule::from_row( $row ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Si hay alguna regla activa (una sola consulta por petición).
	 */
	public function has_active(): bool {
		if ( null === $this->any_active ) {
			$wpdb             = $this->wpdb;
			$this->any_active = null !== $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE active = 1 LIMIT 1', $this->table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Tabla propia.
		}

		return $this->any_active;
	}

	/**
	 * Todas las reglas activas, de la más antigua a la más reciente.
	 *
	 * @return list<Rule>
	 */
	public function active(): array {
		if ( null === $this->active ) {
			$wpdb         = $this->wpdb;
			$rows         = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE active = 1 ORDER BY id ASC', $this->table ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Tabla propia.
			$this->active = array_map( static fn( array $row ): Rule => Rule::from_row( $row ), (array) $rows );
		}

		return $this->active;
	}

	/**
	 * Guarda una regla nueva.
	 *
	 * @param Rule $rule Regla (su ID se ignora).
	 *
	 * @return int ID; 0 si la base de datos no la guardó.
	 */
	public function insert( Rule $rule ): int {
		$wpdb = $this->wpdb;
		$done = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Tabla propia.
			$this->table,
			$this->columns( $rule ) + array(
				'created_at' => current_time( 'mysql', true ),
				'created_by' => $rule->created_by,
			),
			array( '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%d' )
		);
		$this->forget();

		return false === $done ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Actualiza una regla.
	 *
	 * @param Rule $rule Regla con su ID.
	 */
	public function update( Rule $rule ): bool {
		$wpdb = $this->wpdb;
		$done = $wpdb->update( $this->table, $this->columns( $rule ), array( 'id' => $rule->id ), array( '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Tabla propia.
		$this->forget();

		return false !== $done;
	}

	/**
	 * Borra una regla. Los enlaces que ya puso se quedan en el contenido (y en el Historial, con su deshacer).
	 *
	 * @param int $id ID.
	 */
	public function delete( int $id ): bool {
		$wpdb = $this->wpdb;
		$done = $wpdb->delete( $this->table, array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Tabla propia.
		$this->forget();

		return is_int( $done ) && $done > 0;
	}

	/**
	 * Olvida lo leído en esta petición (las pruebas, que vacían la tabla por fuera, lo necesitan).
	 */
	public function forget(): void {
		$this->any_active = null;
		$this->active     = null;
	}

	/**
	 * Columnas de la regla.
	 *
	 * @param Rule $rule Regla.
	 *
	 * @return array<string, mixed>
	 */
	private function columns( Rule $rule ): array {
		return array(
			'phrase'       => implode( "\n", $rule->variants ),
			'target_id'    => $rule->target_id,
			'target_url'   => $rule->target_url,
			'lang'         => $rule->lang,
			'max_per_post' => $rule->max_per_post,
			'first_only'   => $rule->first_only ? 1 : 0,
			'exclude'      => $rule->options_json(),
			'active'       => $rule->active ? 1 : 0,
		);
	}
}

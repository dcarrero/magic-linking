<?php
/**
 * Esquema de las tablas propias.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Core;

/**
 * Nombres y sentencias CREATE TABLE de las tablas del plugin.
 *
 * Las sentencias siguen el formato que exige dbDelta(): una columna por
 * línea, dos espacios tras PRIMARY KEY y KEY con nombre. Los enteros llevan
 * el ancho de visualización que devuelven MySQL 5.7 y MariaDB en DESCRIBE
 * (como el núcleo); sin él, dbDelta() los «cambia» en cada pasada. Es siempre
 * el esquema actual; los cambios de datos entre versiones van en las
 * migraciones de Installer.
 */
final class Schema {

	/**
	 * Prefijo de las tablas, detrás del prefijo del sitio.
	 */
	public const PREFIX = 'magiclinking_';

	/**
	 * Tablas propias, sin prefijo.
	 */
	public const TABLES = array( 'docs', 'terms', 'postings', 'links', 'rules', 'changes', 'jobs' );

	/**
	 * Nombre completo de una tabla.
	 *
	 * @param string $site_prefix Prefijo del sitio ($wpdb->prefix).
	 * @param string $table       Una de TABLES.
	 */
	public static function table( string $site_prefix, string $table ): string {
		return $site_prefix . self::PREFIX . $table;
	}

	/**
	 * Sentencias CREATE TABLE, por tabla sin prefijo.
	 *
	 * @param string $site_prefix     Prefijo del sitio ($wpdb->prefix).
	 * @param string $charset_collate Cláusula de juego de caracteres ($wpdb->get_charset_collate()).
	 *
	 * @return array<string, string>
	 */
	public static function create_statements( string $site_prefix, string $charset_collate ): array {
		$docs     = self::table( $site_prefix, 'docs' );
		$terms    = self::table( $site_prefix, 'terms' );
		$postings = self::table( $site_prefix, 'postings' );
		$links    = self::table( $site_prefix, 'links' );
		$rules    = self::table( $site_prefix, 'rules' );
		$changes  = self::table( $site_prefix, 'changes' );
		$jobs     = self::table( $site_prefix, 'jobs' );

		return array(
			// Una fila por entrada indexada.
			'docs'     => "CREATE TABLE {$docs} (
  post_id bigint(20) unsigned NOT NULL,
  post_type varchar(20) NOT NULL DEFAULT '',
  lang varchar(10) NOT NULL DEFAULT '',
  status tinyint(4) NOT NULL DEFAULT 1,
  content_hash char(40) NOT NULL DEFAULT '',
  word_count int(10) unsigned NOT NULL DEFAULT 0,
  doc_len int(10) unsigned NOT NULL DEFAULT 0,
  inbound int(10) unsigned NOT NULL DEFAULT 0,
  outbound int(10) unsigned NOT NULL DEFAULT 0,
  external int(10) unsigned NOT NULL DEFAULT 0,
  broken int(10) unsigned NOT NULL DEFAULT 0,
  embedding mediumblob NULL,
  embedding_model varchar(80) NULL,
  indexed_at datetime NOT NULL,
  lex_hash char(40) NOT NULL DEFAULT '',
  lex_at datetime NULL,
  PRIMARY KEY  (post_id),
  KEY lang_status (lang,status),
  KEY inbound (inbound),
  KEY type_status (post_type,status)
) {$charset_collate};",

			// Vocabulario. La raíz se compara byte a byte: con una
			// colación insensible a tildes, «cancion» y «canción» chocarían
			// en la clave única.
			'terms'    => "CREATE TABLE {$terms} (
  term_id int(10) unsigned NOT NULL AUTO_INCREMENT,
  lang varchar(10) NOT NULL DEFAULT '',
  stem varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '',
  surface varchar(64) NOT NULL DEFAULT '',
  df int(10) unsigned NOT NULL DEFAULT 0,
  n tinyint(3) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY  (term_id),
  UNIQUE KEY lang_stem_n (lang,stem,n)
) {$charset_collate};",

			// Términos principales por entrada, top-K.
			'postings' => "CREATE TABLE {$postings} (
  term_id int(10) unsigned NOT NULL,
  post_id bigint(20) unsigned NOT NULL,
  weight float NOT NULL DEFAULT 0,
  field tinyint(3) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (term_id,post_id),
  KEY post_id (post_id)
) {$charset_collate};",

			// Grafo de enlaces internos.
			'links'    => "CREATE TABLE {$links} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source_id bigint(20) unsigned NOT NULL,
  target_id bigint(20) unsigned NULL,
  target_url varchar(2048) NOT NULL DEFAULT '',
  anchor varchar(255) NOT NULL DEFAULT '',
  block_path varchar(64) NULL,
  kind tinyint(3) unsigned NOT NULL DEFAULT 0,
  is_internal tinyint(3) unsigned NOT NULL DEFAULT 0,
  is_broken tinyint(3) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY source_id (source_id),
  KEY target_id (target_id),
  KEY is_broken (is_broken)
) {$charset_collate};",

			// Reglas automáticas, sin límite de filas.
			'rules'    => "CREATE TABLE {$rules} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  phrase text NOT NULL,
  target_id bigint(20) unsigned NULL,
  target_url varchar(2048) NOT NULL DEFAULT '',
  lang varchar(10) NOT NULL DEFAULT '',
  max_per_post smallint(5) unsigned NOT NULL DEFAULT 1,
  first_only tinyint(3) unsigned NOT NULL DEFAULT 1,
  exclude longtext NULL,
  active tinyint(3) unsigned NOT NULL DEFAULT 1,
  created_at datetime NOT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY active_lang (active,lang)
) {$charset_collate};",

			// Historial de cambios en el contenido. before_html y
			// after_html en vez de before/after: BEFORE es palabra reservada.
			'changes'  => "CREATE TABLE {$changes} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  batch_id char(26) NOT NULL DEFAULT '',
  post_id bigint(20) unsigned NOT NULL,
  action varchar(20) NOT NULL DEFAULT '',
  block_path varchar(64) NULL,
  before_html mediumtext NOT NULL,
  after_html mediumtext NOT NULL,
  content_hash_after char(40) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  undone_at datetime NULL,
  PRIMARY KEY  (id),
  KEY batch_id (batch_id),
  KEY post_created (post_id,created_at),
  KEY created_at (created_at)
) {$charset_collate};",

			// Procesos largos y gasto de IA.
			'jobs'     => "CREATE TABLE {$jobs} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  type varchar(30) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'queued',
  total int(10) unsigned NOT NULL DEFAULT 0,
  done int(10) unsigned NOT NULL DEFAULT 0,
  params longtext NULL,
  ai_tokens_est bigint(20) unsigned NOT NULL DEFAULT 0,
  ai_tokens_real bigint(20) unsigned NOT NULL DEFAULT 0,
  ai_cost_est decimal(12,6) NOT NULL DEFAULT 0,
  ai_cost_real decimal(12,6) NOT NULL DEFAULT 0,
  ai_provider varchar(80) NOT NULL DEFAULT '',
  ai_model varchar(80) NOT NULL DEFAULT '',
  error text NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY type_status (type,status),
  KEY created_at (created_at)
) {$charset_collate};",
		);
	}
}

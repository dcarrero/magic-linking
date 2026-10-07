<?php
/**
 * Motivo por el que no se inserta (o no se quita) un enlace.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Content;

use RuntimeException;

/**
 * Toda negativa del módulo de contenido lleva un código estable (para la API y las pruebas) y un mensaje
 * en el idioma del usuario. Nunca se escribe nada cuando se lanza.
 */
final class InsertionException extends RuntimeException {

	public const NO_POST           = 'no_post';
	public const NOT_ALLOWED       = 'not_allowed';
	public const LOCKED            = 'locked';
	public const TEXT_CHANGED      = 'text_changed';
	public const ALREADY_LINKED    = 'already_linked';
	public const BLOCK_NOT_ALLOWED = 'block_not_allowed';
	public const REUSABLE_BLOCK    = 'reusable_block';
	public const BOUND_BLOCK       = 'bound_block';
	public const CROSSES_TAGS      = 'crosses_tags';
	public const UNSAFE_ANCHOR     = 'unsafe_anchor';
	public const UNSUPPORTED       = 'unsupported_markup';
	public const BAD_REQUEST       = 'bad_request';
	public const VERIFY_FAILED     = 'verify_failed';
	public const WRITE_FAILED      = 'write_failed';
	public const BUSY              = 'busy';
	public const ALTERED           = 'altered_on_save';
	public const EDITED_AFTER      = 'edited_after';

	/**
	 * Código estable.
	 *
	 * @var string
	 */
	private string $reason;

	/**
	 * Constructor.
	 *
	 * @param string $reason  Código (una de las constantes).
	 * @param string $message Mensaje para el usuario.
	 */
	public function __construct( string $reason, string $message ) {
		parent::__construct( $message );
		$this->reason = $reason;
	}

	/**
	 * Código estable.
	 */
	public function reason(): string {
		return $this->reason;
	}
}

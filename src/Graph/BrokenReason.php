<?php
/**
 * Motivos por los que un enlace interno está roto.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Graph;

/**
 * Códigos guardados en magiclinking_links.is_broken (0 = el enlace funciona).
 */
final class BrokenReason {

	public const NONE         = 0;
	public const NOT_FOUND    = 1;
	public const TRASHED      = 2;
	public const UNPUBLISHED  = 3;
	public const PRIVATE_POST = 4;

	/**
	 * Texto para el usuario.
	 *
	 * @param int $code Uno de los códigos.
	 */
	public static function label( int $code ): string {
		switch ( $code ) {
			case self::NOT_FOUND:
				return __( 'No entry exists with this URL.', 'magic-linking' );
			case self::TRASHED:
				return __( 'The destination entry is in the trash.', 'magic-linking' );
			case self::UNPUBLISHED:
				return __( 'The destination entry is not published (draft, pending or scheduled).', 'magic-linking' );
			case self::PRIVATE_POST:
				return __( 'The destination entry is private.', 'magic-linking' );
			default:
				return '';
		}
	}
}

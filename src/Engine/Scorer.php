<?php
/**
 * Puntuación final de una sugerencia.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * Puntuación = Σ peso · señal − penalizaciones.
 *
 * Pesos calibrados con un banco de pruebas del motor; cambiarlos exige
 * volver a pasarlo. El constructor acepta otros solo para comparar
 * configuraciones en el banco.
 */
final class Scorer {

	/**
	 * Peso de cada señal.
	 */
	public const WEIGHTS = array(
		'relevance' => 0.55,
		'anchor'    => 0.25,
		'need'      => 0.05,
		'position'  => 0.10,
		'freshness' => 0.05,
	);

	/**
	 * Cantidad que resta cada penalización.
	 */
	public const PENALTIES = array(
		'overload'        => 0.2,
		'anchor_overused' => 0.1,
		'unlike_target'   => 0.3,
	);

	/**
	 * Bonificaciones: `prioridad_destino` (contenido pilar) y
	 * `afinidad` (por filtro en modo Considerar compartido). Valen 0 hasta que el
	 * banco las valide: quien quiera probarlas las pasa al constructor.
	 */
	public const BONUSES = array(
		'pillar'   => 0.0,
		'affinity' => 0.0,
	);

	/**
	 * Tope de la suma de `afinidad`.
	 */
	public const AFFINITY_CAP = 0.10;

	/**
	 * Umbral mínimo para mostrar.
	 */
	public const THRESHOLD = 0.35;

	/**
	 * Relevancia mínima, aparte del umbral: sin ella, necesidad + posición +
	 * frescura (hasta 0,30) bastaban para mostrar un destino que apenas se
	 * parece al origen. 0,15 es la mediana de la relevancia de las sugerencias
	 * que se mostraban antes de esta regla en los cuatro sitios del banco
	 * (0,14–0,18).
	 */
	public const MIN_RELEVANCE = 0.15;

	/**
	 * Calidad del ancla según la frase objetivo que la produjo.
	 */
	public const ANCHOR_QUALITY = array(
		Phrase::TITLE   => 1.0,
		Phrase::FOCUS   => 1.0,
		Phrase::NGRAM   => 0.7,
		Phrase::UNIGRAM => 0.4,
	);

	/**
	 * Extra de calidad si el ancla tiene dos palabras o más (con tope en 1).
	 */
	public const MULTIWORD_BONUS = 0.1;

	/**
	 * Posición: primer 60 % del texto, resto, último párrafo.
	 */
	public const POSITION = array(
		'early' => 1.0,
		'late'  => 0.6,
		'last'  => 0.4,
	);

	/**
	 * Fracción del texto que cuenta como «primera parte».
	 */
	public const EARLY = 0.6;

	/**
	 * Días en los que un destino cuenta como reciente.
	 */
	public const FRESH_DAYS = 365;

	/**
	 * Enlaces internos por cada 100 palabras por encima de los cuales el origen está sobrecargado.
	 */
	public const DENSITY = 1.0;

	/**
	 * Entradas con la misma ancla hacia el mismo destino a partir de las cuales está sobreexplotada.
	 */
	public const OVERUSED = 5;

	/**
	 * Pesos efectivos.
	 *
	 * @var array<string, float>
	 */
	private array $weights;

	/**
	 * Bonificaciones efectivas.
	 *
	 * @var array<string, float>
	 */
	private array $bonuses;

	/**
	 * Crea el puntuador.
	 *
	 * @param array $weights   Pesos que cambian respecto a WEIGHTS (solo banco).
	 * @param float $threshold Umbral.
	 * @param float $density   Densidad máxima de enlaces por cada 100 palabras.
	 * @param float $min_relevance Relevancia mínima.
	 * @param array $bonuses   Bonificaciones que cambian respecto a BONUSES (`pillar`, `affinity`).
	 * @param int   $overused  Entradas con la misma ancla hacia el mismo destino a partir de las cuales se penaliza.
	 *
	 * @phpstan-param array<string, float> $weights
	 * @phpstan-param array<string, float> $bonuses
	 */
	public function __construct( array $weights = array(), private float $threshold = self::THRESHOLD, private float $density = self::DENSITY, private float $min_relevance = self::MIN_RELEVANCE, array $bonuses = array(), private int $overused = self::OVERUSED ) {
		$this->weights = array_merge( self::WEIGHTS, $weights );
		$this->bonuses = array_merge( self::BONUSES, $bonuses );
	}

	/**
	 * Umbral efectivo.
	 */
	public function threshold(): float {
		return $this->threshold;
	}

	/**
	 * Puntúa un candidato.
	 *
	 * @param Candidate $candidate Candidato.
	 */
	public function score( Candidate $candidate ): Score {
		$signals = array(
			'relevance' => max( 0.0, min( 1.0, $candidate->relevance ) ),
			'anchor'    => self::anchor_quality( $candidate->anchor_kind, $candidate->anchor_words ),
			'need'      => 1 / ( 1 + max( 0, $candidate->inbound ) ),
			'position'  => $candidate->last ? self::POSITION['last'] : ( $candidate->position <= self::EARLY ? self::POSITION['early'] : self::POSITION['late'] ),
			'freshness' => $candidate->age_days <= self::FRESH_DAYS ? 1.0 : 0.0,
		);

		$penalties = array();
		if ( $candidate->source_words > 0 && $candidate->source_links * 100 / $candidate->source_words > $this->density ) {
			$penalties['overload'] = self::PENALTIES['overload'];
		}
		if ( $candidate->anchor_uses > $this->overused ) {
			$penalties['anchor_overused'] = self::PENALTIES['anchor_overused'];
		}
		if ( $candidate->unlike ) {
			$penalties['unlike_target'] = self::PENALTIES['unlike_target'];
		}

		$bonuses = array();
		if ( $candidate->pillar && $this->bonuses['pillar'] > 0 ) {
			$bonuses['pillar'] = $this->bonuses['pillar'];
		}
		if ( $candidate->affinity > 0 && $this->bonuses['affinity'] > 0 ) {
			$bonuses['affinity'] = min( self::AFFINITY_CAP, $candidate->affinity * $this->bonuses['affinity'] );
		}

		$value = 0.0;
		foreach ( $signals as $name => $signal ) {
			$value += ( $this->weights[ $name ] ?? 0.0 ) * $signal;
		}
		$value -= array_sum( $penalties );

		// Las bonificaciones reordenan, pero no hacen pasar por sí solas el umbral.
		$passes = $value >= $this->threshold && $signals['relevance'] >= $this->min_relevance;
		$value += array_sum( $bonuses );

		return new Score( round( $value, 4 ), $signals, $penalties, $passes, $bonuses );
	}

	/**
	 * Calidad del ancla: título o frase objetivo 1,0; n-grama de peso alto 0,7;
	 * unigrama 0,4; +0,1 si tiene dos palabras o más, con tope en 1.
	 *
	 * @param string $kind  Tipo de frase objetivo.
	 * @param int    $words Palabras del ancla.
	 */
	public static function anchor_quality( string $kind, int $words ): float {
		$quality = self::ANCHOR_QUALITY[ $kind ] ?? self::ANCHOR_QUALITY[ Phrase::UNIGRAM ];
		if ( $words >= 2 ) {
			$quality += self::MULTIWORD_BONUS;
		}
		return min( 1.0, $quality );
	}
}

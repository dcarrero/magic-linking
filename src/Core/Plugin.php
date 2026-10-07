<?php
/**
 * Arranque del plugin.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Core;

use LogicException;
use MagicLinking\Admin\Screen;
use MagicLinking\Cli\CliModule;
use MagicLinking\Cli\Command;
use MagicLinking\Content\Inserter;
use MagicLinking\Content\PostWriter;
use MagicLinking\Graph\BrokenRepository;
use MagicLinking\Graph\GraphIndexer;
use MagicLinking\Graph\GraphRepository;
use MagicLinking\Graph\LinkResolver;
use MagicLinking\Graph\ReportRepository;
use MagicLinking\Engine\DomExtractor;
use MagicLinking\History\BatchJob;
use MagicLinking\History\ChangeRepository;
use MagicLinking\History\Reader;
use MagicLinking\History\Redo;
use MagicLinking\History\Retention;
use MagicLinking\History\Undo;
use MagicLinking\I18n\Language;
use MagicLinking\Index\LexicalIndexer;
use MagicLinking\Index\PostSource;
use MagicLinking\Index\SuggestionCache;
use MagicLinking\Index\Suggestions;
use MagicLinking\Index\TableDocuments;
use MagicLinking\Index\TableRepository;
use MagicLinking\I18n\TextDomain;
use MagicLinking\Jobs\JobRepository;
use MagicLinking\Jobs\Jobs;
use MagicLinking\Report\ExportHandler;
use MagicLinking\Rest\HistoryController;
use MagicLinking\Rest\JobsController;
use MagicLinking\Rest\ReportController;
use MagicLinking\Rest\SettingsController;
use MagicLinking\Rest\SuggestionPresenter;
use MagicLinking\Rest\SuggestionsController;

/**
 * Crea el contenedor, declara los servicios y registra los módulos.
 *
 * El fichero magic-linking.php engancha boot() en plugins_loaded; nada se ejecuta al
 * incluir el fichero. Cada módulo decide en register() en qué hooks trabaja,
 * de modo que en el front-end no se carga nada que no haga falta.
 */
final class Plugin {

	/**
	 * Módulos que se registran al arrancar, en este orden.
	 *
	 * @var list<class-string<Module>>
	 */
	private const MODULES = array(
		TextDomain::class,
		Installer::class,
		Jobs::class,
		Retention::class,
		BatchJob::class,
		ReportController::class,
		HistoryController::class,
		SuggestionCache::class,
		SuggestionsController::class,
		JobsController::class,
		SettingsController::class,
		ExportHandler::class,
		CliModule::class,
		Screen::class,
	);

	/**
	 * Instancia arrancada; null hasta boot().
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Contenedor de servicios.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Constructor.
	 *
	 * @param Container $container Contenedor con los servicios ya declarados.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Arranca el plugin una sola vez por petición.
	 */
	public static function boot(): void {
		if ( null !== self::$instance ) {
			return;
		}

		$container = new Container();
		foreach ( self::services() as $id => $factory ) {
			$container->set( $id, $factory );
		}

		self::$instance = new self( $container );
		self::$instance->register_modules( self::MODULES );
	}

	/**
	 * Indica si el plugin ya arrancó.
	 */
	public static function is_booted(): bool {
		return null !== self::$instance;
	}

	/**
	 * Contenedor del plugin arrancado.
	 *
	 * @throws LogicException Si se pide antes de plugins_loaded.
	 */
	public static function container(): Container {
		if ( null === self::$instance ) {
			throw new LogicException( 'Magic Linking has not booted yet.' );
		}

		return self::$instance->container;
	}

	/**
	 * Crea y registra los módulos indicados.
	 *
	 * @param array<int, string> $module_ids Identificadores de servicio de los módulos.
	 *
	 * @throws LogicException Si un identificador no es un módulo.
	 */
	public function register_modules( array $module_ids ): void {
		foreach ( $module_ids as $id ) {
			$module = $this->container->get( $id );

			if ( ! $module instanceof Module ) {
				throw new LogicException( sprintf( 'Service "%s" is not a module.', $id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Mensaje interno, no se imprime.
			}

			$module->register();
		}
	}

	/**
	 * Servicios del plugin. Crear un servicio no tiene efectos: los hooks se
	 * enganchan en register().
	 *
	 * @return array<string, callable(Container): object>
	 */
	private static function services(): array {
		return array(
			Language::class              => static fn(): Language => new Language(),
			Settings::class              => static fn(): Settings => new Settings(),
			GraphRepository::class       => static function (): GraphRepository {
				global $wpdb;
				return new GraphRepository( $wpdb );
			},
			GraphIndexer::class          => static function ( Container $c ): GraphIndexer {
				global $wpdb;
				return new GraphIndexer(
					$c->get( GraphRepository::class ),
					new LinkResolver( $wpdb ),
					$c->get( Settings::class ),
					$c->get( Language::class )
				);
			},
			TableRepository::class       => static function (): TableRepository {
				global $wpdb;
				return new TableRepository( $wpdb );
			},
			PostSource::class            => static fn( Container $c ): PostSource => new PostSource(
				new DomExtractor(),
				$c->get( Language::class ),
				$c->get( Settings::class ),
				$c->get( GraphRepository::class )
			),
			LexicalIndexer::class        => static fn( Container $c ): LexicalIndexer => new LexicalIndexer(
				$c->get( TableRepository::class ),
				$c->get( PostSource::class ),
				$c->get( JobRepository::class )
			),
			TableDocuments::class        => static function ( Container $c ): TableDocuments {
				global $wpdb;
				return new TableDocuments( $c->get( PostSource::class ), new LinkResolver( $wpdb ), $c->get( GraphRepository::class ) );
			},
			Suggestions::class           => static fn( Container $c ): Suggestions => new Suggestions(
				$c->get( TableRepository::class ),
				$c->get( LexicalIndexer::class ),
				$c->get( TableDocuments::class ),
				$c->get( Settings::class )
			),
			SuggestionCache::class       => static fn(): SuggestionCache => new SuggestionCache(),
			SuggestionPresenter::class   => static fn( Container $c ): SuggestionPresenter => new SuggestionPresenter( $c->get( Language::class ) ),
			SuggestionsController::class => static fn( Container $c ): SuggestionsController => new SuggestionsController(
				$c->get( Suggestions::class ),
				$c->get( Inserter::class ),
				$c->get( Reader::class ),
				$c->get( Settings::class ),
				$c->get( Language::class ),
				$c->get( SuggestionPresenter::class )
			),
			PostWriter::class            => static function (): PostWriter {
				global $wpdb;
				return new PostWriter( $wpdb );
			},
			ChangeRepository::class      => static function (): ChangeRepository {
				global $wpdb;
				return new ChangeRepository( $wpdb );
			},
			Inserter::class              => static fn( Container $c ): Inserter => new Inserter(
				$c->get( PostWriter::class ),
				$c->get( ChangeRepository::class ),
				$c->get( Jobs::class ),
				$c->get( Settings::class )
			),
			Undo::class                  => static fn( Container $c ): Undo => new Undo(
				$c->get( PostWriter::class ),
				$c->get( ChangeRepository::class ),
				$c->get( Jobs::class )
			),
			Redo::class                  => static fn( Container $c ): Redo => new Redo(
				$c->get( PostWriter::class ),
				$c->get( ChangeRepository::class ),
				$c->get( Jobs::class )
			),
			Reader::class                => static fn( Container $c ): Reader => new Reader( $c->get( ChangeRepository::class ) ),
			Retention::class             => static fn( Container $c ): Retention => new Retention(
				$c->get( ChangeRepository::class ),
				$c->get( JobRepository::class ),
				$c->get( Settings::class )
			),
			BatchJob::class              => static fn( Container $c ): BatchJob => new BatchJob(
				$c->get( Reader::class ),
				$c->get( Undo::class ),
				$c->get( Redo::class ),
				$c->get( JobRepository::class )
			),
			HistoryController::class     => static fn( Container $c ): HistoryController => new HistoryController(
				$c->get( Reader::class ),
				$c->get( Undo::class ),
				$c->get( Redo::class ),
				$c->get( BatchJob::class ),
				$c->get( ChangeRepository::class ),
				$c->get( JobRepository::class ),
				$c->get( Settings::class )
			),
			JobRepository::class         => static function (): JobRepository {
				global $wpdb;
				return new JobRepository( $wpdb );
			},
			Jobs::class                  => static fn( Container $c ): Jobs => new Jobs(
				$c->get( JobRepository::class ),
				$c->get( GraphIndexer::class ),
				$c->get( Settings::class ),
				$c->get( LexicalIndexer::class )
			),
			ReportRepository::class      => static function ( Container $c ): ReportRepository {
				global $wpdb;
				return new ReportRepository( $wpdb, $c->get( Settings::class ) );
			},
			BrokenRepository::class      => static function (): BrokenRepository {
				global $wpdb;
				return new BrokenRepository( $wpdb );
			},
			ReportController::class      => static fn( Container $c ): ReportController => new ReportController(
				$c->get( ReportRepository::class ),
				$c->get( BrokenRepository::class )
			),
			JobsController::class        => static fn( Container $c ): JobsController => new JobsController( $c->get( Jobs::class ) ),
			SettingsController::class    => static fn( Container $c ): SettingsController => new SettingsController( $c->get( Settings::class ), $c->get( Jobs::class ) ),
			ExportHandler::class         => static fn( Container $c ): ExportHandler => new ExportHandler( $c->get( ReportRepository::class ), $c->get( BrokenRepository::class ) ),
			CliModule::class             => static fn( Container $c ): CliModule => new CliModule(
				static fn(): Command => new Command( $c->get( Jobs::class ), $c->get( ReportRepository::class ), $c->get( BrokenRepository::class ), $c->get( Reader::class ), $c->get( Undo::class ), $c->get( Retention::class ), $c->get( ChangeRepository::class ), $c->get( Redo::class ), $c->get( BatchJob::class ) )
			),
			Screen::class                => static fn( Container $c ): Screen => new Screen( $c->get( Jobs::class ) ),
			TextDomain::class            => static fn(): TextDomain => new TextDomain(),
			Installer::class             => static function (): Installer {
				global $wpdb;
				return new Installer( $wpdb, MAGICLINKING_DB_VERSION );
			},
		);
	}
}

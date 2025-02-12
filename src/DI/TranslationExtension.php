<?php

/**
 * This file is part of the Kdyby (http://www.kdyby.org)
 *
 * Copyright (c) 2008 Filip Procházka (filip@prochazka.su)
 *
 * For the full copyright and license information, please view the file license.txt that was distributed with this source code.
 */

namespace Kdyby\Translation\DI;

use Closure;
use Kdyby\Console\DI\ConsoleExtension;
use Kdyby\Translation\Caching\PhpFileStorage;
use Kdyby\Translation\CatalogueCompiler;
use Kdyby\Translation\CatalogueFactory;
use Kdyby\Translation\Console\ExtractCommand;
use Kdyby\Translation\Diagnostics\Panel;
use Kdyby\Translation\FallbackResolver;
use Kdyby\Translation\IUserLocaleResolver;
use Kdyby\Translation\Latte\TranslateMacros;
use Kdyby\Translation\LocaleResolver\AcceptHeaderResolver;
use Kdyby\Translation\LocaleResolver\ChainResolver;
use Kdyby\Translation\LocaleResolver\LocaleParamResolver;
use Kdyby\Translation\LocaleResolver\SessionResolver;
use Kdyby\Translation\TemplateHelpers;
use Kdyby\Translation\TranslationLoader;
use Kdyby\Translation\Translator as KdybyTranslator;
use Latte\Engine as LatteEngine;
use Nette\Application\Application;
use Nette\Bridges\ApplicationLatte\ILatteFactory;
use Nette\Bridges\ApplicationLatte\LatteFactory;
use Nette\Configurator;
use Nette\DI\Compiler;
use Nette\DI\Definitions\FactoryDefinition;
use Nette\DI\Helpers;
use Nette\DI\Statement;
use Nette\PhpGenerator\ClassType as ClassTypeGenerator;
use Nette\PhpGenerator\PhpLiteral;
use Nette\Reflection\ClassType as ReflectionClassType;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use Nette\Utils\Finder;
use Nette\Utils\Validators;
use Symfony\Component\Translation\Extractor\ChainExtractor;
use Symfony\Component\Translation\Formatter\IntlFormatter;
use Symfony\Component\Translation\Formatter\IntlFormatterInterface;
use Symfony\Component\Translation\Formatter\MessageFormatter;
use Symfony\Component\Translation\Formatter\MessageFormatterInterface;
use Symfony\Component\Translation\Loader\LoaderInterface;
use Symfony\Component\Translation\MessageSelector;
use Symfony\Component\Translation\Writer\TranslationWriter;
use Tracy\Debugger;
use Tracy\IBarPanel;

class TranslationExtension extends \Nette\DI\CompilerExtension
{



	/** @deprecated */
	const LOADER_TAG = self::TAG_LOADER;
	/** @deprecated */
	const DUMPER_TAG = self::TAG_DUMPER;
	/** @deprecated */
	const EXTRACTOR_TAG = self::TAG_EXTRACTOR;

	const TAG_LOADER = 'translation.loader';
	const TAG_DUMPER = 'translation.dumper';
	const TAG_EXTRACTOR = 'translation.extractor';

	const RESOLVER_REQUEST = 'request';
	const RESOLVER_HEADER = 'header';
	const RESOLVER_SESSION = 'session';

	/**
	 * @var mixed[]
	 */
	public $defaults = [
		'whitelist' => NULL, // array('cs', 'en'),
		'default' => 'en',
		'logging' => NULL, //  TRUE for psr/log, or string for kdyby/monolog channel
		// 'fallback' => array('en_US', 'en'), // using custom merge strategy becase Nette's config merger appends lists of values
		'dirs' => ['%appDir%/lang', '%appDir%/locale'],
		'cache' => PhpFileStorage::class,
		'debugger' => '%debugMode%',
		'resolvers' => [
			self::RESOLVER_SESSION => FALSE,
			self::RESOLVER_REQUEST => TRUE,
			self::RESOLVER_HEADER => TRUE,
		],
		'loaders' => [],
	];

	/**
	 * @var array
	 */
	private $loaders;

	public function getConfigSchema(): Schema
	{
		return Expect::structure([
			'whitelist' => Expect::anyOf(Expect::arrayOf('string'), NULL),
			'default' => Expect::string('en'),
			'logging' => Expect::anyOf(Expect::string(), Expect::bool()),
			'fallback' => Expect::arrayOf('string')->default(['en_US']),
			'dirs' => Expect::arrayOf('string')->default(['%appDir%/lang', '%appDir%/locale']),
			'cache' => Expect::string(PhpFileStorage::class),
			'debugger' => Expect::bool(FALSE),
			'resolvers' => Expect::array()->default([
				self::RESOLVER_SESSION => FALSE,
				self::RESOLVER_REQUEST => TRUE,
				self::RESOLVER_HEADER => TRUE,
			]),
			'loaders' => Expect::array(),
		])->castTo('array');
	}

	public function loadConfiguration()
	{
		$this->loaders = [];

		$builder = $this->getContainerBuilder();
		/** @var array $config */
		$config = $this->config;
		$config['cache'] = new Statement($config['cache'], [dirname(Helpers::expand('%tempDir%/cache', $builder->parameters))]);

		$translator = $builder->addDefinition($this->prefix('default'))
			->setFactory(KdybyTranslator::class, [$this->prefix('@userLocaleResolver')])
			->addSetup('?->setTranslator(?)', [$this->prefix('@userLocaleResolver.param'), '@self'])
			->addSetup('setDefaultLocale', [$config['default']])
			->addSetup('setLocaleWhitelist', [$config['whitelist']]);

		Validators::assertField($config, 'fallback', 'list');
		$translator->addSetup('setFallbackLocales', [$config['fallback']]);

		$catalogueCompiler = $builder->addDefinition($this->prefix('catalogueCompiler'))
			->setFactory(CatalogueCompiler::class, self::filterArgs($config['cache']));

		if ($config['debugger'] && interface_exists(IBarPanel::class)) {
			$builder->addDefinition($this->prefix('panel'))
				->setFactory(Panel::class, [dirname(Helpers::expand('%appDir%', $builder->parameters))])
				->addSetup('setLocaleWhitelist', [$config['whitelist']]);

			$translator->addSetup('?->register(?)', [$this->prefix('@panel'), '@self']);
			$catalogueCompiler->addSetup('enableDebugMode');
		}

		$this->loadLocaleResolver($config);

		$builder->addDefinition($this->prefix('helpers'))
			->setClass(TemplateHelpers::class)
			->setFactory($this->prefix('@default') . '::createTemplateHelpers');

		$builder->addDefinition($this->prefix('fallbackResolver'))
			->setClass(FallbackResolver::class);

		$builder->addDefinition($this->prefix('catalogueFactory'))
			->setClass(CatalogueFactory::class);

		$builder->addDefinition($this->prefix('selector'))
			->setClass(MessageSelector::class);

		if (interface_exists(IntlFormatterInterface::class)) {
			$builder->addDefinition($this->prefix('intlFormatter'))
				->setType(IntlFormatterInterface::class)
				->setFactory(IntlFormatter::class);
		}

		$builder->addDefinition($this->prefix('formatter'))
			->setType(MessageFormatterInterface::class)
			->setFactory(MessageFormatter::class);

		$builder->addDefinition($this->prefix('extractor'))
			->setClass(ChainExtractor::class);

		$this->loadExtractors();

		$builder->addDefinition($this->prefix('writer'))
			->setClass(TranslationWriter::class);

		$this->loadDumpers();

		$builder->addDefinition($this->prefix('loader'))
			->setClass(TranslationLoader::class);

		$loaders = $this->loadFromFile(__DIR__ . '/config/loaders.neon');
		$this->loadLoaders($loaders, $config['loaders'] ?: array_keys($loaders));

		if ($this->isRegisteredConsoleExtension()) {
			$this->loadConsole($config);
		}
	}

	protected function loadLocaleResolver(array $config)
	{
		$builder = $this->getContainerBuilder();

		$builder->addDefinition($this->prefix('userLocaleResolver.param'))
			->setClass(LocaleParamResolver::class)
			->setAutowired(FALSE);

		$builder->addDefinition($this->prefix('userLocaleResolver.acceptHeader'))
			->setClass(AcceptHeaderResolver::class);

		$builder->addDefinition($this->prefix('userLocaleResolver.session'))
			->setClass(SessionResolver::class);

		$chain = $builder->addDefinition($this->prefix('userLocaleResolver'))
			->setClass(IUserLocaleResolver::class)
			->setFactory(ChainResolver::class);

		$resolvers = [];
		if ($config['resolvers'][self::RESOLVER_HEADER]) {
			$resolvers[] = $this->prefix('@userLocaleResolver.acceptHeader');
			$chain->addSetup('addResolver', [$this->prefix('@userLocaleResolver.acceptHeader')]);
		}

		if ($config['resolvers'][self::RESOLVER_REQUEST]) {
			$resolvers[] = $this->prefix('@userLocaleResolver.param');
			$chain->addSetup('addResolver', [$this->prefix('@userLocaleResolver.param')]);
		}

		if ($config['resolvers'][self::RESOLVER_SESSION]) {
			$resolvers[] = $this->prefix('@userLocaleResolver.session');
			$chain->addSetup('addResolver', [$this->prefix('@userLocaleResolver.session')]);
		}

		if ($config['debugger'] && interface_exists(IBarPanel::class)) {
			/** @var \Nette\DI\Definitions\ServiceDefinition $panel */
			$panel = $builder->getDefinition($this->prefix('panel'));
			$panel->addSetup('setLocaleResolvers', [array_reverse($resolvers)]);
		}
	}

	protected function loadConsole(array $config)
	{
		$builder = $this->getContainerBuilder();

		Validators::assertField($config, 'dirs', 'list');
		$builder->addDefinition($this->prefix('console.extract'))
			->setFactory(ExtractCommand::class)
			->addSetup('$defaultOutputDir', [reset($config['dirs'])])
			->addTag(ConsoleExtension::TAG_COMMAND, 'latte');
	}

	protected function loadDumpers()
	{
		$builder = $this->getContainerBuilder();

		foreach ($this->loadFromFile(__DIR__ . '/config/dumpers.neon') as $format => $class) {
			$builder->addDefinition($this->prefix('dumper.' . $format))
				->setClass($class)
				->addTag(self::TAG_DUMPER, $format);
		}
	}

	protected function loadLoaders(array $loaders, array $allowed)
	{
		$builder = $this->getContainerBuilder();

		foreach ($loaders as $format => $class) {
			if (array_search($format, $allowed) === FALSE) {
				continue;
			}
			$builder->addDefinition($this->prefix('loader.' . $format))
				->setClass($class)
				->addTag(self::TAG_LOADER, $format);
		}
	}

	protected function loadExtractors()
	{
		$builder = $this->getContainerBuilder();

		foreach ($this->loadFromFile(__DIR__ . '/config/extractors.neon') as $format => $class) {
			$builder->addDefinition($this->prefix('extractor.' . $format))
				->setClass($class)
				->addTag(self::TAG_EXTRACTOR, $format);
		}
	}

	public function beforeCompile()
	{
		$builder = $this->getContainerBuilder();
		/** @var array $config */
		$config = $this->config;

		$this->beforeCompileLogging($config);

		$registerToLatte = function (FactoryDefinition $def) {
			$def->getResultDefinition()->addSetup('?->onCompile[] = function($engine) { ?::install($engine->getCompiler()); }', ['@self', new PhpLiteral(TranslateMacros::class)]);

			$def->getResultDefinition()
				->addSetup('addProvider', ['translator', $this->prefix('@default')])
				->addSetup('addFilter', ['translate', [$this->prefix('@helpers'), 'translateFilterAware']]);
		};

		$latteFactoryService = $builder->getByType(LatteFactory::class) ?: $builder->getByType(ILatteFactory::class);
		if (!$latteFactoryService || !self::isOfType($builder->getDefinition($latteFactoryService)->getClass(), LatteEngine::class)) {
			$latteFactoryService = 'nette.latteFactory';
		}

		if ($builder->hasDefinition($latteFactoryService) && (self::isOfType($builder->getDefinition($latteFactoryService)->getClass(), LatteFactory::class) || self::isOfType($builder->getDefinition($latteFactoryService)->getClass(), ILatteFactory::class))) {
			$registerToLatte($builder->getDefinition($latteFactoryService));
		}

		if ($builder->hasDefinition('nette.latte')) {
			$registerToLatte($builder->getDefinition('nette.latte'));
		}

		$applicationService = $builder->getByType(Application::class) ?: 'application';
		if ($builder->hasDefinition($applicationService)) {

			/** @var \Nette\DI\Definitions\ServiceDefinition $applicationServiceDefinition */
			$applicationServiceDefinition = $builder->getDefinition($applicationService);
			$applicationServiceDefinition
				->addSetup('$service->onRequest[] = ?', [[$this->prefix('@userLocaleResolver.param'), 'onRequest']]);

			if ($config['debugger'] && interface_exists(IBarPanel::class)) {
				$applicationServiceDefinition
					->addSetup('$self = $this; $service->onStartup[] = function () use ($self) { $self->getService(?); }', [$this->prefix('default')])
					->addSetup('$service->onRequest[] = ?', [[$this->prefix('@panel'), 'onRequest']]);
			}
		}

		if (class_exists(Debugger::class)) {
			Panel::registerBluescreen();
		}

		/** @var \Nette\DI\Definitions\ServiceDefinition $extractor */
		$extractor = $builder->getDefinition($this->prefix('extractor'));
		foreach ($builder->findByTag(self::TAG_EXTRACTOR) as $extractorId => $meta) {
			Validators::assert($meta, 'string:2..');

			$extractor->addSetup('addExtractor', [$meta, '@' . $extractorId]);

			$builder->getDefinition($extractorId)->setAutowired(FALSE);
		}

		/** @var \Nette\DI\Definitions\ServiceDefinition $writer */
		$writer = $builder->getDefinition($this->prefix('writer'));
		foreach ($builder->findByTag(self::TAG_DUMPER) as $dumperId => $meta) {
			Validators::assert($meta, 'string:2..');

			$writer->addSetup('addDumper', [$meta, '@' . $dumperId]);

			$builder->getDefinition($dumperId)->setAutowired(FALSE);
		}

		$this->loaders = [];
		foreach ($builder->findByTag(self::TAG_LOADER) as $loaderId => $meta) {
			Validators::assert($meta, 'string:2..');
			$builder->getDefinition($loaderId)->setAutowired(FALSE);
			$this->loaders[$meta] = $loaderId;
		}

		/** @var \Nette\DI\Definitions\ServiceDefinition $loaderDefinition */
		$loaderDefinition = $builder->getDefinition($this->prefix('loader'));
		$loaderDefinition->addSetup('injectServiceIds', [$this->loaders]);

		foreach ($this->compiler->getExtensions() as $extension) {
			if (!$extension instanceof ITranslationProvider) {
				continue;
			}

			$config['dirs'] = array_merge($config['dirs'], array_values($extension->getTranslationResources()));
		}

		$config['dirs'] = array_map(function ($dir) use ($builder) {
			return str_replace((DIRECTORY_SEPARATOR === '/') ? '\\' : '/', DIRECTORY_SEPARATOR, Helpers::expand($dir, $builder->parameters));
		}, $config['dirs']);

		$dirs = array_values(array_filter($config['dirs'], Closure::fromCallable('is_dir')));
		if (count($dirs) > 0) {
			foreach ($dirs as $dir) {
				$builder->addDependency($dir);
			}

			$this->loadResourcesFromDirs($dirs);
		}
	}

	protected function beforeCompileLogging(array $config)
	{
		$builder = $this->getContainerBuilder();
		/** @var \Nette\DI\Definitions\ServiceDefinition $translator */
		$translator = $builder->getDefinition($this->prefix('default'));

		if ($config['logging'] === TRUE) {
			$translator->addSetup('injectPsrLogger');
		} elseif ($config['logging'] !== NULL) {
			throw new \Kdyby\Translation\InvalidArgumentException(sprintf(
				'Invalid config option for logger. Valid are TRUE for general psr/log or string for kdyby/monolog channel, but %s was given',
				$config['logging']
			));
		}
	}

	protected function loadResourcesFromDirs($dirs)
	{
		$builder = $this->getContainerBuilder();
		$config = $this->config;

		$whitelistRegexp = KdybyTranslator::buildWhitelistRegexp($config['whitelist']);
		/** @var \Nette\DI\Definitions\ServiceDefinition $translator */
		$translator = $builder->getDefinition($this->prefix('default'));

		$mask = array_map(function ($value) {
			return '*.*.' . $value;
		}, array_keys($this->loaders));

		foreach (Finder::findFiles($mask)->from($dirs) as $file) {
			/** @var \SplFileInfo $file */
			if (!preg_match('~^(?P<domain>.*?)\.(?P<locale>[^\.]+)\.(?P<format>[^\.]+)$~', $file->getFilename(), $m)) {
				continue;
			}

			if ($whitelistRegexp && !preg_match($whitelistRegexp, $m['locale']) && $builder->parameters['productionMode']) {
				continue; // ignore in production mode, there is no need to pass the ignored resources
			}

			$this->validateResource($m['format'], $file->getPathname(), $m['locale'], $m['domain']);
			$translator->addSetup('addResource', [$m['format'], $file->getPathname(), $m['locale'], $m['domain']]);
			$builder->addDependency($file->getPathname());
		}
	}

	/**
	 * @param string $format
	 * @param string $file
	 * @param string $locale
	 * @param string $domain
	 */
	protected function validateResource($format, $file, $locale, $domain)
	{
		$builder = $this->getContainerBuilder();

		if (!isset($this->loaders[$format])) {
			return;
		}

		try {
			/** @var \Nette\DI\Definitions\ServiceDefinition $def */
			$def = $builder->getDefinition($this->loaders[$format]);
			$refl = ReflectionClassType::from($def->getEntity() ?: $def->getClass());
			$method = $refl->getConstructor();
			if ($method !== NULL && $method->getNumberOfRequiredParameters() > 1) {
				return;
			}

			$loader = $refl->newInstance();
			if (!$loader instanceof LoaderInterface) {
				return;
			}

		} catch (\ReflectionException $e) {
			return;
		}

		try {
			$loader->load($file, $locale, $domain);

		} catch (\Exception $e) {
			throw new \Kdyby\Translation\InvalidResourceException(sprintf('Resource %s is not valid and cannot be loaded.', $file), 0, $e);
		}
	}

	public function afterCompile(ClassTypeGenerator $class)
	{
		$initialize = $class->getMethod('initialize');
		if (class_exists(Debugger::class)) {
			$initialize->addBody('?::registerBluescreen();', [new PhpLiteral(Panel::class)]);
		}
	}

	private function isRegisteredConsoleExtension()
	{
		foreach ($this->compiler->getExtensions() as $extension) {
			if ($extension instanceof ConsoleExtension) {
				return TRUE;
			}
		}

		return FALSE;
	}

	/**
	 * @param \Nette\Configurator $configurator
	 */
	public static function register(Configurator $configurator)
	{
		$configurator->onCompile[] = function ($config, Compiler $compiler) {
			$compiler->addExtension('translation', new TranslationExtension());
		};
	}

	/**
	 * @param string|\stdClass $statement
	 * @return \Nette\DI\Statement[]
	 */
	protected static function filterArgs($statement)
	{
		return Helpers::filterArguments([is_string($statement) ? new Statement($statement) : $statement]);
	}

	/**
	 * @param string|NULL $class
	 * @param string $type
	 * @return bool
	 */
	private static function isOfType($class, $type)
	{
		return $class !== NULL && ($class === $type || is_subclass_of($class, $type));
	}

}

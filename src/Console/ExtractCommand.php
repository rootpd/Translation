<?php

/**
 * This file is part of the Kdyby (http://www.kdyby.org)
 *
 * Copyright (c) 2008 Filip Procházka (filip@prochazka.su)
 *
 * For the full copyright and license information, please view the file license.txt that was distributed with this source code.
 */

namespace Kdyby\Translation\Console;

use Kdyby\Translation\MessageCatalogue;
use Nette\DI\Helpers;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Translation\Extractor\ChainExtractor;
use Symfony\Component\Translation\Writer\TranslationWriter;

class ExtractCommand extends \Symfony\Component\Console\Command\Command
{


	/**
	 * @var string
	 */
	public $defaultOutputDir = '%appDir%/lang';

	/**
	 * @var \Symfony\Component\Translation\Writer\TranslationWriter
	 */
	private $writer;

	/**
	 * @var \Symfony\Component\Translation\Extractor\ChainExtractor
	 */
	private $extractor;

	/**
	 * @var \Nette\DI\Container
	 */
	private $serviceLocator;

	/**
	 * @var string
	 */
	private $outputFormat;

	/**
	 * @var array
	 */
	private $scanDirs;

	/**
	 * @var string
	 */
	private $outputDir;

	protected function configure()
	{
		$this->setName('kdyby:translation-extract')
			->setDescription('Extracts strings from application to translation files')
			->addOption('scan-dir', 'd', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'The directory to parse the translations. Can contain %placeholders%.', ['%appDir%'])
			->addOption('output-format', 'f', InputOption::VALUE_REQUIRED, 'Format name of the messages.')
			->addOption('output-dir', 'o', InputOption::VALUE_OPTIONAL, 'Directory to write the messages to. Can contain %placeholders%.', $this->defaultOutputDir)
			->addOption('catalogue-language', 'l', InputOption::VALUE_OPTIONAL, 'The language of the catalogue', 'en_US');
	}

	protected function initialize(InputInterface $input, OutputInterface $output)
	{
		$this->writer = $this->getHelper('container')->getByType(TranslationWriter::class);
		$this->extractor = $this->getHelper('container')->getByType(ChainExtractor::class);
		$this->serviceLocator = $this->getHelper('container')->getContainer();
	}

	protected function validate(InputInterface $input, OutputInterface $output)
	{
		$this->outputFormat = trim($input->getOption('output-format'), '=');
		if (!in_array($this->outputFormat, $this->writer->getFormats(), TRUE)) {
			$output->writeln('<error>Unknown --output-format</error>');
			$output->writeln(sprintf('<info>Choose one of: %s</info>', implode(', ', $this->writer->getFormats())));

			return FALSE;
		}

		$this->scanDirs = Helpers::expand($input->getOption('scan-dir'), $this->serviceLocator->parameters);
		foreach ($this->scanDirs as $dir) {
			if (!is_dir($dir)) {
				$output->writeln(sprintf('<error>Given --scan-dir "%s" does not exists.</error>', $dir));

				return FALSE;
			}
		}

		$this->outputDir = Helpers::expand($input->getOption('output-dir'), $this->serviceLocator->parameters);
		if (!is_dir($this->outputDir) || !is_writable($this->outputDir)) {
			$output->writeln(sprintf('<error>Given --output-dir "%s" does not exists or is not writable.</error>', $this->outputDir));

			return FALSE;
		}

		return TRUE;
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		if ($this->validate($input, $output) !== TRUE) {
			return 1;
		}

		$catalogue = new MessageCatalogue((string) $input->getOption('catalogue-language'));
		foreach ($this->scanDirs as $dir) {
			$output->writeln(sprintf('<info>Extracting %s</info>', $dir));
			$this->extractor->extract($dir, $catalogue);
		}

		$this->writer->write($catalogue, $this->outputFormat, [
			'path' => $this->outputDir,
		]);

		$output->writeln('');
		$output->writeln(sprintf('<info>Catalogue was written to %s</info>', $this->outputDir));

		return 0;
	}

}

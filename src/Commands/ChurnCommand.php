<?php declare(strict_types = 1);

namespace Churn\Commands;

use Churn\Collections\FileCollection;
use Churn\Results\ResultCollection;
use Illuminate\Support\Collection;
use Churn\Factories\ProcessFactory;
use Churn\Managers\FileManager;
use Churn\Results\ResultsParser;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Churn\Configuration\Config;
use Symfony\Component\Yaml\Yaml;

class ChurnCommand extends Command
{
    /**
     * The config values.
     * @var Config
     */
    private $config;

    /**
     * The process factory.
     * @var ProcessFactory
     */
    private $processFactory;

    /**
     * Th results parser.
     * @var ResultsParser
     */
    private $resultsParser;

    /**
     * Collection of files to run the processes on.
     * @var FileCollection
     */
    private $filesCollection;

    /**
     * Collection of processes currently running.
     * @var Collection
     */
    private $runningProcesses;

    /**
     * Array of completed processes.
     * @var array
     */
    private $completedProcessesArray;

    /**
     * The start time.
     * @var float
     */
    private $startTime;

    /**
     * Keeps track of how many files were processed.
     * @var integer
     */
    private $filesCount;

    /**
     * ChurnCommand constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->resultsParser = new ResultsParser();
    }

    /**
     * Configure the command
     * @return void
     */
    protected function configure()
    {
        $this->setName('run')
            ->addArgument('paths', InputArgument::IS_ARRAY, 'Path to source to check.')
            ->addOption('configuration', 'c', InputOption::VALUE_OPTIONAL, 'Path to the configuration file', 'churn.yml')  // @codingStandardsIgnoreLine
            ->setDescription('Check files')
            ->setHelp('Checks the churn on the provided path argument(s).');
    }

    /**
     * Execute the command
     * @param  InputInterface  $input  Input.
     * @param  OutputInterface $output Output.
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->startTime = microtime(true);
        $this->setupProcessor($input->getOption('configuration'));

        $this->filesCollection = $this->getPhpFiles($input->getArgument('paths'));
        $this->filesCount = $this->filesCollection->count();
        $this->runningProcesses = new Collection;
        $this->completedProcessesArray = [];
        while ($this->filesCollection->hasFiles() || $this->runningProcesses->count()) {
            $this->getProcessResults();
        }
        $completedProcesses = new Collection($this->completedProcessesArray);

        $results = $this->resultsParser
            ->parse($completedProcesses)
            ->normalizeAgainst($this->config);
        $this->displayResults($output, $results);
    }

    /**
     * Gets the output from processes and stores them in the completedProcessArray member.
     * @return void
     */
    private function getProcessResults()
    {
        for ($index = $this->runningProcesses->count(); $this->filesCollection->hasFiles() > 0 && $index < $this->config->getParallelJobs(); $index++) {
            $file = $this->filesCollection->getNextFile();

            $process = $this->processFactory->createGitCommitProcess($file);
            $process->start();
            $this->runningProcesses->put($process->getKey(), $process);

            $process = $this->processFactory->createCyclomaticComplexityProcess($file);
            $process->start();
            $this->runningProcesses->put($process->getKey(), $process);
        }

        foreach ($this->runningProcesses as $file => $process) {
            if ($process->isSuccessful()) {
                $this->runningProcesses->forget($process->getKey());
                $this->completedProcessesArray[$process->getFileName()][$process->getType()] = $process;
            }
        }
    }

    /**
     * Displays the results in a table.
     * @param  OutputInterface  $output  Output.
     * @param  ResultCollection $results Results Collection.
     * @return void
     */
    protected function displayResults(OutputInterface $output, ResultCollection $results)
    {
        $totalTime = microtime(true) - $this->startTime;
        echo "\n
    ___  _   _  __  __  ____  _  _     ____  _   _  ____
   / __)( )_( )(  )(  )(  _ \( \( )___(  _ \( )_( )(  _ \
  ( (__  ) _ (  )(__)(  )   / )  ((___))___/ ) _ (  )___/
   \___)(_) (_)(______)(_)\_)(_)\_)   (__)  (_) (_)(__)      https://github.com/bmitch/churn-php\n\n";

        $table = new Table($output);
        $table->setHeaders(['File', 'Times Changed', 'Complexity', 'Score']);
        $table->addRows($results->toArray());

        $table->render();

        echo "  "
            . $this->filesCount
            . " files analysed in {$totalTime} seconds using "
            . $this->config->getParallelJobs()
            . " parallel jobs.\n\n";
    }

    /**
     * @param string $configFile Relative path churn.yml configuration file.
     * @return void
     */
    private function setupProcessor(string $configFile)
    {
        $this->config = Config::create(Yaml::parse(@file_get_contents($configFile)) ?? []);
        $this->processFactory = new ProcessFactory($this->config);
    }

    /**
     * @param array $directory Directories.
     * @return FileCollection
     */
    private function getPhpFiles(array $directory): FileCollection
    {
        $fileManager = new FileManager($this->config->getFileExtensions(), $this->config->getFilesToIgnore());

        return $fileManager->getPhpFiles($directory);
    }
}

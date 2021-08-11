<?php

namespace Voyager\Installer\Console;

use ZipArchive;
use RuntimeException;
use GuzzleHttp\Client;
use Symfony\Component\Process\Process;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Console\Question\Question;
use Dotenv\Dotenv;


class NewCommand extends Command
{
    private $input;
    private $output;
    private $dotenv;
    protected $app_url;
    protected $db_connection;
    protected $db_host;
    protected $db_port;
    protected $db_database;
    protected $db_username;
    protected $db_password;


    /**
     * Configure the command options.
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setName('new')
            ->setDescription('Create a new Laravel application.')
            ->addArgument('name', InputArgument::OPTIONAL)
            ->addOption('database', null, InputOption::VALUE_NONE, 'Will prompt for more database fields, such as connection, host, and port')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Forces install even if the directory already exists')
            ->addOption('with-dummy', null, InputOption::VALUE_NONE, 'Voyager installs with dummy data');
    }

    /**
     * Execute the command.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;

        $directory = ($input->getArgument('name')) ? getcwd() . '/' . $input->getArgument('name') : getcwd();

        if (!$input->getOption('force')) {
            $this->verifyApplicationDoesntExist($directory);
        }

        $this->printASCII($output);
        $output->writeln("<fg=black;bg=cyan>Ahoy Matey! Welcome to the Voyager Installer!</>\n");

        $output->writeln('<info>After you\'ve created a database for this application, enter in the credentials below:</info>');
        $this->askEnvironmentVariables();

        $composer = $this->findComposer();

        $output->writeln('<info>Setting Sail! Crafting your new application...</info>');
        $this->runCommands([
            $composer . " create-project laravel/laravel \"$directory\" --remove-vcs --prefer-dist",
            'cd ' . $directory,
            $composer . ' require tcg/voyager',
        ]);

        $output->writeln('<info>Make the storage and bootstrap cache directories are writable</info>');
        $this->prepareWritableDirectories($directory);


        $output->writeln('<info>Updating Environment Variables</info>');
        $this->updateEnvironmentVariables($directory);


        $output->writeln('<info>Installing Voyager assets and migrations...</info>');

        $this->runCommands([
            'cd ' . $directory,
            'php artisan cache:clear',
            'php artisan config:clear',
            'php artisan voyager:install' . ($input->getOption('with-dummy') ? ' --with-dummy' : ''),
        ]);

        $output->writeln('<comment>Application ready! Build something amazing.</comment>');

        return 1;
    }

    /**
     * Run multiple commands in a single process.
     *
     * @param array $commands
     * @return void
     */
    public function runCommands($commands)
    {x
        $process = Process::fromShellCommandline(implode(' && ', $commands));
        $process->setTimeout(300);

        if ('\\' !== DIRECTORY_SEPARATOR && file_exists('/dev/tty') && is_readable('/dev/tty')) {
            $process->setTty(true);
        }

        $process->run(function ($type, $line) {
            $this->output->write($line);
        });
    }

    /**
     * Verify that the application does not already exist.
     *
     * @param  string  $directory
     * @return void
     */
    protected function verifyApplicationDoesntExist($directory)
    {
        if ((is_dir($directory) || is_file($directory)) && $directory != getcwd()) {
            throw new RuntimeException('Application already exists!');
        }
    }

    protected function printASCII($output)
    {
        $output->writeln('                                          ');
        $output->writeln('  _    __                                 ');
        $output->writeln(' | |  / /___  __  ______ _____ ____  _____');
        $output->writeln(' | | / / __ \/ / / / __ `/ __ `/ _ \/ ___/');
        $output->writeln(' | |/ / /_/ / /_/ / /_/ / /_/ /  __/ /    ');
        $output->writeln(' |___/\____/\__, /\__,_/\__, /\___/_/     ');
        $output->writeln('           /____/      /____/             ');
        $output->writeln('                                          ');
    }

    protected function askEnvironmentVariables()
    {
        $helper = $this->getHelper('question');

        if ($this->input->getOption('database')) {
            $this->db_connection = $helper->ask($this->input, $this->output, new Question('DB_CONNECTION=', 'mysql'));
            $this->db_host = $helper->ask($this->input, $this->output, new Question('DB_HOST=', '127.0.0.1'));
            $this->db_port = $helper->ask($this->input, $this->output, new Question('DB_PORT=', '3306'));
        }

        $this->db_database = $helper->ask($this->input, $this->output, new Question('DB_DATABASE=', ''));
        $this->db_username = $helper->ask($this->input, $this->output, new Question('DB_USERNAME=', ''));
        $this->db_password = $helper->ask($this->input, $this->output, new Question('DB_PASSWORD=', ''));

        $this->output->writeln('<info>Finally, we just need your application URL to finish the install:</info>');
        $this->app_url = $helper->ask($this->input, $this->output, new Question('APP_URL=', 'http://localhost'));
    }

    /**
     * Update the environment variables file.
     *
     * @param string $directory
     * @param string $db_connection
     * @param string $db_host
     * @param string $db_port
     * @param string $db_database
     * @param string $db_username
     * @param string $db_password
     * @param string $app_url
     * @return void
     */
    protected function updateEnvironmentVariables($directory)
    {
        $this->dotenv = Dotenv::createMutable($directory);
        $this->dotenv->safeLoad();
        $this->changeEnvironmentVariable($directory, 'APP_URL', $this->app_url);

        if ($this->input->getOption('database')) {
            $this->changeEnvironmentVariable($directory, 'DB_CONNECTION', $this->db_connection);
            $this->changeEnvironmentVariable($directory, 'DB_HOST', $this->db_host);
            $this->changeEnvironmentVariable($directory, 'DB_PORT', $this->db_port);
        }

        $this->changeEnvironmentVariable($directory, 'DB_DATABASE', $this->db_database);
        $this->changeEnvironmentVariable($directory, 'DB_USERNAME', $this->db_username);
        $this->changeEnvironmentVariable($directory, 'DB_PASSWORD', $this->db_password);

        // Load new modified dot env
        $this->dotenv = Dotenv::createMutable($directory);
        $this->dotenv->safeLoad();
    }

    /**
     * Change Environment variables
     * @param  string $key
     * @param  string $value
     * @return void
     */
    protected function changeEnvironmentVariable($directory, $key, $value)
    {
        $path = $directory . '/.env';

        if (!file_exists($path)) {
            return null;
        }

        $old = $_ENV[$key] ?? '';

        file_put_contents($path, str_replace(
            "$key=" . $old,
            "$key=" . $value,
            file_get_contents($path)
        ));

        putenv($key . '=' . $value);
    }

    /**
     * Make sure the storage and bootstrap cache directories are writable.
     *
     * @param  string  $appDirectory
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return $this
     */
    protected function prepareWritableDirectories($appDirectory)
    {
        $filesystem = new Filesystem;

        try {
            $filesystem->chmod($appDirectory . DIRECTORY_SEPARATOR . "bootstrap/cache", 0755, 0000, true);
            $filesystem->chmod($appDirectory . DIRECTORY_SEPARATOR . "storage", 0755, 0000, true);
        } catch (IOExceptionInterface $e) {
            $this->output->writeln('<comment>You should verify that the "storage" and "bootstrap/cache" directories are writable.</comment>');
        }
    }

    /**
     * Get the composer command for the environment.
     *
     * @return string
     */
    protected function findComposer()
    {
        if (file_exists(getcwd() . '/composer.phar')) {
            return '"' . PHP_BINARY . '" composer.phar';
        }

        return 'composer';
    }
}

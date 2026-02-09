<?php

declare(strict_types=1);

namespace Everoute\Cli;

use Everoute\Config\Env;
use Everoute\Risk\ChokepointSeeder;
use PDO;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

final class InstallCommand extends Command
{
    protected static $defaultName = 'install';

    protected function configure(): void
    {
        $this
            ->setName('install')
            ->setDescription('Install Everoute database and user')
            ->addOption('schema-only', null, InputOption::VALUE_NONE, 'Apply schema and seed only')
            ->addOption('reset', null, InputOption::VALUE_NONE, 'Drop and recreate the database before applying schema')
            ->addOption('db-host', null, InputOption::VALUE_REQUIRED, 'DB host', Env::get('DB_HOST', '127.0.0.1'))
            ->addOption('db-port', null, InputOption::VALUE_REQUIRED, 'DB port', (string) Env::int('DB_PORT', 3306))
            ->addOption('db-name', null, InputOption::VALUE_REQUIRED, 'DB name', Env::get('DB_NAME', 'everoute'))
            ->addOption('app-user', null, InputOption::VALUE_REQUIRED, 'App DB user', Env::get('DB_USER', 'everoute_app'))
            ->addOption('app-pass', null, InputOption::VALUE_REQUIRED, 'App DB password', Env::get('DB_PASS', ''))
            ->addOption('db-pass', null, InputOption::VALUE_REQUIRED, 'DB password (alias for app-pass)', Env::get('DB_PASS', ''))
            ->addOption('write-env', null, InputOption::VALUE_NONE, 'Write .env file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $schemaOnly = (bool) $input->getOption('schema-only');
        $reset = (bool) $input->getOption('reset');
        $dbHost = (string) $input->getOption('db-host');
        $dbPort = (int) $input->getOption('db-port');
        $dbName = (string) $input->getOption('db-name');
        $appUser = (string) $input->getOption('app-user');
        $appPass = (string) $input->getOption('app-pass');
        if ($appPass === '') {
            $appPass = (string) $input->getOption('db-pass');
        }

        $helper = $this->getHelper('question');
        if ($appPass === '' && $input->isInteractive()) {
            $question = new Question('App DB password (leave blank to generate): ');
            $appPass = (string) $helper->ask($input, $output, $question);
        }
        if ($appPass === '' && !$input->isInteractive()) {
            $output->writeln('<error>DB_PASS missing. Provide via --db-pass or .env</error>');
            return Command::FAILURE;
        }
        if ($appPass === '') {
            $appPass = bin2hex(random_bytes(8));
        }

        if (!$schemaOnly || $reset) {
            $adminDsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $dbHost, $dbPort);
            $adminPdo = new PDO($adminDsn, $appUser, $appPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            $dbNameSafe = str_replace('`', '``', $dbName);
            $appUserSafe = str_replace('`', '``', $appUser);
            $appPassSafe = $adminPdo->quote($appPass);
            if ($reset) {
                $adminPdo->exec("DROP DATABASE IF EXISTS `{$dbNameSafe}`");
                $output->writeln('<info>Dropped existing database.</info>');
            }
            $adminPdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbNameSafe}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $adminPdo->exec("CREATE USER IF NOT EXISTS `{$appUserSafe}`@'%' IDENTIFIED BY {$appPassSafe}");

            $grants = str_replace(['{{db_name}}', '{{app_user}}'], [$dbNameSafe, $appUserSafe], $this->loadSql('sql/grants.sql'));
            $adminPdo->exec($grants);
            $output->writeln('<info>Database and user created.</info>');
        }

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName);
        $pdo = new PDO($dsn, $appUser, $appPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $pdo->exec($this->loadSql('sql/schema.sql'));
        $pdo->exec($this->loadSql('sql/seed.sql'));

        $output->writeln('<info>Schema and core seed applied.</info>');
        $output->writeln("<comment>DB user: {$appUser}</comment>");
        $output->writeln("<comment>DB pass: {$appPass}</comment>");

        $systemsCount = (int) $pdo->query('SELECT COUNT(*) FROM systems')->fetchColumn();
        if ($systemsCount === 0) {
            $output->writeln('<comment>Universe data not present (systems=0). Skipping chokepoint seed. Run: php bin/console sde:install then php bin/console seed:chokepoints (legacy: import:universe --file data/universe.json)</comment>');
        } else {
            $seeder = new ChokepointSeeder();
            $result = $seeder->seed($pdo, dirname(__DIR__, 2) . '/data/chokepoints.json');
            $output->writeln(sprintf('<info>Seeded %d chokepoints.</info>', $result['seeded']));
            if ($result['missing'] !== []) {
                $output->writeln('<comment>Missing systems: ' . implode(', ', $result['missing']) . '</comment>');
            }
        }

        $output->writeln('<comment>Next steps: run php bin/console sde:install to import the universe (preferred), then seed chokepoints and risk.</comment>');

        if ($input->getOption('write-env')) {
            $env = file_get_contents(dirname(__DIR__, 2) . '/.env.example');
            $env = preg_replace('/DB_HOST=.*/', 'DB_HOST=' . $dbHost, $env);
            $env = preg_replace('/DB_PORT=.*/', 'DB_PORT=' . $dbPort, $env);
            $env = preg_replace('/DB_NAME=.*/', 'DB_NAME=' . $dbName, $env);
            $env = preg_replace('/DB_USER=.*/', 'DB_USER=' . $appUser, $env);
            $env = preg_replace('/DB_PASS=.*/', 'DB_PASS=' . $appPass, $env);
            file_put_contents(dirname(__DIR__, 2) . '/.env', $env);
            $output->writeln('<info>.env written.</info>');
        }

        return Command::SUCCESS;
    }

    private function loadSql(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    }
}

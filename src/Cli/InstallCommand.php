<?php

declare(strict_types=1);

namespace Everoute\Cli;

use Everoute\Config\Env;
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
            ->addOption('admin-user', null, InputOption::VALUE_REQUIRED, 'Admin DB user')
            ->addOption('admin-pass', null, InputOption::VALUE_REQUIRED, 'Admin DB password')
            ->addOption('db-host', null, InputOption::VALUE_REQUIRED, 'DB host', Env::get('DB_HOST', '127.0.0.1'))
            ->addOption('db-port', null, InputOption::VALUE_REQUIRED, 'DB port', (string) Env::int('DB_PORT', 3306))
            ->addOption('db-name', null, InputOption::VALUE_REQUIRED, 'DB name', Env::get('DB_NAME', 'everoute'))
            ->addOption('app-user', null, InputOption::VALUE_REQUIRED, 'App DB user', Env::get('DB_USER', 'everoute_app'))
            ->addOption('app-pass', null, InputOption::VALUE_REQUIRED, 'App DB password')
            ->addOption('write-env', null, InputOption::VALUE_NONE, 'Write .env file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $schemaOnly = (bool) $input->getOption('schema-only');
        $dbHost = (string) $input->getOption('db-host');
        $dbPort = (int) $input->getOption('db-port');
        $dbName = (string) $input->getOption('db-name');
        $appUser = (string) $input->getOption('app-user');
        $appPass = (string) $input->getOption('app-pass');

        $helper = $this->getHelper('question');
        if ($appPass === '') {
            $question = new Question('App DB password (leave blank to generate): ');
            $appPass = (string) $helper->ask($input, $output, $question);
        }
        if ($appPass === '') {
            $appPass = bin2hex(random_bytes(8));
        }

        if (!$schemaOnly) {
            $adminUser = (string) $input->getOption('admin-user');
            $adminPass = (string) $input->getOption('admin-pass');
            if ($adminUser === '' || $adminPass === '') {
                $output->writeln('<error>Admin credentials are required for full install.</error>');
                return Command::FAILURE;
            }

            $adminDsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $dbHost, $dbPort);
            $adminPdo = new PDO($adminDsn, $adminUser, $adminPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            $dbNameSafe = str_replace('`', '``', $dbName);
            $appUserSafe = str_replace('`', '``', $appUser);
            $appPassSafe = $adminPdo->quote($appPass);
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

        $output->writeln('<info>Schema and seed applied.</info>');
        $output->writeln("<comment>DB user: {$appUser}</comment>");
        $output->writeln("<comment>DB pass: {$appPass}</comment>");

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

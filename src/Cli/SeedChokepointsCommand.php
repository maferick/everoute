<?php

declare(strict_types=1);

namespace Everoute\Cli;

use Everoute\Config\Env;
use Everoute\Risk\ChokepointSeeder;
use PDO;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

final class SeedChokepointsCommand extends Command
{
    protected static $defaultName = 'seed:chokepoints';

    protected function configure(): void
    {
        $this
            ->setName('seed:chokepoints')
            ->setDescription('Seed chokepoints from a name-based list')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Chokepoints seed file', 'data/chokepoints.json')
            ->addOption('db-host', null, InputOption::VALUE_REQUIRED, 'DB host', Env::get('DB_HOST', '127.0.0.1'))
            ->addOption('db-port', null, InputOption::VALUE_REQUIRED, 'DB port', (string) Env::int('DB_PORT', 3306))
            ->addOption('db-name', null, InputOption::VALUE_REQUIRED, 'DB name', Env::get('DB_NAME', 'everoute'))
            ->addOption('db-user', null, InputOption::VALUE_REQUIRED, 'DB user', Env::get('DB_USER', 'everoute_app'))
            ->addOption('db-pass', null, InputOption::VALUE_REQUIRED, 'DB password', Env::get('DB_PASS', ''));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dbPass = (string) $input->getOption('db-pass');
        if ($dbPass === '' && $input->isInteractive()) {
            $question = new Question('DB password: ');
            $dbPass = (string) $this->getHelper('question')->ask($input, $output, $question);
        }
        if ($dbPass === '') {
            $output->writeln('<error>DB_PASS missing. Provide via --db-pass or .env</error>');
            return Command::FAILURE;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            (string) $input->getOption('db-host'),
            (int) $input->getOption('db-port'),
            (string) $input->getOption('db-name')
        );

        $pdo = new PDO($dsn, (string) $input->getOption('db-user'), $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $path = (string) $input->getOption('file');
        $path = str_starts_with($path, '/')
            ? $path
            : dirname(__DIR__, 2) . '/' . ltrim($path, '/');

        $seeder = new ChokepointSeeder();
        try {
            $result = $seeder->seed($pdo, $path);
        } catch (RuntimeException $exception) {
            $output->writeln(sprintf('<error>%s</error>', $exception->getMessage()));
            return Command::FAILURE;
        }

        $output->writeln(sprintf('<info>Seeded %d chokepoints.</info>', $result['seeded']));
        if ($result['missing'] !== []) {
            $output->writeln('<comment>Missing systems: ' . implode(', ', $result['missing']) . '</comment>');
        }

        return Command::SUCCESS;
    }
}

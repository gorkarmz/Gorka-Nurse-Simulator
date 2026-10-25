<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(
    name: 'app:import-nurses',
    description: 'Imports nurses from nurses.json into the database'
)]
class ImportNursesCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly KernelInterface $kernel
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('truncate', null, InputOption::VALUE_NONE, 'Truncate nurse table before importing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $filePath = $this->kernel->getProjectDir() . '/data/nurses.json';
        if (!file_exists($filePath)) {
            $io->error(sprintf('File "%s" not found.', $filePath));
            return Command::FAILURE;
        }

        $jsonContent = file_get_contents($filePath);
        $nurses = json_decode($jsonContent, true);

        if (!is_array($nurses)) {
            $io->error('Invalid JSON format in nurses.json');
            return Command::FAILURE;
        }

        $truncate = $input->getOption('truncate');
        if ($truncate) {
            $io->comment('Truncating nurse table...');
            $this->connection->executeStatement('TRUNCATE TABLE nurse');
        }

        $io->progressStart(count($nurses));

        $this->connection->beginTransaction();
        try {
            $sql = 'INSERT INTO nurse (id, name, email, password) VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE name = VALUES(name), email = VALUES(email), password = VALUES(password)';
            $stmt = $this->connection->prepare($sql);

            $count = 0;
            foreach ($nurses as $nurse) {
                $stmt->bindValue(1, $nurse['id'] ?? null);
                $stmt->bindValue(2, $nurse['name'] ?? '');
                $stmt->bindValue(3, $nurse['email'] ?? '');
                $stmt->bindValue(4, $nurse['password'] ?? '');
                $stmt->executeStatement();
                $count++;
                $io->progressAdvance();
            }

            $this->connection->commit();
            $io->progressFinish();
            $io->success(sprintf('Successfully imported %d nurses into the database.', $count));
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            $io->error('Error during import: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}

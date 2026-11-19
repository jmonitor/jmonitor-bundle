<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Command;

use Jmonitor\JmonitorBundle\Storage\Dbal\DbalStorage;
use Jmonitor\JmonitorBundle\Storage\Dbal\SetupResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('jmonitor:setup', description: 'Create or upgrade the tables that store the application metrics')]
final class SetupCommand extends Command
{
    public function __construct(
        private readonly DbalStorage $storage,
        private readonly string $connectionName,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $result = $this->storage->setUp();
        } catch (\Throwable $e) {
            $io->error(sprintf('Could not set up the storage in the "%s" connection: %s', $this->connectionName, $e->getMessage()));

            return Command::FAILURE;
        }

        $io->success(sprintf(match ($result) {
            SetupResult::Created => 'Created the JMonitor tables in the "%s" connection.',
            SetupResult::Upgraded => 'Upgraded the JMonitor tables in the "%s" connection.',
            SetupResult::Unchanged => 'The JMonitor tables are up to date in the "%s" connection.',
        }, $this->connectionName));

        return Command::SUCCESS;
    }
}

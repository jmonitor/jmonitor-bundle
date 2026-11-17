<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Command;

use Jmonitor\JmonitorBundle\Storage\Dbal\AggregatesTable;
use Jmonitor\JmonitorBundle\Storage\Dbal\DbalStorage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('jmonitor:setup', description: 'Create the table that stores the application metrics')]
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
            $created = $this->storage->setUp();
        } catch (\Throwable $e) {
            $io->error(sprintf('Could not set up the storage in the "%s" connection: %s', $this->connectionName, $e->getMessage()));

            return Command::FAILURE;
        }

        $io->success(sprintf(
            $created ? 'Created the %s table in the "%s" connection.' : 'The %s table already exists in the "%s" connection.',
            AggregatesTable::NAME,
            $this->connectionName,
        ));

        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Fixtures\App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:work')]
final class WorkCommand extends Command
{
    public function __construct(private readonly ?Connection $connection = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('times', null, InputOption::VALUE_REQUIRED, 'Executions of the query', '1')
            ->addOption('fail', null, InputOption::VALUE_NONE, 'Throw once the work is done')
            ->addOption('exit', null, InputOption::VALUE_REQUIRED, 'Exit code', '0')
            ->addOption('then', null, InputOption::VALUE_REQUIRED, 'Command run from this one');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        for ($i = 0; $this->connection !== null && $i < (int) $input->getOption('times'); ++$i) {
            $this->connection->executeQuery('SELECT 1')->fetchOne();
        }

        $then = $input->getOption('then');
        if (\is_string($then) && $then !== '') {
            $this->getApplication()?->doRun(new ArrayInput(['command' => $then]), $output);
        }

        if ($input->getOption('fail')) {
            throw new \RuntimeException('boom');
        }

        return (int) $input->getOption('exit');
    }
}

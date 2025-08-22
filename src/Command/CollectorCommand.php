<?php

namespace Jmonitor\JmonitorBundle\Command;

use Jmonitor\Exceptions\JmonitorException;
use Jmonitor\Jmonitor;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('jmonitor:collect', description: 'Collect and send metrics to Jmonitor')]
class CollectorCommand extends Command
{
    /**
     * @var Jmonitor
     */
    private $jmonitor;
    /**
     * @var LoggerInterface|null
     */
    private $logger;

    public function __construct(Jmonitor $jmonitor, ?LoggerInterface $logger = null)
    {
        parent::__construct();

        $this->jmonitor = $jmonitor;
        $this->logger = $logger ?? new NullLogger();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->jmonitor->collect();
        } catch (JmonitorException $e) {
            $this->logger->error('Error while collecting metrics', [
                'exception' => $e,
            ]);

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}

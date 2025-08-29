<?php

namespace Jmonitor\JmonitorBundle\Command;

use Jmonitor\Jmonitor;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

// TODO tests
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
        $result = $this->jmonitor->collect(false);

        $this->logger->info($result->getConclusion());

        $this->logger->debug('Metrics collected', [
            'metrics' => $result->getMetrics(),
        ]);

        if ($result->getResponse()->getStatusCode() >= 400) {
            $this->logger->debug('Response error', [
                'body' => $result->getResponse()->getBody(),
                'code' => $result->getResponse()->getStatusCode(),
                'headers' => $result->getResponse()->getHeaders(),
            ]);
        }

        if ($result->getErrors()) {
            $this->logger->error('Errors', [
                'errors' => $result->getErrors(),
            ]);

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}

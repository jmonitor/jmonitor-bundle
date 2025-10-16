<?php

/*
 * This file is part of the jmonitor/jmonitor-bundle package.
 *
 * (c) Jonathan Plantey <jonathan.plantey@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Jmonitor\JmonitorBundle\Command;

use Jmonitor\Jmonitor;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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

    protected function configure(): void
    {
        $this->addArgument('collector', InputArgument::OPTIONAL, 'To run a specific collector');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not send metrics to Jmonitor');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($collector = $input->getArgument('collector')) {
            $jmonitor = $this->jmonitor->withCollector($collector);
        } else {
            $jmonitor = $this->jmonitor;
        }

        $result = $jmonitor->collect(!$input->getOption('dry-run'), false);

        $this->logger->debug('Metrics collected', [
            'metrics' => $result->getMetrics(),
        ]);

        if ($result->getResponse()?->getStatusCode() >= 400) {
            $this->logger->error('Response error', [
                'body' => $result->getResponse()->getBody()->getContents(),
                'code' => $result->getResponse()->getStatusCode(),
                'headers' => $result->getResponse()->getHeaders(),
            ]);
        }

        if ($result->getErrors()) {
            $this->logger->error('Errors', [
                'errors' => $result->getErrors(),
            ]);
        }

        $this->logger->info($result->getConclusion());

        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector;

use Jmonitor\JmonitorBundle\Stack\Collector\MissingPrerequisiteException;
use Jmonitor\JmonitorBundle\Stack\Collector\UnreachableServerException;
use Jmonitor\Utils\UrlFetcher;

/**
 * Answers each status page with its fixture, chosen by the path of the address, and fails as the real reader
 * does when the application answers instead or when nothing answers.
 */
final class FakeStatusPages extends UrlFetcher
{
    private const PAGES = [
        '/metrics' => 'FrankenPhp/metrics-worker.prom',
        '/server-status' => 'Apache/server-status.txt',
        '/nginx_status' => 'Nginx/stub_status.txt',
    ];

    public int $calls = 0;

    /** @var list<string> */
    public array $urls = [];

    private bool $application = false;
    private bool $silent = false;

    public function answerAsTheApplication(): void
    {
        $this->application = true;
    }

    public function stopAnswering(): void
    {
        $this->silent = true;
    }

    public function fetch(string $url): string
    {
        $this->calls++;
        $this->urls[] = $url;

        if ($this->silent) {
            throw new UnreachableServerException(sprintf('Nothing answered at %s: Connection refused', $url));
        }

        $page = self::PAGES[(string) parse_url($url, \PHP_URL_PATH)] ?? null;

        if ($this->application || $page === null) {
            throw new MissingPrerequisiteException(sprintf('%s answered HTTP 404: it is not a status page.', $url));
        }

        return (string) file_get_contents(\dirname(__DIR__) . '/' . $page);
    }
}

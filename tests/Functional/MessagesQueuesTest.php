<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Functional;

use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\TestKernel;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeProcessRunner;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

final class MessagesQueuesTest extends WebTestCase
{
    use PollsThePage;

    private const COUNTING = ['end_of_life' => false, 'symfony' => ['flex' => false, 'messenger' => true]];
    private const LISTED = '{"transports":["async","batch"]}';
    private const OUTPUT = self::LISTED . "\n" . '{"name":"async","count":12}' . "\n" . '{"name":"batch","count":null}' . "\n";
    private const RUNNER = 'jmonitor.tests.queues_runner';
    private const QUEUES = 'section#messages .jm-facts[aria-label="Queues"]';

    private string $bufferDir;

    /** @var list<string> */
    private static array $varDirs = [];

    protected function setUp(): void
    {
        $this->bufferDir = sys_get_temp_dir() . '/jmonitor-messages-queues-' . bin2hex(random_bytes(6));
        mkdir($this->bufferDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        (new Filesystem())->remove([$this->bufferDir, ...self::$varDirs]);
        self::$varDirs = [];
    }

    public function testThePageSaysReadingAndStartsNoProcess(): void
    {
        $client = $this->client();

        $crawler = $client->request('GET', '/jmonitor/background');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.jm-screen[data-jm-reading]'));
        self::assertCount(1, $crawler->filter('.jm-screen > section#messages > .jm-state[data-jm-reading]'));
        self::assertSame('Counting the queues…', $crawler->filter('section#messages .jm-state__title')->text());
        $rest = (string) $crawler->filter('a[data-jm-rest]')->attr('href');
        self::assertStringStartsWith('/jmonitor/background?', $rest);
        self::assertStringContainsString('period=1h', $rest);
        self::assertStringContainsString('rest=1', $rest);
        self::assertSame([], self::runner($client)->calls);
    }

    public function testTheRestKeepsThePeriodAndTheSort(): void
    {
        $client = $this->client();

        $crawler = $client->request('GET', '/jmonitor/background', ['period' => '6h', 'sort' => 'commands.runs', 'dir' => 'asc']);

        self::assertResponseIsSuccessful();
        $rest = parse_url((string) $crawler->filter('a[data-jm-rest]')->attr('href'));
        self::assertSame('/jmonitor/background', $rest['path'] ?? null);
        parse_str($rest['query'] ?? '', $query);
        self::assertSame(['period' => '6h', 'sort' => 'commands.runs', 'dir' => 'asc', 'rest' => '1'], $query);
        self::assertSame([], self::runner($client)->calls);
    }

    public function testTheFragmentCountsAndThePageThenShowsTheQueues(): void
    {
        $client = $this->client();
        $client->disableReboot();

        $client->request('GET', '/jmonitor/background');
        $fragment = $client->request('GET', '/jmonitor/_fragment/background?period=1h');
        self::assertResponseIsSuccessful();
        $page = $client->request('GET', '/jmonitor/background');

        foreach ([$fragment, $page] as $crawler) {
            self::assertSame([['async', '12'], ['batch', 'Uncountable']], self::facts($crawler));
            self::assertSame('this transport cannot count', $crawler->filter(self::QUEUES . ' .jm-fact__hint')->text());
            self::assertMatchesRegularExpression('/^Messages waiting in each transport, counted /', $crawler->filter(self::QUEUES . ' .jm-panel__note')->text());
        }
        self::assertCount(0, $page->filter('[data-jm-reading]'));
        self::assertCount(0, $page->filter('a[data-jm-rest]'));
        self::assertCount(1, self::runner($client)->calls);
    }

    public function testTheQueuesComeBeforeTheRecordedMessages(): void
    {
        $client = $this->client();

        $client->request('GET', '/dispatch', ['count' => '1']);
        self::assertResponseIsSuccessful();
        self::countTheQueues($client);
        $crawler = self::requestUntil($client, '/jmonitor/background', 'section#messages tr[data-jm-message][data-jm-sent="1"]');

        $blocks = $crawler->filter('section#messages > .jm-section__header ~ *')->each(static fn(Crawler $block): string => (string) $block->attr('class'));
        self::assertSame(['jm-panel jm-facts', 'jm-cards', 'jm-panel'], $blocks);
        self::assertSame('Queues', $crawler->filter('section#messages > .jm-facts .jm-panel__title')->text());
        self::assertCount(1, $crawler->filter('section#messages tr[data-jm-message]'));
    }

    public function testMessagesWithoutStorageStillShowsTheQueues(): void
    {
        $client = $this->client(['doctrine' => false]);

        self::countTheQueues($client);
        $crawler = $client->request('GET', '/jmonitor/background');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['No storage configured'], $crawler->filter('.jm-state__title')->each(static fn(Crawler $title): string => $title->text()));
        self::assertCount(1, $crawler->filter('.jm-screen > .jm-state'));
        self::assertSame([['async', '12'], ['batch', 'Uncountable']], self::facts($crawler));
        self::assertSame('Background', $crawler->filter('.jm-nav__item[aria-current="page"]')->text());

        $home = $client->request('GET', '/jmonitor/');
        self::assertResponseIsSuccessful();
        self::assertSame('JMonitor — Stack', $home->filter('title')->text());
    }

    public function testMessagesWithRecordingOffStillShowsTheQueues(): void
    {
        $client = $this->client(['jmonitor' => ['recording' => ['enabled' => false]]]);

        self::countTheQueues($client);
        $crawler = $client->request('GET', '/jmonitor/background');

        self::assertResponseIsSuccessful();
        self::assertSame(['Recording is off'], $crawler->filter('.jm-state__title')->each(static fn(Crawler $title): string => $title->text()));
        self::assertSame([['async', '12'], ['batch', 'Uncountable']], self::facts($crawler));
        self::assertCount(0, $crawler->filter('.jm-section__empty'));
    }

    public function testWithoutMessageRecordingOnlyTheQueuesShow(): void
    {
        $client = $this->client(['jmonitor' => ['recording' => ['messenger' => false]]]);

        self::countTheQueues($client);
        $crawler = $client->request('GET', '/jmonitor/background');

        self::assertResponseIsSuccessful();
        self::assertSame('Messages are not recorded.', $crawler->filter('section#messages .jm-section__empty strong')->text());
        self::assertSame(
            'Messages are not recorded. Recording the messages is off (jmonitor.recording.messenger). The queues are still counted.',
            $crawler->filter('section#messages .jm-section__empty')->text(),
        );
        self::assertSame([['async', '12'], ['batch', 'Uncountable']], self::facts($crawler));
        self::assertCount(0, $crawler->filter('section#messages .jm-cards'));
        self::assertCount(0, $crawler->filter('.jm-state'));
        self::assertSame('Background', $crawler->filter('.jm-nav__item[aria-current="page"]')->text());
    }

    public function testWithNeitherRecordingNorCountingThereIsNoMessagesScreen(): void
    {
        $client = $this->client(['jmonitor' => ['recording' => ['messenger' => false], 'stack' => ['symfony' => ['messenger' => false]]]]);

        $client->request('GET', '/jmonitor/messages');
        self::assertResponseStatusCodeSame(404);

        $crawler = $client->request('GET', '/jmonitor/background');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('section#messages'));
        self::assertSame(['commands'], $crawler->filter('.jm-screen > section')->each(static fn(Crawler $section): string => (string) $section->attr('id')));
    }

    public function testTheStackOffRemovesTheQueues(): void
    {
        $client = $this->client(['jmonitor' => ['stack' => ['enabled' => false]]]);

        $client->request('GET', '/jmonitor/_fragment/background');
        self::assertResponseIsSuccessful();
        $crawler = $client->request('GET', '/jmonitor/background');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.jm-facts'));
        self::assertCount(0, $crawler->filter('[data-jm-reading]'));
        self::assertSame('No message was sent or handled in the last hour.', $crawler->filter('section#messages .jm-section__empty strong')->text());
        self::assertFalse($client->getContainer()->has(self::RUNNER));
    }

    public function testATransportThatDidNotAnswerInTimeIsUncountable(): void
    {
        $client = $this->client();
        $client->disableReboot();
        self::runner($client)->answer(null, self::LISTED . "\n", null, '', true);

        $crawler = self::countTheQueues($client);

        self::assertResponseIsSuccessful();
        self::assertSame([['async', 'Uncountable'], ['batch', 'Uncountable']], self::facts($crawler));
        self::assertSame(
            ['did not answer in time', 'not counted: an earlier transport did not answer in time'],
            $crawler->filter(self::QUEUES . ' .jm-fact__hint')->each(static fn(Crawler $hint): string => $hint->text()),
        );
    }

    public function testAnOutputThatIsNotTheExpectedOneNeverBreaksTheScreen(): void
    {
        $client = $this->client();
        $client->disableReboot();
        self::runner($client)->answer(null, 'PHP Fatal error:  Allowed memory size exhausted', 255, 'Fatal error: Allowed memory size exhausted');

        $crawler = self::countTheQueues($client);

        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $crawler->filter('.jm-facts'));
        $notice = $crawler->filter('[data-jm-notice="warn"]');
        self::assertCount(1, $notice);
        self::assertStringContainsString('The queues could not be counted', $notice->text());
        self::assertStringContainsString('exit code: 255', $notice->text());
        self::assertStringContainsString('Turn the count off with jmonitor.stack.symfony.messenger: false.', $notice->text());
    }

    public function testATransportNameIsEscaped(): void
    {
        $output = json_encode(['transports' => ['<b>x']], \JSON_THROW_ON_ERROR) . "\n" . json_encode(['name' => '<b>x', 'count' => 3], \JSON_THROW_ON_ERROR) . "\n";
        $client = $this->client(['queuesOutput' => $output]);

        self::countTheQueues($client);
        $html = (string) $client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('&lt;b&gt;x', $html);
        self::assertStringNotContainsString('<b>x', $html);
        self::assertSame([['<b>x', '3']], self::facts(new Crawler($html)));
    }

    public function testTheQueuesAreTheSameWhateverThePeriod(): void
    {
        $client = $this->client();
        $client->disableReboot();
        self::countTheQueues($client);

        $seen = [];
        foreach (Period::cases() as $period) {
            $crawler = $client->request('GET', '/jmonitor/background', ['period' => $period->label()]);
            self::assertResponseIsSuccessful();
            $seen[$period->label()] = $crawler->filter(self::QUEUES . ' .jm-fact')->each(static fn(Crawler $fact): string => $fact->text());
        }

        self::assertSame([['async 12', 'batch Uncountable this transport cannot count']], array_values(array_unique($seen, \SORT_REGULAR)));
        self::assertCount(1, self::runner($client)->calls);
    }

    public function testABackgroundRequestIsNotRecorded(): void
    {
        $client = $this->client();

        for ($i = 0; $i < 3; ++$i) {
            $client->request('GET', '/jmonitor/background');
            self::assertResponseIsSuccessful();
        }
        $client->request('GET', '/jmonitor/_fragment/background');
        self::assertResponseIsSuccessful();
        $client->request('GET', '/hello/world');

        $requests = self::requestUntil($client, '/jmonitor/requests', 'tr[data-jm-route="GET app_hello"]');
        self::assertCount(1, $requests->filter('tr[data-jm-route="GET app_hello"]'));
        $routes = $requests->filter('tr[data-jm-route]')->each(static fn(Crawler $row): string => (string) $row->attr('data-jm-route'));
        self::assertSame([], preg_grep('/jmonitor/', $routes));
    }

    /**
     * @return list<array{string, string}> the label and the value of each transport
     */
    private static function facts(Crawler $crawler): array
    {
        return $crawler->filter(self::QUEUES . ' .jm-fact')->each(static fn(Crawler $fact): array => [
            $fact->filter('.jm-fact__label')->text(),
            $fact->filter('.jm-fact__value')->text(),
        ]);
    }

    private static function countTheQueues(KernelBrowser $client): Crawler
    {
        $client->request('GET', '/jmonitor/background');
        self::assertResponseIsSuccessful();
        $fragment = $client->request('GET', '/jmonitor/_fragment/background');
        self::assertResponseIsSuccessful();

        return $fragment;
    }

    private static function runner(KernelBrowser $client): FakeProcessRunner
    {
        $runner = $client->getContainer()->get(self::RUNNER);
        self::assertInstanceOf(FakeProcessRunner::class, $runner);

        return $runner;
    }

    /**
     * @param array{jmonitor?: array<string, mixed>, doctrine?: bool, queuesOutput?: string} $options
     */
    private function client(array $options = []): KernelBrowser
    {
        $options['jmonitor'] = array_replace_recursive(
            ['recording' => ['enabled' => true, 'flush_interval' => 1, 'buffer_dir' => $this->bufferDir], 'stack' => self::COUNTING],
            $options['jmonitor'] ?? [],
        );
        $options['queuesOutput'] ??= self::OUTPUT;

        return static::createClient($options);
    }

    /**
     * @param array{jmonitor?: array<string, mixed>, doctrine?: bool, queuesOutput?: string} $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        $kernel = new TestKernel(
            'test',
            true,
            $options['jmonitor'] ?? [],
            withDoctrine: $options['doctrine'] ?? true,
            withMessenger: true,
            queuesOutput: $options['queuesOutput'] ?? null,
        );
        self::$varDirs[] = $kernel->getVarDir();

        return $kernel;
    }
}

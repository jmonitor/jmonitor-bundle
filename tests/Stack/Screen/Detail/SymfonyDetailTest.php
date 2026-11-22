<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Screen\Detail;

use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\SymfonyDetail;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CollectorResult;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ResultState;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Snapshots;
use PHPUnit\Framework\TestCase;

final class SymfonyDetailTest extends TestCase
{
    use DescribesSections;

    private const OUTDATED = ['up_to_date' => false, 'outdated_recipes' => ['doctrine/doctrine-bundle', 'symfony/framework-bundle']];

    protected function tearDown(): void
    {
        restore_error_handler();
    }

    public function testSymfonyShowsItsApplicationItsOutdatedRecipesAndItsBundles(): void
    {
        $snapshot = Snapshots::of(Component::Symfony, [
            'symfony' => ['version' => '6.4.20'] + Snapshots::symfony(),
            'symfony.flex' => self::OUTDATED,
            'eol.symfony' => Snapshots::cycles(),
        ]);

        $blocks = (new SymfonyDetail())->sections(self::instance($snapshot), self::findings($snapshot), Snapshots::NOW);

        self::assertSame([
            ['facts', 'Application', ['Version' => '6.4.20 [Security fixes only]', 'Environment' => 'prod', 'Debug' => 'off']],
            ['facts', 'Flex', ['Flex recipes' => self::nb('2 outdated') . ' [Info]']],
            ['table', 'Outdated recipes', ['doctrine/doctrine-bundle', 'symfony/framework-bundle']],
            ['table', 'Bundles', ['FrameworkBundle', 'TwigBundle', 'JmonitorBundle']],
        ], self::describe($blocks));
    }

    public function testRecipesUpToDateAreAFact(): void
    {
        $snapshot = Snapshots::of(Component::Symfony, ['symfony' => Snapshots::symfony(), 'symfony.flex' => ['up_to_date' => true, 'outdated_recipes' => []]]);

        $blocks = (new SymfonyDetail())->sections(self::instance($snapshot), self::findings($snapshot), Snapshots::NOW);

        self::assertSame(['Application', 'Flex', 'Bundles'], self::titles($blocks));
        self::assertSame(['facts', 'Flex', ['Flex recipes' => self::nb('up to date')]], self::describe($blocks)[1]);
    }

    public function testWithoutTheRecipeCheckThereIsNoFlexSection(): void
    {
        $snapshot = Snapshots::of(Component::Symfony, ['symfony' => Snapshots::symfony()]);

        self::assertSame(['Application', 'Bundles'], self::titles((new SymfonyDetail())->sections(self::instance($snapshot), [], Snapshots::NOW)));
    }

    public function testDebugInProdIsMarked(): void
    {
        $snapshot = Snapshots::of(Component::Symfony, ['symfony' => ['debug' => true] + Snapshots::symfony(), 'eol.symfony' => Snapshots::cycles()]);

        $described = self::describe((new SymfonyDetail())->sections(self::instance($snapshot), self::findings($snapshot), Snapshots::NOW));

        self::assertSame(['Version' => '7.4.2', 'Environment' => 'prod', 'Debug' => 'on [Critical]'], $described[0][2]);
    }

    public function testAFailedRecipeCheckShowsItsReason(): void
    {
        $snapshot = Snapshots::of(Component::Symfony, ['symfony' => Snapshots::symfony()], ['symfony.flex' => ResultState::Failed]);

        $blocks = (new SymfonyDetail())->sections(self::instance($snapshot), self::findings($snapshot), Snapshots::NOW);

        self::assertSame(['Application', 'notice: The Flex recipes could not be checked', 'Bundles'], self::titles($blocks));
        self::assertSame(['notice', 'warn', 'The Flex recipes could not be checked', Snapshots::FAILURE], self::describe($blocks)[1]);
    }

    public function testASectionSaysReadingWhileItsCollectorWasNeverRead(): void
    {
        $flex = Snapshots::of(Component::Symfony, ['symfony' => Snapshots::symfony()], ['symfony.flex' => ResultState::Pending, 'eol.symfony' => ResultState::Busy]);

        self::assertSame(['Application', 'reading', 'Bundles'], self::titles((new SymfonyDetail())->sections(self::instance($flex), [], Snapshots::NOW)));
        self::assertSame(['Application', 'Bundles'], self::titles((new SymfonyDetail())->sections(self::instance($flex), [], Snapshots::NOW, false)));

        $nothing = Snapshots::of(Component::Symfony, [], ['symfony' => ResultState::Pending, 'symfony.flex' => ResultState::Pending]);

        self::assertSame([['reading', 'facts'], ['reading', 'facts']], self::describe((new SymfonyDetail())->sections(self::instance($nothing), [], Snapshots::NOW)));
        self::assertSame([], (new SymfonyDetail())->sections(self::instance($nothing), [], Snapshots::NOW, false));
    }

    public function testAnOutdatedValueSaysItsAge(): void
    {
        $snapshot = Snapshots::of(Component::Symfony, ['symfony' => Snapshots::symfony(), 'symfony.flex' => self::OUTDATED], ['symfony.flex' => ResultState::Stale]);

        $blocks = (new SymfonyDetail())->sections(self::instance($snapshot), [], Snapshots::NOW);

        self::assertSame(['Application', 'notice: Read ' . self::nb('1 h') . ' ago', 'Flex', 'Outdated recipes', 'Bundles'], self::titles($blocks));
        self::assertSame('info', self::describe($blocks)[1][1]);
    }

    public function testAFailedRenewalSaysWhyAndWhenItIsRetried(): void
    {
        $snapshot = new ComponentSnapshot(Component::Symfony, [
            'symfony' => new CollectorResult('symfony', Snapshots::symfony(), ResultState::Fresh, collectedAt: Snapshots::NOW, expiresAt: Snapshots::NOW + 60),
            'symfony.flex' => new CollectorResult('symfony.flex', self::OUTDATED, ResultState::Stale, 'composer is not installed', Snapshots::NOW - 86_400, expiresAt: Snapshots::NOW + 3 * 3_600),
        ]);

        $blocks = (new SymfonyDetail())->sections(self::instance($snapshot), [], Snapshots::NOW);

        self::assertSame(['notice', 'warn', 'Could not be read again: composer is not installed', 'next try in ' . self::nb('3 h')], self::describe($blocks)[1]);
        self::assertSame(['Flex', 'Outdated recipes', 'Bundles'], array_slice(self::titles($blocks), 2));
    }

    public function testAnEmptySnapshotRendersWithoutWarning(): void
    {
        self::failOnWarnings();

        $empty = (new SymfonyDetail())->sections(self::instance(Snapshots::of(Component::Symfony, ['symfony' => [], 'symfony.flex' => []])), [], Snapshots::NOW);
        $odd = (new SymfonyDetail())->sections(self::instance(Snapshots::of(Component::Symfony, [
            'symfony' => ['version' => [7], 'env' => null, 'debug' => 'maybe', 'bundles' => ['FrameworkBundle', 3, ['x']]],
            'symfony.flex' => ['up_to_date' => 'perhaps', 'outdated_recipes' => 'all'],
            'eol.symfony' => ['cycles' => [7]],
        ])), [], Snapshots::NOW);

        self::assertSame([
            ['facts', 'Application', ['Version' => '—', 'Environment' => '—', 'Debug' => '—']],
            ['facts', 'Flex', ['Flex recipes' => '—']],
        ], self::describe($empty));
        self::assertSame([
            ['facts', 'Application', ['Version' => '—', 'Environment' => '—', 'Debug' => '—']],
            ['facts', 'Flex', ['Flex recipes' => '—']],
            ['table', 'Bundles', ['FrameworkBundle']],
        ], self::describe($odd));
    }
}

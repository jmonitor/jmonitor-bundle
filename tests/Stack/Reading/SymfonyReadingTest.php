<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Reading;

use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Metric\SupportStatus;
use Jmonitor\JmonitorBundle\Stack\Reading\SymfonyReading;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ResultState;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Snapshots;
use PHPUnit\Framework\TestCase;

final class SymfonyReadingTest extends TestCase
{
    public function testValuesOfACompleteSnapshot(): void
    {
        $reading = SymfonyReading::of(Snapshots::of(Component::Symfony, [
            'symfony' => Snapshots::symfony(),
            'symfony.flex' => ['up_to_date' => false, 'outdated_recipes' => ['symfony/framework-bundle', 'symfony/twig-bundle']],
            'eol.symfony' => Snapshots::cycles(),
        ]), self::today());

        self::assertSame('7.4.2', $reading->version);
        self::assertSame(SupportStatus::Active, $reading->support);
        self::assertSame('prod', $reading->environment);
        self::assertFalse($reading->debug);
        self::assertSame(['FrameworkBundle', 'TwigBundle', 'JmonitorBundle'], $reading->bundles);
        self::assertFalse($reading->recipesUpToDate);
        self::assertSame(['symfony/framework-bundle', 'symfony/twig-bundle'], $reading->outdatedRecipes);
    }

    public function testUpToDateRecipes(): void
    {
        $reading = SymfonyReading::of(Snapshots::of(Component::Symfony, [
            'symfony' => Snapshots::symfony(),
            'symfony.flex' => ['up_to_date' => true, 'outdated_recipes' => []],
        ]), self::today());

        self::assertTrue($reading->recipesUpToDate);
        self::assertSame([], $reading->outdatedRecipes);
        self::assertNull($reading->support, 'no release cycles read');
    }

    public function testRecipesAreUnknownUntilRead(): void
    {
        foreach ([ResultState::Pending, ResultState::Failed] as $state) {
            $reading = SymfonyReading::of(Snapshots::of(Component::Symfony, ['symfony' => Snapshots::symfony()], ['symfony.flex' => $state]), self::today());

            self::assertNull($reading->recipesUpToDate);
            self::assertSame([], $reading->outdatedRecipes);
        }

        self::assertNull(SymfonyReading::of(Snapshots::of(Component::Symfony, ['symfony' => Snapshots::symfony()]), self::today())->recipesUpToDate, 'turned off');
    }

    public function testAnEmptySnapshotGivesNulls(): void
    {
        foreach ([new ComponentSnapshot(Component::Symfony, []), Snapshots::of(Component::Symfony, [], ['symfony' => ResultState::Failed])] as $snapshot) {
            $reading = SymfonyReading::of($snapshot, self::today());

            self::assertNull($reading->version);
            self::assertNull($reading->support);
            self::assertNull($reading->environment);
            self::assertNull($reading->debug);
            self::assertSame([], $reading->bundles);
            self::assertNull($reading->recipesUpToDate);
            self::assertSame([], $reading->outdatedRecipes);
        }
    }

    public function testDataOfUnknownShapeGivesNullsWithoutWarning(): void
    {
        set_error_handler(static fn(int $level, string $message): never => throw new \ErrorException($message, 0, $level));

        try {
            $reading = SymfonyReading::of(Snapshots::of(Component::Symfony, [
                'symfony' => ['env' => [], 'debug' => 'maybe', 'version' => ['7.4'], 'bundles' => 'FrameworkBundle'],
                'symfony.flex' => ['up_to_date' => 'perhaps', 'outdated_recipes' => 'symfony/framework-bundle'],
                'eol.symfony' => ['cycles' => ['7.4' => 'soon']],
            ]), self::today());
        } finally {
            restore_error_handler();
        }

        self::assertNull($reading->version);
        self::assertNull($reading->support);
        self::assertNull($reading->environment);
        self::assertNull($reading->debug);
        self::assertSame([], $reading->bundles);
        self::assertNull($reading->recipesUpToDate);
        self::assertSame([], $reading->outdatedRecipes);
    }

    private static function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@' . Snapshots::NOW);
    }
}

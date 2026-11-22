<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Screen\Detail;

use Jmonitor\JmonitorBundle\Dashboard\View\Block;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use Jmonitor\JmonitorBundle\Dashboard\View\Column;
use Jmonitor\JmonitorBundle\Dashboard\View\Fact;
use Jmonitor\JmonitorBundle\Dashboard\View\Facts;
use Jmonitor\JmonitorBundle\Dashboard\View\Row;
use Jmonitor\JmonitorBundle\Dashboard\View\Table;
use Jmonitor\JmonitorBundle\Stack\Check\Finding;
use Jmonitor\JmonitorBundle\Stack\Read\InstanceState;
use Jmonitor\JmonitorBundle\Stack\Reading\SymfonyReading;
use Jmonitor\JmonitorBundle\Stack\Screen\Marks;

final class SymfonyDetail implements DetailInterface
{
    private const FLEX = 'symfony.flex';

    public function sections(InstanceState $instance, array $findings, int $now, bool $reading = true): array
    {
        $snapshot = Sections::snapshot($instance);

        if ($snapshot === null) {
            return [];
        }

        $symfony = SymfonyReading::of($snapshot, new \DateTimeImmutable('@' . $now));
        $blocks = Sections::of($snapshot, 'symfony', Block::FACTS, $now, $reading, static fn(): array => [
            Block::facts(new Facts('Application', [
                new Fact('Version', Sections::value($symfony->version), $symfony->support === null ? null : Marks::support($symfony->support)),
                new Fact('Environment', Sections::value($symfony->environment)),
                new Fact('Debug', match ($symfony->debug) {
                    true => 'on',
                    false => 'off',
                    null => Sections::MISSING,
                }, Sections::mark($findings, 'symfony.debug_in_prod')),
            ])),
            ...($symfony->bundles === [] ? [] : [Block::table(self::names('bundles', 'Bundles', 'bundle', $symfony->bundles))]),
        ]);

        if ($snapshot->result(self::FLEX) !== null) {
            array_push($blocks, ...Sections::of(
                $snapshot,
                self::FLEX,
                Block::FACTS,
                $now,
                $reading,
                static fn(): array => self::recipes($symfony, $findings),
                'The Flex recipes could not be checked',
            ));
        }

        return $blocks;
    }

    /**
     * @param list<Finding> $findings
     *
     * @return list<Block>
     */
    private static function recipes(SymfonyReading $symfony, array $findings): array
    {
        $outdated = count($symfony->outdatedRecipes);
        $recipes = Block::facts(new Facts('Flex', [new Fact('Flex recipes', match (true) {
            $outdated > 0 => Sections::unbroken($outdated . ' outdated'),
            $symfony->recipesUpToDate === true => 'up' . Sections::NBSP . 'to' . Sections::NBSP . 'date',
            default => Sections::MISSING,
        }, Sections::mark($findings, 'symfony.flex_recipes'))]));

        return $outdated === 0 ? [$recipes] : [$recipes, Block::table(self::names('outdated-recipes', 'Outdated recipes', 'recipe', $symfony->outdatedRecipes))];
    }

    /**
     * @param list<string> $names
     */
    private static function names(string $id, string $title, string $noun, array $names): Table
    {
        return new Table(
            $id,
            $title,
            [new Column($noun, ucfirst($noun), false)],
            array_map(static fn(string $name): Row => new Row($name, [$noun => new Cell($name)]), $names),
            noun: $noun,
        );
    }
}

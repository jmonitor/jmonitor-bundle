<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\Check;

use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\View\Alert;
use Jmonitor\JmonitorBundle\Dashboard\View\CheckLine;
use Jmonitor\JmonitorBundle\Dashboard\View\Mark;
use Jmonitor\JmonitorBundle\Storage\ReaderInterface;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;

final class ApplicationChecks
{
    /** @var array<string, CheckInterface> by id */
    private readonly array $checks;

    /** @param iterable<CheckInterface> $checks */
    public function __construct(iterable $checks)
    {
        $byId = [];

        foreach ($checks as $check) {
            $byId[$check->id()] = $check;
        }

        $this->checks = $byId;
    }

    /** the read that names the first culprit of a check, null when no check has this id */
    public function query(string $id, Period $period, int $now): ?ReportQuery
    {
        return isset($this->checks[$id]) ? $this->checks[$id]->query($period, $now) : null;
    }

    /** null when no check has this id */
    public function severity(string $id): ?Severity
    {
        return isset($this->checks[$id]) ? $this->checks[$id]->severity() : null;
    }

    /**
     * @param list<string> $more metric types read in the same statement, whose totals the result gives too
     */
    public function raised(ReaderInterface $reader, Period $period, int $now, array $more = []): RaisedChecks
    {
        $metrics = [];
        $keyed = [];

        foreach ($this->checks as $check) {
            array_push($metrics, ...$check->metrics());

            if ($check instanceof KeyedCheckInterface) {
                array_push($keyed, ...$check->metrics());
            }
        }

        array_push($metrics, ...$more);

        if ($metrics === []) {
            return new RaisedChecks([], [], $period);
        }

        $read = $reader->totals($period, $now, array_values(array_unique($metrics)), array_values(array_unique($keyed)));
        $totals = $read->types;
        $raised = [];

        foreach ($this->checks as $id => $check) {
            if ($check instanceof KeyedCheckInterface) {
                if ($check->raisedBy(array_intersect_key($read->keys, array_flip($check->metrics())))) {
                    $raised[$id] = $check;
                }

                continue;
            }

            foreach ($check->metrics() as $metric) {
                if (($totals[$metric]->count ?? 0) > 0) {
                    $raised[$id] = $check;

                    break;
                }
            }
        }

        return new RaisedChecks($raised, $totals, $period);
    }

    /**
     * @param list<string>          $ids     the checks wanted
     * @param array<string, Report> $reports check id => a report that already answers its query
     *
     * @return list<Finding> the most severe first, then in the order of $ids
     */
    public function findings(ReaderInterface $reader, Period $period, int $now, RaisedChecks $raised, array $ids, array $reports = []): array
    {
        $findings = [];

        foreach (array_values(array_unique($ids)) as $id) {
            $check = $this->checks[$id] ?? null;

            if ($check === null || !$raised->has($id)) {
                continue;
            }

            $report = $reports[$id] ?? $reader->read($check->query($period, $now));
            $findings[] = $check->finding($report, $raised->totals($id), $period);
        }

        $critical = array_values(array_filter($findings, static fn(Finding $finding): bool => $finding->severity === Severity::Critical));
        $others = array_values(array_filter($findings, static fn(Finding $finding): bool => $finding->severity !== Severity::Critical));

        return [...$critical, ...$others];
    }

    /**
     * @param list<string> $ids the checks wanted
     *
     * @return list<Alert> one per raised check, the critical ones first, then in the order of $ids
     */
    public function alerts(RaisedChecks $raised, array $ids, Period $period): array
    {
        $critical = [];
        $others = [];

        foreach (array_values(array_unique($ids)) as $id) {
            $check = $this->checks[$id] ?? null;

            if ($check === null || !$raised->has($id)) {
                continue;
            }

            $alert = new Alert(
                $id,
                self::mark($check->severity()),
                $check->headline($raised->totals($id)),
                $check->screenLabel(),
                $check->screen(),
                ['period' => $period->label()],
            );

            if ($check->severity() === Severity::Critical) {
                $critical[] = $alert;
            } else {
                $others[] = $alert;
            }
        }

        return [...$critical, ...$others];
    }

    /**
     * @param list<Finding> $findings
     *
     * @return list<CheckLine>
     */
    public function lines(array $findings, string $screen, Period $period): array
    {
        return array_map(function (Finding $finding) use ($screen, $period): CheckLine {
            $elsewhere = $finding->screen !== $screen;

            return new CheckLine(
                $finding->id,
                self::mark($finding->severity),
                $finding->sentence,
                $finding->why,
                $elsewhere ? $this->screenLabel($finding) : '',
                $elsewhere ? $finding->screen : null,
                null,
                $elsewhere ? ['period' => $period->label()] : [],
                $finding->title,
                $finding->action,
                $finding->code,
            );
        }, $findings);
    }

    private static function mark(Severity $severity): Mark
    {
        return new Mark($severity->tone(), $severity->label());
    }

    private function screenLabel(Finding $finding): string
    {
        return isset($this->checks[$finding->id]) ? $this->checks[$finding->id]->screenLabel() : $finding->screen;
    }
}

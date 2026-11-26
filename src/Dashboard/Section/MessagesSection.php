<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\Section;

use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Percentile;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\BlockSourceInterface;
use Jmonitor\JmonitorBundle\Dashboard\Check\ApplicationChecks;
use Jmonitor\JmonitorBundle\Dashboard\Check\RaisedChecks;
use Jmonitor\JmonitorBundle\Dashboard\Format;
use Jmonitor\JmonitorBundle\Dashboard\ScreenRequest;
use Jmonitor\JmonitorBundle\Dashboard\View\Brief;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use Jmonitor\JmonitorBundle\Dashboard\View\CheckList;
use Jmonitor\JmonitorBundle\Dashboard\View\Column;
use Jmonitor\JmonitorBundle\Dashboard\View\Guide;
use Jmonitor\JmonitorBundle\Dashboard\View\GuideItem;
use Jmonitor\JmonitorBundle\Dashboard\View\Row;
use Jmonitor\JmonitorBundle\Dashboard\View\ScreenView;
use Jmonitor\JmonitorBundle\Dashboard\View\Table;
use Jmonitor\JmonitorBundle\Dashboard\View\Tag;
use Jmonitor\JmonitorBundle\Storage\ReaderInterface;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;

/**
 * Duration figures are -1 for a class that was only sent, which sorts it below every handled one.
 *
 * @phpstan-type Message array{row: ReportRow, sent: int, handled: int, avg: float, p95: float, max: float, failed: int, retried: int}
 */
final class MessagesSection implements SectionInterface, BlockSourceInterface
{
    private const LIMIT = 100;
    private const NONE = '—';
    private const SCREEN = 'background';
    private const CHECK = 'messages.failed';

    /**
     * @param bool                  $messenger whether Messenger is enabled
     * @param ?BlockSourceInterface $queues    null when the queues are not counted
     */
    public function __construct(
        private readonly bool $messenger,
        private readonly bool $recorded,
        private readonly ?BlockSourceInterface $queues,
        private readonly ApplicationChecks $checks,
    ) {}

    public function id(): string
    {
        return 'messages';
    }

    public function title(): string
    {
        return 'Messages';
    }

    public function summary(): string
    {
        return 'Work your code hands to Messenger to do later.';
    }

    public function isAvailable(): bool
    {
        return $this->messenger && ($this->recorded || $this->queues !== null);
    }

    public function recordsMessages(): bool
    {
        return $this->messenger && $this->recorded;
    }

    public function sorts(): array
    {
        return [
            'handled' => 'desc',
            'message' => 'asc',
            'sent' => 'desc',
            'avg' => 'desc',
            'p95' => 'desc',
            'max' => 'desc',
            'problems' => 'desc',
        ];
    }

    public function emptyState(Period $period): array
    {
        if (!$this->recorded) {
            return [
                'title' => 'Messages are not recorded.',
                'text' => 'Recording the messages is off (jmonitor.recording.messenger). The queues are still counted.',
            ];
        }

        return [
            'title' => 'No message was sent or handled in the ' . lcfirst(Format::period($period)) . '.',
            'text' => 'Messages sent to a transport and messages handled by a worker show up here. Messages handled synchronously are part of their request.',
        ];
    }

    public function blocks(ScreenRequest $request): array
    {
        return $this->queues?->blocks($request) ?? [];
    }

    public function build(ReaderInterface $reader, Period $period, int $now, string $sort, string $direction, RaisedChecks $raised): ScreenView
    {
        if (!$this->recorded) {
            return new ScreenView();
        }

        $report = $reader->read(new ReportQuery($period, $now, Family::Message));
        $all = [...$report->rows, ...($report->other === null ? [] : [$report->other])];

        $messages = self::sorted(array_map(self::message(...), $report->rows), $sort, $direction);
        $rows = array_map(self::row(...), \array_slice($messages, 0, self::LIMIT));
        if ($report->other !== null) {
            $rows[] = self::row(self::message($report->other), true);
        }

        $table = new Table('messages', 'Messages', [
            new Column('message', 'Message', false, 'message'),
            new Column('sent', 'Sent', sort: 'sent'),
            new Column('handled', 'Handled', sort: 'handled'),
            new Column('avg', 'Avg', sort: 'avg'),
            new Column('p95', 'p95', sort: 'p95'),
            new Column('max', 'Max', sort: 'max'),
            new Column('split', 'Where the average goes', false),
            new Column('problems', 'Problems', false, 'problems'),
        ], $rows, max(0, \count($messages) - self::LIMIT), 'message', partsLegend: true);

        if ($rows === []) {
            return new ScreenView([], [$table]);
        }

        $findings = $this->checks->findings($reader, $period, $now, $raised, [self::CHECK], [self::CHECK => $report]);
        $guide = [
            new GuideItem('Sent and handled.', 'Sent is put in the queue, handled is done. When sent stays above handled, the workers do not keep up and the queue grows.'),
        ];
        if ($this->queues !== null) {
            $guide[] = new GuideItem('Queues.', 'The messages waiting in each transport at this moment, whatever the period. A number that never comes down means no worker is running.');
        }

        return new ScreenView(
            [],
            [$table],
            checks: new CheckList($this->checks->lines($findings, self::SCREEN, $period), emptyText: 'no failed message'),
            guide: new Guide($guide),
            brief: self::brief($all),
        );
    }

    /** @param list<ReportRow> $rows every row of the period, the other messages included */
    private static function brief(array $rows): ?Brief
    {
        $handled = 0;
        $sent = 0;
        $failed = 0;
        foreach ($rows as $row) {
            $handled += $row->count(Metric::MESSAGE_DURATION);
            $sent += $row->count(Metric::MESSAGE_SENT);
            $failed += $row->count(Metric::MESSAGE_FAILED);
        }

        if ($handled === 0 && $sent === 0) {
            return null;
        }

        $failures = $failed > 0 ? ', ' . Format::number($failed) . ' failed' : '';

        return new Brief(
            Format::number($handled > 0 ? $handled : $sent),
            $handled > 0 ? self::counted($handled, 'message', 'handled') : self::counted($sent, 'message', 'sent'),
            ($handled > 0 ? 'handled, ' . Format::number($sent) . ' sent' : 'sent') . $failures,
            match ($failed) {
                0 => '',
                1 => '1 message failed.',
                default => Format::number($failed) . ' messages failed.',
            },
            $failed > 0 ? 'bad' : null,
        );
    }

    private static function counted(int $count, string $noun, string $verb): string
    {
        return ($count === 1 ? '1 ' . $noun : Format::number($count) . ' ' . $noun . 's') . ' ' . $verb;
    }

    /** @return Message */
    private static function message(ReportRow $row): array
    {
        $handled = $row->count(Metric::MESSAGE_DURATION);
        $max = $row->max(Metric::MESSAGE_DURATION);

        return [
            'row' => $row,
            'sent' => $row->count(Metric::MESSAGE_SENT),
            'handled' => $handled,
            'avg' => $handled === 0 ? -1.0 : $row->sum(Metric::MESSAGE_DURATION) / $handled,
            'p95' => $handled === 0 ? -1.0 : Percentile::estimate($row->countsByPrefix(Metric::MESSAGE_DURATION_LE_PREFIX), 0.95, $max),
            'max' => $handled === 0 ? -1.0 : $max,
            'failed' => $row->count(Metric::MESSAGE_FAILED),
            'retried' => $row->count(Metric::MESSAGE_RETRIED),
        ];
    }

    /** @param Message $message */
    private static function row(array $message, bool $other = false): Row
    {
        $handled = $message['handled'] > 0;
        $duration = static fn(float $ms): Cell => $handled ? new Cell(Format::duration($ms)) : new Cell(self::NONE, 'zero');

        return new Row($message['row']->hash, [
            'message' => $other ? new Cell('Other messages') : new Cell($message['row']->key, kind: 'code'),
            'sent' => new Cell(Format::number($message['sent'])),
            'handled' => new Cell(Format::number($message['handled'])),
            'avg' => $duration($message['avg']),
            'p95' => $duration($message['p95']),
            'max' => $duration($message['max']),
            'split' => $handled ? self::split($message['row'], $message['handled'], $message['avg']) : new Cell(''),
            'problems' => Cell::tags(self::tags($message)),
        ], [
            'data-jm-message' => $message['row']->key,
            'data-jm-handled' => (string) $message['handled'],
            'data-jm-sent' => (string) $message['sent'],
        ], $other);
    }

    private static function split(ReportRow $row, int $handled, float $average): Cell
    {
        return Cell::averageSplit(
            $average,
            $row->count(Metric::MESSAGE_SQL_TIME) > 0 ? $row->sum(Metric::MESSAGE_SQL_TIME) / $handled : null,
            $row->sum(Metric::MESSAGE_SQL_COUNT) / $handled,
            $row->count(Metric::MESSAGE_HTTP_TIME) > 0 ? $row->sum(Metric::MESSAGE_HTTP_TIME) / $handled : null,
        );
    }

    /**
     * @param Message $message
     *
     * @return list<Tag>
     */
    private static function tags(array $message): array
    {
        $tags = [];

        if ($message['failed'] > 0) {
            $tags[] = new Tag(Format::number($message['failed']) . ' failed', 'bad');
        }

        if ($message['retried'] > 0) {
            $tags[] = new Tag(Format::number($message['retried']) . ' retried', 'warn');
        }

        return $tags;
    }

    /**
     * @param list<Message> $messages
     *
     * @return list<Message>
     */
    private static function sorted(array $messages, string $sort, string $direction): array
    {
        $sign = $direction === 'asc' ? 1 : -1;

        usort($messages, static fn(array $a, array $b): int => $sign * match ($sort) {
            'message' => strcmp($a['row']->key, $b['row']->key),
            'sent', 'avg', 'p95', 'max' => $a[$sort] <=> $b[$sort],
            'problems' => [$a['failed'], $a['retried']] <=> [$b['failed'], $b['retried']],
            default => $a['handled'] <=> $b['handled'],
        } ?: strcmp($a['row']->key, $b['row']->key));

        return $messages;
    }
}

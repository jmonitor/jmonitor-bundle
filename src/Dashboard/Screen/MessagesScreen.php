<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\Screen;

use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Percentile;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\BlockScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\BlockSourceInterface;
use Jmonitor\JmonitorBundle\Dashboard\Format;
use Jmonitor\JmonitorBundle\Dashboard\ScreenRequest;
use Jmonitor\JmonitorBundle\Dashboard\StorageScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\View\Card;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use Jmonitor\JmonitorBundle\Dashboard\View\Column;
use Jmonitor\JmonitorBundle\Dashboard\View\Row;
use Jmonitor\JmonitorBundle\Dashboard\View\ScreenView;
use Jmonitor\JmonitorBundle\Dashboard\View\Table;
use Jmonitor\JmonitorBundle\Storage\ReaderInterface;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;

/**
 * Duration figures are -1 for a class that was only sent, which sorts it below every handled one.
 *
 * @phpstan-type Message array{row: ReportRow, sent: int, handled: int, avg: float, p95: float, max: float, failed: int, retried: int, sql: float, queries: float, hasSql: bool}
 */
final class MessagesScreen implements StorageScreenInterface, BlockScreenInterface
{
    private const LIMIT = 100;
    private const NONE = '—';

    /**
     * @param bool                  $messenger whether Messenger is enabled
     * @param ?BlockSourceInterface $queues    null when the queues are not counted
     */
    public function __construct(
        private readonly bool $messenger,
        private readonly bool $recorded,
        private readonly ?BlockSourceInterface $queues = null,
    ) {}

    public function id(): string
    {
        return 'messages';
    }

    public function label(): string
    {
        return 'Messages';
    }

    public function heading(): string
    {
        return 'Messages sent and handled per class';
    }

    public function isAvailable(): bool
    {
        return $this->messenger && ($this->recorded || $this->queues !== null);
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
            'failed' => 'desc',
            'retried' => 'desc',
            'sql' => 'desc',
        ];
    }

    public function emptyState(): array
    {
        if (!$this->recorded) {
            return [
                'title' => 'Messages are not recorded',
                'text' => 'Recording the messages is off (jmonitor.recording.messenger). The queues are still counted.',
            ];
        }

        return [
            'title' => 'No messages recorded yet',
            'text' => 'Messages sent to a transport and messages handled by a worker show up here. Messages handled synchronously are part of their request.',
        ];
    }

    public function blocks(ScreenRequest $request): array
    {
        return $this->queues?->blocks($request) ?? [];
    }

    public function build(ReaderInterface $reader, Period $period, int $now, string $sort, string $direction): ScreenView
    {
        if (!$this->recorded) {
            return new ScreenView();
        }

        $report = $reader->read(new ReportQuery($period, $now, Family::Message, seriesMetric: Metric::MESSAGE_DURATION));

        $messages = self::sorted(array_map(self::message(...), $report->rows), $sort, $direction);
        $rows = array_map(self::row(...), \array_slice($messages, 0, self::LIMIT));
        if ($report->other !== null) {
            $rows[] = self::row(self::message($report->other), true);
        }

        return new ScreenView(self::cards($report, $period), [new Table('messages', 'Messages', [
            new Column('message', 'Message', false, 'message'),
            new Column('sent', 'Sent', sort: 'sent'),
            new Column('handled', 'Handled', sort: 'handled'),
            new Column('avg', 'Avg', sort: 'avg'),
            new Column('p95', 'p95', sort: 'p95'),
            new Column('max', 'Max', sort: 'max'),
            new Column('failed', 'Failed', sort: 'failed'),
            new Column('retried', 'Retried', sort: 'retried'),
            new Column('sql', 'SQL', sort: 'sql'),
        ], $rows, max(0, \count($messages) - self::LIMIT), 'message')]);
    }

    /** @return list<Card> */
    private static function cards(Report $report, Period $period): array
    {
        $handled = 0;
        $sent = 0;
        $failed = 0;
        $max = 0.0;
        $counts = [];
        foreach ([...$report->rows, ...($report->other === null ? [] : [$report->other])] as $row) {
            $handled += $row->count(Metric::MESSAGE_DURATION);
            $sent += $row->count(Metric::MESSAGE_SENT);
            $failed += $row->count(Metric::MESSAGE_FAILED);
            $max = max($max, $row->max(Metric::MESSAGE_DURATION));
            foreach ($row->countsByPrefix(Metric::MESSAGE_DURATION_LE_PREFIX) as $bound => $count) {
                $counts[$bound] = ($counts[$bound] ?? 0) + $count;
            }
        }

        return [
            new Card('Handled', Format::number($handled), Format::period($period), series: $report->series, wide: true),
            new Card('Sent', Format::number($sent), 'Sent to a transport'),
            new Card('Failed', Format::number($failed), 'Handled with an exception', $failed > 0 ? 'bad' : null),
            new Card('p95', $handled === 0 ? self::NONE : Format::duration(Percentile::estimate($counts, 0.95, $max)), '95 % of messages are handled faster'),
        ];
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
            'sql' => $handled === 0 ? -1.0 : $row->sum(Metric::MESSAGE_SQL_TIME) / $handled,
            'queries' => $handled === 0 ? 0.0 : $row->sum(Metric::MESSAGE_SQL_COUNT) / $handled,
            'hasSql' => $handled > 0 && $row->count(Metric::MESSAGE_SQL_TIME) > 0,
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
            'failed' => new Cell(Format::number($message['failed']), $message['failed'] > 0 ? 'bad' : 'zero'),
            'retried' => new Cell(Format::number($message['retried']), $message['retried'] > 0 ? 'warn' : 'zero'),
            'sql' => $message['hasSql'] ? new Cell(Format::sql($message['sql'], $message['queries'])) : new Cell(self::NONE, 'zero'),
        ], [
            'data-jm-message' => $message['row']->key,
            'data-jm-handled' => (string) $message['handled'],
            'data-jm-sent' => (string) $message['sent'],
        ], $other);
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
            'sent', 'avg', 'p95', 'max', 'failed', 'retried', 'sql' => $a[$sort] <=> $b[$sort],
            default => $a['handled'] <=> $b['handled'],
        } ?: strcmp($a['row']->key, $b['row']->key));

        return $messages;
    }
}

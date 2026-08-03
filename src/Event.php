<?php

declare(strict_types=1);

namespace Mattmy\ICalendar;

use Carbon\CarbonImmutable;
use Sabre\VObject\Component\VEvent;

final readonly class Event
{
    /**
     * Hydrate an immutable event snapshot.
     *
     * @internal
     */
    public function __construct(
        public ?string $uid,
        public ?string $summary,
        public ?CarbonImmutable $startsAt,
        private bool $allDay,
        private VEvent $component,
    ) {}

    /**
     * Determine whether DTSTART uses the iCalendar DATE value type.
     */
    public function isAllDay(): bool
    {
        return $this->allDay;
    }

    /**
     * Return a deep clone of the underlying low-level event component.
     */
    public function rawComponent(): VEvent
    {
        return clone $this->component;
    }

    /**
     * Convert the current event to its domain-oriented array representation.
     *
     * @return array{uid: ?string, summary: ?string, starts_at: ?string, is_all_day: bool}
     */
    public function toArray(): array
    {
        return [
            'uid' => $this->uid,
            'summary' => $this->summary,
            'starts_at' => $this->startsAt?->toIso8601String(),
            'is_all_day' => $this->isAllDay(),
        ];
    }
}

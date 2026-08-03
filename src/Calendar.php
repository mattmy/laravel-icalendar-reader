<?php

declare(strict_types=1);

namespace Mattmy\ICalendar;

use Illuminate\Support\Collection;
use JsonSerializable;
use Sabre\VObject\Component\VCalendar;

final readonly class Calendar implements JsonSerializable
{
    /**
     * @param  list<Event>  $eventItems
     * @param  list<CalendarIssue>  $warningItems
     *
     * @internal
     */
    public function __construct(
        public ?string $version,
        public ?string $productId,
        public ?string $method,
        public ?string $calendarScale,
        public string $floatingTimezone,
        private array $eventItems,
        private array $warningItems,
        private VCalendar $component,
    ) {}

    /**
     * Return events in document order.
     *
     * @return Collection<int, Event>
     */
    public function events(): Collection
    {
        return collect($this->eventItems);
    }

    /**
     * Determine whether any event, or an exact summary match, exists.
     */
    public function hasEvents(?string $name = null): bool
    {
        if ($name === null) {
            return $this->eventItems !== [];
        }

        foreach ($this->eventItems as $event) {
            if ($event->summary === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find an event by its exact, case-sensitive UID.
     */
    public function event(string $uid): ?Event
    {
        foreach ($this->eventItems as $event) {
            if ($event->uid === $uid) {
                return $event;
            }
        }

        return null;
    }

    /**
     * Return non-fatal parsing, validation, configuration, and mapping issues.
     *
     * @return Collection<int, CalendarIssue>
     */
    public function warnings(): Collection
    {
        return collect($this->warningItems);
    }

    /**
     * Return a deep clone of the underlying low-level calendar component.
     */
    public function rawComponent(): VCalendar
    {
        return clone $this->component;
    }

    /**
     * Convert the calendar to its current domain-oriented representation.
     *
     * @return array{version: ?string, product_id: ?string, method: ?string, calendar_scale: ?string, floating_timezone: string, events: list<array{uid: ?string, summary: ?string, starts_at: ?string, is_all_day: bool}>, warnings: list<array{level: int, code: string, message: string, source: string, line: ?int, component: ?string, property: ?string}>}
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'product_id' => $this->productId,
            'method' => $this->method,
            'calendar_scale' => $this->calendarScale,
            'floating_timezone' => $this->floatingTimezone,
            'events' => \array_map(
                static fn (Event $event): array => $event->toArray(),
                $this->eventItems,
            ),
            'warnings' => \array_map(
                static fn (CalendarIssue $issue): array => $issue->toArray(),
                $this->warningItems,
            ),
        ];
    }

    /**
     * Return data suitable for JSON encoding.
     *
     * @return array{version: ?string, product_id: ?string, method: ?string, calendar_scale: ?string, floating_timezone: string, events: list<array{uid: ?string, summary: ?string, starts_at: ?string, is_all_day: bool}>, warnings: list<array{level: int, code: string, message: string, source: string, line: ?int, component: ?string, property: ?string}>}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

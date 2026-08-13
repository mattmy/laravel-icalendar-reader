<?php

declare(strict_types=1);

namespace Mattmy\ICalendar;

use DateInterval;
use Illuminate\Support\Collection;
use Mattmy\ICalendar\Concerns\QueriesProperties;
use Sabre\VObject\Component\VAlarm;

/** Represent an immutable typed view of one VALARM component. */
final readonly class Alarm
{
    use QueriesProperties;

    /**
     * Hydrate an alarm and its related typed values.
     *
     * The duration is a defensive snapshot of the parsed DURATION value.
     *
     * @param  list<Property>  $propertyItems
     * @internal
     */
    public function __construct(
        public ?string $action,
        public ?AlarmTrigger $trigger,
        public ?string $description,
        public ?string $summary,
        /** @var Collection<int, Attendee> */
        public Collection $attendees,
        /** @var Collection<int, Property> */
        public Collection $attachments,
        public ?int $repeat,
        /** The parsed alarm duration, isolated from mutable parser state. */
        public ?DateInterval $duration,
        private array $propertyItems,
        private VAlarm $component,
    ) {}

    /** Return a deep clone of the underlying low-level alarm component. */
    public function rawComponent(): VAlarm
    {
        return clone $this->component;
    }

    /**
     * Return the alarm's ordered direct properties for the internal query trait.
     *
     * @return list<Property>
     *
     * @internal
     */
    protected function propertyItems(): array
    {
        return $this->propertyItems;
    }
}

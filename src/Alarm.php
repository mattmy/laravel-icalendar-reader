<?php

declare(strict_types=1);

namespace Mattmy\ICalendar;

use DateInterval;
use Illuminate\Support\Collection;

/** Represent an immutable typed view of one VALARM component. */
final readonly class Alarm
{
    /**
     * Hydrate an alarm and its related typed values.
     *
     * The duration is a defensive snapshot of the parsed DURATION value.
     *
     * @internal
     */
    public function __construct(
        public ?string $action,
        public ?AlarmTrigger $trigger,
        public ?string $description,
        public ?string $summary,
        /** @var Collection<int, Attendee> */
        public Collection $attendees,
        public ?int $repeat,
        public ?DateInterval $duration,
    ) {}
}

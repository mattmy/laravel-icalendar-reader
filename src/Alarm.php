<?php

declare(strict_types=1);

namespace Mattmy\ICalendar;

use DateInterval;
use Illuminate\Support\Collection;

final readonly class Alarm
{
    /**
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

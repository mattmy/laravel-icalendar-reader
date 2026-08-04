<?php

declare(strict_types=1);

namespace Mattmy\ICalendar;

use Carbon\CarbonImmutable;
use DateInterval;

/** Preserve whether a VALARM trigger is relative or absolute. */
final readonly class AlarmTrigger
{
    /**
     * Hydrate exactly one relative or absolute trigger representation.
     *
     * @internal
     */
    public function __construct(
        private ?DateInterval $relativeDuration,
        private ?CarbonImmutable $absoluteDateTime,
        private ?string $relation,
    ) {}

    /** Determine whether the trigger is a relative duration. */
    public function isRelative(): bool
    {
        return $this->relativeDuration !== null;
    }

    /** Determine whether the trigger is an absolute date-time. */
    public function isAbsolute(): bool
    {
        return $this->absoluteDateTime !== null;
    }

    /** Return a defensive copy of the relative duration. */
    public function duration(): ?DateInterval
    {
        return $this->relativeDuration === null ? null : clone $this->relativeDuration;
    }

    /** Return the absolute trigger date-time. */
    public function dateTime(): ?CarbonImmutable
    {
        return $this->absoluteDateTime;
    }

    /** Return START or END for a relative trigger. */
    public function relatedTo(): ?string
    {
        return $this->isRelative() ? ($this->relation ?? 'START') : null;
    }
}

<?php

declare(strict_types=1);

namespace Mattmy\ICalendar\Exceptions;

use RuntimeException;

/** Report input that exceeds the configured byte limit. */
final class CalendarTooLarge extends RuntimeException implements ICalendarException
{
    /**
     * Create an exception for content exceeding the configured byte limit.
     */
    public static function forLimit(int $maxBytes): self
    {
        return new self("The iCalendar content exceeds the configured {$maxBytes}-byte limit.");
    }
}

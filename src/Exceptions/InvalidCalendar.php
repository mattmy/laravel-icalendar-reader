<?php

declare(strict_types=1);

namespace Mattmy\ICalendar\Exceptions;

use Illuminate\Support\Collection;
use Mattmy\ICalendar\CalendarIssue;
use RuntimeException;
use Throwable;

/** Report iCalendar parse, root-component, or level-three validation failures. */
final class InvalidCalendar extends RuntimeException implements ICalendarException
{
    /**
     * Create an invalid-calendar exception with structured issues.
     *
     * @param  list<CalendarIssue>  $issues
     */
    public function __construct(
        string $message,
        private readonly array $issues,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    /**
     * Return structured reasons why the document is invalid.
     *
     * @return Collection<int, CalendarIssue>
     */
    public function issues(): Collection
    {
        return collect($this->issues);
    }
}

<?php

declare(strict_types=1);

namespace Mattmy\ICalendar\Exceptions;

use Illuminate\Support\Collection;
use Mattmy\ICalendar\CalendarIssue;
use RuntimeException;
use Throwable;

final class InvalidCalendar extends RuntimeException implements ICalendarException
{
    /**
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
